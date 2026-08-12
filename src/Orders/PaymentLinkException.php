<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * The one TYPED refusal from {@see PaymentLinkService} (payment-links Task 6,
 * design spec §2.2).
 *
 * A `\DomainException` carrying a CLOSED machine-readable `$errorCode`, exactly
 * the convention {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookException}
 * already established: the human message is for logs and fallback rendering, the
 * code is what callers branch on. A caller that already maps `\DomainException`
 * to 409 keeps working; the payment-link controller (Task 8) catches this
 * subclass FIRST to attach the discriminator and to pick the right status.
 *
 * The intended controller mapping -- stated here so Task 8 and Thallo share ONE
 * table rather than inventing two:
 *
 *  - {@see self::ORDER_NOT_FOUND}            -> 404. Unknown OR cross-tenant, one
 *    non-revealing answer, exactly like every other admin order surface.
 *  - {@see self::ORDER_NOT_ADMIN_ORIGIN}     -> 409. The order exists and is
 *    yours, but it was placed by a customer through the storefront; payment
 *    links are an operator instrument for orders the operator raised.
 *  - {@see self::ORDER_NOT_PENDING_PAYMENT}  -> 409. Draft, paid, canceled, or
 *    refunded: there is nothing to collect.
 *  - {@see self::LINK_CHANGED}               -> 409 `payment_link_changed`, the
 *    literal discriminator design spec §2.2 pins. The order IS yours, but the
 *    token submitted is not its CURRENT active link -- it is stale, malformed,
 *    another tenant's, or the order has no active link at all. The remedy is
 *    always "reload and use the current link", never a retry.
 *  - {@see self::PUBLIC_URL_UNAVAILABLE}     -> 503 (or the host's chosen
 *    "feature not configured" status). No {@see \Glueful\Extensions\Commerce\Contracts\PaymentLinkPublicUrlProvider}
 *    is bound, or the bound one produced nothing usable. NOTHING was minted.
 *
 * ## The INITIATION codes (payment-links Task 7, design spec §2.2)
 *
 * `initiateByToken()` is the money path and it is UNAUTHENTICATED, so its
 * refusals split along a different axis: what the PAYER may learn versus what
 * the OPERATOR needs to diagnose. Design spec §2.2 requires that every way the
 * provider leg can fail becomes a TYPED state -- "never empty/open redirects or
 * exception leaks". These are those states:
 *
 *  - {@see self::PAYMENT_LINK_NOT_PAYABLE} -> 404/410 (host's choice). ONE
 *    generic answer for every reason this token cannot start a payment:
 *    malformed, unknown, another tenant's, revoked, superseded, expired,
 *    consumed, or an order that is no longer `pending_payment`. Deliberately
 *    indistinguishable, exactly like `resolveByToken()`'s single null -- an
 *    anonymous caller must not be able to use initiation as an oracle. It is
 *    ALSO the answer when Phase B's recheck loses the race (a revoke or cancel
 *    committed while the provider call was in flight): the attempt stays
 *    server-side and no URL is exposed.
 *  - {@see self::INITIATION_RATE_LIMITED} -> 429. Its OWN code, because it is
 *    the one refusal here that is genuinely transient and where "try again
 *    later" is honest advice. The link's fixed UTC one-hour window is full.
 *  - {@see self::RETURN_URL_UNAVAILABLE} -> 503. No
 *    {@see \Glueful\Extensions\Commerce\Contracts\PaymentLinkReturnUrlProvider}
 *    is bound, or its output is not two absolute-HTTPS URLs. Raised BEFORE the
 *    payment provider is called at all: §2.2 forbids falling back to the
 *    guest-cookie order return route or to a gateway-global callback.
 *  - {@see self::CHECKOUT_MANUAL} -> 503. The collector answered `manual`:
 *    this store collects payment by hand, so a payment LINK has no hosted
 *    session to send anyone to.
 *  - {@see self::CHECKOUT_URL_MISSING} -> 503. `status='ok'` but the payload
 *    carries no usable checkout URL.
 *  - {@see self::CHECKOUT_URL_UNTRUSTED} -> 503. A URL was returned but is not
 *    absolute HTTPS. Kept SEPARATE from the missing case on purpose: they are
 *    the same non-event for the payer and two very different bugs for whoever
 *    has to fix the gateway.
 *  - {@see self::CHECKOUT_INITIATION_FAILED} -> 503. The collector THREW, or
 *    answered a status outside the contract's `ok|manual` domain. Payvia 2.6's
 *    ensure-live raises typed exceptions for renewal-unavailable outcomes on a
 *    repeat initiate; Commerce programs against the CONTRACT, so it catches
 *    `\Throwable` and maps every such mode here rather than letting a
 *    provider-shaped exception escape to an anonymous browser.
 *
 * ## The message never quotes the URL or the token
 *
 * {@see self::publicUrlUnavailable()} takes NO arguments, by construction. The
 * URL it is refusing embeds the raw bearer token, and exception messages reach
 * log files, error responses, and crash reporters. A refused URL is therefore
 * described by its CODE only, never quoted -- `PaymentLinkServiceTest` asserts
 * the message contains neither the token nor the host it came from.
 *
 * The two "not eligible" factories DO quote the offending origin/status: both
 * are the operator's own non-secret facts about an order the operator already
 * has access to, and naming them is what makes the 409 actionable.
 *
 * EVERY initiation factory is argument-less for the same family of reasons: the
 * things it would have to quote are a bearer token, a provider checkout URL, a
 * host return URL, or a third-party exception message that may embed any of
 * them. None of those may reach a log line or an anonymous error body.
 */
