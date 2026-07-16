<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Auth\ApiKey\Exceptions\InsufficientScopeException;
use Glueful\Bootstrap\ApplicationContext;
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
use Glueful\Routing\Middleware\RequireScopeMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Cross-cutting Layer 5 gate (plan Task 6, Group D): a three-way tenant
 * sweep (tenant A, tenant B, and the `''` sentinel) proving all four report
 * endpoints (`sales`, `products`, `customers`, `stock`) remain disjoint --
 * the sentinel is scoped exactly like any other `tenant_uuid` value, never
 * special-cased into leaking or absorbing another tenant's aggregates.
 *
 * Also covers the two remaining cross-cutting concerns from the plan's
 * Global Constraints that no single per-endpoint test file exercises:
 * `require_scope:commerce:read` enforcement (a `commerce:write`-only token
 * must be rejected, mirroring the framework's own
 * `RequireScopeMiddlewareTest::dispatchWithParams()` pattern against the
 * exact scope string `routes.php` registers for every `reports/*` route),
 * and each endpoint's endpoint-specific empty-result contract (sales/
 * customers zero-fill their series and summary; products/stock return an
 * empty paginated list -- stock is point-in-time, so no window is asserted).
 */
final class ReportTenancyTest extends CommerceTestCase
{
    // `tenant_uuid`/`uuid` columns are `varchar(12)` (matching production
    // nanoid width, see `Utils::generateNanoID()`'s default `security.nanoid_length`
    // of 12) -- SQLite ignores declared VARCHAR length entirely, but PostgreSQL
    // enforces it strictly, so every literal identifier in this file (tenants
    // included) is kept to 12 characters or fewer to pass identically on both.
    private const TENANT_A = 'rpttenantA01';
    private const TENANT_B = 'rpttenantB01';
    private const SENTINEL = '';

    // -----------------------------------------------------------------
    // Three-way tenant + sentinel isolation sweep
    // -----------------------------------------------------------------

    public function testSalesReportDisjointAcrossTenantATenantBAndSentinel(): void
    {
        $this->seedOrder('rptsalA00001', 'paid', 100, '2026-06-01 08:00:00', tenant: self::TENANT_A);
        $this->seedRefund('rptsalA00002', 'rptsalA00001', 20, 'completed', '2026-06-01 10:00:00', tenant: self::TENANT_A);

        $this->seedOrder('rptsalB00001', 'paid', 250, '2026-06-01 08:00:00', tenant: self::TENANT_B);
        $this->seedRefund('rptsalB00002', 'rptsalB00001', 50, 'completed', '2026-06-01 10:00:00', tenant: self::TENANT_B);

        $this->seedOrder('rptsalS00001', 'paid', 999, '2026-06-01 08:00:00', tenant: self::SENTINEL);
        $this->seedRefund('rptsalS00002', 'rptsalS00001', 100, 'completed', '2026-06-01 10:00:00', tenant: self::SENTINEL);

        $summaryA = $this->callSales(self::TENANT_A)['data']['summary'];
        self::assertSame(100, $summaryA['gross_minor']);
        self::assertSame(20, $summaryA['refunds_minor']);
        self::assertSame(80, $summaryA['net_minor']);
        self::assertSame(1, $summaryA['orders_count']);

        $summaryB = $this->callSales(self::TENANT_B)['data']['summary'];
        self::assertSame(250, $summaryB['gross_minor']);
        self::assertSame(50, $summaryB['refunds_minor']);
        self::assertSame(200, $summaryB['net_minor']);
        self::assertSame(1, $summaryB['orders_count']);

        $summaryS = $this->callSales(self::SENTINEL)['data']['summary'];
        self::assertSame(999, $summaryS['gross_minor']);
        self::assertSame(100, $summaryS['refunds_minor']);
        self::assertSame(899, $summaryS['net_minor']);
        self::assertSame(1, $summaryS['orders_count']);
    }

