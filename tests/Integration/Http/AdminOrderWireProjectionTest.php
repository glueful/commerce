<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Extensions\Commerce\Customers\AddressBookRepository;
use Glueful\Extensions\Commerce\Customers\CustomerAggregationRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminCustomerController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\OrderProjection;
use Glueful\Extensions\Commerce\Http\DTOs\CustomerLookupQuery;
use Glueful\Extensions\Commerce\Http\DTOs\FulfillOrderData;
use Glueful\Extensions\Commerce\Http\DTOs\OrderListQuery;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Admin order rows must cross the wire through {@see OrderProjection::forAdmin()}
 * on EVERY response path -- never as raw `commerce_orders` rows. The internal
 * columns (`id`, `tenant_uuid`, `guest_token_hash`, `marketplace_partitioned`,
 * `fulfillment_revision`, `refund_revision`) carry no operational value for an
 * admin client and `guest_token_hash` is secret-derived material; before this
 * projection they all leaked from index/show/cancel/markPaid/fulfill and from
 * the customer detail's embedded recent orders.
 *
 * The projection is response-boundary ONLY: `show()` still keys its
 * `seller_orders` embed off the order's own `marketplace_partitioned` snapshot
 * (read from the RAW row, before projecting), and `fulfill()` still hands the
 * RAW row to the `OrderFulfilled` event (listeners/webhook fan-out depend on
 * internal columns).
 */
final class AdminOrderWireProjectionTest extends CommerceTestCase
{
    /** Internal columns that must never appear on the admin wire. */
    private const INTERNAL_COLUMNS = [
        'id',
        'tenant_uuid',
        'guest_token_hash',
        'marketplace_partitioned',
        'fulfillment_revision',
        'refund_revision',
        // Admin-order-creation cycle 2, Task 6 (design spec §2.6/§2.9): draft state
        // stays off the ordinary (finalized-order) admin wire -- only the five
        // finalized walk-in fields (asserted separately below) are added.
        'draft_revision',
    ];

    // -----------------------------------------------------------------
    // index
    // -----------------------------------------------------------------

    public function testIndexProjectsEveryRowToTheAdminWhitelist(): void
    {
        $this->seedOrder('ordproj00001', 'paid');

        $response = $this->orderController()->index(
            new OrderListQuery(),
            Request::create('/commerce/admin/orders', 'GET')
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['data']);
        $item = $body['data'][0];

        $this->assertNoInternalColumns($item);
        self::assertEqualsCanonicalizing(OrderProjection::FIELDS, array_keys($item));
        self::assertSame('ordproj00001', $item['uuid']);
        self::assertSame(5000, (int) $item['grand_total']);
        self::assertSame('USD', $item['currency']);
    }

