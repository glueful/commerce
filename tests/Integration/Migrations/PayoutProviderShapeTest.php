<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Extensions\Commerce\Database\Migrations\CreatePayoutTable;
use Glueful\Extensions\Commerce\Database\Migrations\CreateSellerPayoutAccountsTable;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Freezes the MV4 provider-payout schema foundation (design spec §3) before
 * any saga/repository/reconciliation code consumes it:
 *  - the provider-payout columns folded into the still-unreleased
 *    `commerce_payouts` (013) -- §3.1;
 *  - the brand-new `commerce_seller_payout_accounts` table (014) -- §3.2;
 *  - the two retry/reconcile sweep indexes on `commerce_payouts`;
 *  - `DiagnosticsReport` registration of the new table -- §3.2.
 *
 * `external_ref`/`created_by` continuity (now nullable) is covered by
 * {@see SettlementShapeTest::testPayoutsExternalRefAndCreatedByAreNullableAtTheSchemaLevel()};
 * this file re-asserts the same fact alongside the rest of the folded shape
 * for locality with the MV4 columns it ships with.
 *
 * Because the schema builder exposes `hasTable`/`hasColumn` but no `hasIndex`,
 * every index assertion here is made via direct SQLite driver introspection
 * (`PRAGMA index_list` / `PRAGMA index_info`), matching this codebase's
 * established convention (see {@see SettlementShapeTest}).
 */
final class PayoutProviderShapeTest extends CommerceTestCase
{
    // === commerce_payouts (folded MV4 provider columns, §3.1) =======================

