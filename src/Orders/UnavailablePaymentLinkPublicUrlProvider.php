<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkPublicUrlProvider;

/**
 * The ENGINE DEFAULT binding for {@see PaymentLinkPublicUrlProvider}
 * (payment-links Task 6, design spec §2.2): a generic host that has no public
 * payment-link page still compiles, still resolves the service, and receives a
 * TYPED unavailable result from {@see PaymentLinkService::mintPublic()} --
 * never a guessed origin, never a URL composed from the request `Host` header,
 * and never a link row minted against a URL nobody can open.
 *
 * Returning null (rather than throwing) is deliberate: "this host has no such
 * surface" is an ordinary configuration fact, and the service turns it into the
 * one typed outcome callers branch on. It also means an install that later
 * binds a real provider changes nothing else.
 *
 * The token is accepted and immediately discarded -- unused parameters here are
 * the point, not an oversight.
 */
final class UnavailablePaymentLinkPublicUrlProvider implements PaymentLinkPublicUrlProvider
{
    public function urlFor(ApplicationContext $context, string $rawToken): ?string
    {
        return null;
    }
}
