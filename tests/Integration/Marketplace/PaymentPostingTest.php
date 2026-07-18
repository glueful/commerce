<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerException;
use Glueful\Extensions\Commerce\Marketplace\LedgerPostingService;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Payments\OrderPaymentConfirmationHandler;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\QueryLoggingPdoStatement;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmation;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Atomic payment posting (design spec §2.7, MV3 plan Task 6):
 * `OrderPaymentService::markPaid()` calls `LedgerPostingService::postSale()`
 * from INSIDE its own transaction -- after
 * `SellerOrderPaymentConfirmation::confirm()`, before the `OrderPaid`
 * after-commit -- for a `marketplace_partitioned` order only. Covers
 * `postSale()`'s own contract directly (field shape, zero-commission skip,
 * sorted lock claim order, replay idempotency) and the full `markPaid()`
 * wiring (sum reconciliation, both real callers, the non-partitioned
 * zero-query guarantee, and atomic rollback on a forced ledger failure).
 */
final class PaymentPostingTest extends CommerceTestCase
{
    private const TENANT = '';

    // -----------------------------------------------------------------
    // LedgerPostingService::postSale() directly (hand-built order/seller-order
    // shapes -- deterministic, no checkout wiring needed).
    // -----------------------------------------------------------------

    public function testPostSalePostsSaleCreditAndCommissionDebitWithCorrectFieldsAndSigns(): void
    {
        $orderUuid = 'orderHAND0001';
        $sellerUuid = 'sellerHAND001';
        $sellerOrderUuid = 'selordHAND001';

        $this->ledgerPostingService()->postSale($this->context, self::TENANT, ['uuid' => $orderUuid], [[
            'uuid' => $sellerOrderUuid,
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'attributed_total' => 4500,
            'commission_amount' => 450,
        ]]);

        $rows = $this->ledgerRowsForOrder($orderUuid);
        self::assertCount(2, $rows);

        $saleCredit = $this->ledgerRowByType($rows, 'sale_credit');
        self::assertSame('seller', $saleCredit['account_kind']);
        self::assertSame('seller:' . $sellerUuid, $saleCredit['account_key']);
        self::assertSame($sellerUuid, $saleCredit['seller_uuid']);
        self::assertSame('USD', $saleCredit['currency']);
        self::assertSame(4500, (int) $saleCredit['amount']);
        self::assertSame($orderUuid, $saleCredit['order_uuid']);
        self::assertSame($sellerOrderUuid, $saleCredit['seller_order_uuid']);
        self::assertSame($orderUuid . ':' . $sellerUuid . ':sale_credit', $saleCredit['idempotency_key']);

        $commissionDebit = $this->ledgerRowByType($rows, 'commission_debit');
        self::assertSame('seller', $commissionDebit['account_kind']);
        self::assertSame('seller:' . $sellerUuid, $commissionDebit['account_key']);
        self::assertSame(-450, (int) $commissionDebit['amount']);
        self::assertSame($orderUuid, $commissionDebit['order_uuid']);
        self::assertSame($sellerOrderUuid, $commissionDebit['seller_order_uuid']);
        self::assertSame($orderUuid . ':' . $sellerUuid . ':commission_debit', $commissionDebit['idempotency_key']);
    }

    public function testPostSaleSkipsCommissionDebitRowWhenCommissionAmountIsZero(): void
    {
        $orderUuid = 'orderHAND0002';

        $this->ledgerPostingService()->postSale($this->context, self::TENANT, ['uuid' => $orderUuid], [[
            'uuid' => 'selordHAND002',
            'seller_uuid' => 'sellerHAND002',
            'currency' => 'USD',
            'attributed_total' => 1200,
            'commission_amount' => 0,
        ]]);

        $rows = $this->ledgerRowsForOrder($orderUuid);
        self::assertCount(1, $rows, 'a zero commission_amount must post no commission_debit row');
        self::assertSame('sale_credit', $rows[0]['entry_type']);
        self::assertSame(1200, (int) $rows[0]['amount']);
    }

