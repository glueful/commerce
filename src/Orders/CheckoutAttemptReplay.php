<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * Typed result of a completed {@see CheckoutAttemptAuthority::claimOrReplay()}
 * (design spec §7, Slice-2 Task 3): the same key + same request fingerprint as
 * an already-completed attempt resolves to the ORIGINAL order's identity and
 * the SAME guest credential that was minted for it, never a fresh one --
 * {@see CheckoutService::placeOrder()} reloads the order row by `$orderUuid`
 * and returns `$guestCredential` verbatim as the response's `guest_token`.
 */
final readonly class CheckoutAttemptReplay
{
    public function __construct(
        public string $orderUuid,
        public string $orderRef,
        public string $guestCredential,
    ) {
    }
}
