<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;

final class AttributeRepository
{
    // --- Attributes ---

    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_attributes')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_attributes')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(ApplicationContext $context, string $tenant, string $slug): ?array
    {
        return db($context)->table('commerce_attributes')
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->first();
    }

    /**
     * Batched `findByUuid()`: one query for every uuid in the list via IN,
     * tenant scoped -- avoids one query per attribute when projecting a
     * product's visible attribute rows (e.g. the storefront product show()).
     *
     * @param list<string> $uuids
     * @return array<string,array<string,mixed>> keyed by uuid
     */
    public function findManyByUuid(ApplicationContext $context, string $tenant, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $rows = db($context)->table('commerce_attributes')
            ->where('tenant_uuid', '=', $tenant)
            ->whereIn('uuid', $uuids)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['uuid']] = $row;
        }

        return $result;
    }

    /** @return list<array<string,mixed>> every attribute for the tenant, position then name */
    public function all(ApplicationContext $context, string $tenant): array
    {
        return db($context)->table('commerce_attributes')
            ->where('tenant_uuid', '=', $tenant)
            ->orderBy('position', 'ASC')
            ->orderBy('name', 'ASC')
            ->get();
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        db($context)->table('commerce_attributes')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($changes);
    }

    public function delete(ApplicationContext $context, string $tenant, string $uuid): void
    {
        db($context)->table('commerce_attributes')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->delete();
    }

    /**
     * Affected-row-checked serialization primitive shared by every mutation in this
     * subtree: attribute PATCH/DELETE claim it directly (URL's primary resource);
     * value create/update/delete and the product-attribute set-list claim it too --
     * values and product-attribute join rows carry no revision of their own, so the
     * owning attribute's revision is the single serialization point for everything
     * beneath it (see AttributeService's class docblock). Returns false for an
     * unknown or cross-tenant attribute.
     */
    public function claimRevision(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $affected = db($context)->table('commerce_attributes')->executeModification(
            <<<'SQL'
UPDATE commerce_attributes SET revision = revision + 1 WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [$tenant, $uuid]
        );

        return $affected === 1;
    }

    // --- Values ---

    /** @param array<string,mixed> $row */
    public function insertValue(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_attribute_values')->insert($row);
    }

    /**
     * Tenant-agnostic lookup by uuid -- `commerce_attribute_values` carries no
     * `tenant_uuid` of its own; a value is only reachable through its owning
     * attribute. Callers MUST verify the returned row's `attribute_uuid` resolves
     * in the caller's tenant (via `claimRevision`, which is itself tenant-scoped)
     * before trusting anything else about it -- see AttributeService's class
     * docblock for the full discipline.
     *
     * @return array<string,mixed>|null
     */
    public function findValueByUuid(ApplicationContext $context, string $uuid): ?array
    {
        return db($context)->table('commerce_attribute_values')
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /** @return list<array<string,mixed>> every value for the attribute, ordered by position */
    public function valuesForAttribute(ApplicationContext $context, string $attributeUuid): array
    {
        return db($context)->table('commerce_attribute_values')
            ->where('attribute_uuid', '=', $attributeUuid)
            ->orderBy('position', 'ASC')
            ->get();
    }

    /** @param array<string,mixed> $changes */
    public function updateValue(ApplicationContext $context, string $uuid, array $changes): void
    {
        db($context)->table('commerce_attribute_values')
            ->where('uuid', '=', $uuid)
            ->update($changes);
    }

    public function deleteValue(ApplicationContext $context, string $uuid): void
    {
        db($context)->table('commerce_attribute_values')
            ->where('uuid', '=', $uuid)
            ->delete();
    }

    /** Deletes every value belonging to an attribute being deleted (delete cascade). */
    public function deleteValuesForAttribute(ApplicationContext $context, string $attributeUuid): void
    {
        db($context)->table('commerce_attribute_values')
            ->where('attribute_uuid', '=', $attributeUuid)
            ->delete();
    }

    // --- Product assignments ---

    /** @return list<string> distinct non-null attribute uuids currently assigned to the product */
    public function attributeUuidsForProduct(ApplicationContext $context, string $productUuid): array
    {
        $rows = db($context)->table('commerce_product_attributes')
            ->where('product_uuid', '=', $productUuid)
            ->whereNotNull('attribute_uuid')
            ->get();

        return array_values(array_unique(
            array_map(static fn (array $row): string => (string) $row['attribute_uuid'], $rows)
        ));
    }

    /**
     * @return list<array<string,mixed>> every product-attribute row (global + custom)
     *   for the product, `values` decoded to a list<string>, ordered by position
     */
    public function productAttributeRows(ApplicationContext $context, string $productUuid): array
    {
        $rows = db($context)->table('commerce_product_attributes')
            ->where('product_uuid', '=', $productUuid)
            ->orderBy('position', 'ASC')
            ->get();

        return array_map(fn (array $row): array => $this->decodeValuesColumn($row), $rows);
    }

    /** @param array<string,mixed> $row */
    public function insertProductAttribute(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_product_attributes')->insert($row);
    }

    /** Wipes every product-attribute row for the product (set-list full replace). */
    public function deleteProductAttributesForProduct(ApplicationContext $context, string $productUuid): void
    {
        db($context)->table('commerce_product_attributes')
            ->where('product_uuid', '=', $productUuid)
            ->delete();
    }

    /** Detaches every product from an attribute being deleted (delete cascade). */
    public function detachProducts(ApplicationContext $context, string $attributeUuid): void
    {
        db($context)->table('commerce_product_attributes')
            ->where('attribute_uuid', '=', $attributeUuid)
            ->delete();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeValuesColumn(array $row): array
    {
        $raw = $row['values'] ?? null;
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        $row['values'] = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        $row['used_for_variants'] = (bool) ($row['used_for_variants'] ?? false);
        $row['visible'] = (bool) ($row['visible'] ?? true);

        return $row;
    }
}
