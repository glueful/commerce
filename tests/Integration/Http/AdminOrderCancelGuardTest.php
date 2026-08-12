<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Orders\PaymentSessionExposureException;
use Glueful\Extensions\Commerce\Orders\PaymentSessionExposureGuard;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Payment-links Task 8 (design spec §2.2): "the ordinary admin cancel endpoint
 * uses the same guard so it cannot bypass this policy".
 *
 * An order whose payment link has EXPOSED a provider session may still be
 * paying. Canceling it releases the stock and closes the order while money may
 * be in flight, so the operator must say so explicitly --
 * `accept_late_payment_risk=true` -- and that acknowledgement is recorded in the
 * SAME transaction, BEFORE any stock moves.
 */
final class AdminOrderCancelGuardTest extends CommerceTestCase
{
    private const TENANT = '';
    private const ORDER = 'cancelordr01';
    private const VARIANT = 'cancelvar001';

    public function testCancelIsRefusedWithNoAcknowledgementWhenASessionWasExposed(): void
    {
        $this->seedOrder();
        $this->seedLink('cancellink01', issuedAt: '2026-08-11 08:20:00');

        $response = $this->controller()->cancel($this->request([]), self::ORDER);
        $body = $this->json($response);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            PaymentSessionExposureException::RISK_UNACKNOWLEDGED,
            $body['error']['details']['reason']
        );
        self::assertSame('pending_payment', $this->statusOf(self::ORDER));
        self::assertSame(4, $this->stock(), 'a refused cancel releases nothing');
        self::assertSame([], $this->eventTypes());
    }

    public function testCancelSucceedsWithTheAcknowledgementAndRecordsItBeforeTheStockRelease(): void
    {
        $this->seedOrder();
        $this->seedLink('cancellink02', issuedAt: '2026-08-11 08:20:00');

        $response = $this->controller()->cancel(
            $this->request([PaymentSessionExposureGuard::ACKNOWLEDGEMENT_FIELD => true]),
            self::ORDER
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('canceled', $this->statusOf(self::ORDER));
        self::assertSame(6, $this->stock());
        // ORDER MATTERS: the acknowledgement is recorded BEFORE the cancellation
        // transition (and therefore before the stock release that sits between
        // them), exactly as design spec §2.2 requires.
        self::assertSame(
            [PaymentSessionExposureGuard::RISK_ACCEPTED_EVENT, 'status:canceled'],
            $this->eventTypes()
        );

        // The audit row and the release are one transaction: the movement row
        // exists only because the acknowledgement was recorded first.
        $movement = $this->connection->table('commerce_stock_movements')
            ->where('variant_uuid', '=', self::VARIANT)
            ->orderBy('id', 'DESC')
            ->first();
        self::assertNotNull($movement);
        self::assertSame('release', (string) $movement['reason']);
    }

    /**
     * The acknowledgement is not a magic word an operator may sprinkle
     * everywhere: with nothing exposed there is no risk, so nothing is recorded.
     */
    public function testAnUnexposedOrderCancelsPlainlyAndRecordsNoRiskEvent(): void
    {
        $this->seedOrder();
        $this->seedLink('cancellink03');

        $response = $this->controller()->cancel($this->request([]), self::ORDER);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('canceled', $this->statusOf(self::ORDER));
        self::assertSame(['status:canceled'], $this->eventTypes(), 'no risk row without a real exposure');
    }

    public function testAnOrderWithNoLinksAtAllIsUnaffectedByTheGuard(): void
    {
        $this->seedOrder();

        self::assertSame(200, $this->controller()->cancel($this->request([]), self::ORDER)->getStatusCode());
        self::assertSame('canceled', $this->statusOf(self::ORDER));
    }

    /**
     * The acknowledgement must be an explicit truth, not any old truthy body
     * value -- and never the mere PRESENCE of the key.
     *
     * @dataProvider nonAcknowledgements
     */
    public function testOnlyAnExplicitTrueCountsAsAnAcknowledgement(mixed $value): void
    {
        $this->seedOrder();
        $this->seedLink('cancellink04', issuedAt: '2026-08-11 08:20:00');

        $response = $this->controller()->cancel(
            $this->request([PaymentSessionExposureGuard::ACKNOWLEDGEMENT_FIELD => $value]),
            self::ORDER
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('pending_payment', $this->statusOf(self::ORDER));
    }

    /** @return array<string, array{mixed}> */
    public static function nonAcknowledgements(): array
    {
        return [
            'false' => [false],
            'null' => [null],
            'zero' => [0],
            'empty string' => [''],
            'the string false' => ['false'],
        ];
    }

    /** @dataProvider acknowledgements */
    public function testTheOrdinaryWireFormsOfTrueAreAccepted(mixed $value): void
    {
        $this->seedOrder();
        $this->seedLink('cancellink05', issuedAt: '2026-08-11 08:20:00');

        $response = $this->controller()->cancel(
            $this->request([PaymentSessionExposureGuard::ACKNOWLEDGEMENT_FIELD => $value]),
            self::ORDER
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /** @return array<string, array{mixed}> */
    public static function acknowledgements(): array
    {
        return [
            'boolean true' => [true],
            'string true' => ['true'],
            'string one' => ['1'],
        ];
    }

    public function testAnAlreadyCanceledOrderIsStillTheOrdinaryTransitionConflict(): void
    {
        $this->seedOrder(status: 'canceled');
        $this->seedLink('cancellink06', issuedAt: '2026-08-11 08:20:00');

        $response = $this->controller()->cancel($this->request([]), self::ORDER);

        self::assertSame(409, $response->getStatusCode());
        self::assertArrayNotHasKey('details', $this->json($response)['error']);
    }

    /**
     * A PAID order's exposure is resolved -- the money arrived. Canceling it is
     * the ordinary refund-adjacent operator action and needs no acknowledgement.
     */
    public function testAPaidOrderWithAHistoricallyExposedLinkNeedsNoAcknowledgement(): void
    {
        $this->seedOrder(status: 'paid');
        $this->seedLink('cancellink07', status: PaymentLinkRepository::STATUS_CONSUMED, issuedAt: '2026-08-11 08:20:00');

        self::assertSame(200, $this->controller()->cancel($this->request([]), self::ORDER)->getStatusCode());
        self::assertSame('canceled', $this->statusOf(self::ORDER));
    }

    public function testTheRefusalBodyNeverCarriesATokenOrHash(): void
    {
        $this->seedOrder();
        $this->seedLink('cancellink08', issuedAt: '2026-08-11 08:20:00');

        $body = (string) $this->controller()->cancel($this->request([]), self::ORDER)->getContent();

        self::assertStringNotContainsString('token', $body);
        self::assertStringNotContainsString(hash('sha256', 'cancellink08'), $body);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function controller(): AdminOrderController
    {
        // Explicit collaborators, like every other AdminOrderController test:
        // the lightweight harness container binds no engine services. The
        // exposure guard is deliberately NOT passed -- proving the in-constructor
        // default is a real guard, so no call site can end up unguarded.
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

    /** @param array<string,mixed> $body */
    private function request(array $body): Request
    {
        return Request::create(
            '/commerce/admin/orders/' . self::ORDER . '/cancel',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<string> */
    private function eventTypes(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['type'],
            (new OrderRepository())->eventsForOrder($this->context, self::TENANT, self::ORDER)
        );
    }

    private function statusOf(string $orderUuid): string
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $orderUuid)->first();
        self::assertNotNull($row);

        return (string) $row['status'];
    }

    private function stock(): int
    {
        return (new StockRepository())->quantity($this->context, self::TENANT, self::VARIANT);
    }

    private function seedOrder(string $status = 'pending_payment'): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => self::ORDER,
            'tenant_uuid' => self::TENANT,
            'order_number' => 'ORD-CANCEL-1',
            'status' => $status,
            'origin' => 'admin',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'placed_at' => '2026-08-11 08:00:00',
        ]);

        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => 'cancelline01',
            'order_uuid' => self::ORDER,
            'variant_uuid' => self::VARIANT,
            'product_name' => 'Blue Mug',
            'sku' => 'MUG-BLUE',
            'unit_price' => 500,
            'quantity' => 2,
            'line_total' => 1000,
        ]);

        $this->connection->table('commerce_stock')->insert([
            'tenant_uuid' => self::TENANT,
            'variant_uuid' => self::VARIANT,
            'quantity' => 4,
            'tracked' => 1,
        ]);
    }

    private function seedLink(
        string $uuid,
        string $status = PaymentLinkRepository::STATUS_ACTIVE,
        ?string $issuedAt = null
    ): void {
        $this->connection->table('commerce_payment_links')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'order_uuid' => self::ORDER,
            'token_hash' => hash('sha256', $uuid),
            'status' => $status,
            'expires_at' => '2026-08-18 08:00:00',
            'created_by' => 'canceloper01',
            'initiation_count' => 0,
            'provider_session_issued_at' => $issuedAt,
            'created_at' => '2026-08-11 08:00:00',
        ]);
    }
}
