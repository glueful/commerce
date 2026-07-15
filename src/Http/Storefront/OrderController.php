<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\AddonSnapshot;
use Glueful\Extensions\Commerce\Http\DTOs\OrderListQuery;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadAccessService;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantService;
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
        // Deliberately NOT resolved eagerly below (unlike the collaborators above):
        // every existing test constructs this controller with exactly five
        // positional args, and an eager `??= app(...)` here would throw against
        // those tests' lightweight DI containers (which never bind these) even
        // though they never call downloads()/downloadUrl(). See the three lazy
        // accessors instead.
        private ?DownloadGrantService $downloadGrantService = null,
        private ?DownloadGrantRepository $downloadGrantRepository = null,
        private ?DownloadAccessService $downloadAccess = null,
    ) {
        $this->orders ??= app($context, OrderRepository::class);
        $this->checkout ??= app($context, CheckoutService::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        $this->refunds ??= app($context, RefundRepository::class);
    }

    private function downloadGrantService(): DownloadGrantService
    {
        return $this->downloadGrantService ??= app($this->context, DownloadGrantService::class);
    }

    private function downloadGrantRepository(): DownloadGrantRepository
    {
        return $this->downloadGrantRepository ??= app($this->context, DownloadGrantRepository::class);
    }

    private function downloadAccess(): DownloadAccessService
    {
        return $this->downloadAccess ??= app($this->context, DownloadAccessService::class);
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

    #[ApiOperation(summary: 'List digital-download grants for an order', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Downloads retrieved')]
    #[ApiResponse(404, description: 'Order not found')]
    public function downloads(Request $request, string $number): Response
    {
        $order = $this->accessCheckedOrder($request, $number);
        $tenant = (string) $order['tenant_uuid'];
        $orderUuid = (string) $order['uuid'];

        // Always repair before listing (design spec §4.1): idempotent, heals both an
        // entirely-missing set and a partial tail (some grants exist, some don't).
        $this->downloadGrantService()->ensureGrantsForOrder($this->context, $order);
        $grants = $this->downloadGrantRepository()->findForOrder($this->context, $tenant, $orderUuid);
        $fullyRefunded = $this->isFullyRefunded($order);

        $payload = array_map(
            fn (array $grant): array => $this->grantListingProjection($grant, $fullyRefunded),
            $grants
        );

        return Response::success($payload, 'Downloads retrieved');
    }

    #[ApiOperation(summary: 'Mint a signed download URL for an order grant', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Download URL generated')]
    #[ApiResponse(404, description: 'Order or grant not found')]
    #[ApiResponse(410, description: 'Download link exhausted, expired, revoked, or refund-blocked')]
    public function downloadUrl(Request $request, string $number, string $grantUuid): Response
    {
        $order = $this->accessCheckedOrder($request, $number);
        $tenant = (string) $order['tenant_uuid'];
        $orderUuid = (string) $order['uuid'];

        // Always repair before the target lookup (design spec §4.1), same as downloads().
        $this->downloadGrantService()->ensureGrantsForOrder($this->context, $order);

        $result = $this->downloadAccess()->mint(
            $this->context,
            $tenant,
            $orderUuid,
            $grantUuid,
            $request->getSchemeAndHttpHost()
        );

        if (!$result['ok']) {
            return Response::error('Download link unavailable', 410, ['code' => $result['code']]);
        }

        return Response::success(
            ['url' => $result['url'], 'expires_in' => $result['expires_in']],
            'Download URL generated'
        );
    }

    /**
     * Listing shape whitelist (design spec §4.1) -- exactly these seven keys, NEVER
     * token_hash/blob_uuid.
     *
     * @param array<string,mixed> $grant
     * @return array{grant_uuid: string, name: string, remaining: int|null,
     *     expires_at: mixed, expired: bool, revoked: bool, blocked_by_full_refund: bool}
     */
    private function grantListingProjection(array $grant, bool $orderFullyRefunded): array
    {
        return [
            'grant_uuid' => (string) $grant['uuid'],
            'name' => (string) $grant['name'],
            'remaining' => $grant['remaining'] !== null ? (int) $grant['remaining'] : null,
            'expires_at' => $grant['expires_at'],
            'expired' => $grant['expires_at'] !== null && (string) $grant['expires_at'] <= gmdate('Y-m-d H:i:s'),
            'revoked' => $grant['revoked_at'] !== null,
            'blocked_by_full_refund' => $orderFullyRefunded && $grant['refund_access_override_at'] === null,
        ];
    }

    /**
     * `grand_total > 0` guards against a FREE ($0 grand_total) order being
     * mistaken for a fully-refunded one -- `0 >= 0` would otherwise be
     * trivially true and permanently report `blocked_by_full_refund` for a
     * free digital-product order's grants.
     *
     * @param array<string,mixed> $order
     */
    private function isFullyRefunded(array $order): bool
    {
        $grandTotal = (int) ($order['grand_total'] ?? 0);

        return $grandTotal > 0 && (int) ($order['refunded_total'] ?? 0) >= $grandTotal;
    }

    /** @return array<string,mixed> */
    private function authorizedOrder(Request $request, string $number): array
    {
        $order = $this->accessCheckedOrder($request, $number);
        $tenant = (string) $order['tenant_uuid'];
        $orderUuid = (string) $order['uuid'];

        $order['refunds'] = $this->refundsProjection($tenant, $orderUuid);
        $order['notes'] = $this->notesProjection($tenant, $orderUuid);
        $order['lines'] = $this->linesProjection($tenant, $orderUuid);

        return $order;
    }

    /**
     * The shared access check (guest token header OR authenticated owner) reused
     * verbatim by every order-scoped endpoint: `show()`/`retryPayment()` via
     * {@see self::authorizedOrder()}, and the two digital-download endpoints above.
     * Returns the raw order row (guest_token_hash stripped); throws non-revealing 404
     * for an unknown order OR a failed access check alike.
     *
     * @return array<string,mixed>
     */
    private function accessCheckedOrder(Request $request, string $number): array
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $order = $this->orders->findByNumber($this->context, $tenant, $number);
        if ($order === null || !($this->userOwns($request, $order) || $this->tokenMatches($request, $order))) {
            throw $this->notFound();
        }

        unset($order['guest_token_hash']);

        return $order;
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
