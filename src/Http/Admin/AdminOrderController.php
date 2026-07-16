<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Cart\AddonSnapshot;
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Extensions\Commerce\Events\OrderNoteAdded;
use Glueful\Extensions\Commerce\Http\DTOs\CreateOrderNoteData;
use Glueful\Extensions\Commerce\Http\DTOs\FulfillOrderData;
use Glueful\Extensions\Commerce\Http\DTOs\OrderListQuery;
use Glueful\Extensions\Commerce\Invoices\InvoiceData;
use Glueful\Extensions\Commerce\Invoices\SellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\OrderStateMachine;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

final class AdminOrderController
{
    use ResolvesActor;

    public function __construct(
        private ApplicationContext $context,
        private ?OrderRepository $orders = null,
        private ?StockRepository $stock = null,
        private ?OrderPaymentService $payments = null,
        private ?CurrentTenantResolver $tenants = null,
        private ?RefundRepository $refunds = null,
        private ?SellerIdentityProvider $sellerIdentity = null,
    ) {
        $this->orders ??= app($context, OrderRepository::class);
        $this->stock ??= app($context, StockRepository::class);
        $this->payments ??= app($context, OrderPaymentService::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        $this->refunds ??= app($context, RefundRepository::class);
        $this->sellerIdentity ??= app($context, SellerIdentityProvider::class);
    }

    #[ApiOperation(summary: 'List orders', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Orders retrieved')]
    public function index(OrderListQuery $query, Request $request): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $result = $this->orders->paginatedFor(
            $this->context,
            $this->tenants->tenantUuid($this->context),
            array_filter(['status' => $query->status], static fn (mixed $value): bool => $value !== null),
            $page,
            $perPage
        );

        return Response::paginated(
            $result['items'],
            $result['total'],
            $page,
            $perPage,
            null,
            'Orders retrieved'
        );
    }

    #[ApiOperation(summary: 'Get an order', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Order retrieved')]
    #[ApiResponse(404, description: 'Order not found')]
    public function show(Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $order = $this->order($uuid);
        $order['events'] = $this->orders->eventsForOrder($this->context, $tenant, $uuid);
        $order['lines'] = $this->linesProjection($tenant, $uuid);

        return Response::success($order, 'Order retrieved');
    }

    #[ApiOperation(summary: 'Cancel an order', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Order canceled')]
    #[ApiResponse(409, description: 'Invalid order transition')]
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

