<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Add-on definition CRUD for a product's `commerce_product_addons` rows.
 *
 * Every mutation claims the product's `catalog_revision` FIRST (affected-row-checked
 * UPDATE), then re-reads state before writing -- the same claim-then-re-read
 * discipline as {@see ProductMediaService}: the claimed row lock is the real
 * serialization primitive, and any read taken before the claim is a snapshot only,
 * never trusted for a business decision.
 *
 * A definition edit never touches an existing cart/order line: {@see
 * \Glueful\Extensions\Commerce\Cart\AddonSnapshot} bakes display AND price fields
 * into the line's snapshot at selection time and hashes the full snapshot, so an
 * edited definition simply produces a new hash (and therefore a new line) for any
 * FUTURE selection -- it cannot mutate what's already persisted.
 *
 * `select` addons require a non-empty `choices` list with unique, non-empty keys and
 * a signed integer `price_delta` per choice; `checkbox`/`text` addons instead carry a
 * single signed integer `price_delta` on the row itself and never a `choices` array
 * (any `choices` submitted for a non-select field_type is silently ignored, not
 * rejected -- the column is nullable and simply unused for those field types).
 */
final class AddonService
{
    private const FIELD_TYPES = ['select', 'checkbox', 'text'];
    private const STATUSES = ['active', 'inactive'];

