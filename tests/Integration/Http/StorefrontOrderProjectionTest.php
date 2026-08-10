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
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\DTOs\CheckoutPlaceData;
use Glueful\Extensions\Commerce\Http\DTOs\OrderListQuery;
use Glueful\Extensions\Commerce\Http\Storefront\CheckoutController;
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
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Storefront order-projection security: completed-only refunds sanitized to exactly
 * {date, amount_minor, method}, customer-visible notes only, and no leak of operator
 * reason text / internal note bodies / non-completed refunds into the storefront payload.
 * Admin order detail, by contrast, is the trusted full-visibility surface.
 */
final class StorefrontOrderProjectionTest extends CommerceTestCase
{
    private const OPERATOR_REASON = 'ESCALATED_TO_TIER2_FRAUD_REVIEW_DO_NOT_LEAK';
    private const INTERNAL_NOTE_BODY = 'Customer flagged as high chargeback risk internally.';
    private const CUSTOMER_NOTE_BODY = 'Your package shipped a day late, sorry for the delay!';

    /** Sentinel values seeded directly into columns that must NEVER reach the storefront wire. */
    private const TENANT_SENTINEL = 'TENANTLEAK01';
    private const METADATA_SENTINEL = 'ORDER_METADATA_LEAK_SENTINEL_DO_NOT_EXPOSE';
    private const GUEST_TOKEN_RAW = 'ord-proj-guest-token-raw-value';
    private const CUSTOMER_NAME_SENTINEL = 'RATCHET_LEAK_SENTINEL_CUSTOMER_NAME';
    private const PHONE_NORMALIZED_SENTINEL = '+15550009999';
    private const PHONE_DISPLAY_SENTINEL = '+1 (555) 000-9999 RATCHET_LEAK';

    /**
     * Every `commerce_orders` column that legitimately reaches the storefront wire, in the
     * exact order `array_intersect_key` preserves. This is the RATCHET: a column added to
     * `commerce_orders` (or dropped from the wire) must force a conscious edit HERE, not just
     * inside the projection implementation -- enumerated literally, never sourced from the
     * production FIELDS constant, so widening that constant alone can't silently relax this.
     * Excluded on purpose: `id`, `tenant_uuid`, `guest_token_hash`, `marketplace_partitioned`,
     * `fulfillment_revision`, `refund_revision`, `metadata` (an app-internal channel, same
     * treatment as the storefront PRODUCT projection's `metadata` exclusion). Also excluded
     * (admin-order-creation cycle 2, Task 6, design spec §2.6/§2.9): `customer_name`,
     * `phone_normalized`, `phone_display`, `fulfillment_mode`, `origin`, `draft_revision` --
     * walk-in/draft fields never leave the admin surface, let alone the public one.
     */
    private const BASE_ORDER_FIELDS = [
        'uuid',
        'order_number',
        'status',
        'fulfillment_status',
        'tracking_ref',
        'email',
        'user_uuid',
        'currency',
        'subtotal',
        'discount_total',
        'shipping_total',
        'tax_total',
        'grand_total',
        'refunded_total',
        'discount_code',
        'shipping_method',
        'addresses',
        'placed_at',
        'created_at',
        'updated_at',
    ];

    /** Internal `commerce_orders` columns that must never appear on the storefront wire. */
    private const INTERNAL_ORDER_COLUMNS = [
        'id',
        'tenant_uuid',
        'guest_token_hash',
        'marketplace_partitioned',
        'fulfillment_revision',
        'refund_revision',
        'metadata',
        // Admin-order-creation cycle 2, Task 6 (design spec §2.6/§2.9): walk-in/draft
        // columns added to `commerce_orders` -- closed off the storefront wire by default.
        'customer_name',
        'phone_normalized',
        'phone_display',
        'fulfillment_mode',
        'origin',
        'draft_revision',
    ];

