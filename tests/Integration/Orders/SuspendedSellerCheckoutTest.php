<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

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
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\CheckoutConflictException;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Marketplace MV5b Task 4 (design spec §2.4/§2.5): cart add/update/pricing
 * reject a suspended/closed/onboarding seller's product with the SAME
 * stable unavailable-product error a delisted product returns (via Task 3's
 * {@see ProductRepository::findBuyerAvailableByUuid()}, now wired into
 * {@see CartService}), and the EXISTING `CheckoutService::claimMarketplaceOwnership()`
 * seller-status guard is regression-pinned rather than duplicated -- it
 * remains the safety net for a suspension that lands DURING the checkout
 * transaction itself (see the hook-based "single-connection stand-in" tests
 * in `CheckoutPartitionTest`, extended alongside this file). Already-placed
 * paid orders stay readable/fulfillable regardless of the seller's CURRENT
 * status (§2.5 -- suspension is prospective, never retroactive).
 */
final class SuspendedSellerCheckoutTest extends CommerceTestCase
{
    private const TENANT = '';

    // =====================================================================
    // Cart add/update reject a non-active seller's product (§2.4).
    // =====================================================================

    /** @dataProvider nonActiveSellerStatusProvider */
    public function testCartAddRejectsNonActiveSellerProductWithStableUnavailableError(string $status): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerNAAA01', 'Seller Inactive', $status);
        $product = $this->seedProduct('cart-add-' . $status, 1000, 'sellerNAAA01');

        ['cart' => $cart] = $this->cart()->create($this->context);

