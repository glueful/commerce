<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

final class OrderStateMachine
{
    /**
     * Admin-order-creation cycle 2, Task 8 (design spec §2.7): `draft` is the
     * admin-created ("walk-in") order's pre-finalization state. It has exactly
     * TWO outgoing pairs and ZERO incoming ones -- an order is either born a
     * draft or never becomes one.
     *
     * `draft -> pending_payment` is listed here because it IS a legal
     * lifecycle pair, but {@see OrderRepository::transition()} deliberately
     * REFUSES to perform it: only the dedicated compare-and-set
     * {@see OrderRepository::finalizeDraftTransition()} may finalize a draft,
     * so the finalize path can never be reached by a generic status write.
     * `draft -> canceled` has no such restriction and runs through the
     * ordinary `transition()` (or the draft-specific
     * {@see DraftCleanupService} path, which additionally records the draft
     * audit row).
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'draft' => ['pending_payment', 'canceled'],
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
