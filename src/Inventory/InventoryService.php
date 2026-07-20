<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Inventory;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookOutboxPublisher;
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
 *
 * `$sellers`/`$webhooks` (MV5c-2 Task 4, design spec §2.3/§2.4): the SAME
 * convention, backing {@see self::adjust()}'s own `stock.adjusted` capture --
 * the ONLY real insertion point for this event type (design spec §2.3:
 * checkout decrements and refund/cancel/expiry restocks -- none of which
 * ever call this class at all, they call {@see StockRepository} directly --
 * never emit it). `adjust()` never claims a `LedgerAccountLock` (stock
 * adjustment posts no ledger entries), so there is no lock-order constraint
 * on where the capture call lands inside its transaction.
 */
final class InventoryService
{
    public function __construct(
        private StockRepository $stock,
        private CurrentTenantResolver $tenants,
        private ?VariantRepository $variants = null,
        private ?ProductRepository $products = null,
        private ?SellerRepository $sellers = null,
        private ?SellerWebhookOutboxPublisher $webhooks = null,
        private ?MarketplaceMode $marketplaceMode = null,
    ) {
        $this->variants ??= new VariantRepository();
        $this->products ??= new ProductRepository();
        $this->sellers ??= new SellerRepository();
        $this->marketplaceMode ??= new MarketplaceMode();
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

            $quantityAfter = $this->stock->quantity($context, $tenant, $variantUuid);

            $this->captureStockAdjusted($context, $tenant, $variantUuid, $delta, $quantityAfter, $reason, $reference);

            return $quantityAfter;
        });
    }

    /**
     * `stock.adjusted` outbox capture (MV5c-2 Task 4, design spec §2.3/§2.4):
     * the direct operator/seller adjustment ONLY -- this is the only call
     * site in this class (and, by construction, the only real place in this
     * codebase this event type is ever emitted from: every checkout
     * decrement and every refund/cancel/expiry restock moves stock through
     * {@see StockRepository} directly, never through this method). Resolves
     * the owning seller INSIDE this transaction (never claimed/known by a
     * caller here -- {@see self::adjustForSeller()}'s own ownership check
     * runs entirely BEFORE this transaction opens); a non-marketplace/
     * unattributed variant (no owning seller) silently emits nothing.
     */
    private function captureStockAdjusted(
        ApplicationContext $context,
        string $tenant,
        string $variantUuid,
        int $delta,
        int $quantityAfter,
        string $reason,
        ?string $reference
    ): void {
        if ($this->webhooks === null) {
            return;
        }

        // Off-invariance (design spec §6): marketplace master-off is a
        // config-only, zero-database-query no-op -- checked BEFORE the
        // variant/product SELECTs this method runs purely to build the
        // payload, so a non-marketplace install pays nothing per adjust().
        // (The publisher's own capture() re-checks this, but only after we
        // would have already issued those two reads -- hence the guard here.)
        if (!$this->marketplaceMode->installEnabled($context)) {
            return;
        }

        $variant = $this->variants->findByUuid($context, $tenant, $variantUuid);
        if ($variant === null) {
            return;
        }

        $product = $this->products->findLiveByUuid($context, $tenant, (string) $variant['product_uuid']);
        $sellerUuid = $product !== null ? ($product['seller_uuid'] ?? null) : null;
        if ($sellerUuid === null || $sellerUuid === '') {
            return;
        }
        $sellerUuid = (string) $sellerUuid;

        $this->sellers->claimRevision($context, $tenant, $sellerUuid);

        $this->webhooks->capture($context, $tenant, 'stock.adjusted', [
            'data' => [
                $sellerUuid => [
                    'product_uuid' => (string) $variant['product_uuid'],
                    'variant_uuid' => $variantUuid,
                    'sku' => (string) ($variant['sku'] ?? ''),
                    'delta' => $delta,
                    'quantity_after' => $quantityAfter,
                    'reason' => $reason,
                    'reference' => $reference,
                    'occurred_at' => db($context)->getDriver()->formatDateTime(),
                ],
            ],
            'claimed_sellers' => [$sellerUuid],
            'source_ref' => $variantUuid,
        ]);
    }
}
