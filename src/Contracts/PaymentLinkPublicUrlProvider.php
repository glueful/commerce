<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Contracts;

use Glueful\Bootstrap\ApplicationContext;

/**
 * The host seam that composes a payment link's PUBLIC, customer-facing URL
 * (payment-links Task 6, design spec §2.2).
 *
 * Commerce is headless: it cannot know where a host's public payment-link page
 * lives, and it must never trust the incoming `Host` header to guess. A host
 * with such a surface binds this contract and composes from its own canonical
 * origin authority; Commerce binds
 * {@see \Glueful\Extensions\Commerce\Orders\UnavailablePaymentLinkPublicUrlProvider}
 * by default so a generic install still compiles and receives a TYPED
 * unavailable outcome rather than a broken or guessed URL.
 *
 * ## The raw token
 *
 * This is the ONLY Commerce contract that ever receives the raw bearer token,
 * and it receives it IN MEMORY ONLY, before persistence. Implementations must
 * therefore:
 *  - compose and return, nothing else. No logging of the token or the composed
 *    URL, no storage, no outbound call, no exception message that quotes either.
 *  - be pure with respect to the token: the same token in, the same URL out.
 *
 * {@see \Glueful\Extensions\Commerce\Orders\PaymentLinkService::mintPublic()}
 * calls this BEFORE it opens the mint transaction, and validates the result
 * before any row is written -- so a missing binding, a null, an exception, or a
 * URL that fails validation creates NO link at all.
 *
 * ## What Commerce requires of the returned URL
 *
 * Validated by `PaymentLinkService::mintPublic()`, all of which must hold:
 *  - absolute, scheme exactly `https`;
 *  - a host, and NO userinfo (`user:pass@`) and NO explicit port;
 *  - NO query string and NO fragment;
 *  - the raw token appears in the whole URL EXACTLY ONCE, and it is the FINAL
 *    path segment (no trailing slash after it).
 *
 * Anything else is a typed unavailable outcome
 * ({@see \Glueful\Extensions\Commerce\Orders\PaymentLinkException::PUBLIC_URL_UNAVAILABLE}),
 * never a silently-accepted URL: a token in a query string lands in access logs
 * and `Referer` headers, and a token that is not the last segment cannot be
 * routed back out by the resolve endpoint.
 */
interface PaymentLinkPublicUrlProvider
{
    /**
     * @param string $rawToken 64 lowercase hex characters, in memory only —
     *     never log, store, or forward it
     * @return string|null the absolute HTTPS URL, or null when this host has no
     *     public payment-link surface (a typed unavailable outcome, not a fallback)
     */
    public function urlFor(ApplicationContext $context, string $rawToken): ?string;
}