    /**
     * The ratchet: every column populated (sentinels in `metadata`, `tenant_uuid`, and the
     * hash backing `guest_token_hash`) so a future column addition can't hide behind a NULL
     * default. `show()` receives the fully enriched {@see \Glueful\Extensions\Commerce\Http\Storefront\OrderController::authorizedOrder()}
     * result -- exactly the allowlisted base fields plus `refunds`/`notes`/`lines` -- and no
     * sentinel value may appear anywhere in the raw serialized body.
     */
    public function testStorefrontShowExposesOnlyAllowlistedKeysAndNeverLeaksSentinels(): void
    {
        $userUuid = 'showprojuser';
        $seeded = $this->seedFullyPopulatedOrder('showprojord1', self::TENANT_SENTINEL, $userUuid);

        $request = Request::create('/commerce/orders/' . $seeded['order_number'], 'GET');
        $request->attributes->set('user', ['uuid' => $userUuid]);

        $response = $this->orderControllerForTenant(self::TENANT_SENTINEL)
            ->show($request, $seeded['order_number']);
        $raw = (string) $response->getContent();
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertEqualsCanonicalizing(
            array_merge(self::BASE_ORDER_FIELDS, ['refunds', 'notes', 'lines']),
            array_keys($body['data'])
        );

        foreach (self::INTERNAL_ORDER_COLUMNS as $column) {
            self::assertArrayNotHasKey(
                $column,
                $body['data'],
                "internal column `{$column}` leaked onto the storefront `show` wire"
            );
        }

        self::assertStringNotContainsString(self::TENANT_SENTINEL, $raw);
        self::assertStringNotContainsString(self::METADATA_SENTINEL, $raw);
        self::assertStringNotContainsString($seeded['guest_token_hash'], $raw);
        self::assertStringNotContainsString(self::CUSTOMER_NAME_SENTINEL, $raw);
        self::assertStringNotContainsString(self::PHONE_NORMALIZED_SENTINEL, $raw);
        self::assertStringNotContainsString(self::PHONE_DISPLAY_SENTINEL, $raw);
    }

    /**
     * `mine()` maps every listed item through the SAME allowlist as `show()` -- proven here
     * against raw, unenriched `commerce_orders` rows (no `refunds`/`notes`/`lines` are ever
     * attached by the listing path), which is exactly why `mine()` leaked every internal
     * column, including `guest_token_hash` itself, before this projection existed.
     */
    public function testStorefrontMineMapsEveryOrderThroughTheSameAllowlist(): void
    {
        $userUuid = 'mineprojuser';
        $seeded = $this->seedFullyPopulatedOrder('mineprojord1', self::TENANT_SENTINEL, $userUuid);

        $request = Request::create('/commerce/orders', 'GET');
        $request->attributes->set('user', ['uuid' => $userUuid]);

        $response = $this->orderControllerForTenant(self::TENANT_SENTINEL)
            ->mine(new OrderListQuery(), $request);
        $raw = (string) $response->getContent();
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['data']);
        $item = $body['data'][0];

        self::assertEqualsCanonicalizing(self::BASE_ORDER_FIELDS, array_keys($item));
        foreach (self::INTERNAL_ORDER_COLUMNS as $column) {
            self::assertArrayNotHasKey(
                $column,
                $item,
                "internal column `{$column}` leaked onto the storefront `mine` wire"
            );
        }

