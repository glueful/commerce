<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

final class OrderStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'pending_payment' => ['paid', 'canceled'],
        'paid' => ['fulfilled', 'canceled', 'refunded'],
        'fulfilled' => ['refunded'],
    ];

    public static function assertTransition(string $from, string $to): void
    {
        if (!in_array($to, self::ALLOWED[$from] ?? [], true)) {
            throw new \DomainException("Invalid order transition {$from} -> {$to}.");
        }
    }
}
