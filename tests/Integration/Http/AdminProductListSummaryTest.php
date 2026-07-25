<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\DTOs\AdminProductListQuery;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * 1.6.0: the price/stock summary `GET /admin/products` attaches to every row, so an admin
 * catalog list can show what a product costs and how many are on hand without N+1 reads.
 *
 * Two behaviours carry the weight here and are pinned individually below:
 *   - price spans ALL variants (`price_from`/`price_to`), because this is the merchant's own
 *     catalog view — variant status answers a storefront question, not this one;
 *   - `stock_quantity` is NEVER a fabricated zero. A product with no tracked variant, and a
 *     product whose variant lost its `commerce_stock` row, both report `null` — the latter
 *     WITHOUT throwing {@see \Glueful\Extensions\Commerce\Inventory\StockIntegrityException}
 *     the way the per-product `products.stock.index` read does, since one drifted variant must
 *     not 500 an entire browsing page (`DiagnosticsReport::variants_missing_stock` stays the
 *     loud channel).
 */
final class AdminProductListSummaryTest extends CommerceTestCase
{
    public function testSummaryReportsPriceRangeVariantCountAndTrackedStock(): void
    {
        $this->seedProduct('prodlist0001');
        $this->seedVariant('prodlist0001', 'varlist00001', 1999, 'USD', 0);
        $this->seedStock('stklist00001', 'varlist00001', 4, true);
        $this->seedVariant('prodlist0001', 'varlist00002', 2999, 'USD', 1);
        $this->seedStock('stklist00002', 'varlist00002', 6, true);

        $row = $this->firstRow();

        self::assertSame(2, $row['variant_count']);
        self::assertSame(1999, $row['price_from']);
        self::assertSame(2999, $row['price_to']);
        self::assertSame('USD', $row['currency']);
        // Tracked quantities SUM across the product's variants.
        self::assertSame(10, $row['stock_quantity']);
        self::assertTrue($row['stock_tracked']);
    }

    /** A single-variant product reports an equal from/to, so a caller renders one amount. */
    public function testSingleVariantReportsEqualFromAndTo(): void
    {
        $this->seedProduct('prodlist0002');
        $this->seedVariant('prodlist0002', 'varlist00003', 70000, 'USD', 0);
        $this->seedStock('stklist00003', 'varlist00003', 5, true);

        $row = $this->firstRow();

        self::assertSame(70000, $row['price_from']);
        self::assertSame(70000, $row['price_to']);
        self::assertSame(5, $row['stock_quantity']);
    }

    /**
     * Price spans ALL variants — an inactive variant's price is still a price the merchant set,
     * and the row's own `status` column answers sellability.
     */
    public function testPriceSpansEveryVariantRegardlessOfVariantStatus(): void
    {
        $this->seedProduct('prodlist0003');
        $this->seedVariant('prodlist0003', 'varlist00004', 500, 'USD', 0, 'draft');
        $this->seedStock('stklist00004', 'varlist00004', 0, false);
        $this->seedVariant('prodlist0003', 'varlist00005', 1500, 'USD', 1);
        $this->seedStock('stklist00005', 'varlist00005', 3, true);

        $row = $this->firstRow();

        self::assertSame(500, $row['price_from']);
        self::assertSame(1500, $row['price_to']);
        // Only the TRACKED variant contributes a quantity; the untracked one adds nothing.
        self::assertSame(3, $row['stock_quantity']);
    }

    /** Nothing tracked → `null`, never a fabricated 0 that reads as "out of stock". */
    public function testUntrackedStockReportsNullNotZero(): void
    {
        $this->seedProduct('prodlist0004');
        $this->seedVariant('prodlist0004', 'varlist00006', 1000, 'USD', 0);
        $this->seedStock('stklist00006', 'varlist00006', 0, false);

        $row = $this->firstRow();

        self::assertNull($row['stock_quantity']);
        self::assertFalse($row['stock_tracked']);
    }

