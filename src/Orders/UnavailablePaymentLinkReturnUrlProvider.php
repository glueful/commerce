<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkReturnUrlProvider;

/**
 * The ENGINE DEFAULT binding for {@see PaymentLinkReturnUrlProvider}
 * (payment-links Task 6, design spec §2.2), the exact sibling of
 * {@see UnavailablePaymentLinkPublicUrlProvider}.
 *
 * Null means "this host has no payment-link return surface". Design spec §2.2
 * is explicit about what the consumer (Task 7's initiation path) must then do:
 * report a TYPED unavailable initiation outcome. It must NOT fall back to the
 * guest-cookie order return route or to a gateway-global callback -- both would
 * send the payer to a page they cannot be authorized for, after their money has
 * already moved.
 */
final class UnavailablePaymentLinkReturnUrlProvider implements PaymentLinkReturnUrlProvider
{
    /** @return array{return: string, cancel: string}|null */
    public function urlsFor(ApplicationContext $context, string $linkUuid): ?array
    {
        return null;
    }
}