final class PaymentLinkException extends \DomainException
{
    public const ORDER_NOT_FOUND = 'order_not_found';
    public const ORDER_NOT_ADMIN_ORIGIN = 'order_not_admin_origin';
    public const ORDER_NOT_PENDING_PAYMENT = 'order_not_pending_payment';
    public const LINK_CHANGED = 'payment_link_changed';
    public const PUBLIC_URL_UNAVAILABLE = 'public_url_unavailable';

    // Initiation (payment-links Task 7, design spec §2.2).
    public const PAYMENT_LINK_NOT_PAYABLE = 'payment_link_not_payable';
    public const INITIATION_RATE_LIMITED = 'payment_link_rate_limited';
    public const RETURN_URL_UNAVAILABLE = 'return_url_unavailable';
    public const CHECKOUT_MANUAL = 'checkout_manual';
    public const CHECKOUT_URL_MISSING = 'checkout_url_missing';
    public const CHECKOUT_URL_UNTRUSTED = 'checkout_url_untrusted';
    public const CHECKOUT_INITIATION_FAILED = 'checkout_initiation_failed';

    /** The CLOSED discriminator domain. @var list<string> */
    public const ERROR_CODES = [
        self::ORDER_NOT_FOUND,
        self::ORDER_NOT_ADMIN_ORIGIN,
        self::ORDER_NOT_PENDING_PAYMENT,
        self::LINK_CHANGED,
        self::PUBLIC_URL_UNAVAILABLE,
        self::PAYMENT_LINK_NOT_PAYABLE,
        self::INITIATION_RATE_LIMITED,
        self::RETURN_URL_UNAVAILABLE,
        self::CHECKOUT_MANUAL,
        self::CHECKOUT_URL_MISSING,
        self::CHECKOUT_URL_UNTRUSTED,
        self::CHECKOUT_INITIATION_FAILED,
    ];

    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function orderNotFound(): self
    {
        return new self('No such order for this store.', self::ORDER_NOT_FOUND);
    }

    public static function orderNotAdminOrigin(string $origin): self
    {
        return new self(
            "Payment links are only available for admin-created orders; this order's origin is '{$origin}'.",
            self::ORDER_NOT_ADMIN_ORIGIN
        );
    }

    public static function orderNotPendingPayment(string $status): self
    {
        return new self(
            "This order is '{$status}' and is not awaiting payment; no payment link can be issued.",
            self::ORDER_NOT_PENDING_PAYMENT
        );
    }

    public static function linkChanged(): self
    {
        return new self(
            'This payment link is no longer the order\'s current one; reload the order and use the current link.',
            self::LINK_CHANGED
        );
    }

    /**
     * Deliberately argument-less: see the class docblock. The URL being refused
     * carries the raw token, so nothing about it may enter the message.
     */
    public static function publicUrlUnavailable(): self
    {
        return new self(
            'This store has no public payment-link address configured; no link was created.',
            self::PUBLIC_URL_UNAVAILABLE
        );
    }

    /**
     * The ONE generic initiation refusal. Argument-less by construction: naming
     * WHICH predicate failed (unknown token vs revoked vs expired vs an order
     * that has been paid) would turn an unauthenticated endpoint into an oracle
     * about links and orders the caller may not hold.
     */
    public static function linkNotPayable(): self
    {
        return new self(
            'This payment link can no longer be used to pay; ask the store for a current one.',
            self::PAYMENT_LINK_NOT_PAYABLE
        );
    }

    public static function initiationRateLimited(): self
    {
        return new self(
            'This payment link has started too many checkouts in the past hour; please try again shortly.',
            self::INITIATION_RATE_LIMITED
        );
    }

    /** Argument-less: the URLs it is refusing are host routes, not payer-facing facts. */
    public static function returnUrlUnavailable(): self
    {
        return new self(
            'This store has no payment-link return address configured; no checkout was started.',
            self::RETURN_URL_UNAVAILABLE
        );
    }

    public static function checkoutManual(): self
    {
        return new self(
            'This store collects payment manually; there is no online checkout to open.',
            self::CHECKOUT_MANUAL
        );
    }

    public static function checkoutUrlMissing(): self
    {
        return new self(
            'The payment provider started a session but returned no checkout address.',
            self::CHECKOUT_URL_MISSING
        );
    }

    /** Never quotes the offending URL: an untrusted URL is exactly what must not be echoed. */
    public static function checkoutUrlUntrusted(): self
    {
        return new self(
            'The payment provider returned a checkout address that is not a trusted HTTPS URL.',
            self::CHECKOUT_URL_UNTRUSTED
        );
    }

    /**
     * Argument-less AND deliberately un-chained: the collector's own throwable
     * (and its backtrace) may quote provider references, return URLs, or the
     * payable metadata, so it is swallowed rather than attached as `$previous`.
     */
    public static function checkoutInitiationFailed(): self
    {
        return new self(
            'The payment provider could not start a checkout for this link right now.',
            self::CHECKOUT_INITIATION_FAILED
        );
    }
}
