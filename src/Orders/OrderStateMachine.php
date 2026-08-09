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
     * BOTH draft pairs are listed here because both ARE legal lifecycle pairs,
     * and BOTH are DEDICATED-PATH-ONLY: {@see OrderRepository::transition()}
     * refuses to perform either. A draft may leave `draft` only through the
     * single audited compare-and-set that owns that exit --
     * {@see OrderRepository::finalizeDraftTransition()} for
     * `draft -> pending_payment`, and {@see DraftCleanupService::cancelDraft()}
     * (shared by the TTL sweep and the explicit admin cancel) for
     * `draft -> canceled`. Both bypass this table entirely, so the entries here
     * describe the legal SHAPE of the lifecycle without offering a generic door
     * into it.
     *
     * The symmetry is deliberate: a `draft -> canceled` run through
     * `transition()` would skip the
     * {@see \Glueful\Extensions\Commerce\Orders\Events\DraftOrderEvents} audit
     * row, leaving a canceled draft with no record of whether an operator
     * killed it or the TTL swept it.
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
