<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Http\Exceptions\Client\NotFoundException;

/**
 * The NON-PARTITIONED fulfillment path, extracted verbatim from
 * {@see \Glueful\Extensions\Commerce\Http\Admin\AdminOrderController::fulfill()}
 * (admin-order-creation cycle 2, Task 5) -- a peer of
 * {@see OrderPaymentService::markPaid()}. A marketplace-partitioned order
 * never reaches this class: `AdminOrderController::fulfill()` still routes
 * those through `SellerOrderFulfillmentService::fanOutFulfill()` itself and
 * this service is never consulted, exactly as before extraction.
 *
 * Semantics are preserved byte-for-byte from the pre-extraction controller
 * method, including its exact `OrderFulfilled` dispatch timing: the CAS
 * transition runs inside its own transaction, but the dispatch happens
 * synchronously AFTER that transaction has already returned/committed --
 * NOT registered via `db($context)->afterCommit(...)` from inside it (unlike
 * {@see OrderPaymentService::markPaid()}'s `OrderPaid` dispatch). Mirroring
 * "whatever the current semantics are" (design brief) means keeping that
 * difference, not "fixing" it into the afterCommit idiom.
 */
final class OrderFulfillmentService
{
    public function __construct(
        private OrderRepository $orders,
    ) {
    }

    /**
     * @return array<string,mixed> the RAW fulfilled row -- callers project
     *     it for any HTTP response; the `OrderFulfilled` event above always
     *     carries this same raw shape (listeners/webhook fan-out read
     *     internal columns).
     */
    public function fulfill(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        ?string $trackingRef
    ): array {
        db($context)->transaction(function () use ($context, $tenant, $orderUuid, $trackingRef): void {
            // Same unknown/cross-tenant 404 pre-check as the controller's cancel():
            // without it, transition()'s missing-order RuntimeException surfaces as a 500.
            $this->order($context, $tenant, $orderUuid);
            $this->orders->transition($context, $tenant, $orderUuid, 'fulfilled', [
                'fulfillment_status' => 'fulfilled',
                'tracking_ref' => $trackingRef,
            ]);
        });

        // The event gets the RAW row (listeners/webhook fan-out read internal
        // columns); only the HTTP response projects.
        $fulfilled = $this->order($context, $tenant, $orderUuid);
        $this->dispatch($context, new OrderFulfilled($fulfilled));

        return $fulfilled;
    }

    /** @return array<string,mixed> */
    private function order(ApplicationContext $context, string $tenant, string $orderUuid): array
    {
        $order = $this->orders->findByUuid($context, $tenant, $orderUuid);
        if ($order === null) {
            throw new NotFoundException('Resource not found.');
        }

        return $order;
    }

    private function dispatch(ApplicationContext $context, object $event): void
    {
        $container = container($context);
        if ($container->has(EventService::class)) {
            $container->get(EventService::class)->dispatch($event);
        }
    }
}
