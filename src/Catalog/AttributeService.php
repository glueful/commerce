<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Attribute (global taxonomy) CRUD, per-attribute value CRUD, and product<->attribute
 * set-list assignment.
 *
 * Claim discipline: `commerce_attributes` carries the only revision column in this
 * subtree. PATCH/DELETE on an attribute claim it directly (URL's primary resource --
 * a failed claim is a non-revealing 404). Value create/update/delete claim the
 * OWNING attribute instead of the value row (which carries no revision of its own),
 * so every mutation beneath a given attribute -- its values AND its product
 * assignments -- serializes through one row lock: a value-delete-vs-value-create
 * race, or a value-edit-vs-product-assignment race, has one winner rather than two
 * independent unlocked writers. Attribute delete claims the attribute, then inside
 * the SAME transaction cascades: deletes every value, detaches every product
 * assignment, then deletes the attribute row itself.
 *
 * Value slug uniqueness (`attribute_uuid`, `slug`) is NOT pre-checked by a read: the
 * owning attribute's claim already serializes every writer touching that attribute's
 * values, and the database's own unique index is the backstop, so a duplicate slug
 * surfaces as a caught duplicate-key error turned into a 422 -- mirroring
 * `DiscountRepository::insertRedemption()`, not CategoryService's slug pre-check.
 *
 * Product assignment set-list (`setProductAttributes`) claims the PRODUCT first
 * (`catalog_revision` -- a failed claim is a non-revealing 404, the URL's primary
 * resource), then the union of every attribute_uuid currently assigned to the
 * product and every attribute_uuid referenced in the proposed payload, sorted, so a
 * concurrent attribute delete or value edit serializes against this replacement
 * instead of racing it. Only after every claim succeeds does the mutation re-read
 * each referenced attribute's definition and value slugs and validate the payload;
 * a proposed row naming an attribute that failed to claim (unknown, cross-tenant, or
 * deleted concurrently) is a 422 on the field, never a crash.
 *
 * The join rows themselves are replaced wholesale (delete every existing row for the
 * product, then insert every proposed row) rather than diffed row-by-row: unlike
 * categories/tags, a product-attribute row carries payload (values/flags/position)
 * that can change on every call, and a custom row (`attribute_uuid` null) has no
 * stable identity across calls to diff against in the first place. "Idempotent" here
 * means the same payload submitted twice produces the same resulting CONTENT, not
 * byte-identical internal row uuids. Two rows in the same payload naming the same
 * non-null attribute_uuid collide on the database's own composite unique during this
 * insert step; that collision is caught and turned into a 422 rather than a crash --
 * the claim only proves every DISTINCT referenced attribute exists, it cannot see a
 * duplicate reference within the payload itself, so the database is the actual
 * duplicate guard here, deliberately, per the design brief.
 */
