<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\AdminReportController;
use Glueful\Extensions\Commerce\Http\DTOs\ProductsReportQuery;
use Glueful\Extensions\Commerce\Reports\ProductSalesReportRepository;
use Glueful\Extensions\Commerce\Reports\SalesReportRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * `GET /commerce/admin/reports/products` (design spec §4.2, plan Global
 * Constraints "Products" bullet): two INDEPENDENT sales/refund activity
 * branches combined by `UNION ALL`, outer-grouped by `variant_uuid`. Sales
 * come from `commerce_order_lines` joined to the revenue-status windowed
 * order derived table; refunds come from `commerce_refund_lines` joined
 * through `commerce_refunds` (windowed on `completed_at`, independent of
 * the underlying order's own window membership) and matched back to the
 * exact order line. A completed refund with no lines never contributes to
 * product attribution. Snapshots (`product_name`/`sku`) come from the
 * immutable `commerce_order_lines` columns, never a live catalog join.
 */
final class ProductsReportEndpointTest extends CommerceTestCase
{
    private const TENANT = 'tenantprod01';

    public function testSalesBranchAggregatesQuantityAndRevenuePerVariant(): void
    {
        $this->seedOrder('prodordA0001', 'paid', '2026-06-01 09:00:00');
        $this->seedOrderLine('lineA00001', 'prodordA0001', 'variantA0001', 'Widget A', 'SKU-A', 3, 3000);

        $body = $this->call('2026-06-01', '2026-06-30');

        self::assertSame(1, $body['total']);
        self::assertCount(1, $body['data']);
        $item = $body['data'][0];
        self::assertSame('variantA0001', $item['variant_uuid']);
        self::assertSame('SKU-A', $item['sku']);
        self::assertSame('Widget A', $item['product_name']);
        self::assertSame(3, $item['quantity']);
        self::assertSame(3000, $item['revenue_minor']);
        self::assertSame(0, $item['attributed_refunded_minor']);
        self::assertSame(0, $item['attributed_refunded_quantity']);
    }

    public function testProductsSortedByRevenueDescendingWithDeterministicTieBreakAcrossPages(): void
    {
        $this->seedOrder('prodordD0001', 'paid', '2026-06-01 09:00:00');
        $this->seedOrderLine('lineD00001', 'prodordD0001', 'variantD0001', 'Widget D', 'SKU-D', 1, 3000);

        $this->seedOrder('prodordB0001', 'paid', '2026-06-01 09:00:00');
        $this->seedOrderLine('lineB00001', 'prodordB0001', 'variantB0001', 'Widget B', 'SKU-B', 1, 1000);

        $this->seedOrder('prodordC0001', 'paid', '2026-06-01 09:00:00');
        $this->seedOrderLine('lineC00001', 'prodordC0001', 'variantC0001', 'Widget C', 'SKU-C', 1, 1000);

        $this->seedOrder('prodordZ0001', 'paid', '2026-06-01 09:00:00');
        $this->seedOrderLine('lineZ00001', 'prodordZ0001', 'variantZ0001', 'Widget Z', 'SKU-Z', 1, 500);

        // Overall expected order: D (3000), then the {B,C} 1000-tie broken by
        // variant_uuid ASC (B before C), then Z (500). Page size 2 crosses the
        // tie boundary exactly, so pagination must be deterministic.
        $page1 = $this->call('2026-06-01', '2026-06-30', sort: 'revenue', page: 1, perPage: 2);
        self::assertSame(4, $page1['total']);
        self::assertSame(1, $page1['current_page']);
        self::assertSame(2, $page1['per_page']);
        self::assertSame(2, $page1['total_pages']);
        self::assertTrue($page1['has_next_page']);
        self::assertFalse($page1['has_previous_page']);
        self::assertSame(['variantD0001', 'variantB0001'], array_column($page1['data'], 'variant_uuid'));

        $page2 = $this->call('2026-06-01', '2026-06-30', sort: 'revenue', page: 2, perPage: 2);
        self::assertFalse($page2['has_next_page']);
        self::assertTrue($page2['has_previous_page']);
        self::assertSame(['variantC0001', 'variantZ0001'], array_column($page2['data'], 'variant_uuid'));
    }

    public function testProductsSortedByQuantityDescendingUsesADifferentOrderThanRevenue(): void
    {
        // X: high quantity, low revenue. W and Y: tied at quantity=5 with
        // different revenue -- proves quantity sort ignores revenue entirely,
        // falling back only to variant_uuid ASC on a genuine quantity tie
        // (W0001 sorts before Y0001).
        $this->seedOrder('prodordX0001', 'paid', '2026-06-01 09:00:00');
        $this->seedOrderLine('lineX00001', 'prodordX0001', 'variantX0001', 'Widget X', 'SKU-X', 10, 100);

        $this->seedOrder('prodordY0001', 'paid', '2026-06-01 09:00:00');
        $this->seedOrderLine('lineY00001', 'prodordY0001', 'variantY0001', 'Widget Y', 'SKU-Y', 5, 900);

        $this->seedOrder('prodordW0001', 'paid', '2026-06-01 09:00:00');
        $this->seedOrderLine('lineW00001', 'prodordW0001', 'variantW0001', 'Widget W', 'SKU-W', 5, 50);

        $page1 = $this->call('2026-06-01', '2026-06-30', sort: 'quantity', page: 1, perPage: 2);
        self::assertSame(3, $page1['total']);
        self::assertSame(['variantX0001', 'variantW0001'], array_column($page1['data'], 'variant_uuid'));

        $page2 = $this->call('2026-06-01', '2026-06-30', sort: 'quantity', page: 2, perPage: 2);
        self::assertSame(['variantY0001'], array_column($page2['data'], 'variant_uuid'));

        // Sanity: this ordering genuinely differs from a revenue sort (X would
        // rank second there, not first, since Y has the highest revenue at
        // 900) -- proves `sort` actually switches columns, not just labels.
        $revenueOrder = $this->call('2026-06-01', '2026-06-30', sort: 'revenue', perPage: 100);
        self::assertSame(['variantY0001', 'variantX0001', 'variantW0001'], array_column($revenueOrder['data'], 'variant_uuid'));
    }

    public function testRefundOnlyVariantAppearsInRefundCompletionMonthWithZeroSalesFields(): void
    {
        // Order placed and revenue-eligible in May -- entirely outside June's window.
        $this->seedOrder('prodordR0001', 'paid', '2026-05-15 08:00:00');
        $this->seedOrderLine('lineR00001', 'prodordR0001', 'variantR0001', 'Widget R', 'SKU-R', 2, 2000);

        // Its refund completes in June -- decision 5: refunds bucket/window on
        // completed_at, independent of the order's own window membership.
        $this->seedRefund('prodrefR0001', 'prodordR0001', 500, 'completed', '2026-06-05 09:00:00');
        $this->seedRefundLine('prodrefR0001', 'lineR00001', 1, 500);

        $body = $this->call('2026-06-01', '2026-06-30');

        self::assertSame(1, $body['total']);
        $item = $body['data'][0];
        self::assertSame('variantR0001', $item['variant_uuid']);
        self::assertSame('SKU-R', $item['sku']);
        self::assertSame('Widget R', $item['product_name']);
        self::assertSame(0, $item['quantity']);
        self::assertSame(0, $item['revenue_minor']);
        self::assertSame(500, $item['attributed_refunded_minor']);
        self::assertSame(1, $item['attributed_refunded_quantity']);
    }

    public function testLinelessCompletedRefundIsExcludedFromProductAttribution(): void
    {
        $this->seedOrder('prodordL0001', 'paid', '2026-06-01 09:00:00');
        $this->seedOrderLine('lineL00001', 'prodordL0001', 'variantL0001', 'Widget L', 'SKU-L', 3, 3000);

        // A completed, order-level refund with NO refund_lines -- it cannot be
        // honestly attributed to any product and must not affect this report.
        $this->seedRefund('prodrefL0001', 'prodordL0001', 1000, 'completed', '2026-06-02 09:00:00');

        $body = $this->call('2026-06-01', '2026-06-30');

        self::assertSame(1, $body['total']);
        $item = $body['data'][0];
        self::assertSame('variantL0001', $item['variant_uuid']);
        self::assertSame(3, $item['quantity']);
        self::assertSame(3000, $item['revenue_minor']);
        self::assertSame(0, $item['attributed_refunded_minor']);
        self::assertSame(0, $item['attributed_refunded_quantity']);
    }

    public function testVariantWithBothInWindowSalesAndRefundsCombinesBranchesInOneRow(): void
    {
        // One variant, active on BOTH branches inside the same window: the
        // outer GROUP BY must merge the two UNION ALL branch rows into a
        // single result row carrying the sales branch's quantity/revenue AND
        // the refund branch's attributed sums.
        $this->seedOrder('prodordM0001', 'paid', '2026-06-01 09:00:00');
        $this->seedOrderLine('lineM00001', 'prodordM0001', 'variantM0001', 'Widget M', 'SKU-M', 4, 4000);

        // The SAME order line is refunded via TWO separate completed refunds,
        // both completed in-window: attributed sums must accumulate across
        // refunds (700 + 300 = 1000 minor; 1 + 2 = 3 quantity).
        $this->seedRefund('prodrefM0001', 'prodordM0001', 700, 'completed', '2026-06-10 09:00:00');
        $this->seedRefundLine('prodrefM0001', 'lineM00001', 1, 700);
        $this->seedRefund('prodrefM0002', 'prodordM0001', 300, 'completed', '2026-06-20 09:00:00');
        $this->seedRefundLine('prodrefM0002', 'lineM00001', 2, 300);

        $body = $this->call('2026-06-01', '2026-06-30');

        self::assertSame(
            1,
            $body['total'],
            'one variant active on both branches must yield exactly one outer row'
        );
        self::assertCount(1, $body['data']);
        $item = $body['data'][0];
        self::assertSame('variantM0001', $item['variant_uuid']);
        self::assertSame('SKU-M', $item['sku']);
        self::assertSame('Widget M', $item['product_name']);
        self::assertSame(4, $item['quantity']);
        self::assertSame(4000, $item['revenue_minor']);
        self::assertSame(1000, $item['attributed_refunded_minor']);
        self::assertSame(3, $item['attributed_refunded_quantity']);
    }

    public function testProductNameSnapshotSurvivesLaterCatalogRename(): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => 'prodcatS0001',
            'tenant_uuid' => self::TENANT,
            'slug' => 'widget-s',
            'name' => 'Original Name',
            'type' => 'physical',
            'status' => 'active',
        ]);
        $this->connection->table('commerce_variants')->insert([
            'uuid' => 'variantS0001',
            'tenant_uuid' => self::TENANT,
            'product_uuid' => 'prodcatS0001',
            'sku' => 'SKU-S',
            'option_values' => '{}',
            'price' => 1000,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $this->seedOrder('prodordS0001', 'paid', '2026-06-01 09:00:00');
        $this->seedOrderLine('lineS00001', 'prodordS0001', 'variantS0001', 'Original Name', 'SKU-S', 1, 1000);

        // The live catalog product is renamed AFTER the order was placed.
        $this->connection->table('commerce_products')
            ->where('uuid', '=', 'prodcatS0001')
            ->update(['name' => 'Renamed Later']);

        $body = $this->call('2026-06-01', '2026-06-30');

        self::assertSame(1, $body['total']);
        self::assertSame('Original Name', $body['data'][0]['product_name'], 'must reflect the order-line snapshot, not the live catalog name');
    }

    public function testWindowEdgesIncludeFromInclusiveAndExcludeToExclusiveBoundary(): void
    {
        // Exactly at the window's `from` boundary -- included.
        $this->seedOrder('prodordE0001', 'paid', '2026-06-10 00:00:00');
        $this->seedOrderLine('lineE00001', 'prodordE0001', 'variantE0001', 'Widget E', 'SKU-E', 1, 100);

        // Exactly at the window's exclusive end (`to` + 1 day, 00:00:00) -- excluded.
        $this->seedOrder('prodordF0001', 'paid', '2026-06-11 00:00:00');
        $this->seedOrderLine('lineF00001', 'prodordF0001', 'variantF0001', 'Widget F', 'SKU-F', 1, 200);

        $body = $this->call('2026-06-10', '2026-06-10');

        self::assertSame(1, $body['total']);
        self::assertSame('variantE0001', $body['data'][0]['variant_uuid']);
    }

    public function testTenantIsolationReturnsDisjointResults(): void
    {
        $this->seedOrder('prodordT0001', 'paid', '2026-06-01 08:00:00', tenant: 'tenantprodA1');
        $this->seedOrderLine('lineT00001', 'prodordT0001', 'variantT0001', 'Widget T', 'SKU-T', 1, 100);

        $this->seedOrder('prodordU0001', 'paid', '2026-06-01 08:00:00', tenant: 'tenantprodB1');
        $this->seedOrderLine('lineU00001', 'prodordU0001', 'variantU0001', 'Widget U', 'SKU-U', 1, 999);

        $body = $this->call('2026-06-01', '2026-06-30', tenant: 'tenantprodA1');

        self::assertSame(1, $body['total']);
        self::assertSame('variantT0001', $body['data'][0]['variant_uuid']);
    }

    public function testEmptyWindowReturnsEmptyPaginatedList(): void
    {
        $body = $this->call('2026-08-01', '2026-08-02');

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
        string $from,
        string $to,
        ?string $sort = null,
        ?int $page = null,
        ?int $perPage = null,
        string $tenant = self::TENANT,
    ): array {
        $response = $this->controller($tenant)->products(
            new ProductsReportQuery(from: $from, to: $to, sort: $sort, page: $page, per_page: $perPage),
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
            new ProductSalesReportRepository()
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

    private function seedOrder(
        string $uuid,
        string $status,
        ?string $placedAt,
        ?string $createdAt = null,
        string $tenant = self::TENANT,
    ): void {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'email' => 'buyer@example.com',
            'user_uuid' => null,
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 0,
            'grand_total' => 0,
            'placed_at' => $placedAt,
            'created_at' => $createdAt ?? ($placedAt ?? '2026-01-01 00:00:00'),
        ]);
    }

    private function seedOrderLine(
        string $uuid,
        string $orderUuid,
        string $variantUuid,
        string $productName,
        string $sku,
        int $quantity,
        int $lineTotal,
    ): void {
        $this->connection->table('commerce_order_lines')->insert([
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

    private function seedRefund(
        string $uuid,
        string $orderUuid,
        int $amount,
        string $status,
        ?string $completedAt,
        string $tenant = self::TENANT,
    ): void {
        $this->connection->table('commerce_refunds')->insert([
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

    private function seedRefundLine(string $refundUuid, string $orderLineUuid, int $quantity, int $amount): void
    {
        $this->connection->table('commerce_refund_lines')->insert([
            'refund_uuid' => $refundUuid,
            'order_line_uuid' => $orderLineUuid,
            'quantity' => $quantity,
            'amount' => $amount,
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
