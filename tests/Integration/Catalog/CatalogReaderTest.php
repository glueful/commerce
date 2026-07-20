<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Container\Autowire\AutowireDefinition;
use Glueful\Container\Loader\DefaultServicesLoader;
use Glueful\Extensions\Commerce\Catalog\CatalogReader;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepositoryCatalogReader;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\CommerceServiceProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Commerce-Slice-1 Task 2: {@see CatalogReader}'s read-only cross-domain
 * contract, backed by {@see ProductRepositoryCatalogReader}. Every method is
 * total over its (tenant, uuid) input -- an unknown or cross-tenant uuid is
 * never distinguished from "doesn't exist" by either method.
 */
final class CatalogReaderTest extends CommerceTestCase
{
    public function testLiveProductReturnsTheRowAndIsNotTombstoned(): void
    {
        $product = $this->seedActiveProduct('catrdliveuuid', 'catrd-live');

        $reader = $this->reader();

        $row = $reader->findLiveProduct($this->context, '', $product['uuid']);
        self::assertIsArray($row);
        self::assertSame($product['uuid'], $row['uuid']);

        self::assertFalse($reader->isTombstoned($this->context, '', $product['uuid']));
    }

    public function testTombstonedProductIsNullFromFindLiveAndTrueFromIsTombstoned(): void
    {
        $product = $this->seedActiveProduct('catrdtombuuid', 'catrd-tomb');
        $this->catalog()->deleteProduct($this->context, $product['uuid']);

        $reader = $this->reader();

        self::assertNull($reader->findLiveProduct($this->context, '', $product['uuid']));
        self::assertTrue($reader->isTombstoned($this->context, '', $product['uuid']));
    }

    public function testUnknownUuidIsNullFromFindLiveAndFalseFromIsTombstoned(): void
    {
        $reader = $this->reader();

        self::assertNull($reader->findLiveProduct($this->context, '', 'no-such-product'));
        self::assertFalse($reader->isTombstoned($this->context, '', 'no-such-product'));
    }

    public function testCrossTenantProductIsNullFromFindLiveAndFalseFromIsTombstoned(): void
    {
        $product = $this->seedActiveProduct('catrdxtnuuid', 'catrd-xtn', tenant: 'othertenant1');

        $reader = $this->reader();

        // Reader is queried against the DEFAULT ('') tenant -- the product
        // only exists under 'othertenant1'.
        self::assertNull($reader->findLiveProduct($this->context, '', $product['uuid']));
        self::assertFalse($reader->isTombstoned($this->context, '', $product['uuid']));
    }

    /**
     * Proves `CommerceServiceProvider::services()` actually binds
     * `CatalogReader::class` to a working `ProductRepositoryCatalogReader`
     * (not just that the DI array key exists) -- the real
     * `DefaultServicesLoader`, autowired, in production mode.
     */
    public function testCatalogReaderIsBoundAndAutowiresToProductRepositoryCatalogReader(): void
    {
        $services = CommerceServiceProvider::services();
        self::assertArrayHasKey(CatalogReader::class, $services);
        self::assertSame(ProductRepositoryCatalogReader::class, $services[CatalogReader::class]['class']);
        self::assertTrue($services[CatalogReader::class]['shared']);
        self::assertTrue($services[CatalogReader::class]['autowire']);

        $definitions = (new DefaultServicesLoader())->load(
            CommerceServiceProvider::services(),
            CommerceServiceProvider::class,
            prod: true
        );
        self::assertInstanceOf(AutowireDefinition::class, $definitions[CatalogReader::class]);
    }

    private function reader(): CatalogReader
    {
        return new ProductRepositoryCatalogReader(new ProductRepository());
    }

    /** @return array<string,mixed> */
    private function seedActiveProduct(string $uuid, string $slug, string $tenant = ''): array
    {
        return $this->catalog($tenant)->createProduct($this->context, [
            'slug' => $slug,
            'name' => $uuid,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper($uuid),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
    }

    private function catalog(string $tenant = ''): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant),
            new StockRepository(),
            new ProductChildrenRepository()
        );
    }

    private function fixedTenant(string $tenant): \Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver
    {
        return new class ($tenant) implements \Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(\Glueful\Bootstrap\ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
    }
}
