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
use Glueful\Extensions\Commerce\Contracts\LineTaxCalculator;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\DTOs\CheckoutPlaceData;
use Glueful\Extensions\Commerce\Http\Storefront\CheckoutController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\CheckoutConflictException;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxBreakdown;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\QueryLoggingPdoStatement;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * Partitioned checkout write path (design spec §2.7, MV2 plan Task 5):
 * `CheckoutService::placeOrder()`'s marketplace branch -- the transfer-safe
 * claim protocol, per-line/per-seller attribution, `commerce_seller_orders`
 * writes, and the immutable `marketplace_partitioned` marker -- proved end
 * to end against a real, fully-wired `CheckoutService` (mirrors
 * `tests/Integration/Orders/CheckoutTest.php`'s collaborator wiring).
 */
final class CheckoutPartitionTest extends CommerceTestCase
{
    private const TENANT = '';

    // -----------------------------------------------------------------
    // 1. Value discount + line_detailed tax, two sellers.
    // -----------------------------------------------------------------

    public function testPartitionedCheckoutWithValueDiscountAndLineDetailedTaxReconciles(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('value-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('value-y', 3000, 'sellerBBBB01');
        $this->seedDiscount('SAVE10', 'percentage', 1000);

        [$token, $cart, $lineUuidByProduct] = $this->cartWithTwoLines($productX, $productY, 2, 1, 'SAVE10');

        $placed = $this->checkout($this->lineDetailedTax())
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        $order = $placed['order'];
        self::assertTrue((bool) $order['marketplace_partitioned']);
        self::assertSame(5000, (int) $order['subtotal']);
        self::assertSame(500, (int) $order['discount_total']);
        self::assertSame(500, (int) $order['shipping_total']);
        self::assertSame(500, (int) $order['tax_total']);
        self::assertSame(5500, (int) $order['grand_total']);

        $lines = $this->linesForOrder((string) $order['uuid']);
        self::assertCount(2, $lines);
        $lineX = $this->lineBySku($lines, 'VALUEX');
        $lineY = $this->lineBySku($lines, 'VALUEY');
        self::assertSame('sellerAAAA01', $lineX['seller_uuid']);
        self::assertSame('sellerBBBB01', $lineY['seller_uuid']);
        self::assertSame(200, (int) $lineX['discount_amount']);
        self::assertSame(300, (int) $lineY['discount_amount']);
        self::assertSame(180, (int) $lineX['tax_amount']);
        self::assertSame(270, (int) $lineY['tax_amount']);
        // §2.11: the persisted order line's own uuid is the cart line's uuid.
        self::assertSame($lineUuidByProduct[$productX['uuid']], $lineX['uuid']);
        self::assertSame($lineUuidByProduct[$productY['uuid']], $lineY['uuid']);

        $sellerOrders = $this->sellerOrdersFor((string) $order['uuid']);
        self::assertCount(2, $sellerOrders);
        [$a, $b] = $sellerOrders;
        self::assertSame('sellerAAAA01', $a['seller_uuid']);
        self::assertSame(1, (int) $a['partition_number']);
        self::assertSame($order['order_number'] . '-1', $a['seller_reference']);
        self::assertSame('sellerBBBB01', $b['seller_uuid']);
        self::assertSame(2, (int) $b['partition_number']);
        self::assertSame($order['order_number'] . '-2', $b['seller_reference']);

        self::assertSame(2000, (int) $a['subtotal']);
        self::assertSame(200, (int) $a['allocated_discount']);
        self::assertSame(0, (int) $a['allocated_shipping_discount']);
        self::assertSame(200, (int) $a['allocated_shipping']);
        self::assertSame(200, (int) $a['allocated_tax']);
        self::assertSame(2200, (int) $a['attributed_total']);
        self::assertSame('line_detailed', $a['tax_attribution_method']);
        self::assertNull($a['confirmed_at']);
        self::assertSame('unfulfilled', $a['fulfillment_status']);
        self::assertSame('open', $a['status']);

        self::assertSame(3000, (int) $b['subtotal']);
        self::assertSame(300, (int) $b['allocated_discount']);
        self::assertSame(300, (int) $b['allocated_shipping']);
        self::assertSame(300, (int) $b['allocated_tax']);
        self::assertSame(3300, (int) $b['attributed_total']);
        self::assertSame('line_detailed', $b['tax_attribution_method']);

        // §2.5 exact reconciliation invariants.
        self::assertSame((int) $order['subtotal'], $this->sumColumn($sellerOrders, 'subtotal'));
        self::assertSame((int) $order['discount_total'], $this->sumColumn($sellerOrders, 'allocated_discount'));
        self::assertSame((int) $order['shipping_total'], $this->sumColumn($sellerOrders, 'allocated_shipping'));
        self::assertSame((int) $order['tax_total'], $this->sumColumn($sellerOrders, 'allocated_tax'));
        self::assertSame((int) $order['grand_total'], $this->sumColumn($sellerOrders, 'attributed_total'));
    }

    // -----------------------------------------------------------------
    // 2. Free-shipping discount + aggregate_allocated tax, two sellers.
    // -----------------------------------------------------------------

    public function testPartitionedCheckoutWithFreeShippingDiscountAllocatesShippingWaiverPerSeller(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('free-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('free-y', 3000, 'sellerBBBB01');
        $this->seedDiscount('FREESHIP', 'free_shipping', 0);

        [$token] = $this->cartWithTwoLines($productX, $productY, 2, 1, 'FREESHIP');

        $placed = $this->checkout($this->aggregateTax())
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        $order = $placed['order'];
        self::assertTrue((bool) $order['marketplace_partitioned']);
        self::assertSame(500, (int) $order['discount_total']);
        self::assertSame(0, (int) $order['shipping_total']);

        $lines = $this->linesForOrder((string) $order['uuid']);
        foreach ($lines as $line) {
            self::assertSame(0, (int) $line['discount_amount'], 'free-shipping never allocates per-line discount');
        }

        $sellerOrders = $this->sellerOrdersFor((string) $order['uuid']);
        self::assertCount(2, $sellerOrders);
        foreach ($sellerOrders as $row) {
            self::assertSame(0, (int) $row['allocated_discount']);
            self::assertSame(0, (int) $row['allocated_shipping']);
            self::assertSame('aggregate_allocated', $row['tax_attribution_method']);
        }

        self::assertSame(
            (int) $order['discount_total'],
            $this->sumColumn($sellerOrders, 'allocated_shipping_discount')
        );
        self::assertSame((int) $order['subtotal'], $this->sumColumn($sellerOrders, 'subtotal'));
        self::assertSame((int) $order['tax_total'], $this->sumColumn($sellerOrders, 'allocated_tax'));
        self::assertSame((int) $order['grand_total'], $this->sumColumn($sellerOrders, 'attributed_total'));
    }

    // -----------------------------------------------------------------
    // 3. No discount, single seller, aggregate_allocated tax.
    // -----------------------------------------------------------------

    public function testPartitionedCheckoutWithNoDiscountSingleSeller(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('none-x', 2000, 'sellerAAAA01');

        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->checkout($this->aggregateTax())
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        $order = $placed['order'];
        self::assertTrue((bool) $order['marketplace_partitioned']);
        self::assertSame(0, (int) $order['discount_total']);

        $sellerOrders = $this->sellerOrdersFor((string) $order['uuid']);
        self::assertCount(1, $sellerOrders);
        $row = $sellerOrders[0];
        self::assertSame(1, (int) $row['partition_number']);
        self::assertSame($order['order_number'] . '-1', $row['seller_reference']);
        self::assertSame(0, (int) $row['allocated_discount']);
        self::assertSame(0, (int) $row['allocated_shipping_discount']);
        self::assertSame((int) $order['shipping_total'], (int) $row['allocated_shipping']);
        self::assertSame((int) $order['tax_total'], (int) $row['allocated_tax']);
        self::assertSame((int) $order['grand_total'], (int) $row['attributed_total']);
        self::assertSame('aggregate_allocated', $row['tax_attribution_method']);
    }

    // -----------------------------------------------------------------
    // 4. Non-partitioned: master switch off -- zero seller-table reads.
    // -----------------------------------------------------------------

    public function testNonPartitionedCheckoutWithMasterSwitchOffWritesNoSellerRowsAndReadsZeroSellerTables(): void
    {
        $product = $this->seedProduct('legacy-x', 1000, null);

        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryLoggingPdoStatement::class]);
        QueryLoggingPdoStatement::$queries = [];

        $placed = $this->checkout($this->aggregateTax())
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        self::assertNotEmpty(QueryLoggingPdoStatement::$queries, 'sanity: placeOrder() must run some queries');
        $this->assertNoSellerTableQueries(QueryLoggingPdoStatement::$queries);

        self::assertFalse((bool) $placed['order']['marketplace_partitioned']);
        self::assertSame(0, $this->connection->table('commerce_seller_orders')->count());
    }

    // -----------------------------------------------------------------
    // 5. Non-partitioned: installed but inactive workspace.
    // -----------------------------------------------------------------

    public function testInstalledButInactiveWorkspaceCheckoutWritesNoSellerRows(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
        $product = $this->seedProduct('inactive-x', 1000, null);

        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryLoggingPdoStatement::class]);
        QueryLoggingPdoStatement::$queries = [];

        $placed = $this->checkout($this->aggregateTax())
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        // installEnabled() is true here, so activeFor() legitimately reads
        // commerce_marketplace_settings ONCE to learn the workspace is
        // inactive -- but no seller/seller-order table is ever touched.
        foreach (['commerce_sellers', 'commerce_seller_memberships', 'commerce_seller_orders'] as $table) {
            foreach (QueryLoggingPdoStatement::$queries as $sql) {
                self::assertStringNotContainsString($table, $sql, "must never query {$table} while inactive");
            }
        }

        self::assertFalse((bool) $placed['order']['marketplace_partitioned']);
        self::assertSame(0, $this->connection->table('commerce_seller_orders')->count());
    }

    // -----------------------------------------------------------------
    // 6. Ownership drift: one transient conflict retries and succeeds.
    // -----------------------------------------------------------------

    public function testTransientOwnershipDriftRetriesOnceThenSucceeds(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $product = $this->seedProduct('drift-x', 1000, 'sellerAAAA01');

        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $calls = 0;
        $hook = function (ApplicationContext $c, string $tenant, array $productUuids) use (&$calls, $product): void {
            $calls++;
            if ($calls === 1) {
                // Simulated competing transfer landing inside THIS attempt's
                // own uncommitted transaction (single-connection stand-in --
                // see SellerAttributionTest's identical convention): the
                // re-read observes it, the whole attempt rolls back
                // (undoing this write too), and the retry starts fresh.
                $this->connection->table('commerce_products')
                    ->where('uuid', '=', $product['uuid'])
                    ->update(['seller_uuid' => 'sellerBBBB01']);
            }
        };

        $placed = $this->checkout($this->aggregateTax(), $hook)
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        self::assertSame(2, $calls, 'exactly one retry: the snapshot hook fires once per attempt');
        self::assertTrue((bool) $placed['order']['marketplace_partitioned']);
        $lines = $this->linesForOrder((string) $placed['order']['uuid']);
        self::assertSame('sellerAAAA01', $lines[0]['seller_uuid'], 'the durable, post-rollback seller wins');
        self::assertCount(1, $this->sellerOrdersFor((string) $placed['order']['uuid']));
    }

    // -----------------------------------------------------------------
    // 7. Ownership drift: a second drift is a controlled 409.
    // -----------------------------------------------------------------

    public function testDeterministicOwnershipDriftOnBothAttemptsThrows409CheckoutConflict(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $product = $this->seedProduct('drift-y', 1000, 'sellerAAAA01');

        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        // Every attempt starts from a fresh rollback (product back to seller
        // A), so unconditionally mutating to B on EVERY hook call reproduces
        // drift deterministically on both attempts.
        $calls = 0;
        $hook = function (ApplicationContext $c, string $tenant, array $productUuids) use (&$calls, $product): void {
            $calls++;
            $this->connection->table('commerce_products')
                ->where('uuid', '=', $product['uuid'])
                ->update(['seller_uuid' => 'sellerBBBB01']);
        };

        try {
            $this->checkout($this->aggregateTax(), $hook)
                ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
            self::fail('Expected CheckoutConflictException.');
        } catch (CheckoutConflictException $e) {
            self::assertSame('checkout_conflict', $e->errorCode);
        }

        self::assertSame(2, $calls, 'both attempts were spent before giving up');
        self::assertSame(0, $this->connection->table('commerce_orders')->count());
        self::assertSame(0, $this->connection->table('commerce_seller_orders')->count());
    }

    // -----------------------------------------------------------------
    // 8. Inactive participating seller: immediate 409, never retried.
    // -----------------------------------------------------------------

    public function testInactiveParticipatingSellerReturns409Immediately(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'suspended');
        $product = $this->seedProduct('inactive-seller-x', 1000, 'sellerAAAA01');

        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $calls = 0;
        $hook = function () use (&$calls): void {
            $calls++;
        };

        try {
            $this->checkout($this->aggregateTax(), $hook)
                ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
            self::fail('Expected CheckoutConflictException.');
        } catch (CheckoutConflictException $e) {
            self::assertSame('checkout_conflict', $e->errorCode);
        }

        self::assertSame(1, $calls, 'an inactive seller is never retried');
        self::assertSame(0, $this->connection->table('commerce_orders')->count());
        $cartRow = $this->connection->table('commerce_carts')->where('uuid', '=', $cart['uuid'])->first();
        self::assertSame('active', $cartRow['status'], 'the whole transaction (cart claim included) rolls back');
    }

    /**
     * The checkout conflict must surface through the HTTP boundary as a 409 with
     * the `checkout_conflict` code -- the service-layer tests above catch the
     * exception directly, so this drives {@see CheckoutController::place()} to
     * prove the controller maps it (regression: it previously escaped as a 500).
     */
    public function testInactiveSellerSurfacesAsHttp409ThroughCheckoutController(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'suspended');
        $product = $this->seedProduct('inactive-seller-http', 1000, 'sellerAAAA01');

        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $controller = new CheckoutController($this->context, $this->cart(), $this->checkout($this->aggregateTax()));
        $request = \Symfony\Component\HttpFoundation\Request::create('/commerce/checkout', 'POST');
        $request->headers->set('X-Cart-Token', $token);

        $response = $controller->place(
            new CheckoutPlaceData(buyer: $this->buyer(), addresses: $this->addresses(), shipping_method: 'std'),
            $request
        );

        self::assertSame(409, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('checkout_conflict', $body['error']['details']['code']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function checkout(?TaxCalculator $tax = null, ?callable $afterOwnershipSnapshotHook = null): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), $this->tenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->fakeShipping(),
            $tax ?? $this->aggregateTax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            $this->tenantResolver(),
            new MarketplaceMode(),
            new SellerRepository(),
            new ProductRepository(),
            new SellerOrderRepository(),
            $afterOwnershipSnapshotHook
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

    /** @return array<string,mixed> */
    private function seedProduct(string $slug, int $price, ?string $sellerUuid, int $stock = 100): array
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

        if ($sellerUuid !== null) {
            $this->connection->table('commerce_products')
                ->where('uuid', '=', $product['uuid'])
                ->update(['seller_uuid' => $sellerUuid]);
        }

        return $product;
    }

    private function seedDiscount(string $code, string $type, int $value): void
    {
        $this->connection->table('commerce_discounts')->insert([
            'uuid' => 'disc' . substr(md5($code), 0, 8),
            'tenant_uuid' => self::TENANT,
            'code' => $code,
            'type' => $type,
            'value' => $value,
            'usage_limit' => null,
            'once_per_buyer' => 0,
            'usage_count' => 0,
            'status' => 'active',
        ]);
    }

    /**
     * Builds a cart with two lines against two different products (one per
     * seller), optionally applies a discount code, and returns
     * `[token, cart, lineUuidByProductUuid]`.
     *
     * @param array<string,mixed> $productX
     * @param array<string,mixed> $productY
     * @return array{0:string,1:array<string,mixed>,2:array<string,string>}
     */
    private function cartWithTwoLines(
        array $productX,
        array $productY,
        int $qtyX,
        int $qtyY,
        ?string $discountCode = null
    ): array {
        $cartService = $this->cart();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cart = $cartService->addLine($this->context, $cart, (string) $productX['variants'][0]['uuid'], $qtyX);
        $cart = $cartService->addLine($this->context, $cart, (string) $productY['variants'][0]['uuid'], $qtyY);

        if ($discountCode !== null) {
            $cart = $cartService->applyDiscount($this->context, $cart, $discountCode);
        }

        $lineUuidByProduct = [];
        foreach ($cartService->pricedLines($this->context, $cart) as $line) {
            $lineUuidByProduct[(string) $line['product_uuid']] = (string) $line['line_uuid'];
        }

        return [$token, $cart, $lineUuidByProduct];
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

    /** Flat 10% (basis points), aggregate only -- no {@see TaxBreakdown}. */
    private function aggregateTax(): TaxCalculator
    {
        return new class implements TaxCalculator {
            public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
            {
                return new TaxQuote(intdiv($taxableAmount * 1000 + 5000, 10000));
            }
        };
    }

    /** Flat 10% (basis points), per line -- produces a {@see TaxBreakdown}. */
    private function lineDetailedTax(): TaxCalculator
    {
        return new class implements TaxCalculator, LineTaxCalculator {
            public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
            {
                return new TaxQuote(intdiv($taxableAmount * 1000 + 5000, 10000));
            }

            public function quoteDetailed(
                ApplicationContext $context,
                array $taxableLines,
                int $shippingAmount,
                array $shippingAddress
            ): TaxQuote {
                $taxByLine = [];
                $knownLineUuids = [];
                $total = 0;
                foreach ($taxableLines as $line) {
                    $lineUuid = (string) $line['line_uuid'];
                    $knownLineUuids[] = $lineUuid;
                    $tax = intdiv((int) $line['taxable_amount'] * 1000 + 5000, 10000);
                    $taxByLine[$lineUuid] = $tax;
                    $total += $tax;
                }
                $shippingTax = intdiv($shippingAmount * 1000 + 5000, 10000);
                $total += $shippingTax;

                return new TaxQuote($total, 'Tax', new TaxBreakdown($taxByLine, $shippingTax, $knownLineUuids));
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

    /** @return list<array<string,mixed>> */
    private function linesForOrder(string $orderUuid): array
    {
        return $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /** @param list<array<string,mixed>> $lines @return array<string,mixed> */
    private function lineBySku(array $lines, string $sku): array
    {
        foreach ($lines as $line) {
            if ((string) $line['sku'] === $sku) {
                return $line;
            }
        }

        self::fail("No order line with sku '{$sku}'.");
    }

    /** @return list<array<string,mixed>> */
    private function sellerOrdersFor(string $orderUuid): array
    {
        return $this->connection->table('commerce_seller_orders')
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('partition_number', 'ASC')
            ->get();
    }

    /** @param list<array<string,mixed>> $rows */
    private function sumColumn(array $rows, string $column): int
    {
        $sum = 0;
        foreach ($rows as $row) {
            $sum += (int) $row[$column];
        }

        return $sum;
    }

    /** @param list<string> $queries */
    private function assertNoSellerTableQueries(array $queries): void
    {
        $sellerTables = [
            'commerce_sellers',
            'commerce_seller_memberships',
            'commerce_marketplace_settings',
            'commerce_seller_orders',
        ];
        foreach ($queries as $sql) {
            foreach ($sellerTables as $table) {
                self::assertStringNotContainsString(
                    $table,
                    $sql,
                    "the master-off checkout path must issue ZERO {$table} queries; saw: {$sql}"
                );
            }
        }
    }
}
