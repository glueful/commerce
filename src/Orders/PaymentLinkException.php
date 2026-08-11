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
 */
final class PaymentLinkException extends \DomainException
{
    public const ORDER_NOT_FOUND = 'order_not_found';
    public const ORDER_NOT_ADMIN_ORIGIN = 'order_not_admin_origin';
    public const ORDER_NOT_PENDING_PAYMENT = 'order_not_pending_payment';
    public const LINK_CHANGED = 'payment_link_changed';
    public const PUBLIC_URL_UNAVAILABLE = 'public_url_unavailable';

    /** The CLOSED discriminator domain. @var list<string> */
    public const ERROR_CODES = [
        self::ORDER_NOT_FOUND,
        self::ORDER_NOT_ADMIN_ORIGIN,
        self::ORDER_NOT_PENDING_PAYMENT,
        self::LINK_CHANGED,
        self::PUBLIC_URL_UNAVAILABLE,
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
}
