<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Execution\QueryExecutor;
use Glueful\Extensions\Commerce\Orders\DraftAttemptRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Admin-order-creation cycle 2, Task 6 (design spec §2.6): `DraftAttemptRepository`,
 * the finalize idempotency ledger for admin-created ("walk-in") orders. UNIQUE
 * `(tenant_uuid, idempotency_key)` is the sole key authority -- these tests exercise
 * `claimOrReplay()`/`complete()` sequentially (single connection); the genuine
 * concurrent-first-claim race lives in `DraftAttemptRepositoryPgsqlTest` (needs two
 * real database connections, so SQLite can't produce it).
 */
final class DraftAttemptRepositoryTest extends CommerceTestCase
{
    public function testFreshClaimInsertsPendingRowAndReturnsFreshState(): void
    {
        $repo = new DraftAttemptRepository();

        $result = $repo->claimOrReplay($this->context, 'tenantA00001', 'idem-key-1', 'fp-aaaa', 'orderuuid001');

        self::assertSame('fresh', $result['state']);
        self::assertSame('tenantA00001', $result['attempt']['tenant_uuid']);
        self::assertSame('idem-key-1', $result['attempt']['idempotency_key']);
        self::assertSame('fp-aaaa', $result['attempt']['request_fingerprint']);
        self::assertSame('orderuuid001', $result['attempt']['order_uuid']);
        self::assertSame('pending', $result['attempt']['status']);
        self::assertNull($result['attempt']['completed_at']);

        self::assertSame(
            1,
            $this->connection->table('commerce_order_draft_attempts')
                ->where('tenant_uuid', '=', 'tenantA00001')
                ->count()
        );
    }

    public function testSameKeySameFingerprintReplaysTheExistingPendingAttemptWithNoSecondInsert(): void
    {
        $repo = new DraftAttemptRepository();
        $repo->claimOrReplay($this->context, 'tenantA00002', 'idem-key-2', 'fp-bbbb', 'orderuuid002');

        $result = $repo->claimOrReplay($this->context, 'tenantA00002', 'idem-key-2', 'fp-bbbb', 'orderuuid999');

        self::assertSame('replay', $result['state']);
        // The ORIGINAL order_uuid, not the second caller's argument -- a replay
        // resolves to what already exists, never re-executes.
        self::assertSame('orderuuid002', $result['attempt']['order_uuid']);
        self::assertSame(
            1,
            $this->connection->table('commerce_order_draft_attempts')
                ->where('tenant_uuid', '=', 'tenantA00002')
                ->count()
        );
    }

    public function testSameKeyDifferentFingerprintReturnsFingerprintMismatchWithNoSecondInsert(): void
    {
        $repo = new DraftAttemptRepository();
        $repo->claimOrReplay($this->context, 'tenantA00003', 'idem-key-3', 'fp-cccc', 'orderuuid003');

        $result = $repo->claimOrReplay($this->context, 'tenantA00003', 'idem-key-3', 'fp-dddd', 'orderuuid888');

        self::assertSame('fingerprint_mismatch', $result['state']);
        self::assertSame('fp-cccc', $result['attempt']['request_fingerprint']);
        self::assertSame(
            1,
            $this->connection->table('commerce_order_draft_attempts')
                ->where('tenant_uuid', '=', 'tenantA00003')
                ->count()
        );
    }

    public function testCompleteMarksStatusCompletedWithATimestamp(): void
    {
        $repo = new DraftAttemptRepository();
        $result = $repo->claimOrReplay($this->context, 'tenantA00004', 'idem-key-4', 'fp-eeee', 'orderuuid004');

        $repo->complete($this->context, (int) $result['attempt']['id']);

        $row = $this->connection->table('commerce_order_draft_attempts')
            ->where('tenant_uuid', '=', 'tenantA00004')
            ->first();
        self::assertNotNull($row);
        self::assertSame('completed', $row['status']);
        self::assertNotNull($row['completed_at']);
    }

    public function testReplayAfterCompleteStillResolvesToTheCompletedAttempt(): void
    {
        $repo = new DraftAttemptRepository();
        $first = $repo->claimOrReplay($this->context, 'tenantA00005', 'idem-key-5', 'fp-ffff', 'orderuuid005');
        $repo->complete($this->context, (int) $first['attempt']['id']);

        $result = $repo->claimOrReplay($this->context, 'tenantA00005', 'idem-key-5', 'fp-ffff', 'orderuuid777');

        self::assertSame('replay', $result['state']);
        self::assertSame('completed', $result['attempt']['status']);
        self::assertSame('orderuuid005', $result['attempt']['order_uuid']);
    }

    public function testDifferentTenantsWithTheSameIdempotencyKeyClaimIndependently(): void
    {
        $repo = new DraftAttemptRepository();

        $a = $repo->claimOrReplay($this->context, 'tenantSHARE1', 'idem-shared', 'fp-a', 'orderuuidaaa');
        $b = $repo->claimOrReplay($this->context, 'tenantSHARE2', 'idem-shared', 'fp-b', 'orderuuidbbb');

        self::assertSame('fresh', $a['state']);
        self::assertSame('fresh', $b['state']);
        self::assertSame('orderuuidaaa', $a['attempt']['order_uuid']);
        self::assertSame('orderuuidbbb', $b['attempt']['order_uuid']);
    }

    /**
     * Review fix (Critical): drives the INSERT-then-catch branch of `claimOrReplay()`
     * with a GENUINE duplicate-key conflict on SQLite -- every sequential test above
     * resolves through the pre-check `findByKey()` early-return and never reaches the
     * insert attempt at all, so none of them exercised the catch clause against a real
     * SQLite constraint failure. A real race needs two connections; SQLite's `:memory:`
     * is private per-connection, so this uses a shared temp FILE (connection B is a
     * genuinely separate PDO handle/session onto the same file, exactly like
     * `CommerceTestCase::sqlitePath()`'s own docblock describes this scenario) plus the
     * framework's global query-interceptor seam ({@see QueryExecutor::addQueryInterceptorCallback()})
     * to land B's insert-and-autocommit at the exact moment connection A is about to run
     * its OWN insert -- synchronously, no threads/subprocess needed, because the
     * interceptor fires deterministically right before `PDOStatement::execute()`. A's
     * pre-check has ALREADY run (and seen nothing, since B hasn't written yet), so A's
     * insert is now the one that collides -- with SQLite's BARE (non-extended) result
     * code 19, which classifies as {@see \Glueful\Database\Exceptions\ConstraintViolationException},
     * NOT `UniqueConstraintViolationException`. Pre-fix (narrow catch), this exception
     * escaped uncaught; post-fix, it resolves to a typed `fingerprint_mismatch` with
     * B's winning row, exactly like the real two-connection PostgreSQL race.
     */
    public function testDuplicateInsertRaceResolvesTypedNotRawOnSqlite(): void
    {
        $path = sys_get_temp_dir() . '/draft_attempt_sqlite_race_' . bin2hex(random_bytes(6)) . '.sqlite';
        @unlink($path);

        try {
            $connectionA = new Connection([
                'engine' => 'sqlite',
                'sqlite' => ['primary' => $path],
                'pooling' => ['enabled' => false],
            ]);
            $schema = $connectionA->getSchemaBuilder();
            foreach (self::MIGRATIONS as $migration) {
                (new $migration())->up($schema);
            }
            $contextA = $this->contextFor($connectionA);

            // A genuinely separate PDO handle/session onto the SAME file -- its writes
            // are real, autocommitted statements, immune to whatever A's own
            // transaction/savepoint later rolls back.
            $connectionB = new Connection([
                'engine' => 'sqlite',
                'sqlite' => ['primary' => $path],
                'pooling' => ['enabled' => false],
            ]);

            $tenant = 'racesqlite01';
            $key = 'idem-race-sqlite';
            $fired = false;

            QueryExecutor::addQueryInterceptorCallback(
                static function (string $sql, array $bindings) use ($connectionB, $tenant, $key, &$fired): void {
                    if (
                        $fired
                        || stripos($sql, 'insert into') === false
                        || stripos($sql, 'commerce_order_draft_attempts') === false
                    ) {
                        return;
                    }
                    $fired = true;
                    // Raw PDO exec bypasses the framework's QueryExecutor entirely (no
                    // interceptor re-entry) and commits immediately (autocommit).
                    $connectionB->getPDO()->exec(
                        'INSERT INTO commerce_order_draft_attempts '
                        . '(tenant_uuid, idempotency_key, request_fingerprint, order_uuid, status) VALUES ('
                        . $connectionB->getPDO()->quote($tenant) . ', '
                        . $connectionB->getPDO()->quote($key) . ', '
                        . $connectionB->getPDO()->quote('fp-from-connection-b') . ', '
                        . $connectionB->getPDO()->quote('orderuuidbbb') . ', '
                        . "'pending')"
                    );
                }
            );

            try {
                $result = (new DraftAttemptRepository())->claimOrReplay(
                    $contextA,
                    $tenant,
                    $key,
                    'fp-from-connection-a',
                    'orderuuidaaa'
                );
            } finally {
                QueryExecutor::clearQueryInterceptors();
            }

            self::assertTrue($fired, 'the interceptor must have fired to prove this test actually raced');
            self::assertSame('fingerprint_mismatch', $result['state']);
            self::assertSame('orderuuidbbb', $result['attempt']['order_uuid']);
            self::assertSame('fp-from-connection-b', $result['attempt']['request_fingerprint']);
            self::assertSame(
                1,
                $connectionA->table('commerce_order_draft_attempts')
                    ->where('tenant_uuid', '=', $tenant)
                    ->where('idempotency_key', '=', $key)
                    ->count(),
                'A must never have inserted a second row'
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * Same race shape as above, but B's winning row carries the SAME fingerprint A is
     * about to claim with -- proving the wider `ConstraintViolationException` catch
     * resolves to `replay`, not just `fingerprint_mismatch`, when that's what the
     * winner's row actually matches.
     */
    public function testDuplicateInsertRaceWithMatchingFingerprintResolvesToReplayOnSqlite(): void
    {
        $path = sys_get_temp_dir() . '/draft_attempt_sqlite_race_' . bin2hex(random_bytes(6)) . '.sqlite';
        @unlink($path);

        try {
            $connectionA = new Connection([
                'engine' => 'sqlite',
                'sqlite' => ['primary' => $path],
                'pooling' => ['enabled' => false],
            ]);
            $schema = $connectionA->getSchemaBuilder();
            foreach (self::MIGRATIONS as $migration) {
                (new $migration())->up($schema);
            }
            $contextA = $this->contextFor($connectionA);

            $connectionB = new Connection([
                'engine' => 'sqlite',
                'sqlite' => ['primary' => $path],
                'pooling' => ['enabled' => false],
            ]);

            $tenant = 'racesqlite02';
            $key = 'idem-race-sqlite-2';
            $sharedFingerprint = 'fp-shared';
            $fired = false;

            QueryExecutor::addQueryInterceptorCallback(
                static function (
                    string $sql,
                    array $bindings
                ) use ($connectionB, $tenant, $key, $sharedFingerprint, &$fired): void {
                    if (
                        $fired
                        || stripos($sql, 'insert into') === false
                        || stripos($sql, 'commerce_order_draft_attempts') === false
                    ) {
                        return;
                    }
                    $fired = true;
                    $connectionB->getPDO()->exec(
                        'INSERT INTO commerce_order_draft_attempts '
                        . '(tenant_uuid, idempotency_key, request_fingerprint, order_uuid, status) VALUES ('
                        . $connectionB->getPDO()->quote($tenant) . ', '
                        . $connectionB->getPDO()->quote($key) . ', '
                        . $connectionB->getPDO()->quote($sharedFingerprint) . ', '
                        . $connectionB->getPDO()->quote('orderuuidbbb') . ', '
                        . "'pending')"
                    );
                }
            );

            try {
                $result = (new DraftAttemptRepository())->claimOrReplay(
                    $contextA,
                    $tenant,
                    $key,
                    $sharedFingerprint,
                    'orderuuidaaa'
                );
            } finally {
                QueryExecutor::clearQueryInterceptors();
            }

            self::assertTrue($fired, 'the interceptor must have fired to prove this test actually raced');
            self::assertSame('replay', $result['state']);
            self::assertSame('orderuuidbbb', $result['attempt']['order_uuid']);
        } finally {
            @unlink($path);
        }
    }

    private function contextFor(Connection $connection): ApplicationContext
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
     * Mirrors `Wishlist\WishlistRepositoryTest::testARealDatabaseFailureIsNotReportedAsAlreadySaved()`:
     * dropping the table the repository targets makes BOTH the pre-check lookup and the
     * insert attempt fail with a genuinely unrelated error (not a unique violation) --
     * proving `claimOrReplay()` never swallows or misclassifies a real database outage /
     * schema-drift failure as any of its three typed states.
     */
    public function testUnrelatedDatabaseFailureIsNotSwallowedOrMisclassified(): void
    {
        $this->connection->getPDO()->exec('DROP TABLE commerce_order_draft_attempts');

        $this->expectException(\Throwable::class);
        (new DraftAttemptRepository())->claimOrReplay(
            $this->context,
            'tenantFAIL01',
            'idem-fail',
            'fp-fail',
            'orderuuidfai'
        );
    }
}
