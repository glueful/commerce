<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
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

    public function show(Request $request, string $number): Response
    {
        return Response::success($this->authorizedOrder($request, $number), 'Order retrieved');
    }

    public function retryPayment(Request $request, string $number): Response
    {
        try {
            $order = $this->authorizedOrder($request, $number);

            return Response::success($this->checkout->retryPayment($this->context, $order), 'Payment retry created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    public function mine(Request $request): Response
    {
        $user = $request->attributes->get('user');
        $userUuid = is_array($user) && isset($user['uuid']) ? (string) $user['uuid'] : '';
        if ($userUuid === '') {
            throw new NotFoundException('Resource not found.');
        }

        $orders = array_values(array_filter(
            $this->orders->listFor($this->context, $this->tenants->tenantUuid($this->context)),
            fn (array $order): bool => ($order['user_uuid'] ?? null) === $userUuid
        ));

        return Response::success($orders, 'Orders retrieved');
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
