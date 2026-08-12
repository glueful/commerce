<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Events\LatePaymentRejected;
use Glueful\Extensions\Commerce\Events\OrderPaid;
use Glueful\Extensions\Commerce\Marketplace\LedgerPostingService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookOutboxPublisher;
use Glueful\Events\EventService;

/**
 * `$confirmation` is an APPENDED OPTIONAL collaborator -- mirroring
 * {@see \Glueful\Extensions\Commerce\Orders\CheckoutService}'s identical
 * convention -- so every pre-MV2 direct-construction call site (tests
 * included) stays source-compatible. `$afterPaidHook` is a test-only
 * injectable seam (same convention as CheckoutService's
 * `$afterOwnershipSnapshotHook`), used to deterministically force a failure
 * at a precise point inside `markPaid()`'s own transaction. `$sellerOrders`
 * and `$ledgerPosting` are the same APPENDED OPTIONAL idiom (design spec
 * §2.7, MV3 Task 6): together they let `markPaid()` load a partitioned
 * order's `commerce_seller_orders` children and post their `sale_credit`/
 * `commission_debit` entries without breaking any existing positional
 * construction call site.
 */
final class OrderPaymentService
{
    /** @var callable(ApplicationContext,string,string):void */
    private $afterPaidHook;

    private PaymentLinkRepository $paymentLinks;

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
        private ?SellerOrderRepository $sellerOrders = null,
        private ?LedgerPostingService $ledgerPosting = null,
        private ?SellerWebhookOutboxPublisher $webhooks = null,
        ?PaymentLinkRepository $paymentLinks = null,
    ) {
        $this->afterPaidHook = $afterPaidHook ?? static function (
            ApplicationContext $context,
            string $tenant,
            string $orderUuid
        ): void {
        };
        // Appended optional like every other widening here, but defaulting to a
        // REAL repository rather than to null: the terminal transition in
        // markPaid() is a custody fact, not an opt-in feature, and this class
        // must not have a mode in which a paid order keeps a live payment link.
        // PaymentLinkRepository is stateless and dependency-free, so direct
        // construction is exactly equivalent to the container's shared instance.
        $this->paymentLinks = $paymentLinks ?? new PaymentLinkRepository();
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
     * paid transition executes zero seller-table (and zero ledger/lock)
     * queries. Both callers (the provider payment-confirmation handler and
     * the admin mark-paid endpoint) route through this one operation.
     *
     * Design spec §2.7 (MV3 Task 6): AFTER `$confirmation->confirm()` and
     * BEFORE `$afterPaidHook`/the `OrderPaid` afterCommit registration --
     * still inside this same transaction -- a partitioned order with both
     * ledger collaborators wired loads its `commerce_seller_orders`
     * children and posts their `sale_credit`/`commission_debit` entries via
     * `LedgerPostingService::postSale()`. Any ledger failure (a
     * {@see \Glueful\Extensions\Commerce\Marketplace\LedgerException} or
     * any other throw) propagates out of this closure and rolls back the
     * WHOLE transaction -- the paid CAS, the confirmation stamp, and any
     * already-claimed account locks together -- so a partial posting set
     * can never be observed.
     */
    public function markPaid(ApplicationContext $context, string $tenant, string $orderUuid): void
    {
        db($context)->transaction(function () use ($context, $tenant, $orderUuid): void {
            $this->orders->transition($context, $tenant, $orderUuid, 'paid');

            $order = $this->orders->findByUuid($context, $tenant, $orderUuid);
            if ($order === null) {
                return;
            }

            // TERMINAL TRANSITION, EAGERLY (payment-links Task 8, design spec
            // §2.2: "order paid => link `consumed` (eagerly where OrderPaid is
            // observed; lazily on resolve)").
            //
            // This method IS that observation point: it is the engine's only
            // `pending_payment -> paid` transition and the sole dispatcher of
            // `OrderPaid`, and both callers -- the provider confirmation handler
            // and the admin mark-paid endpoint -- route through it. Consuming
            // here rather than from an `OrderPaid` LISTENER is deliberate: the
            // event is registered `afterCommit`, is fault-isolated, and is not
            // dispatched at all when no `EventService` is bound, so a listener
            // would make a custody transition best-effort. Inside this
            // transaction it commits or rolls back with the paid CAS itself.
            //
            // The repository's compare-and-set is `active` -> `consumed`, so a
            // revoked or expired sibling keeps its own honest terminal state and
            // an order with no links is a no-op. The lazy transition on resolve
            // (Task 6) still covers any order paid by some future path that does
            // not come through here.
            $this->paymentLinks->consumeActiveForOrder(
                $context,
                $tenant,
                $orderUuid,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            );

            $partitioned = (bool) ($order['marketplace_partitioned'] ?? false);

            if ($this->confirmation !== null && $partitioned) {
                $this->confirmation->confirm($context, $tenant, $orderUuid);
            }

            $sellerOrderRows = ($partitioned && $this->sellerOrders !== null)
                ? $this->sellerOrders->forOrder($context, $tenant, $orderUuid)
                : [];

            // MV5c-2 Task 4 (design spec §2.4/§4 lock order): capture `order.paid`
            // BEFORE postSale() below claims any LedgerAccountLock -- markPaid() never
            // claims a seller revision anywhere else, so this call's own claim (inside
            // capture(), sorted, ascending seller_uuid) MUST land before any account-lock
            // claim to preserve the "revision before account lock" global order the
            // MV5b payout freeze already established for this exact commerce_sellers row.
            if ($sellerOrderRows !== [] && $this->webhooks !== null) {
                $this->captureOrderPaid($context, $tenant, $order, $sellerOrderRows);
            }

            if ($sellerOrderRows !== [] && $this->ledgerPosting !== null) {
                $this->ledgerPosting->postSale($context, $tenant, $order, $sellerOrderRows);
            }

            ($this->afterPaidHook)($context, $tenant, $orderUuid);

            db($context)->afterCommit(function () use ($context, $order): void {
                $this->dispatch($context, new OrderPaid($order));
            });
        });
    }

    /**
     * `order.paid` outbox capture (MV5c-2 Task 4, design spec §2.4). See this class's
     * own `markPaid()` docblock note for WHY this must run before `postSale()`.
     * `markPaid()` never holds a caller-side seller-revision claim, so no
     * `claimed_sellers` hint is passed -- capture() claims fresh, sorted.
     *
     * @param array<string,mixed> $order
     * @param list<array<string,mixed>> $sellerOrderRows
     */
    private function captureOrderPaid(
        ApplicationContext $context,
        string $tenant,
        array $order,
        array $sellerOrderRows
    ): void {
        $occurredAt = db($context)->getDriver()->formatDateTime();

        $data = [];
        foreach ($sellerOrderRows as $row) {
            $sellerUuid = (string) $row['seller_uuid'];
            $data[$sellerUuid] = [
                'order_uuid' => (string) $order['uuid'],
                'order_number' => (string) ($order['order_number'] ?? ''),
                'currency' => (string) $row['currency'],
                'occurred_at' => $occurredAt,
                'seller_order_uuid' => (string) $row['uuid'],
                'seller_reference' => (string) $row['seller_reference'],
                'subtotal' => (int) $row['subtotal'],
                'allocated_discount' => (int) $row['allocated_discount'],
                'allocated_shipping' => (int) $row['allocated_shipping'],
                'allocated_tax' => (int) $row['allocated_tax'],
                'attributed_total' => (int) $row['attributed_total'],
                'commission_amount' => (int) ($row['commission_amount'] ?? 0),
            ];
        }

        $this->webhooks->capture($context, $tenant, 'order.paid', [
            'data' => $data,
            'source_ref' => (string) $order['uuid'],
        ]);
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
