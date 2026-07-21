<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * Durable checkout idempotency input (design spec §7, Slice-2 Task 3): the
 * caller-supplied idempotency key plus a hash of the canonicalized checkout
 * payload ("request fingerprint"). Passed through {@see CheckoutService::placeOrder()}
 * to a bound {@see CheckoutAttemptAuthority} untouched -- Commerce never
 * computes or interprets either value itself.
 */
final class CheckoutAttemptContext
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $requestFingerprint,
    ) {
    }
}
