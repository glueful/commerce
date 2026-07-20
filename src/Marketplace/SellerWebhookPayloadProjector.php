<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * The MV5c-2 Task 4 ISOLATION BOUNDARY (design spec §2.3 -- "the security
 * heart"): builds the exact, sanitized per-seller webhook payload for one of
 * the nine {@see SellerWebhookEventCatalog} event types.
 *
 * {@see self::project()} receives ONLY the one participating seller's own
 * PRE-SCOPED data slice -- never the whole capture `$context`, never another
 * seller's slice, never a raw database row. {@see SellerWebhookOutboxPublisher::capture()}
 * is the ONLY caller and it hands this class exactly `$context['data'][$sellerUuid]`,
 * so a bug in THIS class can never reach across sellers even in principle
 * (defense in depth #1 -- the cross-seller boundary is enforced by what data
 * physically reaches this method, not by a check inside it). Every
 * per-event-type method below then builds a FRESH named-key array,
 * explicitly listing every field it emits (defense in depth #2 -- never
 * `array_merge`, never a spread, never "the row minus a blocklist"): no
 * other seller's fields (structurally impossible per #1), no buyer PII
 * beyond what the seller legitimately owns to fulfill/ship an order it is
 * a party to, no secrets (webhook signing secrets, encryption ciphertexts,
 * API keys, internal tokens -- none of which any call site ever puts in a
 * data slice to begin with), and no marketplace-internal columns
 * (`tenant_uuid`, `revision`, internal lifecycle enums, ledger/commission
 * plumbing) beyond the seller-visible commission/attribution figures design
 * spec §2.3/§6.2 already treats as seller-facing elsewhere in this codebase
 * (e.g. {@see \Glueful\Extensions\Commerce\Http\Admin\AdminOrderController::sellerOrderProjection()}).
 */
final class SellerWebhookPayloadProjector
{
    /**
     * @param array<string,mixed> $sellerData this seller's own pre-scoped data slice -- see
     *     {@see SellerWebhookOutboxPublisher}'s class docblock for the exact per-event-type shape
     * @return array<string,mixed> the sanitized, signable payload (JSON-encoded verbatim by the caller)
     */
    public function project(string $eventType, string $sellerUuid, array $sellerData): array
    {
        return match ($eventType) {
            'order.placed' => $this->orderLifecycle('order.placed', $sellerUuid, $sellerData, includeLines: true),
            'order.paid' => $this->orderLifecycle('order.paid', $sellerUuid, $sellerData, includeLines: false),
            'order.canceled' => $this->orderCanceled($sellerUuid, $sellerData),
            'seller_order.fulfilled' => $this->sellerOrderFulfilled($sellerUuid, $sellerData),
            'refund.completed' => $this->refundCompleted($sellerUuid, $sellerData),
            'payout.recorded' => $this->payoutRecorded($sellerUuid, $sellerData),
            'stock.adjusted' => $this->stockAdjusted($sellerUuid, $sellerData),
            'product.adopted' => $this->productAdopted($sellerUuid, $sellerData),
            'product.transferred' => $this->productTransferred($sellerUuid, $sellerData),
            default => throw new \InvalidArgumentException(
                "SellerWebhookPayloadProjector: unknown/uncataloged event type '{$eventType}'."
            ),
        };
    }

    /**
     * Shared shape for `order.placed`/`order.paid` (design spec §2.3): this
     * seller's OWN seller-order allocation snapshot -- never the parent
     * order's grand total, never another seller's allocation, never the
     * buyer's shipping/billing address (irrelevant to "an order was
     * placed/paid" and not carried in the data slice at all). `order.placed`
     * additionally carries this seller's OWN order lines only.
     *
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private function orderLifecycle(string $eventType, string $sellerUuid, array $d, bool $includeLines): array
    {
        $attributedTotal = (int) ($d['attributed_total'] ?? 0);
        $commissionAmount = (int) ($d['commission_amount'] ?? 0);

        $payload = [
            'event' => $eventType,
            'seller_uuid' => $sellerUuid,
            'order_uuid' => (string) ($d['order_uuid'] ?? ''),
            'order_number' => (string) ($d['order_number'] ?? ''),
            'currency' => (string) ($d['currency'] ?? ''),
            'occurred_at' => (string) ($d['occurred_at'] ?? ''),
            'seller_order_uuid' => (string) ($d['seller_order_uuid'] ?? ''),
            'seller_reference' => (string) ($d['seller_reference'] ?? ''),
            'subtotal' => (int) ($d['subtotal'] ?? 0),
            'allocated_discount' => (int) ($d['allocated_discount'] ?? 0),
            'allocated_shipping' => (int) ($d['allocated_shipping'] ?? 0),
            'allocated_tax' => (int) ($d['allocated_tax'] ?? 0),
            'attributed_total' => $attributedTotal,
            'commission_amount' => $commissionAmount,
            'net_amount' => $attributedTotal - $commissionAmount,
        ];

        if ($includeLines) {
            $lines = is_array($d['lines'] ?? null) ? $d['lines'] : [];
            $payload['lines'] = array_values(array_map(static function (mixed $line): array {
                $line = is_array($line) ? $line : [];

                return [
                    'sku' => (string) ($line['sku'] ?? ''),
                    'product_name' => (string) ($line['product_name'] ?? ''),
                    'quantity' => (int) ($line['quantity'] ?? 0),
                    'unit_price' => (int) ($line['unit_price'] ?? 0),
                    'line_total' => (int) ($line['unit_price'] ?? 0) * (int) ($line['quantity'] ?? 0),
                ];
            }, $lines));
        }

        return $payload;
    }

    /** @param array<string,mixed> $d */
    private function orderCanceled(string $sellerUuid, array $d): array
    {
        return [
            'event' => 'order.canceled',
            'seller_uuid' => $sellerUuid,
            'order_uuid' => (string) ($d['order_uuid'] ?? ''),
            'order_number' => (string) ($d['order_number'] ?? ''),
            'currency' => (string) ($d['currency'] ?? ''),
            'occurred_at' => (string) ($d['occurred_at'] ?? ''),
            'seller_order_uuid' => (string) ($d['seller_order_uuid'] ?? ''),
            'seller_reference' => (string) ($d['seller_reference'] ?? ''),
            'attributed_total' => (int) ($d['attributed_total'] ?? 0),
            // 'operator' | 'expired' -- which of the two design spec §2.3
            // cancellation authorities produced this snapshot.
            'cancellation_source' => (string) ($d['cancellation_source'] ?? 'operator'),
        ];
    }

    /** @param array<string,mixed> $d */
    private function sellerOrderFulfilled(string $sellerUuid, array $d): array
    {
        return [
            'event' => 'seller_order.fulfilled',
            'seller_uuid' => $sellerUuid,
            'order_uuid' => (string) ($d['order_uuid'] ?? ''),
            'seller_order_uuid' => (string) ($d['seller_order_uuid'] ?? ''),
            'seller_reference' => (string) ($d['seller_reference'] ?? ''),
            'occurred_at' => (string) ($d['occurred_at'] ?? ''),
            'carrier' => isset($d['carrier']) ? (string) $d['carrier'] : null,
            'tracking_number' => isset($d['tracking_number']) ? (string) $d['tracking_number'] : null,
            'tracking_url' => isset($d['tracking_url']) ? (string) $d['tracking_url'] : null,
        ];
    }

    /**
     * `refund.completed` (design spec §2.3): this seller's OWN attributed
     * cash amount (the sum of THIS refund's persisted lines that belong to
     * this seller's order lines -- never the refund's whole grand amount,
     * never another seller's attributed lines, never the internal ledger
     * `delta_R`/commission-reversal math {@see LedgerPostingService::postRefund()}
     * computes for settlement -- that is marketplace/accounting plumbing,
     * not a seller-facing figure this event type carries).
     *
     * @param array<string,mixed> $d
     */
    private function refundCompleted(string $sellerUuid, array $d): array
    {
        $lines = is_array($d['lines'] ?? null) ? $d['lines'] : [];

        return [
            'event' => 'refund.completed',
            'seller_uuid' => $sellerUuid,
            'order_uuid' => (string) ($d['order_uuid'] ?? ''),
            'refund_uuid' => (string) ($d['refund_uuid'] ?? ''),
            'currency' => (string) ($d['currency'] ?? ''),
            'occurred_at' => (string) ($d['occurred_at'] ?? ''),
            'amount' => (int) ($d['amount'] ?? 0),
            'reason' => isset($d['reason']) ? (string) $d['reason'] : null,
            'lines' => array_values(array_map(static function (mixed $line): array {
                $line = is_array($line) ? $line : [];

                return [
                    'order_line_uuid' => (string) ($line['order_line_uuid'] ?? ''),
                    'quantity' => (int) ($line['quantity'] ?? 0),
                    'amount' => (int) ($line['amount'] ?? 0),
                ];
            }, $lines)),
        ];
    }

    /**
     * `payout.recorded` (design spec §2.3): deliberately minimal -- payout
     * identity/amount/method only. Never `destination_ref`/`provider`
     * (the payout DESTINATION, provider-internal routing detail), never
     * `idempotency_key`/`created_by`/`attempt_count`/`retryable`/
     * `next_reconcile_at`/`reversed_total` (all internal reconciliation
     * plumbing, none of it a "your payout was recorded" fact).
     *
     * @param array<string,mixed> $d
     */
    private function payoutRecorded(string $sellerUuid, array $d): array
    {
        return [
            'event' => 'payout.recorded',
            'seller_uuid' => $sellerUuid,
            'payout_uuid' => (string) ($d['payout_uuid'] ?? ''),
            'currency' => (string) ($d['currency'] ?? ''),
            'amount' => (int) ($d['amount'] ?? 0),
            'method' => (string) ($d['method'] ?? ''),
            'occurred_at' => (string) ($d['occurred_at'] ?? ''),
            'external_ref' => isset($d['external_ref']) ? (string) $d['external_ref'] : null,
        ];
    }

    /** @param array<string,mixed> $d */
    private function stockAdjusted(string $sellerUuid, array $d): array
    {
        return [
            'event' => 'stock.adjusted',
            'seller_uuid' => $sellerUuid,
            'product_uuid' => (string) ($d['product_uuid'] ?? ''),
            'variant_uuid' => (string) ($d['variant_uuid'] ?? ''),
            'sku' => (string) ($d['sku'] ?? ''),
            'delta' => (int) ($d['delta'] ?? 0),
            'quantity_after' => (int) ($d['quantity_after'] ?? 0),
            'reason' => (string) ($d['reason'] ?? ''),
            'reference' => isset($d['reference']) ? (string) $d['reference'] : null,
            'occurred_at' => (string) ($d['occurred_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $d */
    private function productAdopted(string $sellerUuid, array $d): array
    {
        return [
            'event' => 'product.adopted',
            'seller_uuid' => $sellerUuid,
            'product_uuid' => (string) ($d['product_uuid'] ?? ''),
            'occurred_at' => (string) ($d['occurred_at'] ?? ''),
        ];
    }

    /**
     * `product.transferred` (design spec §2.3): a distinct transfer-out
     * (`direction = 'out'`) or transfer-in (`direction = 'in'`) snapshot per
     * the two participating sellers. `counterparty_seller_uuid` is the only
     * cross-seller reference this whole class ever emits -- a bare opaque
     * identifier (never the counterparty's name/slug/contact/product
     * pricing/or any other field), included only because "this product
     * moved to/from seller X" is meaningless without it.
     *
     * @param array<string,mixed> $d
     */
    private function productTransferred(string $sellerUuid, array $d): array
    {
        return [
            'event' => 'product.transferred',
            'seller_uuid' => $sellerUuid,
            'direction' => (string) ($d['direction'] ?? ''),
            'product_uuid' => (string) ($d['product_uuid'] ?? ''),
            'counterparty_seller_uuid' => isset($d['counterparty_seller_uuid'])
                ? (string) $d['counterparty_seller_uuid']
                : null,
            'occurred_at' => (string) ($d['occurred_at'] ?? ''),
        ];
    }
}
