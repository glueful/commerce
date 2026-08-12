<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * What {@see PaymentSessionExposureGuard} decided about ONE order, in the CLOSED
 * vocabulary design spec §2.2 pins (payment-links Task 8).
 *
 * Three reasons, and each answers both cancellation authorities at once --
 * because "may the sweep cancel this?" and "may an operator cancel this?" are
 * genuinely different questions with the same input:
 *
 *  - {@see self::REASON_NONE}: nothing relevant is outstanding. The order is in
 *    the ordinary sweep and an operator may cancel it with no ceremony. This is
 *    also the answer for an UNINITIATED expired or revoked link -- a link nobody
 *    ever used holds nothing.
 *  - {@see self::REASON_ACTIVE_LINK}: an `active`, UNEXPIRED link that has never
 *    issued a provider session. AUTOMATIC cancellation is refused (a customer
 *    may be about to pay, and releasing the stock under them would be the
 *    engine's fault), but an OPERATOR may cancel without acknowledging anything:
 *    no money is in flight, so the worst case is a payer meeting an honest "this
 *    link is no longer payable".
 *  - {@see self::REASON_SESSION_EXPOSED}: some link on this order -- WHATEVER
 *    its current status -- has `provider_session_issued_at` set, so a checkout
 *    URL was handed to somebody and money may already be moving. Automatic
 *    cancellation is refused outright and an operator must carry
 *    `accept_late_payment_risk=true`, which is then recorded.
 *
 * The exposure stamp is never cleared, so the third reason would be permanent
 * if the guard looked at links alone. It does not: once the order stops being
 * `pending_payment` the risk is RESOLVED (the payment either arrived or the
 * order left the payable world), and the guard answers {@see self::REASON_NONE}
 * again -- design spec §2.2's "until payment or an explicit operator
 * cancellation".
 */
final readonly class PaymentSessionExposureDecision
{
    public const REASON_NONE = 'none';
    public const REASON_ACTIVE_LINK = 'active_link';
    public const REASON_SESSION_EXPOSED = 'session_exposed';

    /** The CLOSED reason domain, most-permissive first. @var list<string> */
    public const REASONS = [
        self::REASON_NONE,
        self::REASON_ACTIVE_LINK,
        self::REASON_SESSION_EXPOSED,
    ];

    /**
     * `$linkUuid` names the link the decision is ABOUT (the exposed one, or the
     * active one) purely so an audit row can say which. It is never a token and
     * never a hash, and it is deliberately absent from {@see self::toArray()}:
     * the wire needs the POLICY, not the identity.
     */
    public function __construct(
        public string $reason,
        public ?string $linkUuid = null,
    ) {
    }

    public static function none(): self
    {
        return new self(self::REASON_NONE);
    }

    public static function activeLink(string $linkUuid): self
    {
        return new self(self::REASON_ACTIVE_LINK, $linkUuid);
    }

    public static function sessionExposed(string $linkUuid): self
    {
        return new self(self::REASON_SESSION_EXPOSED, $linkUuid);
    }

    /** May an UNATTENDED sweep cancel this order? Only when nothing is outstanding. */
    public function permitsAutomaticCancellation(): bool
    {
        return $this->reason === self::REASON_NONE;
    }

    /** Must an operator carry `accept_late_payment_risk=true`? Only for a real exposure. */
    public function requiresRiskAcknowledgement(): bool
    {
        return $this->reason === self::REASON_SESSION_EXPOSED;
    }

    public function permitsOperatorCancellation(bool $riskAccepted): bool
    {
        return $riskAccepted || !$this->requiresRiskAcknowledgement();
    }

    /**
     * The operator-surface projection: the policy, and nothing that could
     * identify or reconstruct a link's credential.
     *
     * @return array{
     *     reason: string,
     *     blocks_automatic_cancellation: bool,
     *     requires_risk_acknowledgement: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'blocks_automatic_cancellation' => !$this->permitsAutomaticCancellation(),
            'requires_risk_acknowledgement' => $this->requiresRiskAcknowledgement(),
        ];
    }
}
