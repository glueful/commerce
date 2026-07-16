<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\DTOs\AdminProductListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\CreateProductData;
use Glueful\Extensions\Commerce\Http\DTOs\ProductListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\ProductVariantData;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Reports\ProductSalesReportRepository;
use Glueful\Extensions\Commerce\Reports\ReportWindow;
use Glueful\Extensions\Commerce\Reports\StockReportRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Layer 6 Task 1: product soft delete (design spec §2, plan Global
 * Constraints). Covers: the guarded `deleteProduct()` claim/re-read/tombstone
 * transaction, the four-way live-vs-history repository split (delete lifecycle
 * makes every interactive read 404 on a tombstoned product while named
 * integrity/uniqueness reads still reach it), slug reservation surviving
 * delete, cart/checkout's controlled unavailable-product 422 (not a silent
 * dropped line), historical report/order-snapshot retention, variants/stock/
 * media surviving the tombstone, re-delete 404, and the deterministic
 * (sequential) single-winner claim invariant -- the real two-process pgsql
 * race is Task 7's.
 */
final class ProductDeleteTest extends CommerceTestCase
{
    // --- Delete lifecycle: 404 show, list invisibility -------------------------

    public function testDeleteStampsDeletedAtAndAdminShowThenThrowsNotFound(): void
    {
        $product = $this->seedActiveProduct('delprodA001', 'del-a-001');

        $this->catalog()->deleteProduct($this->context, $product['uuid']);

        $row = (new ProductRepository())->findIncludingDeletedByUuid($this->context, '', $product['uuid']);
        self::assertNotNull($row);
        self::assertNotNull($row['deleted_at']);

        $this->expectException(NotFoundException::class);
        $this->adminController()->show(Request::create('/x', 'GET'), $product['uuid']);
    }

