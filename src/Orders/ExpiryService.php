<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookOutboxPublisher;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * `$sellerOrders`/`$webhooks` (MV5c-2 Task 4, design spec §2.3/§2.4): APPENDED
 * OPTIONAL collaborators -- the SAME "every pre-existing direct-construction
 * call site stays source-compatible" convention every other MV5c-2 capture
 * site in this codebase uses. `expireStale()` never claims a seller revision
 * nor a `LedgerAccountLock` (an expiry cancellation posts no ledger entries),
 * so there is no lock-order constraint on where {@see self::captureExpiryCancellation()}
 * runs relative to anything else in this class.
 *
 * ## LEASE-AWARE EXPIRY (payment-links Task 8, design spec §2.2)
 *
 * This sweep is one of the engine's two non-draft cancellation authorities, and
 * since payment links exist it can no longer decide alone. An order whose link
 * has handed a payer a live checkout URL has a LEASE on that payer's intent:
 * releasing its stock and cancelling it while money may be moving is the one
 * failure this whole feature is built to avoid. So:
 *
 *  1. the CANDIDATE query gains two prefilters
 *     ({@see PaymentLinkRepository::sweepExclusion()}) -- no active-unexpired
 *     link, and no link that has ever issued a provider session;
 *  2. the prefilters are NEVER the authority. Inside each per-order transaction
 *     the order is LOCKED, RELOADED, re-checked for status, and put to
 *     {@see PaymentSessionExposureGuard} -- BEFORE a single unit of stock moves.
 *
 * Step 2 is what closes the mint-vs-sweep and initiate-vs-sweep races: both
 * commit in the window between the candidate query and the per-order
 * transaction, so a prefilter cannot possibly see them, and only a decision
 * taken under the order lock can. `Integration\Orders\LeaseAwareExpiryTest`
 * proves each race by mutating the world inside exactly that window.
 *
 * ## What did NOT change
 *
 * A storefront order can never carry a payment link (mint refuses any origin but
 * `admin`), so for the overwhelming majority of orders the guard answers "allow"
 * and this sweep behaves exactly as it did before: same 60-minute default, same
 * stock release, same movement rows, same marketplace capture. Drafts remain
 * unreachable -- the candidate query is still pinned to an exact-match
 * `pending_payment`, and the locked reload deliberately does NOT include drafts.
 */
final class ExpiryService
{
    /** @var (\Closure(ApplicationContext, string, list<string>): void)|null */
    private ?\Closure $afterCandidates;

    private PaymentSessionExposureGuard $exposure;
    private PaymentLinkRepository $paymentLinks;

    /**
     * `$exposure`/`$paymentLinks` are appended optional collaborators like every
     * other widening here, but they DEFAULT TO REAL OBJECTS rather than to null:
     * a null guard would mean an unguarded cancellation authority, and this
     * class must not have a mode in which the §2.2 policy silently does not
     * apply. Both are stateless and dependency-free, so direct construction is
     * exactly equivalent to the container's shared instances.
     *
     * @param (callable(ApplicationContext, string, list<string>): void)|null $afterCandidates
     *     TEST-ONLY seam (the same convention as
     *     {@see OrderPaymentService}'s `$afterPaidHook`): invoked ONCE with the
     *     selected candidate uuids, after the candidate query and BEFORE the
     *     first per-order transaction -- i.e. inside the sweep window a race
     *     would land in. Production never passes it.
     */
    public function __construct(
        private OrderRepository $orders,
        private StockRepository $stock,
        private CurrentTenantResolver $tenants,
        private ?SellerOrderRepository $sellerOrders = null,
        private ?SellerWebhookOutboxPublisher $webhooks = null,
        ?PaymentSessionExposureGuard $exposure = null,
        ?PaymentLinkRepository $paymentLinks = null,
        ?callable $afterCandidates = null,
    ) {
        $this->paymentLinks = $paymentLinks ?? new PaymentLinkRepository();
        $this->exposure = $exposure ?? new PaymentSessionExposureGuard($this->paymentLinks, $this->orders);
        $this->afterCandidates = $afterCandidates === null ? null : \Closure::fromCallable($afterCandidates);
    }

