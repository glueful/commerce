<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * The ONE typed refusal {@see PaymentSessionExposureGuard} raises
 * (payment-links Task 8, design spec §2.2).
 *
 * A `\DomainException` carrying a CLOSED machine-readable `$errorCode`, the same
 * convention {@see PaymentLinkException} follows -- so a cancellation endpoint
 * that already maps `\DomainException` to 409 keeps working unchanged, while one
 * that wants to render the acknowledgement prompt catches this subclass first
 * and branches on the code.
 *
 * Deliberately argument-less factories: the thing being refused is a payment
 * link, and its token and hash may never be quoted into a message that reaches a
 * log line or an operator's browser. The order and the actor are the caller's
 * own facts and are already on its side of the call.
 */
final class PaymentSessionExposureException extends \DomainException
{
    /**
     * An operator tried to cancel an order whose payment link has already
     * exposed a provider checkout session, without carrying
     * {@see PaymentSessionExposureGuard::ACKNOWLEDGEMENT_FIELD}. The remedy is
     * never a retry: it is a decision, which is exactly why it has to be stated.
     */
    public const RISK_UNACKNOWLEDGED = 'payment_session_risk_unacknowledged';

    /** The CLOSED discriminator domain. @var list<string> */
    public const ERROR_CODES = [
        self::RISK_UNACKNOWLEDGED,
    ];

    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function riskUnacknowledged(): self
    {
        return new self(
            'A checkout session was already opened for this order, so a payment may still arrive. '
            . 'Cancel it only by accepting the late-payment risk.',
            self::RISK_UNACKNOWLEDGED
        );
    }
}
