<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\OrderListQuery;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
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
    ) {
        $this->orders ??= app($context, OrderRepository::class);
        $this->checkout ??= app($context, CheckoutService::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
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
        $order = $this->orders->findByNumber($this->context, $this->tenants->tenantUuid($this->context), $number);
        if ($order === null) {
            throw $this->notFound();
        }

        if ($this->userOwns($request, $order) || $this->tokenMatches($request, $order)) {
            unset($order['guest_token_hash']);
            return $order;
        }

        throw $this->notFound();
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
