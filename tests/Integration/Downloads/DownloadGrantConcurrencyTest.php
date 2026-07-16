<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Downloads;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadUrlSigner;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Repository\BlobRepository;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL races binding design spec §9's three download-grant lock paths (the
 * fourth, concurrent first-default address creation, lives in
 * `Customers\AddressBookConcurrencyTest`):
 *
 *  (a) finite double-mint on `remaining = 1` -- exactly one 200-equivalent mint, one
 *      410-equivalent `exhausted`.
 *  (b) unlimited-grant mint-vs-revoke -- both are guarded UPDATEs on the SAME
 *      `commerce_download_grants` row (`DownloadGrantRepository::mint()` /
 *      `::revoke()`), so whichever commits first wins: a mint that loses the race to
 *      a committed revoke is rejected; a mint that WINS the race stands even after a
 *      later revoke.
 *  (c) mint-vs-full-refund-completion -- both serialize through the shared
 *      `OrderRepository::claimOrderFinancialMutation()` row (the same primitive
 *      refunds and mints both claim, see that method's docblock): a refund that
 *      completes first blocks the mint (`blocked_by_full_refund`, never reaching the
 *      grant UPDATE at all); a mint that wins first stands, and the refund still
 *      proceeds afterward.
 *
 * Every race follows the exact house two-connection pattern established by
 * `Catalog\ProductChildrenConcurrencyTest` / `Catalog\MediaTenancyConcurrencyTest` /
 * `Refunds\GatewayRefundTest`: connection A (this test) holds a row claimed and
 * uncommitted via the repository primitive directly, a genuinely independent
 * subprocess (connection B) attempts the real service call and blocks on A's held
 * PostgreSQL row lock, then A completes and commits -- releasing the lock so B's
 * blocked attempt can proceed and be asserted against.
 */
final class DownloadGrantConcurrencyTest extends CommerceTestCase
{
    private const SIGNING_SECRET = 'test-signing-secret-0123456789ab';

    public function testConcurrentDoubleMintOnRemainingOneOverRealPostgresHasExactlyOneWinner(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);

        $orderUuid = 'ordpgmint001';
        $grantUuid = 'grtpgmint001';
        $blobUuid = 'blbpgmint001';

        $this->deleteRaceDebris($connectionA, $orderUuid, [$grantUuid], [$blobUuid]);

        try {
            $this->seedOrder($connectionA, $orderUuid);
            $this->seedBlob($connectionA, $blobUuid);
            $this->seedGrant($connectionA, $orderUuid, $grantUuid, $blobUuid, 'dlpgmint0001', remaining: 1);

            // A claims the order first -- this holds the row lock, uncommitted. The
            // claim primitive (not the full service) is used directly so the test can
            // pause mid-mint while B's real mint call attempts to claim the same
            // order row.
            $connectionA->getTransactionManager()->begin();
            $orders = new OrderRepository();
            self::assertTrue($orders->claimOrderFinancialMutation($contextA, '', $orderUuid));

            [$process, $pipes] = $this->launchMintRace($pgConfig, $orderUuid, $grantUuid);

            // Give B time to reach and block on its own claim UPDATE before A proceeds.
            usleep(300_000);

            // A completes its own mint directly (it already holds the order claim, so
            // no service-level re-claim is needed): sign, then consume the ONE
            // remaining slot, then commit -- releasing the row lock so B's blocked
            // claim can proceed.
            $signed = (new DownloadUrlSigner(new BlobRepository($connectionA)))
                ->sign($contextA, $blobUuid, 'http://localhost');
            self::assertIsString($signed['url']);
            $grants = new DownloadGrantRepository();
            self::assertTrue($grants->mint($contextA, '', $orderUuid, $grantUuid));
            $connectionA->getTransactionManager()->commit();

            $result = $this->finishProcess($process, $pipes);
            self::assertSame(
                false,
                $result['ok'],
                "The second, blocked mint must lose once A's mint has consumed the only "
                    . 'remaining slot.'
            );
            self::assertSame('exhausted', $result['code']);

            $grant = $connectionA->table('commerce_download_grants')->where('uuid', '=', $grantUuid)->first();
            self::assertNotNull($grant);
            self::assertSame(1, (int) $grant['mint_count'], 'exactly one winner incremented mint_count');
            self::assertSame(0, (int) $grant['remaining']);
        } finally {
            $this->deleteRaceDebris($connectionA, $orderUuid, [$grantUuid], [$blobUuid]);
        }
    }

    public function testConcurrentRevokeBeforeMintOnUnlimitedGrantBlocksTheMint(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);

        $orderUuid = 'ordpgrevk001';
        $grantUuid = 'grtpgrevk001';
        $blobUuid = 'blbpgrevk001';

        $this->deleteRaceDebris($connectionA, $orderUuid, [$grantUuid], [$blobUuid]);

        try {
            $this->seedOrder($connectionA, $orderUuid);
            $this->seedBlob($connectionA, $blobUuid);
            $this->seedGrant($connectionA, $orderUuid, $grantUuid, $blobUuid, 'dlpgrevk0001', remaining: null);

            // A claims the GRANT row directly via the guarded revoke UPDATE -- this
            // holds that row's lock, uncommitted -- while B's real mint call (which
            // claims the ORDER row first, uncontested, then reaches its own guarded
            // UPDATE on the SAME grant row) blocks behind it.
            $connectionA->getTransactionManager()->begin();
            $grants = new DownloadGrantRepository();
            self::assertTrue($grants->revoke($contextA, '', $grantUuid));

            [$process, $pipes] = $this->launchMintRace($pgConfig, $orderUuid, $grantUuid);

            usleep(300_000);

            $connectionA->getTransactionManager()->commit();

            $result = $this->finishProcess($process, $pipes);
            self::assertSame(false, $result['ok'], 'A mint that loses the race to a committed revoke must fail.');
            self::assertSame('revoked', $result['code']);

            $grant = $connectionA->table('commerce_download_grants')->where('uuid', '=', $grantUuid)->first();
            self::assertNotNull($grant);
            self::assertSame(0, (int) $grant['mint_count'], 'the rejected mint must not increment mint_count');
            self::assertNull($grant['remaining'], 'unlimited grant must stay unlimited');
            self::assertNotNull($grant['revoked_at']);
        } finally {
            $this->deleteRaceDebris($connectionA, $orderUuid, [$grantUuid], [$blobUuid]);
        }
    }

    public function testConcurrentMintBeforeRevokeOnUnlimitedGrantLetsTheMintStand(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);

        $orderUuid = 'ordpgrevk002';
        $grantUuid = 'grtpgrevk002';
        $blobUuid = 'blbpgrevk002';

        $this->deleteRaceDebris($connectionA, $orderUuid, [$grantUuid], [$blobUuid]);

        try {
            $this->seedOrder($connectionA, $orderUuid);
            $this->seedBlob($connectionA, $blobUuid);
            $this->seedGrant($connectionA, $orderUuid, $grantUuid, $blobUuid, 'dlpgrevk0002', remaining: null);

            // A claims the GRANT row directly via the guarded mint UPDATE -- this
            // holds that row's lock, uncommitted -- while B's real revoke call blocks
            // behind it on the same row.
            $connectionA->getTransactionManager()->begin();
            $grants = new DownloadGrantRepository();
            self::assertTrue($grants->mint($contextA, '', $orderUuid, $grantUuid));

            $process = proc_open(
                [
                    PHP_BINARY,
                    __DIR__ . '/fixtures/revoke_race_child.php',
                    json_encode($pgConfig, JSON_THROW_ON_ERROR),
                    '',
                    $grantUuid,
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($process);

            usleep(300_000);

            $connectionA->getTransactionManager()->commit();

            $result = $this->finishProcess($process, $pipes);
            self::assertTrue(
                $result['revoked'] ?? false,
                'A revoke that arrives after the mint already committed must still succeed.'
            );

            $grant = $connectionA->table('commerce_download_grants')->where('uuid', '=', $grantUuid)->first();
            self::assertNotNull($grant);
            self::assertSame(
                1,
                (int) $grant['mint_count'],
                "the earlier mint must stand -- a later revoke doesn't undo it"
            );
            self::assertNotNull($grant['revoked_at']);
        } finally {
            $this->deleteRaceDebris($connectionA, $orderUuid, [$grantUuid], [$blobUuid]);
        }
    }

    public function testConcurrentFullRefundCompletionBeforeMintBlocksTheMint(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);

        $orderUuid = 'ordpgrfnd001';
        $grantUuid = 'grtpgrfnd001';
        $blobUuid = 'blbpgrfnd001';
        $refundUuid = 'rfdpgrfnd001';

        $this->deleteRaceDebris($connectionA, $orderUuid, [$grantUuid], [$blobUuid]);

        try {
            $this->seedOrder($connectionA, $orderUuid, grandTotal: 1000);
            $this->seedBlob($connectionA, $blobUuid);
            $this->seedGrant($connectionA, $orderUuid, $grantUuid, $blobUuid, 'dlpgrfnd0001', remaining: 5);

            // A claims the ORDER row first -- this holds the row lock, uncommitted,
            // standing in for the first step of a full manual refund (the same claim
            // `RefundService::issueManual()` takes). B's real mint call blocks trying
            // to claim the SAME order row.
            $connectionA->getTransactionManager()->begin();
            $orders = new OrderRepository();
            self::assertTrue($orders->claimOrderFinancialMutation($contextA, '', $orderUuid));

            [$process, $pipes] = $this->launchMintRace($pgConfig, $orderUuid, $grantUuid);

            usleep(300_000);

            // A completes the full refund's effect directly (it already holds the
            // claim): insert the completed refund row and mark the order fully
            // refunded, then commit -- releasing the row lock so B's blocked claim can
            // proceed. (This mirrors RefundService::applyCompletion()'s end state
            // without re-driving the whole gateway/manual saga already covered
            // elsewhere -- this test is scoped to the shared claim's serialization,
            // not refund completion mechanics.)
            (new RefundRepository())->insert($contextA, [
                'uuid' => $refundUuid,
                'tenant_uuid' => '',
                'order_uuid' => $orderUuid,
                'idempotency_key' => 'idem-pgrfnd-001',
                'request_fingerprint' => md5('idem-pgrfnd-001'),
                'amount' => 1000,
                'currency' => 'USD',
                'method' => 'manual',
                'status' => 'completed',
                'reason' => null,
                'restocked' => false,
            ], []);
            $connectionA->table('commerce_orders')
                ->where('tenant_uuid', '=', '')
                ->where('uuid', '=', $orderUuid)
                ->update(['refunded_total' => 1000, 'status' => 'refunded']);
            $connectionA->getTransactionManager()->commit();

            $result = $this->finishProcess($process, $pipes);
            self::assertSame(
                false,
                $result['ok'],
                'A mint that loses the race to a completed full refund must be blocked.'
            );
            self::assertSame('blocked_by_full_refund', $result['code']);
            self::assertFalse($result['urlPresent']);

            $grant = $connectionA->table('commerce_download_grants')->where('uuid', '=', $grantUuid)->first();
            self::assertNotNull($grant);
            self::assertSame(0, (int) $grant['mint_count'], 'a blocked mint must never reach the grant UPDATE');
            self::assertSame(5, (int) $grant['remaining']);
            self::assertNull($grant['last_minted_at']);
        } finally {
            // deleteRaceDebris() below also clears commerce_refunds by order_uuid; no
            // refund lines were ever inserted (this race's synthetic refund passes an
            // empty lines array), so nothing else needs cleaning up here.
            $this->deleteRaceDebris($connectionA, $orderUuid, [$grantUuid], [$blobUuid]);
        }
    }

    public function testConcurrentMintBeforeFullRefundCompletionLetsTheMintStandAndRefundStillProceeds(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);

        $orderUuid = 'ordpgrfnd002';
        $grantUuid = 'grtpgrfnd002';
        $blobUuid = 'blbpgrfnd002';

        $this->deleteRaceDebris($connectionA, $orderUuid, [$grantUuid], [$blobUuid]);

        try {
            $this->seedOrder($connectionA, $orderUuid, grandTotal: 1000);
            $this->seedBlob($connectionA, $blobUuid);
            $this->seedGrant($connectionA, $orderUuid, $grantUuid, $blobUuid, 'dlpgrfnd0002', remaining: 5);

            // A claims the ORDER row first -- this holds the row lock, uncommitted,
            // standing in for the first step of an in-flight mint. B's real full
            // manual refund blocks trying to claim the SAME order row.
            $connectionA->getTransactionManager()->begin();
            $orders = new OrderRepository();
            self::assertTrue($orders->claimOrderFinancialMutation($contextA, '', $orderUuid));

            $process = proc_open(
                [
                    PHP_BINARY,
                    __DIR__ . '/fixtures/refund_race_child.php',
                    json_encode($pgConfig, JSON_THROW_ON_ERROR),
                    '',
                    $orderUuid,
                    'idem-pgrfnd-002',
                    '1000',
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($process);

            usleep(300_000);

            // A completes its own mint directly (it already holds the order claim):
            // sign, then consume one slot, then commit -- releasing the row lock so
            // B's blocked refund claim can proceed.
            $signed = (new DownloadUrlSigner(new BlobRepository($connectionA)))
                ->sign($contextA, $blobUuid, 'http://localhost');
            self::assertIsString($signed['url']);
            $grants = new DownloadGrantRepository();
            self::assertTrue($grants->mint($contextA, '', $orderUuid, $grantUuid));
            $connectionA->getTransactionManager()->commit();

            $result = $this->finishProcess($process, $pipes);
            self::assertSame(
                'completed',
                $result['status'] ?? null,
                "The refund must still proceed once A's mint has released the claim."
            );

            $order = $connectionA->table('commerce_orders')->where('uuid', '=', $orderUuid)->first();
            self::assertNotNull($order);
            self::assertSame('refunded', $order['status']);
            self::assertSame(1000, (int) $order['refunded_total']);

            $grant = $connectionA->table('commerce_download_grants')->where('uuid', '=', $grantUuid)->first();
            self::assertNotNull($grant);
            self::assertSame(
                1,
                (int) $grant['mint_count'],
                "the earlier mint must stand -- the later refund doesn't undo it"
            );
            self::assertSame(4, (int) $grant['remaining']);
        } finally {
            // RefundService::issueManual() built its own refund row for this order via
            // the real service (RefundInput's lines are empty, so no refund_lines rows
            // exist to clean up); deleteRaceDebris() below clears commerce_refunds by
            // order_uuid alongside the grant/blob/order rows.
            $this->deleteRaceDebris($connectionA, $orderUuid, [$grantUuid], [$blobUuid]);
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $pgConfig
     * @return array{0: resource, 1: array<int,resource>} */
    private function launchMintRace(array $pgConfig, string $orderUuid, string $grantUuid): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/mint_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                '',
                $orderUuid,
                $grantUuid,
                'http://localhost',
                self::SIGNING_SECRET,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param resource $process
     * @param array<int,resource> $pipes
     * @return array<string,mixed>
     */
    private function finishProcess($process, array $pipes): array
    {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "Connection B's subprocess produced no parseable result. stderr: {$stderr}");

        return $result;
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
        require_once __DIR__ . '/../../../vendor/glueful/framework/migrations/uploads/001_CreateBlobsTable.php';
        (new \Glueful\Migrations\Uploads\CreateBlobsTable())->up($schema);

        return $connection;
    }

    private function seedOrder(Connection $connection, string $orderUuid, int $grandTotal = 1000): void
    {
        $connection->table('commerce_orders')->insert([
            'uuid' => $orderUuid,
            'tenant_uuid' => '',
            'order_number' => 'ORD-' . $orderUuid,
            'status' => 'paid',
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => $grandTotal,
            'grand_total' => $grandTotal,
            'refunded_total' => 0,
        ]);
    }

    private function seedBlob(Connection $connection, string $blobUuid): void
    {
        $connection->table('blobs')->insert([
            'uuid' => $blobUuid,
            'name' => $blobUuid,
            'mime_type' => 'application/pdf',
            'size' => 100,
            'url' => '/storage/' . $blobUuid,
            'storage_type' => 'local',
            'visibility' => 'private',
            'status' => 'active',
            'created_by' => 'uploader0001',
        ]);
    }

    private function seedGrant(
        Connection $connection,
        string $orderUuid,
        string $grantUuid,
        string $blobUuid,
        string $downloadUuid,
        ?int $remaining,
    ): void {
        $connection->table('commerce_download_grants')->insert([
            'uuid' => $grantUuid,
            'tenant_uuid' => '',
            'order_uuid' => $orderUuid,
            'download_uuid' => $downloadUuid,
            'blob_uuid' => $blobUuid,
            'name' => 'Race.pdf',
            'token_hash' => TokenHasher::generate()['hash'],
            'remaining' => $remaining,
        ]);
    }

    /**
     * Idempotent pgsql-lane cleanup: neither commerce_download_grants,
     * commerce_refunds, nor commerce_orders carries a deleted_at column, so a plain
     * delete() is enough for those. `blobs` DOES carry deleted_at (framework core
     * uploads table), so it needs forceDelete() -- a plain delete() would soft-delete
     * it, leaving the row (and its unique uuid) physically in place and breaking this
     * cleanup's idempotency across repeated runs of these same pgsql-gated tests.
     *
     * @param list<string> $grantUuids
     * @param list<string> $blobUuids
     */
    private function deleteRaceDebris(
        Connection $connection,
        string $orderUuid,
        array $grantUuids,
        array $blobUuids
    ): void {
        foreach ($grantUuids as $grantUuid) {
            $connection->table('commerce_download_grants')->where('uuid', '=', $grantUuid)->delete();
        }
        foreach ($blobUuids as $blobUuid) {
            $connection->table('blobs')->where('uuid', '=', $blobUuid)->forceDelete();
        }
        $connection->table('commerce_refunds')->where('order_uuid', '=', $orderUuid)->delete();
        $connection->table('commerce_orders')->where('uuid', '=', $orderUuid)->delete();
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
        $context->overrideConfig('app.key', self::SIGNING_SECRET);

        return $context;
    }
}
