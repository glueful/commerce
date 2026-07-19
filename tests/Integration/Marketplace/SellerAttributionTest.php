<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\SellerAttributionException;
use Glueful\Extensions\Commerce\Marketplace\SellerAttributionService;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * The dedicated operator catalog-attribution operation (design spec §2.7/§4
 * lock order) -- {@see SellerAttributionService::assign()}'s class docblock
 * documents the full claim/re-read/stale-abort protocol under test here.
 */
final class SellerAttributionTest extends CommerceTestCase
{
    private const TENANT = 'tenantATTRIB1';

    // -----------------------------------------------------------------
    // Adoption (no prior seller) + transfer (owner-to-owner)
    // -----------------------------------------------------------------

    public function testAssignAdoptsAnUnassignedProductAndReturnsItWithTheNewSeller(): void
    {
        $product = $this->seedProduct('adopt-me');
        $seller = $this->seedActiveSeller('adopting-seller');

        $updated = $this->service()->assign(
            $this->context,
            self::TENANT,
            $product['uuid'],
            $seller['uuid'],
            'actorUser01'
        );

        self::assertSame($seller['uuid'], $updated['seller_uuid']);

        $row = $this->connection->table('commerce_products')->where('uuid', '=', $product['uuid'])->first();
        self::assertSame($seller['uuid'], $row['seller_uuid']);
    }

    public function testAssignTransfersAnOwnedProductToADifferentSellerAndBumpsCatalogRevision(): void
    {
        $product = $this->seedProduct('transfer-me');
        $sellerA = $this->seedActiveSeller('seller-a');
        $sellerB = $this->seedActiveSeller('seller-b');
        $this->setProductSeller($product['uuid'], $sellerA['uuid']);
        $before = $this->productRow($product['uuid']);

        $updated = $this->service()->assign($this->context, self::TENANT, $product['uuid'], $sellerB['uuid']);

        self::assertSame($sellerB['uuid'], $updated['seller_uuid']);
        self::assertGreaterThan(
            (int) $before['catalog_revision'],
            (int) $updated['catalog_revision'],
            'a transfer must bump catalog_revision via the product claim'
        );
    }

    // -----------------------------------------------------------------
    // Stale-source 409: a competing transfer lands between snapshot and re-read
    // -----------------------------------------------------------------

    public function testStaleSourceAbortsTheTransferWith409AndLeavesTheProductExactlyAsItWas(): void
    {
        $product = $this->seedProduct('race-me');
        $sellerA = $this->seedActiveSeller('race-seller-a');
        $sellerB = $this->seedActiveSeller('race-seller-b');
        $sellerC = $this->seedActiveSeller('race-seller-c');
        $this->setProductSeller($product['uuid'], $sellerA['uuid']);
        $before = $this->productRow($product['uuid']);

        // Deterministic single-connection stand-in for a competing transfer
        // landing between the snapshot (step 2) and the re-read (step 5) --
        // see SellerAttributionService's class docblock. A genuine
        // cross-connection race is the pgsql-gated lane (Task 5); this hook
        // is the injectable seam that makes the same window observable on a
        // single SQLite connection.
        $service = $this->service(function (ApplicationContext $c, string $tenant, string $productUuid) use ($sellerB): void {
            $this->connection->table('commerce_products')
                ->where('uuid', '=', $productUuid)
                ->update(['seller_uuid' => $sellerB['uuid']]);
        });

        try {
            $service->assign($this->context, self::TENANT, $product['uuid'], $sellerC['uuid']);
            self::fail('expected a stale-ownership SellerAttributionException');
        } catch (SellerAttributionException $e) {
            $this->addToAssertionCount(1);
        }

        $after = $this->productRow($product['uuid']);
        self::assertSame(
            $before['seller_uuid'],
            $after['seller_uuid'],
            'the aborted transfer must leave the product exactly as it was before the call -- the whole '
                . "transaction (including the hook's simulated competing write) rolls back atomically"
        );
        self::assertSame(
            (int) $before['catalog_revision'],
            (int) $after['catalog_revision'],
            'no catalog_revision claim may survive an aborted stale-ownership transfer'
        );
    }

    // -----------------------------------------------------------------
    // Target seller validation
    // -----------------------------------------------------------------

    public function testAssignRejectsAnUnknownTargetSellerWith422(): void
    {
        $product = $this->seedProduct('unknown-target');

        $this->expectException(ValidationException::class);
        $this->service()->assign($this->context, self::TENANT, $product['uuid'], 'doesNotExist1');
    }

    public function testAssignRejectsACrossTenantTargetSellerWith422(): void
    {
        $product = $this->seedProduct('cross-tenant-target');
        $foreignSeller = $this->seedActiveSeller('foreign-seller', 'tenantFOREIGN2');

        $this->expectException(ValidationException::class);
        $this->service()->assign($this->context, self::TENANT, $product['uuid'], $foreignSeller['uuid']);
    }

    public function testAssignRejectsASuspendedTargetSellerWith409(): void
    {
        $product = $this->seedProduct('suspended-target');
        $seller = $this->seedActiveSeller('suspended-target-seller');
        $this->sellerService()->suspend($this->context, self::TENANT, $seller['uuid'], 'Under review.', 'operator01');

        $this->expectException(SellerAttributionException::class);
        $this->service()->assign($this->context, self::TENANT, $product['uuid'], $seller['uuid']);
    }

    public function testAssignOnAnUnknownProductIs404(): void
    {
        $seller = $this->seedActiveSeller('unknown-product-seller');

        $this->expectException(NotFoundException::class);
        $this->service()->assign($this->context, self::TENANT, 'doesNotExist2', $seller['uuid']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function service(?callable $afterSnapshotHook = null): SellerAttributionService
    {
        return new SellerAttributionService(
            new MarketplaceWorkspaceLock(),
            new SellerRepository(),
            new ProductRepository(),
            $afterSnapshotHook
        );
    }

    private function sellerService(): SellerService
    {
        return new SellerService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            new SellerLifecycleEventRepository()
        );
    }

    /** @return array<string,mixed> */
    private function seedActiveSeller(string $slug, string $tenant = self::TENANT): array
    {
        return $this->sellerService()->create(
            $this->context,
            $tenant,
            $slug,
            ucfirst(str_replace('-', ' ', $slug)),
            null,
            'ownerUser' . substr(md5($slug), 0, 3)
        );
    }

    /** @return array<string,mixed> a live, unassigned product */
    private function seedProduct(string $slug): array
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant(),
            new StockRepository(),
            new ProductChildrenRepository()
        );

        return $catalog->createProduct($this->context, [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '', $slug)),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
    }

    private function fixedTenant(): CurrentTenantResolver
    {
        return new class (self::TENANT) implements CurrentTenantResolver {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
    }

    private function setProductSeller(string $productUuid, string $sellerUuid): void
    {
        $this->connection->table('commerce_products')
            ->where('uuid', '=', $productUuid)
            ->update(['seller_uuid' => $sellerUuid]);
    }

    /** @return array<string,mixed> */
    private function productRow(string $productUuid): array
    {
        $row = $this->connection->table('commerce_products')->where('uuid', '=', $productUuid)->first();
        self::assertNotNull($row);

        return $row;
    }
}
