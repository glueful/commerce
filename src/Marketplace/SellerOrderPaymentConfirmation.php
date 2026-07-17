<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\UtcNowSql;

/**
 * The payment-confirmation PII gate stamp (design spec §2.12): a seller must
 * not learn the customer's ship-to address until payment is confirmed, so
 * every child {@see SellerOrderRepository::insertForOrder()} row starts
 * `confirmed_at = null`. This is the ONE place that ever sets it -- called
 * ONLY by {@see \Glueful\Extensions\Commerce\Orders\OrderPaymentService::markPaid()},
 * inside its own transaction, and ONLY for a `marketplace_partitioned`
 * order. There is no listener fallback.
 */
final class SellerOrderPaymentConfirmation
{
    /**
     * Affected-row-safe UPDATE of every child of `$orderUuid` whose
     * `confirmed_at` is currently NULL, using {@see UtcNowSql} for the
     * embedded timestamp (raw SQL, never a bound `formatDateTime()`
     * parameter -- driver-correct UTC regardless of PostgreSQL session
     * timezone, mirroring {@see SellerRepository::claimRevision()}'s own
     * convention). The `confirmed_at IS NULL` guard makes this BOTH
     * idempotent (a re-entry that finds every child already stamped
     * touches zero rows -- a silent no-op, not an error) and immutable (an
     * already-confirmed row can never be re-stamped, so a later
     * cancellation/refund never disturbs it).
     */
    public function confirm(ApplicationContext $context, string $tenant, string $orderUuid): void
    {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        db($context)->table('commerce_seller_orders')->executeModification(
            <<<SQL
UPDATE commerce_seller_orders
SET confirmed_at = {$utcNow}, updated_at = {$utcNow}
WHERE tenant_uuid = ? AND order_uuid = ? AND confirmed_at IS NULL
SQL,
            [$tenant, $orderUuid]
        );
    }
}
