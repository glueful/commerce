<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Discounts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\LiteralLike;
use Glueful\Validation\ValidationException;

final class DiscountRepository
{
    /** @return array<string,mixed>|null */
    public function findByCode(ApplicationContext $context, string $tenant, string $code): ?array
    {
        $row = db($context)->table('commerce_discounts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('code', '=', $code)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        $row = db($context)->table('commerce_discounts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_discounts')->insert($this->encodeJson($row));
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        $changes['updated_at'] = db($context)->getDriver()->formatDateTime();

        db($context)->table('commerce_discounts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($this->encodeJson($changes));
    }

    public function delete(ApplicationContext $context, string $tenant, string $uuid): void
    {
        db($context)->table('commerce_discounts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->delete();
    }

    /**
     * Affected-row-checked serialization primitive mirroring
     * {@see \Glueful\Extensions\Commerce\Shipping\ShippingClassRepository::claimRevision()}:
     * admin PATCH/DELETE both claim the discount row directly (URL's primary
     * resource) before mutating it, so a delete-vs-PATCH race has one winner --
     * a claim on an already-hard-deleted row affects zero rows, which is exactly
     * how a concurrent PATCH is kept from a false-success update after delete.
     * See {@see DiscountService}'s class docblock for the delete-vs-checkout-
     * redemption race this same claim also serializes. Returns false for an
     * unknown or cross-tenant discount.
     */
    public function claimRevision(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $affected = db($context)->table('commerce_discounts')->executeModification(
            <<<'SQL'
UPDATE commerce_discounts SET revision = revision + 1 WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [$tenant, $uuid]
        );

        return $affected === 1;
    }

    /**
     * True if the discount has at least one redemption row -- the post-claim
     * delete-refusal check ({@see DiscountService::delete()}).
     */
    public function hasRedemptions(ApplicationContext $context, string $tenant, string $discountUuid): bool
    {
        return db($context)->table('commerce_discount_redemptions')
            ->where('tenant_uuid', '=', $tenant)
            ->where('discount_uuid', '=', $discountUuid)
            ->count() > 0;
    }

    /** @return list<array<string,mixed>> */
    public function listAll(ApplicationContext $context, string $tenant): array
    {
        $rows = db($context)->table('commerce_discounts')
            ->where('tenant_uuid', '=', $tenant)
            ->orderBy('created_at', 'DESC')
            ->get();

        return array_map(fn (array $row): array => $this->decodeJson($row), $rows);
    }

    /**
     * @param array<string,mixed> $filters 'status' (exact) and/or 'q' (case-insensitive
     *   literal substring on `code`, via {@see LiteralLike})
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function paginatedFor(
        ApplicationContext $context,
        string $tenant,
        array $filters,
        int $page,
        int $perPage
    ): array {
        $count = db($context)->table('commerce_discounts')->where('tenant_uuid', '=', $tenant);
        $rows = db($context)->table('commerce_discounts')->where('tenant_uuid', '=', $tenant);

        if (isset($filters['status']) && (string) $filters['status'] !== '') {
            $count->where('status', '=', (string) $filters['status']);
            $rows->where('status', '=', (string) $filters['status']);
        }
        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $pattern = LiteralLike::pattern($q);
            $count->whereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern]);
            $rows->whereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern]);
        }

        $items = $rows->orderBy('created_at', 'DESC')
            ->orderBy('uuid', 'ASC')
            ->limit($perPage)
            ->offset(max(0, $page - 1) * $perPage)
            ->get();

        return [
            'items' => array_map(fn (array $row): array => $this->decodeJson($row), $items),
            'total' => $count->count(),
        ];
    }

    public function consumeUsage(ApplicationContext $context, string $tenant, string $discountUuid): bool
    {
        $affected = db($context)->table('commerce_discounts')->executeModification(
            <<<'SQL'
UPDATE commerce_discounts
SET usage_count = usage_count + 1, updated_at = ?
WHERE tenant_uuid = ? AND uuid = ? AND (usage_limit IS NULL OR usage_count < usage_limit)
SQL,
            [
                db($context)->getDriver()->formatDateTime(),
                $tenant,
                $discountUuid,
            ]
        );

        return $affected > 0;
    }

    /** @param array<string,mixed> $row */
    public function insertRedemption(ApplicationContext $context, array $row): void
    {
        try {
            db($context)->table('commerce_discount_redemptions')->insert($row);
        } catch (\Throwable $e) {
            throw ValidationException::forField('discount_code', 'Discount already used by this buyer.');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function encodeJson(array $row): array
    {
        if (isset($row['product_scope']) && is_array($row['product_scope'])) {
            $row['product_scope'] = json_encode($row['product_scope'], JSON_THROW_ON_ERROR);
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeJson(array $row): array
    {
        if (isset($row['product_scope']) && is_string($row['product_scope']) && $row['product_scope'] !== '') {
            $decoded = json_decode($row['product_scope'], true);
            $row['product_scope'] = is_array($decoded) ? $decoded : null;
        }

        return $row;
    }
}