    public function testPostSaleClaimsAccountLocksInSortedAccountKeyOrderRegardlessOfInputOrder(): void
    {
        $orderUuid = 'orderHAND0003';

        // Deliberately scrambled input order -- proves the SORT happens
        // inside postSale() itself, never merely inherited from caller order.
        $this->ledgerPostingService()->postSale($this->context, self::TENANT, ['uuid' => $orderUuid], [
            [
                'uuid' => 'selordC0000001', 'seller_uuid' => 'sellerCCCC01', 'currency' => 'USD',
                'attributed_total' => 100, 'commission_amount' => 0,
            ],
            [
                'uuid' => 'selordA0000001', 'seller_uuid' => 'sellerAAAA01', 'currency' => 'USD',
                'attributed_total' => 200, 'commission_amount' => 0,
            ],
            [
                'uuid' => 'selordB0000001', 'seller_uuid' => 'sellerBBBB01', 'currency' => 'USD',
                'attributed_total' => 300, 'commission_amount' => 0,
            ],
        ]);

        $lockRows = $this->connection->table('commerce_ledger_account_locks')
            ->where('tenant_uuid', '=', self::TENANT)
            ->orderBy('id', 'ASC')
            ->get();

        self::assertCount(3, $lockRows);
        self::assertSame(
            ['seller:sellerAAAA01', 'seller:sellerBBBB01', 'seller:sellerCCCC01'],
            array_column($lockRows, 'account_key'),
            'locks must be claimed (and thus their anchor rows lazily created) in ascending account_key order'
        );
    }

    public function testPostSaleCalledTwiceWithIdenticalInputsPostsNoDuplicateRows(): void
    {
        $orderUuid = 'orderHAND0004';
        $sellerOrders = [[
            'uuid' => 'selordHAND004',
            'seller_uuid' => 'sellerHAND004',
            'currency' => 'USD',
            'attributed_total' => 800,
            'commission_amount' => 80,
        ]];

        $service = $this->ledgerPostingService();
        $service->postSale($this->context, self::TENANT, ['uuid' => $orderUuid], $sellerOrders);
        $service->postSale($this->context, self::TENANT, ['uuid' => $orderUuid], $sellerOrders);

        self::assertCount(
            2,
            $this->ledgerRowsForOrder($orderUuid),
            'a byte-identical replay must never create duplicate rows (1 sale_credit + 1 commission_debit)'
        );
    }

    // -----------------------------------------------------------------
    // Full markPaid() wiring: real checkout + payment posting.
    // -----------------------------------------------------------------

