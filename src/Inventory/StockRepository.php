<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Inventory;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\StorefrontCatalogChangeDispatcher;
use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Glueful\Helpers\Utils;

/**
 * The single write chokepoint for `commerce_stock.quantity` -- every stock
 * mutation in this codebase (direct operator/seller adjustment via
 * {@see \Glueful\Extensions\Commerce\Inventory\InventoryService::adjust()},
 * checkout decrement, and refund/cancel/expiry restock) lands here, never
 * on a raw table write of its own. `$catalogEvents` (Slice-2 Task 1, design
 * spec §9) is an APPENDED OPTIONAL collaborator -- the SAME "every
 * pre-existing direct-construction call site (tests included) stays
 * source-compatible" convention this codebase uses throughout -- backing
 * {@see self::captureStockChanged()} ONLY. This is deliberately the
 * `StorefrontCatalogChanged(reason: stock.changed)` insertion point rather
 * than each individual caller: every caller above reaches quantity changes
 * exclusively through {@see self::decrement()}/{@see self::increment()}/
 * {@see self::incrementChecked()}, so instrumenting them here is the one
 * chokepoint a future caller cannot bypass by construction, unlike
 * per-caller capture.
 */
final class StockRepository
{
    public function __construct(
        private ?StorefrontCatalogChangeDispatcher $catalogEvents = null,
    ) {
    }

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
        } catch (\Throwable $e) {
            // Suppress only a verified idempotent duplicate. Any unrelated database
            // failure must abort catalog creation rather than turning the variant into
            // an implicitly-untracked, unlimited-stock item.
            $existing = $this->trackedState($context, $tenant, $variantUuid);
            if ($existing === null) {
                throw $e;
            }
            if ($existing !== $tracked) {
                throw new \RuntimeException('Existing stock tracking mode does not match the variant.', 0, $e);
            }
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

        $ok = $affected > 0;
        if ($ok) {
            $this->captureStockChanged($context, $tenant, $variantUuid);
        }

        return $ok;
    }

    public function increment(ApplicationContext $context, string $tenant, string $variantUuid, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }

        $affected = db($context)->table('commerce_stock')->executeModification(
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

        // Review F2: mirror the affected-row gating decrement()/incrementChecked()
        // already apply — a no-op UPDATE (missing row) changed nothing storefront-
        // visible and must not signal an invalidation.
        if ($affected > 0) {
            $this->captureStockChanged($context, $tenant, $variantUuid);
        }
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

        $ok = $affected === 1;
        if ($ok) {
            $this->captureStockChanged($context, $tenant, $variantUuid);
        }

        return $ok;
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

    /**
     * `StorefrontCatalogChanged(reason: stock.changed)` capture (Slice-2 Task
     * 1, design spec §9): the ONLY call sites, from every successful
     * quantity-changing write above -- never {@see self::ensureRow()}'s
     * initial zero-quantity row insert, which is part of product/variant
     * CREATE's own `product.created`/`variant.changed` signal, not a stock
     * change. Off (no bound {@see StorefrontCatalogChangeDispatcher}) is a
     * zero-query no-op, preserving byte-parity for every pre-Task-1 direct-
     * construction call site (tests included). Resolves the owning product
     * INSIDE this call (never trusted from a caller) so this is the one
     * place that can never be bypassed or forgotten by a future stock
     * writer; a variant whose product cannot be resolved fires the event
     * with a null `productUuid` rather than skipping it entirely.
     */
    private function captureStockChanged(ApplicationContext $context, string $tenant, string $variantUuid): void
    {
        if ($this->catalogEvents === null) {
            return;
        }

        // No variant→product lookup here (slice-2 review F1): this chokepoint
        // runs on EVERY stock write in every install, so resolving
        // product_uuid would add one SELECT per line on the hot checkout
        // path vs 1.2.1. Purge consumers act on the tenant, never the
        // product, so `stock.changed` carries a null productUuid by design —
        // exactly like the broad taxonomy reasons.
        $this->catalogEvents->dispatch($context, $tenant, null, StorefrontCatalogChanged::REASON_STOCK_CHANGED);
    }
}
