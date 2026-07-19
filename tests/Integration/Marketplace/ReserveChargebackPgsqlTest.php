<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
use Glueful\Extensions\Commerce\Marketplace\ReserveConsumptionService;
use Glueful\Extensions\Commerce\Marketplace\ReserveRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL race lanes for Marketplace MV5a's risk-reserve/chargeback
 * machinery (design spec §2.3/§2.5/§2.6/§2.7/§8; Task 17, GATES). Every case
 * here requires TRUE two-connection row-lock interleaving that SQLite -- a
 * single-process, single-connection engine in this test harness -- cannot
 * exercise at all. This file mirrors `PayoutSagaPgsqlTest`/
 * `SettlementPgsqlTest` primitive-for-primitive: `skipUnlessPgsql()`,
 * `pgConfig()`, `migratedConnection()`, `pgsqlContext()`,
 * `launchRaceChild()`/`collectRaceChild()`, and the fixture-width discipline
 * (every `uuid`/`tenant_uuid`/`seller_uuid` literal here is 11 or 12
 * characters -- `varchar(12)`, strictly enforced by PostgreSQL but silently
 * ignored by SQLite).
 *
 * Both lanes follow the SAME established pattern this suite's earlier pgsql
 * files establish: connection A (this test) manually replicates the
 * mid-transaction critical section of the real service under test --
 * claiming the seller/currency {@see LedgerAccountLock} directly, then
 * calling {@see ReserveConsumptionService::consume()} (its own documented
 * precondition: run from INSIDE the caller's already-claimed lock and open
 * transaction, exactly what `ChargebackService::postAttributedLines()` does
 * in production) and/or posting a ledger entry directly -- held open
 * (uncommitted) via an explicit `getTransactionManager()->begin()`, rather
 * than standing up the full order/seller-order/chargeback-event
 * infrastructure `ChargebackService::ingest()` would otherwise require.
 * Connection B is always launched as a genuinely separate subprocess
 * (`fixtures/reserve_chargeback_race_child.php`) running a REAL top-level
 * service entry point end to end (`PayoutService::record()` or
 * `ReserveService::releaseDue()`), which blocks on A's held claim until A
 * commits or rolls back.
 */
final class ReserveChargebackPgsqlTest extends CommerceTestCase
{
    // =====================================================================
    // 1. Chargeback vs payout reservation under the shared seller/currency
    //    lock (design spec §2.5/§2.6/§2.7): connection A holds the seller
    //    account lock mid-chargeback -- an existing reserve is consumed
    //    (reserve-first, §2.5) and the chargeback_debit is posted in FULL,
    //    driving the seller into debt -- uncommitted. Connection B
    //    (subprocess) attempts a manual payout reservation that would have
    //    been fine against the PRE-chargeback balance but must be refused
    //    once A's debit lands: no double-spend, no over-release of the
    //    reserve, debt/available consistent once A commits.
    // =====================================================================

    public function testChargebackReserveConsumptionAndDebitUnderTheSellerLockRefusesAConcurrentOverdrawingPayoutOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'pg5cbpotn01';
        $seller = 'pg5cbposl01';
        $this->cleanupTenant($connectionA, $tenant);

        // available = 2000 (sale_credit) - 500 (reserve_hold) = 1500; reserved = 500.
        $this->seedAvailable($contextA, $tenant, $seller, 2000);
        $reserveUuid = 'pg5cbpors01';
        $this->seedReserve($connectionA, $contextA, $tenant, $seller, $reserveUuid, 500);

        $accountKey = LedgerRepository::accountKeyForSeller($seller);
        $chargebackUuid = 'pg5cbpocb01';

