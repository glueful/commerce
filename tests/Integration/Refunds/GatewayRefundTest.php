<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Refunds;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\Commerce\Events\RefundCompleted;
use Glueful\Extensions\Commerce\Events\RefundFailed;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundInput;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundOutcomeUnknownException;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundValidationException;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\RefundCollector;
use Glueful\Extensions\Contracts\Payments\RefundRequest;
use Glueful\Extensions\Contracts\Payments\RefundResult;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Psr\Container\ContainerInterface;

final class GatewayRefundTest extends CommerceTestCase
{
    public function testCompletedResultCompletesRefundLikeManualPathAndDispatchesRefundCompleted(): void
    {
        $this->seedOrder('order1000cmp', 1000);
        $collector = new FakeRefundCollector([new RefundResult(RefundResult::COMPLETED, 'prov-complete-1')]);
        $dispatched = $this->bindEventCapture();

        $refund = $this->refundService($collector)->issue(
            $this->context,
            'order1000cmp',
            new RefundInput(1000, 'gateway completed', [], false),
            'idem-complete-1'
        );

        self::assertSame('completed', $refund['status']);

        self::assertCount(1, $collector->calls);
        self::assertSame((string) $refund['uuid'], $collector->calls[0]['idempotencyKey']);
        self::assertSame('gateway completed', $collector->calls[0]['reason']);
        self::assertFalse(
            $collector->calls[0]['withinTransaction'],
            'The collector must never be invoked while a database transaction is open.'
        );

        $updated = $this->orderRow('order1000cmp');
        self::assertSame(1000, (int) $updated['refunded_total']);
        self::assertSame('refunded', $updated['status']);

        $event = $this->connection->table('commerce_order_events')
            ->where('order_uuid', '=', 'order1000cmp')
            ->where('type', '=', 'refund.completed')
            ->first();
        self::assertNotNull($event);

        self::assertCount(1, $dispatched->events);
        self::assertInstanceOf(RefundCompleted::class, $dispatched->events[0]);
    }

    public function testFailedResultLeavesTotalsUnchangedReleasesReservationAndDispatchesRefundFailed(): void
    {
        $this->seedOrder('order1000fai', 1000);
        $collector = new FakeRefundCollector([
            new RefundResult(RefundResult::FAILED, null, 'card declined'),
            new RefundResult(RefundResult::COMPLETED, 'prov-retry-1'),
        ]);
        $dispatched = $this->bindEventCapture();
        $service = $this->refundService($collector);

        $refund = $service->issue(
            $this->context,
            'order1000fai',
            new RefundInput(600, 'card issue', [], false),
            'idem-fail-1'
        );

        self::assertSame('failed', $refund['status']);
        self::assertSame('card declined', $refund['failure_reason']);

        $updated = $this->orderRow('order1000fai');
        self::assertSame(0, (int) $updated['refunded_total'], 'A failed gateway refund must not touch order totals.');
        self::assertSame('paid', $updated['status']);

        self::assertCount(1, $dispatched->events, 'Exactly one event must have been dispatched so far.');
        self::assertInstanceOf(RefundFailed::class, $dispatched->events[0], 'A failed refund must never raise the customer-facing RefundCompleted event.');

        // The failed refund's hold is released: a full refund for the whole order now succeeds.
        $second = $service->issue(
            $this->context,
            'order1000fai',
            new RefundInput(1000, 'full retry', [], false),
            'idem-fail-2'
        );
        self::assertSame('completed', $second['status']);

        $finalOrder = $this->orderRow('order1000fai');
        self::assertSame(1000, (int) $finalOrder['refunded_total']);
        self::assertSame('refunded', $finalOrder['status']);

        self::assertCount(2, $dispatched->events);
        self::assertInstanceOf(RefundCompleted::class, $dispatched->events[1]);
    }