final class AttributeService
{
    public function __construct(
        private AttributeRepository $attributes,
        private ProductRepository $products,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /** @return list<array<string,mixed>> attributes with an embedded `values` list, ordered by position */
    public function list(ApplicationContext $c): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        return array_map(
            fn (array $attribute): array => $this->withValues($c, $attribute),
            $this->attributes->all($c, $tenant)
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(ApplicationContext $c, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $slug = $this->requiredString($input, 'slug');
        $name = $this->requiredString($input, 'name');

        if ($this->attributes->findBySlug($c, $tenant, $slug) !== null) {
            throw ValidationException::forField('slug', 'Slug already in use.');
        }

        $uuid = Utils::generateNanoID();
        $this->attributes->insert($c, [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
            'position' => isset($input['position']) ? (int) $input['position'] : 0,
        ]);

        $attribute = $this->attributes->findByUuid($c, $tenant, $uuid);
        if ($attribute === null) {
            throw new \RuntimeException('Created attribute could not be reloaded.');
        }

        return $this->withValues($c, $attribute);
    }

    /**
     * @param array<string,mixed> $changes name/slug/position -- only present keys are applied
     * @return array<string,mixed>
     */
    public function update(ApplicationContext $c, string $uuid, array $changes): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        return db($c)->transaction(function () use ($c, $tenant, $uuid, $changes): array {
            if (!$this->attributes->claimRevision($c, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->attributes->findByUuid($c, $tenant, $uuid);
            if ($current === null) {
                throw new NotFoundException('Resource not found.');
            }

            $set = [];
            if (array_key_exists('slug', $changes) && $changes['slug'] !== null) {
                $slug = trim((string) $changes['slug']);
                $existing = $this->attributes->findBySlug($c, $tenant, $slug);
                if ($existing !== null && (string) $existing['uuid'] !== $uuid) {
                    throw ValidationException::forField('slug', 'Slug already in use.');
                }
                $set['slug'] = $slug;
            }
            if (array_key_exists('name', $changes) && $changes['name'] !== null) {
                $set['name'] = (string) $changes['name'];
            }
            if (array_key_exists('position', $changes) && $changes['position'] !== null) {
                $set['position'] = (int) $changes['position'];
            }

            if ($set !== []) {
                $this->attributes->update($c, $tenant, $uuid, $set);
            }

            $attribute = $this->attributes->findByUuid($c, $tenant, $uuid);
            if ($attribute === null) {
                throw new \RuntimeException('Updated attribute could not be reloaded.');
            }

            return $this->withValues($c, $attribute);
        });
    }

    /**
     * Claims the attribute, then -- inside the same transaction -- deletes every
     * value, detaches every product assignment, and deletes the attribute row
     * itself. A concurrent value-create or product-assignment attempt against this
     * attribute cannot land once we hold its claim: its own claim on this same row
     * either blocks until we commit (then fails, 0 rows -- a non-revealing 404/422)
     * or, if it committed first, its new row is simply swept up by our cascade.
     */
    public function delete(ApplicationContext $c, string $uuid): void
    {
        $tenant = $this->tenants->tenantUuid($c);

        db($c)->transaction(function () use ($c, $tenant, $uuid): void {
            if (!$this->attributes->claimRevision($c, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }
            if ($this->attributes->findByUuid($c, $tenant, $uuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $this->attributes->deleteValuesForAttribute($c, $uuid);
            $this->attributes->detachProducts($c, $uuid);
            $this->attributes->delete($c, $tenant, $uuid);
        });
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createValue(ApplicationContext $c, string $attributeUuid, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        return db($c)->transaction(function () use ($c, $tenant, $attributeUuid, $input): array {
            if (!$this->attributes->claimRevision($c, $tenant, $attributeUuid)) {
                throw new NotFoundException('Resource not found.');
            }
            if ($this->attributes->findByUuid($c, $tenant, $attributeUuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $slug = $this->requiredString($input, 'slug');
            $value = $this->requiredString($input, 'value');
            $position = isset($input['position'])
                ? (int) $input['position']
                : count($this->attributes->valuesForAttribute($c, $attributeUuid));

            $uuid = Utils::generateNanoID();
            try {
                $this->attributes->insertValue($c, [
                    'uuid' => $uuid,
                    'attribute_uuid' => $attributeUuid,
                    'slug' => $slug,
                    'value' => $value,
                    'position' => $position,
                ]);
            } catch (\Throwable $e) {
                throw ValidationException::forField('slug', 'Slug already in use for this attribute.');
            }

            $row = $this->attributes->findValueByUuid($c, $uuid);
            if ($row === null) {
                throw new \RuntimeException('Created attribute value could not be reloaded.');
            }

            return $row;
        });
    }

    /**
     * @param array<string,mixed> $changes slug/value/position -- only present keys are applied
     * @return array<string,mixed>
     */
    public function updateValue(ApplicationContext $c, string $valueUuid, array $changes): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        // Tenant-agnostic snapshot read purely to discover which attribute to claim
        // -- the URL carries no attribute uuid. Never trusted by itself; the
        // transaction re-reads this row again immediately after the claim succeeds.
        $peek = $this->attributes->findValueByUuid($c, $valueUuid);
        if ($peek === null) {
            throw new NotFoundException('Resource not found.');
        }
        $attributeUuid = (string) $peek['attribute_uuid'];

        return db($c)->transaction(function () use ($c, $tenant, $attributeUuid, $valueUuid, $changes): array {
            if (!$this->attributes->claimRevision($c, $tenant, $attributeUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->attributes->findValueByUuid($c, $valueUuid);
            if ($current === null || (string) $current['attribute_uuid'] !== $attributeUuid) {
                throw new NotFoundException('Resource not found.');
            }

            $set = [];
            if (array_key_exists('value', $changes) && $changes['value'] !== null) {
                $set['value'] = (string) $changes['value'];
            }
            if (array_key_exists('position', $changes) && $changes['position'] !== null) {
                $set['position'] = (int) $changes['position'];
            }
            if (array_key_exists('slug', $changes) && $changes['slug'] !== null) {
                $set['slug'] = trim((string) $changes['slug']);
            }

            if ($set !== []) {
                try {
                    $this->attributes->updateValue($c, $valueUuid, $set);
                } catch (\Throwable $e) {
                    throw ValidationException::forField('slug', 'Slug already in use for this attribute.');
                }
            }

            $row = $this->attributes->findValueByUuid($c, $valueUuid);
            if ($row === null) {
                throw new \RuntimeException('Updated attribute value could not be reloaded.');
            }

            return $row;
        });
    }

    public function deleteValue(ApplicationContext $c, string $valueUuid): void
    {
        $tenant = $this->tenants->tenantUuid($c);

        $peek = $this->attributes->findValueByUuid($c, $valueUuid);
        if ($peek === null) {
            throw new NotFoundException('Resource not found.');
        }
        $attributeUuid = (string) $peek['attribute_uuid'];

        db($c)->transaction(function () use ($c, $tenant, $attributeUuid, $valueUuid): void {
            if (!$this->attributes->claimRevision($c, $tenant, $attributeUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->attributes->findValueByUuid($c, $valueUuid);
            if ($current === null || (string) $current['attribute_uuid'] !== $attributeUuid) {
                throw new NotFoundException('Resource not found.');
            }

            $this->attributes->deleteValue($c, $valueUuid);
        });
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function setProductAttributes(ApplicationContext $c, string $productUuid, array $rows): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        return db($c)->transaction(function () use ($c, $tenant, $productUuid, $rows): array {
            if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }
            if ($this->products->findLiveByUuid($c, $tenant, $productUuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $normalized = $this->normalizeRows($rows);

            $proposedAttributeUuids = array_values(array_unique(array_filter(
                array_map(static fn (array $row): ?string => $row['attribute_uuid'], $normalized)
            )));
            $currentAttributeUuids = $this->attributes->attributeUuidsForProduct($c, $productUuid);

            $claimSet = array_values(array_unique(array_merge($currentAttributeUuids, $proposedAttributeUuids)));
            sort($claimSet);

            $claimed = [];
            foreach ($claimSet as $attributeUuid) {
                $claimed[$attributeUuid] = $this->attributes->claimRevision($c, $tenant, $attributeUuid);
            }

            foreach ($proposedAttributeUuids as $attributeUuid) {
                if (!($claimed[$attributeUuid] ?? false)) {
                    throw ValidationException::forField(
                        'attributes',
                        'attributes must reference existing attributes in this tenant.'
                    );
                }
            }

            $rowsToInsert = [];
            foreach ($normalized as $index => $row) {
                $rowsToInsert[] = $this->planProductAttributeRow($c, $tenant, $productUuid, $index, $row);
            }

            $this->attributes->deleteProductAttributesForProduct($c, $productUuid);

            try {
                foreach ($rowsToInsert as $row) {
                    $this->attributes->insertProductAttribute($c, $row);
                }
            } catch (\Throwable $e) {
                throw ValidationException::forField(
                    'attributes',
                    'attributes must not reference the same attribute more than once.'
                );
            }

            return $this->productAttributesPayload($c, $tenant, $productUuid);
        });
    }

    /**
     * @param array{
     *     attribute_uuid:?string,name:?string,values:list<string>,
     *     used_for_variants:bool,visible:bool,position:?int
     * } $row
     * @return array<string,mixed>
     */
    private function planProductAttributeRow(
        ApplicationContext $c,
        string $tenant,
        string $productUuid,
        int $index,
        array $row
    ): array {
        $attributeUuid = $row['attribute_uuid'];

        if ($attributeUuid !== null) {
            $attribute = $this->attributes->findByUuid($c, $tenant, $attributeUuid);
            if ($attribute === null) {
                throw ValidationException::forField(
                    "attributes.{$index}.attribute_uuid",
                    'attribute_uuid must reference an existing attribute in this tenant.'
                );
            }

            $validSlugs = array_map(
                static fn (array $value): string => (string) $value['slug'],
                $this->attributes->valuesForAttribute($c, $attributeUuid)
            );
            foreach ($row['values'] as $valueIndex => $slug) {
                if (!in_array($slug, $validSlugs, true)) {
                    throw ValidationException::forField(
                        "attributes.{$index}.values.{$valueIndex}",
                        'values must be existing value slugs for this attribute.'
                    );
                }
            }

            return [
                'uuid' => Utils::generateNanoID(),
                'product_uuid' => $productUuid,
                'attribute_uuid' => $attributeUuid,
                'name' => null,
                'values' => json_encode($row['values'], JSON_THROW_ON_ERROR),
                'used_for_variants' => $row['used_for_variants'],
                'visible' => $row['visible'],
                'position' => $row['position'] ?? $index,
            ];
        }

        if ($row['name'] === null || trim($row['name']) === '') {
            throw ValidationException::forField(
                "attributes.{$index}.name",
                'name is required for a custom attribute row.'
            );
        }

        return [
            'uuid' => Utils::generateNanoID(),
            'product_uuid' => $productUuid,
            'attribute_uuid' => null,
            'name' => trim($row['name']),
            'values' => json_encode($row['values'], JSON_THROW_ON_ERROR),
            'used_for_variants' => $row['used_for_variants'],
            'visible' => $row['visible'],
            'position' => $row['position'] ?? $index,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function productAttributesPayload(ApplicationContext $c, string $tenant, string $productUuid): array
    {
        $rows = $this->attributes->productAttributeRows($c, $productUuid);

        $attributeUuids = array_values(array_unique(array_filter(array_column($rows, 'attribute_uuid'))));
        $attributesByUuid = [];
        foreach ($attributeUuids as $attributeUuid) {
            $attribute = $this->attributes->findByUuid($c, $tenant, $attributeUuid);
            if ($attribute !== null) {
                $attributesByUuid[$attributeUuid] = $attribute;
            }
        }

        return array_map(static function (array $row) use ($attributesByUuid): array {
            $attributeUuid = $row['attribute_uuid'];
            $row['attribute_slug'] = $attributeUuid !== null
                ? ($attributesByUuid[$attributeUuid]['slug'] ?? null)
                : null;
            $row['attribute_name'] = $attributeUuid !== null
                ? ($attributesByUuid[$attributeUuid]['name'] ?? null)
                : null;

            return $row;
        }, $rows);
    }

    /**
     * Shape-checks each raw row before any DB work: `attribute_uuid` xor a non-empty
     * `name` is resolved later (post-claim, in planProductAttributeRow -- it needs
     * the tenant lookup), but `values` is normalized to a list<string> and flags are
     * coerced to bool/int here so downstream code never juggles raw request types.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{attribute_uuid:?string,name:?string,values:list<string>,used_for_variants:bool,visible:bool,position:?int}>
     */
    private function normalizeRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw ValidationException::forField("attributes.{$index}", 'Each attribute row must be an object.');
            }

            $attributeUuid = isset($row['attribute_uuid']) && $row['attribute_uuid'] !== null
                ? trim((string) $row['attribute_uuid'])
                : null;
            $attributeUuid = $attributeUuid === '' ? null : $attributeUuid;

            $name = isset($row['name']) && $row['name'] !== null ? (string) $row['name'] : null;

            $rawValues = $row['values'] ?? [];
            if (!is_array($rawValues)) {
                throw ValidationException::forField("attributes.{$index}.values", 'values must be an array.');
            }
            $values = [];
            foreach ($rawValues as $valueIndex => $value) {
                if (!is_string($value) || trim($value) === '') {
                    throw ValidationException::forField(
                        "attributes.{$index}.values.{$valueIndex}",
                        'Each value must be a non-empty string.'
                    );
                }
                $values[] = trim($value);
            }

            $result[] = [
                'attribute_uuid' => $attributeUuid,
                'name' => $name,
                'values' => $values,
                'used_for_variants' => (bool) ($row['used_for_variants'] ?? false),
                'visible' => (bool) ($row['visible'] ?? true),
                'position' => isset($row['position']) ? (int) $row['position'] : null,
            ];
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function withValues(ApplicationContext $c, array $attribute): array
    {
        $attribute['values'] = array_map(static fn (array $value): array => [
            'uuid' => (string) $value['uuid'],
            'slug' => (string) $value['slug'],
            'value' => (string) $value['value'],
            'position' => (int) $value['position'],
        ], $this->attributes->valuesForAttribute($c, (string) $attribute['uuid']));

        return $attribute;
    }

    /** @param array<string,mixed> $input */
    private function requiredString(array $input, string $field): string
    {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value === '') {
            throw ValidationException::forField($field, ucfirst($field) . ' is required.');
        }

        return $value;
    }
}
