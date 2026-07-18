<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Extensions\Commerce\Events\SellerOrderFulfilled;
use Glueful\Extensions\Commerce\Orders\FulfillmentStatus;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Http\Exceptions\Client\NotFoundException;

/**
 * The §2.8 fulfillment claim/rollup/dispatch flow, for both the
 * single-seller path (`fulfill()`, seller-authored or operator-authored) and
 * the operator fan-out (`fanOutFulfill()`, §2.9). Every mutation claims the
 * parent `commerce_orders.fulfillment_revision`
 * ({@see OrderRepository::claimFulfillmentMutation()}) FIRST, inside its own
 * transaction, so two concurrent child fulfillments serialize and every
 * roll-up is computed on committed state -- no double-`partial`, no
 * duplicated events.
 */
final class SellerOrderFulfillmentService
{
    public function __construct(
        private OrderRepository $orders,
        private SellerOrderRepository $sellerOrders,
    ) {
    }

    /**
     * Single seller-order fulfillment (design spec §2.8): claim parent ->
     * claim child -> authorize -> validate fulfillable -> transition child ->
     * re-read all children -> roll up (+ guarded parent CAS to `fulfilled`
     * when every non-`canceled` child is now fulfilled) -> after commit,
     * dispatch `SellerOrderFulfilled` for this child and, only when the
     * parent just reached `fulfilled`, `OrderFulfilled` once.
     *
     * `$actorSellerUuid`: `null` means an operator is acting (any child may
     * be fulfilled); a non-null value must equal the child's OWN
     * `seller_uuid` or the request is treated exactly like an unknown seller
     * order -- a non-revealing 404 (§6.4: cross-seller access must never
     * distinguish "wrong seller" from "doesn't exist").
     *
     * @param array{carrier?:?string, tracking_number?:?string, tracking_url?:?string} $tracking
     * @return array<string,mixed> the transitioned child row
     */
    public function fulfill(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        string $sellerOrderUuid,
        array $tracking,
        ?string $actorSellerUuid
    ): array {
        return db($context)->transaction(function () use (
            $context,
            $tenant,
            $orderUuid,
            $sellerOrderUuid,
            $tracking,
            $actorSellerUuid
        ): array {
            // (1) Claim the parent FIRST -- throws for an unknown/cross-tenant order.
            $this->orders->claimFulfillmentMutation($context, $tenant, $orderUuid);
            $order = $this->orders->findByUuid($context, $tenant, $orderUuid);
            if ($order === null) {
                throw new NotFoundException('Resource not found.');
            }

            // (2) Claim the child's own revision.
            if (!$this->sellerOrders->claimRevision($context, $tenant, $sellerOrderUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $child = $this->sellerOrders->findByUuid($context, $tenant, $sellerOrderUuid);
            if ($child === null || (string) $child['order_uuid'] !== $orderUuid) {
                throw new NotFoundException('Resource not found.');
            }

            // (3) Authorize: null actor = operator (any child); otherwise the
            // acting seller must own this exact child.
            if ($actorSellerUuid !== null && $actorSellerUuid !== (string) $child['seller_uuid']) {
                throw new NotFoundException('Resource not found.');
            }

            // (4) Validate fulfillable.
            if (!(bool) ($order['marketplace_partitioned'] ?? false)) {
                throw new NotFoundException('Resource not found.');
            }
            if ($child['confirmed_at'] === null) {
                throw new NotFoundException('Resource not found.');
            }
            if ((string) $child['status'] === 'canceled') {
                throw new \DomainException('Seller order is canceled.');
            }
            if ((string) $child['fulfillment_status'] === FulfillmentStatus::CHILD_FULFILLED) {
                throw new \DomainException('Seller order is already fulfilled.');
            }

            // (5) Apply the child transition.
            $this->sellerOrders->markFulfilled($context, $tenant, $sellerOrderUuid, $tracking);
            $fulfilledChild = $this->sellerOrders->findByUuid($context, $tenant, $sellerOrderUuid);

            // (6) Re-read every child of the parent and roll up.
            $parentBecameFulfilled = $this->rollUp($context, $tenant, $orderUuid);
            $updatedOrder = $this->orders->findByUuid($context, $tenant, $orderUuid);

            $this->dispatchAfterCommit($context, [$fulfilledChild], $updatedOrder, $parentBecameFulfilled);

            return $fulfilledChild;
        });
    }

    /**
     * Operator fan-out fulfillment (design spec §2.9): claim the parent,
     * mark EVERY non-`canceled`, not-yet-`fulfilled` child fulfilled with
     * the same tracking payload, roll up (which -- since every eligible
     * child was just fulfilled -- always reaches `fulfilled`), and
     * transition the parent order `paid -> fulfilled`. Rejected for a
     * non-partitioned/unknown order (independent parent-only fulfillment on
     * a partitioned order is a Task 9 HTTP-layer concern; this method is
     * ALWAYS the fan-out).
     *
     * `$actorContext` is accepted for interface symmetry with `fulfill()`
     * and future auditing but performs no per-child authorization here: a
     * fan-out is by definition operator-only, so every non-canceled child is
     * eligible regardless of its owning seller.
     *
     * @param array{carrier?:?string, tracking_number?:?string, tracking_url?:?string} $tracking
     * @return array<string,mixed> the (possibly-transitioned) parent order row
     */
    public function fanOutFulfill(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        array $tracking,
        ?string $actorContext = null
    ): array {
        return db($context)->transaction(function () use ($context, $tenant, $orderUuid, $tracking): array {
            $this->orders->claimFulfillmentMutation($context, $tenant, $orderUuid);
            $order = $this->orders->findByUuid($context, $tenant, $orderUuid);
            if ($order === null || !(bool) ($order['marketplace_partitioned'] ?? false)) {
                throw new NotFoundException('Resource not found.');
            }

            $transitionedUuids = [];
            foreach ($this->sellerOrders->forOrder($context, $tenant, $orderUuid) as $child) {
                if ((string) $child['status'] === 'canceled') {
                    continue;
                }
                if ((string) $child['fulfillment_status'] === FulfillmentStatus::CHILD_FULFILLED) {
                    continue;
                }

                $sellerOrderUuid = (string) $child['uuid'];
                if (!$this->sellerOrders->claimRevision($context, $tenant, $sellerOrderUuid)) {
                    continue;
                }

                $this->sellerOrders->markFulfilled($context, $tenant, $sellerOrderUuid, $tracking);
                $transitionedUuids[] = $sellerOrderUuid;
            }

            $parentBecameFulfilled = $this->rollUp($context, $tenant, $orderUuid);
            $updatedOrder = $this->orders->findByUuid($context, $tenant, $orderUuid);

            $transitionedChildren = array_map(
                fn (string $uuid): array => $this->sellerOrders->findByUuid($context, $tenant, $uuid),
                $transitionedUuids
            );

            $this->dispatchAfterCommit($context, $transitionedChildren, $updatedOrder, $parentBecameFulfilled);

            return $updatedOrder;
        });
    }

    /**
     * Re-reads every child of the order and writes the rolled-up parent
     * `fulfillment_status` (design spec §2.8, step 4).
     *
     * @return bool true iff the parent order's own `status` just transitioned
     *     to `fulfilled`.
     */
    private function rollUp(ApplicationContext $context, string $tenant, string $orderUuid): bool
    {
        $children = $this->sellerOrders->forOrder($context, $tenant, $orderUuid);
        $parentStatus = FulfillmentStatus::rollup($children);

        return $this->orders->applyFulfillmentRollup($context, $tenant, $orderUuid, $parentStatus);
    }

    /**
     * Registers the post-commit event dispatch (design spec §2.8, step 5):
     * `SellerOrderFulfilled` once per transitioned child, and `OrderFulfilled`
     * exactly once, only when this call is what brought the parent to
     * `fulfilled` -- mirroring
     * {@see \Glueful\Extensions\Commerce\Orders\OrderPaymentService::markPaid()}'s
     * `afterCommit()` convention so events never fire on a rolled-back
     * transaction and never fire early at an inner savepoint release.
     *
     * @param list<array<string,mixed>> $transitionedChildren
     * @param array<string,mixed> $order
     */
    private function dispatchAfterCommit(
        ApplicationContext $context,
        array $transitionedChildren,
        array $order,
        bool $parentBecameFulfilled
    ): void {
        db($context)->afterCommit(function () use (
            $context,
            $transitionedChildren,
            $order,
            $parentBecameFulfilled
        ): void {
            foreach ($transitionedChildren as $child) {
                $this->dispatch($context, new SellerOrderFulfilled($child));
            }
            if ($parentBecameFulfilled) {
                $this->dispatch($context, new OrderFulfilled($order));
            }
        });
    }

    private function dispatch(ApplicationContext $context, object $event): void
    {
        $container = container($context);
        if ($container->has(EventService::class)) {
            $container->get(EventService::class)->dispatch($event);
        }
    }
}
