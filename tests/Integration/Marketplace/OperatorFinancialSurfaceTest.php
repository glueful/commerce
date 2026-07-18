<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Auth\ApiKey\Exceptions\InsufficientScopeException;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\AdminMarketplaceFinancialController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\DTOs\SellerFinancialReportQuery;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentService;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Reports\SellerFinancialReportRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Routing\Middleware\RequireScopeMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Operator marketplace financial surfaces (design spec §6.1, MV3 Task 11):
 * the marketplace account's own summary, any seller's balance/report, and
 * the seller-order breakdown's new `commission_amount`/`net` fields --
 * exercised in-process (direct controller construction + method calls),
 * mirroring {@see \Glueful\Extensions\Commerce\Tests\Integration\Http\ReportTenancyTest}'s
 * convention for `AdminReportController` (there is no router-based admin
 * test harness in this repo -- see that file's own docblock/`dispatchScoped()`
 * for the established pattern this mirrors, including scope-gate coverage).
 *
 * Ledger fixtures are posted through the REAL {@see AdjustmentService}/
 * {@see LedgerRepository} rather than raw inserts, since these tests exist
 * to prove the OPERATOR SURFACE's wiring (does it read the right account?
 * does it enumerate every currency?), not to re-prove posting/windowing
 * math already covered by {@see PaymentPostingTest}/{@see AdjustmentTest}/
 * {@see \Glueful\Extensions\Commerce\Tests\Integration\Marketplace\SellerFinancialSurfaceTest}.
 */
