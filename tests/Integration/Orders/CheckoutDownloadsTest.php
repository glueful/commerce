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
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
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
use Symfony\Component\HttpFoundation\Request;

/**
 * Checkout-side digital-delivery entitlement snapshot (design spec §2): for a
 * `digital`-type product's line, order-line building snapshots the variant's
 * ACTIVE download definitions (ordered by position) into the line's
 * `downloads` json -- `[{download_uuid, blob_uuid, name, download_limit,
 * expiry_days}]`, empty when none are active; a `physical` line's `downloads`
 * column stays NULL. A definition edit/delete AFTER checkout must never alter
 * the already-persisted order-line snapshot (purchase-time entitlement,
 * design spec §2/§4). No projection (storefront order, admin order) ever
 * echoes `downloads` -- grants (a future task) are the only access surface.
 */
final class CheckoutDownloadsTest extends CommerceTestCase
{
    public function testDigitalLineSnapshotsActiveDownloadsOrderedByPosition(): void
    {
        ['variant_uuid' => $variantUuid] = $this->seedDigitalProduct('SKU-DL1');
        $this->seedDownload($variantUuid, 'dlpos2001111', 'blobA00000001', 'Second.pdf', position: 1);
        $this->seedDownload($variantUuid, 'dlpos1001111', 'blobB00000001', 'First.pdf', position: 0, limit: 3, expiry: 14);

        $placed = $this->placeOrder($variantUuid, 1);

        $line = $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', $placed['order']['uuid'])->first();
        self::assertNotNull($line);
        $downloads = json_decode((string) $line['downloads'], true);

        self::assertIsArray($downloads);
        self::assertCount(2, $downloads);
        self::assertSame(['download_uuid', 'blob_uuid', 'name', 'download_limit', 'expiry_days'], array_keys($downloads[0]));

        // Ordered by position ascending: dlpos1001111 (position 0) first.
        self::assertSame('dlpos1001111', $downloads[0]['download_uuid']);
        self::assertSame('blobB00000001', $downloads[0]['blob_uuid']);
        self::assertSame('First.pdf', $downloads[0]['name']);
        self::assertSame(3, $downloads[0]['download_limit']);
        self::assertSame(14, $downloads[0]['expiry_days']);

        self::assertSame('dlpos2001111', $downloads[1]['download_uuid']);
        self::assertSame('Second.pdf', $downloads[1]['name']);
        self::assertNull($downloads[1]['download_limit']);
        self::assertNull($downloads[1]['expiry_days']);
    }

    public function testDigitalLineWithNoActiveDownloadsSnapshotsEmptyArray(): void
    {
        ['variant_uuid' => $variantUuid] = $this->seedDigitalProduct('SKU-DL2');

        $placed = $this->placeOrder($variantUuid, 1);

        $line = $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', $placed['order']['uuid'])->first();
        self::assertNotNull($line);
        self::assertSame('[]', $line['downloads']);

        $decoded = (new OrderRepository())->linesForOrder($this->context, '', (string) $placed['order']['uuid']);
        self::assertSame([], $decoded[0]['downloads']);
    }

    public function testDigitalLineIgnoresInactiveDownloadDefinitions(): void
    {
        ['variant_uuid' => $variantUuid] = $this->seedDigitalProduct('SKU-DL3');
        $this->seedDownload($variantUuid, 'dlactive0001', 'blobactive001', 'Active.pdf');
        $this->seedDownload($variantUuid, 'dlinactive01', 'blobinactive1', 'Inactive.pdf', status: 'inactive');

        $placed = $this->placeOrder($variantUuid, 1);

        $decoded = (new OrderRepository())->linesForOrder($this->context, '', (string) $placed['order']['uuid']);
        self::assertCount(1, $decoded[0]['downloads']);
        self::assertSame('dlactive0001', $decoded[0]['downloads'][0]['download_uuid']);
    }

    public function testPhysicalLineSnapshotsNullDownloadsColumn(): void
    {
        ['variant_uuid' => $variantUuid] = $this->seedPhysicalProduct('SKU-DL4');

        $placed = $this->placeOrder($variantUuid, 1);

        $line = $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', $placed['order']['uuid'])->first();
        self::assertNotNull($line);
        self::assertNull($line['downloads']);

        $decoded = (new OrderRepository())->linesForOrder($this->context, '', (string) $placed['order']['uuid']);
        self::assertNull($decoded[0]['downloads']);
    }

    public function testDefinitionEditAfterCheckoutLeavesStoredLineSnapshotByteIdentical(): void
    {
        ['variant_uuid' => $variantUuid] = $this->seedDigitalProduct('SKU-DL5');
        $this->seedDownload($variantUuid, 'dledit000001', 'blobedit00001', 'Original.pdf', limit: 5, expiry: 10);

        $placed = $this->placeOrder($variantUuid, 1);
        $orderUuid = (string) $placed['order']['uuid'];

        $before = $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', $orderUuid)->first()['downloads'];
        self::assertIsString($before);

        // Edit the definition after checkout: name, limit, and expiry all change.
        $this->connection->table('commerce_downloads')
            ->where('uuid', '=', 'dledit000001')
            ->update(['name' => 'Renamed.pdf', 'download_limit' => 999, 'expiry_days' => 1]);

        $after = $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', $orderUuid)->first()['downloads'];
        self::assertSame($before, $after, 'order-line snapshot must be byte-identical after a definition edit');
        self::assertStringContainsString('Original.pdf', $after);
        self::assertStringNotContainsString('Renamed.pdf', $after);
    }

