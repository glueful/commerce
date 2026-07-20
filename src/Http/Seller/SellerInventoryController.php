<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Seller;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\StockAdjustmentData;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Seller-scoped inventory surface (design spec §2.8, MV1 Task 4): read +
 * adjust a variant's stock, scoped to the `{sellerUuid}` route resource via
 * `commerce_seller:commerce.seller.inventory.{read,write}` middleware plus
 * an ADDITIONAL tenant+seller ownership check at the {@see InventoryService}
 * layer (never a raw repository call) -- a variant belonging to a different
 * seller's product is a non-revealing 404.
 */
final class SellerInventoryController
{
    public function __construct(
        private ApplicationContext $context,
        private ?InventoryService $inventory = null,
    ) {
        $this->inventory ??= app($context, InventoryService::class);
    }

    #[ApiOperation(summary: "Get a variant's stock for this seller", tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Stock retrieved')]
    #[ApiResponse(404, description: 'Variant not found for this seller')]
    public function show(Request $request, string $sellerUuid, string $variantUuid): Response
    {
        $quantity = $this->inventory->quantityForSeller($this->context, $sellerUuid, $variantUuid);

        return Response::success(['variant_uuid' => $variantUuid, 'quantity' => $quantity], 'Stock retrieved');
    }

    #[ApiOperation(summary: "Adjust a variant's stock for this seller", tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Stock adjusted')]
    #[ApiResponse(404, description: 'Variant not found for this seller')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function adjust(
        StockAdjustmentData $input,
        Request $request,
        string $sellerUuid,
        string $variantUuid
    ): Response {
        try {
            $quantity = $this->inventory->adjustForSeller(
                $this->context,
                $sellerUuid,
                $variantUuid,
                $input->delta,
                $input->reason,
                $input->reference_uuid
            );

            return Response::success(['variant_uuid' => $variantUuid, 'quantity' => $quantity], 'Stock adjusted');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }
}
