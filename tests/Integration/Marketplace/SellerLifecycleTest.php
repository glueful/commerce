<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleException;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Routing\Router;
use Glueful\Validation\ValidationException;

/**
 * Seller identity + lifecycle (design spec §2.4, §2.6): create-with-owner
 * atomicity, slug immutability, the suspend/reactivate/close status matrix
 * (close blocked by a live product, close atomically retiring the final
 * owner), and the operator-foundation-usable-while-inactive/route-manifest
 * proofs from the Task 2 TDD scope.
 */
final class SellerLifecycleTest extends CommerceTestCase
{
    private const TENANT = 'tenantSELLER1';

    private function service(?callable $uuidGenerator = null): SellerService
    {
        return new SellerService(new SellerRepository(), new SellerMembershipRepository(), $uuidGenerator);
    }

    // -----------------------------------------------------------------
    // Create: atomicity + foundation-usable-while-inactive
    // -----------------------------------------------------------------

    public function testCreateWritesSellerAndFirstOwnerMembershipAtomically(): void
    {
        $seller = $this->service()->create(
            $this->context,
            self::TENANT,
            'acme-goods',
            'Acme Goods',
            ['legal_name' => 'Acme Goods LLC'],
            'ownerUser01',
            'actorUser01'
        );

        self::assertSame('acme-goods', $seller['slug']);
        self::assertSame('Acme Goods', $seller['name']);
        self::assertSame('active', $seller['status']);
        self::assertSame(['legal_name' => 'Acme Goods LLC'], $seller['metadata']);

        $membership = $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', $seller['uuid'])
            ->where('user_uuid', '=', 'ownerUser01')
            ->first();

        self::assertNotNull($membership);
        self::assertSame('seller_owner', $membership['role']);
        self::assertSame('active', $membership['status']);
        self::assertSame('actorUser01', $membership['created_by']);
    }

    public function testCreateIsUsableWhileNoMarketplaceSettingsRowExistsForTheTenant(): void
    {
        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_settings')
                ->where('tenant_uuid', '=', self::TENANT)
                ->count(),
            'precondition: zero settings rows for this tenant -- the workspace has never been touched'
        );

        $seller = $this->service()->create(
            $this->context,
            self::TENANT,
            'inactive-workspace-seller',
            'Inactive Workspace Seller',
            null,
            'ownerUser02'
        );