    public function testPayoutsHasEveryFoldedProviderColumn(): void
    {
        $columns = [
            'status', 'method', 'provider', 'provider_ref', 'destination_ref',
            'failure_code', 'failure_reason', 'retryable', 'attempt_count',
            'last_attempt_at', 'next_attempt_at', 'next_reconcile_at',
            'reversed_total', 'updated_at', 'completed_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_payouts', $column),
                "commerce_payouts missing folded column {$column}"
            );
        }
    }

    private function minimalPayoutRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'payoutmv40001',
            'tenant_uuid' => 'tenant0mv4pay',
            'seller_uuid' => 'sellerpay0001',
            'currency' => 'USD',
            'amount' => 5000,
            'idempotency_key' => 'idem-mv4-pay-001',
        ], $overrides);
    }

    public function testPayoutsFoldedColumnsDefaultCorrectlyWhenOmitted(): void
    {
        $this->connection->table('commerce_payouts')->insert($this->minimalPayoutRow([
            'uuid' => 'payoutmv40002',
            'idempotency_key' => 'idem-mv4-pay-002',
        ]));

        $row = $this->connection->table('commerce_payouts')->where('uuid', '=', 'payoutmv40002')->first();
        self::assertNotNull($row);

        self::assertSame('paid', $row['status']);
        self::assertSame('manual', $row['method']);
        self::assertFalse((bool) $row['retryable']);
        self::assertSame(0, (int) $row['attempt_count']);
        self::assertSame(0, (int) $row['reversed_total']);

        foreach (
            [
                'provider', 'provider_ref', 'destination_ref', 'failure_code',
                'failure_reason', 'last_attempt_at', 'next_attempt_at',
                'next_reconcile_at', 'updated_at', 'completed_at',
            ] as $nullableColumn
        ) {
            self::assertNull($row[$nullableColumn], "commerce_payouts.{$nullableColumn} must default to null");
        }
    }

    public function testPayoutsFoldedColumnsAcceptAssignedProviderValues(): void
    {
        $this->connection->table('commerce_payouts')->insert($this->minimalPayoutRow([
            'uuid' => 'payoutmv40003',
            'idempotency_key' => 'idem-mv4-pay-003',
            'status' => 'failed',
            'method' => 'provider',
            'provider' => 'payvia',
            'provider_ref' => 'tr_abc123',
            'destination_ref' => 'acct_seller001',
            'failure_code' => 'insufficient_funds',
            'failure_reason' => 'Provider reported insufficient platform balance.',
            'retryable' => true,
            'attempt_count' => 2,
            'last_attempt_at' => '2026-07-17 12:00:00',
            'next_attempt_at' => '2026-07-17 12:05:00',
            'next_reconcile_at' => '2026-07-17 12:10:00',
            'reversed_total' => 500,
            'updated_at' => '2026-07-17 12:00:01',
            'completed_at' => null,
        ]));

        $row = $this->connection->table('commerce_payouts')->where('uuid', '=', 'payoutmv40003')->first();
        self::assertNotNull($row);

        self::assertSame('failed', $row['status']);
        self::assertSame('provider', $row['method']);
        self::assertSame('payvia', $row['provider']);
        self::assertSame('tr_abc123', $row['provider_ref']);
        self::assertSame('acct_seller001', $row['destination_ref']);
        self::assertSame('insufficient_funds', $row['failure_code']);
        self::assertSame('Provider reported insufficient platform balance.', $row['failure_reason']);
        self::assertTrue((bool) $row['retryable']);
        self::assertSame(2, (int) $row['attempt_count']);
        self::assertSame(500, (int) $row['reversed_total']);
        self::assertNotNull($row['last_attempt_at']);
        self::assertNotNull($row['next_attempt_at']);
        self::assertNotNull($row['next_reconcile_at']);
        self::assertNotNull($row['updated_at']);
        self::assertNull($row['completed_at']);
    }

    public function testPayoutsExternalRefAndCreatedByAreNullable(): void
    {
        // §3.1: a provider row is written before a provider reference is known,
        // and a scheduled batch payout has no human actor -- both nullable at
        // the SCHEMA level now (the manual `record()` service still requires
        // both, service-side -- Task 7, not this migration).
        $this->connection->table('commerce_payouts')->insert($this->minimalPayoutRow([
            'uuid' => 'payoutmv40004',
            'idempotency_key' => 'idem-mv4-pay-004',
        ]));

        $row = $this->connection->table('commerce_payouts')->where('uuid', '=', 'payoutmv40004')->first();
        self::assertNotNull($row);
        self::assertNull($row['external_ref']);
        self::assertNull($row['created_by']);
    }

    public function testPayoutsHasStatusNextAttemptSweepIndex(): void
    {
        $this->assertIndexExists(
            'commerce_payouts',
            'commerce_payouts_status_next_attempt_index',
            ['tenant_uuid', 'status', 'next_attempt_at']
        );
    }

    public function testPayoutsHasStatusNextReconcileSweepIndex(): void
    {
        $this->assertIndexExists(
            'commerce_payouts',
            'commerce_payouts_status_next_reconcile_index',
            ['tenant_uuid', 'status', 'next_reconcile_at']
        );
    }

    public function testRerunning013MigrationWithFoldedColumnsIsANoOp(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $migration = new CreatePayoutTable();

        // setUp() already ran this migration once via CommerceTestCase::MIGRATIONS;
        // re-running up() must be a no-op guarded by hasTable().
        $migration->up($schema);
        $migration->up($schema);

        self::assertTrue($schema->hasTable('commerce_payouts'));
        self::assertTrue($schema->hasColumn('commerce_payouts', 'status'));
        self::assertTrue($schema->hasColumn('commerce_payouts', 'reversed_total'));
    }

    // === commerce_seller_payout_accounts (new table, §3.2) ===========================

    public function testSellerPayoutAccountsTableExists(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        self::assertTrue(
            $schema->hasTable('commerce_seller_payout_accounts'),
            'missing table commerce_seller_payout_accounts'
        );
    }

    public function testSellerPayoutAccountsHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'seller_uuid', 'provider', 'account_ref',
            'readiness_state', 'last_synced_at', 'failure_code', 'created_at', 'updated_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_payout_accounts', $column),
                "commerce_seller_payout_accounts missing column {$column}"
            );
        }
    }

    private function minimalPayoutAccountRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'payacctmv0001',
            'tenant_uuid' => 'tenant0mv4pac',
            'seller_uuid' => 'sellerpac0001',
            'provider' => 'payvia',
            'account_ref' => 'acct_opaque001',
        ], $overrides);
    }

    public function testSellerPayoutAccountsTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_payout_accounts')->insert(
            array_diff_key(
                $this->minimalPayoutAccountRow(['uuid' => 'payacctmv0002']),
                ['tenant_uuid' => true]
            )
        );

        $row = $this->connection->table('commerce_seller_payout_accounts')
            ->where('uuid', '=', 'payacctmv0002')
            ->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testSellerPayoutAccountsReadinessStateDefaultsToPendingWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_payout_accounts')->insert(
            $this->minimalPayoutAccountRow(['uuid' => 'payacctmv0003'])
        );

        $row = $this->connection->table('commerce_seller_payout_accounts')
            ->where('uuid', '=', 'payacctmv0003')
            ->first();
        self::assertNotNull($row);
        self::assertSame('pending', $row['readiness_state']);
        self::assertNull($row['last_synced_at']);
        self::assertNull($row['failure_code']);
    }

    public function testSellerPayoutAccountsAcceptsAssignedReadinessValues(): void
    {
        $this->connection->table('commerce_seller_payout_accounts')->insert(
            $this->minimalPayoutAccountRow([
                'uuid' => 'payacctmv0004',
                'readiness_state' => 'ready',
                'last_synced_at' => '2026-07-17 12:00:00',
            ])
        );

        $row = $this->connection->table('commerce_seller_payout_accounts')
            ->where('uuid', '=', 'payacctmv0004')
            ->first();
        self::assertNotNull($row);
        self::assertSame('ready', $row['readiness_state']);
        self::assertNotNull($row['last_synced_at']);
    }

    public function testSellerPayoutAccountsUniqueTenantSellerProviderIsEnforced(): void
    {
        $this->connection->table('commerce_seller_payout_accounts')->insert(
            $this->minimalPayoutAccountRow(['uuid' => 'payacctmv0010'])
        );

        try {
            $this->connection->table('commerce_seller_payout_accounts')->insert(
                $this->minimalPayoutAccountRow(['uuid' => 'payacctmv0011'])
            );
            self::fail('duplicate (tenant_uuid, seller_uuid, provider) account insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        // A different provider for the SAME seller -- must succeed (independent account row).
        $this->connection->table('commerce_seller_payout_accounts')->insert(
            $this->minimalPayoutAccountRow(['uuid' => 'payacctmv0012', 'provider' => 'other-provider'])
        );
        self::assertSame(
            2,
            $this->connection->table('commerce_seller_payout_accounts')
                ->where('tenant_uuid', '=', 'tenant0mv4pac')
                ->where('seller_uuid', '=', 'sellerpac0001')
                ->count()
        );
    }

    public function testSellerPayoutAccountsUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_seller_payout_accounts')->insert(
            $this->minimalPayoutAccountRow(['uuid' => 'payacctmv0020'])
        );

        try {
            $this->connection->table('commerce_seller_payout_accounts')->insert(
                $this->minimalPayoutAccountRow([
                    'uuid' => 'payacctmv0020',
                    'seller_uuid' => 'sellerpac0002',
                ])
            );
            self::fail('duplicate (tenant_uuid, uuid) account insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testSellerPayoutAccountsHasTenantSellerIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_payout_accounts',
            'commerce_payout_accounts_tenant_seller_index',
            ['tenant_uuid', 'seller_uuid']
        );
    }

    public function testRerunning014MigrationIsANoOp(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $migration = new CreateSellerPayoutAccountsTable();

        // setUp() already ran this migration once via CommerceTestCase::MIGRATIONS;
        // re-running up() must be a no-op guarded by hasTable().
        $migration->up($schema);
        $migration->up($schema);

        self::assertTrue($schema->hasTable('commerce_seller_payout_accounts'));
    }

    // === config/commerce.php marketplace.payouts (§3.5-config) =======================

    public function testConfigMarketplacePayoutsHasSensibleDefaults(): void
    {
        $payouts = config($this->appContext(), 'commerce.marketplace.payouts');

        self::assertIsArray($payouts);
        self::assertSame([], $payouts['minimums']);
        self::assertSame([], $payouts['maximums']);
        self::assertSame(5, $payouts['max_attempts']);
        self::assertIsInt($payouts['pending_reconcile_interval']);
        self::assertIsInt($payouts['paid_reconcile_interval']);
        self::assertGreaterThan(0, $payouts['pending_reconcile_interval']);
        self::assertGreaterThan(0, $payouts['paid_reconcile_interval']);
        self::assertNull($payouts['default_provider']);

        self::assertIsArray($payouts['backoff']);
        self::assertIsInt($payouts['backoff']['base_seconds']);
        self::assertIsInt($payouts['backoff']['multiplier']);
        self::assertIsInt($payouts['backoff']['max_seconds']);
    }

    // === DiagnosticsReport (§3.2) ======================================================

    public function testDiagnosticsCommerceTablesIncludesSellerPayoutAccounts(): void
    {
        self::assertContains('commerce_seller_payout_accounts', DiagnosticsReport::commerceTables());
    }

    public function testDiagnosticsTenantTablesIncludesSellerPayoutAccounts(): void
    {
        self::assertContains('commerce_seller_payout_accounts', DiagnosticsReport::tenantTables());
    }

    public function testDiagnosticsReportBuildShowsSellerPayoutAccountsPresent(): void
    {
        $report = DiagnosticsReport::build($this->appContext());

        self::assertTrue(
            $report['database']['commerce_tables_present']['commerce_seller_payout_accounts'] ?? false,
            'DiagnosticsReport::build() must report commerce_seller_payout_accounts as present'
        );
    }

    /**
     * @param list<string> $expectedColumns ordered, leading column first
     */
    private function assertIndexExists(string $table, string $indexName, array $expectedColumns): void
    {
        $pdo = $this->connection->getPDO();

        $stmt = $pdo->query(sprintf("PRAGMA index_list('%s')", $table));
        self::assertNotFalse($stmt);
        $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $names = array_column($indexes, 'name');
        self::assertContains($indexName, $names, "missing index {$indexName} on {$table}");

        $infoStmt = $pdo->query(sprintf("PRAGMA index_info('%s')", $indexName));
        self::assertNotFalse($infoStmt);
        $columns = $infoStmt->fetchAll(\PDO::FETCH_ASSOC);
        $actualColumns = array_column($columns, 'name');

        self::assertSame($expectedColumns, $actualColumns, "unexpected column set/order for {$indexName}");
    }
}
