<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Events\LatePaymentRejected;
use Glueful\Extensions\Commerce\Events\OrderPaid;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Events\EventService;

/**
 * `$confirmation` is an APPENDED OPTIONAL collaborator -- mirroring
 * {@see \Glueful\Extensions\Commerce\Orders\CheckoutService}'s identical
 * convention -- so every pre-MV2 direct-construction call site (tests
 * included) stays source-compatible. `$afterPaidHook` is a test-only
 * injectable seam (same convention as CheckoutService's
 * `$afterOwnershipSnapshotHook`), used to deterministically force a failure
 * at a precise point inside `markPaid()`'s own transaction.
 */
final class OrderPaymentService
{
    /** @var callable(ApplicationContext,string,string):void */
    private $afterPaidHook;

    /**
     * @param (callable(ApplicationContext,string,string):void)|null $afterPaidHook
     *     Invoked with (context, tenant, orderUuid) AFTER the paid CAS and
     *     any partition `confirmed_at` stamping, but BEFORE the `OrderPaid`
     *     afterCommit registration -- still inside `markPaid()`'s own
     *     transaction. Tests use it to simulate a failure at that exact
     *     point and prove the whole transaction (CAS + stamps) rolls back
     *     together. Defaults to a no-op.
     */
    public function __construct(
        private OrderRepository $orders,
        private ?SellerOrderPaymentConfirmation $confirmation = null,
        ?callable $afterPaidHook = null,
    ) {
        $this->afterPaidHook = $afterPaidHook ?? static function (
            ApplicationContext $context,
            string $tenant,
            string $orderUuid
        ): void {
        };
    }

    /**
     * Design spec §2.12: the `pending_payment → paid` CAS and (for a
     * `marketplace_partitioned` order) the `confirmed_at` stamping of every
     * child are ONE transaction -- both commit or both roll back. `OrderPaid`
     * dispatch is registered via `db($context)->afterCommit(...)` from
     * INSIDE that transaction, so it fires only once, after the successful
     * OUTERMOST commit, even when `markPaid()` participates in a
     * caller-owned transaction (a savepoint here). The partition check reads
     * the order's OWN `marketplace_partitioned` flag -- never current
     * `activeFor()` -- BEFORE touching any seller table, so a non-partitioned
     * paid transition executes zero seller-table queries. Both callers
     * (the provider payment-confirmation handler and the admin mark-paid
     * endpoint) route through this one operation.
     */
    public function markPaid(ApplicationContext $context, string $tenant, string $orderUuid): void
    {
        db($context)->transaction(function () use ($context, $tenant, $orderUuid): void {
            $this->orders->transition($context, $tenant, $orderUuid, 'paid');

            $order = $this->orders->findByUuid($context, $tenant, $orderUuid);
            if ($order === null) {
                return;
            }

            if ($this->confirmation !== null && (bool) ($order['marketplace_partitioned'] ?? false)) {
                $this->confirmation->confirm($context, $tenant, $orderUuid);
            }

            ($this->afterPaidHook)($context, $tenant, $orderUuid);

            db($context)->afterCommit(function () use ($context, $order): void {
                $this->dispatch($context, new OrderPaid($order));
            });
        });
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
