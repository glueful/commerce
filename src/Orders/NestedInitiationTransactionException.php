<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * A CALLER BUG, not a payer-facing state (payment-links Task 7 review round 1,
 * Important 1): {@see PaymentLinkService::initiateByToken()} was invoked while a
 * database transaction was already open.
 *
 * ## Why this has to be an assertion rather than a best effort
 *
 * `Connection::transaction()` DELEGATES to the active TransactionManager when
 * one already exists, so a nested call becomes a SAVEPOINT rather than a real
 * transaction. Under an ambient caller transaction, initiation's Phase A would
 * therefore not commit at all: its "commit" would be a savepoint release, the
 * order and link row locks it took would still be held by the outer
 * transaction, and they would stay held across the provider network call --
 * which is the single failure mode this whole two-phase design exists to
 * prevent. Worse, it would happen SILENTLY: every test would still pass,
 * because the phases would still execute in order.
 *
 * There is no safe automatic remedy. Committing the caller's transaction on
 * their behalf would publish writes they had not finished making; suspending it
 * is not something the framework offers; and proceeding anyway is the bug. So
 * initiation refuses, loudly, at the boundary.
 *
 * ## Why it is NOT a {@see PaymentLinkException}
 *
 * That class is the CLOSED domain of typed refusals a controller maps to a
 * status code and shows a payer. This is none of those things: no payer can
 * cause it, no payer can act on it, and it must never be rendered as "your
 * payment link has a problem". A `\LogicException` is the honest classification
 * -- a mistake in the calling code, discoverable in development, and the
 * controller's ordinary unknown-throwable path (a bodiless 500) is the right
 * response if one ever escapes in production.
 *
 * Argument-less like every other refusal on this path, so nothing about the
 * link, the order, or the token can ride out in a message that reaches a log.
 */
final class NestedInitiationTransactionException extends \LogicException
{
    public static function forInitiation(): self
    {
        return new self(
            'Payment-link initiation must not run inside an existing database transaction: '
            . 'its first phase has to COMMIT before the payment provider is called, and a nested '
            . 'transaction would hold the order and link locks across that network call. '
            . 'Call initiateByToken() outside your transaction.'
        );
    }
}