        // Connection A: manually replicates ChargebackService::postAttributedLines()'s
        // mid-posting critical section -- claim the seller account lock, consume the
        // existing reserve FIRST against the full net liability (reserve-first, design
        // spec §2.5 -- capped at the reserve's own remaining, 500, even though the
        // liability itself is larger), THEN post the chargeback_debit in FULL,
        // uncapped by whatever the reserve covered (design spec §2.6) -- held open.
        $connectionA->getTransactionManager()->begin();
        (new LedgerAccountLock())->claim($contextA, $tenant, $accountKey, 'USD');
        $consumed = (new ReserveConsumptionService(new ReserveRepository(), new LedgerRepository()))->consume(
            $contextA,
            $tenant,
            $seller,
            'USD',
            2200,
            'chargeback',
            $chargebackUuid
        );
        self::assertSame(500, $consumed, 'sanity: consumption is capped at the reserve\'s own remaining, not the liability.');
        (new LedgerRepository())->post($contextA, $tenant, [
            'account_kind' => 'seller',
            'account_key' => $accountKey,
            'seller_uuid' => $seller,
            'currency' => 'USD',
            'entry_type' => 'chargeback_debit',
            'amount' => -2200,
            'chargeback_uuid' => $chargebackUuid,
            'idempotency_key' => "{$chargebackUuid}:{$seller}:chargeback_debit",
        ]);

        // Connection B (subprocess): a REAL PayoutService::record() attempt for an
        // amount that is fine against the PRE-chargeback balance (1500) but must be
        // refused once A's debit commits and drives the seller into debt -- blocks
        // entirely on A's held account-lock claim.
        $handle = $this->launchRaceChild($pgConfig, 'payoutRecord', [
            'tenant' => $tenant,
            'sellerUuid' => $seller,
            'currency' => 'USD',
            'amount' => 1000,
            'idempotencyKey' => 'idem-pg5-cbpo-1',
            'externalRef' => 'ext-pg5-cbpo-1',
            'actorUuid' => 'pg5operat001',
        ]);

        usleep(300_000);
        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse(
            $result['ok'] ?? true,
            'a concurrent payout must be refused once the chargeback drives the seller into debt: '
                . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame(PayoutException::class, $result['exceptionClass'] ?? null);
        self::assertStringContainsString('outstanding debt', (string) ($result['message'] ?? ''));

        // No double-spend: zero payout rows landed.
        self::assertSame(0, $connectionA->table('commerce_payouts')->where('tenant_uuid', '=', $tenant)->count());

        // No over-release of reserve: the reserve's derived remaining is exactly 0
        // (consumed in full, never negative, never double-counted), and exactly ONE
        // reserve_release row exists for it.
        self::assertSame(0, (new LedgerRepository())->remainingForReserve($contextA, $tenant, $reserveUuid));
        self::assertSame(
            1,
            $connectionA->table('commerce_marketplace_ledger')
                ->where('reserve_uuid', '=', $reserveUuid)
                ->where('entry_type', '=', 'reserve_release')
                ->count(),
            'exactly ONE reserve_release landed for this reserve -- no double-release.'
        );

        // debt/available consistent once A commits: 2000 - 500(hold) + 500(release) -
        // 2200(debit) = -200.
        $balance = (new SellerBalanceService(new LedgerRepository()))->balance($contextA, $tenant, $seller, 'USD');
        self::assertSame(-200, $balance['available']);
        self::assertSame(200, $balance['debt']);
        self::assertSame(0, $balance['reserved'], 'reserve fully consumed -- reserved must be exactly zero, never negative.');

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 2. Concurrent reserve-release sweep vs chargeback consumption of the
    //    SAME held reserve (design spec §2.3/§2.5): connection A holds the
    //    seller account lock mid-consumption -- a chargeback consumes the
    //    reserve's FULL remaining, marking it `consumed` -- uncommitted.
    //    Connection B (subprocess) runs the REAL scheduled release-sweep
    //    entry point for that SAME reserve: exactly ONE wins, the reserve
    //    is never double-released, and `reserved` stays >= 0.
    // =====================================================================

    public function testConcurrentReserveConsumptionAndReleaseSweepOnTheSameHeldReserveNeverDoubleReleaseOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'pg5swptn001';
        $seller = 'pg5swpsl001';
        $this->cleanupTenant($connectionA, $tenant);

        $this->seedAvailable($contextA, $tenant, $seller, 1000);
        $reserveUuid = 'pg5swprs001';
        $this->seedReserve($connectionA, $contextA, $tenant, $seller, $reserveUuid, 1000);

        $accountKey = LedgerRepository::accountKeyForSeller($seller);
        $chargebackUuid = 'pg5swpcb001';

        // Connection A: manually replicates the chargeback's mid-consumption critical
        // section -- claims the seller/currency account lock and consumes the FULL
        // reserve remaining (1000) against this liability, marking the reserve
        // `consumed` -- held open (uncommitted).
        $connectionA->getTransactionManager()->begin();
        (new LedgerAccountLock())->claim($contextA, $tenant, $accountKey, 'USD');
        $consumed = (new ReserveConsumptionService(new ReserveRepository(), new LedgerRepository()))->consume(
            $contextA,
            $tenant,
            $seller,
            'USD',
            1000,
            'chargeback',
            $chargebackUuid
        );
        self::assertSame(1000, $consumed);

        // Connection B (subprocess): the REAL ReserveService::releaseDue() for the
        // SAME reserve -- blocks entirely on A's held account-lock claim; once
        // unblocked, its own re-read under the lock observes status='consumed' (A
        // already won) and is a clean no-op -- it must NEVER post a second
        // reserve_release.
        $handle = $this->launchRaceChild($pgConfig, 'releaseDue', [
            'tenant' => $tenant,
            'reserveUuid' => $reserveUuid,
            'sellerUuid' => $seller,
            'currency' => 'USD',
        ]);

        usleep(300_000);
        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue($result['ok'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame(
            'consumed',
            $result['status'],
            "B must observe A's already-consumed state, never fabricate/override it."
        );
        self::assertSame(
            0,
            $result['releasedAmount'],
            'the second concurrent path must release NOTHING once A has already won.'
        );

        // Exactly ONE wins: exactly one reserve_release row for this reserve, and its
        // total never exceeds the original hold (1000) -- no double-release.
        $releaseRows = $connectionA->table('commerce_marketplace_ledger')
            ->where('reserve_uuid', '=', $reserveUuid)
            ->where('entry_type', '=', 'reserve_release')
            ->get();
        self::assertCount(1, $releaseRows, 'the reserve must not be double-released.');
        self::assertSame(1000, (int) $releaseRows[0]['amount']);

        self::assertSame(0, (new LedgerRepository())->remainingForReserve($contextA, $tenant, $reserveUuid));
        $reserveRow = $connectionA->table('commerce_seller_reserves')->where('uuid', '=', $reserveUuid)->first();
        self::assertNotNull($reserveRow);
        self::assertSame('consumed', $reserveRow['status']);

        $balance = (new SellerBalanceService(new LedgerRepository()))->balance($contextA, $tenant, $seller, 'USD');
        self::assertSame(0, $balance['reserved'], 'reserved must be exactly zero -- never negative from a double-release.');
        self::assertGreaterThanOrEqual(0, $balance['reserved']);

        $this->cleanupTenant($connectionA, $tenant);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane for true two-connection row-lock interleaving.');
        }
    }

    private function seedAvailable(ApplicationContext $context, string $tenant, string $sellerUuid, int $amount): void
    {
        (new LedgerRepository())->post($context, $tenant, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => $amount,
            'order_uuid' => 'seed' . substr(md5($sellerUuid), 0, 8),
            'idempotency_key' => 'seed:' . $sellerUuid . ':sale_credit',
        ]);
    }

