<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\AddonSnapshot;
use Glueful\Extensions\Commerce\Http\DTOs\OrderListQuery;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class OrderController
{
    public function __construct(
        private ApplicationContext $context,
        private ?OrderRepository $orders = null,
        private ?CheckoutService $checkout = null,
        private ?CurrentTenantResolver $tenants = null,
        private ?RefundRepository $refunds = null,
    ) {
        $this->orders ??= app($context, OrderRepository::class);
        $this->checkout ??= app($context, CheckoutService::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        $this->refunds ??= app($context, RefundRepository::class);
    }

    #[ApiOperation(summary: 'Get an order by number', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Order retrieved')]
    #[ApiResponse(404, description: 'Order not found')]
    public function show(Request $request, string $number): Response
    {
        return Response::success($this->authorizedOrder($request, $number), 'Order retrieved');
    }

    #[ApiOperation(summary: 'Retry payment for an order', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Payment retry created')]
    #[ApiResponse(404, description: 'Order not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function retryPayment(Request $request, string $number): Response
    {
        try {
            $order = $this->authorizedOrder($request, $number);

            return Response::success($this->checkout->retryPayment($this->context, $order), 'Payment retry created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'List the authenticated user orders', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Orders retrieved')]
    #[ApiResponse(404, description: 'User not found')]
    public function mine(OrderListQuery $query, Request $request): Response
    {
        $user = $request->attributes->get('user');
        $userUuid = is_array($user) && isset($user['uuid']) ? (string) $user['uuid'] : '';
        if ($userUuid === '') {
            throw new NotFoundException('Resource not found.');
        }

        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $result = $this->orders->paginatedFor(
            $this->context,
            $this->tenants->tenantUuid($this->context),
            array_filter([
                'user_uuid' => $userUuid,
                'status' => $query->status,
            ], static fn (mixed $value): bool => $value !== null),
            $page,
            $perPage
        );

        return Response::paginated($result['items'], $result['total'], $page, $perPage, null, 'Orders retrieved');
    }

    /** @return array<string,mixed> */
    private function authorizedOrder(Request $request, string $number): array
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $order = $this->orders->findByNumber($this->context, $tenant, $number);
        if ($order === null) {
            throw $this->notFound();
        }

        if ($this->userOwns($request, $order) || $this->tokenMatches($request, $order)) {
            unset($order['guest_token_hash']);
            $orderUuid = (string) $order['uuid'];
            $order['refunds'] = $this->refundsProjection($tenant, $orderUuid);
            $order['notes'] = $this->notesProjection($tenant, $orderUuid);
            $order['lines'] = $this->linesProjection($tenant, $orderUuid);

            return $order;
        }

        throw $this->notFound();
    }

    /**
     * Completed refunds only, sanitized to exactly {date, amount_minor, method}. Never
     * exposes reason, status, provider_ref, idempotency_key, or initiated_by.
     *
     * @return list<array{date: mixed, amount_minor: int, method: string}>
     */
    private function refundsProjection(string $tenant, string $orderUuid): array
    {
        $completed = array_filter(
            $this->refunds->listForOrder($this->context, $tenant, $orderUuid),
            static fn (array $refund): bool => ($refund['status'] ?? null) === 'completed'
        );

        return array_values(array_map(
            static fn (array $refund): array => [
                'date' => $refund['completed_at'],
                'amount_minor' => (int) $refund['amount'],
                'method' => (string) $refund['method'],
            ],
            $completed
        ));
    }

    /**
     * Customer-visible notes only ({@see \Glueful\Extensions\Commerce\Http\Admin\AdminOrderController::addNote()}
     * records these as `type = 'note.added'`). Internal notes and every other internal
     * event type (status transitions, refund.completed/failed, payment events, ...) never
     * reach this projection.
     *
     * @return list<array{date: mixed, body: string}>
     */
    private function notesProjection(string $tenant, string $orderUuid): array
    {
        $notes = array_filter(
            $this->orders->eventsForOrder($this->context, $tenant, $orderUuid),
            static fn (array $event): bool =>
                ($event['type'] ?? null) === 'note.added' && ($event['visibility'] ?? null) === 'customer'
        );

        return array_values(array_map(
            static function (array $event): array {
                $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

                return [
                    'date' => $event['created_at'],
                    'body' => (string) ($payload['body'] ?? ''),
                ];
            },
            $notes
        ));
    }

    /**
     * Order lines whitelisted to exactly {product_name, sku, quantity, unit_price,
     * line_total, option_values, addons} -- never the internal `id`, `uuid`,
     * `order_uuid`, or `variant_uuid` columns {@see OrderRepository::linesForOrder()}
     * also returns. `addons` gets the same SANITIZED echo per line (design spec §4)
     * -- `{name, field_type?, choice_label?, value?, price_delta}` only, never
     * `addon_uuid`, `choice_key`, choices arrays, status, or any other
     * addon-definition internal. `option_values` is already JSON-decoded to an
     * array by `linesForOrder()`.
     *
     * @return list<array{
     *     product_name: string, sku: string, quantity: int, unit_price: int,
     *     line_total: int, option_values: array<string,mixed>, addons: list<array<string,mixed>>
     * }>
     */
    private function linesProjection(string $tenant, string $orderUuid): array
    {
        return array_map(static function (array $line): array {
            return [
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

    /** @param array<string,mixed> $order */
    private function userOwns(Request $request, array $order): bool
    {
        $user = $request->attributes->get('user');
        $userUuid = is_array($user) && isset($user['uuid']) ? (string) $user['uuid'] : '';

        return $userUuid !== '' && ($order['user_uuid'] ?? null) === $userUuid;
    }

    /** @param array<string,mixed> $order */
    private function tokenMatches(Request $request, array $order): bool
    {
        $token = trim((string) $request->headers->get('X-Order-Token', ''));

        return $token !== ''
            && isset($order['guest_token_hash'])
            && hash_equals((string) $order['guest_token_hash'], TokenHasher::hash($token));
    }

    private function notFound(): NotFoundException
    {
        return new NotFoundException('Resource not found.');
    }
}
