<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Seller-scoped order surfaces (design spec §6.1/§2.12, MV2 Task 8):
 * list/detail/fulfill over REAL routes -- the full capability x role
 * matrix, cross-seller/unknown/non-partitioned non-revealing 404s, the
 * `confirmed_at` payment-confirmation gate on read AND fulfill, the
 * confirmed-only listing (+ `fulfillment_status` filter), the §2.12
 * shipping-only allowlist proven with poison strings, and the fulfill happy
 * path delegating to {@see \Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService}
 * (Task 7).
 *
 * Fixtures are seeded by DIRECT row inserts into `commerce_orders` /
 * `commerce_order_lines` / `commerce_seller_orders` (never through
 * `CheckoutService`) -- these read surfaces don't care how a row got there,
 * and direct seeding gives full, precise control over PII fields for the
 * poison-string assertions (mirrors {@see SellerMiddlewareTest}'s own
 * direct-row-insert convention for its cross-tenant fixture).
 */
final class SellerOrderSurfaceTest extends CommerceRouterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMarketplace();
        $this->activateWorkspace();
        $this->bindFakeAuth();
    }

    // -----------------------------------------------------------------
    // Full capability x role matrix over real routes.
    // -----------------------------------------------------------------

    public function testOrdersReadCapabilityAllowsAllFourRoles(): void
    {
        $seller = $this->seedSeller('orders-read-matrix', 'ownerOrdRead1');
        $this->seedMembership($seller['uuid'], 'adminOrdRead1', 'seller_admin');
        $this->seedMembership($seller['uuid'], 'staffOrdRead1', 'seller_staff');
        $this->seedMembership($seller['uuid'], 'analystOrdRd1', 'seller_analyst');
        $this->seedConfirmedSellerOrder($seller['uuid']);

        $router = $this->freshRouter();
        foreach (['ownerOrdRead1', 'adminOrdRead1', 'staffOrdRead1', 'analystOrdRd1'] as $userUuid) {
            $response = $this->dispatch($router, $this->requestAs(
                $userUuid,
                'GET',
                "/commerce/seller/{$seller['uuid']}/orders"
            ));

            self::assertSame(200, $response->getStatusCode(), "orders.read must allow {$userUuid}");
        }
    }

    public function testOrdersFulfillCapabilityAllowsOwnerAdminStaffButDeniesAnalyst(): void
    {
        $seller = $this->seedSeller('orders-fulfill-matrix', 'ownerOrdFul1');
        $this->seedMembership($seller['uuid'], 'adminOrdFul1', 'seller_admin');
        $this->seedMembership($seller['uuid'], 'staffOrdFul1', 'seller_staff');
        $this->seedMembership($seller['uuid'], 'analystOrdFl1', 'seller_analyst');

        $router = $this->freshRouter();
        $expected = [
            'ownerOrdFul1' => 200,
            'adminOrdFul1' => 200,
            'staffOrdFul1' => 200,
            'analystOrdFl1' => 403,
        ];
        foreach ($expected as $userUuid => $status) {
            // A fresh confirmed, unfulfilled partition per actor -- a
            // successful prior fulfill would otherwise 409 the next one.
            $fixture = $this->seedConfirmedSellerOrder($seller['uuid']);
            $response = $this->dispatch($router, $this->requestAs(
                $userUuid,
                'POST',
                "/commerce/seller/{$seller['uuid']}/orders/{$fixture['seller_order']['uuid']}/fulfill",
                ['carrier' => 'UPS', 'tracking_number' => '1Z999', 'tracking_url' => null]
            ));

            self::assertSame($status, $response->getStatusCode(), "orders.fulfill for {$userUuid}");
        }
    }

    // -----------------------------------------------------------------
    // Cross-seller / unknown / non-partitioned -- non-revealing 404.
    // -----------------------------------------------------------------

    public function testCrossSellerSellerOrderAccessIsNotFound(): void
    {
        $sellerA = $this->seedSeller('cross-seller-a', 'ownerCrossA1');
        $sellerB = $this->seedSeller('cross-seller-b', 'ownerCrossB1');
        $fixtureB = $this->seedConfirmedSellerOrder($sellerB['uuid']);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerCrossA1',
            'GET',
            "/commerce/seller/{$sellerA['uuid']}/orders/{$fixtureB['seller_order']['uuid']}"
        ));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testCrossSellerAndUnknownSellerOrderProduceIdenticalNonRevealingResponses(): void
    {
        $sellerA = $this->seedSeller('nonreveal-a', 'ownerNonRevA1');
        $sellerB = $this->seedSeller('nonreveal-b', 'ownerNonRevB1');
        $fixtureB = $this->seedConfirmedSellerOrder($sellerB['uuid']);

        $router = $this->freshRouter();
        $crossSeller = $this->dispatch($router, $this->requestAs(
            'ownerNonRevA1',
            'GET',
            "/commerce/seller/{$sellerA['uuid']}/orders/{$fixtureB['seller_order']['uuid']}"
        ));
        $unknown = $this->dispatch($router, $this->requestAs(
            'ownerNonRevA1',
            'GET',
            "/commerce/seller/{$sellerA['uuid']}/orders/doesNotExist1"
        ));

        self::assertSame(404, $crossSeller->getStatusCode());
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame(
            $this->json($unknown),
            $this->json($crossSeller),
            'cross-seller and unknown-uuid must be byte-identical, never distinguishable'
        );
    }

    public function testUnknownSellerOrderUuidIsNotFound(): void
    {
        $seller = $this->seedSeller('unknown-order', 'ownerUnknown1');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUnknown1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/orders/doesNotExistAtAll"
        ));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testNonPartitionedOrdersSellerOrderIsNotFound(): void
    {
        $seller = $this->seedSeller('non-partitioned', 'ownerNonPart1');
        // Adversarial fixture: a confirmed commerce_seller_orders row whose
        // parent order is (somehow) not partitioned -- unreachable via any
        // real checkout path, but SellerOrderService must still fail closed
        // (design spec §6.4) rather than trust the child row alone.
        $fixture = $this->seedConfirmedSellerOrder($seller['uuid'], orderOverrides: [
            'marketplace_partitioned' => false,
        ]);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerNonPart1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/orders/{$fixture['seller_order']['uuid']}"
        ));

        self::assertSame(404, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Unconfirmed partition -- 404 on list (omitted), detail, AND fulfill.
    // -----------------------------------------------------------------

    public function testUnconfirmedPartitionIsInvisibleOnListDetailAndFulfill(): void
    {
        $seller = $this->seedSeller('unconfirmed', 'ownerUnconf1');
        $pending = $this->seedConfirmedSellerOrder($seller['uuid'], confirmed: false);

        $router = $this->freshRouter();

        $list = $this->dispatch($router, $this->requestAs(
            'ownerUnconf1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/orders"
        ));
        self::assertSame(200, $list->getStatusCode());
        self::assertSame(0, $this->json($list)['total'], 'a pending-payment partition must be omitted from the list');

        $detail = $this->dispatch($router, $this->requestAs(
            'ownerUnconf1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/orders/{$pending['seller_order']['uuid']}"
        ));
        self::assertSame(404, $detail->getStatusCode());

        $fulfill = $this->dispatch($router, $this->requestAs(
            'ownerUnconf1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/orders/{$pending['seller_order']['uuid']}/fulfill",
            ['carrier' => 'UPS', 'tracking_number' => '1Z999', 'tracking_url' => null]
        ));
        self::assertSame(404, $fulfill->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Confirmed-only listing + fulfillment_status filter.
    // -----------------------------------------------------------------

    public function testListingReturnsOnlyConfirmedSellerOrders(): void
    {
        $seller = $this->seedSeller('confirmed-listing', 'ownerConfList1');
        $paid = $this->seedConfirmedSellerOrder($seller['uuid']);
        $this->seedConfirmedSellerOrder($seller['uuid'], confirmed: false);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerConfList1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/orders"
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame($paid['seller_order']['uuid'], $body['data'][0]['uuid']);
    }

    public function testListingFiltersByFulfillmentStatus(): void
    {
        $seller = $this->seedSeller('status-filter', 'ownerStatFlt1');
        $this->seedConfirmedSellerOrder($seller['uuid']);
        $fulfilled = $this->seedConfirmedSellerOrder($seller['uuid'], sellerOrderOverrides: [
            'fulfillment_status' => 'fulfilled',
            'fulfilled_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerStatFlt1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/orders?fulfillment_status=fulfilled"
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame($fulfilled['seller_order']['uuid'], $body['data'][0]['uuid']);
    }

    // -----------------------------------------------------------------
    // Shipping-only allowlist, proven with poison strings.
    // -----------------------------------------------------------------

    public function testDetailExposesOnlyTheShippingAllowlistNeverPoisonFields(): void
    {
        $sellerA = $this->seedSeller('allowlist-a', 'ownerAllowA1');
        $sellerB = $this->seedSeller('allowlist-b', 'ownerAllowB1');

        $fixture = $this->seedConfirmedSellerOrder($sellerA['uuid'], orderOverrides: [
            'email' => 'buyer+EMAILLEAKSENTINEL@example.com',
            'user_uuid' => 'USRLEAKUUID1',
            'guest_token_hash' => str_pad('GUESTTOKENLEAKSENTINEL', 64, '0'),
            'addresses' => [
                'shipping' => $this->defaultShippingAddress(),
                'billing' => [
                    'name' => 'BILLINGLEAKSENTINELNAME',
                    'line1' => 'BILLINGLEAKSENTINELLINE1',
                    'city' => 'BILLINGLEAKSENTINELCITY',
                    'country' => 'US',
                ],
            ],
            'metadata' => [
                'note' => 'METADATALEAKSENTINEL',
                'payment_token' => 'PAYMENTTOKENLEAKSENTINEL',
            ],
        ], lineOverrides: [
            'product_name' => 'Seller A Widget',
            'downloads' => [['token_hash' => 'DOWNLOADTOKENLEAKSENTINEL', 'remaining' => 1]],
        ]);

        // A second seller's line on the SAME order -- proves "only this
        // seller's lines", never a cross-seller leak through the shared
        // order-lines table.
        $this->seedOrderLine($fixture['order']['uuid'], $sellerB['uuid'], [
            'product_name' => 'SELLERBLINELEAKSENTINEL',
        ]);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerAllowA1',
            'GET',
            "/commerce/seller/{$sellerA['uuid']}/orders/{$fixture['seller_order']['uuid']}"
        ));

        self::assertSame(200, $response->getStatusCode());
        $raw = (string) $response->getContent();
        $body = $this->json($response);
        $decodedDump = print_r($body, true);

        $poisons = [
            'EMAILLEAKSENTINEL',
            'USRLEAKUUID1',
            'GUESTTOKENLEAKSENTINEL',
            'BILLINGLEAKSENTINELNAME',
            'BILLINGLEAKSENTINELLINE1',
            'BILLINGLEAKSENTINELCITY',
            'METADATALEAKSENTINEL',
            'PAYMENTTOKENLEAKSENTINEL',
            'DOWNLOADTOKENLEAKSENTINEL',
            'SELLERBLINELEAKSENTINEL',
        ];
        foreach ($poisons as $poison) {
            self::assertStringNotContainsString($poison, $raw, "{$poison} must never appear in the raw response");
            self::assertStringNotContainsString(
                $poison,
                $decodedDump,
                "{$poison} must never appear in the decoded body"
            );
        }

        self::assertSame($this->defaultShippingAddress(), $body['data']['shipping_address']);

        self::assertCount(1, $body['data']['lines'], 'only this seller\'s own line(s)');
        self::assertSame('Seller A Widget', $body['data']['lines'][0]['product_name']);
    }

    public function testDetailExposesTheSnapshottedCommissionPolicyPerLine(): void
    {
        // §2.4/§6.2: the seller sees the commission SNAPSHOTTED on each of their
        // own sold lines (their own money data) -- the "snapshotted" half of the
        // effective+snapshotted policy view.
        $seller = $this->seedSeller('commission-view', 'ownerCommV001');
        $fixture = $this->seedConfirmedSellerOrder($seller['uuid'], lineOverrides: [
            'product_name' => 'Commissioned Widget',
            'line_total' => 1000,
            'discount_amount' => 0,
            'commission_source' => 'seller',
            'commission_kind' => 'percentage',
            'commission_bps' => 1500,
            'commission_fixed' => null,
            'commission_basis' => 1000,
            'commission_amount' => 150,
        ]);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerCommV001',
            'GET',
            "/commerce/seller/{$seller['uuid']}/orders/{$fixture['seller_order']['uuid']}"
        ));

        self::assertSame(200, $response->getStatusCode());
        $commission = $this->json($response)['data']['lines'][0]['commission'];
        self::assertSame('seller', $commission['source']);
        self::assertSame('percentage', $commission['kind']);
        self::assertSame(1500, $commission['bps']);
        self::assertNull($commission['fixed']);
        self::assertSame(1000, $commission['basis']);
        self::assertSame(150, $commission['amount']);
    }

    public function testShippingAddressNormalizesRegionPostcodeAndNameAliases(): void
    {
        $seller = $this->seedSeller('alias-normalize', 'ownerAlias0001');
        $fixture = $this->seedConfirmedSellerOrder($seller['uuid'], orderOverrides: [
            'addresses' => [
                'shipping' => [
                    'first_name' => 'Jane',
                    'last_name' => 'Shopper',
                    'line1' => '1 Main St',
                    'city' => 'Springfield',
                    'state' => 'IL',
                    'zip' => '62704',
                    'country' => 'US',
                ],
            ],
        ]);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerAlias0001',
            'GET',
            "/commerce/seller/{$seller['uuid']}/orders/{$fixture['seller_order']['uuid']}"
        ));

        self::assertSame(200, $response->getStatusCode());
        $shipping = $this->json($response)['data']['shipping_address'];
        self::assertSame('Jane Shopper', $shipping['name']);
        self::assertSame('IL', $shipping['region']);
        self::assertSame('62704', $shipping['postcode']);
    }

    // -----------------------------------------------------------------
    // Fulfill happy path: transitions the child + rolls up (Task 7).
    // -----------------------------------------------------------------

    public function testFulfillHappyPathTransitionsChildAndRollsUpParent(): void
    {
        $seller = $this->seedSeller('fulfill-happy', 'ownerFulHap01');
        $fixture = $this->seedConfirmedSellerOrder($seller['uuid']);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerFulHap01',
            'POST',
            "/commerce/seller/{$seller['uuid']}/orders/{$fixture['seller_order']['uuid']}/fulfill",
            ['carrier' => 'UPS', 'tracking_number' => '1Z999', 'tracking_url' => 'https://example.com/track']
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('fulfilled', $body['data']['fulfillment_status']);
        self::assertSame('UPS', $body['data']['carrier']);
        self::assertSame('1Z999', $body['data']['tracking_number']);
        self::assertSame('https://example.com/track', $body['data']['tracking_url']);

        // Single-seller order: the parent rolls straight to `fulfilled`
        // (design spec §2.8) -- delegated entirely to
        // SellerOrderFulfillmentService::fulfill() (Task 7).
        $orderRow = $this->connection->table('commerce_orders')
            ->where('uuid', '=', $fixture['order']['uuid'])
            ->first();
        self::assertSame('fulfilled', $orderRow['fulfillment_status']);
        self::assertSame('fulfilled', $orderRow['status']);

        $childRow = $this->connection->table('commerce_seller_orders')
            ->where('uuid', '=', $fixture['seller_order']['uuid'])
            ->first();
        self::assertSame('fulfilled', $childRow['fulfillment_status']);
        self::assertNotNull($childRow['fulfilled_at']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $orderOverrides
     * @param array<string,mixed> $sellerOrderOverrides
     * @param array<string,mixed> $lineOverrides
     * @return array{order: array<string,mixed>, line: array<string,mixed>, seller_order: array<string,mixed>}
     */
    private function seedConfirmedSellerOrder(
        string $sellerUuid,
        bool $confirmed = true,
        array $orderOverrides = [],
        array $sellerOrderOverrides = [],
        array $lineOverrides = []
    ): array {
        $order = $this->seedOrder($orderOverrides);
        $line = $this->seedOrderLine($order['uuid'], $sellerUuid, $lineOverrides);
        $sellerOrder = $this->seedSellerOrderRow($order['uuid'], $sellerUuid, array_merge(
            ['confirmed_at' => $confirmed ? gmdate('Y-m-d H:i:s') : null],
            $sellerOrderOverrides
        ));

        return ['order' => $order, 'line' => $line, 'seller_order' => $sellerOrder];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed> the seeded row, JSON columns as PHP arrays
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
     * @return array<string,mixed> the seeded row, JSON columns as PHP values
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
