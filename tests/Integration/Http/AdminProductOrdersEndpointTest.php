<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\OrderProjection;
use Glueful\Extensions\Commerce\Http\DTOs\ProductOrdersQuery;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * `GET /products/{uuid}/orders` (composed-editor spec §5.4b phase 2, 1.6.0): recent orders
 * CONTAINING a product plus a windowed, product-attributed activity summary. Wire discipline:
 * recent rows cross through {@see OrderProjection::forAdmin()} exactly like every other admin
 * order surface; summary revenue mirrors the products report's rules — statuses
 * `('paid','fulfilled','refunded')`, report time `placed_at` falling back to `created_at`, and
 * revenue attributed as the SUM of THIS product's line totals, never orders' grand totals.
 */
final class AdminProductOrdersEndpointTest extends CommerceTestCase
{
    public function testRecentOrdersContainOnlyOrdersWithTheProductOnceEachMostRecentFirst(): void
    {
        $this->seedProductWithVariant('prodorders01', 'v-target');
        $this->seedProductWithVariant('otherproduct', 'v-other');

        // Two lines of the SAME order for the target product -> the order appears ONCE.
        $this->seedOrder('ord-multi', 'paid', '2026-07-20 10:00:00');
        $this->seedLine('ord-multi', 'v-target', 1000);
        $this->seedLine('ord-multi', 'v-target', 500);
        // Older order with the product.
        $this->seedOrder('ord-old', 'fulfilled', '2026-07-01 10:00:00');
        $this->seedLine('ord-old', 'v-target', 700);
        // An order WITHOUT the product never appears.
        $this->seedOrder('ord-none', 'paid', '2026-07-22 10:00:00');
        $this->seedLine('ord-none', 'v-other', 900);

        $body = $this->request('prodorders01');

        $uuids = array_column($body['data']['recent'], 'uuid');
        self::assertSame(['ord-multi', 'ord-old'], $uuids);
    }

    public function testRecentRowsAreProjectedThroughTheAdminWhitelist(): void
    {
        $this->seedProductWithVariant('prodorders02', 'v-proj');
        $this->seedOrder('ord-proj', 'paid', '2026-07-20 10:00:00');
        $this->seedLine('ord-proj', 'v-proj', 1000);

        $body = $this->request('prodorders02');

        self::assertCount(1, $body['data']['recent']);
        self::assertEqualsCanonicalizing(
            OrderProjection::FIELDS,
            array_keys($body['data']['recent'][0]),
        );
    }

    public function testSummaryMirrorsTheReportsDiscipline(): void
    {
        $this->seedProductWithVariant('prodorders03', 'v-sum');

        // In-window, revenue statuses: counted; revenue = the product's line totals only.
        $this->seedOrder('ord-a', 'paid', $this->daysAgo(2));
        $this->seedLine('ord-a', 'v-sum', 1000);
        $this->seedOrder('ord-b', 'refunded', $this->daysAgo(5));
        $this->seedLine('ord-b', 'v-sum', 300);
        // pending_payment / canceled: never counted.
        $this->seedOrder('ord-c', 'pending_payment', $this->daysAgo(1));
        $this->seedLine('ord-c', 'v-sum', 9999);
        $this->seedOrder('ord-d', 'canceled', $this->daysAgo(1));
        $this->seedLine('ord-d', 'v-sum', 8888);
        // Out of the 30-day window: excluded from the summary (still eligible for `recent`).
        $this->seedOrder('ord-e', 'paid', $this->daysAgo(40));
        $this->seedLine('ord-e', 'v-sum', 5000);

        $body = $this->request('prodorders03');

        self::assertSame(30, $body['data']['window_days']);
        self::assertSame(['orders' => 2, 'revenue_minor' => 1300], $body['data']['summary']);
        // `recent` is activity, not revenue: every order containing the product qualifies.
        self::assertCount(5, $body['data']['recent']);
    }

    public function testPlacedAtFallsBackToCreatedAtForWindowing(): void
    {
        $this->seedProductWithVariant('prodorders04', 'v-fb');
        // placed_at NULL, created_at in-window -> counted via the fallback.
        $this->seedOrder('ord-fb', 'paid', null, $this->daysAgo(3));
        $this->seedLine('ord-fb', 'v-fb', 400);

        $body = $this->request('prodorders04');

        self::assertSame(['orders' => 1, 'revenue_minor' => 400], $body['data']['summary']);
    }