    public function testDefinitionDeleteAfterCheckoutLeavesStoredLineSnapshotByteIdentical(): void
    {
        ['variant_uuid' => $variantUuid] = $this->seedDigitalProduct('SKU-DL6');
        $this->seedDownload($variantUuid, 'dldelete0001', 'blobdelete001', 'Doomed.pdf');

        $placed = $this->placeOrder($variantUuid, 1);
        $orderUuid = (string) $placed['order']['uuid'];

        $before = $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', $orderUuid)->first()['downloads'];
        self::assertIsString($before);

        $this->connection->table('commerce_downloads')->where('uuid', '=', 'dldelete0001')->delete();

        $after = $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', $orderUuid)->first()['downloads'];
        self::assertSame($before, $after, 'order-line snapshot must survive definition deletion untouched');
        self::assertStringContainsString('Doomed.pdf', $after);
    }

    public function testStorefrontOrderProjectionNeverExposesDownloadsKeyForADigitalLine(): void
    {
        ['variant_uuid' => $variantUuid] = $this->seedDigitalProduct('SKU-DL7');
        $this->seedDownload($variantUuid, 'dlleak000001', 'blobleak00001', 'Secret.pdf');

        $placed = $this->placeOrder($variantUuid, 1);
        $number = (string) $placed['order']['order_number'];

        $request = Request::create("/commerce/orders/{$number}", 'GET');
        $request->headers->set('X-Order-Token', (string) $placed['guest_token']);

        $response = $this->orderController()->show($request, $number);
        $raw = (string) $response->getContent();
        $body = $this->json($response);

        self::assertArrayNotHasKey('downloads', $body['data']['lines'][0]);
        self::assertStringNotContainsString('"downloads"', $raw);
        self::assertStringNotContainsString('dlleak000001', $raw);
        self::assertStringNotContainsString('blobleak00001', $raw);
    }

    public function testAdminOrderProjectionNeverExposesDownloadsKeyForADigitalLine(): void
    {
        ['variant_uuid' => $variantUuid] = $this->seedDigitalProduct('SKU-DL8');
        $this->seedDownload($variantUuid, 'dlleak000002', 'blobleak00002', 'Secret2.pdf');

        $placed = $this->placeOrder($variantUuid, 1);

        $response = $this->adminOrderController()->show(
            Request::create('/commerce/admin/orders/' . $placed['order']['uuid'], 'GET'),
            (string) $placed['order']['uuid']
        );
        $raw = (string) $response->getContent();
        $body = $this->json($response);

        self::assertArrayNotHasKey('downloads', $body['data']['lines'][0]);
        self::assertStringNotContainsString('"downloads"', $raw);
        self::assertStringNotContainsString('dlleak000002', $raw);
        self::assertStringNotContainsString('blobleak00002', $raw);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** @return array{product_uuid: string, variant_uuid: string} */
    private function seedDigitalProduct(string $sku): array
    {
        $product = $this->catalog()->createProduct($this->context, [
            'slug' => strtolower($sku),
            'name' => $sku,
            'type' => 'digital',
            'status' => 'active',
            'variants' => [[
                'sku' => $sku,
                'option_values' => [],
                'price' => 500,
                'currency' => 'USD',
            ]],
        ]);

        return [
            'product_uuid' => (string) $product['uuid'],
            'variant_uuid' => (string) $product['variants'][0]['uuid'],
        ];
    }

    /** @return array{product_uuid: string, variant_uuid: string} */
    private function seedPhysicalProduct(string $sku): array
    {
        $product = $this->catalog()->createProduct($this->context, [
            'slug' => strtolower($sku),
            'name' => $sku,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => $sku,
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, '', $variantUuid, 10);

        return ['product_uuid' => (string) $product['uuid'], 'variant_uuid' => $variantUuid];
    }

    private function seedDownload(
        string $variantUuid,
        string $uuid,
        string $blobUuid,
        string $name,
        int $position = 0,
        ?int $limit = null,
        ?int $expiry = null,
        string $status = 'active',
    ): void {
        $this->connection->table('commerce_downloads')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'variant_uuid' => $variantUuid,
            'blob_uuid' => $blobUuid,
            'name' => $name,
            'download_limit' => $limit,
            'expiry_days' => $expiry,
            'position' => $position,
            'status' => $status,
        ]);
    }

    /** @return array{order: array<string,mixed>, guest_token: string} */
    private function placeOrder(string $variantUuid, int $quantity): array
    {
        $cart = $this->cart();
        ['cart' => $c, 'token' => $token] = $cart->create($this->context);
        $cart->addLine($this->context, $c, $variantUuid, $quantity);

        return $this->checkout()->placeOrder(
            $this->context,
            $token,
            ['email' => 'buyer@example.com', 'user_uuid' => null],
            ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            'std'
        );
    }

    private function catalog(): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository()
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
            new SentinelTenantResolver()
        );
    }

    private function checkout(): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), new SentinelTenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->shipping(),
            $this->tax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            new SentinelTenantResolver()
        );
    }

    private function orderController(): OrderController
    {
        return new OrderController(
            $this->context,
            new OrderRepository(),
            $this->checkout(),
            new SentinelTenantResolver(),
            new RefundRepository()
        );
    }

    private function adminOrderController(): AdminOrderController
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

    private function shipping(): ShippingRateProvider
    {
        return new class implements ShippingRateProvider {
            public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
            {
                return [new ShippingQuote('std', 'Standard', 500)];
            }
        };
    }

    private function tax(): TaxCalculator
    {
        return new class implements TaxCalculator {
            public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
            {
                return new TaxQuote(0);
            }
        };
    }

    /** @return array<string,mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
