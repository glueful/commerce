<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Support\Money;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Validation\ValidationException;

final class CatalogService
{
    public function __construct(
        private ProductRepository $products,
        private VariantRepository $variants,
        private CurrentTenantResolver $tenants,
        private ?StockRepository $stock = null,
    ) {
        $this->stock ??= new StockRepository();
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createProduct(ApplicationContext $context, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $storeCurrency = $this->storeCurrency($context);
        $variants = $this->validateVariants($context, $tenant, $input['variants'] ?? [], $storeCurrency);
        $slug = $this->requiredString($input, 'slug');

        if ($this->products->findBySlug($context, $tenant, $slug) !== null) {
            throw ValidationException::forField('slug', 'Slug already in use.');
        }

        $productUuid = Utils::generateNanoID();
        $type = (string) ($input['type'] ?? 'physical');

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
        $variants = $this->validateVariants($context, $tenant, [$input], $storeCurrency);
        $product = $this->products->findByUuid($context, $tenant, $productUuid);
        if ($product === null) {
            throw ValidationException::forField('product_uuid', 'Product not found.');
        }

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

    /** @param array<string,mixed> $changes */
    public function updateProduct(ApplicationContext $context, string $productUuid, array $changes): void
    {
        $tenant = $this->tenants->tenantUuid($context);
        if (isset($changes['slug'])) {
            $existing = $this->products->findBySlug($context, $tenant, (string) $changes['slug']);
            if ($existing !== null && ($existing['uuid'] ?? null) !== $productUuid) {
                throw ValidationException::forField('slug', 'Slug already in use.');
            }
        }

        $this->products->update($context, $tenant, $productUuid, $changes);
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