        self::assertStringNotContainsString(self::TENANT_SENTINEL, $raw);
        self::assertStringNotContainsString(self::METADATA_SENTINEL, $raw);
        self::assertStringNotContainsString($seeded['guest_token_hash'], $raw);
        self::assertStringNotContainsString(self::CUSTOMER_NAME_SENTINEL, $raw);
        self::assertStringNotContainsString(self::PHONE_NORMALIZED_SENTINEL, $raw);
        self::assertStringNotContainsString(self::PHONE_DISPLAY_SENTINEL, $raw);
    }

    /** @return array{order_number: string, guest_token_hash: string} */
    private function seedFullyPopulatedOrder(string $uuid, string $tenant, string $userUuid): array
    {
        $orderNumber = 'ORD-' . $uuid;
        $guestTokenHash = TokenHasher::hash(self::GUEST_TOKEN_RAW);

        (new OrderRepository())->insert($this->context, [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => $orderNumber,
            'status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'marketplace_partitioned' => false,
            'fulfillment_revision' => 4,
            'tracking_ref' => 'TRACK-REF-1',
            'email' => 'buyer@example.com',
            'user_uuid' => $userUuid,
            'guest_token_hash' => $guestTokenHash,
            'currency' => 'USD',
            'subtotal' => 5000,
            'discount_total' => 300,
            'shipping_total' => 500,
            'tax_total' => 200,
            'grand_total' => 5400,
            'refunded_total' => 100,
            'refund_revision' => 2,
            'discount_code' => 'SAVE10',
            'shipping_method' => 'std',
            'addresses' => ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            'metadata' => ['note' => self::METADATA_SENTINEL],
            'customer_name' => self::CUSTOMER_NAME_SENTINEL,
            'phone_normalized' => self::PHONE_NORMALIZED_SENTINEL,
            'phone_display' => self::PHONE_DISPLAY_SENTINEL,
            'origin' => 'admin',
            'fulfillment_mode' => 'in_store',
            'draft_revision' => 7,
            'placed_at' => '2026-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
        ]);

        return ['order_number' => $orderNumber, 'guest_token_hash' => $guestTokenHash];
    }

    /**
     * `place()` receives the CHECKOUT SERVICE's raw `order` (payment initiation and
     * other internal consumers of `CheckoutService::placeOrder()` need the internal
     * fields) -- only `CheckoutController::place()`'s HTTP response boundary
     * projects it, exactly like `show()`/`mine()` above. Placed through a REAL
     * cart+checkout run (unlike `show()`/`mine()`'s direct row seeding) because
     * `placeOrder()` builds the order itself; the tenant sentinel is threaded
     * through catalog/cart/checkout so the order actually lands under it, and the
     * guest-token-hash sentinel is derived from the RAW token the response hands
     * back (the real hash `TokenHasher::hash()` would have written to the row).
     * `metadata` is asserted absent by key only -- `placeOrderAttempt()` never
     * writes an order `metadata` value, so there is no live value to seed a
     * leak-sentinel into.
     */
    public function testStorefrontCheckoutPlaceProjectsOrderThroughTheSameAllowlist(): void
    {
        $tenants = $this->tenantResolver(self::TENANT_SENTINEL);
        $variantUuid = $this->seedVariantForTenant(self::TENANT_SENTINEL, 'SKU-PLACE-PROJ', 5, 1000);

        ['cart' => $cart, 'token' => $cartToken] = $this->cartForTenant($tenants)->create($this->context);
        $this->cartForTenant($tenants)->addLine($this->context, $cart, $variantUuid, 1);

        $request = Request::create('/commerce/checkout', 'POST');
        $request->headers->set('X-Cart-Token', $cartToken);

        $response = $this->checkoutControllerForTenant($tenants)->place(
            new CheckoutPlaceData(
                buyer: ['email' => 'buyer@example.com'],
                addresses: ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
                shipping_method: 'std'
            ),
            $request
        );
        $raw = (string) $response->getContent();
        $body = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        $order = $body['data']['order'];

        self::assertEqualsCanonicalizing(self::BASE_ORDER_FIELDS, array_keys($order));
        foreach (self::INTERNAL_ORDER_COLUMNS as $column) {
            self::assertArrayNotHasKey(
                $column,
                $order,
                "internal column `{$column}` leaked onto the checkout `place` wire"
            );
        }

        $guestTokenHash = TokenHasher::hash((string) $body['data']['guest_token']);
        self::assertStringNotContainsString(self::TENANT_SENTINEL, $raw);
        self::assertStringNotContainsString($guestTokenHash, $raw);
    }

    private function orderControllerForTenant(string $tenant): OrderController
    {
        return new OrderController(
            $this->context,
            new OrderRepository(),
            $this->checkout(),
            $this->tenantResolver($tenant),
            new RefundRepository()
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

    private function seedVariantForTenant(string $tenant, string $sku, int $stock, int $price): string
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->tenantResolver($tenant),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
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
        (new StockRepository())->increment($this->context, $tenant, $variantUuid, $stock);

        return $variantUuid;
    }

    private function cartForTenant(CurrentTenantResolver $tenants): CartService
    {
        return new CartService(
            new CartRepository(),
            new VariantRepository(),
            new ProductRepository(),
            new StockRepository(),
            new DiscountRepository(),
            new PricingEngine(),
            $tenants
        );
    }

    private function checkoutForTenant(CurrentTenantResolver $tenants): CheckoutService
    {
        return new CheckoutService(
            $this->cartForTenant($tenants),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), $tenants),
            new StockRepository(),
            new PricingEngine(),
            $this->shipping(),
            $this->tax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            $tenants
        );
    }

    private function checkoutControllerForTenant(CurrentTenantResolver $tenants): CheckoutController
    {
        return new CheckoutController(
            $this->context,
            $this->cartForTenant($tenants),
            $this->checkoutForTenant($tenants),
            null,
            $tenants
        );
    }

    public function testStorefrontShowExposesOnlySanitizedRefundsAndCustomerNotes(): void
    {
        $placed = $this->placeAndPayOrder('SKU-PROJ-1', 5, 1, 1000);
        $orderUuid = (string) $placed['order']['uuid'];
        $number = (string) $placed['order']['order_number'];

        $this->seedRefund($orderUuid, 'refundcompl1', 'idem-proj-completed', [
            'status' => 'completed',
            'reason' => self::OPERATOR_REASON,
            'method' => 'original',
            'amount' => 700,
            'completed_at' => '2026-01-05 12:00:00',
        ]);
        $this->seedRefund($orderUuid, 'refundfaild1', 'idem-proj-failed', [
            'status' => 'failed',
            'reason' => 'gateway declined, ' . self::OPERATOR_REASON,
            'method' => 'original',
            'amount' => 200,
            'failure_reason' => 'provider declined',
        ]);
        $this->seedRefund($orderUuid, 'refundpend1', 'idem-proj-pending', [
            'status' => 'pending',
            'method' => 'original',
            'amount' => 100,
        ]);

        $orders = new OrderRepository();
        $orders->recordEvent(
            $this->context,
            $orderUuid,
            'note.added',
            ['body' => self::INTERNAL_NOTE_BODY, 'visibility' => 'internal', 'notify' => false],
            'internalop01',
            'internal'
        );
        $orders->recordEvent(
            $this->context,
            $orderUuid,
            'note.added',
            ['body' => self::CUSTOMER_NOTE_BODY, 'visibility' => 'customer', 'notify' => true],
            'supportagt1',
            'customer'
        );

        $request = Request::create("/commerce/orders/{$number}", 'GET');
        $request->headers->set('X-Order-Token', (string) $placed['guest_token']);

        $response = $this->orderController()->show($request, $number);
        $raw = (string) $response->getContent();
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());

        // --- refunds: exactly the one completed refund, exactly the three allowed keys ---
        $refunds = $body['data']['refunds'];
        self::assertCount(1, $refunds);
        self::assertSame(['date', 'amount_minor', 'method'], array_keys($refunds[0]));
        self::assertSame('2026-01-05 12:00:00', $refunds[0]['date']);
        self::assertSame(700, $refunds[0]['amount_minor']);
        self::assertSame('original', $refunds[0]['method']);

        // --- notes: exactly the one customer note, exactly {date, body} ---
        $notes = $body['data']['notes'];
        self::assertCount(1, $notes);
        self::assertSame(['date', 'body'], array_keys($notes[0]));
        self::assertSame(self::CUSTOMER_NOTE_BODY, $notes[0]['body']);

        // --- the operator's reason text must not appear anywhere in the serialized body ---
        self::assertStringNotContainsString(self::OPERATOR_REASON, $raw);
        self::assertStringNotContainsString('gateway declined', $raw);
        self::assertStringNotContainsString('provider declined', $raw);
        self::assertStringNotContainsString(self::INTERNAL_NOTE_BODY, $raw);
        foreach (['status', 'reason', 'idempotency_key', 'provider_ref', 'initiated_by', 'failure_reason'] as $key) {
            self::assertArrayNotHasKey($key, $refunds[0]);
        }

        // --- sanity: all three refunds exist in storage; only the completed one is exposed ---
        self::assertSame(
            3,
            $this->connection->table('commerce_refunds')->where('order_uuid', '=', $orderUuid)->count()
        );
    }

    public function testAdminShowIncludesEveryEventRegardlessOfVisibilityWithActorAndVisibility(): void
    {
        $placed = $this->placeAndPayOrder('SKU-PROJ-2', 5, 1, 1000);
        $orderUuid = (string) $placed['order']['uuid'];

        $orders = new OrderRepository();
        $orders->recordEvent(
            $this->context,
            $orderUuid,
            'note.added',
            ['body' => self::INTERNAL_NOTE_BODY, 'visibility' => 'internal', 'notify' => false],
            'internalop01',
            'internal'
        );
        $orders->recordEvent(
            $this->context,
            $orderUuid,
            'note.added',
            ['body' => self::CUSTOMER_NOTE_BODY, 'visibility' => 'customer', 'notify' => true],
            'supportagt1',
            'customer'
        );

        $response = $this->adminOrderController()->show(
            Request::create('/commerce/admin/orders/' . $orderUuid, 'GET'),
            $orderUuid
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $events = $body['data']['events'];
        $types = array_column($events, 'type');

        // Pre-existing internal status events (placed, status:paid, ...) are present.
        self::assertContains('placed', $types);
        self::assertContains('status:paid', $types);

        $noteEvents = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === 'note.added'));
        self::assertCount(2, $noteEvents);

        $internal = self::firstWhere($noteEvents, 'visibility', 'internal');
        $customer = self::firstWhere($noteEvents, 'visibility', 'customer');
        self::assertNotNull($internal);
        self::assertNotNull($customer);
        self::assertSame('internalop01', $internal['actor_uuid']);
        self::assertSame('supportagt1', $customer['actor_uuid']);
        self::assertSame(self::INTERNAL_NOTE_BODY, $internal['payload']['body']);
        self::assertSame(self::CUSTOMER_NOTE_BODY, $customer['payload']['body']);
    }

    /**
     * Storefront order lines must never leak internal `id`/`uuid`/`order_uuid`/
     * `variant_uuid` columns, and `option_values` must be JSON-decoded to an
     * array (never a raw JSON string) — the same whitelist/decode guarantee the
     * addons echo already gets.
     */
    public function testStorefrontShowExposesWhitelistedLineShapeWithDecodedOptionValues(): void
    {
        $placed = $this->placeAndPayOrder('SKU-PROJ-3', 5, 1, 1000, ['color' => 'red']);
        $number = (string) $placed['order']['order_number'];

        $request = Request::create("/commerce/orders/{$number}", 'GET');
        $request->headers->set('X-Order-Token', (string) $placed['guest_token']);

        $response = $this->orderController()->show($request, $number);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $lines = $body['data']['lines'];
        self::assertCount(1, $lines);
        $line = $lines[0];

        self::assertSame(
            ['product_name', 'sku', 'quantity', 'unit_price', 'line_total', 'option_values', 'addons'],
            array_keys($line)
        );
        foreach (['id', 'uuid', 'order_uuid', 'variant_uuid'] as $internalKey) {
            self::assertArrayNotHasKey($internalKey, $line);
        }
        self::assertIsArray($line['option_values']);
        self::assertSame(['color' => 'red'], $line['option_values']);
        self::assertSame('SKU-PROJ-3', $line['sku']);
    }

    /**
     * Admin order lines get the same whitelist/decode guarantee as the storefront
     * projection — admin detail is the trusted full-visibility surface for events,
     * but line rows carry no operational value in their internal id/uuid columns
     * and stay whitelisted just like every other surface (see the controller's
     * class docblock).
     */
    public function testAdminShowExposesWhitelistedLineShapeWithDecodedOptionValues(): void
    {
        $placed = $this->placeAndPayOrder('SKU-PROJ-4', 5, 1, 1000, ['size' => 'M']);
        $orderUuid = (string) $placed['order']['uuid'];

        $response = $this->adminOrderController()->show(
            Request::create('/commerce/admin/orders/' . $orderUuid, 'GET'),
            $orderUuid
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $lines = $body['data']['lines'];
        self::assertCount(1, $lines);
        $line = $lines[0];

        // The operator surface keeps `uuid` deliberately: refund line attribution
        // (CreateRefundData.lines[].order_line_uuid) is built from it.
        self::assertSame(
            ['uuid', 'product_name', 'sku', 'quantity', 'unit_price', 'line_total', 'option_values', 'addons'],
            array_keys($line)
        );
        self::assertNotSame('', $line['uuid']);
        foreach (['id', 'order_uuid', 'variant_uuid'] as $internalKey) {
            self::assertArrayNotHasKey($internalKey, $line);
        }
        self::assertIsArray($line['option_values']);
        self::assertSame(['size' => 'M'], $line['option_values']);
    }

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

    /** @param array<string,mixed> $overrides */
    private function seedRefund(string $orderUuid, string $uuid, string $idempotencyKey, array $overrides): void
    {
        (new RefundRepository())->insert($this->context, array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'order_uuid' => $orderUuid,
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => md5($idempotencyKey),
            'amount' => 500,
            'currency' => 'USD',
            'method' => 'original',
            'status' => 'pending',
            'reason' => null,
            'restocked' => false,
        ], $overrides), []);
    }

    /**
     * Places, pays, and returns an order with a single tracked line, plus its guest token.
     *
     * @param array<string,mixed> $optionValues
     * @return array{order: array<string,mixed>, guest_token: string, payment: array<string,mixed>}
     */
    private function placeAndPayOrder(
        string $sku,
        int $stock,
        int $quantity,
        int $price,
        array $optionValues = []
    ): array {
        $variantUuid = $this->seedVariant($sku, $stock, $price, $optionValues);
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, $variantUuid, $quantity);

        $placed = $this->checkout()->placeOrder(
            $this->context,
            $token,
            ['email' => 'buyer@example.com', 'user_uuid' => null],
            ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            'std'
        );
        $orderUuid = (string) $placed['order']['uuid'];

        (new OrderPaymentService(new OrderRepository()))->markPaid($this->context, '', $orderUuid);
        $order = (new OrderRepository())->findByUuid($this->context, '', $orderUuid);
        self::assertNotNull($order);

        return ['order' => $order, 'guest_token' => (string) $placed['guest_token'], 'payment' => $placed['payment']];
    }

    /** @param array<string,mixed> $optionValues */
    private function seedVariant(string $sku, int $stock, int $price, array $optionValues = []): string
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => strtolower($sku),
            'name' => $sku,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => $sku,
                'option_values' => $optionValues,
                'price' => $price,
                'currency' => 'USD',
            ]],
        ]);
        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, '', $variantUuid, $stock);

        return $variantUuid;
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
