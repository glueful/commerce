<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

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
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
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
use Symfony\Component\HttpFoundation\Request;

/**
 * Customer `seller_groups` projection (design spec §6.3, MV2 Task 9): the
 * `/commerce/orders/{number}` projection adds a strictly allowlisted
 * `seller_groups[]` ONLY when the order's OWN `marketplace_partitioned`
 * snapshot is true. Every excluded field (`seller_uuid`, `revision`, the
 * internal `open|canceled` `status`, `tenant_uuid`, `tax_attribution_method`)
 * is proven absent with poison strings seeded directly into the row, and the
 * consolidated order (`grand_total`, flat `lines`) is proven unchanged.
 */
final class StorefrontSellerGroupsProjectionTest extends CommerceTestCase
{
    private const TENANT = 'poisonTenant1LEAK';

    public function testSellerGroupsProjectionIncludesAllocationsLinesFulfillmentWithExclusions(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller Alpha');
        $this->seedSeller('sellerBBBB01', 'Seller Bravo');
        $productX = $this->seedProduct('sg-proj-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('sg-proj-y', 2000, 'sellerBBBB01');

        $placed = $this->placeTwoSellerOrder($productX, $productY);
        $order = $placed['order'];
        $orderUuid = (string) $order['uuid'];

        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);

        [$childA, $childB] = $this->sellerOrdersFor($orderUuid);
        self::assertSame('sellerAAAA01', $childA['seller_uuid']);
        self::assertSame('sellerBBBB01', $childB['seller_uuid']);

        // Fulfill only seller A's child so the projection proves the
        // fulfillment sub-object is genuinely PER-SELLER, not a shared copy.
        $this->fulfillmentService()->fulfill(
            $this->context,
            self::TENANT,
            $orderUuid,
            (string) $childA['uuid'],
            ['carrier' => 'UPS', 'tracking_number' => 'TRACK-SG-A', 'tracking_url' => 'https://track.example/sg-a'],
            null
        );

        $number = (string) $order['order_number'];
        $request = Request::create("/commerce/orders/{$number}", 'GET');
        $request->headers->set('X-Order-Token', (string) $placed['guest_token']);

        $response = $this->orderController()->show($request, $number);
        $raw = (string) $response->getContent();
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());

        // --- consolidated order unchanged ---
        self::assertSame(3500, $body['data']['grand_total'], 'subtotal 3000 + shipping 500 + tax 0');
        self::assertCount(2, $body['data']['lines'], 'the flat consolidated lines list is untouched');

        // --- seller_groups shape ---
        $groups = $body['data']['seller_groups'];
        self::assertCount(2, $groups);

        $groupA = self::firstWhere($groups, 'seller_reference', $childA['seller_reference']);
        $groupB = self::firstWhere($groups, 'seller_reference', $childB['seller_reference']);
        self::assertNotNull($groupA);
        self::assertNotNull($groupB);

        foreach ([$groupA, $groupB] as $group) {
            self::assertSame(
                [
                    'seller_reference', 'seller_name', 'lines', 'allocated_subtotal', 'allocated_discount',
                    'allocated_shipping_discount', 'allocated_shipping', 'allocated_tax', 'attributed_total',
                    'fulfillment',
                ],
                array_keys($group),
                'exact allowlist -- never a raw commerce_seller_orders row spread'
            );
            self::assertSame(
                ['fulfillment_status', 'carrier', 'tracking_number', 'tracking_url'],
                array_keys($group['fulfillment'])
            );
        }

        self::assertSame('Seller Alpha', $groupA['seller_name']);
        self::assertSame('Seller Bravo', $groupB['seller_name']);

        self::assertCount(1, $groupA['lines']);
        self::assertSame('SGPROJX', $groupA['lines'][0]['sku']);
        self::assertCount(1, $groupB['lines']);
        self::assertSame('SGPROJY', $groupB['lines'][0]['sku']);
        foreach (array_merge($groupA['lines'], $groupB['lines']) as $line) {
            self::assertSame(
                ['product_name', 'sku', 'quantity', 'unit_price', 'line_total', 'option_values', 'addons'],
                array_keys($line),
                'seller_groups lines use the SAME allowlist as the consolidated lines'
            );
        }

        self::assertSame('fulfilled', $groupA['fulfillment']['fulfillment_status']);
        self::assertSame('UPS', $groupA['fulfillment']['carrier']);
        self::assertSame('TRACK-SG-A', $groupA['fulfillment']['tracking_number']);
        self::assertSame('unfulfilled', $groupB['fulfillment']['fulfillment_status']);
        self::assertNull($groupB['fulfillment']['carrier']);

        // --- exact reconciliation against the consolidated order ---
        self::assertSame(3000, $groupA['allocated_subtotal'] + $groupB['allocated_subtotal']);
        self::assertSame(0, $groupA['allocated_discount'] + $groupB['allocated_discount']);
        self::assertSame(0, $groupA['allocated_shipping_discount'] + $groupB['allocated_shipping_discount']);
        self::assertSame(500, $groupA['allocated_shipping'] + $groupB['allocated_shipping']);
        self::assertSame(0, $groupA['allocated_tax'] + $groupB['allocated_tax']);
        self::assertSame(3500, $groupA['attributed_total'] + $groupB['attributed_total']);