    public function testDeleteViaHttpControllerReturns204(): void
    {
        $product = $this->seedActiveProduct('delprodB001', 'del-b-001');

        $response = $this->adminController()->destroy(Request::create('/x', 'DELETE'), $product['uuid']);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testDeleteUnknownProductReturns404(): void
    {
        $this->expectException(NotFoundException::class);

        $this->catalog()->deleteProduct($this->context, 'no-such-prod');
    }

    public function testDeleteCrossTenantProductReturns404(): void
    {
        $product = $this->seedActiveProduct('delprodX001', 'del-x-001', tenant: 'tenantdelA01');

        $this->expectException(NotFoundException::class);

        // Default catalog() below is scoped to tenant '' via SentinelTenantResolver.
        $this->catalog()->deleteProduct($this->context, $product['uuid']);
    }

    public function testReDeleteReturns404(): void
    {
        $product = $this->seedActiveProduct('delprodC001', 'del-c-001');
        $this->catalog()->deleteProduct($this->context, $product['uuid']);

        $this->expectException(NotFoundException::class);
        $this->catalog()->deleteProduct($this->context, $product['uuid']);
    }

    public function testSequentialConcurrentDeleteAttemptsYieldExactlyOneSuccess(): void
    {
        // Deterministic stand-in for the real two-connection row-lock race
        // (Task 7's pgsql-gated lane): the "winner" runs to completion first,
        // then the "loser" attempts the exact same delete against the
        // now-tombstoned row and must observe the same non-revealing 404 --
        // never a false success, never a corrupted double-tombstone write.
        $product = $this->seedActiveProduct('delprodD001', 'del-d-001');
        $catalog = $this->catalog();

        $catalog->deleteProduct($this->context, $product['uuid']);

        try {
            $catalog->deleteProduct($this->context, $product['uuid']);
            self::fail('expected NotFoundException on the second (losing) concurrent delete attempt');
        } catch (NotFoundException $e) {
            // expected -- exactly one delete may ever win.
        }

        $row = (new ProductRepository())->findIncludingDeletedByUuid($this->context, '', $product['uuid']);
        self::assertNotNull($row);
        self::assertNotNull($row['deleted_at']);
    }

    // --- Interactive invisibility sweep -----------------------------------------

    public function testDeletedProductInvisibleInAdminList(): void
    {
        $visible = $this->seedActiveProduct('delprodE001', 'del-e-001');
        $deleted = $this->seedActiveProduct('delprodE002', 'del-e-002');
        $this->catalog()->deleteProduct($this->context, $deleted['uuid']);

        $response = $this->adminController()->index(new AdminProductListQuery(), Request::create('/x', 'GET'));
        $uuids = array_column($this->json($response)['data'], 'uuid');

        self::assertContains($visible['uuid'], $uuids);
        self::assertNotContains($deleted['uuid'], $uuids);
    }

    public function testDeletedProductInvisibleInStorefrontShowByUuidAndSlugPaths(): void
    {
        $product = $this->seedActiveProduct('delprodF001', 'del-f-001');
        $this->catalog()->deleteProduct($this->context, $product['uuid']);

        self::assertNull((new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']));
        self::assertNull((new ProductRepository())->findLiveBySlug($this->context, '', 'del-f-001'));

        $this->expectException(NotFoundException::class);
        $this->productController()->show(Request::create('/x', 'GET'), 'del-f-001');
    }

    public function testDeletedProductInvisibleInStorefrontList(): void
    {
        $visible = $this->seedActiveProduct('delprodG001', 'del-g-001');
        $deleted = $this->seedActiveProduct('delprodG002', 'del-g-002');
        $this->catalog()->deleteProduct($this->context, $deleted['uuid']);

        $response = $this->productController()->index(new ProductListQuery());
        $uuids = array_column($this->json($response)['data'], 'uuid');

        self::assertContains($visible['uuid'], $uuids);
        self::assertNotContains($deleted['uuid'], $uuids);
    }

    public function testDeletedProductExcludedFromStockReport(): void
    {
        $product = $this->seedActiveProduct('delprodH001', 'del-h-001');
        $variantUuid = (string) $product['variants'][0]['uuid'];
        // ensureRow() (run during product creation) already leaves the tracked
        // physical variant's stock row at quantity 0 -- out-of-stock already.

        $before = (new StockReportRepository())->paginate($this->context, '', 2, null, 1, 25);
        self::assertGreaterThanOrEqual(1, $before['total']);

        $this->catalog()->deleteProduct($this->context, $product['uuid']);

        $after = (new StockReportRepository())->paginate($this->context, '', 2, null, 1, 25);
        $variantUuids = array_column($after['items'], 'variant_uuid');
        self::assertNotContains($variantUuid, $variantUuids);
    }

    public function testUpdateRejectsPatchOnTombstonedProductWith404(): void
    {
        $product = $this->seedActiveProduct('delprodI001', 'del-i-001');
        $this->catalog()->deleteProduct($this->context, $product['uuid']);

        $this->expectException(NotFoundException::class);
        $this->catalog()->updateProduct($this->context, $product['uuid'], ['name' => 'Renamed']);
    }

    // --- Variants/stock/media survive; order-line/report snapshots untouched --

    public function testVariantsStockAndMediaRowsRemainAfterProductDeleted(): void
    {
        $product = $this->seedActiveProduct('delprodJ001', 'del-j-001');
        $variantUuid = (string) $product['variants'][0]['uuid'];
        $this->connection->table('commerce_product_media')->insert([
            'uuid' => 'delprodmed01',
            'tenant_uuid' => '',
            'product_uuid' => $product['uuid'],
            'blob_uuid' => 'blobdelj0001',
            'role' => 'cover',
            'position' => 0,
        ]);

        $this->catalog()->deleteProduct($this->context, $product['uuid']);

        self::assertNotNull((new VariantRepository())->findByUuid($this->context, '', $variantUuid));
        self::assertTrue((new StockRepository())->isTracked($this->context, '', $variantUuid));
        self::assertNotNull((new ProductMediaRepository())->findByUuid($this->context, '', 'delprodmed01'));
    }

    public function testHistoricalProductsReportRetainsSnapshotAfterProductDeleted(): void
    {
        $product = $this->seedActiveProduct('delprodK001', 'del-k-001');
        $variantUuid = (string) $product['variants'][0]['uuid'];

        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'delordK00001',
            'tenant_uuid' => '',
            'order_number' => 'ORD-delordK00001',
            'status' => 'paid',
            'email' => 'buyer@example.com',
            'user_uuid' => null,
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 3000,
            'grand_total' => 3000,
            'placed_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => 'delordlineK01',
            'order_uuid' => 'delordK00001',
            'variant_uuid' => $variantUuid,
            'product_name' => 'Original Snapshot Name',
            'sku' => 'DEL-K-SKU',
            'option_values' => '{}',
            'unit_price' => 1000,
            'quantity' => 3,
            'line_total' => 3000,
        ]);

        $this->catalog()->deleteProduct($this->context, $product['uuid']);

        $report = (new ProductSalesReportRepository())->paginate(
            $this->context,
            '',
            ReportWindow::fromDates(null, null),
            'revenue',
            1,
            25
        );
        $item = null;
        foreach ($report['items'] as $row) {
            if ($row['variant_uuid'] === $variantUuid) {
                $item = $row;
            }
        }

        self::assertNotNull($item, 'the deleted product must still contribute its historical snapshot activity');
        self::assertSame('Original Snapshot Name', $item['product_name']);
        self::assertSame('DEL-K-SKU', $item['sku']);
        self::assertSame(3, $item['quantity']);
        self::assertSame(3000, $item['revenue_minor']);
    }

    public function testOrderLineSnapshotUntouchedAfterProductDeletedViaRealCheckout(): void
    {
        [$token, $variantUuid, $productUuid] = $this->seedCartWithLine('DEL-L-SKU', 5, 2, 1500);
        $placed = $this->checkout()->placeOrder(
            $this->context,
            $token,
            ['email' => 'buyer@example.com', 'user_uuid' => null],
            ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            'std'
        );

        $this->catalog()->deleteProduct($this->context, $productUuid);

        $lines = (new OrderRepository())->linesForOrder($this->context, '', (string) $placed['order']['uuid']);
        self::assertCount(1, $lines);
        self::assertSame('DEL-L-SKU', $lines[0]['sku']);
        self::assertSame(2, (int) $lines[0]['quantity']);
        self::assertSame(3000, (int) $lines[0]['line_total']);
    }

    // --- Slug reservation --------------------------------------------------------

    public function testSlugRemainsReservedAfterDeleteRejectsNewProductWith422(): void
    {
        $product = $this->seedActiveProduct('delprodM001', 'del-m-reserved');
        $this->catalog()->deleteProduct($this->context, $product['uuid']);

        try {
            $this->catalog()->createProduct($this->context, [
                'slug' => 'del-m-reserved',
                'name' => 'Reuses tombstoned slug',
                'type' => 'physical',
                'status' => 'active',
                'variants' => [[
                    'sku' => 'DEL-M-NEW',
                    'option_values' => [],
                    'price' => 500,
                    'currency' => 'USD',
                ]],
            ]);
            self::fail('expected ValidationException reusing a tombstoned slug');
        } catch (ValidationException $e) {
            self::assertSame('Slug already in use.', $e->firstError('slug'));
        }
    }

    public function testSlugRemainsReservedAfterDeleteRejectsRenameWith422(): void
    {
        $tombstoned = $this->seedActiveProduct('delprodN001', 'del-n-reserved');
        $this->catalog()->deleteProduct($this->context, $tombstoned['uuid']);
        $other = $this->seedActiveProduct('delprodN002', 'del-n-other');

        try {
            $this->catalog()->updateProduct($this->context, $other['uuid'], ['slug' => 'del-n-reserved']);
            self::fail('expected ValidationException renaming onto a tombstoned slug');
        } catch (ValidationException $e) {
            self::assertSame('Slug already in use.', $e->firstError('slug'));
        }
    }

    // --- Cart/checkout: controlled 422, not a silent dropped line ---------------

    public function testCartAddLineRejectsTombstonedProductWith422NotSilentSuccess(): void
    {
        $product = $this->seedActiveProduct('delprodO001', 'del-o-001');
        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, '', $variantUuid, 5);
        $this->catalog()->deleteProduct($this->context, $product['uuid']);

        ['cart' => $cart] = $this->cartService()->create($this->context);

        $this->expectException(ValidationException::class);
        $this->cartService()->addLine($this->context, $cart, $variantUuid, 1);
    }