final class OperatorFinancialSurfaceTest extends CommerceTestCase
{
    private const TENANT = 'opfinTENANT1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
    }

    // -----------------------------------------------------------------
    // Marketplace-account summary, across every currency it holds.
    // -----------------------------------------------------------------

    public function testMarketplaceSummaryAggregatesAcrossEveryCurrency(): void
    {
        $this->adjustmentService()->post(
            $this->context,
            self::TENANT,
            LedgerRepository::MARKETPLACE_ACCOUNT_KEY,
            'USD',
            1500,
            'seed marketplace USD',
            'idem-opfin-mkt-usd',
            'operatorOPFIN1'
        );
        $this->adjustmentService()->post(
            $this->context,
            self::TENANT,
            LedgerRepository::MARKETPLACE_ACCOUNT_KEY,
            'EUR',
            700,
            'seed marketplace EUR',
            'idem-opfin-mkt-eur',
            'operatorOPFIN1'
        );

        $response = $this->controller()->marketplaceSummary(Request::create('/x', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $balances = $this->json($response)['data']['balances'];
        self::assertCount(2, $balances);

        $byCurrency = [];
        foreach ($balances as $entry) {
            $byCurrency[$entry['currency']] = $entry;
        }
        self::assertSame(1500, $byCurrency['USD']['available']);
        self::assertSame(1500, $byCurrency['USD']['adjustments']);
        self::assertSame(700, $byCurrency['EUR']['available']);
    }

    public function testMarketplaceSummaryWithNoActivityIsAnEmptyList(): void
    {
        $response = $this->controller()->marketplaceSummary(Request::create('/x', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->json($response)['data']['balances']);
    }

    public function testMarketplaceSummaryRequiresReadScopeRejectsWriteOnlyToken(): void
    {
        $allowed = $this->dispatchScoped(
            ['commerce:read'],
            fn (): HttpResponse => $this->controller()->marketplaceSummary(Request::create('/x', 'GET'))
        );
        self::assertSame(200, $allowed->getStatusCode());

        $this->expectException(InsufficientScopeException::class);
        $this->dispatchScoped(
            ['commerce:write'],
            fn (): HttpResponse => $this->controller()->marketplaceSummary(Request::create('/x', 'GET'))
        );
    }

    // -----------------------------------------------------------------
    // Operator can read ANY seller's balance / financial report.
    // -----------------------------------------------------------------

    public function testOperatorReadsAnySellerBalance(): void
    {
        $this->seedSeller('sellerOPFIN01', 'Seller OpFin');
        $this->seedLedgerSale('sellerOPFIN01', 10000);
        $this->postLedgerEntry('sellerOPFIN01', 'commission_debit', -1000, 'orderOPFIN0001');

        $response = $this->controller()->sellerBalance(Request::create('/x', 'GET'), 'sellerOPFIN01');

        self::assertSame(200, $response->getStatusCode());
        $balances = $this->json($response)['data']['balances'];
        self::assertCount(1, $balances);
        self::assertSame('USD', $balances[0]['currency']);
        self::assertSame(9000, $balances[0]['available']);
        self::assertSame(10000, $balances[0]['gross_sales']);
        self::assertSame(1000, $balances[0]['commission']);
    }

    public function testOperatorSellerBalanceForUnknownSellerIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->sellerBalance(Request::create('/x', 'GET'), 'doesNotExist01');
    }

    public function testOperatorReadsAnySellerFinancialReport(): void
    {
        $this->seedSeller('sellerOPFIN02', 'Seller OpFin 2');
        $this->seedLedgerSale('sellerOPFIN02', 8000);
        $this->postLedgerEntry('sellerOPFIN02', 'commission_debit', -800, 'orderOPFIN0002');

        $response = $this->controller()->sellerReport(
            new SellerFinancialReportQuery(currency: 'USD'),
            Request::create('/x', 'GET'),
            'sellerOPFIN02'
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response)['data'];
        self::assertSame('sellerOPFIN02', $body['seller_uuid']);
        self::assertSame('USD', $body['currency']);
        self::assertSame(8000, $body['summary']['gross_minor']);
        self::assertSame(800, $body['summary']['commission_minor']);
        self::assertSame(7200, $body['summary']['net_minor']);
        self::assertSame(7200, $body['summary']['balance_minor']);
    }

    public function testOperatorSellerReportForUnknownSellerIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->sellerReport(
            new SellerFinancialReportQuery(currency: 'USD'),
            Request::create('/x', 'GET'),
            'doesNotExist01'
        );
    }

    // -----------------------------------------------------------------
    // Order / seller-order breakdown gains commission + net.
    // -----------------------------------------------------------------

    public function testAdminOrderSellerOrderBreakdownIncludesCommissionAndNet(): void
    {
        $order = $this->seedOrder();
        $this->seedSellerOrderRow($order['uuid'], 'sellerOPFIN03', [
            'attributed_total' => 1000,
            'commission_amount' => 150,
        ]);

        $response = $this->adminOrderController()->show(
            Request::create('/commerce/admin/orders/' . $order['uuid'], 'GET'),
            (string) $order['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $row = $this->json($response)['data']['seller_orders'][0];
        self::assertSame(1000, $row['attributed_total']);
        self::assertSame(150, $row['commission_amount']);
        self::assertSame(850, $row['net']);
    }

    // -----------------------------------------------------------------
    // Fixtures + helpers.
    // -----------------------------------------------------------------

    private function controller(): AdminMarketplaceFinancialController
    {
        $ledger = new LedgerRepository();

        return new AdminMarketplaceFinancialController(
            $this->context,
            new SellerBalanceService($ledger),
            $ledger,
            new SellerFinancialReportRepository(),
            new SellerRepository(),
            $this->tenantResolver()
        );
    }

    private function adminOrderController(): AdminOrderController
    {
        return new AdminOrderController(
            $this->context,
            new OrderRepository(),
            new StockRepository(),
            new OrderPaymentService(new OrderRepository(), new SellerOrderPaymentConfirmation()),
            $this->tenantResolver(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider(),
            new SellerOrderRepository(),
            new SellerOrderFulfillmentService(new OrderRepository(), new SellerOrderRepository())
        );
    }

    private function adjustmentService(): AdjustmentService
    {
        return new AdjustmentService(new LedgerRepository(), new LedgerAccountLock());
    }

    private function tenantResolver(): CurrentTenantResolver
    {
        $tenant = self::TENANT;

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

    private function seedSeller(string $uuid, string $name, string $status = 'active'): void
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'slug' => strtolower($uuid),
            'name' => $name,
            'status' => $status,
        ]);
    }

    /** Directly posts a sale_credit so a balance/report test has data without a full checkout. */
    private function seedLedgerSale(string $sellerUuid, int $amount): void
    {
        (new LedgerRepository())->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => $amount,
            'order_uuid' => 'orderOPFINSALE',
            'idempotency_key' => 'orderOPFINSALE:' . $sellerUuid . ':sale_credit',
        ]);
    }

    private function postLedgerEntry(string $sellerUuid, string $entryType, int $amount, string $orderUuid): void
    {
        (new LedgerRepository())->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'entry_type' => $entryType,
            'amount' => $amount,
            'order_uuid' => $orderUuid,
            'idempotency_key' => $orderUuid . ':' . $sellerUuid . ':' . $entryType,
        ]);
    }

    /** @return array<string,mixed> */
    private function seedOrder(): array
    {
        $uuid = Utils::generateNanoID();
        $now = gmdate('Y-m-d H:i:s');

        $order = [
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => true,
            'fulfillment_revision' => 0,
            'email' => 'buyer@example.com',
            'user_uuid' => null,
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'refunded_total' => 0,
            'refund_revision' => 0,
            'discount_code' => null,
            'shipping_method' => 'std',
            'addresses' => json_encode(['shipping' => ['country' => 'US']], JSON_THROW_ON_ERROR),
            'metadata' => null,
            'placed_at' => $now,
            'created_at' => $now,
        ];

        $this->connection->table('commerce_orders')->insert($order);

        return $order;
    }

    /** @param array<string,mixed> $overrides */
    private function seedSellerOrderRow(string $orderUuid, string $sellerUuid, array $overrides = []): void
    {
        $uuid = Utils::generateNanoID();
        $now = gmdate('Y-m-d H:i:s');

        $defaults = [
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'order_uuid' => $orderUuid,
            'seller_uuid' => $sellerUuid,
            'seller_name_snapshot' => 'Seller',
            'partition_number' => 1,
            'seller_reference' => 'ORD-' . $orderUuid . '-1',
            'currency' => 'USD',
            'subtotal' => 1000,
            'allocated_discount' => 0,
            'allocated_shipping_discount' => 0,
            'allocated_shipping' => 0,
            'allocated_tax' => 0,
            'attributed_total' => 1000,
            'commission_amount' => 0,
            'tax_attribution_method' => 'aggregate_allocated',
            'confirmed_at' => $now,
            'fulfillment_status' => 'unfulfilled',
            'fulfilled_at' => null,
            'carrier' => null,
            'tracking_number' => null,
            'tracking_url' => null,
            'status' => 'open',
            'revision' => 0,
            'created_at' => $now,
        ];

        $this->connection->table('commerce_seller_orders')->insert(array_merge($defaults, $overrides));
    }

    /**
     * Dispatches through the real `RequireScopeMiddleware` with the exact
     * param string `routes.php` registers for every `/commerce/admin/marketplace/*`
     * route (`$read = 'require_scope:commerce:read'`), mirroring
     * {@see \Glueful\Extensions\Commerce\Tests\Integration\Http\ReportTenancyTest::dispatchScoped()}.
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

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
