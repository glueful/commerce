<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Events\SellerProductAdopted;
use Glueful\Extensions\Commerce\Events\SellerProductTransferred;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * The dedicated guarded catalog-attribution operator operation (design spec
 * §2.7/§4 lock order): assigns an unowned product to a seller (adoption,
 * when the product's current `seller_uuid` is null) or moves an owned
 * product to a different seller (transfer, when it is not). This is the
 * repair surface design spec §2.3 promises for configuring a workspace
 * while inactive, and the ONLY path (besides
 * {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::createProduct()}'s
 * own create-time attribution) that may ever write `commerce_products.seller_uuid`.
 *
 * {@see self::assign()}'s transaction follows the design spec §4 GLOBAL LOCK
 * ORDER verbatim:
 *
 * 1. Claim {@see MarketplaceWorkspaceLock} (workspace-settings claim first).
 * 2. Plain-read the product and snapshot its CURRENT `seller_uuid` (the
 *    source) -- never trusted for a decision, only for computing which
 *    sellers to claim.
 * 3. Claim the SORTED {snapshot-source, target} seller set (skipping a null
 *    source -- adoption has no source seller to claim).
 * 4. Claim the product's `catalog_revision`.
 * 5. RE-READ the product. If its CURRENT seller no longer matches the
 *    snapshot from step 2, a competing adoption/transfer won the race --
 *    abort with a 409 {@see SellerAttributionException} ("stale
 *    ownership"). Critically, this NEVER extends the seller lock set to
 *    claim whatever the new source turned out to be -- the claim set was
 *    fixed in step 3, before the product was ever locked; extending it here
 *    would invert the lock order (design spec §4: "No flow may invert this
 *    order").
 * 6. Validate the target seller (already claimed in step 3): exists and
 *    in-tenant (422 {@see ValidationException} otherwise) and `active` (409
 *    {@see SellerAttributionException} otherwise).
 * 7. Write `seller_uuid` on the claimed product (the step-4 claim already
 *    bumped `catalog_revision` exactly once).
 *
 * The matching {@see SellerProductAdopted}/{@see SellerProductTransferred}
 * audit event is dispatched AFTER commit, mirroring
 * {@see \Glueful\Extensions\Commerce\Orders\CheckoutService}'s
 * dispatch-after-transaction convention.
 */
final class SellerAttributionService
{
    /** @var callable(ApplicationContext,string,string):void */
    private $afterSnapshotHook;

    /**
     * @param (callable(ApplicationContext,string,string):void)|null $afterSnapshotHook
     *     Injectable seam (same convention as {@see MarketplaceWorkspaceLock}'s
     *     `$uuidGenerator` / {@see SellerService}'s `$uuidGenerator`):
     *     invoked with (context, tenant, productUuid) immediately after the
     *     step-2 snapshot read, before the seller claim set is locked. Tests
     *     use it to deterministically simulate a competing adoption/transfer
     *     landing between the snapshot and the step-5 re-read (a genuine
     *     concurrent race is only observable across real DB connections --
     *     see the pgsql-gated lane -- this hook is the single-connection
     *     deterministic stand-in). Defaults to a no-op.
     */
    public function __construct(
        private MarketplaceWorkspaceLock $workspaceLock,
        private SellerRepository $sellers,
        private ProductRepository $products,
        ?callable $afterSnapshotHook = null,
        private ?SellerWebhookOutboxPublisher $webhooks = null,
    ) {
        $this->afterSnapshotHook = $afterSnapshotHook ?? static function (
            ApplicationContext $context,
            string $tenant,
            string $productUuid
        ): void {
        };
    }

    /** @return array<string,mixed> the updated product row */
    public function assign(
        ApplicationContext $c,
        string $tenant,
        string $productUuid,
        string $targetSellerUuid,
        ?string $actor = null
    ): array {
        $outcome = db($c)->transaction(function () use ($c, $tenant, $productUuid, $targetSellerUuid): array {
            $this->workspaceLock->claim($c, $tenant);

            $snapshot = $this->products->findLiveByUuid($c, $tenant, $productUuid);
            if ($snapshot === null) {
                throw new NotFoundException('Resource not found.');
            }
            $snapshotSource = $snapshot['seller_uuid'] ?? null;

            ($this->afterSnapshotHook)($c, $tenant, $productUuid);

            $claimSet = array_values(array_unique(array_filter(
                [$snapshotSource, $targetSellerUuid],
                static fn (mixed $uuid): bool => $uuid !== null
            )));
            sort($claimSet);

            foreach ($claimSet as $sellerUuid) {
                $this->sellers->claimRevision($c, $tenant, (string) $sellerUuid);
            }

            if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->products->findLiveByUuid($c, $tenant, $productUuid);
            if ($current === null) {
                throw new NotFoundException('Resource not found.');
            }

            $currentSource = $current['seller_uuid'] ?? null;
            if ($currentSource !== $snapshotSource) {
                throw new SellerAttributionException(
                    "Stale ownership: this product's seller changed since it was read; retry."
                );
            }

            $target = $this->sellers->findByUuid($c, $tenant, $targetSellerUuid);
            if ($target === null) {
                throw ValidationException::forField(
                    'seller_uuid',
                    'seller_uuid must reference an existing seller in this tenant.'
                );
            }
            if ((string) $target['status'] !== 'active') {
                throw new SellerAttributionException(
                    "Seller is '{$target['status']}'; products cannot be attributed to it."
                );
            }

            $this->products->update($c, $tenant, $productUuid, ['seller_uuid' => $targetSellerUuid]);

            $updated = $this->products->findLiveByUuid($c, $tenant, $productUuid);
            if ($updated === null) {
                throw new \RuntimeException('Updated product could not be reloaded.');
            }

            if ($this->webhooks !== null) {
                $this->captureAttribution($c, $tenant, $productUuid, $snapshotSource, $targetSellerUuid, $claimSet);
            }

            return ['product' => $updated, 'source' => $snapshotSource];
        });

        /** @var array<string,mixed> $product */
        $product = $outcome['product'];
        $source = $outcome['source'];

        $event = $source === null
            ? new SellerProductAdopted([
                'tenant_uuid' => $tenant,
                'product_uuid' => (string) $product['uuid'],
                'seller_uuid' => $targetSellerUuid,
                'actor' => $actor,
            ])
            : new SellerProductTransferred([
                'tenant_uuid' => $tenant,
                'product_uuid' => (string) $product['uuid'],
                'from_seller_uuid' => (string) $source,
                'to_seller_uuid' => $targetSellerUuid,
                'actor' => $actor,
            ]);
        $this->dispatch($c, $event);

        return $product;
    }

    /**
     * `product.adopted`/`product.transferred` outbox capture (MV5c-2 Task 4,
     * design spec §2.3/§2.4), still inside `assign()`'s own transaction. A
     * null `$sourceSellerUuid` (adoption -- the product had no owner)
     * captures `product.adopted` for the target seller only; a non-null
     * source captures `product.transferred` for BOTH participating sellers
     * as DISTINCT snapshots (`direction = 'out'`/`'in'`) -- see
     * {@see SellerWebhookPayloadProjector::productTransferred()} for why
     * `counterparty_seller_uuid` is the only cross-seller reference either
     * snapshot ever carries. `$claimedSellers` is `$claimSet` from THIS
     * same transaction (already sorted, already claimed above, before the
     * product's own catalog-revision claim) -- reused, never re-claimed.
     *
     * @param list<string> $claimedSellers
     */
    private function captureAttribution(
        ApplicationContext $c,
        string $tenant,
        string $productUuid,
        ?string $sourceSellerUuid,
        string $targetSellerUuid,
        array $claimedSellers
    ): void {
        $now = db($c)->getDriver()->formatDateTime();

        if ($sourceSellerUuid === null) {
            $this->webhooks->capture($c, $tenant, 'product.adopted', [
                'data' => [
                    $targetSellerUuid => [
                        'product_uuid' => $productUuid,
                        'occurred_at' => $now,
                    ],
                ],
                'claimed_sellers' => $claimedSellers,
                'source_ref' => $productUuid,
            ]);

            return;
        }

        $this->webhooks->capture($c, $tenant, 'product.transferred', [
            'data' => [
                $sourceSellerUuid => [
                    'direction' => 'out',
                    'product_uuid' => $productUuid,
                    'counterparty_seller_uuid' => $targetSellerUuid,
                    'occurred_at' => $now,
                ],
                $targetSellerUuid => [
                    'direction' => 'in',
                    'product_uuid' => $productUuid,
                    'counterparty_seller_uuid' => $sourceSellerUuid,
                    'occurred_at' => $now,
                ],
            ],
            'claimed_sellers' => $claimedSellers,
            'source_ref' => $productUuid,
        ]);
    }

    private function dispatch(ApplicationContext $context, object $event): void
    {
        $container = container($context);
        if ($container->has(EventService::class)) {
            $container->get(EventService::class)->dispatch($event);
        }
    }
}