    public function testPartitionedPaidTransitionPostsLedgerEntriesThatReconcileWithTotals(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 1000,
        ]);
        $this->seedSeller('sellerBBBB01', 'Seller B', 'active', [
            'commission_kind' => 'fixed', 'commission_fixed' => 200,
        ]);
        $productX = $this->seedProduct('post-x', 5000, 'sellerAAAA01');
        $productY = $this->seedProduct('post-y', 3000, 'sellerBBBB01');

        $token = $this->cartWithLines([[$productX, 1], [$productY, 1]]);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $order = $placed['order'];
        self::assertTrue((bool) $order['marketplace_partitioned']);

        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        $sellerOrders = $this->sellerOrdersFor((string) $order['uuid']);
        self::assertCount(2, $sellerOrders);

        $ledgerRows = $this->ledgerRowsForOrder((string) $order['uuid']);
        self::assertCount(4, $ledgerRows, '2 sellers x (sale_credit + commission_debit), both have nonzero commission');

        $sumAttributedTotal = 0;
        $sumCommissionAmount = 0;
        foreach ($sellerOrders as $sellerOrder) {
            $sumAttributedTotal += (int) $sellerOrder['attributed_total'];
            $sumCommissionAmount += (int) $sellerOrder['commission_amount'];
        }

        $sumSaleCredit = 0;
        $sumCommissionDebit = 0;
        foreach ($ledgerRows as $row) {
            if ($row['entry_type'] === 'sale_credit') {
                $sumSaleCredit += (int) $row['amount'];
            }
            if ($row['entry_type'] === 'commission_debit') {
                $sumCommissionDebit += (int) $row['amount'];
            }
        }

        self::assertSame($sumAttributedTotal, $sumSaleCredit, 'sum of sale_credit must equal sum of attributed_total');
        self::assertSame(
            -$sumCommissionAmount,
            $sumCommissionDebit,
            'sum of commission_debit must equal negative sum of commission_amount'
        );

        $sellerA = $this->sellerOrderBySeller($sellerOrders, 'sellerAAAA01');
        $saleCreditA = $this->ledgerRowForSellerAndType($ledgerRows, 'sellerAAAA01', 'sale_credit');
        self::assertSame('seller', $saleCreditA['account_kind']);
        self::assertSame('seller:sellerAAAA01', $saleCreditA['account_key']);
        self::assertSame('USD', $saleCreditA['currency']);
        self::assertSame((string) $order['uuid'], $saleCreditA['order_uuid']);
        self::assertSame((string) $sellerA['uuid'], $saleCreditA['seller_order_uuid']);
        self::assertSame((int) $sellerA['attributed_total'], (int) $saleCreditA['amount']);
    }

    public function testNonPartitionedPaidTransitionExecutesZeroLedgerAndLockQueriesAndPostsNothing(): void
    {
        $order = $this->placeNonPartitionedOrder();
        self::assertFalse((bool) $order['marketplace_partitioned']);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryLoggingPdoStatement::class]);
        QueryLoggingPdoStatement::$queries = [];

        // Fully wired collaborators (confirmation, seller-order repo, ledger
        // posting) -- proving the gate is the order's OWN
        // marketplace_partitioned flag, never a missing collaborator.
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        self::assertNotEmpty(QueryLoggingPdoStatement::$queries, 'sanity: markPaid() must run some queries');
        foreach (QueryLoggingPdoStatement::$queries as $sql) {
            self::assertStringNotContainsString('commerce_marketplace_ledger', $sql);
            self::assertStringNotContainsString('commerce_ledger_account_locks', $sql);
        }

        self::assertSame('paid', $this->orderRow((string) $order['uuid'])['status']);
        self::assertSame(0, $this->connection->table('commerce_marketplace_ledger')->count());
        self::assertSame(0, $this->connection->table('commerce_ledger_account_locks')->count());
    }

    public function testAdminMarkPaidControllerPostsLedgerEntries(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 500,
        ]);
        $product = $this->seedProduct('admin-post-x', 4000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];

        $controller = new AdminOrderController(
            $this->context,
            new OrderRepository(),
            new StockRepository(),
            $this->paymentService(),
            new SentinelTenantResolver(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider()
        );

        $response = $controller->markPaid(
            Request::create('/commerce/admin/orders/' . $orderUuid . '/mark-paid', 'POST'),
            $orderUuid
        );

        self::assertSame(200, $response->getStatusCode());
        $rows = $this->ledgerRowsForOrder($orderUuid);
        self::assertCount(2, $rows, 'the admin mark-paid path must route through postSale() too');
        self::assertSame('sale_credit', $this->ledgerRowByType($rows, 'sale_credit')['entry_type']);
        self::assertSame('commission_debit', $this->ledgerRowByType($rows, 'commission_debit')['entry_type']);
    }

    public function testProviderConfirmationHandlerPostsLedgerEntries(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 500,
        ]);
        $product = $this->seedProduct('provider-post-x', 4000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];

        $handler = new OrderPaymentConfirmationHandler(
            new OrderRepository(),
            $this->paymentService(),
            new SentinelTenantResolver()
        );

        $handler->confirmed(
            $this->context,
            new PayableReference('commerce_order', $orderUuid, (int) $order['grand_total'], $order['currency']),
            new PaymentConfirmation('paid', 'ref-post-1', (int) $order['grand_total'], $order['currency'])
        );

        $rows = $this->ledgerRowsForOrder($orderUuid);
        self::assertCount(2, $rows, 'the provider payment-confirmation path must route through postSale() too');
    }

    public function testForcedLedgerFailureDuringPostingRollsBackThePaidCasAndAnyPartialPostings(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('forced-fail-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];

        $sellerOrder = $this->sellerOrdersFor($orderUuid)[0];
        $sellerUuid = (string) $sellerOrder['seller_uuid'];

        // Pre-seed a MISMATCHED ledger row under the EXACT idempotency key
        // postSale() will compute for this order's sale_credit -- forces
        // Task 5's verify() to throw a LedgerException mid-posting, inside
        // markPaid()'s own transaction.
        $this->connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => 'ledgerCONFLCT',
            'tenant_uuid' => self::TENANT,
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'account_kind' => 'seller',
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => 999999,
            'order_uuid' => $orderUuid,
            'seller_order_uuid' => (string) $sellerOrder['uuid'],
            'idempotency_key' => $orderUuid . ':' . $sellerUuid . ':sale_credit',
        ]);

        try {
            $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);
            self::fail('Expected the mismatched ledger replay to throw and roll back the whole transaction.');
        } catch (LedgerException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(
            'pending_payment',
            $this->orderRow($orderUuid)['status'],
            'the paid CAS must roll back together with the failed posting'
        );
        self::assertNull(
            $this->sellerOrdersFor($orderUuid)[0]['confirmed_at'],
            'the confirmation stamp must roll back too -- one shared transaction'
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_marketplace_ledger')->where('order_uuid', '=', $orderUuid)->count(),
            'only the one pre-seeded row may remain -- no legitimate postings persisted'
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_ledger_account_locks')->count(),
            'the account-lock claim must roll back with the rest of the transaction'
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function ledgerPostingService(): LedgerPostingService
    {
        return new LedgerPostingService(new LedgerRepository(), new LedgerAccountLock());
    }

    private function paymentService(
        ?SellerOrderPaymentConfirmation $confirmation = new SellerOrderPaymentConfirmation(),
        ?callable $afterPaidHook = null,
    ): OrderPaymentService {
        return new OrderPaymentService(
            new OrderRepository(),
            $confirmation,
            $afterPaidHook,
            new SellerOrderRepository(),
            $this->ledgerPostingService()
        );
    }

    /** @return array<string,mixed> the placed parent order */
    private function placeOneSellerOrder(array $product): array
    {
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->checkout()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        return $placed['order'];
    }

    /** @return array<string,mixed> the placed parent order */
    private function placeNonPartitionedOrder(): array
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->tenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => 'non-partitioned-post-x',
            'name' => 'non-partitioned-post-x',
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => 'NONPARTPOSTX',
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
        (new StockRepository())->increment($this->context, self::TENANT, (string) $product['variants'][0]['uuid'], 10);

        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->checkoutPlain()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        return $placed['order'];
    }

    private function checkout(): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), $this->tenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->fakeShipping(),
            $this->aggregateTax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            $this->tenantResolver(),
            new MarketplaceMode(),
            new SellerRepository(),
            new ProductRepository(),
            new SellerOrderRepository()
        );
    }

    private function checkoutPlain(): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), $this->tenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->fakeShipping(),
            $this->aggregateTax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            $this->tenantResolver()
        );
    }

    private function cart(): CartService
    {
        return new CartService(
            new CartRepository(),
            new VariantRepository(),
            new ProductRepository(),
            new StockRepository(),
            new DiscountRepository(),
            new PricingEngine(),
            $this->tenantResolver()
        );
    }

    private function tenantResolver(): CurrentTenantResolver
    {
        return new SentinelTenantResolver();
    }

    private function activateMarketplace(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsettings1',
            'tenant_uuid' => self::TENANT,
            'status' => 'active',
        ]);
    }

    /** @param array{commission_kind?:string,commission_bps?:int,commission_fixed?:int} $commission */
    private function seedSeller(string $uuid, string $name, string $status = 'active', array $commission = []): void
    {
        $this->connection->table('commerce_sellers')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'slug' => strtolower($uuid),
            'name' => $name,
            'status' => $status,
        ], $commission));
    }

    /** @return array<string,mixed> */
    private function seedProduct(string $slug, int $price, string $sellerUuid, int $stock = 100): array
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->tenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '', $slug)),
                'option_values' => [],
                'price' => $price,
                'currency' => 'USD',
            ]],
        ]);
        (new StockRepository())->increment(
            $this->context,
            self::TENANT,
            (string) $product['variants'][0]['uuid'],
            $stock
        );

        $this->connection->table('commerce_products')
            ->where('uuid', '=', $product['uuid'])
            ->update(['seller_uuid' => $sellerUuid]);

        return $product;
    }

    private function fakeShipping(): ShippingRateProvider
    {
        return new class implements ShippingRateProvider {
            public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
            {
                return [new ShippingQuote('std', 'Standard', 500)];
            }
        };
    }

    private function aggregateTax(): TaxCalculator
    {
        return new class implements TaxCalculator {
            public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
            {
                return new TaxQuote(0);
            }
        };
    }

    /** @return array{email: string, user_uuid: null} */
    private function buyer(): array
    {
        return ['email' => 'buyer@example.com', 'user_uuid' => null];
    }

    /** @return array{shipping: array{country: string}, billing: array{country: string}} */
    private function addresses(): array
    {
        return ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']];
    }

    /**
     * @param list<array{0:array<string,mixed>,1:int}> $productsAndQuantities
     * @return string cart token
     */
    private function cartWithLines(array $productsAndQuantities): string
    {
        $cartService = $this->cart();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        foreach ($productsAndQuantities as [$product, $quantity]) {
            $cart = $cartService->addLine(
                $this->context,
                $cart,
                (string) $product['variants'][0]['uuid'],
                $quantity
            );
        }

        return $token;
    }

    /** @return array<string,mixed> */
    private function orderRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $uuid)->first();
        self::assertNotNull($row);

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function sellerOrdersFor(string $orderUuid): array
    {
        return $this->connection->table('commerce_seller_orders')
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('partition_number', 'ASC')
            ->get();
    }

    /** @param list<array<string,mixed>> $sellerOrders @return array<string,mixed> */
    private function sellerOrderBySeller(array $sellerOrders, string $sellerUuid): array
    {
        foreach ($sellerOrders as $row) {
            if ((string) $row['seller_uuid'] === $sellerUuid) {
                return $row;
            }
        }

        self::fail("No seller order for seller '{$sellerUuid}'.");
    }

    /** @return list<array<string,mixed>> */
    private function ledgerRowsForOrder(string $orderUuid): array
    {
        return $this->connection->table('commerce_marketplace_ledger')
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function ledgerRowByType(array $rows, string $entryType): array
    {
        foreach ($rows as $row) {
            if ((string) $row['entry_type'] === $entryType) {
                return $row;
            }
        }

        self::fail("No ledger row of entry_type '{$entryType}'.");
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function ledgerRowForSellerAndType(array $rows, string $sellerUuid, string $entryType): array
    {
        foreach ($rows as $row) {
            if ((string) $row['seller_uuid'] === $sellerUuid && (string) $row['entry_type'] === $entryType) {
                return $row;
            }
        }

        self::fail("No ledger row for seller '{$sellerUuid}' entry_type '{$entryType}'.");
    }
}
