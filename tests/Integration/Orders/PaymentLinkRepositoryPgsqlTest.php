<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL lane for `PaymentLinkRepository` (payment-links Task 5, design
 * spec §2.2). Two things can only be proven here, never on SQLite:
 *
 *  1. The initiation counter is claimed UNDER THE LINK ROW LOCK. SQLite's
 *     database-wide write lock makes every claim trivially serial, so a missing
 *     `FOR UPDATE` would pass unnoticed there. On PostgreSQL two genuinely
 *     concurrent connections can read the same row simultaneously -- so a claim
 *     that skipped the lock (or dropped the compare-and-set) would LOSE the
 *     other's update and let a ceiling of 1 be claimed twice.
 *  2. Timestamps survive a PostgreSQL round trip. `initiation_window_started_at`
 *     is read back and compared against the freshly computed UTC hour on every
 *     claim; a driver that returned a fractional-second suffix would silently
 *     make EVERY claim look like a new window and reset the counter forever.
 *
 * PHP has no threads, so the concurrent connection is a genuinely separate OS
 * process ({@see fixtures/payment_link_race_child.php}) -- gating/fixture-width
 * discipline mirrors `Orders\DraftAttemptRepositoryPgsqlTest` exactly.
 */
final class PaymentLinkRepositoryPgsqlTest extends CommerceTestCase
{
    private const TENANT = 'plinkpg0001';
    private const ORDER = 'plinkpgord1';
    private const ACTOR = 'plinkpgact1';

    /**
     * Ceiling of 1: the child claims it under the row lock and holds the lock
     * open. The parent's own claim must BLOCK on that lock, then observe the
     * COMMITTED counter and refuse -- exactly one initiation total, never two.
     */
    public function testAConcurrentClaimBlocksOnTheLinkLockAndCannotExceedTheCeiling(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connection = $this->migratedConnection($pgConfig);
        $context = $this->pgsqlContext($connection);
        $links = new PaymentLinkRepository();
        $linkUuid = 'plinkpglk01';

        $this->deleteLinkRows($connection);

        try {
            $links->insert(
                $context,
                self::TENANT,
                $linkUuid,
                self::ORDER,
                hash('sha256', 'pg-race-ceiling'),
                $this->at('23:00:00'),
                self::ACTOR,
                $this->at('08:00:00')
            );

            $handle = $this->launchRaceChild($pgConfig, 'hold_claim', [
                'tenant' => self::TENANT,
                'linkUuid' => $linkUuid,
                'now' => '2026-08-11 13:30:00',
                'limit' => 1,
                'sleepMs' => 500,
            ]);

            // Give the child time to take the lock and its uncommitted increment.
            usleep(200_000);

            $parentClaimed = $links->claimInitiationWindow($context, self::TENANT, $linkUuid, $this->at('13:31:00'), 1);
            $childResult = $this->collectRaceChild($handle);

            self::assertTrue($childResult['ok'] ?? false, 'the holding claim must commit cleanly');
            self::assertTrue($childResult['claimed'] ?? false, 'the child holds the lock and claims first');
            self::assertFalse($parentClaimed, 'the blocked claim must observe the committed count and refuse');

            $row = $links->findByUuid($context, self::TENANT, $linkUuid);
            self::assertNotNull($row);
            self::assertSame(1, (int) $row['initiation_count'], 'a ceiling of 1 must never be claimed twice');
            self::assertSame('2026-08-11 13:00:00', $this->utc((string) $row['initiation_window_started_at']));
        } finally {
            $this->deleteLinkRows($connection);
        }
    }

    /**
     * The same race with room in the window: BOTH claims must land. A lost
     * update (the classic read-modify-write bug the row lock exists to prevent)
     * would leave the counter at 1 while two provider sessions were initiated.
     */
    public function testTwoConcurrentClaimsWithHeadroomBothCountAndNeitherIsLost(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connection = $this->migratedConnection($pgConfig);
        $context = $this->pgsqlContext($connection);
        $links = new PaymentLinkRepository();
        $linkUuid = 'plinkpglk02';

        $this->deleteLinkRows($connection);

        try {
            $links->insert(
                $context,
                self::TENANT,
                $linkUuid,
                self::ORDER,
                hash('sha256', 'pg-race-headroom'),
                $this->at('23:00:00'),
                self::ACTOR,
                $this->at('08:00:00')
            );

            $handle = $this->launchRaceChild($pgConfig, 'hold_claim', [
                'tenant' => self::TENANT,
                'linkUuid' => $linkUuid,
                'now' => '2026-08-11 13:30:00',
                'limit' => 10,
                'sleepMs' => 500,
            ]);

            usleep(200_000);

            $parentClaimed = $links->claimInitiationWindow(
                $context,
                self::TENANT,
                $linkUuid,
                $this->at('13:31:00'),
                10
            );
            $childResult = $this->collectRaceChild($handle);

            self::assertTrue($childResult['ok'] ?? false, 'the holding claim must commit cleanly');
            self::assertTrue($childResult['claimed'] ?? false);
            self::assertTrue($parentClaimed, 'with headroom the blocked claim must succeed once unblocked');

            $row = $links->findByUuid($context, self::TENANT, $linkUuid);
            self::assertNotNull($row);
            self::assertSame(2, (int) $row['initiation_count'], 'neither concurrent claim may be lost');
        } finally {
            $this->deleteLinkRows($connection);
        }
    }

