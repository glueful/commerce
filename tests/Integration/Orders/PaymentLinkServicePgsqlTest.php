<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkReturnUrlProvider;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkException;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL lane for `PaymentLinkService` (payment-links Task 6, design
 * spec §2.2 Ruling 7). One thing can only be proven here, never on SQLite:
 * CONCURRENT MINTS SERIALIZE ON THE ORDER LOCK, so exactly one active link
 * survives.
 *
 * SQLite's database-wide write lock makes every mint trivially serial, so a
 * mint that never locked the order at all would pass unnoticed there. On
 * PostgreSQL two genuinely concurrent connections can read the same order
 * simultaneously -- so a mint whose "revoke prior, insert new" ran without the
 * order lock would have BOTH callers observe "no prior active link", revoke
 * nothing, and insert, leaving TWO active links for one order. The schema
 * carries no partial unique index (see `Database\Migrations\CreatePaymentLinksTable`),
 * so nothing else would catch it.
 *
 * PHP has no threads, so the concurrent connection is a genuinely separate OS
 * process ({@see fixtures/payment_link_mint_race_child.php}) -- gating/fixture
 * discipline mirrors `Orders\PaymentLinkRepositoryPgsqlTest` exactly.
 */
final class PaymentLinkServicePgsqlTest extends CommerceTestCase
{
    private const TENANT = 'plsvcpgten01';
    private const ORDER = 'plsvcpgord01';
    private const ACTOR = 'plsvcpgact01';