    /**
     * The integrity-drift case: a variant with NO `commerce_stock` row makes the product's
     * quantity UNKNOWN (`null`) rather than a silently-short sum — and the list still responds
     * 200, unlike the per-product stock read which throws.
     */
    public function testMissingStockRowReportsNullQuantityWithoutFailingTheList(): void
    {
        $this->seedProduct('prodlist0005');
        $this->seedVariant('prodlist0005', 'varlist00007', 1000, 'USD', 0);
        $this->seedStock('stklist00007', 'varlist00007', 9, true);
        // Second variant deliberately WITHOUT a stock row (bypasses StockRepository::ensureRow).
        $this->seedVariant('prodlist0005', 'varlist00008', 1200, 'USD', 1);

        $response = $this->list();
        $row = $this->json($response)['data'][0];

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($row['stock_quantity'], 'a short sum would understate real stock');
        // The product still HAS a tracked variant — the flag stays honest about that.
        self::assertTrue($row['stock_tracked']);
    }

    /** A variant-less product (e.g. a fresh grouped bundle) summarizes without inventing prices. */
    public function testProductWithoutVariantsReportsNullsAndZeroCount(): void
    {
        $this->seedProduct('prodlist0006');

        $row = $this->firstRow();

        self::assertSame(0, $row['variant_count']);
        self::assertNull($row['price_from']);
        self::assertNull($row['price_to']);
        self::assertNull($row['currency']);
        self::assertNull($row['stock_quantity']);
        self::assertFalse($row['stock_tracked']);
    }

    /** The summary is ADDITIVE: every pre-existing product column still crosses the wire. */
    public function testSummaryKeysAreAdditiveAndNeverReplaceProductColumns(): void
    {
        $this->seedProduct('prodlist0007');
        $this->seedVariant('prodlist0007', 'varlist00009', 800, 'USD', 0);
        $this->seedStock('stklist00009', 'varlist00009', 2, true);

        $row = $this->firstRow();

        foreach (['uuid', 'slug', 'name', 'type', 'status'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertSame('prodlist0007', $row['uuid']);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function firstRow(): array
    {
        $body = $this->json($this->list());
        self::assertNotEmpty($body['data']);

        return $body['data'][0];
    }

    private function list(): HttpResponse
    {
        return $this->controller()->index(new AdminProductListQuery(), Request::create('/x'));
    }

    private function controller(): AdminProductController
    {
        // Every collaborator constructed explicitly: the controller ctor eagerly resolves any
        // it isn't given, and this suite's container binds no CatalogService.
        return new AdminProductController(
            $this->context,
            new CatalogService(
                new ProductRepository(),
                new VariantRepository(),
                new SentinelTenantResolver(),
                new StockRepository(),
                new ProductChildrenRepository(),
            ),
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            null,
            new StockRepository(),
        );
    }

    private function seedProduct(string $uuid, string $tenant = ''): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'type' => 'physical',
            'status' => 'active',
        ]);
    }

    private function seedVariant(
        string $productUuid,
        string $variantUuid,
        int $price,
        string $currency,
        int $position,
        string $status = 'active',
        string $tenant = ''
    ): void {
        $this->connection->table('commerce_variants')->insert([
            'uuid' => $variantUuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'sku' => $variantUuid,
            'option_values' => '[]',
            'price' => $price,
            'currency' => $currency,
            'position' => $position,
            'status' => $status,
        ]);
    }

    private function seedStock(
        string $stockUuid,
        string $variantUuid,
        int $quantity,
        bool $tracked,
        string $tenant = ''
    ): void {
        $this->connection->table('commerce_stock')->insert([
            'uuid' => $stockUuid,
            'tenant_uuid' => $tenant,
            'variant_uuid' => $variantUuid,
            'quantity' => $quantity,
            'tracked' => $tracked ? 1 : 0,
        ]);
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
