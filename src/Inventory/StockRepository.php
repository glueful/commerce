<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Inventory;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;

final class StockRepository
{
    public function ensureRow(ApplicationContext $context, string $tenant, string $variantUuid, bool $tracked): void
    {
        try {
            db($context)->table('commerce_stock')->insert([
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => $tenant,
                'variant_uuid' => $variantUuid,
                'quantity' => 0,
                'tracked' => $tracked ? 1 : 0,
            ]);
        } catch (\Throwable) {
            // The unique (tenant_uuid, variant_uuid) index is the idempotency guard.
        }
    }

    public function decrement(ApplicationContext $context, string $tenant, string $variantUuid, int $quantity): bool
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }

        $affected = db($context)->table('commerce_stock')->executeModification(
            <<<'SQL'
UPDATE commerce_stock
SET quantity = quantity - ?, updated_at = ?
WHERE tenant_uuid = ? AND variant_uuid = ? AND tracked = 1 AND quantity >= ?
SQL,
            [
                $quantity,
                $this->now($context),
                $tenant,
                $variantUuid,
                $quantity,
            ]
        );

        return $affected > 0;
    }

    public function increment(ApplicationContext $context, string $tenant, string $variantUuid, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }

        db($context)->table('commerce_stock')->executeModification(
            <<<'SQL'
UPDATE commerce_stock
SET quantity = quantity + ?, updated_at = ?
WHERE tenant_uuid = ? AND variant_uuid = ?
SQL,
            [
                $quantity,
                $this->now($context),
                $tenant,
                $variantUuid,
            ]
        );
    }

    /**
     * Affected-row-checked increment: only succeeds while the row still exists and is
     * tracked. Used by refund restocking so a stock row disappearing (or losing tracking)
     * between validation and completion aborts the refund instead of silently no-op'ing.
     */
    public function incrementChecked(
        ApplicationContext $context,
        string $tenant,
        string $variantUuid,
        int $quantity
    ): bool {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }

        $affected = db($context)->table('commerce_stock')->executeModification(
            <<<'SQL'
UPDATE commerce_stock
SET quantity = quantity + ?, updated_at = ?
WHERE tenant_uuid = ? AND variant_uuid = ? AND tracked = 1
SQL,
            [
                $quantity,
                $this->now($context),
                $tenant,
                $variantUuid,
            ]
        );

        return $affected === 1;
    }

    public function isTracked(ApplicationContext $context, string $tenant, string $variantUuid): bool
    {
        $row = db($context)->table('commerce_stock')
            ->where('tenant_uuid', '=', $tenant)
            ->where('variant_uuid', '=', $variantUuid)
            ->first();

        return $row !== null && (int) ($row['tracked'] ?? 0) === 1;
    }

    /**
     * Distinguishes "row missing" from "row exists but untracked" — `isTracked()` collapses
     * both to `false`, which is safe for callers that only ever skip on either outcome. Refund
     * restocking needs the distinction: a missing row is an integrity violation (the variant's
     * stock record disappeared between validation and completion), while `tracked = false` is a
     * legitimate, unchanged no-op (mirrors cancel()'s releaseStock).
     *
     * @return bool|null null when no stock row exists for this tenant/variant.
     */
    public function trackedState(ApplicationContext $context, string $tenant, string $variantUuid): ?bool
    {
        $row = db($context)->table('commerce_stock')
            ->where('tenant_uuid', '=', $tenant)
            ->where('variant_uuid', '=', $variantUuid)
            ->first();

        return $row === null ? null : (int) ($row['tracked'] ?? 0) === 1;
    }

    public function quantity(ApplicationContext $context, string $tenant, string $variantUuid): int
    {
        $row = db($context)->table('commerce_stock')
            ->where('tenant_uuid', '=', $tenant)
            ->where('variant_uuid', '=', $variantUuid)
            ->first();

        return $row === null ? 0 : (int) ($row['quantity'] ?? 0);
    }

    public function recordMovement(
        ApplicationContext $context,
        string $tenant,
        string $variantUuid,
        int $delta,
        string $reason,
        ?string $referenceUuid = null,
    ): void {
        db($context)->table('commerce_stock_movements')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $tenant,
            'variant_uuid' => $variantUuid,
            'delta' => $delta,
            'reason' => $reason,
            'reference_uuid' => $referenceUuid,
        ]);
    }

    private function now(ApplicationContext $context): string
    {
        return db($context)->getDriver()->formatDateTime();
    }
}
