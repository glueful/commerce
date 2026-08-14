<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * The LOSER of {@see OrderRepository::transition()}'s compare-and-set: the
 * `WHERE ... AND status = <from>` guard matched ZERO rows, so somebody else
 * moved this order between the read and the write.
 *
 * This is deliberately NARROWER than "the transition failed". An outright
 * illegal pair ({@see OrderStateMachine::assertTransition()}) and a draft
 * routed through the generic door both still raise a plain `\DomainException`
 * from `transition()`; only a genuine lost race arrives as this class. That
 * distinction is what lets a caller answer a race idempotently WITHOUT also
 * swallowing a real refusal -- see
 * {@see OrderPaymentService::markPaid()}, whose whole self-heal rule is "the
 * CAS was lost AND the observed status is already the one I wanted".
 *
 * `$observed` is the status read back immediately after the failed CAS -- the
 * winner's outcome, as far as this session can see it -- or `null` if the row
 * has since vanished entirely. It is a diagnostic, not a lock: a caller that
 * acts on it re-reads for itself.
 *
 * Extends `\DomainException` so the standing `catch (\DomainException) -> 409`
 * idiom every admin controller already uses keeps classifying a lost race
 * exactly as it did before this type existed.
 */
final class ConcurrentOrderTransitionException extends \DomainException
{
    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $observed,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function lost(string $from, string $to, ?string $observed): self
    {
        return new self(
            $from,
            $to,
            $observed,
            // Byte-identical to the message this arm has always produced: it is
            // already on operator screens and in logs, and the discriminator a
            // caller should branch on is now the TYPE, never the string.
            'Order status changed concurrently; retry the operation.'
        );
    }
}
