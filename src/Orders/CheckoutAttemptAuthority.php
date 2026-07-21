<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Durable checkout idempotency authority (design spec §7, Slice-2 Task 3): a
 * Commerce-local seam Commerce NEVER binds an implementation of -- soft-
 * resolved once by {@see \Glueful\Extensions\Commerce\CommerceServiceProvider}'s
 * `makeCheckoutService()` and injected into {@see CheckoutService} (the
 * `SlugLifecycleAuthority`/`ProductSlugChanged` soft-consumption convention
 * applied to checkout). Both methods run INSIDE `placeOrder()`'s existing
 * placement transaction -- never a new/outer one -- so a throw from either
 * rolls back everything the attempt has done so far, including whatever this
 * authority itself already wrote.
 *
 * A bound implementation (e.g. the pack's `thallo_commerce_checkout_attempts`
 * ledger) is expected to take a transaction advisory lock on `(tenant, key)`
 * inside `claimOrReplay()` before re-reading, so two simultaneous first uses
 * of the same key serialize into one completed attempt/order and one replay
 * rather than two orders.
 */
interface CheckoutAttemptAuthority
{
    /**
     * Called INSIDE the placement transaction, before cart validation.
     * Returns null when this is a brand-new attempt (the caller proceeds to
     * place the order normally and must call {@see self::complete()} once it
     * exists), or a typed replay result when `$ctx->idempotencyKey` already
     * has a completed attempt with the SAME `$ctx->requestFingerprint`.
     *
     * Throws a 409-shaped exception when `$ctx->idempotencyKey` already has a
     * completed (or pending) attempt whose fingerprint does NOT match
     * `$ctx->requestFingerprint` -- the whole placement transaction rolls
     * back and no order is created.
     */
    public function claimOrReplay(
        ApplicationContext $c,
        string $tenant,
        CheckoutAttemptContext $ctx
    ): ?CheckoutAttemptReplay;

    /**
     * Called INSIDE the same transaction, immediately after the order this
     * attempt placed has been inserted: binds `$ctx->idempotencyKey` to
     * `$orderUuid`/`$orderRef`/`$rawGuestToken` so a later replay of the same
     * key can resolve back to this exact order and credential. The pending
     * claim `claimOrReplay()` made can never commit separately from the
     * order it completes -- both share this one transaction's commit.
     */
    public function complete(
        ApplicationContext $c,
        string $tenant,
        CheckoutAttemptContext $ctx,
        string $orderUuid,
        string $orderRef,
        string $rawGuestToken
    ): void;
}