    /** Directly seeds a `held` `manual` reserve row plus its matching `reserve_hold` posting. */
    private function seedReserve(
        Connection $connection,
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $reserveUuid,
        int $amount
    ): void {
        $connection->table('commerce_seller_reserves')->insert([
            'uuid' => $reserveUuid,
            'tenant_uuid' => $tenant,
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'source_kind' => 'manual',
            'idempotency_key' => 'seed:' . $reserveUuid,
            'amount' => $amount,
            'reserve_bps_snapshot' => 0,
            'reserve_days_snapshot' => 0,
            'held_at' => $connection->getDriver()->formatDateTime(),
        ]);

        (new LedgerRepository())->post($context, $tenant, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'entry_type' => 'reserve_hold',
            'amount' => -$amount,
            'payout_uuid' => null,
            'reserve_uuid' => $reserveUuid,
            'idempotency_key' => "seed:{$reserveUuid}:reserve_hold",
        ]);
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
                __DIR__ . '/fixtures/reserve_chargeback_race_child.php',
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

    private function cleanupTenant(Connection $connection, string $tenant): void
    {
        $connection->table('commerce_marketplace_ledger')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_ledger_account_locks')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_payouts')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_seller_reserves')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_chargeback_lines')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_chargebacks')->where('tenant_uuid', '=', $tenant)->delete();
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
        $context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);

        return $context;
    }
}
