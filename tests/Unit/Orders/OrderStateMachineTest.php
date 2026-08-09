<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Orders;

use Glueful\Extensions\Commerce\Orders\OrderStateMachine;
use PHPUnit\Framework\TestCase;

/**
 * Admin-order-creation cycle 2, Task 8: `draft` joins the lifecycle table with
 * EXACTLY two outgoing pairs (`pending_payment`, `canceled`) and ZERO incoming
 * ones -- nothing may ever transition INTO `draft` (a draft is only ever born a
 * draft). The exhaustive table below is the ratchet: every (from, to) pair over
 * the full status vocabulary is asserted, so an accidental widening anywhere in
 * `OrderStateMachine::ALLOWED` fails here rather than silently reaching
 * production.
 */
final class OrderStateMachineTest extends TestCase
{
    /** Every order status value the engine can persist. */
    private const STATUSES = ['draft', 'pending_payment', 'paid', 'fulfilled', 'canceled', 'refunded'];

    /**
     * The COMPLETE expected transition table. `draft` is the only Task 8
     * addition; every pre-existing row is byte-for-byte unchanged.
     *
     * @var array<string, list<string>>
     */
    private const EXPECTED = [
        'draft' => ['pending_payment', 'canceled'],
        'pending_payment' => ['paid', 'canceled'],
        'paid' => ['fulfilled', 'canceled', 'refunded'],
        'fulfilled' => ['refunded'],
        'canceled' => [],
        'refunded' => [],
    ];

    public function testStateMachine(): void
    {
        OrderStateMachine::assertTransition('pending_payment', 'paid');
        OrderStateMachine::assertTransition('paid', 'refunded');

        $this->expectException(\DomainException::class);

        OrderStateMachine::assertTransition('canceled', 'paid');
    }

    public function testTheCompleteTransitionTableIsExactlyTheExpectedOne(): void
    {
        foreach (self::STATUSES as $from) {
            foreach (self::STATUSES as $to) {
                $allowed = in_array($to, self::EXPECTED[$from], true);

                try {
                    OrderStateMachine::assertTransition($from, $to);
                    self::assertTrue($allowed, "{$from} -> {$to} must NOT be allowed but was accepted.");
                } catch (\DomainException $e) {
                    self::assertFalse($allowed, "{$from} -> {$to} must be allowed but was rejected.");
                    self::assertSame("Invalid order transition {$from} -> {$to}.", $e->getMessage());
                }
            }
        }
    }

    public function testDraftMayOnlyGoToPendingPaymentOrCanceled(): void
    {
        OrderStateMachine::assertTransition('draft', 'pending_payment');
        OrderStateMachine::assertTransition('draft', 'canceled');

        foreach (['paid', 'fulfilled', 'refunded', 'draft'] as $to) {
            try {
                OrderStateMachine::assertTransition('draft', $to);
                self::fail("draft -> {$to} must be rejected.");
            } catch (\DomainException $e) {
                self::assertSame("Invalid order transition draft -> {$to}.", $e->getMessage());
            }
        }
    }

    public function testNothingEverTransitionsIntoDraft(): void
    {
        foreach (self::STATUSES as $from) {
            try {
                OrderStateMachine::assertTransition($from, 'draft');
                self::fail("{$from} -> draft must be rejected.");
            } catch (\DomainException $e) {
                self::assertSame("Invalid order transition {$from} -> draft.", $e->getMessage());
            }
        }
    }

    public function testAnUnknownSourceStatusStillRejects(): void
    {
        $this->expectException(\DomainException::class);

        OrderStateMachine::assertTransition('not_a_status', 'canceled');
    }
}
