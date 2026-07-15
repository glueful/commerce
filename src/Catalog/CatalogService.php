<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Support\Money;
use Glueful\Extensions\Commerce\Support\OpenVocabularySlug;
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
 * sets type to external) AND on every subsequent update that touches `metadata`
 * while the product's EFFECTIVE type (the incoming `type` key if present, else
 * the current stored type) is external — including a metadata-only PATCH that
 * carries no `type` key at all, so an already-external product can never be left
 * with a stripped/invalid `external_url` by a partial update.
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
        private ?ShippingClassRepository $shippingClasses = null,
    ) {
        $this->stock ??= new StockRepository();
        $this->children ??= new ProductChildrenRepository();
        $this->shippingClasses ??= new ShippingClassRepository();
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
        $taxClass = $this->normalizeTaxClass($input['tax_class'] ?? null);

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
            'tax_class' => $taxClass,
        ]);

        $created = [];
        db($context)->transaction(
            function () use ($context, $tenant, $productUuid, $variants, $storeCurrency, $type, &$created): void {
                $this->claimAndInsertCreatedVariants(
                    $context,
                    $tenant,
                    $productUuid,
                    $variants,
                    $storeCurrency,
                    $type,
                    $created
                );
            }
        );

        $product = $this->products->findByUuid($context, $tenant, $productUuid);
        if ($product === null) {
            throw new \RuntimeException('Created product could not be reloaded.');
        }

        $product['variants'] = $this->shippingClasses->attachResolvedSlugs(
            $context,
            $tenant,
            array_values(array_filter($created))
        );

        return $product;
    }

    /**
     * Claims every distinct shipping class the batch of $variants references
     * (see {@see self::claimShippingClassesForCreate()}) and inserts each
     * variant row, all inside the SAME transaction createProduct() opened --
     * extracted purely to keep that transaction's closure body under the
     * house line-length limit.
     *
     * @param list<array<string,mixed>> $variants
     * @param list<array<string,mixed>|null> $created
     */
    private function claimAndInsertCreatedVariants(
        ApplicationContext $context,
        string $tenant,
        string $productUuid,
        array $variants,
        string $storeCurrency,
        string $type,
        array &$created
    ): void {
        $this->claimShippingClassesForCreate($context, $tenant, array_map(
            static fn (array $variant): ?string => $variant['shipping_class_uuid'] ?? null,
            $variants
        ));

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
                'shipping_class_uuid' => $variant['shipping_class_uuid'] ?? null,
            ]);

            $this->stock->ensureRow($context, $tenant, $variantUuid, $type === 'physical');

            $created[] = $this->variants->findByUuid($context, $tenant, $variantUuid);
        }
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

        db($context)->transaction(
            function () use ($context, $tenant, $productUuid, $variant, $variantUuid, $storeCurrency): void {
                $this->claimShippingClassesForCreate($context, $tenant, [$variant['shipping_class_uuid'] ?? null]);

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
                    'shipping_class_uuid' => $variant['shipping_class_uuid'] ?? null,
                ]);
            }
        );

        $this->stock->ensureRow($context, $tenant, $variantUuid, ($product['type'] ?? 'physical') === 'physical');

        $created = $this->variants->findByUuid($context, $tenant, $variantUuid);
        if ($created === null) {
            throw new \RuntimeException('Created variant could not be reloaded.');
        }

        return $this->shippingClasses->attachResolvedSlug($context, $tenant, $created);
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

        $touchesType = array_key_exists('type', $changes);
        $touchesMetadata = array_key_exists('metadata', $changes);

        // Loaded whenever either key is present: a `type` change needs the
        // CURRENT type/metadata to decide the immutability guard and the
        // fallback metadata to re-validate against; a metadata-only change needs
        // the current type to know whether the product is (still) external at
        // all -- see the metadata re-validation block below.
        $current = null;
        if ($touchesType || $touchesMetadata) {
            $current = $this->products->findByUuid($context, $tenant, $productUuid);
            if ($current === null) {
                throw new NotFoundException('Resource not found.');
            }
        }

        if ($touchesType) {
            $newType = (string) $changes['type'];
            $this->assertValidType($newType);

            $currentType = (string) ($current['type'] ?? 'physical');
            if ($newType !== $currentType && $this->productHasStrandableReferences($context, $tenant, $productUuid)) {
                throw ValidationException::forField(
                    'type',
                    'type cannot change while the product has variants, children, or cart/order references.'
                );
            }
        }

        // Re-validate `metadata.external_url` whenever the update touches
        // `metadata` AND the product's EFFECTIVE type (the incoming `type` key,
        // else the current stored type) is `external` -- covers both a
        // type-change landing on external AND a metadata-only PATCH against an
        // already-external product (the bug this guard closes: such a PATCH
        // could otherwise strip/corrupt `external_url` with no validation at
        // all, since the old code only ever checked this inside the `type`-key
        // branch).
        if ($touchesType || $touchesMetadata) {
            $effectiveType = $touchesType ? (string) $changes['type'] : (string) ($current['type'] ?? 'physical');
            if ($effectiveType === 'external') {
                $metadata = $touchesMetadata ? $changes['metadata'] : ($current['metadata'] ?? null);
                $this->assertExternalMetadata($metadata);
            }
        }

        // tax_class (spec §5): explicit null CLEARS (stored as null -> "standard"
        // at the calculator); an omitted key PRESERVES the current value. Only a
        // non-null value is run through the open-vocabulary normalizer.
        if (array_key_exists('tax_class', $changes)) {
            $changes['tax_class'] = $this->normalizeTaxClass($changes['tax_class']);
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

    /**
     * A `shipping_class_uuid` key present in $changes (spec §6) routes through
     * the shared-claim protocol below regardless of whether it is a real change
     * or a no-op reassertion; its ABSENCE preserves the current assignment via
     * the plain write path unchanged from before this field existed.
     *
     * @param array<string,mixed> $changes
     */
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

        if (!array_key_exists('shipping_class_uuid', $changes)) {
            $this->variants->update($context, $tenant, $variantUuid, $changes);

            return;
        }

        $this->updateVariantShippingClass($context, $tenant, $variantUuid, $changes);
    }

    /**
     * The §6 shared-claim protocol for variant shipping-class assignment/clear:
     * one transaction that resolves variant->product, claims the product's
     * `catalog_revision`, claims the sorted-uuid union of the variant's CURRENT
     * and PROPOSED shipping class (affected-row-checked revision bumps on
     * `commerce_shipping_classes`), re-validates the proposed class still exists
     * in-tenant, then writes. See {@see \Glueful\Extensions\Commerce\Shipping\ShippingClassService}'s
     * class docblock for the full class-delete-vs-variant-assign race analysis
     * this claim set serializes against. A `null` proposed value CLEARS the
     * assignment; updateVariant() only reaches this method when
     * `shipping_class_uuid` is present in $changes at all -- omission never calls
     * this path.
     *
     * @param array<string,mixed> $changes
     */
    private function updateVariantShippingClass(
        ApplicationContext $context,
        string $tenant,
        string $variantUuid,
        array $changes
    ): void {
        $proposed = $changes['shipping_class_uuid'] ?? null;
        if ($proposed !== null && (!is_string($proposed) || trim($proposed) === '')) {
            throw ValidationException::forField(
                'shipping_class_uuid',
                'shipping_class_uuid must be a non-empty string or null.'
            );
        }

        db($context)->transaction(function () use ($context, $tenant, $variantUuid, $changes, $proposed): void {
            $variant = $this->variants->findByUuid($context, $tenant, $variantUuid);
            if ($variant === null) {
                throw new NotFoundException('Resource not found.');
            }
            $productUuid = (string) $variant['product_uuid'];

            if (!$this->products->claimCatalogRevision($context, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $variant['shipping_class_uuid'] ?? null;
            $claimSet = array_values(array_unique(array_filter(
                [$current, $proposed],
                static fn (mixed $uuid): bool => $uuid !== null
            )));
            sort($claimSet);

            foreach ($claimSet as $classUuid) {
                $claimed = $this->shippingClasses->claimRevision($context, $tenant, (string) $classUuid);
                if (!$claimed && $classUuid === $proposed) {
                    throw ValidationException::forField(
                        'shipping_class_uuid',
                        'shipping_class_uuid must reference an existing shipping class in this tenant.'
                    );
                }
            }

            // Post-claim re-read: the claim already proved the proposed class
            // existed in-tenant at claim time, but this mirrors the house
            // discipline of never deciding on a pre-claim snapshot alone.
            if ($proposed !== null && $this->shippingClasses->findByUuid($context, $tenant, $proposed) === null) {
                throw ValidationException::forField(
                    'shipping_class_uuid',
                    'shipping_class_uuid must reference an existing shipping class in this tenant.'
                );
            }

            $this->variants->update($context, $tenant, $variantUuid, $changes);
        });
    }

    /**
     * Symmetric CREATE-path counterpart to {@see self::updateVariantShippingClass()}'s
     * shared-claim protocol (reviewer-mandated hardening, T3 follow-up): claims
     * every distinct shipping-class uuid a variant CREATE proposes to reference
     * (sorted, affected-row-checked via
     * {@see \Glueful\Extensions\Commerce\Shipping\ShippingClassRepository::claimRevision()})
     * and re-validates existence post-claim. `validateVariants()`'s plain
     * `findByUuid` existence check alone leaves a TOCTOU gap against a
     * concurrent class DELETE landing between validation and insert -- claiming
     * here closes it, so create/update/delete all serialize against the SAME
     * class row (see {@see \Glueful\Extensions\Commerce\Shipping\ShippingClassService}'s
     * class docblock for the full race analysis). MUST run inside the SAME
     * transaction as the variant insert(s) that reference the claimed class(es);
     * claiming without an atomic insert alongside it would not serialize
     * against anything.
     *
     * @param list<string|null> $classUuids
     */
    private function claimShippingClassesForCreate(ApplicationContext $context, string $tenant, array $classUuids): void
    {
        $claimSet = array_values(array_unique(array_filter(
            $classUuids,
            static fn (mixed $uuid): bool => $uuid !== null
        )));
        sort($claimSet);

        foreach ($claimSet as $classUuid) {
            $claimed = $this->shippingClasses->claimRevision($context, $tenant, (string) $classUuid);
            if (!$claimed || $this->shippingClasses->findByUuid($context, $tenant, (string) $classUuid) === null) {
                throw ValidationException::forField(
                    'shipping_class_uuid',
                    'shipping_class_uuid must reference an existing shipping class in this tenant.'
                );
            }
        }
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

            $classUuid = $variant['shipping_class_uuid'] ?? null;
            $classFound = $classUuid === null
                || $this->shippingClasses->findByUuid($context, $tenant, (string) $classUuid) !== null;
            if (!$classFound) {
                throw ValidationException::forField(
                    "variants.{$index}.shipping_class_uuid",
                    'shipping_class_uuid must reference an existing shipping class in this tenant.'
                );
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

    /**
     * tax_class (spec §5): null preserves/means "standard"; a non-null value
     * must match the open-vocabulary rule shared with shipping-class slugs and
     * tax-rate classes (an unmatched-but-well-formed class is allowed and simply
     * taxes at 0 -- existence is never enforced here).
     */
    private function normalizeTaxClass(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return OpenVocabularySlug::normalize((string) $value, 'tax_class');
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