        self::assertSame('active', $seller['status']);
        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_settings')->count(),
            'seller creation never touches the marketplace settings table'
        );
    }

    public function testCreateFailureInsertingTheFirstOwnerMembershipRollsBackBothRowsInsertingNeither(): void
    {
        // Pre-seed a membership row that owns the EXACT uuid the fixed generator
        // below will hand to the membership insert -- a genuine unique-constraint
        // PDOException on `commerce_seller_memberships.uuid`, forcing the SAME
        // transaction's seller insert (which committed moments earlier in the
        // SAME closure) to roll back too.
        $this->connection->table('commerce_seller_memberships')->insert([
            'uuid' => 'collideduuid1',
            'tenant_uuid' => 'tenantOTHERX2',
            'seller_uuid' => 'otherSeller1',
            'user_uuid' => 'otherUser001',
            'role' => 'seller_owner',
            'status' => 'active',
        ]);

        $service = $this->service(static fn (): string => 'collideduuid1');

        try {
            $service->create(
                $this->context,
                self::TENANT,
                'atomic-fail-seller',
                'Atomic Fail Seller',
                null,
                'ownerUser03'
            );
            self::fail('expected the forced membership-insert collision to propagate');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(
            0,
            $this->connection->table('commerce_sellers')
                ->where('tenant_uuid', '=', self::TENANT)
                ->where('slug', '=', 'atomic-fail-seller')
                ->count(),
            'the seller row committed earlier in the transaction must be rolled back too'
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_memberships')
                ->where('user_uuid', '=', 'ownerUser03')
                ->count(),
            'no membership row for the intended owner was ever left behind'
        );
        $this->expectException(NotFoundException::class);
        $this->service()->show($this->context, self::TENANT, 'collideduuid1');
    }

    public function testCreateRejectsADuplicateSlugInTheSameTenant(): void
    {
        $this->service()->create($this->context, self::TENANT, 'dup-slug', 'First', null, 'ownerUser05');

        $this->expectException(ValidationException::class);
        $this->service()->create($this->context, self::TENANT, 'dup-slug', 'Second', null, 'ownerUser06');
    }

    // -----------------------------------------------------------------
    // Update: slug immutability
    // -----------------------------------------------------------------

    public function testUpdatePayloadContainingSlugIsRejectedWith422(): void
    {
        $service = $this->service();
        $seller = $service->create($this->context, self::TENANT, 'immutable-slug', 'Immutable', null, 'ownerUser07');

        $this->expectException(ValidationException::class);
        $service->update($this->context, self::TENANT, $seller['uuid'], ['slug' => 'new-slug']);
    }

    public function testUpdateChangesNameAndMetadataWithoutTouchingSlug(): void
    {
        $service = $this->service();
        $seller = $service->create($this->context, self::TENANT, 'updatable-seller', 'Old Name', null, 'ownerUser08');

        $updated = $service->update($this->context, self::TENANT, $seller['uuid'], [
            'name' => 'New Name',
            'metadata' => ['tier' => 'gold'],
        ]);

        self::assertSame('updatable-seller', $updated['slug']);
        self::assertSame('New Name', $updated['name']);
        self::assertSame(['tier' => 'gold'], $updated['metadata']);
    }

    // -----------------------------------------------------------------
    // Lifecycle: suspend / reactivate / close
    // -----------------------------------------------------------------

    public function testSuspendTransitionsAnActiveSellerToSuspended(): void
    {
        $service = $this->service();
        $seller = $service->create($this->context, self::TENANT, 'suspend-me', 'Suspend Me', null, 'ownerUser09');

        $suspended = $service->suspend($this->context, self::TENANT, $seller['uuid']);

        self::assertSame('suspended', $suspended['status']);
    }

    public function testSuspendingAnAlreadySuspendedSellerIs409(): void
    {
        $service = $this->service();
        $seller = $service->create($this->context, self::TENANT, 'double-suspend', 'Double', null, 'ownerUser10');
        $service->suspend($this->context, self::TENANT, $seller['uuid']);

        $this->expectException(SellerLifecycleException::class);
        $service->suspend($this->context, self::TENANT, $seller['uuid']);
    }

    public function testReactivateTransitionsASuspendedSellerBackToActive(): void
    {
        $service = $this->service();
        $seller = $service->create($this->context, self::TENANT, 'reactivate-me', 'Reactivate', null, 'ownerUser11');
        $service->suspend($this->context, self::TENANT, $seller['uuid']);

        $reactivated = $service->reactivate($this->context, self::TENANT, $seller['uuid']);

        self::assertSame('active', $reactivated['status']);
    }

    public function testReactivatingAnAlreadyActiveSellerIs409(): void
    {
        $service = $this->service();
        $seller = $service->create($this->context, self::TENANT, 'already-active', 'Active', null, 'ownerUser12');

        $this->expectException(SellerLifecycleException::class);
        $service->reactivate($this->context, self::TENANT, $seller['uuid']);
    }

    public function testCloseIsBlockedWith409WhileTheSellerOwnsALiveProduct(): void
    {
        $service = $this->service();
        $seller = $service->create($this->context, self::TENANT, 'has-products', 'Has Products', null, 'ownerUser13');

        // Attribution wiring is the REAL seller-scoped catalog write path
        // (Task 3, design spec §2.2/§2.7): master switch on, workspace
        // active, CatalogService::createProduct() claims + validates the
        // seller and attributes the product inside the SAME transaction as
        // the insert -- not a direct DB seed standing in for it.
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $this->marketplaceCatalog()->createProduct(
            $this->context,
            $this->productInput('seeded-product'),
            $seller['uuid']
        );

        $this->expectException(SellerLifecycleException::class);
        $service->close($this->context, self::TENANT, $seller['uuid']);
    }

    public function testCloseIgnoresASoftDeletedProductAndSucceeds(): void
    {
        $service = $this->service();
        $seller = $service->create($this->context, self::TENANT, 'deleted-product', 'Deleted Product', null, 'own14');

        $this->connection->table('commerce_products')->insert([
            'uuid' => 'productSEED02',
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => $seller['uuid'],
            'slug' => 'deleted-seeded-product',
            'name' => 'Deleted Seeded Product',
            'type' => 'physical',
            'status' => 'draft',
            'deleted_at' => '2026-01-01 00:00:00',
        ]);

        $closed = $service->close($this->context, self::TENANT, $seller['uuid']);

        self::assertSame('closed', $closed['status']);
    }

    public function testCloseAtomicallyClosesTheSellerAndDeactivatesAllOfItsMembershipsRetiringTheFinalOwner(): void
    {
        $service = $this->service();
        $seller = $service->create($this->context, self::TENANT, 'close-me', 'Close Me', null, 'ownerUser15');

        $closed = $service->close($this->context, self::TENANT, $seller['uuid']);

        self::assertSame('closed', $closed['status']);

        $membership = $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', $seller['uuid'])
            ->where('user_uuid', '=', 'ownerUser15')
            ->first();

        self::assertNotNull($membership);
        self::assertSame(
            'revoked',
            $membership['status'],
            'close is the ONLY transition allowed to retire the seller\'s final owner'
        );
    }

    public function testClosingAnAlreadyClosedSellerIs409(): void
    {
        $service = $this->service();
        $seller = $service->create($this->context, self::TENANT, 'double-close', 'Double Close', null, 'ownerUser16');
        $service->close($this->context, self::TENANT, $seller['uuid']);

        $this->expectException(SellerLifecycleException::class);
        $service->close($this->context, self::TENANT, $seller['uuid']);
    }

    public function testUnknownSellerOnAnyLifecycleMutationIsNotFound(): void
    {
        $service = $this->service();

        $this->expectException(NotFoundException::class);
        $service->suspend($this->context, self::TENANT, 'doesNotExist');
    }

    public function testCrossTenantSellerIsNotFound(): void
    {
        $service = $this->service();
        $seller = $service->create($this->context, self::TENANT, 'tenant-scoped', 'Tenant Scoped', null, 'own17');

        $this->expectException(NotFoundException::class);
        $service->suspend($this->context, 'someOtherTenant', $seller['uuid']);
    }

    // -----------------------------------------------------------------
    // Routes: registered ONLY when the install master switch is on
    // -----------------------------------------------------------------

    public function testMarketplaceSellerRoutesAreAbsentWhenTheInstallSwitchIsOff(): void
    {
        $router = $this->freshRouter();

        self::assertSame([], $this->marketplaceAdminPaths($router));
    }

    public function testMarketplaceSellerRoutesRegisterWhenTheInstallSwitchIsOn(): void
    {
        $this->context->overrideConfig('commerce.marketplace.enabled', true);
        $router = $this->freshRouter();

        $paths = $this->marketplaceAdminPaths($router);

        self::assertContains('/commerce/admin/marketplace/sellers', $paths);
        self::assertContains('/commerce/admin/marketplace/sellers/{uuid}', $paths);
        self::assertContains('/commerce/admin/marketplace/sellers/{uuid}/suspend', $paths);
        self::assertContains('/commerce/admin/marketplace/sellers/{uuid}/reactivate', $paths);
        self::assertContains('/commerce/admin/marketplace/sellers/{uuid}/close', $paths);
        self::assertContains('/commerce/admin/marketplace/sellers/{uuid}/memberships', $paths);
        self::assertContains('/commerce/admin/marketplace/sellers/{uuid}/memberships/{userUuid}', $paths);
    }

    private function freshRouter(): Router
    {
        $this->bind(ApplicationContext::class, $this->context);
        $router = new Router($this->contextContainer());

        require __DIR__ . '/../../../routes.php';

        return $router;
    }

    /** @return list<string> */
    private function marketplaceAdminPaths(Router $router): array
    {
        $paths = [];
        foreach ($router->getAllRoutes() as $route) {
            if (str_starts_with((string) $route['path'], '/commerce/admin/marketplace')) {
                $paths[] = (string) $route['path'];
            }
        }

        return array_values(array_unique($paths));
    }

    // -----------------------------------------------------------------
    // Real-path catalog attribution helpers (design spec §2.2/§2.7)
    // -----------------------------------------------------------------

    private function enableMarketplace(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
    }

    private function activateWorkspace(string $tenant): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => substr('actv' . md5($tenant), 0, 12),
            'tenant_uuid' => $tenant,
            'status' => 'active',
        ]);
    }

    private function marketplaceCatalog(): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant(),
            new StockRepository(),
            new ProductChildrenRepository(),
            new ShippingClassRepository(),
            new MarketplaceMode(),
            new MarketplaceWorkspaceLock(),
            new SellerRepository()
        );
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

    /** @return array<string,mixed> */
    private function productInput(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'draft',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '', $slug)),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ];
    }
}
