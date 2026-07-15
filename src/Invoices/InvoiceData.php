<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Invoices;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\AddonSnapshot;

/**
 * Pure assembly of the versioned invoice-data payload (spec §7). No I/O: the
 * controller resolves the tenant, 404-guards the order, and reads lines/refunds/
 * seller identity; this class only shapes those already-fetched values, which is
 * what keeps it unit-testable without a database.
 *
 * All `*_minor` fields are integer minor units in `order.currency`. The
 * `refunds` list applies the completed-only filter and key whitelist itself
 * (never `reason` or any other refund column) — callers may pass every refund
 * row from `RefundRepository::listForOrder()` regardless of status.
 */
final class InvoiceData
{
    /**
     * @param array<string,mixed> $order decoded `commerce_orders` row
     * @param list<array<string,mixed>> $lines decoded `commerce_order_lines` rows
     * @param list<array<string,mixed>> $refunds raw rows from
     *        {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository::listForOrder()}
     *        (any status; this method filters to completed only)
     * @param array{name:?string,address:?string,tax_id:?string} $seller
     * @return array<string,mixed>
     */
    public static function build(
        ApplicationContext $context,
        array $order,
        array $lines,
        array $refunds,
        array $seller
    ): array {
        return [
            'schema_version' => 1,
            'seller' => [
                'name' => $seller['name'] ?? null,
                'address' => $seller['address'] ?? null,
                'tax_id' => $seller['tax_id'] ?? null,
            ],
            'buyer' => [
                'email' => $order['email'] ?? null,
                'addresses' => $order['addresses'] ?? null,
            ],
            'order' => [
                'number' => $order['order_number'] ?? null,
                'dates' => [
                    'placed_at' => $order['placed_at'] ?? null,
                    'created_at' => $order['created_at'] ?? null,
                    'updated_at' => $order['updated_at'] ?? null,
                ],
                'currency' => $order['currency'] ?? null,
                'status' => $order['status'] ?? null,
            ],
            'lines' => array_values(array_map(
                static fn (array $line): array => [
                    'name' => (string) ($line['product_name'] ?? ''),
                    'sku' => (string) ($line['sku'] ?? ''),
                    'quantity' => (int) ($line['quantity'] ?? 0),
                    'unit_minor' => (int) ($line['unit_price'] ?? 0),
                    'subtotal_minor' => (int) ($line['line_total'] ?? 0),
                    'addons' => AddonSnapshot::sanitize(is_array($line['addons'] ?? null) ? $line['addons'] : []),
                ],
                $lines
            )),
            'totals' => [
                'subtotal_minor' => (int) ($order['subtotal'] ?? 0),
                'discount_minor' => (int) ($order['discount_total'] ?? 0),
                'shipping_minor' => (int) ($order['shipping_total'] ?? 0),
                'tax_minor' => (int) ($order['tax_total'] ?? 0),
                'grand_minor' => (int) ($order['grand_total'] ?? 0),
                'refunded_minor' => (int) ($order['refunded_total'] ?? 0),
            ],
            'refunds' => array_values(array_map(
                static fn (array $refund): array => [
                    'date' => $refund['completed_at'] ?? null,
                    'amount_minor' => (int) ($refund['amount'] ?? 0),
                    'method' => (string) ($refund['method'] ?? ''),
                ],
                array_values(array_filter(
                    $refunds,
                    static fn (array $refund): bool => ($refund['status'] ?? null) === 'completed'
                ))
            )),
        ];
    }
}
