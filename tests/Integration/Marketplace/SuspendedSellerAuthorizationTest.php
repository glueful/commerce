<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\DTOs\FulfillSellerOrderData;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Suspended-member fulfillment authorization (design spec §2.6, MV5b Task 5):
 * `commerce_seller:<capability>` gains an explicit route-level second
 * middleware parameter, `allow_suspended` (parsed by the framework's own
 * `Router::resolveMiddleware()` `name:param1,param2` support -- see
 * {@see \Glueful\Routing\Router}). The default for a valid ACTIVE membership
 * on a `suspended` seller is now a stable 409 on EVERY seller route
 * regardless of HTTP method (replacing the prior method-based "all GET/HEAD
 * allowed while suspended" rule) UNLESS the route is one of the five that
 * opts in: order list/detail (`orders.read`), fulfillment/tracking
 * (`orders.fulfill`), and balance/reserves (`reports.read`). The capability
 * check still runs AFTER lifecycle eligibility on an opted-in route --
 * `allow_suspended` never grants a capability. `closed` never qualifies for
 * this allowance -- its pre-existing mutation-409/read-OK posture is
 * untouched (design spec §2.9, proven directly in {@see SellerMiddlewareTest}).
 */
final class SuspendedSellerAuthorizationTest extends CommerceRouterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMarketplace();
        $this->activateWorkspace();
        $this->bindFakeAuth();
    }

    // -----------------------------------------------------------------
    // A suspended seller member WITH the capability CAN reach the five
    // opted-in routes.
    // -----------------------------------------------------------------

    public function testSuspendedOwnerCanListOrders(): void
    {
        $seller = $this->seedSeller('susp-list-orders', 'ownerSuspLst1');
        $this->seedConfirmedSellerOrder($seller['uuid']);
        $this->suspend($seller['uuid']);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerSuspLst1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/orders"
        ));

        self::assertSame(200, $response->getStatusCode(), 'orders.read must stay reachable while suspended');
    }

    public function testSuspendedOwnerCanShowAnOrder(): void
    {
        $seller = $this->seedSeller('susp-show-order', 'ownerSuspShw1');
        $fixture = $this->seedConfirmedSellerOrder($seller['uuid']);
        $this->suspend($seller['uuid']);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerSuspShw1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/orders/{$fixture['seller_order']['uuid']}"
        ));

        self::assertSame(200, $response->getStatusCode(), 'orders.read (show) must stay reachable while suspended');
    }

    public function testSuspendedOwnerCanFulfillAnOrderIncludingTracking(): void
    {
        $seller = $this->seedSeller('susp-fulfill', 'ownerSuspFul1');
        $fixture = $this->seedConfirmedSellerOrder($seller['uuid']);
        $this->suspend($seller['uuid']);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerSuspFul1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/orders/{$fixture['seller_order']['uuid']}/fulfill",
            ['carrier' => 'UPS', 'tracking_number' => '1Z999', 'tracking_url' => 'https://example.com/track']
        ));

        self::assertSame(200, $response->getStatusCode(), 'orders.fulfill (incl. tracking) must work while suspended');
        $body = $this->json($response);
        self::assertSame('fulfilled', $body['data']['fulfillment_status']);
        self::assertSame('1Z999', $body['data']['tracking_number']);
    }

    public function testSuspendedOwnerCanReadBalance(): void
    {
        $seller = $this->seedSeller('susp-balance', 'ownerSuspBal1');
        $this->suspend($seller['uuid']);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerSuspBal1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/financials/balance"
        ));

        self::assertSame(200, $response->getStatusCode(), 'reports.read (balance) must stay reachable while suspended');
    }

    public function testSuspendedOwnerCanReadReserves(): void
    {
        $seller = $this->seedSeller('susp-reserves', 'ownerSuspRsv1');
        $this->suspend($seller['uuid']);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerSuspRsv1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/financials/reserves"
        ));

        self::assertSame(200, $response->getStatusCode(), 'reports.read (reserves) must stay reachable while suspended');
    }

    // -----------------------------------------------------------------
    // Capability absence on an opted-in route is still 403 -- lifecycle
    // eligibility never substitutes for the capability check.
    // -----------------------------------------------------------------

    public function testSuspendedMemberWithoutFulfillCapabilityIs403OnFulfill(): void
    {
        // seller_analyst has orders.read + reports.read but NOT orders.fulfill.
        $seller = $this->seedSeller('susp-no-fulfill-cap', 'ownerSuspNfc1');
        $this->seedMembership($seller['uuid'], 'analystSuspNfc1', 'seller_analyst');
        $fixture = $this->seedConfirmedSellerOrder($seller['uuid']);
        $this->suspend($seller['uuid']);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'analystSuspNfc1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/orders/{$fixture['seller_order']['uuid']}/fulfill",
            ['carrier' => 'UPS', 'tracking_number' => '1Z999', 'tracking_url' => null]
        ));

        self::assertSame(403, $response->getStatusCode(), 'lifecycle-allowed but capability-denied must be 403, not 409');
    }

    public function testSuspendedMemberWithoutReportsCapabilityIs403OnBalance(): void
    {
        // seller_staff has orders.read + orders.fulfill but NOT reports.read.
        $seller = $this->seedSeller('susp-no-reports-cap', 'ownerSuspNrc1');
        $this->seedMembership($seller['uuid'], 'staffSuspNrc1', 'seller_staff');
        $this->suspend($seller['uuid']);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'staffSuspNrc1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/financials/balance"
        ));

        self::assertSame(403, $response->getStatusCode(), 'lifecycle-allowed but capability-denied must be 403, not 409');
    }

    // -----------------------------------------------------------------
    // The SAME member gets a stable 409 on every UNMARKED route, including
    // GET/HEAD reads -- no more method-based allowance.
    // -----------------------------------------------------------------

    public function testSuspendedOwnerGets409OnEveryUnmarkedRoute(): void
    {
        $seller = $this->seedSeller('susp-unmarked', 'ownerSuspUnm1');
        $this->seedProduct($seller['uuid'], 'susp-unmarked-p');
        $this->suspend($seller['uuid']);

        $router = $this->freshRouter();

        /** @var list<array{0:string,1:string,2:array<string,mixed>}> $cases */
        $cases = [
            ['GET', '/products', []],
            ['POST', '/products', [
                'slug' => 'susp-write-blocked',
                'name' => 'susp-write-blocked',
                'type' => 'physical',
                'status' => 'active',
                'variants' => [[
                    'sku' => 'SUSPWRITEBLOCKED',
                    'option_values' => [],
                    'price' => 1000,
                    'currency' => 'USD',
                ]],
            ]],
            ['GET', '/members', []],
            ['POST', '/members', ['user_uuid' => 'someNewUser1', 'role' => 'seller_staff']],
            ['GET', '/financials/report', []],
            ['GET', '/commission-policy', []],
            ['GET', '/payouts', []],
            ['GET', '/payouts/accounts', []],
        ];

        foreach ($cases as [$method, $suffix, $body]) {
            $response = $this->dispatch($router, $this->requestAs(
                'ownerSuspUnm1',
                $method,
                "/commerce/seller/{$seller['uuid']}{$suffix}",
                $body
            ));

            self::assertSame(
                409,
                $response->getStatusCode(),
                "unmarked route {$method} {$suffix} must be 409 for a suspended seller, including reads"
            );
        }
    }

    public function testSuspendedOwnerGets409OnInventoryReadAndWrite(): void
    {
        $seller = $this->seedSeller('susp-inventory', 'ownerSuspInv1');
        $product = $this->seedProduct($seller['uuid'], 'susp-inventory-p');
        $variantUuid = (string) $product['variants'][0]['uuid'];
        $this->suspend($seller['uuid']);

        $router = $this->freshRouter();

        $read = $this->dispatch($router, $this->requestAs(
            'ownerSuspInv1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/variants/{$variantUuid}/stock"
        ));
        self::assertSame(409, $read->getStatusCode(), 'inventory.read must be 409 for a suspended seller');

        $write = $this->dispatch($router, $this->requestAs(
            'ownerSuspInv1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/variants/{$variantUuid}/stock/adjust",
            ['delta' => 1, 'reason' => 'susp-test']
        ));
        self::assertSame(409, $write->getStatusCode(), 'inventory.write must be 409 for a suspended seller');
    }

    // -----------------------------------------------------------------
    // A closed seller never gains the suspended allowance -- a marked
    // route's MUTATION half (fulfill) must stay blocked by the closed
    // mutation-409 rule.
    // -----------------------------------------------------------------

    public function testClosedSellerDoesNotGainAllowSuspendedOnFulfill(): void
    {
        // Seeded directly (mirrors SellerMiddlewareTest's own convention for
        // isolating the status-gate code path): a real close() also revokes
        // every membership, which would independently 404. Flipping status
        // alone proves closed never reaches the allow_suspended branch.
        $seller = $this->seedSeller('closed-no-allowance', 'ownerClosedNa1');
        $fixture = $this->seedConfirmedSellerOrder($seller['uuid']);
        $this->connection->table('commerce_sellers')
            ->where('uuid', '=', $seller['uuid'])
            ->update(['status' => 'closed']);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerClosedNa1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/orders/{$fixture['seller_order']['uuid']}/fulfill",
            ['carrier' => 'UPS', 'tracking_number' => '1Z999', 'tracking_url' => null]
        ));

        self::assertSame(409, $response->getStatusCode(), 'a closed seller must never gain the allow_suspended exemption');
    }

    public function testClosedSellerStillAllowsReadsOnAMarkedRoute(): void
    {
        // The closed posture (mutation-409, read-OK) is untouched by this
        // task -- proven here on one of the newly-marked routes to show the
        // `allow_suspended` change didn't accidentally alter closed's own
        // (pre-existing) read behavior either.
        $seller = $this->seedSeller('closed-reads-ok', 'ownerClosedRd1');
        $this->seedConfirmedSellerOrder($seller['uuid']);
        $this->connection->table('commerce_sellers')
            ->where('uuid', '=', $seller['uuid'])
            ->update(['status' => 'closed']);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerClosedRd1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/orders"
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Active-seller behavior is byte-identical -- no 409 anywhere.
    // -----------------------------------------------------------------

    public function testActiveSellerBehaviorIsUnaffectedOnMarkedAndUnmarkedRoutes(): void
    {
        $seller = $this->seedSeller('active-unaffected', 'ownerActiveUn1');
        $fixture = $this->seedConfirmedSellerOrder($seller['uuid']);
        $this->seedProduct($seller['uuid'], 'active-unaffected-p');

        $router = $this->freshRouter();

        $marked = $this->dispatch($router, $this->requestAs(
            'ownerActiveUn1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/orders/{$fixture['seller_order']['uuid']}"
        ));
        self::assertSame(200, $marked->getStatusCode());

        $unmarkedRead = $this->dispatch($router, $this->requestAs(
            'ownerActiveUn1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/products"
        ));
        self::assertSame(200, $unmarkedRead->getStatusCode());

        $unmarkedWrite = $this->dispatch($router, $this->requestAs(
            'ownerActiveUn1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/financials/report"
        ));
        self::assertSame(200, $unmarkedWrite->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Operator fulfillment remains fully available for a suspended
    // seller's orders (design spec §2.6: "operator fulfillment remains
    // fully available").
    // -----------------------------------------------------------------

    public function testOperatorCanStillFulfillASuspendedSellersOrder(): void
    {
        $seller = $this->seedSeller('operator-fulfills-suspended', 'ownerOpFulSusp1');
        $fixture = $this->seedConfirmedSellerOrder($seller['uuid']);
        $this->suspend($seller['uuid']);

        $orders = new OrderRepository();
        $sellerOrders = new SellerOrderRepository();
        $controller = new AdminOrderController(
            $this->context,
            $orders,
            new StockRepository(),
            new OrderPaymentService($orders, new SellerOrderPaymentConfirmation()),
            $this->fixedTenant(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider(),
            $sellerOrders,
            new SellerOrderFulfillmentService($orders, $sellerOrders)
        );

        $response = $controller->fulfillSellerOrder(
            new FulfillSellerOrderData('UPS', 'OP-TRACK-1', null),
            Request::create(
                '/commerce/admin/orders/' . $fixture['order']['uuid']
                    . '/seller-orders/' . $fixture['seller_order']['uuid'] . '/fulfill',
                'POST'
            ),
            (string) $fixture['order']['uuid'],
            (string) $fixture['seller_order']['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), 'operator fulfillment must remain available for a suspended seller');
    }

    // -----------------------------------------------------------------
    // Fixtures (mirrors SellerOrderSurfaceTest's direct-row-insert
    // convention for these same tables).
    // -----------------------------------------------------------------

    private function suspend(string $sellerUuid): void
    {
        $this->sellerService()->suspend($this->context, $this->tenant, $sellerUuid, 'Under review.', 'operator01');
    }

    /**
     * @param array<string,mixed> $orderOverrides
     * @param array<string,mixed> $sellerOrderOverrides
     * @return array{order: array<string,mixed>, line: array<string,mixed>, seller_order: array<string,mixed>}
     */
    private function seedConfirmedSellerOrder(
        string $sellerUuid,
        array $orderOverrides = [],
        array $sellerOrderOverrides = []
    ): array {
        $order = $this->seedOrder($orderOverrides);
        $line = $this->seedOrderLine($order['uuid'], $sellerUuid);
        $sellerOrder = $this->seedSellerOrderRow($order['uuid'], $sellerUuid, array_merge(
            ['confirmed_at' => gmdate('Y-m-d H:i:s')],
            $sellerOrderOverrides
        ));

        return ['order' => $order, 'line' => $line, 'seller_order' => $sellerOrder];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function seedOrder(array $overrides = []): array
    {
        $uuid = $overrides['uuid'] ?? Utils::generateNanoID();
        $now = gmdate('Y-m-d H:i:s');

        $defaults = [
            'uuid' => $uuid,
            'tenant_uuid' => $this->tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => true,
            'fulfillment_revision' => 0,
            'email' => 'buyer@example.com',
            'user_uuid' => null,
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'refunded_total' => 0,
            'refund_revision' => 0,
            'discount_code' => null,
            'shipping_method' => 'std',
            'addresses' => ['shipping' => $this->defaultShippingAddress()],
            'metadata' => null,
            'placed_at' => $now,
            'created_at' => $now,
        ];

        $order = array_merge($defaults, $overrides);

        $insertRow = $order;
        $insertRow['addresses'] = $order['addresses'] !== null
            ? json_encode($order['addresses'], JSON_THROW_ON_ERROR)
            : null;
        $insertRow['metadata'] = $order['metadata'] !== null
            ? json_encode($order['metadata'], JSON_THROW_ON_ERROR)
            : null;

        $this->connection->table('commerce_orders')->insert($insertRow);

        return $order;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function seedOrderLine(string $orderUuid, string $sellerUuid, array $overrides = []): array
    {
        $uuid = $overrides['uuid'] ?? Utils::generateNanoID();

        $defaults = [
            'uuid' => $uuid,
            'order_uuid' => $orderUuid,
            'variant_uuid' => Utils::generateNanoID(),
            'product_name' => 'Seller Product',
            'sku' => 'SKU-' . $uuid,
            'option_values' => [],
            'unit_price' => 1000,
            'quantity' => 1,
            'line_total' => 1000,
            'seller_uuid' => $sellerUuid,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'addons' => null,
            'downloads' => null,
        ];

        $line = array_merge($defaults, $overrides);

        $insertRow = $line;
        $insertRow['option_values'] = json_encode($line['option_values'] ?? [], JSON_THROW_ON_ERROR);
        $insertRow['addons'] = $line['addons'] !== null ? json_encode($line['addons'], JSON_THROW_ON_ERROR) : null;
        $insertRow['downloads'] = $line['downloads'] !== null
            ? json_encode($line['downloads'], JSON_THROW_ON_ERROR)
            : null;

        $this->connection->table('commerce_order_lines')->insert($insertRow);

        return $line;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function seedSellerOrderRow(string $orderUuid, string $sellerUuid, array $overrides = []): array
    {
        $uuid = $overrides['uuid'] ?? Utils::generateNanoID();
        $now = gmdate('Y-m-d H:i:s');

        $orderRow = $this->connection->table('commerce_orders')->where('uuid', '=', $orderUuid)->first();
        $orderNumber = $orderRow !== null ? (string) $orderRow['order_number'] : ('ORD-' . $orderUuid);

        $defaults = [
            'uuid' => $uuid,
            'tenant_uuid' => $this->tenant,
            'order_uuid' => $orderUuid,
            'seller_uuid' => $sellerUuid,
            'seller_name_snapshot' => 'Seller',
            'partition_number' => 1,
            'seller_reference' => $orderNumber . '-1',
            'currency' => 'USD',
            'subtotal' => 1000,
            'allocated_discount' => 0,
            'allocated_shipping_discount' => 0,
            'allocated_shipping' => 0,
            'allocated_tax' => 0,
            'attributed_total' => 1000,
            'tax_attribution_method' => 'aggregate_allocated',
            'confirmed_at' => $now,
            'fulfillment_status' => 'unfulfilled',
            'fulfilled_at' => null,
            'carrier' => null,
            'tracking_number' => null,
            'tracking_url' => null,
            'status' => 'open',
            'revision' => 0,
            'created_at' => $now,
        ];

        $row = array_merge($defaults, $overrides);
        $this->connection->table('commerce_seller_orders')->insert($row);

        return $row;
    }

    /** @return array{
     *     name: string, company: string, line1: string, line2: string,
     *     city: string, region: string, postcode: string, country: string, phone: string
     * }
     */
    private function defaultShippingAddress(): array
    {
        return [
            'name' => 'Jane Shopper',
            'company' => 'Acme Co',
            'line1' => '742 Evergreen Terrace',
            'line2' => 'Apt 5',
            'city' => 'Springfield',
            'region' => 'IL',
            'postcode' => '62704',
            'country' => 'US',
            'phone' => '+15551234567',
        ];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $body */
    private function requestAs(string $userUuid, string $method, string $uri, array $body = []): Request
    {
        $content = $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $request = Request::create($uri, $method, [], [], [], [], $content);
        if ($content !== null) {
            $request->headers->set('Content-Type', 'application/json');
        }
        $request->headers->set('X-Test-User', $userUuid);

        return $request;
    }
}