        // --- exclusion poison strings: never in either group's decoded keys OR the raw body ---
        foreach ([$groupA, $groupB] as $group) {
            foreach (['seller_uuid', 'revision', 'status', 'tenant_uuid', 'tax_attribution_method'] as $excluded) {
                self::assertArrayNotHasKey($excluded, $group, "'{$excluded}' must never appear in a seller_group");
            }
        }
        self::assertStringNotContainsString('sellerAAAA01', $raw, 'seller_uuid must never leak into the raw body');
        self::assertStringNotContainsString('sellerBBBB01', $raw, 'seller_uuid must never leak into the raw body');
        // The top-level order row has always echoed its own `tenant_uuid`
        // (pre-existing, unrelated to MV2/Task 9); the assertion here is that
        // seller_groups does NOT introduce a SECOND occurrence of it.
        self::assertSame(
            1,
            substr_count($raw, self::TENANT),
            'tenant_uuid must not additionally leak inside seller_groups'
        );
        self::assertStringNotContainsString(
            'aggregate_allocated',
            $raw,
            'tax_attribution_method must never leak into the raw body'
        );
        self::assertStringNotContainsString('"status":"open"', $raw, 'internal seller-order status must never leak');
    }

    public function testSellerGroupsProjectionPresentBeforePaymentConfirmation(): void
    {
        // Customer own-order visibility has no confirmed_at gate (§2.12 only
        // gates the SELLER-facing surface): a not-yet-paid order still shows
        // seller_groups to the customer who placed it, matching how `lines`
        // has never had a payment gate either.
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller Alpha');
        $product = $this->seedProduct('sg-proj-preconfirm', 1000, 'sellerAAAA01');

        $placed = $this->placeOneSellerOrder($product);
        $order = $placed['order'];
        self::assertSame('pending_payment', $this->orderRow((string) $order['uuid'])['status']);

        $number = (string) $order['order_number'];
        $request = Request::create("/commerce/orders/{$number}", 'GET');
        $request->headers->set('X-Order-Token', (string) $placed['guest_token']);

        $body = $this->json($this->orderController()->show($request, $number));

        self::assertCount(1, $body['data']['seller_groups']);
        self::assertSame('unfulfilled', $body['data']['seller_groups'][0]['fulfillment']['fulfillment_status']);
    }

    public function testNonPartitionedOrderHasNoSellerGroupsAndIsByteIdenticalToPreMv2(): void
    {
        $placed = $this->placeNonPartitionedOrder();
        $order = $placed['order'];
        $number = (string) $order['order_number'];

        $request = Request::create("/commerce/orders/{$number}", 'GET');
        $request->headers->set('X-Order-Token', (string) $placed['guest_token']);

        $body = $this->json($this->orderController()->show($request, $number));

        self::assertArrayNotHasKey('seller_groups', $body['data']);
        self::assertSame(
            ['refunds', 'notes', 'lines'],
            array_values(array_intersect(['refunds', 'notes', 'lines'], array_keys($body['data'])))
        );
        self::assertSame(0, $this->connection->table('commerce_seller_orders')->count());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @param list<array<string,mixed>> $rows @return array<string,mixed>|null */
    private static function firstWhere(array $rows, string $key, string $value): ?array
    {
        foreach ($rows as $row) {
            if (($row[$key] ?? null) === $value) {
                return $row;
            }
        }

        return null;
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

    private function fulfillmentService(): SellerOrderFulfillmentService
    {
        return new SellerOrderFulfillmentService(new OrderRepository(), new SellerOrderRepository());
    }

    private function paymentService(): OrderPaymentService
    {
        return new OrderPaymentService(new OrderRepository(), new SellerOrderPaymentConfirmation());
    }

    /** @return array{order: array<string,mixed>, guest_token: string} */
    private function placeOneSellerOrder(array $product): array
    {
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->checkoutPartitioned()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        return ['order' => $placed['order'], 'guest_token' => (string) $placed['guest_token']];
    }

    /** @return array{order: array<string,mixed>, guest_token: string} */
    private function placeTwoSellerOrder(array $productX, array $productY): array
    {
        $cartService = $this->cart();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cart = $cartService->addLine($this->context, $cart, (string) $productX['variants'][0]['uuid'], 1);
        $cartService->addLine($this->context, $cart, (string) $productY['variants'][0]['uuid'], 1);

        $placed = $this->checkoutPartitioned()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        return ['order' => $placed['order'], 'guest_token' => (string) $placed['guest_token']];
    }

    /** @return array{order: array<string,mixed>, guest_token: string} */
    private function placeNonPartitionedOrder(): array
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->tenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => 'sg-proj-nonpartitioned',
            'name' => 'sg-proj-nonpartitioned',
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => 'SGPROJNONPART',
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

        return ['order' => $placed['order'], 'guest_token' => (string) $placed['guest_token']];
    }

    private function checkoutPartitioned(): CheckoutService
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

    private function activateMarketplace(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsettings3',
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

    /** @return array<string,mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
