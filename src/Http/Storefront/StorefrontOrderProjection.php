<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

/**
 * Response-boundary whitelist for `commerce_orders` rows on the STOREFRONT wire
 * (`show()`'s fully enriched result and every item `mine()` lists).
 *
 * {@see OrderController::accessCheckedOrder()} intentionally stays internal/raw
 * (minus `guest_token_hash`) -- `downloads()`, `downloadUrl()`, and
 * `retryPayment()` need internal fields (`tenant_uuid`, `marketplace_partitioned`,
 * ...) to do their work. Only the order HTTP response boundaries project through
 * this whitelist. `metadata` is excluded like the storefront PRODUCT
 * projection's `metadata` -- an app-internal channel, never customer-facing.
 * Fail-closed: a column added to `commerce_orders` stays off the storefront
 * wire until named here.
 */
final class StorefrontOrderProjection
{
    /**
     * Every business-facing `commerce_orders` column exposed to the storefront wire, in
     * table order. Excluded internals: `id`, `tenant_uuid`, `guest_token_hash`,
     * `marketplace_partitioned`, `fulfillment_revision`, `refund_revision`, `metadata`.
     */
    public const FIELDS = [
        'uuid',
        'order_number',
        'status',
        'fulfillment_status',
        'tracking_ref',
        'email',
        'user_uuid',
        'currency',
        'subtotal',
        'discount_total',
        'shipping_total',
        'tax_total',
        'grand_total',
        'refunded_total',
        'discount_code',
        'shipping_method',
        'addresses',
        'placed_at',
        'created_at',
        'updated_at',
        // Response-only enrichment keys added by OrderController::authorizedOrder()
        // before projection -- never raw `commerce_orders` columns, but part of the
        // same closed wire shape.
        'refunds',
        'notes',
        'lines',
        'seller_groups',
    ];

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function forStorefront(array $row): array
    {
        return array_intersect_key($row, array_flip(self::FIELDS));
    }
}
