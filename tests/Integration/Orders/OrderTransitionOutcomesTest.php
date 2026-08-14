<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\Commerce\Events\OrderPaid;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Orders\ConcurrentOrderTransitionException;
use Glueful\Extensions\Commerce\Orders\OrderNotFoundException;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Cleanup-train Task 4: the three ORDER-LIFECYCLE outcomes the engine used to
 * report badly.
 *
 *  1. A VANISHED (unknown / cross-tenant) order inside
 *     {@see OrderRepository::transition()} was a bare `\RuntimeException`
 *     distinguishable only by its message -- every caller logged it as a 500.
 *     It is now {@see OrderNotFoundException}, a typed not-found callers can
 *     classify, and the admin surface answers the same non-revealing 404 its
 *     read endpoints already do.
 *  2. `AdminOrderController::markPaid()` was the ONE admin order endpoint that
 *     did not run the draft-blind `order()` precheck, so a draft uuid fell
 *     through to the transition CAS and came back 409 where every sibling 404s.
 *  3. The loser of the `pending_payment -> paid` compare-and-set surfaced as a
 *     bare 500 rather than recognizing that the desired end state was already
 *     reached. `OrderPaymentService::markPaid()` now ANSWERS IDEMPOTENTLY --
 *     it reports whether THIS call performed the transition, and a conceding
 *     call writes nothing, posts nothing, and dispatches nothing.
 *
 * The genuine two-process `pending_payment -> paid` race lives in
 * {@see PaidCasRacePgsqlTest} (a real second connection is the only way to
 * make a CAS matched-zero-rows happen for real). What is pinned HERE is the
 * same self-heal rule reached through its OTHER, single-process door: an order
 * that is ALREADY `paid` when `markPaid()` starts.
 */
final class OrderTransitionOutcomesTest extends CommerceTestCase
{
    private const TENANT = '';

    // -----------------------------------------------------------------
    // 1. typed not-found from transition()
    // -----------------------------------------------------------------

    public function testTransitionRaisesATypedNotFoundForAVanishedOrder(): void
    {
        $orders = new OrderRepository();

        try {
            $orders->transition($this->context, self::TENANT, 'nosuchord01', 'paid');
            self::fail('a vanished order must not transition');
        } catch (OrderNotFoundException $e) {
            self::assertSame('nosuchord01', $e->orderUuid);
            self::assertSame('Order not found.', $e->getMessage());
        }
    }