    /**
     * The child takes the ORDER row lock, holds it, mints, and commits. The
     * parent's own mint must BLOCK on that order lock, then observe the
     * COMMITTED link, revoke it, and insert its own -- exactly one active link
     * for the order, and two rows total.
     */
    public function testConcurrentMintsSerializeOnTheOrderLockAndLeaveExactlyOneActiveLink(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connection = $this->migratedConnection($pgConfig);
        $context = $this->pgsqlContext($connection);

        $this->cleanup($connection);

        try {
            $this->seedOrder($connection);

            $handle = $this->launchRaceChild($pgConfig, 'hold_order_then_mint', [
                'tenant' => self::TENANT,
                'orderUuid' => self::ORDER,
                'actor' => self::ACTOR,
                'ttlDays' => 7,
                'now' => '2026-08-11 08:00:00',
                'sleepMs' => 500,
            ]);

            // Give the child time to take the order lock before the parent tries.
            usleep(200_000);

            $parent = $this->service()->mint(
                $context,
                self::TENANT,
                self::ORDER,
                7,
                self::ACTOR,
                new \DateTimeImmutable('2026-08-11 08:00:05', new \DateTimeZone('UTC'))
            );
            $childResult = $this->collectRaceChild($handle);

            self::assertTrue($childResult['ok'] ?? false, 'the holding mint must commit cleanly');
            self::assertNotSame(
                $childResult['linkUuid'] ?? null,
                $parent['link']->linkUuid,
                'the two mints must be two distinct links'
            );

            $rows = $connection->table('commerce_payment_links')
                ->where('tenant_uuid', '=', self::TENANT)
                ->orderBy('id', 'ASC')
                ->get();

            self::assertCount(2, $rows, 'both mints land as rows');

            $active = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['status'] === PaymentLinkRepository::STATUS_ACTIVE
            ));
            self::assertCount(1, $active, 'Ruling 7: exactly ONE active link survives concurrent mints');
            self::assertSame(
                $parent['link']->linkUuid,
                (string) $active[0]['uuid'],
                'the mint that ran LAST owns the surviving active link'
            );
            self::assertSame(
                PaymentLinkRepository::STATUS_REVOKED,
                (string) $rows[0]['status'],
                'the earlier link is revoked, never left dangling'
            );
        } finally {
            $this->cleanup($connection);
        }
    }

    /**
     * The raw token survives a PostgreSQL round trip only as a hash: the mint
     * path must never write the bearer value into any column on any driver.
     */
    public function testTheRawTokenNeverReachesAnyColumnOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $context = $this->pgsqlContext($connection);

        $this->cleanup($connection);

        try {
            $this->seedOrder($connection);
            $service = $this->service();

            $minted = $service->mint(
                $context,
                self::TENANT,
                self::ORDER,
                7,
                self::ACTOR,
                new \DateTimeImmutable('2026-08-11 08:00:00', new \DateTimeZone('UTC'))
            );

            $view = $service->resolveByToken(
                $context,
                $minted['rawToken'],
                new \DateTimeImmutable('2026-08-11 09:00:00', new \DateTimeZone('UTC'))
            );
            self::assertNotNull($view, 'the token resolves through a real PostgreSQL round trip');

            foreach (['commerce_payment_links', 'commerce_order_events'] as $table) {
                $dump = json_encode($connection->table($table)->get(), JSON_THROW_ON_ERROR);
                self::assertStringNotContainsString($minted['rawToken'], $dump, "raw token leaked into {$table}");
            }
        } finally {
            $this->cleanup($connection);
        }
    }

    /**
     * Payment-links Task 7 (design spec §2.2): NO PROVIDER CALL RUNS INSIDE A
     * TRANSACTION OR WHILE ROW LOCKS ARE HELD -- proven the only way it can
     * really be proven, with a genuinely separate OS process and connection.
     *
     * SQLite can only show that no transaction is OPEN in this process. Here a
     * second connection tries to take the ORDER row `FOR UPDATE` while the
     * parent's provider leg is in flight. If the parent still held Phase A's
     * transaction, that acquisition would block, and the child's short
     * `lock_timeout` turns the block into a reported failure rather than a hung
     * suite. It succeeds, revokes, and commits -- and the parent's Phase B then
     * rechecks every predicate, sees the revocation, and REFUSES the redirect.
     * The provider attempt stays server-side; no URL is exposed and nothing is
     * stamped.
     */
    public function testTheProviderLegHoldsNoLockSoAConcurrentRevokeWinsAndPhaseBRefuses(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connection = $this->migratedConnection($pgConfig);
        $context = $this->pgsqlContext($connection);

        $this->cleanup($connection);

        try {
            $this->seedOrder($connection);

            $checkoutUrl = 'https://psp.example.com/session/pg1';

            $collector = new class ($this, $pgConfig, $checkoutUrl) implements PaymentCollector {
                public int $calls = 0;

                /** @var array<string,mixed> */
                public array $childResult = [];

                /** @param array<string,mixed> $pgConfig */
                public function __construct(
                    private PaymentLinkServicePgsqlTest $test,
                    private array $pgConfig,
                    private string $checkoutUrl,
                ) {
                }

                public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
                {
                    $this->calls++;
                    // A REAL second process, competing for the order lock while
                    // this "network call" is in flight.
                    $this->childResult = $this->test->runRevokeRaceChild($this->pgConfig);

                    return new PaymentInitiation('fakepsp', 'ok', ['checkout_url' => $this->checkoutUrl]);
                }
            };

            $service = $this->service($collector, $this->returnUrlProvider());
            $minted = $service->mint(
                $context,
                self::TENANT,
                self::ORDER,
                7,
                self::ACTOR,
                new \DateTimeImmutable('2026-08-11 08:00:00', new \DateTimeZone('UTC'))
            );

            try {
                $service->initiateByToken(
                    $context,
                    $minted['rawToken'],
                    new \DateTimeImmutable('2026-08-11 09:00:00', new \DateTimeZone('UTC'))
                );
                self::fail('Phase B must refuse a link revoked during the provider call');
            } catch (PaymentLinkException $e) {
                self::assertSame(PaymentLinkException::PAYMENT_LINK_NOT_PAYABLE, $e->errorCode);
                self::assertStringNotContainsString($checkoutUrl, $e->getMessage());
            }

            self::assertTrue(
                $collector->childResult['ok'] ?? false,
                'a separate connection must be able to lock the order DURING provider I/O: '
                    . json_encode($collector->childResult, JSON_THROW_ON_ERROR)
            );
            self::assertSame(1, $collector->calls);

            $row = $connection->table('commerce_payment_links')
                ->where('uuid', '=', $minted['link']->linkUuid)
                ->first();
            self::assertNotNull($row);
            self::assertSame(PaymentLinkRepository::STATUS_REVOKED, (string) $row['status']);
            self::assertNull($row['provider_session_issued_at'], 'a refused Phase B stamps nothing');
            self::assertSame(1, (int) $row['initiation_count'], 'the claim was made before the provider call');
        } finally {
            $this->cleanup($connection);
        }
    }

    /**
     * Public so the fake collector above can reach it: launches the child that
     * competes for the order lock, and waits for it.
     *
     * @param array<string,mixed> $pgConfig
     * @return array<string,mixed>
     */
    public function runRevokeRaceChild(array $pgConfig): array
    {
        return $this->collectRaceChild($this->launchRaceChild($pgConfig, 'lock_order_then_revoke', [
            'tenant' => self::TENANT,
            'orderUuid' => self::ORDER,
            'actor' => self::ACTOR,
            'now' => '2026-08-11 09:00:01',
        ]));
    }

    // --- Helpers -------------------------------------------------------------
    // (pgsql lane setup mirrors Orders\PaymentLinkRepositoryPgsqlTest exactly.)

    private function service(
        ?PaymentCollector $collector = null,
        ?PaymentLinkReturnUrlProvider $returnUrls = null
    ): PaymentLinkService {
        return new PaymentLinkService(
            new OrderRepository(),
            new PaymentLinkRepository(),
            new class (self::TENANT) implements CurrentTenantResolver {
                public function __construct(private string $tenant)
                {
                }

                public function tenantUuid(ApplicationContext $context): string
                {
                    return $this->tenant;
                }
            },
            null,
            $collector,
            $returnUrls
        );
    }

    private function returnUrlProvider(): PaymentLinkReturnUrlProvider
    {
        return new class implements PaymentLinkReturnUrlProvider {
            /** @return array{return: string, cancel: string}|null */
            public function urlsFor(ApplicationContext $context, string $linkUuid): ?array
            {
                return [
                    'return' => 'https://shop.example.com/pay/return?sig=pg',
                    'cancel' => 'https://shop.example.com/pay/cancel?sig=pg',
                ];
            }
        };
    }

    private function seedOrder(Connection $connection): void
    {
        $connection->table('commerce_orders')->insert([
            'uuid' => self::ORDER,
            'tenant_uuid' => self::TENANT,
            'order_number' => 'ORD-PLPG-01',
            'status' => 'pending_payment',
            'origin' => 'admin',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'email' => 'pgbuyer@example.com',
            'guest_token_hash' => str_repeat('d', 64),
            'currency' => 'USD',
            'subtotal' => 2000,
            'grand_total' => 2000,
        ]);
    }

    private function cleanup(Connection $connection): void
    {
        $connection->table('commerce_payment_links')->where('tenant_uuid', '=', self::TENANT)->delete();
        $connection->table('commerce_order_events')->where('order_uuid', '=', self::ORDER)->delete();
        $connection->table('commerce_orders')->where('tenant_uuid', '=', self::TENANT)->delete();
    }

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove concurrent mints really serialize.');
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
                __DIR__ . '/fixtures/payment_link_mint_race_child.php',
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
