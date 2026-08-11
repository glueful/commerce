<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Contracts;

use Glueful\Bootstrap\ApplicationContext;

/**
 * The host seam that composes the browser return/cancel URLs for a PAYMENT
 * LINK's checkout session (payment-links Task 6, design spec §2.2).
 *
 * The payment-link sibling of {@see OrderPaymentReturnUrlProvider}, and
 * deliberately a SEPARATE contract rather than a reuse of it. Two reasons:
 *  1. The subject differs. The order-payment seam receives the placed ORDER row
 *     and returns the storefront's own order pages; a payment-link session
 *     belongs to a LINK whose customer may never have had a storefront session
 *     at all, and whose return route must be scoped to that link.
 *  2. The failure mode differs. An absent order-payment binding degrades
 *     gracefully (payables simply carry no return metadata, and gateways fall
 *     back to their dashboard callbacks). An absent payment-link binding is a
 *     TYPED UNAVAILABLE outcome for initiation -- design spec §2.2 is explicit
 *     that it must NOT fall back to the guest-cookie order return route or to a
 *     gateway-global callback, because either would return the payer to a page
 *     they cannot be authorized for.
 *
 * ## Link UUID, never the raw token
 *
 * This seam receives the LINK UUID and nothing else. A return URL is handed to
 * a payment provider, stored in its dashboard, and replayed through browser
 * redirects and `Referer` headers -- so putting the bearer token in it would
 * hand the link's whole authority to every intermediary. The link uuid is an
 * opaque identifier that confers nothing on its own. Task 6's
 * `PaymentLinkServiceTest` pins the parameter list by reflection so a future
 * signature cannot quietly add a token.
 *
 * ## What Commerce requires of the returned URLs
 *
 * Both `return` and `cancel` must be absolute HTTPS. Commerce validates them
 * before placing them in `PayableReference` metadata (the initiation path,
 * Task 7); a missing binding, a null, or a URL that fails validation is a typed
 * unavailable initiation outcome.
 *
 * Commerce binds {@see \Glueful\Extensions\Commerce\Orders\UnavailablePaymentLinkReturnUrlProvider}
 * by default, so a generic install compiles and fails explicitly instead of
 * silently redirecting somewhere wrong.
 */
interface PaymentLinkReturnUrlProvider
{
    /**
     * @param string $linkUuid the payment link's own uuid — NEVER the raw token
     * @return array{return: string, cancel: string}|null absolute HTTPS URLs, or
     *     null when this host has no payment-link return surface (a typed
     *     unavailable outcome, not a fallback)
     */
    public function urlsFor(ApplicationContext $context, string $linkUuid): ?array;
}
