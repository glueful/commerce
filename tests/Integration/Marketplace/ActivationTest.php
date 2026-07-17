<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceActivationException;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceActivationService;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\SellerAttributionException;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Validation\ValidationException;

/**
 * Per-workspace activation (design spec §2.2/§2.3) --
 * {@see MarketplaceActivationService}'s class docblock documents the full
 * claim/adoption-gate/settings-flip protocol under test here.
 */
final class ActivationTest extends CommerceTestCase
{
    private const TENANT = 'tenantACTIVAT1';

    public function testActivateFailsWith409AndTheExactUnassignedCountWhenLiveProductsLackASeller(): void
    {
        $this->seedProduct('prodUNASGN01');
        $this->seedProduct('prodUNASGN02');

        try {
            $this->service()->activate($this->context, self::TENANT);
            self::fail('expected a MarketplaceActivationException');
        } catch (MarketplaceActivationException $e) {
            self::assertSame(2, $e->unassignedCount);
        }

        // The whole activate() call is ONE transaction (design spec §2.2): a
        // blocked activation rolls back EVERYTHING it did, including the
        // workspace lock's own idempotent settings-row creation -- so no row
        // may exist at all yet, and if a later successful call creates one,
        // it must never be `active`.
        $settings = $this->connection->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', self::TENANT)
            ->first();
        self::assertNotSame('active', $settings['status'] ?? 'disabled', 'a blocked activation must not go active');
    }

    public function testActivateSucceedsWhenEveryLiveProductAlreadyHasASeller(): void
    {
        $seller = $this->seedActiveSeller('pre-owned-seller');
        $this->seedProduct('prodOWNED0001', $seller['uuid']);

        $settings = $this->service()->activate($this->context, self::TENANT);

        self::assertSame('active', $settings['status']);
    }

    public function testActivateIgnoresASoftDeletedUnassignedProduct(): void
    {
        $this->seedProduct('prodDELETED1', null, deleted: true);

        $settings = $this->service()->activate($this->context, self::TENANT);

        self::assertSame('active', $settings['status']);
    }

    public function testActivateWithDefaultSellerBulkAdoptsAllUnassignedProductsAndBumpsEachRevision(): void
    {
        $seller = $this->seedActiveSeller('default-seller');
        $this->seedProduct('prodBULK0001');
        $this->seedProduct('prodBULK0002');
        $before1 = $this->productRow('prodBULK0001');
        $before2 = $this->productRow('prodBULK0002');

        $settings = $this->service()->activate($this->context, self::TENANT, $seller['uuid'], 'actorUser01');

        self::assertSame('active', $settings['status']);
        self::assertSame($seller['uuid'], $settings['default_seller_uuid']);
        self::assertSame('actorUser01', $settings['activated_by']);
        self::assertNotNull($settings['activated_at']);

        $after1 = $this->productRow('prodBULK0001');
        $after2 = $this->productRow('prodBULK0002');
        self::assertSame($seller['uuid'], $after1['seller_uuid']);
        self::assertSame($seller['uuid'], $after2['seller_uuid']);
        self::assertGreaterThan(
            (int) $before1['catalog_revision'],
            (int) $after1['catalog_revision'],
            'bulk adoption must bump EACH product revision'
        );
        self::assertGreaterThan((int) $before2['catalog_revision'], (int) $after2['catalog_revision']);
    }

    public function testReactivationReRunsTheAdoptionGateAgainstProductsCreatedWhileInactive(): void
    {
        $seller = $this->seedActiveSeller('re-activation-seller');
        $this->seedProduct('prodREACT001', $seller['uuid']);

        $settings = $this->service()->activate($this->context, self::TENANT);
        self::assertSame('active', $settings['status']);

        $this->service()->deactivate($this->context, self::TENANT);

        // A product created while the workspace was inactive -- unassigned.
        $this->seedProduct('prodREACT002');

        try {
            $this->service()->activate($this->context, self::TENANT);
            self::fail('re-activation must re-run the adoption gate');
        } catch (MarketplaceActivationException $e) {
            self::assertSame(1, $e->unassignedCount);
        }
    }

    public function testDeactivateIsNonDestructiveSellerMembershipAndAttributionDataUntouched(): void
    {
        $seller = $this->seedActiveSeller('deactivate-seller');
        $this->seedProduct('prodDEACT001', $seller['uuid']);
        $this->service()->activate($this->context, self::TENANT);

        $settings = $this->service()->deactivate($this->context, self::TENANT, 'actorUser02');

        self::assertSame('disabled', $settings['status']);

        $sellerRow = $this->connection->table('commerce_sellers')->where('uuid', '=', $seller['uuid'])->first();
        self::assertSame('active', $sellerRow['status'], 'sellers must be untouched by deactivation');

        $membership = $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', $seller['uuid'])
            ->first();
        self::assertSame('active', $membership['status'], 'memberships must be untouched by deactivation');

        $product = $this->productRow('prodDEACT001');
        self::assertSame($seller['uuid'], $product['seller_uuid'], 'seller_uuid must be untouched by deactivation');
    }

    public function testActivateWithUnknownDefaultSellerIs422(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->activate($this->context, self::TENANT, 'doesNotExist1');
    }

    public function testActivateWithSuspendedDefaultSellerIs409(): void
    {
        $seller = $this->seedActiveSeller('suspended-default');
        $this->sellerService()->suspend($this->context, self::TENANT, $seller['uuid']);

        $this->expectException(SellerAttributionException::class);
        $this->service()->activate($this->context, self::TENANT, $seller['uuid']);
    }

    public function testSentinelWorkspaceActivationWithNoUnassignedProductsSucceeds(): void
    {
        $settings = $this->service()->activate($this->context, '');

        self::assertSame('active', $settings['status']);
        self::assertSame('', $settings['tenant_uuid']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function service(): MarketplaceActivationService
    {
        return new MarketplaceActivationService(
            new MarketplaceWorkspaceLock(),
            new SellerRepository(),
            new ProductRepository()
        );
    }

    private function sellerService(): SellerService
    {
        return new SellerService(new SellerRepository(), new SellerMembershipRepository());
    }

    /** @return array<string,mixed> */
    private function seedActiveSeller(string $slug): array
    {
        return $this->sellerService()->create(
            $this->context,
            self::TENANT,
            $slug,
            ucfirst(str_replace('-', ' ', $slug)),
            null,
            'ownerUser' . substr(md5($slug), 0, 3)
        );
    }

    private function seedProduct(string $uuid, ?string $sellerUuid = null, bool $deleted = false): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'slug' => $uuid,
            'name' => $uuid,
            'type' => 'physical',
            'status' => 'active',
            'seller_uuid' => $sellerUuid,
            'deleted_at' => $deleted ? '2026-01-01 00:00:00' : null,
        ]);
    }

    /** @return array<string,mixed> */
    private function productRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_products')->where('uuid', '=', $uuid)->first();
        self::assertNotNull($row);

        return $row;
    }
}