    public function __construct(
        private AddonRepository $addons,
        private ProductRepository $products,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /** @return list<array<string,mixed>> every definition for the product, ordered by position */
    public function list(ApplicationContext $c, string $productUuid): array
    {
        return $this->addons->forProduct($c, $this->tenants->tenantUuid($c), $productUuid);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(ApplicationContext $c, string $productUuid, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        return db($c)->transaction(function () use ($c, $tenant, $productUuid, $input): array {
            if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            // Post-claim re-read: the product must still exist under this tenant
            // now that we hold its claim (the pre-claim caller never checked this).
            if ($this->products->findLiveByUuid($c, $tenant, $productUuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $name = $this->requiredString($input, 'name');
            $fieldType = $this->requiredFieldType($input['field_type'] ?? null);
            $choices = $this->normalizeChoices($fieldType, $input['choices'] ?? null);
            $priceDelta = $fieldType === 'select' ? 0 : $this->requiredPriceDelta($input['price_delta'] ?? 0);
            $existing = $this->addons->forProduct($c, $tenant, $productUuid);
            $position = isset($input['position']) ? (int) $input['position'] : count($existing);

            $uuid = Utils::generateNanoID();
            $this->addons->insert($c, [
                'uuid' => $uuid,
                'tenant_uuid' => $tenant,
                'product_uuid' => $productUuid,
                'name' => $name,
                'field_type' => $fieldType,
                'required' => (bool) ($input['required'] ?? false),
                'choices' => $choices,
                'price_delta' => $priceDelta,
                'position' => $position,
                'status' => $this->normalizeStatus($input['status'] ?? 'active'),
            ]);

            $row = $this->addons->findByUuid($c, $tenant, $uuid);
            if ($row === null) {
                throw new \RuntimeException('Created add-on could not be reloaded.');
            }

            return $row;
        });
    }

    /**
     * @param array<string,mixed> $changes only present keys are applied
     * @return array<string,mixed>
     */
    public function update(ApplicationContext $c, string $uuid, array $changes): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        // Tenant-scoped snapshot read purely to discover which product to claim --
        // the URL carries no product uuid. Never trusted by itself; the
        // transaction re-reads this row again immediately after the claim succeeds.
        $peek = $this->addons->findByUuid($c, $tenant, $uuid);
        if ($peek === null) {
            throw new NotFoundException('Resource not found.');
        }
        $productUuid = (string) $peek['product_uuid'];

        return db($c)->transaction(function () use ($c, $tenant, $productUuid, $uuid, $changes): array {
            if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->addons->findByUuid($c, $tenant, $uuid);
            if ($current === null || (string) $current['product_uuid'] !== $productUuid) {
                throw new NotFoundException('Resource not found.');
            }

            $set = $this->planUpdate($changes, $current);
            if ($set !== []) {
                $this->addons->update($c, $tenant, $uuid, $set);
            }

            $row = $this->addons->findByUuid($c, $tenant, $uuid);
            if ($row === null) {
                throw new \RuntimeException('Updated add-on could not be reloaded.');
            }

            return $row;
        });
    }

    public function delete(ApplicationContext $c, string $uuid): void
    {
        $tenant = $this->tenants->tenantUuid($c);

        $peek = $this->addons->findByUuid($c, $tenant, $uuid);
        if ($peek === null) {
            throw new NotFoundException('Resource not found.');
        }
        $productUuid = (string) $peek['product_uuid'];

        db($c)->transaction(function () use ($c, $tenant, $productUuid, $uuid): void {
            if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->addons->findByUuid($c, $tenant, $uuid);
            if ($current === null || (string) $current['product_uuid'] !== $productUuid) {
                throw new NotFoundException('Resource not found.');
            }

            $this->addons->delete($c, $tenant, $uuid);
        });
    }

    /**
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    private function planUpdate(array $changes, array $current): array
    {
        $set = [];
        if (array_key_exists('name', $changes) && $changes['name'] !== null) {
            $set['name'] = $this->requiredString($changes, 'name');
        }
        if (array_key_exists('required', $changes) && $changes['required'] !== null) {
            $set['required'] = (bool) $changes['required'];
        }
        if (array_key_exists('position', $changes) && $changes['position'] !== null) {
            $set['position'] = (int) $changes['position'];
        }
        if (array_key_exists('status', $changes) && $changes['status'] !== null) {
            $set['status'] = $this->normalizeStatus($changes['status']);
        }

        $touchesShape = array_key_exists('field_type', $changes)
            || array_key_exists('choices', $changes)
            || array_key_exists('price_delta', $changes);
        if (!$touchesShape) {
            return $set;
        }

        $fieldType = array_key_exists('field_type', $changes) && $changes['field_type'] !== null
            ? $this->requiredFieldType($changes['field_type'])
            : (string) $current['field_type'];

        $choicesSource = array_key_exists('choices', $changes) ? $changes['choices'] : $current['choices'];
        $set['field_type'] = $fieldType;
        $set['choices'] = $this->normalizeChoices($fieldType, $choicesSource);

        if ($fieldType === 'select') {
            $set['price_delta'] = 0;
        } elseif (array_key_exists('price_delta', $changes) && $changes['price_delta'] !== null) {
            $set['price_delta'] = $this->requiredPriceDelta($changes['price_delta']);
        } else {
            $set['price_delta'] = (int) $current['price_delta'];
        }

        return $set;
    }

    private function requiredFieldType(mixed $raw): string
    {
        $fieldType = (string) $raw;
        if (!in_array($fieldType, self::FIELD_TYPES, true)) {
            throw ValidationException::forField(
                'field_type',
                'field_type must be one of: ' . implode(', ', self::FIELD_TYPES) . '.'
            );
        }

        return $fieldType;
    }

    private function normalizeStatus(mixed $raw): string
    {
        $status = (string) $raw;
        if (!in_array($status, self::STATUSES, true)) {
            throw ValidationException::forField(
                'status',
                'status must be one of: ' . implode(', ', self::STATUSES) . '.'
            );
        }

        return $status;
    }

    /**
     * `select` requires a non-empty choices list with unique, non-empty keys and a
     * signed integer price_delta per choice; every other field_type gets `null`
     * regardless of what was submitted (choices are select-only, per the design
     * spec's schema).
     *
     * @return list<array{key:string,label:string,price_delta:int}>|null
     */
    private function normalizeChoices(string $fieldType, mixed $raw): ?array
    {
        if ($fieldType !== 'select') {
            return null;
        }

        if (!is_array($raw) || $raw === []) {
            throw ValidationException::forField('choices', 'select addons require a non-empty choices list.');
        }

        $seenKeys = [];
        $choices = [];
        foreach (array_values($raw) as $index => $choice) {
            if (!is_array($choice)) {
                throw ValidationException::forField("choices.{$index}", 'Each choice must be an object.');
            }

            $key = isset($choice['key']) ? trim((string) $choice['key']) : '';
            if ($key === '') {
                throw ValidationException::forField("choices.{$index}.key", 'key is required.');
            }
            if (isset($seenKeys[$key])) {
                throw ValidationException::forField("choices.{$index}.key", 'Duplicate choice key.');
            }
            $seenKeys[$key] = true;

            $label = isset($choice['label']) ? trim((string) $choice['label']) : '';
            if ($label === '') {
                throw ValidationException::forField("choices.{$index}.label", 'label is required.');
            }

            if (!isset($choice['price_delta']) || !is_int($choice['price_delta'])) {
                throw ValidationException::forField(
                    "choices.{$index}.price_delta",
                    'price_delta must be a signed integer.'
                );
            }

            $choices[] = ['key' => $key, 'label' => $label, 'price_delta' => $choice['price_delta']];
        }

        return $choices;
    }

    private function requiredPriceDelta(mixed $raw): int
    {
        if (!is_int($raw)) {
            throw ValidationException::forField('price_delta', 'price_delta must be a signed integer.');
        }

        return $raw;
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