    /**
     * `$now` is an APPENDED OPTIONAL clock (defaulting to the real one, so every
     * pre-existing call site -- the cron command included -- is unchanged). It
     * governs the staleness cutoff, the link prefilters, the guard's decision,
     * and the lapsed-link sweep, so one tick reasons about one instant instead
     * of four slightly different ones.
     */
    public function expireStale(ApplicationContext $context, ?\DateTimeImmutable $now = null): int
    {
        $tenant = $this->tenants->tenantUuid($context);
        $moment = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));
        $cutoff = $moment->modify('-' . CommerceSettings::orderExpiryMinutes($context) . ' minutes')
            ->format('Y-m-d H:i:s');

        // Draft isolation (admin-order-creation cycle 2, Task 8): this sweep is
        // pinned to `pending_payment` -- an exact-match ALLOWLIST that is
        // strictly stronger than OrderScope's exclusion, so a draft can never
        // be released/canceled here no matter how old it is. Stale DRAFTS are
        // the separate, draft-specific concern of
        // {@see DraftCleanupService::cancelStale()} (audit rows only, no stock
        // release, no marketplace capture). Keep this an exact match.
        //
        // The link prefilters (payment-links Task 8) are candidate selection
        // only; see the class docblock and the per-order guard call below.
        $exclusion = $this->paymentLinks->sweepExclusion($tenant, $moment);
        $orders = db($context)->table('commerce_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('status', '=', 'pending_payment')
            ->whereRaw('placed_at IS NOT NULL AND placed_at < ?', [$cutoff])
            ->whereRaw($exclusion['sql'], $exclusion['bindings'])
            ->get();

        if ($this->afterCandidates !== null) {
            ($this->afterCandidates)(
                $context,
                $tenant,
                array_map(static fn (array $order): string => (string) $order['uuid'], $orders)
            );
        }

        $expired = 0;
        foreach ($orders as $order) {
            db($context)->transaction(function () use ($context, $tenant, $order, $moment, &$expired): void {
                // THE AUTHORITY STARTS HERE. Everything above was a hint.
                //
                // Lock and RELOAD: the candidate row is a snapshot from before
                // this transaction, and `includeDrafts` is deliberately left
                // false so a row that somehow became a draft is simply not
                // found rather than swept.
                $locked = $this->orders->findByUuidForUpdate($context, $tenant, (string) $order['uuid']);
                if ($locked === null || (string) $locked['status'] !== 'pending_payment') {
                    return;
                }

                // The §2.2 guard, BEFORE any stock moves. A mint or an
                // initiation that committed in the sweep window is visible only
                // from here, under this lock.
                if (!$this->exposure->permitsAutomaticCancellation($context, $tenant, $locked, $moment)) {
                    return;
                }

                $lines = db($context)->table('commerce_order_lines')
                    ->where('order_uuid', '=', $locked['uuid'])
                    ->get();
                foreach ($lines as $line) {
                    $variantUuid = (string) $line['variant_uuid'];
                    if (!$this->stock->isTracked($context, $tenant, $variantUuid)) {
                        continue;
                    }

                    $this->stock->increment($context, $tenant, $variantUuid, (int) $line['quantity']);
                    $this->stock->recordMovement(
                        $context,
                        $tenant,
                        $variantUuid,
                        (int) $line['quantity'],
                        'release',
                        (string) $locked['uuid']
                    );
                }

                $this->orders->transition($context, $tenant, (string) $locked['uuid'], 'canceled');

                if ((bool) ($locked['marketplace_partitioned'] ?? false)) {
                    $this->captureExpiryCancellation($context, $tenant, (string) $locked['uuid'], $locked);
                }

                $expired++;
            });
        }

        // The SWEPT half of the link TTL transition (design spec §2.2), run
        // LAST on purpose: the candidate query and the guard both compare
        // `expires_at` against this same instant, so lapsing rows early would
        // rewrite statuses the sweep above already treated as lapsed without
        // changing a single outcome. Doing it here keeps one tick's reasoning
        // about one table state.
        $this->paymentLinks->expireLapsed($context, $tenant, $moment);

        return $expired;
    }

    /**
     * `order.canceled` outbox capture for the EXPIRY cancellation authority
     * (design spec §2.3/§2.4) -- the sibling of
     * {@see \Glueful\Extensions\Commerce\Http\Admin\AdminOrderController::captureOrderCanceled()}'s
     * `'operator'` call, here always `'expired'`. `expireStale()` never
     * cancels the child seller orders themselves (MV2 whole-order
     * cancellation-fan-out is an AdminOrderController-only concern today), so
     * this reads {@see SellerOrderRepository::forOrder()} as-is -- still
     * `open`/`unfulfilled` at capture time -- rather than a post-cancel
     * re-read.
     *
     * @param array<string,mixed> $order
     */
    private function captureExpiryCancellation(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        array $order
    ): void {
        if ($this->webhooks === null || $this->sellerOrders === null) {
            return;
        }

        $sellerOrderRows = $this->sellerOrders->forOrder($context, $tenant, $orderUuid);
        if ($sellerOrderRows === []) {
            return;
        }

        $data = [];
        foreach ($sellerOrderRows as $row) {
            $sellerUuid = (string) $row['seller_uuid'];
            $data[$sellerUuid] = [
                'order_uuid' => $orderUuid,
                'order_number' => (string) ($order['order_number'] ?? ''),
                'currency' => (string) $row['currency'],
                'occurred_at' => gmdate('Y-m-d H:i:s'),
                'seller_order_uuid' => (string) $row['uuid'],
                'seller_reference' => (string) $row['seller_reference'],
                'attributed_total' => (int) $row['attributed_total'],
                'cancellation_source' => 'expired',
            ];
        }

        $this->webhooks->capture($context, $tenant, 'order.canceled', [
            'data' => $data,
            'source_ref' => $orderUuid,
        ]);
    }
}