    #[ApiOperation(summary: 'Mark an order paid', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Order marked paid')]
    #[ApiResponse(409, description: 'Invalid order transition')]
    public function markPaid(Request $request, string $uuid): Response
    {
        try {
            $this->payments->markPaid($this->context, $this->tenants->tenantUuid($this->context), $uuid);

            return Response::success($this->order($uuid), 'Order marked paid');
        } catch (\DomainException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(summary: 'Fulfill an order', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Order fulfilled')]
    #[ApiResponse(409, description: 'Invalid order transition')]
    public function fulfill(FulfillOrderData $input, Request $request, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $order = $this->order($uuid);
            OrderStateMachine::assertTransition((string) $order['status'], 'fulfilled');

            db($this->context)->table('commerce_orders')
                ->where('tenant_uuid', '=', $tenant)
                ->where('uuid', '=', $uuid)
                ->update([
                    'status' => 'fulfilled',
                    'fulfillment_status' => 'fulfilled',
                    'tracking_ref' => $input->tracking_ref,
                    'updated_at' => db($this->context)->getDriver()->formatDateTime(),
                ]);
            $this->orders->recordEvent($this->context, $uuid, 'status:fulfilled');
            $fulfilled = $this->order($uuid);
            $this->dispatch(new OrderFulfilled($fulfilled));

            return Response::success($fulfilled, 'Order fulfilled');
        } catch (\DomainException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(summary: 'Add a note to an order', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Note added')]
    #[ApiResponse(404, description: 'Order not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function addNote(CreateOrderNoteData $input, Request $request, string $uuid): Response
    {
        // Tenant-scoped 404 guard first (non-revealing), before any validation or write.
        $order = $this->order($uuid);

        if ($input->notify && $input->visibility !== 'customer') {
            return Response::validation([
                'notify' => 'notify requires visibility to be customer.',
            ]);
        }

        $actorUuid = $this->actorUuid($request);
        $note = [
            'body' => $input->body,
            'visibility' => $input->visibility,
            'notify' => $input->notify,
            'actor_uuid' => $actorUuid,
        ];

        $this->orders->recordEvent($this->context, $uuid, 'note.added', $note, $actorUuid, $input->visibility);

        // recordEvent() is not transactional, so it's already durable by the time we get
        // here; dispatching directly (no afterCommit) is correct.
        if ($input->notify) {
            $this->dispatch(new OrderNoteAdded($order, $note));
        }

        return Response::success(['order_uuid' => $uuid, 'note' => $note], 'Note added');
    }

    #[ApiOperation(summary: "Get an order's notes", tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Notes retrieved')]
    #[ApiResponse(404, description: 'Order not found')]
    public function notes(Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        // Tenant-scoped 404 guard first (non-revealing), before any further reads.
        $this->order($uuid);

        $notes = array_values(array_filter(
            $this->orders->eventsForOrder($this->context, $tenant, $uuid),
            static fn (array $event): bool => ($event['type'] ?? null) === 'note.added'
        ));

        return Response::success($notes, 'Notes retrieved');
    }

    #[ApiOperation(summary: 'Get invoice data for an order', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Invoice data retrieved')]
    #[ApiResponse(404, description: 'Order not found')]
    public function invoiceData(Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        // Tenant-scoped 404 guard first (non-revealing), before any further reads.
        $order = $this->order($uuid);
        $lines = $this->orders->linesForOrder($this->context, $tenant, $uuid);
        $refunds = $this->refunds->listForOrder($this->context, $tenant, $uuid);
        $seller = $this->sellerIdentity->forTenant($this->context, $tenant);

        return Response::success(
            InvoiceData::build($this->context, $order, $lines, $refunds, $seller),
            'Invoice data retrieved'
        );
    }

    /**
     * Order lines whitelisted to exactly {product_name, sku, quantity, unit_price,
     * line_total, option_values, addons} -- never the internal `id`, `uuid`,
     * `order_uuid`, or `variant_uuid` columns {@see OrderRepository::linesForOrder()}
     * also returns. `addons` gets the same SANITIZED echo per line (design spec §4)
     * -- `{name, field_type?, choice_label?, value?, price_delta}` only, never
     * `addon_uuid`, `choice_key`, choices arrays, status, or any other
     * addon-definition internal. Admin order detail is otherwise the trusted
     * full-visibility surface (see events, above), but line/addon internals carry
     * no operational value here and stay whitelisted just like every other surface.
     * `option_values` is already JSON-decoded to an array by `linesForOrder()`.
     *
     * @return list<array{
     *     uuid: string, product_name: string, sku: string, quantity: int, unit_price: int,
     *     line_total: int, option_values: array<string,mixed>, addons: list<array<string,mixed>>
     * }>
     */
    private function linesProjection(string $tenant, string $orderUuid): array
    {
        return array_map(static function (array $line): array {
            return [
                // Operator surface keeps the line uuid: refund line attribution
                // (CreateRefundData.lines[].order_line_uuid) is built from it.
                'uuid' => (string) ($line['uuid'] ?? ''),
                'product_name' => (string) ($line['product_name'] ?? ''),
                'sku' => (string) ($line['sku'] ?? ''),
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unit_price' => (int) ($line['unit_price'] ?? 0),
                'line_total' => (int) ($line['line_total'] ?? 0),
                'option_values' => is_array($line['option_values'] ?? null) ? $line['option_values'] : [],
                'addons' => AddonSnapshot::sanitize(is_array($line['addons'] ?? null) ? $line['addons'] : []),
            ];
        }, $this->orders->linesForOrder($this->context, $tenant, $orderUuid));
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

    private function dispatch(object $event): void
    {
        $container = container($this->context);
        if ($container->has(EventService::class)) {
            $container->get(EventService::class)->dispatch($event);
        }
    }
}
