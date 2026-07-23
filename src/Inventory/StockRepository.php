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

        // Portability: `tracked` is a genuine boolean column (migrations/002) — bind it as a
        // parameter rather than a literal `1` (see StockReportRepository's docblock for why a
        // literal integer comparison against a boolean column fails on PostgreSQL with
        // "operator does not exist: boolean = integer", even though it compiles fine on
        // SQLite/MySQL's integer-affinity booleans).
        $affected = db($context)->table('commerce_stock')->executeModification(
            <<<'SQL'
UPDATE commerce_stock
SET quantity = quantity - ?, updated_at = ?
WHERE tenant_uuid = ? AND variant_uuid = ? AND tracked = ? AND quantity >= ?
SQL,
            [
                $quantity,
                $this->now($context),
                $tenant,
                $variantUuid,
                true,
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

        // Portability: same boolean-column-vs-literal-integer fix as decrement() above.
        $affected = db($context)->table('commerce_stock')->executeModification(
            <<<'SQL'
UPDATE commerce_stock
SET quantity = quantity + ?, updated_at = ?
WHERE tenant_uuid = ? AND variant_uuid = ? AND tracked = ?
SQL,
            [
                $quantity,
                $this->now($context),
                $tenant,
                $variantUuid,
                true,
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

    /**
     * Whitelisted product->variant stock read projection (single-page product
     * editor plan, Task A4): the product's variants are the FROM side,
     * `commerce_stock` LEFT-joined on `variant_uuid` (never the reverse) so a
     * variant with NO matching stock row still surfaces as one result row with
     * `tracked`/`quantity` both `null` -- the only way this query can reveal a
     * missing stock row in ONE read instead of one existence check per variant
     * (Global Constraints: "the read fails loudly"). Callers (see
     * {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::stockForProduct()})
     * turn any `null` row into a thrown {@see StockIntegrityException} --
     * this repository method itself never fabricates a default and never
     * throws.
     *
     * The join carries no additional tenant predicate on `commerce_stock`
     * itself: `variant_uuid` is a globally unique NanoID (the SAME invariant
     * {@see \Glueful\Extensions\Commerce\Reports\StockReportRepository}'s class
     * docblock documents for its own three-table join), so scoping
     * `commerce_variants.tenant_uuid = ?` on the FROM side alone is sufficient.
     * Filtering `commerce_stock.tenant_uuid` in a WHERE clause instead would
     * silently turn this LEFT JOIN into an INNER JOIN, hiding exactly the
     * missing-row rows this read exists to surface.
     *
     * Selects ONLY `commerce_variants.uuid`/`commerce_stock.tracked`/
     * `commerce_stock.quantity` -- never a raw row. Ordered
     * `commerce_variants.position ASC`, then `uuid ASC` as a deterministic
     * tie-break.
     *
     * @return list<array{variant_uuid: string, tracked: ?bool, quantity: ?int}>
     */
    public function stockProjectionsForProduct(
        ApplicationContext $context,
        string $tenant,
        string $productUuid
    ): array {
        $rows = db($context)->table('commerce_variants')
            ->leftJoin('commerce_stock', 'commerce_stock.variant_uuid', '=', 'commerce_variants.uuid')
            ->select([
                'commerce_variants.uuid AS variant_uuid',
                'commerce_stock.tracked AS tracked',
                'commerce_stock.quantity AS quantity',
            ])
            ->where('commerce_variants.tenant_uuid', '=', $tenant)
            ->where('commerce_variants.product_uuid', '=', $productUuid)
            ->orderBy('commerce_variants.position', 'ASC')
            ->orderBy('commerce_variants.uuid', 'ASC')
            ->get();

        return array_map(static function (array $row): array {
            return [
                'variant_uuid' => (string) $row['variant_uuid'],
                'tracked' => $row['tracked'] === null ? null : (bool) $row['tracked'],
                'quantity' => $row['quantity'] === null ? null : (int) $row['quantity'],
            ];
        }, $rows);
    }

    /**
     * Diagnostics-only cross-tenant integrity scan (single-page product
     * editor plan, Task A4; Global Constraints: "diagnostics report the
     * drift"): ONE LEFT JOIN across every variant in EVERY tenant --
     * `commerce_variants` LEFT JOIN `commerce_stock` on `variant_uuid` --
     * returning the exact `{tenant_uuid, product_uuid, variant_uuid}`
     * identity of every variant with no matching stock row
     * (`commerce_stock.uuid IS NULL`). Empty when the install is healthy.
     * Ordered `tenant_uuid, product_uuid, variant_uuid` (all ASC) so the
     * report is deterministic across runs. {@see \Glueful\Extensions\Commerce\Support\DiagnosticsReport}
     * is the only caller.
     *
     * @return list<array{tenant_uuid: string, product_uuid: string, variant_uuid: string}>
     */
    public function variantsMissingStock(ApplicationContext $context): array
    {
        $rows = db($context)->table('commerce_variants')
            ->leftJoin('commerce_stock', 'commerce_stock.variant_uuid', '=', 'commerce_variants.uuid')
            ->select([
                'commerce_variants.tenant_uuid AS tenant_uuid',
                'commerce_variants.product_uuid AS product_uuid',
                'commerce_variants.uuid AS variant_uuid',
            ])
            ->whereRaw('commerce_stock.uuid IS NULL')
            ->orderBy('commerce_variants.tenant_uuid', 'ASC')
            ->orderBy('commerce_variants.product_uuid', 'ASC')
            ->orderBy('commerce_variants.uuid', 'ASC')
            ->get();

        return array_map(static fn (array $row): array => [
            'tenant_uuid' => (string) $row['tenant_uuid'],
            'product_uuid' => (string) $row['product_uuid'],
            'variant_uuid' => (string) $row['variant_uuid'],
        ], $rows);
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
