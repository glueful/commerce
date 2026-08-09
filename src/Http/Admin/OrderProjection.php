<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

/**
 * Response-boundary whitelist for `commerce_orders` rows on the ADMIN wire
 * (orders index/show, cancel/mark-paid/fulfill responses, and the customer
 * detail's embedded recent orders).
 *
 * The repositories intentionally return full rows -- internal logic needs the
 * dropped columns (`marketplace_partitioned` keys the seller_orders embed and
 * cancellation fan-out, `guest_token_hash` backs the storefront access check,
 * the `*_revision` counters back CAS) and event payloads carry the RAW row for
 * listeners/webhook fan-out. Only the HTTP response projects. Fail-closed: a
 * column added to the table stays off the admin wire until named here.
 *
 * Admin-order-creation cycle 2, Task 6 (design spec §2.6/§2.9): the five
 * finalized walk-in fields (`customer_name`, `phone_normalized`,
 * `phone_display`, `fulfillment_mode`, `origin`) are added here so a
 * finalized walk-in order stays fully operable on the ordinary admin order
 * surface. `draft_revision` and every `commerce_order_draft_attempts` field
 * stay OFF this projection on purpose -- draft state belongs to the
 * dedicated draft projection (a later task), not the finalized-order wire.
 */
final class OrderProjection
{
    /**
     * Every business-facing `commerce_orders` column, in table order. Excluded
     * internals: `id`, `tenant_uuid`, `guest_token_hash`,
     * `marketplace_partitioned`, `fulfillment_revision`, `refund_revision`,
     * `draft_revision`.
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
        'metadata',
        'placed_at',
        'created_at',
        'updated_at',
        'customer_name',
        'phone_normalized',
        'phone_display',
        'fulfillment_mode',
        'origin',
    ];

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function forAdmin(array $row): array
    {
        return array_intersect_key($row, array_flip(self::FIELDS));
    }
}
