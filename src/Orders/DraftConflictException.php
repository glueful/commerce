<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * A TYPED 409 from the draft surface (admin-order-creation cycle 2, Tasks 9 and
 * 10): the request was well-formed and the operator is authorized, but the
 * draft's own state disagrees with what the request assumed.
 *
 * `$conflict` is a CLOSED machine-readable discriminator the SPA branches on --
 * the human message is for logs and fallback rendering only. The MUTATION
 * surface (Task 9) raises the first two; the FINALIZATION authority (Task 10,
 * design spec §2.5) raises the rest:
 *  - {@see self::STALE_REVISION}     -- the `draft_revision` compare-and-set
 *    matched zero rows: either the caller sent an `expected_revision` that is
 *    no longer current, or a concurrent mutation won the race. Either way the
 *    remedy is identical (reload the draft and retry), which is why they share
 *    one discriminator.
 *  - {@see self::USER_EMAIL_MISMATCH} -- a `user_uuid` resolved to an active
 *    user whose email disagrees with a `email` supplied in the SAME request.
 *    Design spec §2.3: never silently linked. Nothing is written.
 *  - {@see self::IDEMPOTENCY_KEY}    -- this `X-Idempotency-Key` is already
 *    bound to a DIFFERENT request (a different draft, or a different
 *    `expected_revision`). The remedy is a fresh key, never a retry.
 *  - {@see self::NOT_DRAFT}          -- the order at this uuid is no longer a
 *    draft (a concurrent finalize or cancel won). Deliberately distinct from
 *    the mutation surface's non-revealing 404: finalize must stay reachable for
 *    an idempotent REPLAY after the order is already finalized, so it cannot
 *    hide a finalized row behind a 404 the way `show()`/`update()` do.
 *  - {@see self::CURRENCY}           -- the draft's snapshotted currency no
 *    longer matches the store currency. Money cannot be reinterpreted; the
 *    remedy is cancel + recreate.
 *  - {@see self::LINE_CONFLICTS}     -- one or more lines cannot become
 *    authoritative: price/add-on drift, an unavailable purchasable, a digital
 *    or marketplace line, or insufficient stock. `$details['lines']` carries
 *    one entry per offending line.
 *  - {@see self::SHIPPING_METHOD}    -- the draft's chosen shipping method is
 *    no longer in a live quote for its lines and address.
 *  - {@see self::DISCOUNT}           -- the draft's discount code no longer
 *    resolves, no longer validates, or is exhausted.
 *
 * `$details` is the machine-readable payload the controller merges under
 * `error.details` alongside `conflict`. It is EMPTY for every Task 9 conflict
 * (the discriminator says everything there is to say) and non-empty only where
 * the operator needs per-item specifics to act -- currently the per-line list.
 *
 * Extends `\DomainException` to match the codebase's existing conflict idiom
 * ({@see OrderRepository::transition()}, {@see OrderStateMachine}), so a caller
 * that already catches `\DomainException` -> 409 keeps working; the controller
 * catches this subclass FIRST to attach the discriminator.
 */
final class DraftConflictException extends \DomainException
{
    public const STALE_REVISION = 'stale_revision';
    public const USER_EMAIL_MISMATCH = 'user_email_mismatch';
    public const IDEMPOTENCY_KEY = 'idempotency_key';
    public const NOT_DRAFT = 'not_draft';
    public const CURRENCY = 'currency';
    public const LINE_CONFLICTS = 'line_conflicts';
    public const SHIPPING_METHOD = 'shipping_method';
    public const DISCOUNT = 'discount';

    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly string $conflict,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function staleRevision(): self
    {
        return new self(
            self::STALE_REVISION,
            'This draft changed since you loaded it; reload the draft and retry.'
        );
    }

    public static function userEmailMismatch(): self
    {
        return new self(
            self::USER_EMAIL_MISMATCH,
            'The supplied email does not match the selected account; the draft was not linked.'
        );
    }

    public static function idempotencyKeyReuse(): self
    {
        return new self(
            self::IDEMPOTENCY_KEY,
            'This idempotency key was already used for a different finalize request; use a new key.'
        );
    }

    public static function notDraft(string $status): self
    {
        return new self(
            self::NOT_DRAFT,
            "This order is no longer a draft (status '{$status}') and cannot be finalized again."
        );
    }

    /**
     * The same discriminator as {@see self::notDraft()}, for the LATE discovery
     * of the same fact: the finalize compare-and-set matched zero rows, so
     * something stopped this order being a draft between the load and the write.
     * A separate factory only because there is no status to name -- the winner's
     * new status is not reliably ours to read at that point -- while the client's
     * branch and remedy (reload the draft, retry) are identical, which is exactly
     * what one discriminator across two factories is for.
     */
    public static function finalizeRaceLost(): self
    {
        return new self(
            self::NOT_DRAFT,
            'This draft stopped being finalizable while the request was running; reload it and retry.'
        );
    }

    public static function currencyChanged(string $draftCurrency, string $storeCurrency): self
    {
        return new self(
            self::CURRENCY,
            "This draft is priced in {$draftCurrency} but the store currency is now {$storeCurrency};"
            . ' cancel it and start a new draft.'
        );
    }

    /** @param list<array<string,mixed>> $lines */
    public static function lineConflicts(array $lines): self
    {
        return new self(
            self::LINE_CONFLICTS,
            'Some lines can no longer be ordered as drafted; review them and retry.',
            ['lines' => $lines]
        );
    }

    public static function shippingUnavailable(string $methodId): self
    {
        return new self(
            self::SHIPPING_METHOD,
            "Shipping method '{$methodId}' is no longer available for this order; choose another."
        );
    }

    public static function discountUnusable(string $detail): self
    {
        return new self(self::DISCOUNT, $detail);
    }
}
