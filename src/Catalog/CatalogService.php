<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Support\Money;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Product/variant CRUD plus product-type gating and grouped-product children.
 *
 * Type widening (physical|digital|external|grouped) is validated BEFORE variant
 * validation on every create path: external/grouped products are NOT purchasable
 * (variant creation is rejected for them, both the initial `variants` array on
 * createProduct() and createVariant()/storeVariant), so the type decision must be
 * made first or the pre-existing unconditional "at least one variant" rule would
 * wrongly reject them. external additionally requires a validated http/https
 * `metadata.external_url` at the point its type is set (create, or an update that
 * sets type to external).
 *
 * Type is immutable once it would strand data: a type-changing update is rejected
 * whenever the product has variants, a children relationship in EITHER direction
 * (parent or child), or a cart/order line referencing one of its variants —
 * checked independently, not merely inferred from variant presence, so the guard
 * stays correct if a future change ever decouples them.
 *
 * Children set-list (setProductChildren) follows the same claim-then-re-read
 * discipline as CategoryService::setProductCategories / AttributeService::
 * setProductAttributes: claims the parent PRODUCT first (`catalog_revision` — a
 * failed claim is a non-revealing 404, the URL's primary resource), then the union
 * of every currently-attached and proposed child uuid (sorted) via the SAME
 * `catalog_revision` claim primitive (children are themselves `commerce_products`
 * rows). Only after every claim succeeds does the mutation re-read the parent's
 * type and each proposed child's fresh type/tenant — a proposed child that failed
 * to claim, or whose fresh type is not physical/digital, is a 422 on the field,
 * never a crash or a stale-state attach.
 */
final class CatalogService
{
    private const TYPES = ['physical', 'digital', 'external', 'grouped'];
    private const PURCHASABLE_TYPES = ['physical', 'digital'];

    public function __construct(
        private ProductRepository $products,
        private VariantRepository $variants,
        private CurrentTenantResolver $tenants,
        private ?StockRepository $stock = null,
        private ?ProductChildrenRepository $children = null,
    ) {
        $this->stock ??= new StockRepository();
        $this->children ??= new ProductChildrenRepository();
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createProduct(ApplicationContext $context, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $storeCurrency = $this->storeCurrency($context);

        // Type is decided and validated BEFORE variants: it governs whether
        // validateVariants() even runs (see class docblock).
        $type = (string) ($input['type'] ?? 'physical');
        $this->assertValidType($type);
        if ($type === 'external') {
            $this->assertExternalMetadata($input['metadata'] ?? null);
        }

        $variants = $this->planCreationVariants($context, $tenant, $type, $input['variants'] ?? [], $storeCurrency);
        $slug = $this->requiredString($input, 'slug');

        if ($this->products->findBySlug($context, $tenant, $slug) !== null) {
            throw ValidationException::forField('slug', 'Slug already in use.');
        }

        $productUuid = Utils::generateNanoID();

        $this->products->insert($context, [
            'uuid' => $productUuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $this->requiredString($input, 'name'),
            'description' => $input['description'] ?? null,
            'type' => $type,
            'status' => (string) ($input['status'] ?? 'draft'),
            'options' => $input['options'] ?? null,
            'metadata' => $input['metadata'] ?? null,
        ]);

        $created = [];
        foreach ($variants as $position => $variant) {
            $variantUuid = Utils::generateNanoID();
            $this->variants->insert($context, [
                'uuid' => $variantUuid,
                'tenant_uuid' => $tenant,
                'product_uuid' => $productUuid,
                'sku' => (string) $variant['sku'],
                'option_values' => $variant['option_values'] ?? [],
                'price' => (int) $variant['price'],
                'compare_at_price' => $variant['compare_at_price'] ?? null,
                'currency' => $storeCurrency,
                'position' => $position,
                'status' => (string) ($variant['status'] ?? 'active'),
            ]);

            $this->stock->ensureRow($context, $tenant, $variantUuid, $type === 'physical');

            $created[] = $this->variants->findByUuid($context, $tenant, $variantUuid);
        }

        $product = $this->products->findByUuid($context, $tenant, $productUuid);
        if ($product === null) {
            throw new \RuntimeException('Created product could not be reloaded.');
        }

        $product['variants'] = array_values(array_filter($created));

        return $product;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createVariant(ApplicationContext $context, string $productUuid, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $storeCurrency = $this->storeCurrency($context);
        $product = $this->products->findByUuid($context, $tenant, $productUuid);
        if ($product === null) {
            throw ValidationException::forField('product_uuid', 'Product not found.');
        }

        $type = (string) ($product['type'] ?? 'physical');
        if (!in_array($type, self::PURCHASABLE_TYPES, true)) {
            throw ValidationException::forField(
                'product_uuid',
                "Cannot add variants to a '{$type}' product."
            );
        }

        $variants = $this->validateVariants($context, $tenant, [$input], $storeCurrency);
        $variant = $variants[0];
        $variantUuid = Utils::generateNanoID();
        $this->variants->insert($context, [
            'uuid' => $variantUuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'sku' => (string) $variant['sku'],
            'option_values' => $variant['option_values'] ?? [],
            'price' => (int) $variant['price'],
            'compare_at_price' => $variant['compare_at_price'] ?? null,
            'currency' => $storeCurrency,
            'position' => count($this->variants->forProduct($context, $tenant, $productUuid)),
            'status' => (string) ($variant['status'] ?? 'active'),
        ]);

        $this->stock->ensureRow($context, $tenant, $variantUuid, ($product['type'] ?? 'physical') === 'physical');

        $created = $this->variants->findByUuid($context, $tenant, $variantUuid);
        if ($created === null) {
            throw new \RuntimeException('Created variant could not be reloaded.');
        }

        return $created;
    }

    /**
     * @param array<string,mixed> $changes a `type` key triggers the immutability
     *   guard below; other fields are applied as before.
     */
    public function updateProduct(ApplicationContext $context, string $productUuid, array $changes): void
    {
        $tenant = $this->tenants->tenantUuid($context);
        if (isset($changes['slug'])) {
            $existing = $this->products->findBySlug($context, $tenant, (string) $changes['slug']);
            if ($existing !== null && ($existing['uuid'] ?? null) !== $productUuid) {
                throw ValidationException::forField('slug', 'Slug already in use.');
            }
        }

        if (array_key_exists('type', $changes)) {
            $newType = (string) $changes['type'];
            $this->assertValidType($newType);

            $current = $this->products->findByUuid($context, $tenant, $productUuid);
            if ($current === null) {
                throw new NotFoundException('Resource not found.');
            }

            $currentType = (string) ($current['type'] ?? 'physical');
            if ($newType !== $currentType && $this->productHasStrandableReferences($context, $tenant, $productUuid)) {
                throw ValidationException::forField(
                    'type',
                    'type cannot change while the product has variants, children, or cart/order references.'
                );
            }

            if ($newType === 'external') {
                $metadata = array_key_exists('metadata', $changes)
                    ? $changes['metadata']
                    : ($current['metadata'] ?? null);
                $this->assertExternalMetadata($metadata);
            }
        }

        $this->products->update($context, $tenant, $productUuid, $changes);
    }

    /**
     * Idempotent ordered set-list replace for a grouped product's children. See
     * class docblock for the full claim/re-read discipline. Currently-attached
     * children no longer proposed are simply detached; submitting the same
     * ordered list twice produces the same resulting content (wholesale replace,
     * not a row-by-row diff — mirrors AttributeService::setProductAttributes()).
     *
     * @param list<string> $childUuids ordered
     * @return list<array<string,mixed>> ordered child product rows
     */
    public function setProductChildren(ApplicationContext $context, string $productUuid, array $childUuids): array
    {
        $tenant = $this->tenants->tenantUuid($context);

        return db($context)->transaction(function () use ($context, $tenant, $productUuid, $childUuids): array {
            if (!$this->products->claimCatalogRevision($context, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $parent = $this->products->findByUuid($context, $tenant, $productUuid);
            if ($parent === null) {
                throw new NotFoundException('Resource not found.');
            }
            if (($parent['type'] ?? null) !== 'grouped') {
                throw ValidationException::forField('type', 'Only grouped products can have children.');
            }

            $proposed = $this->normalizeUuidList($childUuids, 'child_uuids');
            foreach ($proposed as $index => $childUuid) {
                if ($childUuid === $productUuid) {
                    throw ValidationException::forField(
                        "child_uuids.{$index}",
                        'A product cannot be its own child.'
                    );
                }
            }

            // Pre-claim snapshot purely to discover which child rows join the
            // claim set below -- never trusted for the physical/digital decision,
            // which only happens after every claim succeeds and each proposed
            // child is re-read fresh (see class docblock).
            $current = $this->children->childUuidsForProduct($context, $productUuid);

            $union = array_values(array_unique(array_merge($current, $proposed)));
            sort($union);

            $claimed = [];
            foreach ($union as $childUuid) {
                $claimed[$childUuid] = $this->products->claimCatalogRevision($context, $tenant, $childUuid);
            }

            foreach ($proposed as $index => $childUuid) {
                if (!($claimed[$childUuid] ?? false)) {
                    throw ValidationException::forField(
                        "child_uuids.{$index}",
                        'child_uuids must reference existing products in this tenant.'
                    );
                }

                $child = $this->products->findByUuid($context, $tenant, $childUuid);
                if ($child === null) {
                    throw ValidationException::forField(
                        "child_uuids.{$index}",
                        'child_uuids must reference existing products in this tenant.'
                    );
                }

                $childType = (string) ($child['type'] ?? 'physical');
                if (!in_array($childType, self::PURCHASABLE_TYPES, true)) {
                    throw ValidationException::forField(
                        "child_uuids.{$index}",
                        "child_uuids must reference physical or digital products (got '{$childType}')."
                    );
                }
            }

            $this->children->replaceChildren($context, $productUuid, $proposed);

            return $this->children->childProductsForProduct($context, $tenant, $productUuid);
        });
    }

    /** @param array<string,mixed> $changes */
    public function updateVariant(ApplicationContext $context, string $variantUuid, array $changes): void
    {
        $tenant = $this->tenants->tenantUuid($context);
        $storeCurrency = $this->storeCurrency($context);

        if (isset($changes['currency']) && $changes['currency'] !== $storeCurrency) {
            throw ValidationException::forField(
                'currency',
                "Variant currency must equal the store currency ({$storeCurrency})."
            );
        }
        if (isset($changes['price']) && (!is_int($changes['price']) || $changes['price'] < 0)) {
            throw ValidationException::forField('price', 'Price must be a non-negative integer (minor units).');
        }

        if (isset($changes['sku'])) {
            $existing = $this->variants->findBySku($context, $tenant, (string) $changes['sku']);
            if ($existing !== null && ($existing['uuid'] ?? null) !== $variantUuid) {
                throw ValidationException::forField('sku', 'SKU already in use.');
            }
        }

        $this->variants->update($context, $tenant, $variantUuid, $changes);
    }

    private function storeCurrency(ApplicationContext $context): string
    {
        $currency = (string) config($context, 'commerce.currency', 'USD');
        Money::assertValidCurrency($currency);

        return $currency;
    }

    /**
     * @param mixed $raw
     * @return list<array<string,mixed>>
     */
    private function validateVariants(
        ApplicationContext $context,
        string $tenant,
        mixed $raw,
        string $storeCurrency,
    ): array {
        if (!is_array($raw) || $raw === []) {
            throw ValidationException::forField('variants', 'A product needs at least one variant.');
        }

        $variants = array_values($raw);
        foreach ($variants as $index => $variant) {
            if (!is_array($variant)) {
                throw ValidationException::forField("variants.{$index}", 'Variant must be an object.');
            }

            if (($variant['currency'] ?? $storeCurrency) !== $storeCurrency) {
                throw ValidationException::forField(
                    "variants.{$index}.currency",
                    "Variant currency must equal the store currency ({$storeCurrency})."
                );
            }

            if (!is_int($variant['price'] ?? null) || $variant['price'] < 0) {
                throw ValidationException::forField(
                    "variants.{$index}.price",
                    'Price must be a non-negative integer (minor units).'
                );
            }

            $sku = (string) ($variant['sku'] ?? '');
            if ($sku === '') {
                throw ValidationException::forField("variants.{$index}.sku", 'SKU is required.');
            }
            if ($this->variants->findBySku($context, $tenant, $sku) !== null) {
                throw ValidationException::forField("variants.{$index}.sku", 'SKU already in use.');
            }
        }

        /** @var list<array<string,mixed>> $variants */
        return $variants;
    }

    /**
     * Zero variants is accepted ONLY for external/grouped products (they are not
     * purchasable — variant creation is rejected for them outright); physical/
     * digital retain the pre-existing at-least-one-variant rule enforced by
     * validateVariants().
     *
     * @param mixed $raw
     * @return list<array<string,mixed>>
     */
    private function planCreationVariants(
        ApplicationContext $context,
        string $tenant,
        string $type,
        mixed $raw,
        string $storeCurrency,
    ): array {
        if (!in_array($type, self::PURCHASABLE_TYPES, true)) {
            if (is_array($raw) && $raw !== []) {
                throw ValidationException::forField(
                    'variants',
                    "'{$type}' products cannot have variants."
                );
            }

            return [];
        }

        return $this->validateVariants($context, $tenant, $raw, $storeCurrency);
    }

    private function assertValidType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw ValidationException::forField(
                'type',
                'type must be one of: ' . implode(', ', self::TYPES) . '.'
            );
        }
    }

    /** @param mixed $metadata */
    private function assertExternalMetadata(mixed $metadata): void
    {
        $metadata = is_array($metadata) ? $metadata : [];

        $url = $metadata['external_url'] ?? null;
        if (!is_string($url) || trim($url) === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::forField(
                'metadata.external_url',
                'metadata.external_url is required and must be a valid URL for external products.'
            );
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::forField(
                'metadata.external_url',
                'metadata.external_url must use the http or https scheme.'
            );
        }

        $buttonLabel = $metadata['button_label'] ?? null;
        if ($buttonLabel !== null && !is_string($buttonLabel)) {
            throw ValidationException::forField(
                'metadata.button_label',
                'metadata.button_label must be a string.'
            );
        }
    }

    /**
     * True when changing $productUuid's type would strand data: it has variants,
     * a children relationship in either direction, or a cart/order line
     * referencing one of its variants. Each condition is checked independently
     * (not merely inferred from variant presence) per the class docblock.
     */
    private function productHasStrandableReferences(
        ApplicationContext $context,
        string $tenant,
        string $productUuid
    ): bool {
        $variants = $this->variants->forProduct($context, $tenant, $productUuid);
        $hasVariants = $variants !== [];

        $hasChildRelationship = $this->children->isParentAnywhere($context, $productUuid)
            || $this->children->isChildAnywhere($context, $productUuid);

        $variantUuids = array_map(static fn (array $variant): string => (string) $variant['uuid'], $variants);
        $hasCartOrOrderReference = $variantUuids !== [] && (
            $this->hasLineReference($context, 'commerce_cart_lines', $variantUuids)
            || $this->hasLineReference($context, 'commerce_order_lines', $variantUuids)
        );

        return $hasVariants || $hasChildRelationship || $hasCartOrOrderReference;
    }

    /** @param list<string> $variantUuids */
    private function hasLineReference(ApplicationContext $context, string $table, array $variantUuids): bool
    {
        return db($context)->table($table)->whereIn('variant_uuid', $variantUuids)->count() > 0;
    }

    /**
     * @param mixed $raw
     * @return list<string> unique, trimmed, non-empty uuids (order preserved)
     */
    private function normalizeUuidList(mixed $raw, string $field): array
    {
        if (!is_array($raw)) {
            throw ValidationException::forField($field, "{$field} must be an array of uuids.");
        }

        $result = [];
        foreach ($raw as $index => $value) {
            if (!is_string($value) || trim($value) === '') {
                throw ValidationException::forField($field, "{$field}.{$index} must be a non-empty string.");
            }
            $result[] = trim($value);
        }

        return array_values(array_unique($result));
    }

    /** @param array<string,mixed> $input */
    private function requiredString(array $input, string $field): string
    {
        $value = (string) ($input[$field] ?? '');
        if ($value === '') {
            throw ValidationException::forField($field, ucfirst($field) . ' is required.');
        }

        return $value;
    }
}
