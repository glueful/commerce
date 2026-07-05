<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\OrderStateMachine;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class AdminOrderController
{
    use ReadsAdminInput;

    public function __construct(
        private ApplicationContext $context,
        private ?OrderRepository $orders = null,
        private ?StockRepository $stock = null,
        private ?OrderPaymentService $payments = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->orders ??= app($context, OrderRepository::class);
        $this->stock ??= app($context, StockRepository::class);
        $this->payments ??= app($context, OrderPaymentService::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    public function index(Request $request): Response
    {
        return Response::success(
            $this->orders->listFor($this->context, $this->tenants->tenantUuid($this->context)),
            'Orders retrieved'
        );
    }

    public function show(Request $request, string $uuid): Response
    {
        return Response::success($this->order($uuid), 'Order retrieved');
    }

    public function cancel(Request $request, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            db($this->context)->transaction(function () use ($tenant, $uuid): void {
                $order = $this->order($uuid);
                OrderStateMachine::assertTransition((string) $order['status'], 'canceled');
                $this->releaseStock($tenant, $uuid);
                $this->orders->transition($this->context, $tenant, $uuid, 'canceled');
            });

            return Response::success($this->order($uuid), 'Order canceled');
        } catch (\DomainException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    public function markPaid(Request $request, string $uuid): Response
    {
        try {
            $this->payments->markPaid($this->context, $this->tenants->tenantUuid($this->context), $uuid);

            return Response::success($this->order($uuid), 'Order marked paid');
        } catch (\DomainException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    public function fulfill(Request $request, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $order = $this->order($uuid);
            OrderStateMachine::assertTransition((string) $order['status'], 'fulfilled');
            $input = $this->input($request);

            db($this->context)->table('commerce_orders')
                ->where('tenant_uuid', '=', $tenant)
                ->where('uuid', '=', $uuid)
                ->update([
                    'status' => 'fulfilled',
                    'fulfillment_status' => 'fulfilled',
                    'tracking_ref' => isset($input['tracking_ref']) ? (string) $input['tracking_ref'] : null,
                    'updated_at' => db($this->context)->getDriver()->formatDateTime(),
                ]);
            $this->orders->recordEvent($this->context, $uuid, 'status:fulfilled');

            return Response::success($this->order($uuid), 'Order fulfilled');
        } catch (\DomainException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    public function markRefunded(Request $request, string $uuid): Response
    {
        try {
            $this->orders->transition($this->context, $this->tenants->tenantUuid($this->context), $uuid, 'refunded');

            return Response::success($this->order($uuid), 'Order marked refunded');
        } catch (\DomainException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    private function releaseStock(string $tenant, string $orderUuid): void
    {
        $lines = db($this->context)->table('commerce_order_lines')
            ->where('order_uuid', '=', $orderUuid)
            ->get();
        foreach ($lines as $line) {
            $variantUuid = (string) $line['variant_uuid'];
            if (!$this->stock->isTracked($this->context, $tenant, $variantUuid)) {
                continue;
            }
            $this->stock->increment($this->context, $tenant, $variantUuid, (int) $line['quantity']);
            $this->stock->recordMovement(
                $this->context,
                $tenant,
                $variantUuid,
                (int) $line['quantity'],
                'release',
                $orderUuid
            );
        }
    }

    /** @return array<string,mixed> */
    private function order(string $uuid): array
    {
        $order = $this->orders->findByUuid($this->context, $this->tenants->tenantUuid($this->context), $uuid);
        if ($order === null) {
            throw new NotFoundException('Resource not found.');
        }

        return $order;
    }
}