    public function testClampsAndParameters(): void
    {
        $this->seedProductWithVariant('prodorders05', 'v-clamp');
        for ($i = 0; $i < 4; $i++) {
            $this->seedOrder("ord-cl-{$i}", 'paid', $this->daysAgo($i + 1));
            $this->seedLine("ord-cl-{$i}", 'v-clamp', 100);
        }

        $body = $this->request('prodorders05', days: 7, perPage: 2);
        self::assertSame(7, $body['data']['window_days']);
        self::assertCount(2, $body['data']['recent']);

        // Out-of-range values clamp instead of erroring.
        $body = $this->request('prodorders05', days: 9999, perPage: 9999);
        self::assertSame(365, $body['data']['window_days']);
        self::assertCount(4, $body['data']['recent']);
    }

    public function testUnknownCrossTenantAndTombstonedProducts404(): void
    {
        $this->seedProductWithVariant('prodorders06', 'v-404');

        // Unknown.
        $this->expectNotFound('missingproduct');
        // Cross-tenant.
        $this->connection->table('commerce_products')->insert([
            'uuid' => 'crosstenant1',
            'tenant_uuid' => 'othertenant1',
            'slug' => 'cross-tenant-orders',
            'name' => 'Cross',
            'type' => 'physical',
            'status' => 'active',
        ]);
        $this->expectNotFound('crosstenant1');
        // Tombstoned.
        $this->connection->table('commerce_products')
            ->where('uuid', '=', 'prodorders06')
            ->executeModification(
                'UPDATE commerce_products SET deleted_at = ? WHERE uuid = ?',
                ['2026-07-01 00:00:00', 'prodorders06'],
            );
        $this->expectNotFound('prodorders06');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function controller(): AdminOrderController
    {
        return new AdminOrderController(
            $this->context,
            new OrderRepository(),
            new StockRepository(),
            new OrderPaymentService(new OrderRepository()),
            new SentinelTenantResolver(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider()
        );
    }

    /** @return array<string,mixed> */
    private function request(string $productUuid, ?int $days = null, ?int $perPage = null): array
    {
        $response = $this->controller()->ordersForProductIndex(
            new ProductOrdersQuery(days: $days, per_page: $perPage),
            Request::create("/commerce/admin/products/{$productUuid}/orders", 'GET'),
            $productUuid
        );
        self::assertSame(200, $response->getStatusCode());

        return $this->json($response);
    }

    private function expectNotFound(string $productUuid): void
    {
        try {
            $this->controller()->ordersForProductIndex(
                new ProductOrdersQuery(),
                Request::create("/commerce/admin/products/{$productUuid}/orders", 'GET'),
                $productUuid
            );
            self::fail('expected NotFoundException');
        } catch (\Glueful\Http\Exceptions\Client\NotFoundException $e) {
            self::assertSame('Resource not found.', $e->getMessage());
        }
    }

    private function daysAgo(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    }

    private function seedProductWithVariant(string $productUuid, string $variantUuid): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => '',
            'slug' => "slug-{$productUuid}",
            'name' => "Product {$productUuid}",
            'type' => 'physical',
            'status' => 'active',
        ]);
        $this->connection->table('commerce_variants')->insert([
            'uuid' => $variantUuid,
            'tenant_uuid' => '',
            'product_uuid' => $productUuid,
            'sku' => "sku-{$variantUuid}",
            'price' => 1000,
            'currency' => 'USD',
            'status' => 'active',
        ]);
    }

    private function seedOrder(
        string $uuid,
        string $status,
        ?string $placedAt,
        ?string $createdAt = null,
    ): void {
        $row = [
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'email' => 'buyer@example.com',
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'placed_at' => $placedAt,
        ];
        if ($createdAt !== null) {
            $row['created_at'] = $createdAt;
        }
        $this->connection->table('commerce_orders')->insert($row);
    }

    private function seedLine(string $orderUuid, string $variantUuid, int $lineTotal): void
    {
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => 'l' . substr(md5($orderUuid . $variantUuid . $lineTotal . random_int(0, 999999)), 0, 11),
            'order_uuid' => $orderUuid,
            'variant_uuid' => $variantUuid,
            'product_name' => 'Line',
            'sku' => 'sku',
            'unit_price' => $lineTotal,
            'quantity' => 1,
            'line_total' => $lineTotal,
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
