<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Downloads;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL regression for `DownloadGrantRepository::mint()`'s DB-time expiry
 * guard under a non-UTC session (see {@see \Glueful\Extensions\Commerce\Support\UtcNowSql}
 * and `mint()`'s own docblock). This is the exact fail-open documented in Task 5's
 * report: a naive `expires_at` `timestamp` column holds UTC wall-clock time
 * (`gmdate()`), but the bare `CURRENT_TIMESTAMP` keyword is a PostgreSQL `timestamptz`
 * that gets implicitly cast down to the SESSION's local timezone before comparison --
 * so under a non-UTC session, an already-expired grant could mint successfully for up
 * to |UTC offset| past its true expiry (fails OPEN, not closed).
 * `UtcNowSql::expression('pgsql')` pins the comparison to UTC regardless of session
 * timezone; this test proves the fix live against a real PostgreSQL server (SQLite's
 * `CURRENT_TIMESTAMP` is already UTC and never exhibits this bug, so it can't catch
 * this class of regression).
 *
 * Follows the same pgsql-lane gating (`COMMERCE_TEST_DB_DRIVER=pgsql`) and
 * fresh-migrated-connection setup as `Catalog\ReviewConcurrencyTest` /
 * `Catalog\CategoryTreeConcurrencyTest`, but needs no second connection/subprocess --
 * this is a single-session timezone assertion, not a cross-connection race.
 */
final class DownloadGrantExpiryPgsqlTest extends CommerceTestCase
{
    public function testMintRejectsAnHourExpiredGrantUnderANonUtcPostgresSession(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane to exercise a real session timezone.');
        }

        $connection = $this->migratedConnection($this->pgConfig());
        $context = $this->pgsqlContext($connection);

        $orderUuid = 'pgexpord0001';
        $grantUuid = 'pgexpgrant01';

        // Self-healing: wipe debris a previously-interrupted run of this same
        // pgsql-gated test left behind before inserting the fixture row.
        $connection->table('commerce_download_grants')->where('uuid', '=', $grantUuid)->delete();

        try {
            // Non-UTC session -- the exact condition the finding describes. Every
            // subsequent DB-time read/write on this connection is affected by it.
            $connection->getPDO()->exec("SET TIME ZONE 'America/New_York'");

            $connection->table('commerce_download_grants')->insert([
                'uuid' => $grantUuid,
                'tenant_uuid' => '',
                'order_uuid' => $orderUuid,
                'download_uuid' => 'pgexpdl00001',
                'blob_uuid' => 'pgexpblob001',
                'name' => 'Expired.pdf',
                'token_hash' => hash('sha256', $grantUuid),
                'remaining' => null,
                // Expired ONE HOUR AGO in true UTC -- a naive value, exactly how
                // DownloadGrantService writes expires_at via gmdate().
                'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
            ]);

            $minted = (new DownloadGrantRepository())->mint($context, '', $orderUuid, $grantUuid);

            // This is the exact fail-open the finding describes: on the pre-fix code
            // (bare CURRENT_TIMESTAMP in the guarded UPDATE), PostgreSQL casts
            // CURRENT_TIMESTAMP down to the session's local wall-clock time before
            // comparing against the naive expires_at column -- shifting the
            // comparison by the session's UTC offset (4-5h for America/New_York) and
            // making an expiry that is 1 hour in the past appear still in the future,
            // so the guarded UPDATE would have affected 1 row (minted = true) instead
            // of correctly rejecting it. UtcNowSql::expression('pgsql') fixes this by
            // pinning the comparison to UTC regardless of session timezone.
            self::assertFalse(
                $minted,
                'An hour-expired grant must be rejected even under a non-UTC PostgreSQL session.'
            );

            $reloaded = $connection->table('commerce_download_grants')->where('uuid', '=', $grantUuid)->first();
            self::assertNotNull($reloaded);
            self::assertSame(0, (int) $reloaded['mint_count'], 'A rejected mint must not increment mint_count.');
            self::assertNull($reloaded['last_minted_at'], 'A rejected mint must not stamp last_minted_at.');
        } finally {
            // Leave the pgsql fixture database as we found it.
            $connection->table('commerce_download_grants')->where('uuid', '=', $grantUuid)->delete();
        }
    }

    // --- Helpers -------------------------------------------------------------
    // (pgsql lane setup mirrors Catalog\ReviewConcurrencyTest exactly.)

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
}
