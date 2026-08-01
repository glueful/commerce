<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Contracts;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Host-bound browser-return URLs for an order's hosted payment flow (checkout-ui plan Task 2).
 *
 * Commerce is headless: it cannot know where the storefront's return/cancel pages live. A host
 * application that renders a checkout binds this contract; {@see \Glueful\Extensions\Commerce\
 * Orders\CheckoutService::initiatePayment()} calls it with the COMPLETED order row (the order
 * number is final), so the same path serves initial placement, durable replay, and payment retry —
 * no placeholder substitution, no request-host trust (implementations must compose from a
 * canonical, configured origin, never the incoming Host header).
 *
 * The URLs feed payvia's payable metadata convention (`callback_url` / `cancel_url`) and are
 * browser NAVIGATION only — webhooks remain the settlement authority. Commerce validates the
 * returned values as absolute HTTPS; invalid output degrades the payment leg to `init_failed`
 * without rolling back the placed order. Absent binding = no URL metadata (gateways fall back to
 * their dashboard-configured callbacks where they have one).
 */
interface OrderPaymentReturnUrlProvider
{
    /**
     * @param array<string,mixed> $order the placed order row (order_number, uuid, email, …)
     * @return array{return: string, cancel: string}|null absolute HTTPS URLs, or null for none
     */
    public function urlsFor(ApplicationContext $context, array $order): ?array;
}
