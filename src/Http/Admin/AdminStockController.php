<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\StockAdjustmentData;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminStockController
{
    public function __construct(
        private ApplicationContext $context,
        private ?InventoryService $inventory = null,
    ) {
        $this->inventory ??= app($context, InventoryService::class);
    }

    #[ApiOperation(summary: 'Adjust variant stock', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Stock adjusted')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function adjust(StockAdjustmentData $input, Request $request, string $variantUuid): Response
    {
        try {
            $quantity = $this->inventory->adjust(
                $this->context,
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