    /**
     * A stored `initiation_window_started_at` read back from PostgreSQL must
     * still be recognised as the SAME fixed UTC hour -- otherwise every claim
     * would look like a fresh window and the ceiling would never bite.
     */
    public function testTheStoredWindowRoundTripsSoTheCounterAccumulatesAcrossClaims(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $context = $this->pgsqlContext($connection);
        $links = new PaymentLinkRepository();
        $linkUuid = 'plinkpglk03';

        $this->deleteLinkRows($connection);

        try {
            $links->insert(
                $context,
                self::TENANT,
                $linkUuid,
                self::ORDER,
                hash('sha256', 'pg-window-roundtrip'),
                $this->at('23:00:00'),
                self::ACTOR,
                $this->at('08:00:00')
            );

            for ($i = 1; $i <= 3; $i++) {
                self::assertTrue(
                    $links->claimInitiationWindow($context, self::TENANT, $linkUuid, $this->at('13:0' . $i . ':00'), 3)
                );
            }
            self::assertFalse(
                $links->claimInitiationWindow($context, self::TENANT, $linkUuid, $this->at('13:59:00'), 3)
            );

            // The next UTC hour is a genuinely new window on PostgreSQL too.
            self::assertTrue(
                $links->claimInitiationWindow($context, self::TENANT, $linkUuid, $this->at('14:00:00'), 3)
            );

            $row = $links->findByUuid($context, self::TENANT, $linkUuid);
            self::assertNotNull($row);
            self::assertSame(1, (int) $row['initiation_count']);
            self::assertSame('2026-08-11 14:00:00', $this->utc((string) $row['initiation_window_started_at']));
        } finally {
            $this->deleteLinkRows($connection);
        }
    }

    /**
     * The exposure stamp and the guard read are the other two clock-sensitive
     * primitives; both must behave identically on a real PostgreSQL round trip.
     */
    public function testExposureStampAndGuardReadBehaveIdenticallyOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $context = $this->pgsqlContext($connection);
        $links = new PaymentLinkRepository();

        $this->deleteLinkRows($connection);

        try {
            $links->insert(
                $context,
                self::TENANT,
                'plinkpglk04',
                'plinkpgordA',
                hash('sha256', 'pg-guard-active'),
                $this->at('23:00:00'),
                self::ACTOR,
                $this->at('08:00:00')
            );
            $links->insert(
                $context,
                self::TENANT,
                'plinkpglk05',
                'plinkpgordB',
                hash('sha256', 'pg-guard-exposed'),
                new \DateTimeImmutable('2026-08-01 00:00:00', new \DateTimeZone('UTC')),
                self::ACTOR,
                $this->at('08:00:00')
            );
            self::assertTrue(
                $links->stampProviderSessionIssued($context, self::TENANT, 'plinkpglk05', $this->at('09:00:00'))
            );
            self::assertTrue($links->revoke($context, self::TENANT, 'plinkpglk05', $this->at('09:30:00')));

            $now = $this->at('13:00:00');
            self::assertTrue($links->hasGuardRelevantLink($context, self::TENANT, 'plinkpgordA', $now));
            self::assertTrue(
                $links->hasGuardRelevantLink($context, self::TENANT, 'plinkpgordB', $now),
                'a revoked but historically exposed link stays guard-relevant on PostgreSQL too'
            );

            $exposed = $links->findByUuid($context, self::TENANT, 'plinkpglk05');
            self::assertNotNull($exposed);
            self::assertSame('2026-08-11 09:00:00', $this->utc((string) $exposed['provider_session_issued_at']));

            // Re-stamping keeps the FIRST exposure instant across the round trip.
            self::assertTrue(
                $links->stampProviderSessionIssued($context, self::TENANT, 'plinkpglk05', $this->at('12:00:00'))
            );
            $again = $links->findByUuid($context, self::TENANT, 'plinkpglk05');
            self::assertNotNull($again);
            self::assertSame('2026-08-11 09:00:00', $this->utc((string) $again['provider_session_issued_at']));
        } finally {
            $this->deleteLinkRows($connection);
        }
    }

    // --- Helpers -------------------------------------------------------------
    // (pgsql lane setup mirrors Orders\DraftAttemptRepositoryPgsqlTest exactly.)

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-11 ' . $time, new \DateTimeZone('UTC'));
    }

    /** A stored PostgreSQL timestamp reduced to the canonical UTC `Y-m-d H:i:s` form. */
    private function utc(string $stored): string
    {
        return (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove the claim really runs under a row lock.');
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

    private function deleteLinkRows(Connection $connection): void
    {
        $connection->table('commerce_payment_links')
            ->where('tenant_uuid', '=', self::TENANT)
            ->delete();
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
                __DIR__ . '/fixtures/payment_link_race_child.php',
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
