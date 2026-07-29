<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\WishlistImportData;
use Glueful\Extensions\Commerce\Http\DTOs\WishlistItemData;
use Glueful\Extensions\Commerce\Wishlist\WishlistService;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The authenticated user's wishlist. Actor resolution matches
 * {@see AccountAddressController}: the post-auth `user` attribute, never `auth.user` (that
 * identity is admin-audit attribution). Routes are auth-gated, but the actor check fails
 * non-revealing anyway so a directly-constructed controller cannot act as an empty user.
 */
final class AccountWishlistController
{
    public function __construct(
        private ApplicationContext $context,
        private ?WishlistService $wishlist = null,
    ) {
        $this->wishlist ??= app($context, WishlistService::class);
    }

    #[ApiOperation(summary: "List the authenticated user's wishlist", tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Wishlist retrieved')]
    public function index(Request $request): Response
    {
        return Response::success(
            $this->wishlist->list($this->context, $this->actorUuid($request)),
            'Wishlist retrieved'
        );
    }

    #[ApiOperation(summary: 'Save a product to the wishlist', tags: ['Commerce Storefront'])]
    #[ApiRequestBody(schema: WishlistItemData::class)]
    #[ApiResponse(200, description: 'Saved')]
    #[ApiResponse(422, description: 'Unavailable product, or the wishlist is full')]
    public function store(WishlistItemData $input, Request $request): Response
    {
        $added = $this->wishlist->add($this->context, $this->actorUuid($request), $input->product_uuid);

        return Response::success(
            ['product_uuid' => $input->product_uuid, 'added' => $added],
            $added ? 'Saved to wishlist' : 'Already on the wishlist'
        );
    }

    #[ApiOperation(summary: 'Remove a product from the wishlist', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Removed')]
    public function destroy(Request $request, string $productUuid): Response
    {
        $removed = $this->wishlist->remove($this->context, $this->actorUuid($request), $productUuid);

        return Response::success(
            ['product_uuid' => $productUuid, 'removed' => $removed],
            $removed ? 'Removed from wishlist' : 'Not on the wishlist'
        );
    }

    #[ApiOperation(summary: 'Merge a device-local wishlist into the account', tags: ['Commerce Storefront'])]
    #[ApiRequestBody(schema: WishlistImportData::class)]
    #[ApiResponse(200, description: 'Import result')]
    public function import(WishlistImportData $input, Request $request): Response
    {
        $result = $this->wishlist->import($this->context, $this->actorUuid($request), $input->product_uuids);

        // The caller holds the device list and decides what to clear, so it is told exactly
        // what landed, what was dropped as unavailable, and what did not fit.
        return Response::success([
            'imported' => $result->imported,
            'unavailable' => $result->unavailable,
            'overflow' => $result->overflow,
        ], 'Wishlist imported');
    }

    private function actorUuid(Request $request): string
    {
        $user = $request->attributes->get('user');
        $userUuid = is_array($user) && isset($user['uuid']) ? (string) $user['uuid'] : '';
        if ($userUuid === '') {
            throw new NotFoundException('Resource not found.');
        }

        return $userUuid;
    }
}
