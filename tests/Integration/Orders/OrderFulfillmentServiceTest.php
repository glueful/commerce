<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\OrderProjection;
use Glueful\Extensions\Commerce\Http\DTOs\FulfillOrderData;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\OrderFulfillmentService;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Service-level parity matrix for {@see OrderFulfillmentService} (admin-order-creation
 * cycle 2, Task 5): the NON-PARTITIONED fulfillment path extracted verbatim from
 * {@see AdminOrderController::fulfill()}. Every assertion here proves the extraction
 * changed nothing observable -- same CAS, same raw-row event payload, same exactly-once
 * dispatch timing, same 404-before-409 precedence -- while {@see AdminOrderController}
 * itself keeps its marketplace-partitioned fan-out branch and HTTP projection untouched.
 */
final class OrderFulfillmentServiceTest extends CommerceTestCase
{
    public function testFulfillFromPaidSucceedsWithNullableTrackingAndDispatchesExactlyOneEventWithTheRawRow(): void
    {
        $captured = $this->bindEventCapture();
        $this->seedOrder('orderful00001', 'paid');

        $result = $this->service()->fulfill($this->context, '', 'orderful00001', null);

        self::assertSame('fulfilled', $result['status']);
        self::assertSame('fulfilled', $result['fulfillment_status']);
        self::assertNull($result['tracking_ref']);

        self::assertCount(1, $captured->events);
        self::assertInstanceOf(OrderFulfilled::class, $captured->events[0]);
        self::assertSame('orderful00001', $captured->events[0]->order['uuid']);
        self::assertNull($captured->events[0]->order['tracking_ref']);
        // A RAW row -- an internal-only column proves the event never saw a projection.
        self::assertArrayHasKey('guest_token_hash', $captured->events[0]->order);
    }

    public function testFulfillFromPaidRecordsAProvidedTrackingRefOnBothTheReturnAndTheEvent(): void
    {
        $captured = $this->bindEventCapture();
        $this->seedOrder('orderful00002', 'paid');

        $result = $this->service()->fulfill($this->context, '', 'orderful00002', 'TRACK-99');

        self::assertSame('TRACK-99', $result['tracking_ref']);
        self::assertCount(1, $captured->events);
        self::assertSame('TRACK-99', $captured->events[0]->order['tracking_ref']);
    }

    /** @return list<array{0:string}> */
    public static function nonPaidStatusProvider(): array
    {
        return [
            'pending_payment' => ['pending_payment'],
            'already fulfilled' => ['fulfilled'],
        ];
    }

    /** @dataProvider nonPaidStatusProvider */
    public function testFulfillFromAnyOtherStatusThrowsDomainExceptionAndDispatchesNoEvent(string $status): void
    {
        $captured = $this->bindEventCapture();
        $this->seedOrder('orderful00003', $status);

        try {
            $this->service()->fulfill($this->context, '', 'orderful00003', null);
            self::fail("Expected a DomainException fulfilling an order in status '{$status}'.");
        } catch (\DomainException $e) {
            self::assertStringContainsString('Invalid order transition', $e->getMessage());
        }

        self::assertCount(0, $captured->events);
        $unchanged = (new OrderRepository())->findByUuid($this->context, '', 'orderful00003');
        self::assertSame($status, $unchanged['status']);
    }

    public function testFulfillingAnUnknownOrderIsANotFoundNeverADomainException(): void
    {
        $captured = $this->bindEventCapture();

        try {
            $this->service()->fulfill($this->context, '', 'nosuchorder1', null);
            self::fail('Expected NotFoundException for an unknown order.');
        } catch (NotFoundException $e) {
            self::assertSame('Resource not found.', $e->getMessage());
        }

        self::assertCount(0, $captured->events);
    }

    public function testFulfillingACrossTenantOrderIsANotFoundNotAnotherTenantsRow(): void
    {
        $this->seedOrder('orderful00009', 'paid', tenant: 'tenantAAAA01');

        $this->expectException(NotFoundException::class);
        $this->service()->fulfill($this->context, 'tenantBBBB02', 'orderful00009', null);
    }

    public function testControllerDelegatesTheNonPartitionedPathAndReturnsAProjectedResponse(): void
    {
        $this->seedOrder('orderful00004', 'paid');

        $response = $this->orderController()->fulfill(
            new FulfillOrderData(tracking_ref: 'TRK-CTRL'),
            Request::create('/commerce/admin/orders/orderful00004/fulfill', 'POST'),
            'orderful00004'
        );
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('fulfilled', $body['data']['status']);
        self::assertSame('TRK-CTRL', $body['data']['tracking_ref']);
        self::assertEqualsCanonicalizing(OrderProjection::FIELDS, array_keys($body['data']));
        self::assertArrayNotHasKey('guest_token_hash', $body['data']);
        self::assertArrayNotHasKey('marketplace_partitioned', $body['data']);
    }

    /**
     * The parent's own `fulfillment_revision` claim path (marketplace MV2) reaches
     * `fulfilled` via `SellerOrderFulfillmentService::fanOutFulfill()`'s own rollup and
     * its own after-commit dispatch -- {@see OrderFulfillmentService} is never consulted
     * once `marketplace_partitioned` is true, so exactly one `OrderFulfilled` fires (from
     * the marketplace path), never two.
     */
    public function testMarketplacePartitionedOrdersKeepTheirExistingControllerPathAndEventCountUnchanged(): void
    {
        $captured = $this->bindEventCapture();
        $this->seedOrder('orderful00005', 'paid', partitioned: true);

        $response = $this->orderController()->fulfill(
            new FulfillOrderData(tracking_ref: null),
            Request::create('/commerce/admin/orders/orderful00005/fulfill', 'POST'),
            'orderful00005'
        );

        self::assertSame(200, $response->getStatusCode());
        $fulfilledEvents = array_values(array_filter(
            $captured->events,
            static fn (object $event): bool => $event instanceof OrderFulfilled
        ));
        self::assertCount(1, $fulfilledEvents);
    }

