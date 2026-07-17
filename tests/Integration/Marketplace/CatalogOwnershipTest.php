<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\DTOs\CreateProductData;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\SellerAttributionException;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\QueryLoggingPdoStatement;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Catalog attribution policy (design spec §2.2/§2.7, MV1 Task 3): the
 * MASTER-OFF fast path, the ACTIVE/INACTIVE seller-attribution matrix, and
 * the DTO/service guards that keep `seller_uuid` unreachable from an
 * ordinary create/update. `CatalogService::createProduct()`'s policy is the
 * invariant-bearing surface -- see class docblock.
 */
final class CatalogOwnershipTest extends CommerceTestCase
{
    private const TENANT = 'tenantOWNER01';

    // -----------------------------------------------------------------
    // Master-off fast path
    // -----------------------------------------------------------------

    public function testConfigOffCreateWithMarketplaceCollaboratorsIssuesZeroMarketplaceTableQueries(): void
    {
        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryLoggingPdoStatement::class]);
        QueryLoggingPdoStatement::$queries = [];

        $this->marketplaceCatalog()->createProduct($this->context, $this->productInput('with-collab-off'));

        self::assertNotEmpty(QueryLoggingPdoStatement::$queries, 'sanity: the create itself must run some queries');

        $marketplaceTables = ['commerce_marketplace_settings', 'commerce_sellers', 'commerce_seller_memberships'];
        foreach (QueryLoggingPdoStatement::$queries as $sql) {
            foreach ($marketplaceTables as $table) {
                self::assertStringNotContainsString(
                    $table,
                    $sql,
                    "config-off create must issue ZERO marketplace-table queries; saw: {$sql}"
                );
            }
        }
    }

    public function testConfigOffSellerUuidProvidedIs422(): void
    {
        $this->expectException(ValidationException::class);

        $this->marketplaceCatalog()->createProduct(
            $this->context,
            $this->productInput('config-off-attributed'),
            'someSeller01'
        );
    }

    public function testMissingMarketplaceCollaboratorsBehavesAsMasterOffEvenWithConfigOn(): void
    {
        $this->enableMarketplace();

        // legacyCatalog() has ALL THREE marketplace collaborators null -- the
        // "legacy direct construction" case: must behave as master-off
        // regardless of the config flag.
        $this->expectException(ValidationException::class);
        $this->legacyCatalog()->createProduct(
            $this->context,
            $this->productInput('collaborators-missing'),
            'someSeller01'
        );
    }

    // -----------------------------------------------------------------
    // Master on: the ACTIVE/INACTIVE attribution matrix
    // -----------------------------------------------------------------

    public function testMasterOnPlainCreateIs422WhenWorkspaceIsActive(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);

        $this->expectException(ValidationException::class);
        $this->marketplaceCatalog()->createProduct($this->context, $this->productInput('blocked-active'));
    }

    public function testMasterOnPlainCreateIsAllowedWhenWorkspaceIsInactive(): void
    {
        $this->enableMarketplace();

        $product = $this->marketplaceCatalog()->createProduct($this->context, $this->productInput('inactive-ok'));

        self::assertNull($product['seller_uuid']);

        $settings = $this->connection->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', self::TENANT)
            ->first();
        self::assertNotNull(
            $settings,
            'the workspace lock claim inside createProduct() must have ensured the settings row'
        );
        self::assertSame('disabled', $settings['status']);
    }

    public function testMasterOnSellerDerivedCreateSetsSellerUuid(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $seller = $this->seedActiveSeller('owned-goods');

        $product = $this->marketplaceCatalog()->createProduct(
            $this->context,
            $this->productInput('seller-owned'),
            $seller['uuid']
        );

        self::assertSame($seller['uuid'], $product['seller_uuid']);

        $row = $this->connection->table('commerce_products')->where('uuid', '=', $product['uuid'])->first();
        self::assertSame($seller['uuid'], $row['seller_uuid']);
    }

    public function testSuspendedTargetSellerIsRejectedWith409(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $seller = $this->seedActiveSeller('suspended-goods');
        $this->sellerService()->suspend($this->context, self::TENANT, $seller['uuid']);

        $this->expectException(SellerAttributionException::class);
        $this->marketplaceCatalog()->createProduct(
            $this->context,
            $this->productInput('suspended-target'),
            $seller['uuid']
        );
    }

    public function testClosedTargetSellerIsRejectedWith409(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $seller = $this->seedActiveSeller('closed-goods');
        $this->sellerService()->close($this->context, self::TENANT, $seller['uuid']);

        $this->expectException(SellerAttributionException::class);
        $this->marketplaceCatalog()->createProduct(
            $this->context,
            $this->productInput('closed-target'),
            $seller['uuid']
        );
    }

    public function testUnknownTargetSellerIsRejectedWith422(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);

        $this->expectException(ValidationException::class);
        $this->marketplaceCatalog()->createProduct(
            $this->context,
            $this->productInput('unknown-target'),
            'doesNotExist1'
        );
    }

    public function testForeignTenantTargetSellerIsRejectedWith422(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $foreignSeller = $this->seedActiveSeller('foreign-goods', 'tenantFOREIGN1');

        $this->expectException(ValidationException::class);
        $this->marketplaceCatalog()->createProduct(
            $this->context,
            $this->productInput('foreign-target'),
            $foreignSeller['uuid']
        );
    }

    public function testInactiveModeSellerUuidProvidedIs422(): void
    {
        $this->enableMarketplace();
        $seller = $this->seedActiveSeller('inactive-attempt');

        $this->expectException(ValidationException::class);
        $this->marketplaceCatalog()->createProduct(
            $this->context,
            $this->productInput('inactive-attributed'),
            $seller['uuid']
        );
    }

    // -----------------------------------------------------------------
    // DTO / service guards against a client-supplied seller_uuid
    // -----------------------------------------------------------------

    public function testCreateProductDataHydrationRejectsAClientSuppliedSellerUuidKey(): void
    {
        try {
            (new RequestDataHydrator())->hydrate(
                CreateProductData::class,
                [
                    'slug' => 'dto-seller-reject',
                    'name' => 'DTO Seller Reject',
                    'type' => 'physical',
                    'status' => 'draft',
                    'seller_uuid' => 'someSeller01',
                    'variants' => [[
                        'sku' => 'DTO-SR-1',
                        'option_values' => [],
                        'price' => 100,
                        'currency' => 'USD',
                    ]],
                ],
                [],
                []
            );
            self::fail('expected a ValidationException for a client-supplied seller_uuid on create');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('seller_uuid', $e->errors());
        }
    }

    public function testUpdateProductRejectsASellerUuidKeyAnywhereInTheChangesArray(): void
    {
        $product = $this->legacyCatalog()->createProduct($this->context, $this->productInput('update-reject'));

        $this->expectException(ValidationException::class);
        $this->legacyCatalog()->updateProduct($this->context, $product['uuid'], [
            'name' => 'New Name',
            'seller_uuid' => 'someSeller01',
        ]);
    }

    public function testAdminProductControllerUpdateRejectsASellerUuidKeyInTheRawBodyWith422(): void
    {
        $product = $this->legacyCatalog()->createProduct($this->context, $this->productInput('update-http-reject'));

        $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode(
            ['name' => 'New Name', 'seller_uuid' => 'someSeller01'],
            JSON_THROW_ON_ERROR
        ));
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->adminController()->update($request, $product['uuid']);

        self::assertSame(422, $response->getStatusCode());
        $row = $this->connection->table('commerce_products')->where('uuid', '=', $product['uuid'])->first();
        self::assertSame($product['name'], $row['name'], 'no field may change when seller_uuid is rejected');
    }

    // -----------------------------------------------------------------
    // Grep gate: ProductRepository::insert() has no production caller
    // outside CatalogService (design spec §2.7/Task 3 brief).
    // -----------------------------------------------------------------

    public function testProductRepositoryInsertHasNoProductionCallerOutsideCatalogService(): void
    {
        $srcRoot = dirname(__DIR__, 3) . '/src';
        self::assertDirectoryExists($srcRoot);

        // Discover every property/parameter name bound to ProductRepository
        // across src/ (the codebase consistently names it `$products`, but
        // this stays correct even if a file uses a different name).
        $propertyNames = ['products'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            if (preg_match_all('/ProductRepository\s+\$(\w+)/', $contents, $matches) > 0) {
                foreach ($matches[1] as $name) {
                    $propertyNames[] = $name;
                }
            }
        }
        $propertyNames = array_values(array_unique($propertyNames));

        $pattern = '/(?:\$this->(?:' . implode('|', $propertyNames) . ')|\$(?:'
            . implode('|', $propertyNames) . '))->insert\(/';

        $callers = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            if (preg_match($pattern, $contents) === 1) {
                $callers[] = str_replace($srcRoot . '/', '', $file->getPathname());
            }
        }

        self::assertSame(
            ['Catalog/CatalogService.php'],
            $callers,
            'ProductRepository::insert() must have no production caller outside CatalogService.'
        );
    }

    // -----------------------------------------------------------------
    // Helpers
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

    private function sellerService(): SellerService
    {
        return new SellerService(new SellerRepository(), new SellerMembershipRepository());
    }

    private function legacyCatalog(): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant(),
            new StockRepository(),
            new ProductChildrenRepository(),
            new ShippingClassRepository()
        );
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

    private function adminController(): AdminProductController
    {
        return new AdminProductController(
            $this->context,
            $this->legacyCatalog(),
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant()
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
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '', $slug)),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ];
    }
}
