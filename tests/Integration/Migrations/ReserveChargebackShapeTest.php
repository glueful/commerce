<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Database\Migrations\CreateChargebacksTable;
use Glueful\Extensions\Commerce\Database\Migrations\CreateMarketplaceLedgerTables;
use Glueful\Extensions\Commerce\Database\Migrations\CreateMarketplaceSellerTables;
use Glueful\Extensions\Commerce\Database\Migrations\CreateSellerReservesTable;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Freezes the MV5a reserve/chargeback schema foundation (design spec §3)
 * before any policy/reserve/chargeback service code consumes it:
 *  - the rolling-reserve policy columns folded into the still-unreleased
 *    `commerce_marketplace_settings` + `commerce_sellers` (010) -- §3.1;
 *  - the `reserve_uuid`/`chargeback_uuid` correlation columns folded into
 *    the still-unreleased `commerce_marketplace_ledger` (012) -- §3.4;
 *  - the brand-new `commerce_seller_reserves` + `commerce_reserve_policy_events`
 *    tables (015) -- §3.2;
 *  - the brand-new `commerce_chargebacks` + `commerce_chargeback_lines`
 *    tables (016) -- §3.3;
 *  - `config/commerce.php` `marketplace.reserves` (operational only, §3-config)
 *    and the four new tables' `DiagnosticsReport` registration.
 *
 * `LedgerRepository`'s 12-to-14-field replay-verify expansion is covered by
 * {@see \Glueful\Extensions\Commerce\Tests\Integration\Marketplace\LedgerRepositoryTest}
 * (behavior), not repeated here (shape only).
 *
 * Because the schema builder exposes `hasTable`/`hasColumn` but no `hasIndex`,
 * every index assertion here is made via direct SQLite driver introspection
 * (`PRAGMA index_list` / `PRAGMA index_info`), matching this codebase's
 * established convention (see {@see PayoutProviderShapeTest}).
 */
final class ReserveChargebackShapeTest extends CommerceTestCase
{
    // === commerce_marketplace_settings (folded reserve policy, §3.1) ================