    public function testTheTypedNotFoundIsCrossTenantBlindAndStaysARuntimeException(): void
    {
        $this->seedOrder('otherorder1', 'pending_payment', 'tenant-b');

        $caught = null;
        try {
            (new OrderRepository())->transition($this->context, self::TENANT, 'otherorder1', 'paid');
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        // Still a `\RuntimeException` (nothing that used to catch one breaks),
        // but now precisely typed.
        self::assertInstanceOf(OrderNotFoundException::class, $caught);
        self::assertSame('pending_payment', $this->orderRow('otherorder1')['status']);
    }

    public function testTheCasLoserIsATypedConcurrentTransitionAndStaysADomainException(): void
    {
        // The CAS-loss discriminator is a `\DomainException` subclass, so the
        // standing `catch (\DomainException) -> 409` idiom in every admin
        // controller keeps classifying it exactly as before.
        self::assertTrue(
            is_subclass_of(ConcurrentOrderTransitionException::class, \DomainException::class)
        );
    }

    // -----------------------------------------------------------------
    // 2. draft-blind markPaid() precheck
    // -----------------------------------------------------------------

    public function testMarkPaidIsANonRevealing404ForADraftUuidJustLikeEverySiblingEndpoint(): void
    {
        $this->seedOrder('draftorder1', 'draft');

        try {
            $this->adminController()->markPaid(Request::create('/commerce/admin/orders', 'POST'), 'draftorder1');
            self::fail('mark-paid must not reveal a draft through a 409');
        } catch (NotFoundException $e) {
            self::assertSame('Resource not found.', $e->getMessage());
        }

        self::assertSame('draft', $this->orderRow('draftorder1')['status']);
        self::assertSame([], $this->eventTypesFor('draftorder1'));
    }

    public function testMarkPaidIsANonRevealing404ForAnUnknownUuid(): void
    {
        $this->expectException(NotFoundException::class);
        $this->adminController()->markPaid(Request::create('/commerce/admin/orders', 'POST'), 'nosuchord02');
    }

    // -----------------------------------------------------------------
    // 3. idempotent paid outcome
    // -----------------------------------------------------------------

    public function testMarkPaidReportsThatItPerformedTheTransitionTheFirstTime(): void
    {
        $this->seedOrder('paidorder001', 'pending_payment');
        $captured = $this->bindEventCapture();

        $performed = $this->payments()->markPaid($this->context, self::TENANT, 'paidorder001');

        self::assertTrue($performed);
        self::assertSame('paid', $this->orderRow('paidorder001')['status']);
        self::assertSame(['status:paid'], $this->eventTypesFor('paidorder001'));
        self::assertCount(1, $captured->events);
    }

    public function testMarkPaidConcedesIdempotentlyWhenTheDesiredEndStateIsAlreadyReached(): void
    {
        $this->seedOrder('paidorder002', 'pending_payment');
        $payments = $this->payments();
        self::assertTrue($payments->markPaid($this->context, self::TENANT, 'paidorder002'));

        $captured = $this->bindEventCapture();
        $performed = $payments->markPaid($this->context, self::TENANT, 'paidorder002');

        self::assertFalse($performed, 'a conceding markPaid() must report that it did NOT transition');
        self::assertSame('paid', $this->orderRow('paidorder002')['status']);
        self::assertSame(['status:paid'], $this->eventTypesFor('paidorder002'), 'no duplicate audit row');
        self::assertCount(0, $captured->events, 'a conceding markPaid() must dispatch nothing');
    }

    public function testAnUnreachableEndStateStillThrowsRatherThanConceding(): void
    {
        $this->seedOrder('paidorder003', 'canceled');

        try {
            $this->payments()->markPaid($this->context, self::TENANT, 'paidorder003');
            self::fail('a canceled order must not be answered as if it were paid');
        } catch (\DomainException $e) {
            self::assertStringContainsString('canceled', $e->getMessage());
        }

        self::assertSame('canceled', $this->orderRow('paidorder003')['status']);
    }

    public function testTheAdminEndpointAnswersTheConcededCallWithThePaidOutcomeNotA409(): void
    {
        $this->seedOrder('paidorder004', 'pending_payment');
        $controller = $this->adminController();
        $request = Request::create('/commerce/admin/orders', 'POST');

        $first = $controller->markPaid($request, 'paidorder004');
        self::assertSame(200, $first->getStatusCode());

        $second = $controller->markPaid($request, 'paidorder004');
        self::assertSame(200, $second->getStatusCode(), (string) $second->getContent());
        self::assertSame('paid', $this->json($second)['data']['status']);
        self::assertSame(['status:paid'], $this->eventTypesFor('paidorder004'));
    }

    public function testAVanishedOrderIsA404FromTheAdminMarkPaidEndpointNotA500(): void
    {
        // The draft-blind precheck is what makes this a 404: it is the same
        // guard every sibling endpoint already runs.
        $this->expectException(NotFoundException::class);
        $this->adminController()->markPaid(Request::create('/commerce/admin/orders', 'POST'), 'vanished001');
    }

    // -----------------------------------------------------------------
    // fixtures
    // -----------------------------------------------------------------

    private function payments(): OrderPaymentService
    {
        return new OrderPaymentService(new OrderRepository());
    }

    private function adminController(): AdminOrderController
    {
        return new AdminOrderController(
            $this->context,
            new OrderRepository(),
            new StockRepository(),
            $this->payments(),
            new SentinelTenantResolver(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider()
        );
    }

    private function bindEventCapture(): object
    {
        $capture = new class {
            /** @var list<object> */
            public array $events = [];
        };
        $listeners = new ListenerProvider();
        $eventService = new EventService(new EventDispatcher($listeners), $listeners);
        $eventService->addListener(OrderPaid::class, static function (OrderPaid $e) use ($capture): void {
            $capture->events[] = $e;
        });
        $this->bind(EventService::class, $eventService);

        return $capture;
    }

    private function seedOrder(string $uuid, string $status, string $tenant = self::TENANT): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'email' => 'buyer@example.com',
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'origin' => 'storefront',
            'fulfillment_mode' => 'delivery',
        ]);
    }

    /** @return array<string,mixed> */
    private function orderRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $uuid)->first();
        self::assertIsArray($row);

        return $row;
    }

    /** @return list<string> */
    private function eventTypesFor(string $uuid): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['type'],
            $this->connection->table('commerce_order_events')
                ->where('order_uuid', '=', $uuid)
                ->orderBy('id', 'ASC')
                ->get()
        );
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
