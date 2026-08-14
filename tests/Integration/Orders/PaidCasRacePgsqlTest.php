<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\OrderPayable;
use Glueful\Extensions\Commerce\Payments\OrderPaymentConfirmationHandler;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmation;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL race lane for the `pending_payment -> paid` compare-and-set
 * LOSER (cleanup-train Task 4). PHP has no threads, so a genuine
 * update-vs-update race needs a truly separate OS process/connection -- this
 * file mirrors `Orders\DraftFinalizeTransitionPgsqlTest`'s gating,
 * fixture-width and cleanup discipline exactly, and is the reason the CAS-loss
 * arm is proven for real rather than simulated.
 *
 * Connection B (subprocess) runs the REAL `OrderPaymentService::markPaid()`
 * first and holds its row lock open/uncommitted; connection A (this test) then
 * runs its OWN settlement, whose UPDATE blocks on that lock. Once B commits,
 * A's blocked UPDATE re-evaluates `WHERE status = 'pending_payment'` against
 * the freshly committed row, matches ZERO rows, and loses -- deterministically.
 *
 * What A must then do is the whole point of the lane:
 *  - `markPaid()` recognizes that the DESIRED END STATE was reached by someone
 *    else and answers idempotently (`false` = "I did not perform it"), rather
 *    than letting a bare exception become a 500. Its own transaction rolled
 *    back, so it wrote nothing: exactly one `status:paid` audit row exists.
 *  - the provider-confirmation handler, whose settlement it was, falls through
 *    to `rejectLatePayment()` -- byte-identical to what it would have done had
 *    it read the order's status a moment later.
 */
final class PaidCasRacePgsqlTest extends CommerceTestCase
{
    public function testThePaidCasLoserConcedesIdempotentlyInsteadOfThrowing(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'paidcas0001';
        $orderUuid = 'paidcasord1';

        $this->deleteRows($connectionA, $tenant, $orderUuid);

        try {
            $this->seedPendingOrder($connectionA, $tenant, $orderUuid);

            $handle = $this->launchRaceChild($pgConfig, 'hold_mark_paid', [
                'tenant' => $tenant,
                'orderUuid' => $orderUuid,
                'sleepMs' => 500,
            ]);

            // Give B time to take the row lock before A attempts its own CAS.
            usleep(200_000);

            $performed = (new OrderPaymentService(new OrderRepository()))
                ->markPaid($contextA, $tenant, $orderUuid);

            $childResult = $this->collectRaceChild($handle);
            self::assertTrue($childResult['ok'] ?? false, 'the holding markPaid() must commit cleanly');
            self::assertTrue($childResult['performed'] ?? false, 'the winner must report that it transitioned');
            self::assertFalse($performed, 'the CAS loser must concede idempotently, never throw');

            self::assertSame('paid', $this->statusOf($connectionA, $orderUuid));
            self::assertSame(1, $this->eventCount($connectionA, $orderUuid, 'status:paid'));
        } finally {
            $this->deleteRows($connectionA, $tenant, $orderUuid);
        }
    }

    public function testThePaidCasLoserRoutesTheProviderConfirmationToTheLateRejection(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'paidcas0002';
        $orderUuid = 'paidcasord2';

        $this->deleteRows($connectionA, $tenant, $orderUuid);

        try {
            $this->seedPendingOrder($connectionA, $tenant, $orderUuid);

            $handle = $this->launchRaceChild($pgConfig, 'hold_mark_paid', [
                'tenant' => $tenant,
                'orderUuid' => $orderUuid,
                'sleepMs' => 500,
            ]);

            usleep(200_000);

            // A reads `pending_payment` (B has not committed yet), settles, and
            // loses the CAS -- the exact interleaving that used to 500.
            $handler = new OrderPaymentConfirmationHandler(
                new OrderRepository(),
                new OrderPaymentService(new OrderRepository()),
                $this->tenantResolver($tenant)
            );
            $handler->confirmed(
                $contextA,
                new PayableReference(OrderPayable::TYPE, $orderUuid, 1000, 'USD'),
                new PaymentConfirmation('paid', 'ref-race-1', 1000, 'USD')
            );

            $childResult = $this->collectRaceChild($handle);
            self::assertTrue($childResult['ok'] ?? false, 'the holding markPaid() must commit cleanly');
            self::assertTrue($childResult['performed'] ?? false, 'the winner must report that it transitioned');

            self::assertSame('paid', $this->statusOf($connectionA, $orderUuid));
            self::assertSame(1, $this->eventCount($connectionA, $orderUuid, 'status:paid'));
            self::assertSame(
                1,
                $this->eventCount($connectionA, $orderUuid, 'payment_late_rejected'),
                'a conceded settlement must still be discoverable as a late provider payment'
            );
        } finally {
            $this->deleteRows($connectionA, $tenant, $orderUuid);
        }
    }

    // --- Helpers -------------------------------------------------------------
    // (pgsql lane setup mirrors Orders\DraftFinalizeTransitionPgsqlTest exactly.)

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove the concurrent paid-CAS loser.');
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

    private function seedPendingOrder(Connection $connection, string $tenant, string $orderUuid): void
    {
        $connection->table('commerce_orders')->insert([
            'uuid' => $orderUuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $orderUuid,
            'status' => 'pending_payment',
            'email' => 'buyer@example.com',
            'guest_token_hash' => null,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'origin' => 'storefront',
            'fulfillment_mode' => 'delivery',
        ]);
    }

    private function statusOf(Connection $connection, string $orderUuid): string
    {
        $row = $connection->table('commerce_orders')->where('uuid', '=', $orderUuid)->first();
        self::assertIsArray($row);

        return (string) $row['status'];
    }

    private function eventCount(Connection $connection, string $orderUuid, string $type): int
    {
        return $connection->table('commerce_order_events')
            ->where('order_uuid', '=', $orderUuid)
            ->where('type', '=', $type)
            ->count();
    }

    private function deleteRows(Connection $connection, string $tenant, string $orderUuid): void
    {
        $connection->table('commerce_order_events')->where('order_uuid', '=', $orderUuid)->delete();
        $connection->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->delete();
    }

    /**
     * @param array<string,mixed> $pgConfig
     * @param array<string,mixed> $args
     * @return array{0: resource, 1: array<int,resource>}
     */
    private function launchRaceChild(array $pgConfig, string $action, array $args): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/paid_cas_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $action,
                json_encode($args, JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int,resource>} $handle
     * @return array<string,mixed>
     */
    private function collectRaceChild(array $handle): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "subprocess produced no parseable result. stderr: {$stderr}");

        return $result;
    }
}
