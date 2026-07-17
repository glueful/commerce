<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
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
}