    public function testProductsReportDisjointAcrossTenantATenantBAndSentinel(): void
    {
        $this->seedOrder('rptprdA00001', 'paid', 0, '2026-06-01 09:00:00', tenant: self::TENANT_A);
        $this->seedOrderLine('rptlinA0001', 'rptprdA00001', 'rptvarA00001', 'Widget A', 'SKU-RPA', 1, 100);

        $this->seedOrder('rptprdB00001', 'paid', 0, '2026-06-01 09:00:00', tenant: self::TENANT_B);
        $this->seedOrderLine('rptlinB0001', 'rptprdB00001', 'rptvarB00001', 'Widget B', 'SKU-RPB', 1, 250);

        $this->seedOrder('rptprdS00001', 'paid', 0, '2026-06-01 09:00:00', tenant: self::SENTINEL);
        $this->seedOrderLine('rptlinS0001', 'rptprdS00001', 'rptvarS00001', 'Widget S', 'SKU-RPS', 1, 999);

        $bodyA = $this->callProducts(self::TENANT_A);
        self::assertSame(1, $bodyA['total']);
        self::assertSame('rptvarA00001', $bodyA['data'][0]['variant_uuid']);
        self::assertSame(100, $bodyA['data'][0]['revenue_minor']);

        $bodyB = $this->callProducts(self::TENANT_B);
        self::assertSame(1, $bodyB['total']);
        self::assertSame('rptvarB00001', $bodyB['data'][0]['variant_uuid']);
        self::assertSame(250, $bodyB['data'][0]['revenue_minor']);

        $bodyS = $this->callProducts(self::SENTINEL);
        self::assertSame(1, $bodyS['total']);
        self::assertSame('rptvarS00001', $bodyS['data'][0]['variant_uuid']);
        self::assertSame(999, $bodyS['data'][0]['revenue_minor']);
    }

    public function testCustomersReportDisjointAcrossTenantATenantBAndSentinel(): void
    {
        $this->seedOrder('rptcusA00001', 'paid', 0, '2026-06-01 09:00:00', userUuid: 'rptusrA0001', tenant: self::TENANT_A);

        $this->seedOrder('rptcusB00001', 'paid', 0, '2026-06-01 09:00:00', userUuid: 'rptusrB0001', tenant: self::TENANT_B);
        $this->seedOrder('rptcusB00002', 'paid', 0, '2026-06-01 09:00:00', userUuid: 'rptusrB0002', tenant: self::TENANT_B);

        $this->seedOrder('rptcusS00001', 'paid', 0, '2026-06-01 09:00:00', userUuid: 'rptusrS0001', tenant: self::SENTINEL);

        $summaryA = $this->callCustomers(self::TENANT_A)['data']['summary'];
        self::assertSame(1, $summaryA['new_customers']);
        self::assertSame(0, $summaryA['returning_customers']);
        self::assertSame(1, $summaryA['total_customers']);

        $summaryB = $this->callCustomers(self::TENANT_B)['data']['summary'];
        self::assertSame(2, $summaryB['new_customers']);
        self::assertSame(0, $summaryB['returning_customers']);
        self::assertSame(2, $summaryB['total_customers']);

        $summaryS = $this->callCustomers(self::SENTINEL)['data']['summary'];
        self::assertSame(1, $summaryS['new_customers']);
        self::assertSame(0, $summaryS['returning_customers']);
        self::assertSame(1, $summaryS['total_customers']);
    }

    public function testStockReportDisjointAcrossTenantATenantBAndSentinel(): void
    {
        $this->seedProduct('rptstpA0001', tenant: self::TENANT_A);
        $this->seedVariant('rptstvA0001', 'rptstpA0001', 'SKU-STA', tenant: self::TENANT_A);
        $this->seedStock('rptstsA0001', 'rptstvA0001', 0, tenant: self::TENANT_A);

        $this->seedProduct('rptstpB0001', tenant: self::TENANT_B);
        $this->seedVariant('rptstvB0001', 'rptstpB0001', 'SKU-STB', tenant: self::TENANT_B);
        $this->seedStock('rptstsB0001', 'rptstvB0001', 1, tenant: self::TENANT_B);

        $this->seedProduct('rptstpS0001', tenant: self::SENTINEL);
        $this->seedVariant('rptstvS0001', 'rptstpS0001', 'SKU-STS', tenant: self::SENTINEL);
        $this->seedStock('rptstsS0001', 'rptstvS0001', 0, tenant: self::SENTINEL);

        $bodyA = $this->callStock(self::TENANT_A);
        self::assertSame(1, $bodyA['total']);
        self::assertSame('rptstvA0001', $bodyA['data'][0]['variant_uuid']);

        $bodyB = $this->callStock(self::TENANT_B);
        self::assertSame(1, $bodyB['total']);
        self::assertSame('rptstvB0001', $bodyB['data'][0]['variant_uuid']);

        $bodyS = $this->callStock(self::SENTINEL);
        self::assertSame(1, $bodyS['total']);
        self::assertSame('rptstvS0001', $bodyS['data'][0]['variant_uuid']);
    }

