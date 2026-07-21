<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AddonService;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\StorefrontCatalogChangeDispatcher;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\TagService;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Events\ProductDeleted;
use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundInput;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Repository\BlobRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Commerce-Slice-2 Task 1: {@see StorefrontCatalogChanged} (design spec §9)
 * -- one RED case per closed-vocabulary reason family, driven through the
 * REAL mutation path (never a direct `new StorefrontCatalogChanged(...)`
 * except for the closed-vocabulary unit case), plus rollback/unbound/
 * poison-data guarantees shared across the whole taxonomy.
 */
final class StorefrontCatalogChangedTest extends CommerceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../vendor/glueful/framework/migrations/uploads/001_CreateBlobsTable.php';
        (new \Glueful\Migrations\Uploads\CreateBlobsTable())->up($this->connection->getSchemaBuilder());
    }

    public function testClosedVocabularyRejectsAnUnknownReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StorefrontCatalogChanged('', 'not.a.real.reason');
    }

    public function testEventCarriesOnlyTenantUuidProductUuidAndReason(): void
    {
        $event = new StorefrontCatalogChanged('tenant1', StorefrontCatalogChanged::REASON_STOCK_CHANGED, 'prod1');

        $reflection = new \ReflectionClass($event);
        $publicPropertyNames = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            $reflection->getProperties(\ReflectionProperty::IS_PUBLIC)
        );
        sort($publicPropertyNames);

        self::assertSame(['productUuid', 'reason', 'tenantUuid'], $publicPropertyNames);
    }

    public function testProductCreateDispatchesProductCreatedAfterCommit(): void
    {
        $captured = $this->bindCatalogChangeCapture();

        $product = $this->catalog()->createProduct($this->context, $this->productInput('sfcc-create'));

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_PRODUCT_CREATED);
        self::assertCount(1, $events);
        self::assertSame('', $events[0]->tenantUuid);
        self::assertSame($product['uuid'], $events[0]->productUuid);
        self::assertSame(0, $captured->transactionLevelsAtDispatch[array_key_first($events)] ?? -1);
    }

    public function testProductFieldUpdateDispatchesProductUpdated(): void
    {
        $captured = $this->bindCatalogChangeCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('sfcc-update'));
        $captured->events = [];

        $this->catalog()->updateProduct($this->context, $product['uuid'], ['name' => 'Renamed']);

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_PRODUCT_UPDATED);
        self::assertCount(1, $events);
        self::assertSame($product['uuid'], $events[0]->productUuid);
    }

    public function testProductStatusChangeDispatchesProductStatusChanged(): void
    {
        $captured = $this->bindCatalogChangeCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('sfcc-status'));
        $captured->events = [];

        $this->catalog()->setProductStatus($this->context, $product['uuid'], 'archived');

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_PRODUCT_STATUS_CHANGED);
        self::assertCount(1, $events);
        self::assertSame($product['uuid'], $events[0]->productUuid);
        self::assertCount(0, $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_PRODUCT_UPDATED));
    }

    public function testProductDeleteDispatchesProductDeletedAlongsideTheLegacyEvent(): void
    {
        $captured = $this->bindCatalogChangeCapture();
        $legacy = $this->bindLegacyProductDeletedCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('sfcc-delete'));
        $captured->events = [];

        $this->catalog()->deleteProduct($this->context, $product['uuid']);

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_PRODUCT_DELETED);
        self::assertCount(1, $events);
        self::assertSame($product['uuid'], $events[0]->productUuid);
        self::assertCount(1, $legacy->events, 'ProductDeleted must still fire alongside it');
    }

    public function testVariantCreateDispatchesVariantChanged(): void
    {
        $captured = $this->bindCatalogChangeCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('sfcc-variant-create'));
        $captured->events = [];

        $this->catalog()->createVariant($this->context, $product['uuid'], [
            'sku' => 'SFCC-VARIANT-CREATE-2',
            'option_values' => [],
            'price' => 1200,
            'currency' => 'USD',
        ]);

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_VARIANT_CHANGED);
        self::assertCount(1, $events);
        self::assertSame($product['uuid'], $events[0]->productUuid);
    }

    public function testVariantPriceChangeDispatchesVariantChanged(): void
    {
        $captured = $this->bindCatalogChangeCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('sfcc-variant-price'));
        $captured->events = [];

        $this->catalog()->setVariantPrice($this->context, $product['variants'][0]['uuid'], 2500);

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_VARIANT_CHANGED);
        self::assertCount(1, $events);
        self::assertSame($product['uuid'], $events[0]->productUuid);
    }

    public function testGroupedChildrenSetListDispatchesProductUpdatedScopedToTheParent(): void
    {
        $captured = $this->bindCatalogChangeCapture();
        $parent = $this->catalog()->createProduct($this->context, [
            'slug' => 'sfcc-grouped-parent',
            'name' => 'Grouped parent',
            'type' => 'grouped',
            'status' => 'active',
        ]);
        $child = $this->catalog()->createProduct($this->context, $this->productInput('sfcc-grouped-child'));
        $captured->events = [];

        $this->catalog()->setProductChildren($this->context, $parent['uuid'], [$child['uuid']]);

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_PRODUCT_UPDATED);
        self::assertCount(1, $events);
        self::assertSame($parent['uuid'], $events[0]->productUuid);
    }

    public function testStockChangeViaInventoryAdjustDispatchesStockChanged(): void
    {
        $captured = $this->bindCatalogChangeCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('sfcc-stock-adjust'));
        $variantUuid = (string) $product['variants'][0]['uuid'];
        $captured->events = [];

        $this->inventory()->adjust($this->context, $variantUuid, 5, 'manual');

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_STOCK_CHANGED);
        self::assertCount(1, $events);
        // stock.changed carries a null productUuid by design (review F1): the
        // chokepoint avoids a per-write variant→product SELECT on the hot path.
        self::assertNull($events[0]->productUuid);
    }

    public function testStockChangeViaCheckoutDecrementDispatchesStockChanged(): void
    {
        $captured = $this->bindCatalogChangeCapture();
        [$token, , $productUuid] = $this->seedCartWithLine('SFCC-CHECKOUT', 5, 2, 1000);
        $captured->events = [];

        $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_STOCK_CHANGED);
        self::assertNotEmpty($events, 'checkout decrement must dispatch stock.changed');
        self::assertNull($events[0]->productUuid);
    }

    public function testStockChangeViaRefundRestockDispatchesStockChanged(): void
    {
        [$token, , $productUuid] = $this->seedCartWithLine('SFCC-REFUND', 5, 2, 1000);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        (new OrderPaymentService(new OrderRepository()))->markPaid($this->context, '', (string) $placed['order']['uuid']);
        $order = (new OrderRepository())->findByUuid($this->context, '', (string) $placed['order']['uuid']);
        self::assertNotNull($order);
        $line = $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', (string) $order['uuid'])
            ->first();
        self::assertNotNull($line);

        $captured = $this->bindCatalogChangeCapture();

        $this->refundService()->issue(
            $this->context,
            (string) $order['uuid'],
            new RefundInput(1000, 'one unit', [
                ['order_line_uuid' => (string) $line['uuid'], 'quantity' => 1, 'amount' => 1000],
            ], true),
            'sfcc-refund-restock-1'
        );

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_STOCK_CHANGED);
        self::assertNotEmpty($events, 'refund restock must dispatch stock.changed');
        self::assertNull($events[0]->productUuid);
    }

    public function testStockChangeViaAdminCancelRestockDispatchesStockChanged(): void
    {
        $variantUuid = $this->seedVariantForAdmin('SFCC-CANCEL', 4, 1000);
        $productUuid = (string) $this->connection->table('commerce_variants')
            ->where('uuid', '=', $variantUuid)->first()['product_uuid'];
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, $variantUuid, 2);
        $placed = $this->checkout()->placeOrder(
            $this->context,
            $token,
            $this->buyer(),
            $this->addresses(),
            'std'
        );

        $captured = $this->bindCatalogChangeCapture();

        $this->orderController()->cancel(
            Request::create('/commerce/admin/orders/' . $placed['order']['uuid'] . '/cancel', 'POST'),
            (string) $placed['order']['uuid']
        );

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_STOCK_CHANGED);
        self::assertNotEmpty($events, 'admin cancel restock must dispatch stock.changed');
        self::assertNull($events[0]->productUuid);
    }

    public function testMediaAttachDispatchesMediaChanged(): void
    {
        $captured = $this->bindCatalogChangeCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('sfcc-media'));
        $this->connection->table('blobs')->insert($this->blobRow('sfccblob0001'));
        $captured->events = [];

        $this->media()->attach($this->context, $product['uuid'], [
            'blob_uuid' => 'sfccblob0001',
            'role' => 'gallery',
        ]);

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_MEDIA_CHANGED);
        self::assertCount(1, $events);
        self::assertSame($product['uuid'], $events[0]->productUuid);
    }

    public function testCategoryDefinitionCreateDispatchesCategoryChangedWithNullProductUuid(): void
    {
        $captured = $this->bindCatalogChangeCapture();

        $this->categories()->create($this->context, ['slug' => 'sfcc-cat', 'name' => 'SFCC Category']);

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_CATEGORY_CHANGED);
        self::assertCount(1, $events);
        self::assertNull($events[0]->productUuid);
    }

    public function testSetProductCategoriesDispatchesCategoryChangedScopedToTheProduct(): void
    {
        $captured = $this->bindCatalogChangeCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('sfcc-cat-assign'));
        $category = $this->categories()->create($this->context, ['slug' => 'sfcc-cat-assign', 'name' => 'C']);
        $captured->events = [];

        $this->categories()->setProductCategories($this->context, $product['uuid'], [$category['uuid']]);

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_CATEGORY_CHANGED);
        self::assertCount(1, $events);
        self::assertSame($product['uuid'], $events[0]->productUuid);
    }

    public function testTagDefinitionCreateDispatchesTagChangedWithNullProductUuid(): void
    {
        $captured = $this->bindCatalogChangeCapture();

        $this->tags()->create($this->context, ['slug' => 'sfcc-tag', 'name' => 'SFCC Tag']);

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_TAG_CHANGED);
        self::assertCount(1, $events);
        self::assertNull($events[0]->productUuid);
    }

    public function testSetProductTagsDispatchesTagChangedScopedToTheProduct(): void
    {
        $captured = $this->bindCatalogChangeCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('sfcc-tag-assign'));
        $tag = $this->tags()->create($this->context, ['slug' => 'sfcc-tag-assign', 'name' => 'T']);
        $captured->events = [];

        $this->tags()->setProductTags($this->context, $product['uuid'], [$tag['uuid']]);

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_TAG_CHANGED);
        self::assertCount(1, $events);
        self::assertSame($product['uuid'], $events[0]->productUuid);
    }

    public function testAttributeDefinitionCreateDispatchesAttributeChangedWithNullProductUuid(): void
    {
        $captured = $this->bindCatalogChangeCapture();

        $this->attributes()->create($this->context, ['slug' => 'sfcc-attr', 'name' => 'SFCC Attr']);

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_ATTRIBUTE_CHANGED);
        self::assertCount(1, $events);
        self::assertNull($events[0]->productUuid);
    }

    public function testSetProductAttributesDispatchesAttributeChangedScopedToTheProduct(): void
    {
        $captured = $this->bindCatalogChangeCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('sfcc-attr-assign'));
        $attribute = $this->attributes()->create($this->context, ['slug' => 'sfcc-attr-assign', 'name' => 'A']);
        $captured->events = [];

        $this->attributes()->setProductAttributes($this->context, $product['uuid'], [[
            'attribute_uuid' => $attribute['uuid'],
            'values' => [],
            'used_for_variants' => false,
            'visible' => true,
        ]]);

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_ATTRIBUTE_CHANGED);
        self::assertCount(1, $events);
        self::assertSame($product['uuid'], $events[0]->productUuid);
    }

    public function testAddonCreateDispatchesAddonChangedScopedToTheProduct(): void
    {
        $captured = $this->bindCatalogChangeCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('sfcc-addon'));
        $captured->events = [];

        $this->addons()->create($this->context, $product['uuid'], [
            'name' => 'Gift wrap',
            'field_type' => 'checkbox',
            'price_delta' => 200,
        ]);

        $events = $this->eventsWithReason($captured, StorefrontCatalogChanged::REASON_ADDON_CHANGED);
        self::assertCount(1, $events);
        self::assertSame($product['uuid'], $events[0]->productUuid);
    }

    public function testRolledBackOuterTransactionDispatchesNoStorefrontCatalogChanged(): void
    {
        $captured = $this->bindCatalogChangeCapture();
        $legacy = $this->bindLegacyProductDeletedCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('sfcc-rollback'));
        $captured->events = [];

        $tx = db($this->context)->getTransactionManager();
        $tx->begin();
        $this->catalog()->deleteProduct($this->context, $product['uuid']);
        self::assertCount(0, $captured->events, 'still inside the outer transaction -- must not have fired yet');
        $tx->rollback();

        self::assertCount(0, $captured->events);
        self::assertCount(0, $legacy->events);
    }

    public function testUnboundInstallRunsCreateWithoutDispatchingOrCrashing(): void
    {
        // No StorefrontCatalogChangeDispatcher collaborator supplied -- the same
        // pre-Task-1 direct-construction shape every other catalog test in this
        // suite already uses.
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository()
        );

        $product = $catalog->createProduct($this->context, $this->productInput('sfcc-unbound'));

        self::assertNotEmpty($product['uuid']);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function productInput(string $slug, array $overrides = []): array
    {
        return array_merge([
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '_', $slug)),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ], $overrides);
    }

    /** @return list<StorefrontCatalogChanged> */
    private function eventsWithReason(object $captured, string $reason): array
    {
        return array_values(array_filter(
            $captured->events,
            static fn (StorefrontCatalogChanged $e): bool => $e->reason === $reason
        ));
    }

    private function bindCatalogChangeCapture(): object
    {
        $context = $this->context;
        $capture = new class {
            /** @var list<StorefrontCatalogChanged> */
            public array $events = [];
            /** @var list<int> */
            public array $transactionLevelsAtDispatch = [];
        };
        $listeners = $this->sharedListenerProvider();
        $listeners->addListener(
            StorefrontCatalogChanged::class,
            function (StorefrontCatalogChanged $e) use ($capture, $context): void {
                $capture->events[] = $e;
                $capture->transactionLevelsAtDispatch[] = db($context)->transactionLevel();
            }
        );

        return $capture;
    }

    private function bindLegacyProductDeletedCapture(): object
    {
        $capture = new class {
            /** @var list<ProductDeleted> */
            public array $events = [];
        };
        $this->sharedListenerProvider()->addListener(
            ProductDeleted::class,
            function (ProductDeleted $e) use ($capture): void {
                $capture->events[] = $e;
            }
        );

        return $capture;
    }

    /**
     * One real {@see EventService} shared by every helper in this test, bound lazily
     * on first use -- lets `bindCatalogChangeCapture()` and
     * `bindLegacyProductDeletedCapture()` both register listeners against the SAME
     * dispatcher within a single test method.
     */
    private function sharedListenerProvider(): ListenerProvider
    {
        if (!array_key_exists(EventService::class, $this->bindings)) {
            $listeners = new ListenerProvider();
            $this->bind(EventService::class, new EventService(new EventDispatcher($listeners), $listeners));
            $this->bind('sfcc.listeners', $listeners);
        }

        /** @var ListenerProvider $listeners */
        $listeners = $this->bindings['sfcc.listeners'];

        return $listeners;
    }

    private function catalog(): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            $this->wiredStock(),
            new ProductChildrenRepository(),
            null,
            null,
            null,
            null,
            null,
            new StorefrontCatalogChangeDispatcher()
        );
    }

    private function media(): ProductMediaService
    {
        return new ProductMediaService(
            new ProductRepository(),
            new VariantRepository(),
            new ProductMediaRepository(),
            new SentinelTenantResolver(),
            new BlobRepository($this->connection),
            new StorefrontCatalogChangeDispatcher()
        );
    }

    private function categories(): CategoryService
    {
        return new CategoryService(
            new CategoryRepository(),
            new ProductRepository(),
            new SentinelTenantResolver(),
            null,
            new StorefrontCatalogChangeDispatcher()
        );
    }

    private function tags(): TagService
    {
        return new TagService(
            new TagRepository(),
            new ProductRepository(),
            new SentinelTenantResolver(),
            new StorefrontCatalogChangeDispatcher()
        );
    }

    private function attributes(): AttributeService
    {
        return new AttributeService(
            new AttributeRepository(),
            new ProductRepository(),
            new SentinelTenantResolver(),
            new StorefrontCatalogChangeDispatcher()
        );
    }

    private function addons(): AddonService
    {
        return new AddonService(
            new AddonRepository(),
            new ProductRepository(),
            new SentinelTenantResolver(),
            new StorefrontCatalogChangeDispatcher()
        );
    }

    private function inventory(): InventoryService
    {
        return new InventoryService($this->wiredStock(), new SentinelTenantResolver());
    }

    /**
     * Every StockRepository instance in this test file must be wired with the
     * SAME dispatcher shape (real VariantRepository + a real
     * StorefrontCatalogChangeDispatcher) so `stock.changed` fires regardless of
     * WHICH caller (InventoryService, CheckoutService, RefundService,
     * AdminOrderController) reaches decrement()/increment()/incrementChecked().
     */
    private function wiredStock(): StockRepository
    {
        return new StockRepository(new StorefrontCatalogChangeDispatcher());
    }

    private function checkout(?\Glueful\Extensions\Contracts\Payments\PaymentCollector $collector = null): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), new SentinelTenantResolver()),
            $this->wiredStock(),
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

    private function cart(): CartService
    {
        return new CartService(
            new CartRepository(),
            new VariantRepository(),
            new ProductRepository(),
            $this->wiredStock(),
            new DiscountRepository(),
            new PricingEngine(),
            new SentinelTenantResolver()
        );
    }

    private function refundService(): RefundService
    {
        return new RefundService(
            new OrderRepository(),
            new RefundRepository(),
            $this->wiredStock(),
            new SentinelTenantResolver()
        );
    }

    private function orderController(): AdminOrderController
    {
        return new AdminOrderController(
            $this->context,
            new OrderRepository(),
            $this->wiredStock(),
            new OrderPaymentService(new OrderRepository()),
            new SentinelTenantResolver(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider()
        );
    }

    /** @return array{0: string, 1: string, 2: string} [token, variantUuid, productUuid] */
    private function seedCartWithLine(string $sku, int $stock, int $quantity, int $price): array
    {
        $product = $this->catalog()->createProduct($this->context, $this->productInput(
            strtolower($sku),
            ['variants' => [[
                'sku' => $sku,
                'option_values' => [],
                'price' => $price,
                'currency' => 'USD',
            ]]]
        ));
        $variantUuid = (string) $product['variants'][0]['uuid'];
        $this->wiredStock()->increment($this->context, '', $variantUuid, $stock);
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, $variantUuid, $quantity);

        return [$token, $variantUuid, (string) $product['uuid']];
    }

    private function seedVariantForAdmin(string $sku, int $stock, int $price): string
    {
        $product = $this->catalog()->createProduct($this->context, $this->productInput(
            strtolower($sku),
            ['variants' => [[
                'sku' => $sku,
                'option_values' => [],
                'price' => $price,
                'currency' => 'USD',
            ]]]
        ));
        $variantUuid = (string) $product['variants'][0]['uuid'];
        $this->wiredStock()->increment($this->context, '', $variantUuid, $stock);

        return $variantUuid;
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
    private function blobRow(string $uuid): array
    {
        return [
            'uuid' => $uuid,
            'name' => $uuid,
            'mime_type' => 'image/png',
            'size' => 100,
            'url' => '/storage/' . $uuid,
            'storage_type' => 'local',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'uploader0003',
        ];
    }
}
