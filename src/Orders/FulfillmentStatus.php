<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * Marketplace MV2 fulfillment vocabulary (design spec §2.8): a parent order's
 * `fulfillment_status` (`unfulfilled` | `partial` | `fulfilled`) is a plain
 * column derived from the roll-up of its `commerce_seller_orders` children's
 * own `fulfillment_status` (`unfulfilled` | `fulfilled`). `partial` is a
 * FULFILLMENT value only -- it is never a `commerce_orders.status`
 * lifecycle value, and {@see OrderStateMachine} is intentionally left
 * untouched by MV2.
 */
final class FulfillmentStatus
{
    public const PARENT_UNFULFILLED = 'unfulfilled';
    public const PARENT_PARTIAL = 'partial';
    public const PARENT_FULFILLED = 'fulfilled';

    public const CHILD_UNFULFILLED = 'unfulfilled';
    public const CHILD_FULFILLED = 'fulfilled';

    /** @var list<string> */
    private const PARENT_VALUES = [self::PARENT_UNFULFILLED, self::PARENT_PARTIAL, self::PARENT_FULFILLED];

    /** @var list<string> */
    private const CHILD_VALUES = [self::CHILD_UNFULFILLED, self::CHILD_FULFILLED];

    public static function assertParent(string $value): void
    {
        if (!in_array($value, self::PARENT_VALUES, true)) {
            throw new \DomainException("Invalid parent fulfillment status '{$value}'.");
        }
    }

    public static function assertChild(string $value): void
    {
        if (!in_array($value, self::CHILD_VALUES, true)) {
            throw new \DomainException("Invalid child fulfillment status '{$value}'.");
        }
    }

    /**
     * Rolls up the parent value over the NON-`canceled` children (design
     * spec §2.8): `fulfilled` iff every non-canceled child is `fulfilled`,
     * `partial` iff some (but not all) are, else `unfulfilled`. Each row
     * needs only its own `status` (seller-order operational status,
     * `open`|`canceled`) and `fulfillment_status` (`unfulfilled`|`fulfilled`)
     * keys -- extra keys (the money/attribution columns callers naturally
     * re-read alongside these) are ignored, so a caller can pass the full
     * `commerce_seller_orders` row straight through without projecting it
     * first.
     *
     * Edge case -- every child canceled: pinned to `fulfilled` ("nothing
     * left to fulfill"), a deterministic result for this pure function.
     * Unreachable via any MV2-supported path in practice: the only way a
     * child ever reaches `canceled` is the whole-order cancel fan-out
     * (§2.9), which ALSO terminalizes the PARENT order's own `status` to
     * `canceled` in the very same transaction -- so by the time every child
     * is canceled, {@see OrderStateMachine} already rejects any further
     * `canceled -> fulfilled` transition regardless of what this method
     * returns; only the (inert) `fulfillment_status` column value is
     * affected.
     *
     * @param list<array<string,mixed>> $children
     */
    public static function rollup(array $children): string
    {
        $active = array_values(array_filter(
            $children,
            static fn (array $child): bool => (string) ($child['status'] ?? '') !== 'canceled'
        ));

        if ($active === []) {
            return self::PARENT_FULFILLED;
        }

        $fulfilledCount = 0;
        foreach ($active as $child) {
            if ((string) ($child['fulfillment_status'] ?? '') === self::CHILD_FULFILLED) {
                $fulfilledCount++;
            }
        }

        if ($fulfilledCount === count($active)) {
            return self::PARENT_FULFILLED;
        }

        return $fulfilledCount > 0 ? self::PARENT_PARTIAL : self::PARENT_UNFULFILLED;
    }
}
