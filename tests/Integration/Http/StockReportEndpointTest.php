<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\AdminReportController;
use Glueful\Extensions\Commerce\Http\DTOs\StockReportQuery;
use Glueful\Extensions\Commerce\Reports\CustomerReportRepository;
use Glueful\Extensions\Commerce\Reports\ProductSalesReportRepository;
use Glueful\Extensions\Commerce\Reports\ReportConfigurationException;
use Glueful\Extensions\Commerce\Reports\SalesReportRepository;
use Glueful\Extensions\Commerce\Reports\StockReportRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * `GET /commerce/admin/reports/stock` (design spec §4.4, decision 10):
 * point-in-time low-stock/out-of-stock list. `commerce_stock` (`tracked = 1`,
 * `quantity <= threshold`) JOIN `commerce_variants` (`status = 'active'`)
 * JOIN `commerce_products` (`deleted_at IS NULL` -- draft/inactive products
 * remain visible to stock administrators until trashed). The effective
 * threshold is resolved by `StockThreshold::resolve()` (request override or
 * `config('commerce.reports.low_stock_threshold')`, default 2 in the test
 * harness's merged config) and echoed per item.
 */
final class StockReportEndpointTest extends CommerceTestCase
{
    private const TENANT = 'tenantstock1';

    public function testOutOfStockAtZeroQuantityIsVisibleAndEchoesEffectiveThreshold(): void
    {
        $this->seedProduct('stockprodA01');
        $this->seedVariant('stockvarA001', 'stockprodA01', 'SKU-A');
        $this->seedStock('stockstkA001', 'stockvarA001', 0);

        $body = $this->call();

        self::assertSame(1, $body['total']);
        $item = $body['data'][0];
        self::assertSame('stockvarA001', $item['variant_uuid']);
        self::assertSame('SKU-A', $item['sku']);
        self::assertSame('Widget', $item['product_name']);
        self::assertSame(0, $item['quantity']);
        self::assertSame('out_of_stock', $item['status']);
        self::assertSame(2, $item['threshold'], 'default config threshold (2) must be echoed per item');
    }

    public function testLowStockAtExactThresholdIsVisible(): void
    {
        $this->seedProduct('stockprodB01');
        $this->seedVariant('stockvarB001', 'stockprodB01', 'SKU-B');
        $this->seedStock('stockstkB001', 'stockvarB001', 2); // exactly the default threshold

        $body = $this->call();

        self::assertSame(1, $body['total']);
        self::assertSame(2, $body['data'][0]['quantity']);
        self::assertSame('low_stock', $body['data'][0]['status']);
    }

    public function testInvisibleWhenQuantityExceedsThresholdByOne(): void
    {
        $this->seedProduct('stockprodC01');
        $this->seedVariant('stockvarC001', 'stockprodC01', 'SKU-C');
        $this->seedStock('stockstkC001', 'stockvarC001', 3); // threshold(2) + 1

        $body = $this->call();

        self::assertSame(0, $body['total']);
        self::assertSame([], $body['data']);
    }

    public function testUntrackedVariantIsInvisibleRegardlessOfQuantity(): void
    {
        $this->seedProduct('stockprodD01');
        $this->seedVariant('stockvarD001', 'stockprodD01', 'SKU-D');
        $this->seedStock('stockstkD001', 'stockvarD001', 0, tracked: false);

        $body = $this->call();

        self::assertSame(0, $body['total']);
    }

    public function testInactiveVariantIsExcluded(): void
    {
        $this->seedProduct('stockprodE01');
        $this->seedVariant('stockvarE001', 'stockprodE01', 'SKU-E', status: 'inactive');
        $this->seedStock('stockstkE001', 'stockvarE001', 0);

        $body = $this->call();

        self::assertSame(0, $body['total']);
    }

    public function testTrashedProductIsExcludedWhileDraftProductRemainsVisible(): void
    {
        $this->seedProduct('stockprodF01', deletedAt: '2026-01-01 00:00:00');
        $this->seedVariant('stockvarF001', 'stockprodF01', 'SKU-F');
        $this->seedStock('stockstkF001', 'stockvarF001', 0);

        $this->seedProduct('stockprodG01', status: 'draft');
        $this->seedVariant('stockvarG001', 'stockprodG01', 'SKU-G');
        $this->seedStock('stockstkG001', 'stockvarG001', 0);

        $body = $this->call();

        self::assertSame(1, $body['total']);
        self::assertSame('stockvarG001', $body['data'][0]['variant_uuid']);
    }

    public function testStatusFilterOutOfStockReturnsOnlyThatClass(): void
    {
        $this->seedProduct('stockprodH01');
        $this->seedVariant('stockvarH001', 'stockprodH01', 'SKU-H');
        $this->seedStock('stockstkH001', 'stockvarH001', 0); // out_of_stock

        $this->seedProduct('stockprodI01');
        $this->seedVariant('stockvarI001', 'stockprodI01', 'SKU-I');
        $this->seedStock('stockstkI001', 'stockvarI001', 1); // low_stock

        $body = $this->call(status: 'out_of_stock');

        self::assertSame(1, $body['total']);
        self::assertSame('stockvarH001', $body['data'][0]['variant_uuid']);
        self::assertSame('out_of_stock', $body['data'][0]['status']);
    }

    public function testStatusFilterLowStockReturnsOnlyThatClass(): void
    {
        $this->seedProduct('stockprodH02');
        $this->seedVariant('stockvarH002', 'stockprodH02', 'SKU-H2');
        $this->seedStock('stockstkH002', 'stockvarH002', 0); // out_of_stock

        $this->seedProduct('stockprodI02');
        $this->seedVariant('stockvarI002', 'stockprodI02', 'SKU-I2');
        $this->seedStock('stockstkI002', 'stockvarI002', 1); // low_stock

        $body = $this->call(status: 'low_stock');

        self::assertSame(1, $body['total']);
        self::assertSame('stockvarI002', $body['data'][0]['variant_uuid']);
        self::assertSame('low_stock', $body['data'][0]['status']);
    }

    public function testThresholdOverrideChangesVisibilityAndIsEchoed(): void
    {
        $this->seedProduct('stockprodJ01');
        $this->seedVariant('stockvarJ001', 'stockprodJ01', 'SKU-J');
        $this->seedStock('stockstkJ001', 'stockvarJ001', 5); // above default threshold(2)

        $defaultBody = $this->call();
        self::assertSame(0, $defaultBody['total'], 'invisible under the default config threshold');

        $overrideBody = $this->call(threshold: 5);
        self::assertSame(1, $overrideBody['total']);
        self::assertSame(5, $overrideBody['data'][0]['quantity']);
        self::assertSame(5, $overrideBody['data'][0]['threshold'], 'override value must be echoed, not the config default');
    }

    public function testThresholdOverrideAboveMaxReturns422NotServerError(): void
    {
        try {
            $this->call(threshold: 100001);
            self::fail('Expected ValidationException for an out-of-range threshold override.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
    }

    public function testThresholdOverrideNegativeReturns422NotServerError(): void
    {
        try {
            $this->call(threshold: -1);
            self::fail('Expected ValidationException for an out-of-range threshold override.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
    }

    public function testInvalidConfiguredThresholdNonNumericSurfacesAsNamedConfigurationError(): void
    {
        $this->context->mergeConfigDefaults('commerce', [
            'reports' => ['low_stock_threshold' => 'banana'],
        ]);

        $this->expectException(ReportConfigurationException::class);
        $this->expectExceptionMessageMatches('/low_stock_threshold/');

        $this->call();
    }

    public function testInvalidConfiguredThresholdNegativeSurfacesAsNamedConfigurationError(): void
    {
        $this->context->mergeConfigDefaults('commerce', [
            'reports' => ['low_stock_threshold' => -5],
        ]);

        $this->expectException(ReportConfigurationException::class);
        $this->expectExceptionMessageMatches('/low_stock_threshold/');

        $this->call();
    }

    public function testOrderingByQuantityAscendingThenVariantUuidAscendingOnTies(): void
    {
        $this->seedProduct('stockprodK01');
        $this->seedVariant('stockvarK003', 'stockprodK01', 'SKU-K3');
        $this->seedStock('stockstkK003', 'stockvarK003', 1);

        $this->seedProduct('stockprodK02');
        $this->seedVariant('stockvarK001', 'stockprodK02', 'SKU-K1');
        $this->seedStock('stockstkK001', 'stockvarK001', 0);

        $this->seedProduct('stockprodK03');
        $this->seedVariant('stockvarK002', 'stockprodK03', 'SKU-K2');
        $this->seedStock('stockstkK002', 'stockvarK002', 1);

        $body = $this->call(perPage: 100);

        self::assertSame(
            ['stockvarK001', 'stockvarK002', 'stockvarK003'],
            array_column($body['data'], 'variant_uuid'),
            'quantity ASC first (0 before the 1/1 tie), tie broken by variant_uuid ASC'
        );
    }

    public function testTenantIsolationReturnsDisjointResults(): void
    {
        $this->seedProduct('stockprodL01', tenant: 'stocktenantA');
        $this->seedVariant('stockvarL001', 'stockprodL01', 'SKU-L', tenant: 'stocktenantA');
        $this->seedStock('stockstkL001', 'stockvarL001', 0, tenant: 'stocktenantA');

        $this->seedProduct('stockprodM01', tenant: 'stocktenantB');
        $this->seedVariant('stockvarM001', 'stockprodM01', 'SKU-M', tenant: 'stocktenantB');
        $this->seedStock('stockstkM001', 'stockvarM001', 0, tenant: 'stocktenantB');

        $body = $this->call(tenant: 'stocktenantA');

        self::assertSame(1, $body['total']);
        self::assertSame('stockvarL001', $body['data'][0]['variant_uuid']);
    }

    public function testEmptyResultReturnsEmptyPaginatedList(): void
    {
        $body = $this->call();

        self::assertSame(0, $body['total']);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['total_pages']);
        self::assertFalse($body['has_next_page']);
        self::assertFalse($body['has_previous_page']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function call(
        ?string $status = null,
        ?int $threshold = null,
        ?int $page = null,
        ?int $perPage = null,
        string $tenant = self::TENANT,
    ): array {
        $response = $this->controller($tenant)->stock(
            new StockReportQuery(status: $status, threshold: $threshold, page: $page, per_page: $perPage),
            Request::create('/x', 'GET')
        );

        self::assertSame(200, $response->getStatusCode());

        return $this->json($response);
    }

    private function controller(string $tenant = self::TENANT): AdminReportController
    {
        return new AdminReportController(
            $this->context,
            new SalesReportRepository(),
            $this->tenantResolver($tenant),
            new ProductSalesReportRepository(),
            new CustomerReportRepository(),
            new StockReportRepository()
        );
    }

    private function tenantResolver(string $tenant): CurrentTenantResolver
    {
        return new class ($tenant) implements CurrentTenantResolver {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
    }

    private function seedProduct(
        string $uuid,
        string $name = 'Widget',
        string $status = 'active',
        ?string $deletedAt = null,
        string $tenant = self::TENANT,
    ): void {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => 'slug-' . $uuid,
            'name' => $name,
            'type' => 'physical',
            'status' => $status,
            'deleted_at' => $deletedAt,
        ]);
    }

    private function seedVariant(
        string $uuid,
        string $productUuid,
        string $sku,
        string $status = 'active',
        string $tenant = self::TENANT,
    ): void {
        $this->connection->table('commerce_variants')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'sku' => $sku,
            'option_values' => '{}',
            'price' => 1000,
            'currency' => 'USD',
            'status' => $status,
        ]);
    }

    private function seedStock(
        string $uuid,
        string $variantUuid,
        int $quantity,
        bool $tracked = true,
        string $tenant = self::TENANT,
    ): void {
        $this->connection->table('commerce_stock')->insert([
            'uuid' => $uuid,
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