    // -----------------------------------------------------------------
    // Scope enforcement: require_scope:commerce:read per route
    // -----------------------------------------------------------------

    public function testSalesReportRequiresReadScopeRejectsWriteOnlyToken(): void
    {
        $allowed = $this->dispatchScoped(['commerce:read'], fn (): HttpResponse => $this->controller(self::TENANT_A)->sales(
            new ReportWindowQuery(from: '2026-06-01', to: '2026-06-01'),
            Request::create('/x', 'GET')
        ));
        self::assertSame(200, $allowed->getStatusCode());

        $this->expectException(InsufficientScopeException::class);
        $this->dispatchScoped(['commerce:write'], fn (): HttpResponse => $this->controller(self::TENANT_A)->sales(
            new ReportWindowQuery(from: '2026-06-01', to: '2026-06-01'),
            Request::create('/x', 'GET')
        ));
    }

    public function testProductsReportRequiresReadScopeRejectsWriteOnlyToken(): void
    {
        $allowed = $this->dispatchScoped(['commerce:read'], fn (): HttpResponse => $this->controller(self::TENANT_A)->products(
            new ProductsReportQuery(from: '2026-06-01', to: '2026-06-01'),
            Request::create('/x', 'GET')
        ));
        self::assertSame(200, $allowed->getStatusCode());

        $this->expectException(InsufficientScopeException::class);
        $this->dispatchScoped(['commerce:write'], fn (): HttpResponse => $this->controller(self::TENANT_A)->products(
            new ProductsReportQuery(from: '2026-06-01', to: '2026-06-01'),
            Request::create('/x', 'GET')
        ));
    }

    public function testCustomersReportRequiresReadScopeRejectsWriteOnlyToken(): void
    {
        $allowed = $this->dispatchScoped(['commerce:read'], fn (): HttpResponse => $this->controller(self::TENANT_A)->customers(
            new ReportWindowQuery(from: '2026-06-01', to: '2026-06-01'),
            Request::create('/x', 'GET')
        ));
        self::assertSame(200, $allowed->getStatusCode());

        $this->expectException(InsufficientScopeException::class);
        $this->dispatchScoped(['commerce:write'], fn (): HttpResponse => $this->controller(self::TENANT_A)->customers(
            new ReportWindowQuery(from: '2026-06-01', to: '2026-06-01'),
            Request::create('/x', 'GET')
        ));
    }

    public function testStockReportRequiresReadScopeRejectsWriteOnlyToken(): void
    {
        $allowed = $this->dispatchScoped(['commerce:read'], fn (): HttpResponse => $this->controller(self::TENANT_A)->stock(
            new StockReportQuery(),
            Request::create('/x', 'GET')
        ));
        self::assertSame(200, $allowed->getStatusCode());

        $this->expectException(InsufficientScopeException::class);
        $this->dispatchScoped(['commerce:write'], fn (): HttpResponse => $this->controller(self::TENANT_A)->stock(
            new StockReportQuery(),
            Request::create('/x', 'GET')
        ));
    }

    // -----------------------------------------------------------------
    // Endpoint-specific empty-result contracts
    // -----------------------------------------------------------------

    public function testSalesReportEmptyResultReturnsZeroFilledSeriesAndZeroSummary(): void
    {
        $body = $this->callSales(self::TENANT_A, '2026-09-01', '2026-09-02');

        self::assertCount(2, $body['data']['series']);
        foreach ($body['data']['series'] as $bucket) {
            self::assertSame(0, $bucket['gross_minor']);
            self::assertSame(0, $bucket['refunds_minor']);
            self::assertSame(0, $bucket['net_minor']);
            self::assertSame(0, $bucket['orders_count']);
            self::assertSame(0, $bucket['aov_minor']);
        }

        $summary = $body['data']['summary'];
        self::assertSame(0, $summary['gross_minor']);
        self::assertSame(0, $summary['refunds_minor']);
        self::assertSame(0, $summary['net_minor']);
        self::assertSame(0, $summary['orders_count']);
        self::assertSame(0, $summary['aov_minor']);
        self::assertSame(0, $summary['pending_orders']);
        self::assertSame(0, $summary['discount_minor']);
        self::assertSame(0, $summary['shipping_minor']);
        self::assertSame(0, $summary['tax_minor']);
    }

