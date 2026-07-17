<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Inventory;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * `$variants`/`$products` (MV1 Task 4): APPENDED OPTIONAL collaborators, the
 * SAME "legacy direct construction stays source-compatible" convention
 * {@see \Glueful\Extensions\Commerce\Catalog\CatalogService}'s marketplace
 * collaborators use -- every pre-existing direct-construction call site
 * (tests included) keeps working unchanged. They back
 * {@see self::quantityForSeller()}/{@see self::adjustForSeller()} only.
 */
final class InventoryService
{
    public function __construct(
        private StockRepository $stock,
        private CurrentTenantResolver $tenants,
        private ?VariantRepository $variants = null,
        private ?ProductRepository $products = null,
    ) {
        $this->variants ??= new VariantRepository();
        $this->products ??= new ProductRepository();
    }

    /**
     * Seller-scoped stock read (design spec §2.8, MV1 Task 4): tenant AND
     * seller predicated at the SERVICE layer via
     * {@see self::requireSellerOwnedVariant()} -- a variant belonging to a
     * different seller's product is the same non-revealing 404 as an
     * unknown one.
     */
    public function quantityForSeller(ApplicationContext $context, string $sellerUuid, string $variantUuid): int
    {
        $tenant = $this->tenants->tenantUuid($context);
        $this->requireSellerOwnedVariant($context, $tenant, $sellerUuid, $variantUuid);

        return $this->stock->quantity($context, $tenant, $variantUuid);
    }

    /**
     * Seller-scoped stock adjustment (design spec §2.8, MV1 Task 4): the
     * SAME ownership check as {@see self::quantityForSeller()}, then
     * delegates to the existing {@see self::adjust()} ledger write.
     */
    public function adjustForSeller(
        ApplicationContext $context,
        string $sellerUuid,
        string $variantUuid,
        int $delta,
        string $reason = 'adjustment',
        ?string $reference = null,
    ): int {
        $tenant = $this->tenants->tenantUuid($context);
        $this->requireSellerOwnedVariant($context, $tenant, $sellerUuid, $variantUuid);

        return $this->adjust($context, $variantUuid, $delta, $reason, $reference);
    }

    /**
     * The shared seller-ownership check {@see self::quantityForSeller()} and
     * {@see self::adjustForSeller()} funnel through: resolves the variant's
     * parent product (tenant-scoped) and confirms it is LIVE and owned by
     * $sellerUuid.
     */
    private function requireSellerOwnedVariant(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $variantUuid
    ): void {
        $variant = $this->variants->findByUuid($context, $tenant, $variantUuid);
        if ($variant === null) {
            throw new NotFoundException('Resource not found.');
        }

        $product = $this->products->findLiveByUuid($context, $tenant, (string) $variant['product_uuid']);
        if ($product === null || (string) ($product['seller_uuid'] ?? '') !== $sellerUuid) {
            throw new NotFoundException('Resource not found.');
        }
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
