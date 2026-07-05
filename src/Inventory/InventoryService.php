<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Inventory;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Validation\ValidationException;

final class InventoryService
{
    public function __construct(
        private StockRepository $stock,
        private CurrentTenantResolver $tenants,
    ) {
    }

    public function adjust(
        ApplicationContext $context,
        string $variantUuid,
        int $delta,
        string $reason = 'adjustment',
        ?string $reference = null,
    ): int {
        $tenant = $this->tenants->tenantUuid($context);

        return (int) db($context)->transaction(function () use (
            $context,
            $tenant,
            $variantUuid,
            $delta,
            $reason,
            $reference
        ): int {
            if ($delta < 0) {
                $ok = $this->stock->decrement($context, $tenant, $variantUuid, abs($delta));
                if (!$ok) {
                    throw ValidationException::forField('quantity', 'Stock cannot go below zero.');
                }
            } elseif ($delta > 0) {
                $this->stock->increment($context, $tenant, $variantUuid, $delta);
            }

            $this->stock->recordMovement($context, $tenant, $variantUuid, $delta, $reason, $reference);

            return $this->stock->quantity($context, $tenant, $variantUuid);
        });
    }
}
