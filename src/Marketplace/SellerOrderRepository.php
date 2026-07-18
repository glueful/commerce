<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\UtcNowSql;
use Glueful\Helpers\Utils;

/**
 * `commerce_seller_orders` reads/writes (design spec §3.3): the immutable
 * per-`(order, seller)` partition {@see \Glueful\Extensions\Commerce\Orders\CheckoutService}
 * creates at checkout.
 */
final class SellerOrderRepository
{
    /**
     * ONE INSERT per `(order, seller)`, called after the parent order (and
     * its per-line attribution) is written, inside the same checkout
     * transaction. `partition_number` is assigned 1-based by ASCENDING
     * `seller_uuid` -- computed HERE, independent of the caller's own row
     * order, so it is the single source of truth for the deterministic
     * numbering the spec requires. `seller_reference` is the deterministic
     * composite `{order_number}-{partition_number}`. Every row starts
     * `confirmed_at = null` (the payment PII gate, design spec §2.12,
     * stamped later by `SellerOrderPaymentConfirmation`), `fulfillment_status
     * = 'unfulfilled'`, `status = 'open'`, `revision = 0` -- `created_at`
     * rides the column's own `CURRENT_TIMESTAMP` default and `updated_at`
     * stays null until the row is first mutated, mirroring
     * {@see \Glueful\Extensions\Commerce\Orders\OrderRepository::insert()}'s
     * own convention for a freshly created row.
     *
     * @param list<array{
     *     order_uuid:string,
     *     order_number:string,
     *     seller_uuid:string,
     *     seller_name_snapshot:string,
     *     currency:string,
     *     subtotal:int,
     *     allocated_discount:int,
     *     allocated_shipping_discount:int,
     *     allocated_shipping:int,
     *     allocated_tax:int,
     *     attributed_total:int,
     *     tax_attribution_method:string
     * }> $sellerOrderRows
     */
    public function insertForOrder(ApplicationContext $context, string $tenant, array $sellerOrderRows): void
    {
        $sorted = $sellerOrderRows;
        usort($sorted, static fn (array $a, array $b): int => $a['seller_uuid'] <=> $b['seller_uuid']);

        $partitionNumber = 0;
        foreach ($sorted as $row) {
            $partitionNumber++;

            db($context)->table('commerce_seller_orders')->insert([
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => $tenant,
                'order_uuid' => $row['order_uuid'],
                'seller_uuid' => $row['seller_uuid'],
                'seller_name_snapshot' => $row['seller_name_snapshot'],
                'partition_number' => $partitionNumber,
                'seller_reference' => $row['order_number'] . '-' . $partitionNumber,
                'currency' => $row['currency'],
                'subtotal' => $row['subtotal'],
                'allocated_discount' => $row['allocated_discount'],
                'allocated_shipping_discount' => $row['allocated_shipping_discount'],
                'allocated_shipping' => $row['allocated_shipping'],
                'allocated_tax' => $row['allocated_tax'],
                'attributed_total' => $row['attributed_total'],
                'tax_attribution_method' => $row['tax_attribution_method'],
                'confirmed_at' => null,
                'fulfillment_status' => 'unfulfilled',
                'status' => 'open',
                'revision' => 0,
            ]);
        }
    }

