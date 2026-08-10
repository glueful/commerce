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
 */
final class ExpiryService
{
    public function __construct(
        private OrderRepository $orders,
        private StockRepository $stock,
        private CurrentTenantResolver $tenants,
        private ?SellerOrderRepository $sellerOrders = null,
        private ?SellerWebhookOutboxPublisher $webhooks = null,
    ) {
    }

    public function expireStale(ApplicationContext $context): int
    {
        $tenant = $this->tenants->tenantUuid($context);
        $cutoff = gmdate('Y-m-d H:i:s', time() - (CommerceSettings::orderExpiryMinutes($context) * 60));
        // Draft isolation (admin-order-creation cycle 2, Task 8): this sweep is
        // pinned to `pending_payment` -- an exact-match ALLOWLIST that is
        // strictly stronger than OrderScope's exclusion, so a draft can never
        // be released/canceled here no matter how old it is. Stale DRAFTS are
        // the separate, draft-specific concern of
        // {@see DraftCleanupService::cancelStale()} (audit rows only, no stock
        // release, no marketplace capture). Keep this an exact match.
        $orders = db($context)->table('commerce_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('status', '=', 'pending_payment')
            ->whereRaw('placed_at IS NOT NULL AND placed_at < ?', [$cutoff])
            ->get();

        $expired = 0;
        foreach ($orders as $order) {
            db($context)->transaction(function () use ($context, $tenant, $order, &$expired): void {
                $lines = db($context)->table('commerce_order_lines')
                    ->where('order_uuid', '=', $order['uuid'])
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
                        (string) $order['uuid']
                    );
                }

                $this->orders->transition($context, $tenant, (string) $order['uuid'], 'canceled');

                if ((bool) ($order['marketplace_partitioned'] ?? false)) {
                    $this->captureExpiryCancellation($context, $tenant, (string) $order['uuid'], $order);
                }

                $expired++;
            });
        }

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
