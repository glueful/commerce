<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Events\LatePaymentRejected;
use Glueful\Extensions\Commerce\Events\OrderPaid;
use Glueful\Events\EventService;

final class OrderPaymentService
{
    public function __construct(private OrderRepository $orders)
    {
    }

    public function markPaid(ApplicationContext $context, string $tenant, string $orderUuid): void
    {
        $this->orders->transition($context, $tenant, $orderUuid, 'paid');
        $order = $this->orders->findByUuid($context, $tenant, $orderUuid);
        if ($order !== null) {
            $this->dispatch($context, new OrderPaid($order));
        }
    }

    /** @param array<string,mixed> $payload */
    public function rejectLatePayment(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        array $payload,
    ): void {
        $this->orders->recordEvent($context, $orderUuid, 'payment_late_rejected', $payload);
        $this->dispatch($context, new LatePaymentRejected($payload + [
            'order_uuid' => $orderUuid,
            'tenant_uuid' => $tenant,
        ]));
    }

    private function dispatch(ApplicationContext $context, object $event): void
    {
        $container = container($context);
        if ($container->has(EventService::class)) {
            $container->get(EventService::class)->dispatch($event);
        }
    }
}