        try {
            $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);
            self::fail("expected ValidationException adding a '{$status}' seller's product to the cart");
        } catch (ValidationException $e) {
            self::assertSame(
                ['This product is no longer available.'],
                $e->errorsFor('variant_uuid'),
                'must be the SAME stable unavailable-product error a delisted/deleted product returns'
            );
        }

        self::assertSame(0, $this->connection->table('commerce_cart_lines')->count());
    }

    /** @return iterable<string, array{0:string}> */
    public static function nonActiveSellerStatusProvider(): iterable
    {
        yield 'suspended' => ['suspended'];
        yield 'closed' => ['closed'];
        yield 'onboarding' => ['onboarding'];
    }

    public function testCartUpdateRejectsWhenSellerSuspendedAfterTheLineWasAdded(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerUPDT01', 'Seller Update');
        $product = $this->seedProduct('cart-update-x', 1000, 'sellerUPDT01');

        $cartService = $this->cart();
        ['cart' => $cart] = $cartService->create($this->context);
        $cart = $cartService->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);
        $line = $this->connection->table('commerce_cart_lines')
            ->where('cart_uuid', '=', $cart['uuid'])
            ->first();
        self::assertNotNull($line);

        // The seller is suspended AFTER the line was already in the cart --
        // the update path must still reject it, exactly like a delisted
        // product would.
        $this->suspendSellerDirectly('sellerUPDT01');

        try {
            $cartService->setLineQuantity($this->context, $cart, (string) $line['uuid'], 3);
            self::fail('expected ValidationException updating a suspended seller\'s cart line');
        } catch (ValidationException $e) {
            self::assertSame(['This product is no longer available.'], $e->errorsFor('variant_uuid'));
        }

        $unchanged = $this->connection->table('commerce_cart_lines')->where('uuid', '=', $line['uuid'])->first();
        self::assertSame(1, (int) $unchanged['quantity'], 'the rejected update must not mutate the line');
    }

    // =====================================================================
    // Checkout: a cart built while active, then the seller suspended
    // BEFORE checkout even starts, fails via the SAME buyer-availability
    // guard cart pricing uses -- no order/seller-order is ever written.
    // =====================================================================

    /** @dataProvider nonActiveTerminalStatusProvider */
    public function testCartBuiltWhileActiveThenSellerSuspendedThenCheckoutFailsWithNoOrderRows(string $status): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerCOMM01', 'Seller Committed');
        $product = $this->seedProduct('checkout-committed-' . $status, 1000, 'sellerCOMM01');

        $cartService = $this->cart();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        // Fully committed BEFORE checkout starts -- no concurrency involved.
        $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerCOMM01')->update(['status' => $status]);

        try {
            $this->checkoutPartitioned()
                ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
            self::fail("expected checkout to fail for a '{$status}' seller's cart");
        } catch (ValidationException | CheckoutConflictException $e) {
            // Either the cart-pricing buyer-availability guard (ValidationException,
            // the common case since suspension is already committed by the time
            // checkout re-reads the product) or claimMarketplaceOwnership()'s own
            // guard may be what fires -- the pinned INVARIANT is that checkout
            // never completes, not which guard caught it.
            self::assertNotNull($e);
        }

        self::assertSame(
            0,
            $this->connection->table('commerce_orders')->where('tenant_uuid', '=', self::TENANT)->count()
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_orders')->where('seller_uuid', '=', 'sellerCOMM01')->count()
        );
    }

    /** @return iterable<string, array{0:string}> */
    public static function nonActiveTerminalStatusProvider(): iterable
    {
        yield 'suspended' => ['suspended'];
        yield 'closed' => ['closed'];
    }

    // =====================================================================
    // Checkout: a suspension that lands DURING the checkout transaction
    // itself (single-connection stand-in for a genuine concurrent suspend,
    // same convention CheckoutPartitionTest's ownership-drift hooks use) is
    // caught by the EXISTING `claimMarketplaceOwnership()` guard -- this is
    // the guard this task pins rather than duplicates.
    // =====================================================================

    public function testSuspensionDuringTheCheckoutClaimIsCaughtByTheExistingOwnershipGuard(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerRACE01', 'Seller Race');
        $product = $this->seedProduct('checkout-race-x', 1000, 'sellerRACE01');

        $cartService = $this->cart();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $calls = 0;
        $hook = function () use (&$calls): void {
            $calls++;
            // Fires AFTER the pre-claim ownership snapshot (so cart pricing,
            // which already ran, and the snapshot itself both still saw
            // 'active') but BEFORE the seller/product revision claim.
            $this->connection->table('commerce_sellers')
                ->where('uuid', '=', 'sellerRACE01')
                ->update(['status' => 'suspended']);
        };

        try {
            $this->checkoutPartitioned($hook)
                ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
            self::fail('expected CheckoutConflictException.');
        } catch (CheckoutConflictException $e) {
            self::assertSame('checkout_conflict', $e->errorCode);
        }

        self::assertSame(1, $calls, 'an inactive seller is never retried (never ownership drift)');
        self::assertSame(0, $this->connection->table('commerce_orders')->count());
        self::assertSame(0, $this->connection->table('commerce_seller_orders')->count());
        $cartRow = $this->connection->table('commerce_carts')->where('uuid', '=', $cart['uuid'])->first();
        self::assertSame('active', $cartRow['status'], 'the whole transaction (cart claim included) rolls back');
    }

    // =====================================================================
    // Positive controls: sellerless / active-seller checkout is unaffected.
    // =====================================================================

    public function testActiveSellerCartCheckoutSucceedsNormally(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerOKOK01', 'Seller OK');
        $product = $this->seedProduct('checkout-active-x', 1000, 'sellerOKOK01');

        $cartService = $this->cart();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->checkoutPartitioned()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        self::assertTrue((bool) $placed['order']['marketplace_partitioned']);
        $sellerOrders = $this->connection->table('commerce_seller_orders')
            ->where('order_uuid', '=', (string) $placed['order']['uuid'])
            ->get();
        self::assertCount(1, $sellerOrders);
        self::assertSame('sellerOKOK01', $sellerOrders[0]['seller_uuid']);
    }

    public function testSellerlessCartCheckoutSucceedsNormallyWithMasterSwitchOff(): void
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->tenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => 'sellerless-x',
            'name' => 'sellerless-x',
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => 'SELLERLESSX',
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
        (new StockRepository())->increment($this->context, self::TENANT, (string) $product['variants'][0]['uuid'], 10);

        $cartService = $this->cart();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->checkoutPlain()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        self::assertFalse((bool) $placed['order']['marketplace_partitioned']);
        self::assertSame(0, $this->connection->table('commerce_seller_orders')->count());
    }

    // =====================================================================
    // §2.5: an already-placed PAID order remains readable + fulfillable
    // through suspension -- fulfillment is never gated on CURRENT status.
    // =====================================================================

    public function testExistingPaidOrderRemainsReadableAndFulfillableAfterSellerSuspension(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerPAID01', 'Seller Paid');
        $product = $this->seedProduct('paid-order-x', 1000, 'sellerPAID01');

        $cartService = $this->cart();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->checkoutPartitioned()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $orderUuid = (string) $placed['order']['uuid'];

        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);

        // Operator-audited, reason-mandatory suspension (design spec §2.1) --
        // the real transition, not a raw row mutation.
        $sellers = new SellerService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            new SellerLifecycleEventRepository()
        );
        $sellers->suspend($this->context, self::TENANT, 'sellerPAID01', 'Regression test suspension.', 'operatorTest1');

        // Still readable via the repository...
        $reread = (new OrderRepository())->findByUuid($this->context, self::TENANT, $orderUuid);
        self::assertNotNull($reread);
        self::assertSame('paid', $reread['status']);

        // ...and via the customer-facing HTTP surface.
        $request = Request::create('/commerce/orders/' . $placed['order']['order_number']);
        $request->headers->set('X-Order-Token', $placed['guest_token']);
        $response = $this->orderController()->show($request, (string) $placed['order']['order_number']);
        self::assertSame(200, $response->getStatusCode());

        // Still fulfillable -- fulfillment is NEVER gated on the seller's
        // CURRENT status (§2.5).
        $child = $this->connection->table('commerce_seller_orders')->where('order_uuid', '=', $orderUuid)->first();
        self::assertNotNull($child);
        $fulfilled = $this->fulfillmentService()->fulfill(
            $this->context,
            self::TENANT,
            $orderUuid,
            (string) $child['uuid'],
            ['carrier' => 'UPS', 'tracking_number' => 'TRACK1', 'tracking_url' => null],
            null
        );
        self::assertSame('fulfilled', $fulfilled['fulfillment_status']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function checkoutPartitioned(?callable $afterOwnershipSnapshotHook = null): CheckoutService
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
            $afterOwnershipSnapshotHook
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

    private function fulfillmentService(): SellerOrderFulfillmentService
    {
        return new SellerOrderFulfillmentService(new OrderRepository(), new SellerOrderRepository());
    }

    private function paymentService(): OrderPaymentService
    {
        return new OrderPaymentService(new OrderRepository(), new SellerOrderPaymentConfirmation());
    }

    private function orderController(): OrderController
    {
        return new OrderController(
            $this->context,
            new OrderRepository(),
            $this->checkoutPartitioned(),
            $this->tenantResolver(),
            new RefundRepository()
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

    private function suspendSellerDirectly(string $uuid): void
    {
        $this->connection->table('commerce_sellers')->where('uuid', '=', $uuid)->update(['status' => 'suspended']);
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
}