    public function testCustomersReportEmptyResultReturnsZeroFilledSeriesAndZeroSummary(): void
    {
        $body = $this->callCustomers(self::TENANT_A, '2026-09-01', '2026-09-02');

        self::assertCount(2, $body['data']['series']);
        foreach ($body['data']['series'] as $bucket) {
            self::assertSame(0, $bucket['new_customers']);
            self::assertSame(0, $bucket['returning_customers']);
        }

        $summary = $body['data']['summary'];
        self::assertSame(0, $summary['new_customers']);
        self::assertSame(0, $summary['returning_customers']);
        self::assertSame(0, $summary['total_customers']);
    }

    public function testProductsReportEmptyResultReturnsEmptyPaginatedData(): void
    {
        $body = $this->callProducts(self::TENANT_A, '2026-09-01', '2026-09-02');

        self::assertSame(0, $body['total']);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['total_pages']);
        self::assertFalse($body['has_next_page']);
        self::assertFalse($body['has_previous_page']);
    }

    public function testStockReportEmptyResultReturnsEmptyPaginatedDataWithNoQualifyingRows(): void
    {
        // Stock is point-in-time -- there is no from/to window on this endpoint
        // at all, so this is deliberately NOT a window-boundary assertion, just
        // "no qualifying rows exist" (no product/variant/stock rows seeded).
        $body = $this->callStock(self::TENANT_A);

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
    private function callSales(string $tenant, string $from = '2026-06-01', string $to = '2026-06-01'): array
    {
        $response = $this->controller($tenant)->sales(
            new ReportWindowQuery(from: $from, to: $to),
            Request::create('/x', 'GET')
        );

        self::assertSame(200, $response->getStatusCode());

        return $this->json($response);
    }

    /** @return array<string,mixed> */
    private function callProducts(string $tenant, string $from = '2026-06-01', string $to = '2026-06-01'): array
    {
        $response = $this->controller($tenant)->products(
            new ProductsReportQuery(from: $from, to: $to),
            Request::create('/x', 'GET')
        );

        self::assertSame(200, $response->getStatusCode());

        return $this->json($response);
    }

    /** @return array<string,mixed> */
    private function callCustomers(string $tenant, string $from = '2026-06-01', string $to = '2026-06-01'): array
    {
        $response = $this->controller($tenant)->customers(
            new ReportWindowQuery(from: $from, to: $to),
            Request::create('/x', 'GET')
        );

        self::assertSame(200, $response->getStatusCode());

        return $this->json($response);
    }

    /** @return array<string,mixed> */
    private function callStock(string $tenant, ?string $status = null, ?int $threshold = null): array
    {
        $response = $this->controller($tenant)->stock(
            new StockReportQuery(status: $status, threshold: $threshold),
            Request::create('/x', 'GET')
        );

        self::assertSame(200, $response->getStatusCode());

        return $this->json($response);
    }

    /**
     * Dispatches through the real `RequireScopeMiddleware` with the exact
     * param string `routes.php` registers for every `reports/*` route
     * (`$read = 'require_scope:commerce:read'`, which the router splits into
     * a single `'commerce:read'` param -- see `Router::resolveMiddleware()`),
     * mirroring the framework's own
     * `RequireScopeMiddlewareTest::dispatchWithParams()` pattern.
     *
     * @param list<string>|null $grantedScopes null = the api_key_scopes attribute is absent
     */
    private function dispatchScoped(?array $grantedScopes, callable $next): HttpResponse
    {
        $request = Request::create('/x', 'GET');
        if ($grantedScopes !== null) {
            $request->attributes->set('api_key_scopes', $grantedScopes);
        }

        return (new RequireScopeMiddleware())->handle(
            $request,
            static fn (Request $r): HttpResponse => $next(),
            'commerce:read'
        );
    }

    private function controller(string $tenant): AdminReportController
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

    private function seedOrder(
        string $uuid,
        string $status,
        int $grandTotal,
        ?string $placedAt,
        ?string $createdAt = null,
        ?string $userUuid = null,
        string $email = 'buyer@example.com',
        string $tenant = self::TENANT_A,
    ): void {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'email' => $email,
            'user_uuid' => $userUuid,
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => $grandTotal,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => $grandTotal,
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
        string $tenant = self::TENANT_A,
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

    private function seedProduct(
        string $uuid,
        string $name = 'Widget',
        string $status = 'active',
        ?string $deletedAt = null,
        string $tenant = self::TENANT_A,
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
        string $tenant = self::TENANT_A,
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
        string $tenant = self::TENANT_A,
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
