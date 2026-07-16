<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\LiteralLike;

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

    /**
     * Paginated admin list (Layer 6 Global Constraints): `q` is a
     * case-insensitive literal substring match on name OR slug via
     * {@see LiteralLike}. Ordered `position ASC, name ASC, uuid ASC` (stable
     * tie-break); count and row queries apply the identical predicate set.
     *
     * @param array<string,mixed> $filters 'q' (literal substring on name/slug)
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function paginatedFor(
        ApplicationContext $context,
        string $tenant,
        array $filters,
        int $page,
        int $perPage
    ): array {
        $count = db($context)->table('commerce_attributes')->where('tenant_uuid', '=', $tenant);
        $rows = db($context)->table('commerce_attributes')->where('tenant_uuid', '=', $tenant);

        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $pattern = LiteralLike::pattern($q);
            $condition = "(LOWER(name) LIKE ? ESCAPE '!' OR LOWER(slug) LIKE ? ESCAPE '!')";
            $count->whereRaw($condition, [$pattern, $pattern]);
            $rows->whereRaw($condition, [$pattern, $pattern]);
        }

        $items = $rows->orderBy('position', 'ASC')
            ->orderBy('name', 'ASC')
            ->orderBy('uuid', 'ASC')
            ->limit($perPage)
            ->offset(max(0, $page - 1) * $perPage)
            ->get();

        return [
            'items' => $items,
            'total' => $count->count(),
        ];
    }

    /**
     * Batched tenant-scoped resolution for the storefront product list's
     * `attributes` filter (Layer 6 Global Constraints): ONE query resolves
     * every requested `attribute-slug:value-slug` pair regardless of how many
     * pairs are requested (max 5, enforced at the DTO boundary) -- never one
     * query per pair. Joins `commerce_attribute_values` to `commerce_attributes`
     * (values carry no `tenant_uuid` of their own; the join is what scopes the
     * lookup to the caller's tenant) and constrains to the distinct requested
     * attribute/value slugs via two `IN` lists, then matches the resulting rows
     * back to the exact requested pairs in PHP (cheap: the row count is bounded
     * by the cross product of the distinct slug lists, not by table size).
     *
     * Result is keyed by the requested `"{attribute_slug}:{value_slug}"` string
     * and contains ONLY pairs that actually resolved -- a key absent from the
     * result means that pair is unknown in this tenant. The caller
     * (`Http\Storefront\ProductController`) checks every requested pair against
     * this map and short-circuits to an empty paginated response (never a 404,
     * never a per-pair error) the moment one is missing, before the product
     * query runs at all.
     *
     * @param list<array{attribute_slug:string, value_slug:string}> $pairs
     * @return array<string, array{attribute_uuid:string, value_slug:string}>
     */
    public function findPairsBySlugs(ApplicationContext $context, string $tenant, array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $attributeSlugs = array_values(array_unique(
            array_map(static fn (array $pair): string => $pair['attribute_slug'], $pairs)
        ));
        $valueSlugs = array_values(array_unique(
            array_map(static fn (array $pair): string => $pair['value_slug'], $pairs)
        ));

        $rows = db($context)->table('commerce_attribute_values')
            ->join(
                'commerce_attributes',
                'commerce_attribute_values.attribute_uuid',
                '=',
                'commerce_attributes.uuid'
            )
            ->select([
                'commerce_attributes.uuid AS attribute_uuid',
                'commerce_attributes.slug AS attribute_slug',
                'commerce_attribute_values.slug AS value_slug',
            ])
            ->where('commerce_attributes.tenant_uuid', '=', $tenant)
            ->whereIn('commerce_attributes.slug', $attributeSlugs)
            ->whereIn('commerce_attribute_values.slug', $valueSlugs)
            ->get();

        $byPair = [];
        foreach ($rows as $row) {
            $key = ((string) $row['attribute_slug']) . ':' . ((string) $row['value_slug']);
            $byPair[$key] = [
                'attribute_uuid' => (string) $row['attribute_uuid'],
                'value_slug' => (string) $row['value_slug'],
            ];
        }

        $result = [];
        foreach ($pairs as $pair) {
            $key = $pair['attribute_slug'] . ':' . $pair['value_slug'];
            if (isset($byPair[$key])) {
                $result[$key] = $byPair[$key];
            }
        }

        return $result;
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

    /**
     * Batched `valuesForAttribute()`: one query for every value across every
     * attribute in `$attributeUuids` (Layer 6 Global Constraints -- "attributes
     * embed values without one query per attribute on list pages") -- avoids the
     * N+1 pattern of calling {@see self::valuesForAttribute()} once per attribute
     * when projecting an admin attribute list page.
     *
     * @param list<string> $attributeUuids
     * @return array<string,list<array<string,mixed>>> values grouped by attribute_uuid, each list position-ordered
     */
    public function valuesForAttributes(ApplicationContext $context, array $attributeUuids): array
    {
        if ($attributeUuids === []) {
            return [];
        }

        $rows = db($context)->table('commerce_attribute_values')
            ->whereIn('attribute_uuid', $attributeUuids)
            ->orderBy('position', 'ASC')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['attribute_uuid']][] = $row;
        }

        return $grouped;
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
