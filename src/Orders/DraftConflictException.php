<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * A TYPED 409 from the draft surface (admin-order-creation cycle 2, Task 9):
 * the request was well-formed and the operator is authorized, but the draft's
 * own state disagrees with what the request assumed.
 *
 * `$conflict` is a CLOSED machine-readable discriminator the SPA branches on --
 * the human message is for logs and fallback rendering only:
 *  - {@see self::STALE_REVISION}     -- the `draft_revision` compare-and-set
 *    matched zero rows: either the caller sent an `expected_revision` that is
 *    no longer current, or a concurrent mutation won the race. Either way the
 *    remedy is identical (reload the draft and retry), which is why they share
 *    one discriminator.
 *  - {@see self::USER_EMAIL_MISMATCH} -- a `user_uuid` resolved to an active
 *    user whose email disagrees with a `email` supplied in the SAME request.
 *    Design spec §2.3: never silently linked. Nothing is written.
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

    public function __construct(public readonly string $conflict, string $message)
    {
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
}