    public function testPendingReservationHoldsCapacityUntilSettledFailedThenReleasesIt(): void
    {
        $this->seedOrder('order0100pnd', 100);
        $collector = new FakeRefundCollector([
            new RefundResult(RefundResult::PENDING),
            new RefundResult(RefundResult::PENDING),
        ]);
        $service = $this->refundService($collector);

        $first = $service->issue(
            $this->context,
            'order0100pnd',
            new RefundInput(60, 'gateway hold', [], false),
            'idem-hold-a'
        );
        self::assertSame('pending', $first['status']);
        self::assertSame(0, (int) $this->orderRow('order0100pnd')['refunded_total']);

        // Remaining refundable = 100 - 0 - 60 (pending hold) = 40; a second 60-unit refund
        // must be rejected while the first stays pending.
        $threw = null;
        try {
            $service->issue(
                $this->context,
                'order0100pnd',
                new RefundInput(60, 'second attempt', [], false),
                'idem-hold-b'
            );
        } catch (RefundValidationException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(
            RefundValidationException::class,
            $threw,
            'A pending 60-unit reservation must count against remaining refundable capacity.'
        );

        $settled = $service->settle(
            $this->context,
            (string) $first['uuid'],
            new RefundResult(RefundResult::FAILED, null, 'declined')
        );
        self::assertSame('failed', $settled['status']);

        // Releasing the failed reservation frees the hold; the same retried request now succeeds.
        $retry = $service->issue(
            $this->context,
            'order0100pnd',
            new RefundInput(60, 'second attempt', [], false),
            'idem-hold-b'
        );
        self::assertSame('pending', $retry['status']);
    }

    public function testCollectorThrowLeavesRefundPendingThenReplayWithSameKeyFinalizes(): void
    {
        $this->seedOrder('order1000thr', 1000);
        $collector = new FakeRefundCollector([
            new \RuntimeException('gateway timeout'),
            new RefundResult(RefundResult::COMPLETED, 'prov-replay-1'),
        ]);
        $service = $this->refundService($collector);

        $threw = null;
        try {
            $service->issue(
                $this->context,
                'order1000thr',
                new RefundInput(1000, 'flaky gateway', [], false),
                'idem-throw-1'
            );
        } catch (RefundOutcomeUnknownException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(RefundOutcomeUnknownException::class, $threw);

        $pendingRow = (new RefundRepository())->findByIdempotencyKey($this->context, '', 'idem-throw-1');
        self::assertNotNull($pendingRow);
        self::assertSame('pending', $pendingRow['status']);
        self::assertSame('gateway timeout', $pendingRow['failure_reason']);

        $replayed = $service->issue(
            $this->context,
            'order1000thr',
            new RefundInput(1000, 'flaky gateway', [], false),
            'idem-throw-1'
        );
        self::assertSame('completed', $replayed['status']);
        self::assertSame($pendingRow['uuid'], $replayed['uuid']);

        self::assertCount(2, $collector->calls);
        self::assertSame(
            $collector->calls[0]['idempotencyKey'],
            $collector->calls[1]['idempotencyKey'],
            'Replaying the same HTTP idempotency key must reuse the SAME refund-uuid collector key.'
        );
        self::assertSame($pendingRow['uuid'], $collector->calls[0]['idempotencyKey']);

        $updated = $this->orderRow('order1000thr');
        self::assertSame(1000, (int) $updated['refunded_total']);
        self::assertSame('refunded', $updated['status']);
    }

    public function testSettleFinalizesPendingRefundThenIsIdempotent(): void
    {
        $this->seedOrder('order1000stl', 1000);
        $collector = new FakeRefundCollector([new RefundResult(RefundResult::PENDING)]);
        $service = $this->refundService($collector);

        $pending = $service->issue(
            $this->context,
            'order1000stl',
            new RefundInput(1000, 'settle me', [], false),
            'idem-settle-1'
        );
        self::assertSame('pending', $pending['status']);

        $settled = $service->settle(
            $this->context,
            (string) $pending['uuid'],
            new RefundResult(RefundResult::COMPLETED, 'prov-settle-1')
        );
        self::assertSame('completed', $settled['status']);

        $updated = $this->orderRow('order1000stl');
        self::assertSame(1000, (int) $updated['refunded_total']);
        self::assertSame('refunded', $updated['status']);

        // Idempotent: settling an already-terminal refund again must not double-apply totals.
        $again = $service->settle(
            $this->context,
            (string) $pending['uuid'],
            new RefundResult(RefundResult::COMPLETED, 'prov-settle-1')
        );
        self::assertSame('completed', $again['status']);

        $updatedAgain = $this->orderRow('order1000stl');
        self::assertSame(1000, (int) $updatedAgain['refunded_total']);
    }

    public function testSettleWithUnknownStatusIsInfrastructureErrorAndRefundStaysPending(): void
    {
        $this->seedOrder('order1000unk', 1000);
        $collector = new FakeRefundCollector([new RefundResult(RefundResult::PENDING)]);
        $service = $this->refundService($collector);

        $pending = $service->issue(
            $this->context,
            'order1000unk',
            new RefundInput(1000, 'unknown status', [], false),
            'idem-unknown-1'
        );

        $threw = null;
        try {
            $service->settle($this->context, (string) $pending['uuid'], new RefundResult('weird-status'));
        } catch (RefundOutcomeUnknownException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(RefundOutcomeUnknownException::class, $threw);

        $stillPending = (new RefundRepository())->findByUuid($this->context, '', (string) $pending['uuid']);
        self::assertNotNull($stillPending);
        self::assertSame('pending', $stillPending['status']);
        self::assertNotNull($stillPending['failure_reason']);
    }

    public function testSettleUnknownRefundUuidThrowsNotFound(): void
    {
        $service = $this->refundService(new FakeRefundCollector([]));

        $this->expectException(NotFoundException::class);
        $service->settle($this->context, 'no-such-refund', new RefundResult(RefundResult::COMPLETED));
    }

    public function testSettleCrossTenantRefundThrowsNotFound(): void
    {
        $this->seedOrder('order1000xtn', 1000);
        (new RefundRepository())->insert($this->context, [
            'uuid' => 'refundXtenant',
            'tenant_uuid' => 'tenant-b',
            'order_uuid' => 'order1000xtn',
            'idempotency_key' => 'idem-tenant-b',
            'request_fingerprint' => md5('idem-tenant-b'),
            'amount' => 500,
            'currency' => 'USD',
            'method' => 'gateway',
            'status' => 'pending',
            'reason' => null,
            'restocked' => false,
        ], []);

        $service = $this->refundService(new FakeRefundCollector([]));

        $this->expectException(NotFoundException::class);
        $service->settle($this->context, 'refundXtenant', new RefundResult(RefundResult::COMPLETED));
    }

    /**
     * Adjudicated replacement for the plan's Step 1b (SQLite `:memory:` cannot run a true
     * two-connection interleaved race). Real cross-connection interleaving is exercised by
     * testConcurrentGatewayReservationsSerializeViaOrderClaim(), gated to a pgsql test lane.
     */
    public function testConcurrentGatewayReservationsSerializeViaOrderClaim(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = [
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

        // Connection A drives this test method directly. Connection B runs in a genuinely
        // independent OS process (fixtures/gateway_refund_race_child.php) against the same
        // database, so its claim attempt can really block on Postgres row-lock contention
        // while A still holds the order row open and uncommitted.
        $connectionA = new Connection($pgConfig);
        $schema = $connectionA->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        $contextA = $this->pgsqlContext($connectionA);
        $orderUuid = 'orderpgrace1';
        $connectionA->table('commerce_orders')->insert([
            'uuid' => $orderUuid,
            'tenant_uuid' => '',
            'order_number' => 'ORD-' . $orderUuid,
            'status' => 'paid',
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 100,
            'grand_total' => 100,
        ]);

        // A claims the order first — this holds the row lock, uncommitted. The claim
        // primitive (not a closure-wrapped RefundService::issue()) is used directly so the
        // test can pause mid-reservation while B attempts to claim the same row.
        $connectionA->getTransactionManager()->begin();
        $orders = new OrderRepository();
        self::assertTrue($orders->claimRefundMutation($contextA, '', $orderUuid));

        // Launch B: its own claim attempt on the same order blocks on A's row lock.
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/gateway_refund_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $orderUuid,
                'idem-race-b',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        // Give B time to reach and block on its own claim UPDATE before A proceeds.
        usleep(300_000);

        // A completes its reservation: validate, insert the pending refund, re-check
        // capacity, then commit — releasing the row lock so B's blocked claim can proceed.
        $order = $orders->findByUuid($contextA, '', $orderUuid);
        self::assertNotNull($order);
        $refunds = new RefundRepository();
        $refunds->insert($contextA, [
            'uuid' => 'refundpgrace',
            'tenant_uuid' => '',
            'order_uuid' => $orderUuid,
            'idempotency_key' => 'idem-race-a',
            'request_fingerprint' => md5('idem-race-a'),
            'amount' => 60,
            'currency' => 'USD',
            'method' => 'gateway',
            'status' => 'pending',
            'reason' => null,
            'restocked' => false,
        ], []);
        self::assertLessThanOrEqual(
            (int) $order['grand_total'],
            $refunds->reservedAmountSum($contextA, '', $orderUuid)
        );
        $connectionA->getTransactionManager()->commit();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim($stdout), true);
        self::assertIsArray($result, "Connection B's subprocess produced no parseable result. stderr: {$stderr}");
        self::assertSame(
            RefundValidationException::class,
            $result['exceptionClass'] ?? null,
            'The loser must see the winner\'s committed reservation and fail validation '
                . '(remaining refundable = 40, requested 60).'
        );
        self::assertFalse(
            $result['collectorCalledInsideTransaction'] ?? true,
            'The collector must never be invoked inside a database transaction.'
        );

        $pending = $connectionA->table('commerce_refunds')
            ->where('order_uuid', '=', $orderUuid)
            ->where('status', '=', 'pending')
            ->get();
        self::assertCount(1, $pending, 'Exactly one pending refund must have committed.');
        self::assertSame('refundpgrace', $pending[0]['uuid']);
    }

    /**
     * Binds a real EventService into the test container and returns a capture object
     * whose `events` list is appended to (in dispatch order) as RefundCompleted/RefundFailed
     * fire. An object is used (rather than returning an array by reference) since PHP
     * copies arrays on return — a plain array captured by the listener closures would not
     * stay linked to the array the caller holds.
     */
    private function bindEventCapture(): object
    {
        $capture = new class {
            /** @var list<object> */
            public array $events = [];
        };
        $listeners = new ListenerProvider();
        $eventService = new EventService(new EventDispatcher($listeners), $listeners);
        $eventService->addListener(RefundCompleted::class, function (RefundCompleted $e) use ($capture): void {
            $capture->events[] = $e;
        });
        $eventService->addListener(RefundFailed::class, function (RefundFailed $e) use ($capture): void {
            $capture->events[] = $e;
        });
        $this->bind(EventService::class, $eventService);

        return $capture;
    }

    private function refundService(RefundCollector $collector): RefundService
    {
        return new RefundService(
            new OrderRepository(),
            new RefundRepository(),
            new StockRepository(),
            new SentinelTenantResolver(),
            $collector
        );
    }

    private function seedOrder(string $uuid, int $grandTotal): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => $grandTotal,
            'grand_total' => $grandTotal,
        ]);
    }

    /** @return array<string,mixed> */
    private function orderRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $uuid)->first();
        self::assertNotNull($row);

        return $row;
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
}

/**
 * Scripted fake gateway. Constructed with a queue of RefundResult|Throwable outcomes
 * consumed in order; records every call's collector idempotency key, forwarded reason,
 * and whether a database transaction was open at call time.
 */
final class FakeRefundCollector implements RefundCollector
{
    /** @var list<array{idempotencyKey: string, reason: ?string, withinTransaction: bool}> */
    public array $calls = [];

    /** @param list<RefundResult|\Throwable> $queue */
    public function __construct(private array $queue)
    {
    }

    public function refund(
        ApplicationContext $context,
        PayableReference $payable,
        RefundRequest $request
    ): RefundResult {
        $this->calls[] = [
            'idempotencyKey' => $request->idempotencyKey,
            'reason' => $request->reason,
            'withinTransaction' => db($context)->withinTransaction(),
        ];

        if ($this->queue === []) {
            throw new \RuntimeException('FakeRefundCollector queue exhausted.');
        }

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
