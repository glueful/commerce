<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Discounts;

use Glueful\Bootstrap\ApplicationContext;
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

    /** @return list<array<string,mixed>> */
    public function listAll(ApplicationContext $context, string $tenant): array
    {
        $rows = db($context)->table('commerce_discounts')
            ->where('tenant_uuid', '=', $tenant)
            ->orderBy('created_at', 'DESC')
            ->get();

        return array_map(fn (array $row): array => $this->decodeJson($row), $rows);
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
