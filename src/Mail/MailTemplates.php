<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Mail;

use Glueful\Extensions\Commerce\Support\Money;

/**
 * Plain string message builders for Commerce's transactional emails (spec §6). No
 * templating engine dependency; apps that want richer templates rebind
 * {@see CommerceMailer} or intercept per-template config.
 *
 * `order_refunded` is security-sensitive: it may only project amount / partial-vs-full /
 * order facts from the refund payload. The operator-facing `reason` column on
 * `commerce_refunds` (and any `failure_reason`) must NEVER be read here.
 */
final class MailTemplates
{
    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $payload template-specific facts (refund row for
     *        `order_refunded`, note row for `order_note`)
     * @return array{subject:string,body:string}
     */
    public static function render(string $template, array $order, array $payload): array
    {
        return match ($template) {
            'order_placed' => self::orderPlaced($order),
            'order_paid' => self::orderPaid($order),
            'order_fulfilled' => self::orderFulfilled($order),
            'order_refunded' => self::orderRefunded($order, $payload),
            'order_note' => self::orderNote($order, $payload),
            default => throw new \InvalidArgumentException("Unknown mail template '{$template}'."),
        };
    }

    /** @param array<string,mixed> $order @return array{subject:string,body:string} */
    private static function orderPlaced(array $order): array
    {
        $number = self::orderNumber($order);

        return [
            'subject' => "Order {$number} received",
            'body' => "Thanks for your order {$number}. We'll email you again once payment is confirmed.",
        ];
    }

    /** @param array<string,mixed> $order @return array{subject:string,body:string} */
    private static function orderPaid(array $order): array
    {
        $number = self::orderNumber($order);
        $total = self::displayAmount((int) ($order['grand_total'] ?? 0), self::currency($order));

        return [
            'subject' => "Order {$number} payment confirmed",
            'body' => "Payment of {$total} received for order {$number}. Thank you!",
        ];
    }

    /** @param array<string,mixed> $order @return array{subject:string,body:string} */
    private static function orderFulfilled(array $order): array
    {
        $number = self::orderNumber($order);
        $tracking = $order['tracking_ref'] ?? null;
        $body = "Order {$number} has shipped.";
        if (is_string($tracking) && $tracking !== '') {
            $body .= " Tracking reference: {$tracking}.";
        }

        return [
            'subject' => "Order {$number} has shipped",
            'body' => $body,
        ];
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $payload the refund row — ONLY `amount`/`currency` are
     *        read; `reason`/`failure_reason` must never appear in the rendered output.
     * @return array{subject:string,body:string}
     */
    private static function orderRefunded(array $order, array $payload): array
    {
        $number = self::orderNumber($order);
        $currency = self::currency($order);
        $amount = (int) ($payload['amount'] ?? 0);
        $grandTotal = (int) ($order['grand_total'] ?? 0);
        $isFull = $grandTotal > 0 && $amount >= $grandTotal;
        $kind = $isFull ? 'full' : 'partial';
        $amountDisplay = self::displayAmount($amount, $currency);

        return [
            'subject' => "Order {$number} refund processed",
            'body' => "A {$kind} refund of {$amountDisplay} has been issued for order {$number}.",
        ];
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $payload the note row (`body`)
     * @return array{subject:string,body:string}
     */
    private static function orderNote(array $order, array $payload): array
    {
        $number = self::orderNumber($order);
        $body = is_string($payload['body'] ?? null) ? $payload['body'] : '';

        return [
            'subject' => "New note on order {$number}",
            'body' => $body,
        ];
    }

    /** @param array<string,mixed> $order */
    private static function orderNumber(array $order): string
    {
        $number = $order['order_number'] ?? $order['uuid'] ?? '';

        return (string) $number;
    }

    /** @param array<string,mixed> $order */
    private static function currency(array $order): string
    {
        $currency = $order['currency'] ?? 'USD';

        return is_string($currency) && $currency !== '' ? $currency : 'USD';
    }

    private static function displayAmount(int $amount, string $currency): string
    {
        return Money::format($amount, $currency) . ' ' . $currency;
    }
}
