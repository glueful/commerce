<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;

/**
 * THE AUTHORITY FOR EVERY NON-DRAFT ORDER CANCELLATION (payment-links Task 8,
 * design spec §2.2: "one shared `PaymentSessionExposureGuard` is the authority
 * for every non-draft cancellation path").
 *
 * ## The problem it exists for
 *
 * Cancelling an unpaid order releases its reserved stock and closes it. That is
 * correct and routine -- until a payment link has handed somebody a live
 * checkout URL. From that instant the order has a LEASE on the customer's
 * intent: the payer may be typing their card number right now, and the engine
 * cannot see it. Cancelling underneath them produces the one outcome commerce
 * software must never produce on its own initiative: money taken for an order
 * that no longer exists, against stock that has been sold to somebody else.
 *
 * So this class answers one question -- "may this order be cancelled, and by
 * whom?" -- for both cancellation authorities the engine has
 * ({@see ExpiryService::expireStale()} and
 * {@see \Glueful\Extensions\Commerce\Http\Admin\AdminOrderController::cancel()}),
 * and `Unit\Orders\CancellationAuthorityInventoryTest` fails the build if a
 * third one appears without being wired here.
 *
 * ## The policy, exactly as §2.2 states it
 *
 *  - an UNINITIATED expired or revoked link => the order returns to the ordinary
 *    sweep. A link nobody ever used holds nothing.
 *  - an ACTIVE, UNEXPIRED, uninitiated link => automatic cancellation is
 *    blocked; an operator may still cancel plainly.
 *  - ANY link with `provider_session_issued_at` set, WHATEVER its status =>
 *    automatic cancellation is blocked "until payment or an explicit operator
 *    cancellation carrying `accept_late_payment_risk=true`"; that acknowledgement
 *    records {@see self::RISK_ACCEPTED_EVENT} with actor and time, in the SAME
 *    transaction as the cancellation and BEFORE the stock release.
 *
 * ## Why it takes a LOCKED order row
 *
 * The prefilters {@see PaymentLinkRepository::guardRelevantLinks()} serves are
 * candidate selection, never authority: between a sweep's candidate query and
 * its per-order transaction an operator can mint a link and a payer can open a
 * checkout. So every cancellation authority must lock and RELOAD the order and
 * then call in here, inside the transaction that will do the cancelling. Passing
 * an unlocked row is not a syntax error and cannot be made one -- what makes it
 * safe is that the guard's own reads then run under that lock, so the decision
 * and the cancellation are one atomic story.
 *
 * A READ-ONLY caller (the admin status endpoint) may pass an unlocked row on
 * purpose: it is publishing an OBSERVATION for an operator to look at, not
 * authorizing anything, exactly as {@see PaymentLinkService::resolveByToken()}
 * publishes a read-time observation.
 *
 * ## What it never does
 *
 * It never cancels, never releases stock, never touches a link row, and never
 * sees a token. It reads links and, when a risk is accepted, writes exactly one
 * audit row.
 */
final class PaymentSessionExposureGuard
{
    /**
     * The request field an operator sets to accept the late-payment risk. Named
     * once here so the engine controller, Thallo's admin SPA, and the audit row
     * cannot drift into three spellings of the same decision.
     */
    public const ACKNOWLEDGEMENT_FIELD = 'accept_late_payment_risk';

    /**
     * The `commerce_order_events.type` written when that acknowledgement is
     * used. A ROW, not a dispatched event -- like
     * {@see Events\PaymentLinkEvents} -- because it is operator bookkeeping
     * about a decision, not a commerce fact that should wake mail, webhooks, or
     * the marketplace fan-out.
     */
    public const RISK_ACCEPTED_EVENT = 'payment_session_risk_accepted';

    /** The one order status at which a payment link can still take money. */
    private const AWAITING_PAYMENT = 'pending_payment';

    public function __construct(
        private PaymentLinkRepository $links,
        private OrderRepository $orders,
    ) {
    }