    // =====================================================================
    // Real-PostgreSQL race lane: a genuine two-connection CAS conflict.
    // Mirrors Orders\OrderNumberGeneratorTest's pgsql race-child pattern --
    // PHP has no threads, so a genuine read-then-write interleaving inside
    // OrderRepository::transition() needs a truly separate OS process/
    // connection.
    // =====================================================================

    /**
     * Connection A (this test) directly transitions the order paid -> fulfilled and
     * holds that transaction open/uncommitted. Connection B (subprocess, the REAL
     * {@see OrderFulfillmentService}) reads the still-committed 'paid' row (A hasn't
     * committed), passes its own state-guard, then blocks on A's row lock for its
     * UPDATE. Once A commits, B's blocked UPDATE re-evaluates its `WHERE status =
     * 'paid'` against the now-'fulfilled' row, matches zero rows, and
     * OrderRepository::transition() throws its CAS DomainException -- exactly the
     * concurrent-write case a single connection can never produce.
     */
    public function testConcurrentFulfillAttemptsOnTheSameOrderYieldExactlyOneCasConflictOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'orderfulrace1';
        $orderUuid = 'orderfulrace';

        $this->cleanupRaceOrder($connectionA, $orderUuid);

        try {
            $connectionA->table('commerce_orders')->insert([
                'uuid' => $orderUuid,
                'tenant_uuid' => $tenant,
                'order_number' => 'ORD-RACE-1',
                'status' => 'paid',
                'fulfillment_status' => 'unfulfilled',
                'marketplace_partitioned' => false,
                'email' => 'racebuyer@example.com',
                'guest_token_hash' => str_repeat('a', 64),
                'currency' => 'USD',
                'subtotal' => 2000,
                'grand_total' => 2000,
            ]);

            $connectionA->getTransactionManager()->begin();
            (new OrderRepository())->transition($contextA, $tenant, $orderUuid, 'fulfilled', [
                'fulfillment_status' => 'fulfilled',
                'tracking_ref' => 'A-TRACK',
            ]);

            $process = proc_open(
                [
                    PHP_BINARY,
                    __DIR__ . '/fixtures/order_fulfillment_race_child.php',
                    json_encode($pgConfig, JSON_THROW_ON_ERROR),
                    $tenant,
                    $orderUuid,
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($process);

            // Give B time to read the still-'paid' row and block on A's held lock.
            usleep(300_000);

            $connectionA->getTransactionManager()->commit();

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $result = json_decode(trim((string) $stdout), true);
            self::assertIsArray($result, "subprocess produced no parseable result. stderr: {$stderr}");

            self::assertFalse($result['ok'] ?? true, 'B must lose the race to A\'s already-committed transition');
            self::assertSame(\DomainException::class, $result['exceptionClass'] ?? null);
            self::assertSame('Order status changed concurrently; retry the operation.', $result['message'] ?? null);

            $final = $connectionA->table('commerce_orders')->where('uuid', '=', $orderUuid)->first();
            self::assertNotNull($final);
            self::assertSame('fulfilled', $final['status']);
            self::assertSame('A-TRACK', $final['tracking_ref'], 'A\'s own committed transition must win, untouched by B');
        } finally {
            $this->cleanupRaceOrder($connectionA, $orderUuid);
        }
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function service(): OrderFulfillmentService
    {
        return new OrderFulfillmentService(new OrderRepository());
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

    private function seedOrder(
        string $uuid,
        string $status,
        bool $partitioned = false,
        string $tenant = '',
    ): void {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => $partitioned,
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 5000,
            'grand_total' => 5000,
        ]);
    }

    /**
     * Binds a real EventService into the test container and returns a capture object whose
     * `events` list is appended to (in dispatch order) as events fire. An object is used
     * (rather than an array by reference) since PHP copies arrays on return.
     */
    private function bindEventCapture(): object
    {
        $capture = new class {
            /** @var list<object> */
            public array $events = [];
        };
        $listeners = new ListenerProvider();
        $eventService = new EventService(new EventDispatcher($listeners), $listeners);
        $eventService->addListener(OrderFulfilled::class, function (OrderFulfilled $e) use ($capture): void {
            $capture->events[] = $e;
        });
        $this->bind(EventService::class, $eventService);

        return $capture;
    }

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane for true two-connection row-lock interleaving.');
        }
    }

    /** @return array<string,mixed> */
    private function pgConfig(): array
    {
        return [
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'glueful_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
            ],
            'pooling' => ['enabled' => false],
        ];
    }

    /** @param array<string,mixed> $pgConfig */
    private function migratedConnection(array $pgConfig): Connection
    {
        $connection = new Connection($pgConfig);
        $schema = $connection->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        return $connection;
    }

    private function pgsqlContext(Connection $connection): ApplicationContext
    {
        $container = new class ($connection) implements ContainerInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database' || $id === Connection::class;
            }
        };

        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->setContainer($container);
        $context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../config/commerce.php');

        return $context;
    }

    private function cleanupRaceOrder(Connection $connection, string $orderUuid): void
    {
        $connection->table('commerce_orders')->where('uuid', '=', $orderUuid)->delete();
    }
}