    public function testMarketplaceSettingsHasFoldedReserveColumns(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        foreach (['reserve_bps', 'reserve_days'] as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_marketplace_settings', $column),
                "commerce_marketplace_settings missing folded column {$column}"
            );
        }
    }

    private function minimalSettingsRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'mktsetmv5a01',
            'tenant_uuid' => 'tenant0mv5aset',
            'status' => 'active',
        ], $overrides);
    }

    public function testMarketplaceSettingsReserveColumnsDefaultToZeroWhenOmitted(): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert(
            $this->minimalSettingsRow(['uuid' => 'mktsetmv5a02', 'tenant_uuid' => 'tenant0mv5aszd'])
        );

        $row = $this->connection->table('commerce_marketplace_settings')
            ->where('uuid', '=', 'mktsetmv5a02')
            ->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['reserve_bps']);
        self::assertSame(0, (int) $row['reserve_days']);
    }

    public function testMarketplaceSettingsReserveColumnsAcceptAssignedValues(): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert(
            $this->minimalSettingsRow([
                'uuid' => 'mktsetmv5a03',
                'tenant_uuid' => 'tenant0mv5asza',
                'reserve_bps' => 250,
                'reserve_days' => 14,
            ])
        );

        $row = $this->connection->table('commerce_marketplace_settings')
            ->where('uuid', '=', 'mktsetmv5a03')
            ->first();
        self::assertNotNull($row);
        self::assertSame(250, (int) $row['reserve_bps']);
        self::assertSame(14, (int) $row['reserve_days']);
    }

    public function testRerunning010MigrationWithFoldedReserveColumnsIsANoOp(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $migration = new CreateMarketplaceSellerTables();

        // setUp() already ran this migration once via CommerceTestCase::MIGRATIONS;
        // re-running up() must be a no-op guarded by hasTable().
        $migration->up($schema);
        $migration->up($schema);

        self::assertTrue($schema->hasTable('commerce_marketplace_settings'));
        self::assertTrue($schema->hasColumn('commerce_marketplace_settings', 'reserve_bps'));
        self::assertTrue($schema->hasColumn('commerce_sellers', 'reserve_bps'));
    }

    // === commerce_sellers (folded reserve policy override, §3.1) ====================

    public function testSellersHasFoldedReserveColumns(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        foreach (['reserve_bps', 'reserve_days'] as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_sellers', $column),
                "commerce_sellers missing folded column {$column}"
            );
        }
    }

    private function minimalSellerRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'sellermv5a001',
            'tenant_uuid' => 'tenant0mv5asel',
            'slug' => 'mv5a-reserve-seller',
            'name' => 'MV5a Reserve Seller',
        ], $overrides);
    }

    public function testSellersReserveColumnsDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_sellers')->insert(
            $this->minimalSellerRow(['uuid' => 'sellermv5a002', 'slug' => 'mv5a-reserve-seller-2'])
        );

        $row = $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellermv5a002')->first();
        self::assertNotNull($row);
        self::assertNull($row['reserve_bps']);
        self::assertNull($row['reserve_days']);
    }

    public function testSellersReserveColumnsAcceptAssignedValuesIncludingExplicitZero(): void
    {
        // A positive override.
        $this->connection->table('commerce_sellers')->insert(
            $this->minimalSellerRow([
                'uuid' => 'sellermv5a003',
                'slug' => 'mv5a-reserve-seller-3',
                'reserve_bps' => 500,
                'reserve_days' => 7,
            ])
        );
        $row = $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellermv5a003')->first();
        self::assertNotNull($row);
        self::assertSame(500, (int) $row['reserve_bps']);
        self::assertSame(7, (int) $row['reserve_days']);

        // §2.1: an explicit 0 override disables the reserve for this seller (it
        // does NOT inherit) -- distinct from a null override, which does inherit.
        $this->connection->table('commerce_sellers')->insert(
            $this->minimalSellerRow([
                'uuid' => 'sellermv5a004',
                'slug' => 'mv5a-reserve-seller-4',
                'reserve_bps' => 0,
                'reserve_days' => 0,
            ])
        );
        $disabled = $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellermv5a004')->first();
        self::assertNotNull($disabled);
        self::assertSame(0, (int) $disabled['reserve_bps']);
        self::assertSame(0, (int) $disabled['reserve_days']);
        self::assertNotNull($disabled['reserve_bps'], 'an explicit 0 must persist as 0, never coerce to null');
    }

    // === commerce_marketplace_ledger (folded correlation columns, §3.4) =============

    public function testLedgerHasFoldedReserveAndChargebackUuidColumns(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        foreach (['reserve_uuid', 'chargeback_uuid'] as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_marketplace_ledger', $column),
                "commerce_marketplace_ledger missing folded column {$column}"
            );
        }
    }

    private function minimalLedgerRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'ledgermv5a001',
            'tenant_uuid' => 'tenant0mv5aldg',
            'account_key' => 'marketplace',
            'account_kind' => 'marketplace',
            'seller_uuid' => null,
            'currency' => 'USD',
            'entry_type' => 'reserve_hold',
            'amount' => -500,
            'idempotency_key' => 'idem-mv5a-ldg-001',
        ], $overrides);
    }

    public function testLedgerReserveAndChargebackUuidDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_marketplace_ledger')->insert(
            $this->minimalLedgerRow(['uuid' => 'ledgermv5a002', 'idempotency_key' => 'idem-mv5a-ldg-002'])
        );

        $row = $this->connection->table('commerce_marketplace_ledger')->where('uuid', '=', 'ledgermv5a002')->first();
        self::assertNotNull($row);
        self::assertNull($row['reserve_uuid']);
        self::assertNull($row['chargeback_uuid']);
    }

    public function testLedgerReserveAndChargebackUuidAcceptAssignedValues(): void
    {
        $this->connection->table('commerce_marketplace_ledger')->insert(
            $this->minimalLedgerRow([
                'uuid' => 'ledgermv5a003',
                'idempotency_key' => 'idem-mv5a-ldg-003',
                'reserve_uuid' => 'reserveuuid01',
            ])
        );
        $reserveRow = $this->connection->table('commerce_marketplace_ledger')
            ->where('uuid', '=', 'ledgermv5a003')->first();
        self::assertNotNull($reserveRow);
        self::assertSame('reserveuuid01', $reserveRow['reserve_uuid']);
        self::assertNull($reserveRow['chargeback_uuid']);

        $this->connection->table('commerce_marketplace_ledger')->insert(
            $this->minimalLedgerRow([
                'uuid' => 'ledgermv5a004',
                'idempotency_key' => 'idem-mv5a-ldg-004',
                'entry_type' => 'chargeback_debit',
                'chargeback_uuid' => 'chargebckuu01',
            ])
        );
        $chargebackRow = $this->connection->table('commerce_marketplace_ledger')
            ->where('uuid', '=', 'ledgermv5a004')->first();
        self::assertNotNull($chargebackRow);
        self::assertSame('chargebckuu01', $chargebackRow['chargeback_uuid']);
        self::assertNull($chargebackRow['reserve_uuid']);
    }

    public function testLedgerHasReserveUuidIndex(): void
    {
        $this->assertIndexExists(
            'commerce_marketplace_ledger',
            'commerce_marketplace_ledger_reserve_uuid_index',
            ['reserve_uuid']
        );
    }

    public function testLedgerHasChargebackUuidIndex(): void
    {
        $this->assertIndexExists(
            'commerce_marketplace_ledger',
            'commerce_marketplace_ledger_chargeback_uuid_index',
            ['chargeback_uuid']
        );
    }

    public function testRerunning012MigrationWithFoldedCorrelationColumnsIsANoOp(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $migration = new CreateMarketplaceLedgerTables();

        // setUp() already ran this migration once via CommerceTestCase::MIGRATIONS;
        // re-running up() must be a no-op guarded by hasTable().
        $migration->up($schema);
        $migration->up($schema);

        self::assertTrue($schema->hasTable('commerce_marketplace_ledger'));
        self::assertTrue($schema->hasColumn('commerce_marketplace_ledger', 'reserve_uuid'));
        self::assertTrue($schema->hasColumn('commerce_marketplace_ledger', 'chargeback_uuid'));
    }

    // === commerce_seller_reserves (new table, §3.2) ==================================

    public function testSellerReservesTableExists(): void
    {
        self::assertTrue(
            $this->connection->getSchemaBuilder()->hasTable('commerce_seller_reserves'),
            'missing table commerce_seller_reserves'
        );
    }

    public function testSellerReservesHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'seller_uuid', 'currency', 'source_kind',
            'seller_order_uuid', 'idempotency_key', 'amount', 'reserve_bps_snapshot',
            'reserve_days_snapshot', 'status', 'held_at', 'release_at', 'closed_at',
            'created_by', 'reason', 'created_at', 'updated_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_reserves', $column),
                "commerce_seller_reserves missing column {$column}"
            );
        }
    }

    private function minimalRollingReserveRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'resrvmv5a0001',
            'tenant_uuid' => 'tenant0mv5arsv',
            'seller_uuid' => 'sellerrsv0001',
            'currency' => 'USD',
            'source_kind' => 'rolling',
            'seller_order_uuid' => 'selordrsv0001',
            'amount' => 1000,
            'reserve_bps_snapshot' => 250,
            'reserve_days_snapshot' => 14,
            'held_at' => '2026-07-17 12:00:00',
        ], $overrides);
    }

    public function testSellerReservesTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_reserves')->insert(
            array_diff_key(
                $this->minimalRollingReserveRow(['uuid' => 'resrvmv5a0002', 'seller_order_uuid' => 'selordrsv0002']),
                ['tenant_uuid' => true]
            )
        );

        $row = $this->connection->table('commerce_seller_reserves')->where('uuid', '=', 'resrvmv5a0002')->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testSellerReservesStatusDefaultsToHeldWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_reserves')->insert(
            $this->minimalRollingReserveRow(['uuid' => 'resrvmv5a0003', 'seller_order_uuid' => 'selordrsv0003'])
        );

        $row = $this->connection->table('commerce_seller_reserves')->where('uuid', '=', 'resrvmv5a0003')->first();
        self::assertNotNull($row);
        self::assertSame('held', $row['status']);
        self::assertNull($row['release_at']);
        self::assertNull($row['closed_at']);
        self::assertNull($row['created_by']);
        self::assertNull($row['reason']);
        self::assertNull($row['idempotency_key']);
    }

    public function testSellerReservesAcceptsAssignedManualHoldValues(): void
    {
        $this->connection->table('commerce_seller_reserves')->insert(
            $this->minimalRollingReserveRow([
                'uuid' => 'resrvmv5a0004',
                'source_kind' => 'manual',
                'seller_order_uuid' => null,
                'idempotency_key' => 'manual-hold-idem-001',
                'reserve_bps_snapshot' => 0,
                'reserve_days_snapshot' => 0,
                'release_at' => null,
                'created_by' => 'operatormv5a1',
                'reason' => 'Fraud investigation hold.',
            ])
        );

        $row = $this->connection->table('commerce_seller_reserves')->where('uuid', '=', 'resrvmv5a0004')->first();
        self::assertNotNull($row);
        self::assertSame('manual', $row['source_kind']);
        self::assertNull($row['seller_order_uuid']);
        self::assertSame('manual-hold-idem-001', $row['idempotency_key']);
        self::assertSame(0, (int) $row['reserve_bps_snapshot']);
        self::assertSame(0, (int) $row['reserve_days_snapshot']);
        self::assertNull($row['release_at']);
        self::assertSame('operatormv5a1', $row['created_by']);
        self::assertSame('Fraud investigation hold.', $row['reason']);
    }

    public function testSellerReservesUniqueOrderSellerIsEnforcedButManualNullRowsAreExempt(): void
    {
        $this->connection->table('commerce_seller_reserves')->insert(
            $this->minimalRollingReserveRow(['uuid' => 'resrvmv5a0010', 'seller_order_uuid' => 'selordrsv0010'])
        );

        try {
            $this->connection->table('commerce_seller_reserves')->insert(
                $this->minimalRollingReserveRow(['uuid' => 'resrvmv5a0011', 'seller_order_uuid' => 'selordrsv0010'])
            );
            self::fail('duplicate (tenant_uuid, seller_order_uuid, seller_uuid) rolling reserve must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        // Two MANUAL holds (seller_order_uuid null) for the same seller must coexist --
        // the nullable column keeps them exempt from this constraint on every driver.
        $this->connection->table('commerce_seller_reserves')->insert(
            $this->minimalRollingReserveRow([
                'uuid' => 'resrvmv5a0012',
                'source_kind' => 'manual',
                'seller_order_uuid' => null,
                'idempotency_key' => 'manual-hold-idem-a',
            ])
        );
        $this->connection->table('commerce_seller_reserves')->insert(
            $this->minimalRollingReserveRow([
                'uuid' => 'resrvmv5a0013',
                'source_kind' => 'manual',
                'seller_order_uuid' => null,
                'idempotency_key' => 'manual-hold-idem-b',
            ])
        );
        self::assertSame(
            2,
            $this->connection->table('commerce_seller_reserves')
                ->where('tenant_uuid', '=', 'tenant0mv5arsv')
                ->where('source_kind', '=', 'manual')
                ->count()
        );
    }

    public function testSellerReservesUniqueIdempotencyKeyRejectsDuplicateNonNullButAllowsMultipleNulls(): void
    {
        // Two ROLLING rows (idempotency_key null) must coexist -- null-exempt
        // uniqueness (the MV4 008/payvia null-exempt-unique idiom, same as
        // SQLite/PostgreSQL treating each NULL as distinct).
        $this->connection->table('commerce_seller_reserves')->insert(
            $this->minimalRollingReserveRow(['uuid' => 'resrvmv5a0020', 'seller_order_uuid' => 'selordrsv0020'])
        );
        $this->connection->table('commerce_seller_reserves')->insert(
            $this->minimalRollingReserveRow(['uuid' => 'resrvmv5a0021', 'seller_order_uuid' => 'selordrsv0021'])
        );
        self::assertSame(
            2,
            $this->connection->table('commerce_seller_reserves')
                ->where('tenant_uuid', '=', 'tenant0mv5arsv')
                ->where('idempotency_key', '=', null)
                ->count()
        );

        // Two MANUAL rows with the SAME non-null idempotency_key must be rejected.
        $this->connection->table('commerce_seller_reserves')->insert(
            $this->minimalRollingReserveRow([
                'uuid' => 'resrvmv5a0022',
                'source_kind' => 'manual',
                'seller_order_uuid' => null,
                'idempotency_key' => 'manual-hold-idem-dup',
            ])
        );
        try {
            $this->connection->table('commerce_seller_reserves')->insert(
                $this->minimalRollingReserveRow([
                    'uuid' => 'resrvmv5a0023',
                    'source_kind' => 'manual',
                    'seller_order_uuid' => null,
                    'idempotency_key' => 'manual-hold-idem-dup',
                ])
            );
            self::fail('duplicate (tenant_uuid, idempotency_key) manual reserve insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testSellerReservesUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_seller_reserves')->insert(
            $this->minimalRollingReserveRow(['uuid' => 'resrvmv5a0030', 'seller_order_uuid' => 'selordrsv0030'])
        );

        try {
            $this->connection->table('commerce_seller_reserves')->insert(
                $this->minimalRollingReserveRow([
                    'uuid' => 'resrvmv5a0030',
                    'seller_order_uuid' => 'selordrsv0031',
                ])
            );
            self::fail('duplicate (tenant_uuid, uuid) reserve insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testSellerReservesHasStatusReleaseIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_reserves',
            'commerce_seller_reserves_status_release_index',
            ['tenant_uuid', 'status', 'release_at']
        );
    }

    public function testSellerReservesHasFifoIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_reserves',
            'commerce_seller_reserves_fifo_index',
            ['tenant_uuid', 'seller_uuid', 'currency', 'status', 'release_at']
        );
    }

    public function testRerunning015MigrationIsANoOp(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $migration = new CreateSellerReservesTable();

        $migration->up($schema);
        $migration->up($schema);

        self::assertTrue($schema->hasTable('commerce_seller_reserves'));
        self::assertTrue($schema->hasTable('commerce_reserve_policy_events'));
    }

    // === commerce_reserve_policy_events (new table, §3.2) ============================

    public function testReservePolicyEventsTableExists(): void
    {
        self::assertTrue(
            $this->connection->getSchemaBuilder()->hasTable('commerce_reserve_policy_events'),
            'missing table commerce_reserve_policy_events'
        );
    }

    public function testReservePolicyEventsHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'subject_kind', 'subject_uuid', 'actor_uuid',
            'before_policy', 'after_policy', 'created_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_reserve_policy_events', $column),
                "commerce_reserve_policy_events missing column {$column}"
            );
        }
    }

    private function minimalReservePolicyEventRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'rpevent0mv001',
            'tenant_uuid' => 'tenant0mv5aevt',
            'subject_kind' => 'workspace',
            'subject_uuid' => 'wkspcevt00001',
            'actor_uuid' => 'operatorevt02',
            'before_policy' => json_encode(['reserve_bps' => 0, 'reserve_days' => 0]),
            'after_policy' => json_encode(['reserve_bps' => 250, 'reserve_days' => 14]),
        ], $overrides);
    }

    public function testReservePolicyEventsTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_reserve_policy_events')->insert(
            array_diff_key(
                $this->minimalReservePolicyEventRow(['uuid' => 'rpevent0mv002']),
                ['tenant_uuid' => true]
            )
        );

        $row = $this->connection->table('commerce_reserve_policy_events')
            ->where('uuid', '=', 'rpevent0mv002')
            ->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testReservePolicyEventsUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_reserve_policy_events')->insert(
            $this->minimalReservePolicyEventRow(['uuid' => 'rpevent0mv010'])
        );

        try {
            $this->connection->table('commerce_reserve_policy_events')->insert(
                $this->minimalReservePolicyEventRow(['uuid' => 'rpevent0mv010'])
            );
            self::fail('duplicate (tenant_uuid, uuid) reserve-policy-event insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testReservePolicyEventsHasSubjectCreatedIndex(): void
    {
        $this->assertIndexExists(
            'commerce_reserve_policy_events',
            'commerce_reserve_events_subject_created_index',
            ['tenant_uuid', 'subject_kind', 'subject_uuid', 'created_at']
        );
    }

    // === commerce_chargebacks (new table, §3.3) ======================================

    public function testChargebacksTableExists(): void
    {
        self::assertTrue(
            $this->connection->getSchemaBuilder()->hasTable('commerce_chargebacks'),
            'missing table commerce_chargebacks'
        );
    }

    public function testChargebacksHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'provider', 'provider_event_id', 'payment_reference',
            'order_uuid', 'amount', 'currency', 'reason_code', 'occurred_at', 'kind',
            'related_chargeback_uuid', 'status', 'posted_at', 'created_at', 'updated_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_chargebacks', $column),
                "commerce_chargebacks missing column {$column}"
            );
        }
    }

    private function minimalChargebackRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'chbackmv5a001',
            'tenant_uuid' => 'tenant0mv5acb',
            'provider' => 'payvia',
            'provider_event_id' => 'evt_mv5a_001',
            'payment_reference' => 'pay_mv5a_001',
            'amount' => 2500,
            'currency' => 'USD',
            'occurred_at' => '2026-07-17 12:00:00',
        ], $overrides);
    }

    public function testChargebacksTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_chargebacks')->insert(
            array_diff_key(
                $this->minimalChargebackRow(['uuid' => 'chbackmv5a002', 'provider_event_id' => 'evt_mv5a_002']),
                ['tenant_uuid' => true]
            )
        );

        $row = $this->connection->table('commerce_chargebacks')->where('uuid', '=', 'chbackmv5a002')->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testChargebacksKindAndStatusDefaultCorrectlyWhenOmitted(): void
    {
        $this->connection->table('commerce_chargebacks')->insert(
            $this->minimalChargebackRow(['uuid' => 'chbackmv5a003', 'provider_event_id' => 'evt_mv5a_003'])
        );

        $row = $this->connection->table('commerce_chargebacks')->where('uuid', '=', 'chbackmv5a003')->first();
        self::assertNotNull($row);
        self::assertSame('chargeback', $row['kind']);
        self::assertSame('received', $row['status']);
        self::assertNull($row['order_uuid']);
        self::assertNull($row['reason_code']);
        self::assertNull($row['related_chargeback_uuid']);
        self::assertNull($row['posted_at']);
    }

    public function testChargebacksAcceptsAssignedReversalKindAndRelatedChargebackUuid(): void
    {
        $this->connection->table('commerce_chargebacks')->insert(
            $this->minimalChargebackRow(['uuid' => 'chbackmv5a004', 'provider_event_id' => 'evt_mv5a_004'])
        );

        $this->connection->table('commerce_chargebacks')->insert(
            $this->minimalChargebackRow([
                'uuid' => 'chbackmv5a005',
                'provider_event_id' => 'evt_mv5a_005',
                'kind' => 'reversal',
                'related_chargeback_uuid' => 'chbackmv5a004',
                'status' => 'posted',
                'posted_at' => '2026-07-18 09:00:00',
                'order_uuid' => 'ordermv5a0001',
                'reason_code' => 'duplicate',
            ])
        );

        $row = $this->connection->table('commerce_chargebacks')->where('uuid', '=', 'chbackmv5a005')->first();
        self::assertNotNull($row);
        self::assertSame('reversal', $row['kind']);
        self::assertSame('chbackmv5a004', $row['related_chargeback_uuid']);
        self::assertSame('posted', $row['status']);
        self::assertNotNull($row['posted_at']);
        self::assertSame('ordermv5a0001', $row['order_uuid']);
        self::assertSame('duplicate', $row['reason_code']);
    }

    public function testChargebacksUniqueProviderEventIsEnforced(): void
    {
        $this->connection->table('commerce_chargebacks')->insert(
            $this->minimalChargebackRow(['uuid' => 'chbackmv5a010', 'provider_event_id' => 'evt_mv5a_dup'])
        );

        try {
            $this->connection->table('commerce_chargebacks')->insert(
                $this->minimalChargebackRow(['uuid' => 'chbackmv5a011', 'provider_event_id' => 'evt_mv5a_dup'])
            );
            self::fail('duplicate (tenant_uuid, provider, provider_event_id) insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        // A different provider for the SAME provider_event_id must succeed
        // (independent idempotency space per provider).
        $this->connection->table('commerce_chargebacks')->insert(
            $this->minimalChargebackRow([
                'uuid' => 'chbackmv5a012',
                'provider' => 'other-provider',
                'provider_event_id' => 'evt_mv5a_dup',
            ])
        );
        self::assertSame(
            2,
            $this->connection->table('commerce_chargebacks')
                ->where('tenant_uuid', '=', 'tenant0mv5acb')
                ->where('provider_event_id', '=', 'evt_mv5a_dup')
                ->count()
        );
    }

    public function testChargebacksHasOrderIndex(): void
    {
        $this->assertIndexExists(
            'commerce_chargebacks',
            'commerce_chargebacks_tenant_order_index',
            ['tenant_uuid', 'order_uuid']
        );
    }

    public function testChargebacksHasStatusIndex(): void
    {
        $this->assertIndexExists(
            'commerce_chargebacks',
            'commerce_chargebacks_tenant_status_index',
            ['tenant_uuid', 'status']
        );
    }

    public function testRerunning016MigrationIsANoOp(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $migration = new CreateChargebacksTable();

        $migration->up($schema);
        $migration->up($schema);

        self::assertTrue($schema->hasTable('commerce_chargebacks'));
        self::assertTrue($schema->hasTable('commerce_chargeback_lines'));
    }

    // === commerce_chargeback_lines (new table, §3.3) =================================

    public function testChargebackLinesTableExists(): void
    {
        self::assertTrue(
            $this->connection->getSchemaBuilder()->hasTable('commerce_chargeback_lines'),
            'missing table commerce_chargeback_lines'
        );
    }

    public function testChargebackLinesHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'chargeback_uuid', 'order_line_uuid', 'seller_uuid',
            'amount', 'created_at', 'updated_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_chargeback_lines', $column),
                "commerce_chargeback_lines missing column {$column}"
            );
        }
    }

    private function minimalChargebackLineRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'cblinemv5a001',
            'tenant_uuid' => 'tenant0mv5acbl',
            'chargeback_uuid' => 'chbackmv5a001',
            'order_line_uuid' => 'olinemv5a0001',
            'seller_uuid' => 'sellerlnmv5a1',
            'amount' => 1000,
        ], $overrides);
    }

    public function testChargebackLinesTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_chargeback_lines')->insert(
            array_diff_key(
                $this->minimalChargebackLineRow(['uuid' => 'cblinemv5a002']),
                ['tenant_uuid' => true]
            )
        );

        $row = $this->connection->table('commerce_chargeback_lines')->where('uuid', '=', 'cblinemv5a002')->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testChargebackLinesUniqueChargebackOrderLineIsEnforced(): void
    {
        $this->connection->table('commerce_chargeback_lines')->insert(
            $this->minimalChargebackLineRow(['uuid' => 'cblinemv5a010'])
        );

        try {
            $this->connection->table('commerce_chargeback_lines')->insert(
                $this->minimalChargebackLineRow(['uuid' => 'cblinemv5a011'])
            );
            self::fail('duplicate (tenant_uuid, chargeback_uuid, order_line_uuid) insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        // A different order line for the SAME chargeback must succeed (a second
        // attribution row for the same event).
        $this->connection->table('commerce_chargeback_lines')->insert(
            $this->minimalChargebackLineRow(['uuid' => 'cblinemv5a012', 'order_line_uuid' => 'olinemv5a0002'])
        );
        self::assertSame(
            2,
            $this->connection->table('commerce_chargeback_lines')
                ->where('tenant_uuid', '=', 'tenant0mv5acbl')
                ->where('chargeback_uuid', '=', 'chbackmv5a001')
                ->count()
        );
    }

    public function testChargebackLinesUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_chargeback_lines')->insert(
            $this->minimalChargebackLineRow(['uuid' => 'cblinemv5a020'])
        );

        try {
            $this->connection->table('commerce_chargeback_lines')->insert(
                $this->minimalChargebackLineRow(['uuid' => 'cblinemv5a020', 'order_line_uuid' => 'olinemv5a0099'])
            );
            self::fail('duplicate (tenant_uuid, uuid) chargeback-line insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testChargebackLinesHasSellerIndex(): void
    {
        $this->assertIndexExists(
            'commerce_chargeback_lines',
            'commerce_chargeback_lines_tenant_seller_index',
            ['tenant_uuid', 'seller_uuid']
        );
    }

    // === config/commerce.php marketplace.reserves (§3-config) ========================

    public function testConfigMarketplaceReservesHasOperationalDefaultsOnly(): void
    {
        $reserves = config($this->appContext(), 'commerce.marketplace.reserves');

        self::assertIsArray($reserves);
        self::assertArrayHasKey('release_sweep_batch_size', $reserves);
        self::assertIsInt($reserves['release_sweep_batch_size']);
        self::assertGreaterThan(0, $reserves['release_sweep_batch_size']);

        // §3-config: no policy defaults here -- `commerce_marketplace_settings`
        // (default 0) is the sole authoritative policy source.
        self::assertArrayNotHasKey('reserve_bps', $reserves);
        self::assertArrayNotHasKey('reserve_days', $reserves);
    }

    // === DiagnosticsReport (§3.2/§3.3) ================================================

    private const MV5A_TABLES = [
        'commerce_seller_reserves',
        'commerce_reserve_policy_events',
        'commerce_chargebacks',
        'commerce_chargeback_lines',
    ];

    public function testDiagnosticsCommerceTablesIncludesAllFourMv5aTables(): void
    {
        $tables = DiagnosticsReport::commerceTables();
        foreach (self::MV5A_TABLES as $table) {
            self::assertContains($table, $tables, "DiagnosticsReport::commerceTables() missing {$table}");
        }
    }

    public function testDiagnosticsTenantTablesIncludesAllFourMv5aTables(): void
    {
        $tables = DiagnosticsReport::tenantTables();
        foreach (self::MV5A_TABLES as $table) {
            self::assertContains($table, $tables, "DiagnosticsReport::tenantTables() missing {$table}");
        }
    }

    public function testDiagnosticsReportBuildShowsAllFourMv5aTablesPresent(): void
    {
        $report = DiagnosticsReport::build($this->appContext());

        foreach (self::MV5A_TABLES as $table) {
            self::assertTrue(
                $report['database']['commerce_tables_present'][$table] ?? false,
                "DiagnosticsReport::build() must report {$table} as present"
            );
        }
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

    // =====================================================================
    // Real-PostgreSQL convergence lanes (design spec §3/§10): the SQLite
    // tests above prove the SHAPE; these prove the SAME migrations (010/012's
    // folded MV5a columns, 015/016's brand-new tables) converge on a
    // genuinely different engine -- every index via `pg_indexes` (the schema
    // builder exposes no `hasIndex`, and index names are generated
    // identically across drivers -- {@see \Glueful\Database\Schema\TableBuilder::generateIndexName()}
    // in the framework -- so the SAME pinned names from the SQLite tests
    // above apply here unchanged), the null-exempt unique behavior on a
    // second engine, the amount CHECK constraint, and re-running every
    // folded/new migration is a no-op. Gating, fixture-width discipline, and
    // the throwaway `Connection`/`ApplicationContext` construction all
    // mirror `PayoutProviderShapeTest`'s own pgsql lanes exactly.
    // =====================================================================

    public function testFreshInstallConvergesOnRealPostgresWithFoldedColumnsAndAllFourMv5aTables(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();

        foreach (['reserve_bps', 'reserve_days'] as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_marketplace_settings', $column),
                "commerce_marketplace_settings missing folded column {$column} on PostgreSQL"
            );
            self::assertTrue(
                $schema->hasColumn('commerce_sellers', $column),
                "commerce_sellers missing folded column {$column} on PostgreSQL"
            );
        }
        foreach (['reserve_uuid', 'chargeback_uuid'] as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_marketplace_ledger', $column),
                "commerce_marketplace_ledger missing folded column {$column} on PostgreSQL"
            );
        }

        foreach (
            [
                'commerce_seller_reserves',
                'commerce_reserve_policy_events',
                'commerce_chargebacks',
                'commerce_chargeback_lines',
            ] as $table
        ) {
            self::assertTrue($schema->hasTable($table), "missing {$table} on PostgreSQL");
        }
    }

    public function testMv5aIndexesExistOnRealPostgresViaPgIndexes(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());

        $this->assertPgIndexExists(
            $connection,
            'commerce_marketplace_ledger',
            'commerce_marketplace_ledger_reserve_uuid_index',
            ['reserve_uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_marketplace_ledger',
            'commerce_marketplace_ledger_chargeback_uuid_index',
            ['chargeback_uuid']
        );

        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_reserves',
            'commerce_seller_reserves_order_seller_unique',
            ['tenant_uuid', 'seller_order_uuid', 'seller_uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_reserves',
            'commerce_seller_reserves_idempotency_unique',
            ['tenant_uuid', 'idempotency_key']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_reserves',
            'commerce_seller_reserves_tenant_uuid_unique',
            ['tenant_uuid', 'uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_reserves',
            'commerce_seller_reserves_status_release_index',
            ['tenant_uuid', 'status', 'release_at']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_reserves',
            'commerce_seller_reserves_fifo_index',
            ['tenant_uuid', 'seller_uuid', 'currency', 'status', 'release_at']
        );

        $this->assertPgIndexExists(
            $connection,
            'commerce_reserve_policy_events',
            'commerce_reserve_policy_events_tenant_uuid_unique',
            ['tenant_uuid', 'uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_reserve_policy_events',
            'commerce_reserve_events_subject_created_index',
            ['tenant_uuid', 'subject_kind', 'subject_uuid', 'created_at']
        );

        $this->assertPgIndexExists(
            $connection,
            'commerce_chargebacks',
            'commerce_chargebacks_provider_event_unique',
            ['tenant_uuid', 'provider', 'provider_event_id']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_chargebacks',
            'commerce_chargebacks_tenant_order_index',
            ['tenant_uuid', 'order_uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_chargebacks',
            'commerce_chargebacks_tenant_status_index',
            ['tenant_uuid', 'status']
        );

        $this->assertPgIndexExists(
            $connection,
            'commerce_chargeback_lines',
            'commerce_chargeback_lines_chargeback_order_line_unique',
            ['tenant_uuid', 'chargeback_uuid', 'order_line_uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_chargeback_lines',
            'commerce_chargeback_lines_tenant_uuid_unique',
            ['tenant_uuid', 'uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_chargeback_lines',
            'commerce_chargeback_lines_tenant_seller_index',
            ['tenant_uuid', 'seller_uuid']
        );
    }

    public function testSellerReservesAmountCheckConstraintIsEnforcedOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());

        foreach ([0, -100] as $amount) {
            try {
                $connection->table('commerce_seller_reserves')->insert([
                    'uuid' => 'pgckrsv' . abs($amount),
                    'tenant_uuid' => 'tntpgckrsv01',
                    'seller_uuid' => 'pgckrsvslr01',
                    'currency' => 'USD',
                    'source_kind' => 'manual',
                    'idempotency_key' => 'idem-pg-ckrsv-' . $amount,
                    'amount' => $amount,
                    'reserve_bps_snapshot' => 0,
                    'reserve_days_snapshot' => 0,
                    'held_at' => '2026-07-17 12:00:00',
                ]);
                self::fail("a reserve amount of {$amount} must violate the CHECK constraint on PostgreSQL");
            } catch (\Throwable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSellerReservesNullExemptIdempotencyUniqueBehavesTheSameOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        // Self-healing cleanup: real commits against the persistent PostgreSQL
        // test database, unlike every SQLite test in this file.
        $connection->table('commerce_seller_reserves')->where('tenant_uuid', '=', 'tntpgckrsv02')->delete();

        $connection->table('commerce_seller_reserves')->insert([
            'uuid' => 'pgckrsvnul01',
            'tenant_uuid' => 'tntpgckrsv02',
            'seller_uuid' => 'pgckrsvslr02',
            'currency' => 'USD',
            'source_kind' => 'rolling',
            'seller_order_uuid' => 'pgckrsvord01',
            'amount' => 1000,
            'reserve_bps_snapshot' => 250,
            'reserve_days_snapshot' => 14,
            'held_at' => '2026-07-17 12:00:00',
        ]);
        // A second rolling row (idempotency_key null, distinct seller_order_uuid)
        // must succeed -- NULLs are never duplicates on PostgreSQL either.
        $connection->table('commerce_seller_reserves')->insert([
            'uuid' => 'pgckrsvnul02',
            'tenant_uuid' => 'tntpgckrsv02',
            'seller_uuid' => 'pgckrsvslr02',
            'currency' => 'USD',
            'source_kind' => 'rolling',
            'seller_order_uuid' => 'pgckrsvord02',
            'amount' => 500,
            'reserve_bps_snapshot' => 250,
            'reserve_days_snapshot' => 14,
            'held_at' => '2026-07-17 12:00:00',
        ]);

        $connection->table('commerce_seller_reserves')->insert([
            'uuid' => 'pgckrsvnul03',
            'tenant_uuid' => 'tntpgckrsv02',
            'seller_uuid' => 'pgckrsvslr02',
            'currency' => 'USD',
            'source_kind' => 'manual',
            'idempotency_key' => 'idem-pg-ckrsv-dup',
            'amount' => 200,
            'reserve_bps_snapshot' => 0,
            'reserve_days_snapshot' => 0,
            'held_at' => '2026-07-17 12:00:00',
        ]);
        try {
            $connection->table('commerce_seller_reserves')->insert([
                'uuid' => 'pgckrsvnul04',
                'tenant_uuid' => 'tntpgckrsv02',
                'seller_uuid' => 'pgckrsvslr02',
                'currency' => 'USD',
                'source_kind' => 'manual',
                'idempotency_key' => 'idem-pg-ckrsv-dup',
                'amount' => 300,
                'reserve_bps_snapshot' => 0,
                'reserve_days_snapshot' => 0,
                'held_at' => '2026-07-17 12:00:00',
            ]);
            self::fail('duplicate (tenant_uuid, idempotency_key) manual reserve insert must be rejected on PostgreSQL');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        $connection->table('commerce_seller_reserves')->where('tenant_uuid', '=', 'tntpgckrsv02')->delete();
    }

    public function testRerunning010012015016MigrationsAreNoOpsOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();

        // migratedConnection() already ran every migration (including
        // 010/012/015/016) once; re-running up() again must be a no-op
        // guarded by hasTable()/hasColumn() checks inside each migration.
        (new CreateMarketplaceSellerTables())->up($schema);
        (new CreateMarketplaceLedgerTables())->up($schema);
        (new CreateSellerReservesTable())->up($schema);
        (new CreateChargebacksTable())->up($schema);

        self::assertTrue($schema->hasTable('commerce_marketplace_settings'));
        self::assertTrue($schema->hasTable('commerce_marketplace_ledger'));
        self::assertTrue($schema->hasTable('commerce_seller_reserves'));
        self::assertTrue($schema->hasTable('commerce_reserve_policy_events'));
        self::assertTrue($schema->hasTable('commerce_chargebacks'));
        self::assertTrue($schema->hasTable('commerce_chargeback_lines'));
    }

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove migration convergence is portable.');
        }
    }

    /**
     * `pg_indexes.indexdef` looks like `CREATE INDEX name ON public.table
     * USING btree (col_a, col_b)` (or `CREATE UNIQUE INDEX ...` for a named
     * unique constraint) -- the column list (in order) is the content of the
     * LAST parenthesized group.
     *
     * @param list<string> $expectedColumns ordered, leading column first
     */
    private function assertPgIndexExists(
        Connection $connection,
        string $table,
        string $indexName,
        array $expectedColumns
    ): void {
        $pdo = $connection->getPDO();
        $stmt = $pdo->prepare('SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?');
        $stmt->execute([$table, $indexName]);
        $indexDef = $stmt->fetchColumn();

        self::assertIsString($indexDef, "missing index {$indexName} on {$table} (pg_indexes)");
        self::assertMatchesRegularExpression('/\(([^()]+)\)\s*$/', $indexDef, "unparseable indexdef: {$indexDef}");
        preg_match('/\(([^()]+)\)\s*$/', $indexDef, $matches);
        $actualColumns = array_map('trim', explode(',', $matches[1]));

        self::assertSame($expectedColumns, $actualColumns, "unexpected column set/order for {$indexName}");
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
}