    /**
     * Admin-order-creation cycle 2, Task 6: the five closed finalized walk-in fields
     * are on the admin wire with their real values, while `draft_revision` -- seeded
     * with a sentinel that would be impossible to miss if it ever leaked -- is not.
     */
    public function testIndexProjectsTheFiveWalkInFieldsButNeverDraftRevision(): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'ordproj0walk',
            'tenant_uuid' => '',
            'order_number' => 'ORD-ordproj0walk',
            'status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'fulfillment_revision' => 1,
            'refund_revision' => 0,
            'draft_revision' => 999999,
            'email' => null,
            'user_uuid' => null,
            'guest_token_hash' => null,
            'phone_normalized' => '+15559998888',
            'phone_display' => '+1 (555) 999-8888',
            'customer_name' => 'Walk-in Customer',
            'origin' => 'admin',
            'fulfillment_mode' => 'in_store',
            'currency' => 'USD',
            'subtotal' => 5000,
            'grand_total' => 5000,
        ]);

        $response = $this->orderController()->index(
            new OrderListQuery(),
            Request::create('/commerce/admin/orders', 'GET')
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $item = self::firstWhere($body['data'], 'uuid', 'ordproj0walk');
        self::assertNotNull($item);

        $this->assertNoInternalColumns($item);
        self::assertSame('+15559998888', $item['phone_normalized']);
        self::assertSame('+1 (555) 999-8888', $item['phone_display']);
        self::assertSame('Walk-in Customer', $item['customer_name']);
        self::assertSame('admin', $item['origin']);
        self::assertSame('in_store', $item['fulfillment_mode']);
        self::assertArrayNotHasKey('draft_revision', $item);
        self::assertStringNotContainsString('999999', (string) $response->getContent());
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

    // -----------------------------------------------------------------
    // show -- projection must not break the marketplace embed decision
    // -----------------------------------------------------------------

    public function testShowProjectsRowAndStillEmbedsSellerOrdersForPartitionedOrders(): void
    {
        $this->seedOrder('ordproj00002', 'paid', partitioned: true);

        $response = $this->orderController()->show(
            Request::create('/commerce/admin/orders/ordproj00002', 'GET'),
            'ordproj00002'
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $order = $body['data'];

        $this->assertNoInternalColumns($order);
        // The embed decision reads marketplace_partitioned from the RAW row
        // BEFORE projecting -- the key must be gone while its effect remains.
        self::assertArrayHasKey('seller_orders', $order);
        self::assertArrayHasKey('events', $order);
        self::assertArrayHasKey('lines', $order);
        self::assertEqualsCanonicalizing(
            array_merge(OrderProjection::FIELDS, ['events', 'lines', 'seller_orders']),
            array_keys($order)
        );
    }

    public function testShowOmitsSellerOrdersForNonPartitionedOrders(): void
    {
        $this->seedOrder('ordproj00003', 'paid');

        $response = $this->orderController()->show(
            Request::create('/commerce/admin/orders/ordproj00003', 'GET'),
            'ordproj00003'
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $this->assertNoInternalColumns($body['data']);
        self::assertArrayNotHasKey('seller_orders', $body['data']);
    }

    // -----------------------------------------------------------------
    // mutation responses (cancel / markPaid / fulfill)
    // -----------------------------------------------------------------

    public function testCancelResponseIsProjected(): void
    {
        $this->seedOrder('ordproj00004', 'paid');

        $response = $this->orderController()->cancel(
            Request::create('/commerce/admin/orders/ordproj00004/cancel', 'POST'),
            'ordproj00004'
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $this->assertNoInternalColumns($body['data']);
        self::assertEqualsCanonicalizing(OrderProjection::FIELDS, array_keys($body['data']));
        self::assertSame('canceled', $body['data']['status']);
    }

    public function testMarkPaidResponseIsProjected(): void
    {
        $this->seedOrder('ordproj00005', 'pending_payment');

        $response = $this->orderController()->markPaid(
            Request::create('/commerce/admin/orders/ordproj00005/mark-paid', 'POST'),
            'ordproj00005'
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $this->assertNoInternalColumns($body['data']);
        self::assertEqualsCanonicalizing(OrderProjection::FIELDS, array_keys($body['data']));
        self::assertSame('paid', $body['data']['status']);
    }

    public function testFulfillResponseIsProjected(): void
    {
        $this->seedOrder('ordproj00006', 'paid');

        $response = $this->orderController()->fulfill(
            new FulfillOrderData(tracking_ref: 'TRK-1'),
            Request::create('/commerce/admin/orders/ordproj00006/fulfill', 'POST'),
            'ordproj00006'
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $this->assertNoInternalColumns($body['data']);
        self::assertEqualsCanonicalizing(OrderProjection::FIELDS, array_keys($body['data']));
        self::assertSame('fulfilled', $body['data']['status']);
    }

    // -----------------------------------------------------------------
    // customer detail's embedded recent orders
    // -----------------------------------------------------------------

    public function testCustomerShowEmbeddedOrdersAreProjected(): void
    {
        $this->seedOrder('ordproj00007', 'paid', email: 'projected@example.com');

        $query = (new RequestDataHydrator())->hydrate(
            CustomerLookupQuery::class,
            [],
            [],
            ['by' => 'email']
        );
        self::assertInstanceOf(CustomerLookupQuery::class, $query);

        $response = $this->customerController()->show(
            $query,
            Request::create('/commerce/admin/customers/projected@example.com', 'GET'),
            'projected@example.com'
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($body['data']['orders']);
        foreach ($body['data']['orders'] as $order) {
            $this->assertNoInternalColumns($order);
            self::assertEqualsCanonicalizing(OrderProjection::FIELDS, array_keys($order));
        }
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $payload */
    private function assertNoInternalColumns(array $payload): void
    {
        foreach (self::INTERNAL_COLUMNS as $column) {
            self::assertArrayNotHasKey($column, $payload, "internal column `{$column}` leaked onto the admin wire");
        }
    }

    private function orderController(): AdminOrderController
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

    private function customerController(): AdminCustomerController
    {
        return new AdminCustomerController(
            $this->context,
            new CustomerAggregationRepository(),
            new OrderRepository(),
            new SentinelTenantResolver(),
            null,
            new AddressBookRepository()
        );
    }

    private function seedOrder(
        string $uuid,
        string $status,
        bool $partitioned = false,
        string $email = 'buyer@example.com',
    ): void {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => $partitioned,
            'fulfillment_revision' => 3,
            'refund_revision' => 2,
            'email' => $email,
            'user_uuid' => null,
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 5000,
            'grand_total' => 5000,
        ]);
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
