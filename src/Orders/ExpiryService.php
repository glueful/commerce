<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

final class ExpiryService
{
    public function __construct(
        private OrderRepository $orders,
        private StockRepository $stock,
        private CurrentTenantResolver $tenants,
    ) {
    }

    public function expireStale(ApplicationContext $context): int
    {
        $tenant = $this->tenants->tenantUuid($context);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ((int) config($context, 'commerce.orders.expiry_minutes', 60) * 60));
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
                $expired++;
            });
        }

        return $expired;
    }
}
