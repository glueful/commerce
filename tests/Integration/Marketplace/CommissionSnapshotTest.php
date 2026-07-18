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
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\QueryLoggingPdoStatement;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * Checkout commission snapshot (design spec §2.4, MV3 plan Task 3):
 * `CheckoutService::placeOrder()`'s partitioned branch resolves the
 * product -> seller -> workspace -> config commission policy per line and
 * snapshots it immutably onto `commerce_order_lines`, with the seller-order
 * `commission_amount` as the exact sum of its lines'. Wired against a real,
 * fully-constructed `CheckoutService` (mirrors `CheckoutPartitionTest`'s
 * collaborator wiring).
 */
final class CommissionSnapshotTest extends CommerceTestCase
{
    private const TENANT = '';

    // -----------------------------------------------------------------
    // 1. Full precedence chain end to end: product > seller > workspace > config.
    // -----------------------------------------------------------------

    public function testPrecedenceProductBeatsSellerBeatsWorkspaceBeatsConfig(): void
    {
        // Config tail (base fallback, distinguishable bps so we can prove
        // which level actually won): 2%.
        $this->context->mergeConfigDefaults('commerce', [
            'marketplace' => ['commission' => ['kind' => 'percentage', 'bps' => 200, 'fixed' => null]],
        ]);
        // Workspace-settings override: 10%.
        $this->activateMarketplace(['commission_kind' => 'percentage', 'commission_bps' => 1000]);
        // Seller A overrides at 30%; Seller B inherits the workspace level.
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 3000,
        ]);
        $this->seedSeller('sellerBBBB01', 'Seller B');

        // P1: product-level override wins over seller A's 30% -> fixed 150.
        $p1 = $this->seedProduct('prec-p1', 1000, 'sellerAAAA01', [
            'commission_kind' => 'fixed', 'commission_fixed' => 150,
        ]);
        // P2: no product override -> inherits seller A's 30%.
        $p2 = $this->seedProduct('prec-p2', 2000, 'sellerAAAA01');
        // P3: no product/seller override -> inherits workspace's 10%.
        $p3 = $this->seedProduct('prec-p3', 3000, 'sellerBBBB01');

        $token = $this->cartWithLines([[$p1, 1], [$p2, 1], [$p3, 1]]);

        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $order = $placed['order'];
        self::assertTrue((bool) $order['marketplace_partitioned']);

        $lines = $this->linesForOrder((string) $order['uuid']);
        $lineP1 = $this->lineBySku($lines, 'PRECP1');
        $lineP2 = $this->lineBySku($lines, 'PRECP2');
        $lineP3 = $this->lineBySku($lines, 'PRECP3');

        // P1: product override wins (fixed 150, capped at basis 1000 -> 150).
        self::assertSame('product', $lineP1['commission_source']);
        self::assertSame('fixed', $lineP1['commission_kind']);
        self::assertNull($lineP1['commission_bps']);
        self::assertSame(150, (int) $lineP1['commission_fixed']);
        self::assertSame(1000, (int) $lineP1['commission_basis']);
        self::assertSame(150, (int) $lineP1['commission_amount']);

        // P2: seller A's 30% wins over workspace's 10% -> intdiv(2000*3000+5000,10000)=600.
        self::assertSame('seller', $lineP2['commission_source']);
        self::assertSame('percentage', $lineP2['commission_kind']);
        self::assertSame(3000, (int) $lineP2['commission_bps']);
        self::assertSame(2000, (int) $lineP2['commission_basis']);
        self::assertSame(600, (int) $lineP2['commission_amount']);

        // P3: workspace's 10% wins over config's 2% -> intdiv(3000*1000+5000,10000)=300.
        self::assertSame('workspace', $lineP3['commission_source']);
        self::assertSame('percentage', $lineP3['commission_kind']);
        self::assertSame(1000, (int) $lineP3['commission_bps']);
        self::assertSame(3000, (int) $lineP3['commission_basis']);
        self::assertSame(300, (int) $lineP3['commission_amount']);

        // Seller-order commission_amount = exact sum of its lines'.
        $sellerOrders = $this->sellerOrdersFor((string) $order['uuid']);
        $sellerA = $this->sellerOrderBySeller($sellerOrders, 'sellerAAAA01');
        $sellerB = $this->sellerOrderBySeller($sellerOrders, 'sellerBBBB01');
        self::assertSame(150 + 600, (int) $sellerA['commission_amount']);
        self::assertSame(300, (int) $sellerB['commission_amount']);
    }

    // -----------------------------------------------------------------
    // 2. All levels null -> total config fallback.
    // -----------------------------------------------------------------

    public function testAllLevelsNullResolveToConfigDefault(): void
    {
        // No config override: config/commerce.php default is {percentage, 0, null}.
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('cfg-default', 4000, 'sellerAAAA01');

        $token = $this->cartWithLines([[$product, 1]]);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $order = $placed['order'];

        $line = $this->linesForOrder((string) $order['uuid'])[0];
        self::assertSame('config', $line['commission_source']);
        self::assertSame('percentage', $line['commission_kind']);
        self::assertSame(0, (int) $line['commission_bps']);
        self::assertNull($line['commission_fixed']);
        self::assertSame(4000, (int) $line['commission_basis']);
        self::assertSame(0, (int) $line['commission_amount']);

        $sellerOrder = $this->sellerOrdersFor((string) $order['uuid'])[0];
        self::assertSame(0, (int) $sellerOrder['commission_amount']);
    }

    // -----------------------------------------------------------------
    // 3. Math: basis excludes discount, percentage rounds half-up, fixed is capped.
    // -----------------------------------------------------------------

    public function testCommissionBasisExcludesDiscountAndRoundsHalfUpPercentage(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        // 13.33% product-level override.
        $product = $this->seedProduct('math-percent', 1000, 'sellerAAAA01', [
            'commission_kind' => 'percentage', 'commission_bps' => 1333,
        ]);
        $this->seedDiscount('TAKE300', 'fixed', 300);

        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $cart = $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);
        $this->cart()->applyDiscount($this->context, $cart, 'TAKE300');

        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $order = $placed['order'];
        $line = $this->linesForOrder((string) $order['uuid'])[0];

        // line_total 1000, discount 300 -> basis 700.
        self::assertSame(300, (int) $line['discount_amount']);
        self::assertSame(700, (int) $line['commission_basis']);
        // intdiv(700*1333+5000,10000) = intdiv(937100,10000) = 93.
        self::assertSame(93, (int) $line['commission_amount']);
    }

    public function testFixedCommissionIsCappedAtBasis(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        // Fixed commission (500) deliberately larger than the line's basis (200).
        $product = $this->seedProduct('math-fixed', 200, 'sellerAAAA01', [
            'commission_kind' => 'fixed', 'commission_fixed' => 500,
        ]);

        $token = $this->cartWithLines([[$product, 1]]);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $order = $placed['order'];
        $line = $this->linesForOrder((string) $order['uuid'])[0];

        self::assertSame(200, (int) $line['commission_basis']);
        self::assertSame(200, (int) $line['commission_amount'], 'fixed commission is capped at the line basis');
    }

    // -----------------------------------------------------------------
    // 4. Immutability: policy edits after placement never touch the snapshot.
    // -----------------------------------------------------------------

    public function testSnapshotIsImmutableAcrossLaterPolicyEdits(): void
    {
        $this->activateMarketplace(['commission_kind' => 'percentage', 'commission_bps' => 1000]);
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('immutable-x', 5000, 'sellerAAAA01');

        $token = $this->cartWithLines([[$product, 1]]);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $order = $placed['order'];

        $before = $this->linesForOrder((string) $order['uuid'])[0];
        $sellerOrderBefore = $this->sellerOrdersFor((string) $order['uuid'])[0];
        self::assertSame('workspace', $before['commission_source']);
        self::assertSame(500, (int) $before['commission_amount']); // 10% of 5000.

        // Mutate product, seller, and workspace commission AFTER the order was placed.
        $this->connection->table('commerce_products')
            ->where('uuid', '=', $product['uuid'])
            ->update(['commission_kind' => 'fixed', 'commission_fixed' => 999]);
        $this->connection->table('commerce_sellers')
            ->where('uuid', '=', 'sellerAAAA01')
            ->update(['commission_kind' => 'percentage', 'commission_bps' => 9999]);
        $this->connection->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', self::TENANT)
            ->update(['commission_kind' => 'percentage', 'commission_bps' => 1]);

        $after = $this->linesForOrder((string) $order['uuid'])[0];
        $sellerOrderAfter = $this->sellerOrdersFor((string) $order['uuid'])[0];

        self::assertSame($before['commission_source'], $after['commission_source']);
        self::assertSame($before['commission_kind'], $after['commission_kind']);
        self::assertSame($before['commission_bps'], $after['commission_bps']);
        self::assertSame($before['commission_fixed'], $after['commission_fixed']);
        self::assertSame($before['commission_basis'], $after['commission_basis']);
        self::assertSame($before['commission_amount'], $after['commission_amount']);
        self::assertSame(
            (int) $sellerOrderBefore['commission_amount'],
            (int) $sellerOrderAfter['commission_amount']
        );
    }

    // -----------------------------------------------------------------
    // 5. Non-partitioned: zero-default commission columns, zero extra queries.
    // -----------------------------------------------------------------

    public function testInactiveWorkspaceCheckoutWritesZeroDefaultCommissionAndReadsSettingsRowOnlyOnce(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
        $product = $this->seedProduct('inactive-commission', 1000, null);

        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryLoggingPdoStatement::class]);
        QueryLoggingPdoStatement::$queries = [];

        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $order = $placed['order'];

        self::assertFalse((bool) $order['marketplace_partitioned']);

        $line = $this->linesForOrder((string) $order['uuid'])[0];
        self::assertNull($line['commission_source']);
        self::assertNull($line['commission_kind']);
        self::assertNull($line['commission_bps']);
        self::assertNull($line['commission_fixed']);
        self::assertSame(0, (int) $line['commission_basis']);
        self::assertSame(0, (int) $line['commission_amount']);
        self::assertSame(0, $this->connection->table('commerce_seller_orders')->count());

        // installEnabled() is true, so activeFor() legitimately reads
        // commerce_marketplace_settings ONCE to learn the workspace is
        // inactive -- settingsRowFor() must never be additionally called on
        // this path (it only runs inside the partitioned branch).
        $settingsQueries = array_values(array_filter(
            QueryLoggingPdoStatement::$queries,
            // Exclude the query builder's one-time PRAGMA table_info() schema
            // introspection (soft-delete-column detection, unrelated to how many
            // times business logic actually reads the settings row).
            static fn (string $sql): bool => str_starts_with($sql, 'SELECT')
                && str_contains($sql, 'commerce_marketplace_settings')
        ));
        self::assertCount(
            1,
            $settingsQueries,
            'the settings row must be read exactly once (activeFor()); never re-read for commission resolution'
        );
    }

    public function testNonPartitionedCheckoutOfProductWithCommissionOverrideDoesNotLeakRawPolicy(): void
    {
        // The exact leak this task guards against: a product carrying a raw
        // commission override, checked out on a NON-partitioned order, must NOT
        // persist that override onto its order line (orderLineRow() gates the
        // commission columns on the RESOLVED commission_source, never the raw
        // product keys pricedLines() always carries).
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
        $product = $this->seedProduct('leak-guard-commission', 1000, null, [
            'commission_kind' => 'fixed',
            'commission_fixed' => 999,
        ]);

        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $order = $placed['order'];

        self::assertFalse((bool) $order['marketplace_partitioned']);

        $line = $this->linesForOrder((string) $order['uuid'])[0];
        self::assertNull($line['commission_source']);
        self::assertNull($line['commission_kind'], 'raw product commission override must not leak into a non-partitioned line');
        self::assertNull($line['commission_bps']);
        self::assertNull($line['commission_fixed']);
        self::assertSame(0, (int) $line['commission_basis']);
        self::assertSame(0, (int) $line['commission_amount']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

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
            new SellerOrderRepository(),
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

    /** @param array{commission_kind?:string,commission_bps?:int,commission_fixed?:int} $commission */
    private function activateMarketplace(array $commission = []): void
    {
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
        $this->connection->table('commerce_marketplace_settings')->insert(array_merge([
            'uuid' => 'mktsettings1',
            'tenant_uuid' => self::TENANT,
            'status' => 'active',
        ], $commission));
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

    /**
     * @param array{commission_kind?:string,commission_bps?:int,commission_fixed?:int} $commission
     * @return array<string,mixed>
     */
    private function seedProduct(
        string $slug,
        int $price,
        ?string $sellerUuid,
        array $commission = [],
        int $stock = 100
    ): array {
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

        $updates = $commission;
        if ($sellerUuid !== null) {
            $updates['seller_uuid'] = $sellerUuid;
        }
        if ($updates !== []) {
            $this->connection->table('commerce_products')
                ->where('uuid', '=', $product['uuid'])
                ->update($updates);
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

    private function fakeShipping(): ShippingRateProvider
    {
        return new class implements ShippingRateProvider {
            public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
            {
                return [new ShippingQuote('std', 'Standard', 500)];
            }
        };
    }

    /** Flat 10% (basis points), aggregate only. */
    private function aggregateTax(): TaxCalculator
    {
        return new class implements TaxCalculator {
            public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
            {
                return new TaxQuote(intdiv($taxableAmount * 1000 + 5000, 10000));
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
}