    /**
     * All children of an order (rollup + operator breakdown reads), ordered
     * by `partition_number` -- the same deterministic order they were
     * created in.
     *
     * @return list<array<string,mixed>>
     */
    public function forOrder(ApplicationContext $context, string $tenant, string $orderUuid): array
    {
        return db($context)->table('commerce_seller_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('partition_number', 'ASC')
            ->get();
    }

    /**
     * The confirmed-scoped seller listing read (design spec §2.12, consumed
     * by Task 8's seller order surfaces): every child order for ONE seller
     * where `confirmed_at IS NOT NULL`. A partition still awaiting payment
     * confirmation (or one whose parent order was canceled before payment)
     * never appears here -- the customer's ship-to PII stays hidden from the
     * seller until {@see SellerOrderPaymentConfirmation::confirm()} has run.
     * Ordered newest-first, matching a typical seller order list.
     *
     * @return list<array<string,mixed>>
     */
    public function confirmedForSeller(ApplicationContext $context, string $tenant, string $sellerUuid): array
    {
        return db($context)->table('commerce_seller_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->whereNotNull('confirmed_at')
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    /**
     * The confirmed-scoped seller detail/fulfillment read (design spec
     * §2.12): the single child order identified by its own `uuid`, scoped to
     * the requesting seller and tenant, returned ONLY when
     * `confirmed_at IS NOT NULL`. Returns null for an unconfirmed partition,
     * one belonging to a different seller/tenant, or an unknown uuid --
     * callers turn that into the non-revealing seller-facing 404 themselves;
     * this method only enforces the read boundary.
     *
     * @return array<string,mixed>|null
     */
    public function confirmedForSellerByUuid(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $sellerOrderUuid
    ): ?array {
        return db($context)->table('commerce_seller_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->where('uuid', '=', $sellerOrderUuid)
            ->whereNotNull('confirmed_at')
            ->first();
    }

    /**
     * Unscoped-by-seller lookup (design spec §2.8): the fulfillment claim
     * chain re-reads the child by ITS OWN uuid before the caller yet knows
     * which seller owns it (an operator fan-out never scopes by seller at
     * all) or whether it is even confirmed -- {@see confirmedForSellerByUuid()}
     * cannot serve that read since it demands both up front. Tenant+uuid
     * scoped only; callers apply the order/seller/confirmation checks
     * themselves and turn a mismatch into their own non-revealing 404.
     *
     * @return array<string,mixed>|null
     */
    public function findByUuid(ApplicationContext $context, string $tenant, string $sellerOrderUuid): ?array
    {
        return db($context)->table('commerce_seller_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $sellerOrderUuid)
            ->first();
    }

    /**
     * Affected-row-checked serialization primitive for a single child
     * (design spec §2.8, §4 lock order): claimed AFTER the parent
     * `fulfillment_revision` ({@see \Glueful\Extensions\Commerce\Orders\OrderRepository::claimFulfillmentMutation()}),
     * mirroring {@see SellerRepository::claimRevision()}'s own claim
     * discipline and its `UtcNowSql` convention. Returns false for an
     * unknown or cross-tenant seller order -- the caller (
     * {@see \Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService})
     * turns that into a non-revealing 404.
     */
    public function claimRevision(ApplicationContext $context, string $tenant, string $sellerOrderUuid): bool
    {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        $affected = db($context)->table('commerce_seller_orders')->executeModification(
            <<<SQL
UPDATE commerce_seller_orders SET revision = revision + 1, updated_at = {$utcNow}
WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [$tenant, $sellerOrderUuid]
        );

        return $affected === 1;
    }

    /**
     * The child transition write (design spec §2.8, step 3): sets a single
     * seller order `fulfillment_status = 'fulfilled'`, stamps `fulfilled_at`
     * (`UtcNowSql`, mirroring {@see SellerOrderPaymentConfirmation::confirm()}'s
     * driver-correct-UTC convention), and applies the carrier/tracking
     * triple. Callers ({@see \Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService})
     * always call this only after successfully claiming the row's own
     * `revision` ({@see claimRevision()}), so no further affected-row check
     * is needed here.
     *
     * @param array{carrier?:?string, tracking_number?:?string, tracking_url?:?string} $tracking
     */
    public function markFulfilled(
        ApplicationContext $context,
        string $tenant,
        string $sellerOrderUuid,
        array $tracking
    ): void {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        db($context)->table('commerce_seller_orders')->executeModification(
            <<<SQL
UPDATE commerce_seller_orders
SET fulfillment_status = 'fulfilled', fulfilled_at = {$utcNow}, carrier = ?, tracking_number = ?,
    tracking_url = ?, updated_at = {$utcNow}
WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [
                $tracking['carrier'] ?? null,
                $tracking['tracking_number'] ?? null,
                $tracking['tracking_url'] ?? null,
                $tenant,
                $sellerOrderUuid,
            ]
        );
    }

    /**
     * Whole-order cancellation fan-out (design spec §2.9): sets EVERY
     * still-open child of `$orderUuid` to `status = 'canceled'`, called by
     * {@see \Glueful\Extensions\Commerce\Http\Admin\AdminOrderController::cancel()}
     * inside the SAME transaction as the parent order's own
     * `pending_payment|paid -> canceled` CAS, for a `marketplace_partitioned`
     * order only. The `status != 'canceled'` guard makes a re-entry a silent
     * no-op (defensive; MV2 has no path that calls this twice for one
     * order). Never touches `fulfillment_status` -- operational status and
     * fulfillment are separate fields (§2.10).
     */
    public function cancelAllForOrder(ApplicationContext $context, string $tenant, string $orderUuid): void
    {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        db($context)->table('commerce_seller_orders')->executeModification(
            <<<SQL
UPDATE commerce_seller_orders
SET status = 'canceled', updated_at = {$utcNow}
WHERE tenant_uuid = ? AND order_uuid = ? AND status != 'canceled'
SQL,
            [$tenant, $orderUuid]
        );
    }
}
