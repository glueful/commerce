<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
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
     * @return array{order: array<string,mixed>, guest_token: string, payment: array<string,mixed>}
     */
    private function placeAndPayOrder(string $sku, int $stock, int $quantity, int $price): array
    {
        $variantUuid = $this->seedVariant($sku, $stock, $price);
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

    private function seedVariant(string $sku, int $stock, int $price): string
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
                'option_values' => [],
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
            new SentinelTenantResolver()
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
