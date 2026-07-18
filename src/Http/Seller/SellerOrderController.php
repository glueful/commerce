<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Seller;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\FulfillSellerOrderData;
use Glueful\Extensions\Commerce\Http\DTOs\SellerOrderListQuery;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Seller-scoped order surface (design spec §6.1/§2.12, MV2 Task 8):
 * list/show/fulfill scoped to the `{sellerUuid}` route resource --
 * `commerce_seller:commerce.seller.orders.{read,fulfill}` middleware has
 * already resolved and authorized the caller against that exact seller
 * before any handler here runs. Every read is ADDITIONALLY predicated by
 * seller + payment confirmation at the {@see SellerOrderService} layer
 * (never a raw repository call) -- a wrong-seller or still-pending-payment
 * seller order uuid is a non-revealing 404, matching the middleware's own
 * fail-closed posture (design spec §6.4).
 *
 * `fulfill()` never mutates directly: it resolves the parent order uuid
 * through the SAME confirmed-scoped read `show()`/`index()` use
 * ({@see SellerOrderService::orderUuidForFulfill()}), THEN delegates the
 * actual claim/transition/rollup to
 * {@see SellerOrderFulfillmentService::fulfill()} with
 * `$actorSellerUuid = {sellerUuid}` -- an unconfirmed partition is rejected
 * before the fulfillment service is ever called, and the fulfillment
 * service's own actor check is the second, independent layer against a
 * spoofed `{sellerUuid}` route segment for a DIFFERENT child's uuid.
 */
final class SellerOrderController
{
    public function __construct(
        private ApplicationContext $context,
        private ?SellerOrderService $sellerOrders = null,
        private ?SellerOrderFulfillmentService $fulfillment = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->sellerOrders ??= app($context, SellerOrderService::class);
        $this->fulfillment ??= app($context, SellerOrderFulfillmentService::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    #[ApiOperation(summary: "List a seller's payment-confirmed orders", tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Seller orders retrieved')]
    #[ApiResponse(404, description: 'Unknown seller, no active membership, or workspace not activated')]
    public function index(SellerOrderListQuery $query, Request $request, string $sellerUuid): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));

        $result = $this->sellerOrders->list(
            $this->context,
            $sellerUuid,
            array_filter(
                ['fulfillment_status' => $query->fulfillment_status],
                static fn (mixed $value): bool => $value !== null
            ),
            $page,
            $perPage
        );

        return Response::paginated(
            $result['items'],
            $result['total'],
            $page,
            $perPage,
            null,
            'Seller orders retrieved'
        );
    }

    #[ApiOperation(summary: "Get a payment-confirmed order owned by this seller", tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Seller order retrieved')]
    #[ApiResponse(404, description: 'Seller order not found or not yet payment-confirmed')]
    public function show(Request $request, string $sellerUuid, string $sellerOrderUuid): Response
    {
        $order = $this->sellerOrders->detail($this->context, $sellerUuid, $sellerOrderUuid);

        return Response::success($order, 'Seller order retrieved');
    }

    #[ApiOperation(summary: 'Fulfill a seller order', tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Seller order fulfilled')]
    #[ApiResponse(404, description: 'Seller order not found or not yet payment-confirmed')]
    #[ApiResponse(409, description: 'Seller order is canceled or already fulfilled')]
    public function fulfill(
        FulfillSellerOrderData $input,
        Request $request,
        string $sellerUuid,
        string $sellerOrderUuid
    ): Response {
        try {
            $orderUuid = $this->sellerOrders->orderUuidForFulfill($this->context, $sellerUuid, $sellerOrderUuid);
            $tenant = $this->tenants->tenantUuid($this->context);

            $this->fulfillment->fulfill(
                $this->context,
                $tenant,
                $orderUuid,
                $sellerOrderUuid,
                [
                    'carrier' => $input->carrier,
                    'tracking_number' => $input->tracking_number,
                    'tracking_url' => $input->tracking_url,
                ],
                $sellerUuid
            );

            $order = $this->sellerOrders->detail($this->context, $sellerUuid, $sellerOrderUuid);

            return Response::success($order, 'Seller order fulfilled');
        } catch (\DomainException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }
}