    public function testCartViewThrowsControlled422ForLineWhoseProductWasDeletedAfterAdd(): void
    {
        $product = $this->seedActiveProduct('delprodP001', 'del-p-001');
        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, '', $variantUuid, 5);

        ['cart' => $cart] = $this->cartService()->create($this->context);
        $cart = $this->cartService()->addLine($this->context, $cart, $variantUuid, 1);

        $this->catalog()->deleteProduct($this->context, $product['uuid']);

        try {
            $this->cartService()->view($this->context, $cart);
            self::fail('expected ValidationException viewing a cart with a tombstoned-product line');
        } catch (ValidationException $e) {
            self::assertNotSame([], $e->errors(), 'the line must not be silently dropped with no error at all');
        }
    }

    public function testCheckoutQuoteRejectsTombstonedProductLineWith422(): void
    {
        [$token, $variantUuid, $productUuid] = $this->seedCartWithLine('DEL-Q-SKU', 5, 1, 1000);
        $cart = $this->cartService()->byToken($this->context, $token);
        self::assertNotNull($cart);

        $this->catalog()->deleteProduct($this->context, $productUuid);

        $this->expectException(ValidationException::class);
        $this->checkout()->quote($this->context, $cart, ['country' => 'US'], 'std');
    }

    public function testCheckoutPlaceOrderRejectsTombstonedProductLineWith422(): void
    {
        [$token, $variantUuid, $productUuid] = $this->seedCartWithLine('DEL-R-SKU', 5, 1, 1000);

        $this->catalog()->deleteProduct($this->context, $productUuid);

        $this->expectException(ValidationException::class);
        $this->checkout()->placeOrder(
            $this->context,
            $token,
            ['email' => 'buyer@example.com', 'user_uuid' => null],
            ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            'std'
        );
    }

    // --- Vocabulary boundary rejection (create/update) --------------------------

    public function testCreateProductRejectsUnknownStatus(): void
    {
        $response = $this->adminController()->store(
            new CreateProductData(
                slug: 'bogus-status',
                name: 'Bogus Status',
                type: 'physical',
                status: 'published',
                variants: [new ProductVariantData(
                    sku: 'BOGUS-STATUS',
                    price: 500,
                    currency: 'USD',
                )],
            ),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('status', $this->json($response)['error']['details']);
    }

    public function testUpdateProductRejectsUnknownStatus(): void
    {
        $product = $this->seedActiveProduct('delprodS001', 'del-s-001');

        $response = $this->adminController()->update(
            $this->patchRequest(['status' => 'published']),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('status', $this->json($response)['error']['details']);
    }

    public function testVariantPatchRejectsADeletedParentProduct(): void
    {
        $product = $this->seedActiveProduct('delprodT001', 'del-t-001');
        $variantUuid = (string) $product['variants'][0]['uuid'];
        $this->catalog()->deleteProduct($this->context, (string) $product['uuid']);

        try {
            $this->catalog()->updateVariant($this->context, $variantUuid, ['sku' => 'SHOULD-NOT-WRITE']);
            self::fail('Expected a tombstoned product to reject variant mutation.');
        } catch (NotFoundException) {
            $variant = (new VariantRepository())->findByUuid($this->context, '', $variantUuid);
            self::assertNotNull($variant);
            self::assertSame('DELPRODT001', $variant['sku']);
        }
    }

    // --- Helpers -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function seedActiveProduct(string $uuid, string $slug, string $tenant = ''): array
    {
        return $this->catalog($tenant)->createProduct($this->context, [
            'slug' => $slug,
            'name' => $uuid,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper($uuid),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
    }

    /** @return array{0: string, 1: string, 2: string} token, variant uuid, product uuid */
    private function seedCartWithLine(string $sku, int $stock, int $quantity, int $price): array
    {
        $product = $this->catalog()->createProduct($this->context, [
            'slug' => strtolower($sku),
            'name' => $sku,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => $sku,
                'option_values' => [],
                'price' => $price,
                'currency' => 'USD',
            ]],
        ]);
        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, '', $variantUuid, $stock);
        ['cart' => $cart, 'token' => $token] = $this->cartService()->create($this->context);
        $this->cartService()->addLine($this->context, $cart, $variantUuid, $quantity);

        return [$token, $variantUuid, (string) $product['uuid']];
    }

    private function catalog(string $tenant = ''): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant),
            new StockRepository(),
            new ProductChildrenRepository()
        );
    }

    private function adminController(): AdminProductController
    {
        return new AdminProductController(
            $this->context,
            $this->catalog(),
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver()
        );
    }

    private function productController(): ProductController
    {
        return new ProductController(
            $this->context,
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new ProductMediaRepository(),
            new CategoryRepository(),
            new TagRepository(),
            new AttributeRepository(),
            new ProductChildrenRepository(),
            new AddonRepository()
        );
    }

    private function cartService(): CartService
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

    private function checkout(?PaymentCollector $collector = null): CheckoutService
    {
        return new CheckoutService(
            $this->cartService(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), new SentinelTenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->shipping(),
            $this->tax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            $collector ?? new ManualPaymentCollector(),
            new SentinelTenantResolver()
        );
    }

    private function shipping(): ShippingRateProvider
    {
        return new class implements ShippingRateProvider {
            public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
            {
                return [new ShippingQuote('std', 'Standard', 0)];
            }
        };
    }

    private function tax(): TaxCalculator
    {
        return new class implements TaxCalculator {
            public function quote(ApplicationContext $context, int $grandTotal, array $shippingAddress): TaxQuote
            {
                return new TaxQuote(0);
            }
        };
    }

    private function fixedTenant(string $tenant): CurrentTenantResolver
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

    /** @param array<string,mixed> $body */
    private function patchRequest(array $body): Request
    {
        $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
