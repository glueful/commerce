<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Reports;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Http\Admin\AdminReportController;
use Glueful\Extensions\Commerce\Http\DTOs\ProductsReportQuery;
use Glueful\Extensions\Commerce\Http\DTOs\ReportWindowQuery;
use Glueful\Extensions\Commerce\Http\DTOs\StockReportQuery;
use Glueful\Extensions\Commerce\Reports\CustomerReportRepository;
use Glueful\Extensions\Commerce\Reports\ProductSalesReportRepository;
use Glueful\Extensions\Commerce\Reports\SalesReportRepository;
use Glueful\Extensions\Commerce\Reports\StockReportRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Real-PostgreSQL regression lane for the report layer's hand-built raw SQL
 * (design spec Layer 5, §4). Every `*ReportRepository` bypasses the portable
 * query builder and interpolates driver-specific fragments
 * (`DateBucketSql`/`ReportBoundarySql`) or relies on native-prepare parameter
 * binding (`StockReportRepository`'s `tracked` boolean) directly -- exactly
 * the class of defect the SQLite-only CI gate cannot catch. This file closes
 * the gap the Layer 5 final review flagged: two real PostgreSQL bugs (the
 * `tracked = 1` boolean literal, and 13/14-char tenant fixtures overflowing
 * `varchar(12)`) shipped in this layer and were only ever caught by a manual,
 * uncommitted pgsql run -- see `StockReportRepository`'s own class docblock
 * for the fixed boolean-bind rationale.
 *
 * One focused test per endpoint, each hitting that endpoint's own
 * PG-sensitive surface:
 * - sales: `DateBucketSql::dayExpression()` (`to_char` on pgsql) feeding both
 *   a day-grouped series and a PHP-folded week bucket, plus refund-day
 *   bucketing independent of the order's own window.
 * - products: the two independent sales/refund activity branches combined
 *   with `UNION ALL` (mismatched literal-vs-summed column types across the
 *   union arms -- exactly what PostgreSQL is strict about and SQLite is not).
 * - customers: the week-boundary literal table (`ReportBoundarySql::rowExpression()`'s
 *   `CAST(? AS timestamp)`) `LEFT JOIN`ed against windowed activity, proving
 *   both a real bucket and a genuinely empty bucket resolve correctly.
 * - stock: `commerce_stock.tracked`'s bound boolean parameter (`tracked = ?`)
 *   together with the `quantity <= ?` threshold filter and ORDER BY/LIMIT.
 *
 * Gating, fixture-width discipline (`varchar(12)` on every `uuid`/`tenant_uuid`
 * column), self-healing per-test cleanup, and the throwaway `Connection`/
 * `ApplicationContext` construction all mirror
 * `Customers\CustomerAggregationPgsqlTest` exactly. `whereIn()->delete()` is
 * NOT supported by this framework's query builder for the DELETE path (see
 * `Refunds\GatewayRefundTest::deleteRaceDebris()`'s own docblock) -- cleanup
 * here uses only simple `where(col, '=', value)->delete()` calls.
 */
final class ReportPgsqlTest extends CommerceTestCase
{
    private const TENANT_SALES = 'pgtsales01';
    private const TENANT_PRODUCTS = 'pgtprod001';
    private const TENANT_CUSTOMERS = 'pgtcust001';
    private const TENANT_STOCK = 'pgtstock01';

    public function testSalesDayAndWeekBucketingAggregateCorrectlyOnRealPostgres(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane to prove the day-bucket/refund SQL is portable.');
        }

        $connection = $this->migratedConnection($this->pgConfig());
        $context = $this->pgsqlContext($connection);

        $this->cleanupSales($connection);

        try {
            // 2026-06-15 (Monday) .. 2026-06-21 (Sunday) is exactly ISO week
            // 2026-W25 -- shared convention with SalesReportEndpointTest.
            $this->seedOrder($connection, self::TENANT_SALES, 'pgsord00001', 'paid', 1000, '2026-06-15 08:00:00');
            $this->seedOrder($connection, self::TENANT_SALES, 'pgsord00002', 'paid', 2000, '2026-06-17 08:00:00');
            $this->seedRefund($connection, self::TENANT_SALES, 'pgsref00001', 'pgsord00001', 300, 'completed', '2026-06-16 09:00:00');

            $controller = $this->controller($context, self::TENANT_SALES);

            $dayBody = $this->json($controller->sales(
                new ReportWindowQuery(from: '2026-06-15', to: '2026-06-21', group: 'day'),
                Request::create('/x', 'GET')
            ));
            $series = [];
            foreach ($dayBody['data']['series'] as $bucket) {
                $series[$bucket['bucket']] = $bucket;
            }

            self::assertSame(1000, $series['2026-06-15']['gross_minor']);
            self::assertSame(1, $series['2026-06-15']['orders_count']);
            self::assertSame(0, $series['2026-06-15']['refunds_minor']);
            self::assertSame(1000, $series['2026-06-15']['net_minor']);

            self::assertSame(0, $series['2026-06-16']['gross_minor']);
            self::assertSame(0, $series['2026-06-16']['orders_count']);
            self::assertSame(300, $series['2026-06-16']['refunds_minor']);
            self::assertSame(-300, $series['2026-06-16']['net_minor']);

            self::assertSame(2000, $series['2026-06-17']['gross_minor']);
            self::assertSame(1, $series['2026-06-17']['orders_count']);
            self::assertSame(0, $series['2026-06-17']['refunds_minor']);

            self::assertSame(0, $series['2026-06-18']['gross_minor']);

            $daySummary = $dayBody['data']['summary'];
            self::assertSame(3000, $daySummary['gross_minor']);
            self::assertSame(300, $daySummary['refunds_minor']);
            self::assertSame(2700, $daySummary['net_minor']);
            self::assertSame(2, $daySummary['orders_count']);
            self::assertSame(1500, $daySummary['aov_minor']);

            $weekBody = $this->json($controller->sales(
                new ReportWindowQuery(from: '2026-06-15', to: '2026-06-21', group: 'week'),
                Request::create('/x', 'GET')
            ));

            self::assertCount(1, $weekBody['data']['series']);
            $weekBucket = $weekBody['data']['series'][0];
            self::assertSame('2026-W25', $weekBucket['bucket']);
            self::assertSame(3000, $weekBucket['gross_minor']);
            self::assertSame(300, $weekBucket['refunds_minor']);
            self::assertSame(2700, $weekBucket['net_minor']);
            self::assertSame(2, $weekBucket['orders_count']);
            self::assertSame(1500, $weekBucket['aov_minor']);
        } finally {
            $this->cleanupSales($connection);
        }
    }

    public function testProductsSalesAndRefundBranchesCombineViaUnionAllOnRealPostgres(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane to prove the UNION ALL activity SQL is portable.');
        }

        $connection = $this->migratedConnection($this->pgConfig());
        $context = $this->pgsqlContext($connection);

        $this->cleanupProducts($connection);

        try {
            // Variant A: sales-branch only, fully inside the June window.
            $this->seedOrder($connection, self::TENANT_PRODUCTS, 'pgpord00001', 'paid', 0, '2026-06-05 08:00:00');
            $this->seedOrderLine($connection, 'pgpline0001', 'pgpord00001', 'pgpvarA001', 'Widget A', 'SKU-A', 2, 2000);

            // Variant B: refund-branch only -- order placed in May (outside the
            // June window entirely), refund completes in June (decision 5:
            // refunds bucket/window on completed_at, independent of the order's
            // own window membership).
            $this->seedOrder($connection, self::TENANT_PRODUCTS, 'pgpord00002', 'paid', 0, '2026-05-15 08:00:00');
            $this->seedOrderLine($connection, 'pgpline0002', 'pgpord00002', 'pgpvarB001', 'Widget B', 'SKU-B', 1, 500);
            $this->seedRefund($connection, self::TENANT_PRODUCTS, 'pgpref00001', 'pgpord00002', 500, 'completed', '2026-06-10 09:00:00');
            $this->seedRefundLine($connection, 'pgpref00001', 'pgpline0002', 1, 500);

            $controller = $this->controller($context, self::TENANT_PRODUCTS);

            $body = $this->json($controller->products(
                new ProductsReportQuery(from: '2026-06-01', to: '2026-06-30', sort: 'revenue'),
                Request::create('/x', 'GET')
            ));

            self::assertSame(2, $body['total']);
            self::assertSame(['pgpvarA001', 'pgpvarB001'], array_column($body['data'], 'variant_uuid'));

            $byVariant = [];
            foreach ($body['data'] as $item) {
                $byVariant[$item['variant_uuid']] = $item;
            }

            self::assertSame('SKU-A', $byVariant['pgpvarA001']['sku']);
            self::assertSame('Widget A', $byVariant['pgpvarA001']['product_name']);
            self::assertSame(2, $byVariant['pgpvarA001']['quantity']);
            self::assertSame(2000, $byVariant['pgpvarA001']['revenue_minor']);
            self::assertSame(0, $byVariant['pgpvarA001']['attributed_refunded_minor']);
            self::assertSame(0, $byVariant['pgpvarA001']['attributed_refunded_quantity']);

            self::assertSame('SKU-B', $byVariant['pgpvarB001']['sku']);
            self::assertSame('Widget B', $byVariant['pgpvarB001']['product_name']);
            self::assertSame(0, $byVariant['pgpvarB001']['quantity']);
            self::assertSame(0, $byVariant['pgpvarB001']['revenue_minor']);
            self::assertSame(500, $byVariant['pgpvarB001']['attributed_refunded_minor']);
            self::assertSame(1, $byVariant['pgpvarB001']['attributed_refunded_quantity']);
        } finally {
            $this->cleanupProducts($connection);
        }
    }

    public function testCustomersWeekBoundaryDerivedTableClassifiesCorrectlyOnRealPostgres(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane to prove the week-boundary CAST SQL is portable.');
        }

        $connection = $this->migratedConnection($this->pgConfig());
        $context = $this->pgsqlContext($connection);

        $this->cleanupCustomers($connection);

        try {
            // Both orders (and this key's all-time first order) fall inside the
            // same ISO week (2026-W25) -- "new" exactly once, never both new and
            // returning. The second requested week (2026-W26) has no activity at
            // all, proving the LEFT JOINed boundary table still yields exactly
            // one (zero-filled) row for a genuinely empty bucket.
            $this->seedCustomerOrder($connection, self::TENANT_CUSTOMERS, 'pgcord00001', 'paid', '2026-06-15 08:00:00', 'pgcuserA001');
            $this->seedCustomerOrder($connection, self::TENANT_CUSTOMERS, 'pgcord00002', 'paid', '2026-06-17 08:00:00', 'pgcuserA001');

            $controller = $this->controller($context, self::TENANT_CUSTOMERS);

            $body = $this->json($controller->customers(
                new ReportWindowQuery(from: '2026-06-15', to: '2026-06-28', group: 'week'),
                Request::create('/x', 'GET')
            ));

            self::assertCount(2, $body['data']['series']);
            $series = [];
            foreach ($body['data']['series'] as $bucket) {
                $series[$bucket['bucket']] = $bucket;
            }

            self::assertSame(1, $series['2026-W25']['new_customers']);
            self::assertSame(0, $series['2026-W25']['returning_customers']);
            self::assertSame(0, $series['2026-W26']['new_customers']);
            self::assertSame(0, $series['2026-W26']['returning_customers']);

            self::assertSame(1, $body['data']['summary']['new_customers']);
            self::assertSame(0, $body['data']['summary']['returning_customers']);
            self::assertSame(1, $body['data']['summary']['total_customers']);
        } finally {
            $this->cleanupCustomers($connection);
        }
    }

    public function testStockTrackedBooleanBindAndThresholdFilterOnRealPostgres(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane to prove the tracked boolean bind is portable.');
        }

        $connection = $this->migratedConnection($this->pgConfig());
        $context = $this->pgsqlContext($connection);

        $this->cleanupStock($connection);

        try {
            // A: tracked, quantity 1 -- visible at the default threshold (2).
            $this->seedProduct($connection, self::TENANT_STOCK, 'pgstprodA01');
            $this->seedVariant($connection, self::TENANT_STOCK, 'pgstvarA001', 'pgstprodA01', 'SKU-A');
            $this->seedStock($connection, self::TENANT_STOCK, 'pgststkA001', 'pgstvarA001', 1, true);

            // B: untracked, quantity 0 -- must stay invisible regardless of
            // quantity or threshold. This is the exact regression the boolean
            // bind fix targets: a stray `tracked = 1` literal would have
            // rejected outright on PostgreSQL before matching any row at all.
            $this->seedProduct($connection, self::TENANT_STOCK, 'pgstprodB01');
            $this->seedVariant($connection, self::TENANT_STOCK, 'pgstvarB001', 'pgstprodB01', 'SKU-B');
            $this->seedStock($connection, self::TENANT_STOCK, 'pgststkB001', 'pgstvarB001', 0, false);

            // C: tracked, quantity 5 -- above the default threshold, but within
            // an overridden one.
            $this->seedProduct($connection, self::TENANT_STOCK, 'pgstprodC01');
            $this->seedVariant($connection, self::TENANT_STOCK, 'pgstvarC001', 'pgstprodC01', 'SKU-C');
            $this->seedStock($connection, self::TENANT_STOCK, 'pgststkC001', 'pgstvarC001', 5, true);

            $controller = $this->controller($context, self::TENANT_STOCK);

            $defaultBody = $this->json($controller->stock(
                new StockReportQuery(),
                Request::create('/x', 'GET')
            ));
            self::assertSame(1, $defaultBody['total']);
            self::assertSame('pgstvarA001', $defaultBody['data'][0]['variant_uuid']);
            self::assertSame(2, $defaultBody['data'][0]['threshold']);

            $overrideBody = $this->json($controller->stock(
                new StockReportQuery(threshold: 5),
                Request::create('/x', 'GET')
            ));
            self::assertSame(2, $overrideBody['total']);
            self::assertSame(
                ['pgstvarA001', 'pgstvarC001'],
                array_column($overrideBody['data'], 'variant_uuid'),
                'quantity ASC ordering; the untracked variant B must never appear'
            );
            self::assertSame(5, $overrideBody['data'][0]['threshold']);
        } finally {
            $this->cleanupStock($connection);
        }
    }

    // --- Helpers -------------------------------------------------------------
    // (pgsql lane setup mirrors Customers\CustomerAggregationPgsqlTest exactly.)

    private function controller(ApplicationContext $context, string $tenant): AdminReportController
    {
        return new AdminReportController(
            $context,
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

    private function cleanupSales(Connection $connection): void
    {
        $connection->table('commerce_refunds')->where('tenant_uuid', '=', self::TENANT_SALES)->delete();
        $connection->table('commerce_orders')->where('tenant_uuid', '=', self::TENANT_SALES)->delete();
    }

    /**
     * `commerce_order_lines`/`commerce_refund_lines` carry no `tenant_uuid`
     * column, so their debris is found via the tenant's own orders/refunds
     * first. `whereIn()->delete()` is not supported by this framework's query
     * builder (see class docblock) -- only simple per-row `where(..)->delete()`.
     */
    private function cleanupProducts(Connection $connection): void
    {
        $orderUuids = array_map(
            static fn (array $row): string => (string) $row['uuid'],
            $connection->table('commerce_orders')->where('tenant_uuid', '=', self::TENANT_PRODUCTS)->get()
        );
        foreach ($orderUuids as $orderUuid) {
            $connection->table('commerce_order_lines')->where('order_uuid', '=', $orderUuid)->delete();
        }

        $refundUuids = array_map(
            static fn (array $row): string => (string) $row['uuid'],
            $connection->table('commerce_refunds')->where('tenant_uuid', '=', self::TENANT_PRODUCTS)->get()
        );
        foreach ($refundUuids as $refundUuid) {
            $connection->table('commerce_refund_lines')->where('refund_uuid', '=', $refundUuid)->delete();
        }

        $connection->table('commerce_refunds')->where('tenant_uuid', '=', self::TENANT_PRODUCTS)->delete();
        $connection->table('commerce_orders')->where('tenant_uuid', '=', self::TENANT_PRODUCTS)->delete();
    }

    private function cleanupCustomers(Connection $connection): void
    {
        $connection->table('commerce_orders')->where('tenant_uuid', '=', self::TENANT_CUSTOMERS)->delete();
    }

    /**
     * `commerce_products` carries a `deleted_at` column, so a plain `delete()`
     * soft-deletes (leaves the row in place with `deleted_at` stamped) rather
     * than removing it -- the row would then keep colliding with the next
     * run's identical `uuid`/`(tenant_uuid, slug)` unique constraints.
     * `forceDelete()` is required here; `commerce_stock`/`commerce_variants`
     * carry no `deleted_at` column, so plain `delete()` already hard-deletes
     * (mirrors the same finding in `Refunds\GatewayRefundTest::deleteRaceDebris()`'s
     * own docblock, just for `deleted_at` instead of `whereIn()`).
     */
    private function cleanupStock(Connection $connection): void
    {
        $connection->table('commerce_stock')->where('tenant_uuid', '=', self::TENANT_STOCK)->delete();
        $connection->table('commerce_variants')->where('tenant_uuid', '=', self::TENANT_STOCK)->delete();
        $connection->table('commerce_products')->where('tenant_uuid', '=', self::TENANT_STOCK)->forceDelete();
    }

    private function seedOrder(
        Connection $connection,
        string $tenant,
        string $uuid,
        string $status,
        int $grandTotal,
        ?string $placedAt,
    ): void {
        $this->insertOrder($connection, $tenant, $uuid, $status, $grandTotal, $placedAt, null);
    }

    private function seedCustomerOrder(
        Connection $connection,
        string $tenant,
        string $uuid,
        string $status,
        ?string $placedAt,
        ?string $userUuid,
    ): void {
        $this->insertOrder($connection, $tenant, $uuid, $status, 0, $placedAt, $userUuid);
    }

    private function insertOrder(
        Connection $connection,
        string $tenant,
        string $uuid,
        string $status,
        int $grandTotal,
        ?string $placedAt,
        ?string $userUuid,
    ): void {
        $connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'email' => 'buyer@example.com',
            'user_uuid' => $userUuid,
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => $grandTotal,
            'grand_total' => $grandTotal,
            'placed_at' => $placedAt,
            'created_at' => $placedAt ?? '2026-01-01 00:00:00',
        ]);
    }

    private function seedRefund(
        Connection $connection,
        string $tenant,
        string $uuid,
        string $orderUuid,
        int $amount,
        string $status,
        ?string $completedAt,
    ): void {
        $connection->table('commerce_refunds')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'idempotency_key' => 'idem-' . $uuid,
            'request_fingerprint' => str_repeat('f', 40),
            'amount' => $amount,
            'currency' => 'USD',
            'method' => 'manual',
            'status' => $status,
            'completed_at' => $completedAt,
        ]);
    }

    private function seedOrderLine(
        Connection $connection,
        string $uuid,
        string $orderUuid,
        string $variantUuid,
        string $productName,
        string $sku,
        int $quantity,
        int $lineTotal,
    ): void {
        $connection->table('commerce_order_lines')->insert([
            'uuid' => $uuid,
            'order_uuid' => $orderUuid,
            'variant_uuid' => $variantUuid,
            'product_name' => $productName,
            'sku' => $sku,
            'option_values' => '{}',
            'unit_price' => $quantity > 0 ? intdiv($lineTotal, $quantity) : 0,
            'quantity' => $quantity,
            'line_total' => $lineTotal,
        ]);
    }

    private function seedRefundLine(
        Connection $connection,
        string $refundUuid,
        string $orderLineUuid,
        int $quantity,
        int $amount,
    ): void {
        $connection->table('commerce_refund_lines')->insert([
            'refund_uuid' => $refundUuid,
            'order_line_uuid' => $orderLineUuid,
            'quantity' => $quantity,
            'amount' => $amount,
        ]);
    }

    private function seedProduct(Connection $connection, string $tenant, string $uuid): void
    {
        $connection->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => 'slug-' . $uuid,
            'name' => 'Widget',
            'type' => 'physical',
            'status' => 'active',
        ]);
    }

    private function seedVariant(
        Connection $connection,
        string $tenant,
        string $uuid,
        string $productUuid,
        string $sku,
    ): void {
        $connection->table('commerce_variants')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'sku' => $sku,
            'option_values' => '{}',
            'price' => 1000,
            'currency' => 'USD',
            'status' => 'active',
        ]);
    }

    private function seedStock(
        Connection $connection,
        string $tenant,
        string $uuid,
        string $variantUuid,
        int $quantity,
        bool $tracked,
    ): void {
        $connection->table('commerce_stock')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'variant_uuid' => $variantUuid,
            'quantity' => $quantity,
            'tracked' => $tracked,
        ]);
    }

    /** @return array<string,mixed> */
    private function pgConfig(): array
    {
        return [
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'glueful_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
            ],
            'pooling' => ['enabled' => false],
        ];
    }

    /** @param array<string,mixed> $pgConfig */
    private function migratedConnection(array $pgConfig): Connection
    {
        $connection = new Connection($pgConfig);
        $schema = $connection->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        return $connection;
    }

    private function pgsqlContext(Connection $connection): ApplicationContext
    {
        $container = new class ($connection) implements ContainerInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database' || $id === Connection::class;
            }
        };

        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->setContainer($container);
        $context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../config/commerce.php');

        return $context;
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        self::assertSame(200, $response->getStatusCode());

        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