    /**
     * Decide, for ONE order, what its outstanding payment links permit.
     *
     * `$order` is a row as the repository returns it -- LOCKED for every
     * cancellation caller (see the class docblock). `$now` is injected by every
     * caller that has a clock; it defaults to the real one so a caller with no
     * clock of its own cannot accidentally decide against a frozen instant.
     *
     * @param array<string,mixed> $order
     */
    public function decide(
        ApplicationContext $context,
        string $tenant,
        array $order,
        ?\DateTimeImmutable $now = null
    ): PaymentSessionExposureDecision {
        // "until PAYMENT": once the order has left `pending_payment` the
        // late-payment risk is resolved -- the money either arrived (paid) or
        // the order is already out of the payable world. The exposure stamp is
        // never cleared, so without this the guard would block a paid order's
        // ordinary operator cancellation forever.
        if ((string) ($order['status'] ?? '') !== self::AWAITING_PAYMENT) {
            return PaymentSessionExposureDecision::none();
        }

        $moment = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));
        $relevant = $this->links->guardRelevantLinks($context, $tenant, (string) $order['uuid'], $moment);

        $active = null;
        foreach ($relevant as $link) {
            // EXPOSURE WINS. A revoked-but-initiated link and a pristine active
            // one can both sit on one order (an operator revoking a link a payer
            // had already opened, then regenerating). The exposed one is the
            // dangerous fact, so it decides -- and it is answered on the FIRST
            // match, in the repository's `id ASC` order, so the answer is the
            // EARLIEST exposure rather than an arbitrary one.
            if ($link['provider_session_issued_at'] !== null) {
                return PaymentSessionExposureDecision::sessionExposed((string) $link['uuid']);
            }

            $active ??= (string) $link['uuid'];
        }

        // Whatever is left came from the query's first branch: `active` AND
        // unexpired. An uninitiated expired or revoked link is not in this set
        // at all, which is precisely how such an order "returns to the ordinary
        // sweep" (design spec §2.2).
        return $active === null
            ? PaymentSessionExposureDecision::none()
            : PaymentSessionExposureDecision::activeLink($active);
    }

    /**
     * The AUTOMATIC (unattended) authority: may a sweep cancel this order?
     *
     * MUST be called inside the sweep's own per-order transaction, on a row that
     * was locked and reloaded there -- the candidate query's prefilters are not
     * an answer to this question, only a way to ask it less often.
     *
     * @param array<string,mixed> $order
     */
    public function permitsAutomaticCancellation(
        ApplicationContext $context,
        string $tenant,
        array $order,
        ?\DateTimeImmutable $now = null
    ): bool {
        return $this->decide($context, $tenant, $order, $now)->permitsAutomaticCancellation();
    }

    /**
     * The OPERATOR authority: refuse an unacknowledged cancellation of an
     * exposed order, and RECORD the acknowledgement when one is given.
     *
     * Call it inside the cancellation transaction and BEFORE any stock moves
     * (design spec §2.2). Two properties follow from that placement and neither
     * is optional:
     *  - a refusal THROWS, so the transaction rolls back and nothing was
     *    released, transitioned, or audited;
     *  - an acceptance's audit row commits with the cancellation itself, so
     *    there is no window in which stock was released without a recorded
     *    decision behind it.
     *
     * The acknowledgement is recorded ONLY when it was actually needed. An
     * operator who sends `accept_late_payment_risk=true` on an order with no
     * exposure has accepted nothing, and writing a risk row there would make the
     * audit trail a record of what clients send rather than of what happened.
     *
     * @param array<string,mixed> $order the LOCKED, reloaded order row
     * @param bool $riskAccepted the caller's parsed `accept_late_payment_risk`
     * @throws PaymentSessionExposureException `payment_session_risk_unacknowledged`
     */
    public function authorizeOperatorCancellation(
        ApplicationContext $context,
        string $tenant,
        array $order,
        bool $riskAccepted,
        ?string $actorUuid = null,
        ?\DateTimeImmutable $now = null
    ): PaymentSessionExposureDecision {
        $moment = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));
        $decision = $this->decide($context, $tenant, $order, $moment);

        if (!$decision->requiresRiskAcknowledgement()) {
            return $decision;
        }

        if (!$riskAccepted) {
            throw PaymentSessionExposureException::riskUnacknowledged();
        }

        $this->orders->recordEvent(
            $context,
            (string) $order['uuid'],
            self::RISK_ACCEPTED_EVENT,
            [
                // `link_uuid` names WHICH exposure was accepted; the timestamp is
                // the caller's own injected clock rather than the row's
                // `created_at`, so a test (and a replayed cron) audits an exact
                // instant. Never a token, never a hash.
                'link_uuid' => $decision->linkUuid,
                'accepted_at' => $moment->format('Y-m-d H:i:s'),
            ],
            $actorUuid
        );

        return $decision;
    }
}
