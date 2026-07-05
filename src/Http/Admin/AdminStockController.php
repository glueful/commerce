<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Http\Response;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminStockController
{
    use ReadsAdminInput;

    public function __construct(
        private ApplicationContext $context,
        private ?InventoryService $inventory = null,
    ) {
        $this->inventory ??= app($context, InventoryService::class);
    }

    public function adjust(Request $request, string $variantUuid): Response
    {
        try {
            $input = $this->input($request);
            $quantity = $this->inventory->adjust(
                $this->context,
                $variantUuid,
                (int) ($input['delta'] ?? 0),
                (string) ($input['reason'] ?? 'adjustment'),
                isset($input['reference_uuid']) ? (string) $input['reference_uuid'] : null
            );

            return Response::success(['variant_uuid' => $variantUuid, 'quantity' => $quantity], 'Stock adjusted');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }
}
