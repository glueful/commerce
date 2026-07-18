<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Database\Migrations\CreateMarketplaceLedgerTables;
use Glueful\Extensions\Commerce\Database\Migrations\CreatePayoutTable;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Freezes the MV3 commission & settlement-ledger schema foundation (design
 * spec §3) before any resolver/calculator/ledger-posting code consumes it:
 *  - folded commission columns on `commerce_products` (001), `commerce_order_lines`
 *    (004), `commerce_sellers` + `commerce_marketplace_settings` (010), and
 *    `commerce_seller_orders` (011) -- §3.1;
 *  - the three brand-new `commerce_marketplace_ledger` / `commerce_ledger_account_locks`
 *    / `commerce_commission_policy_events` tables (012) -- §3.2-§3.4;
 *  - the brand-new `commerce_payouts` table (013) -- §3.5;
 *  - `config/commerce.php` `marketplace.commission` (§3.6) and the four new
 *    tables' `DiagnosticsReport` registration (§3.7).
 *
 * Because the schema builder exposes `hasTable`/`hasColumn` but no `hasIndex`,
 * every index assertion here is made via direct SQLite driver introspection
 * (`PRAGMA index_list` / `PRAGMA index_info`), matching this codebase's
 * established convention (see {@see MarketplaceOrderShapeTest}).
 */
final class SettlementShapeTest extends CommerceTestCase
{
    // === commerce_products (folded columns) =======================================

    public function testProductCommissionColumnsAreNullableAndDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => 'prod0mv30001',
            'slug' => 'mv3-commission-fixture',
            'name' => 'MV3 Commission Fixture',
            'type' => 'physical',
            'status' => 'draft',
        ]);

        $row = $this->connection->table('commerce_products')->where('uuid', '=', 'prod0mv30001')->first();
        self::assertNotNull($row);
        self::assertNull($row['commission_kind']);
        self::assertNull($row['commission_bps']);
        self::assertNull($row['commission_fixed']);
    }

    public function testProductCommissionColumnsAcceptAssignedValues(): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => 'prod0mv30002',
            'slug' => 'mv3-commission-fixture-2',
            'name' => 'MV3 Commission Fixture 2',
            'type' => 'physical',
            'status' => 'draft',
            'commission_kind' => 'percentage',
            'commission_bps' => 500,
            'commission_fixed' => null,
        ]);

        $row = $this->connection->table('commerce_products')->where('uuid', '=', 'prod0mv30002')->first();
        self::assertNotNull($row);
        self::assertSame('percentage', $row['commission_kind']);
        self::assertSame(500, (int) $row['commission_bps']);
        self::assertNull($row['commission_fixed']);
    }

    // === commerce_order_lines (folded columns) =====================================

    public function testOrderLineCommissionColumnsDefaultCorrectlyWhenOmitted(): void
    {
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => 'oline0mv3001',
            'order_uuid' => 'order0mv3001',
            'variant_uuid' => 'var000mv3001',
            'product_name' => 'Widget',
            'sku' => 'WIDGET-MV3-1',
            'option_values' => '{}',
            'unit_price' => 1000,
            'quantity' => 1,
            'line_total' => 1000,
        ]);

        $row = $this->connection->table('commerce_order_lines')->where('uuid', '=', 'oline0mv3001')->first();
        self::assertNotNull($row);
        self::assertNull($row['commission_source']);
        self::assertNull($row['commission_kind']);
        self::assertNull($row['commission_bps']);
        self::assertNull($row['commission_fixed']);
        self::assertSame(0, (int) $row['commission_basis']);
        self::assertSame(0, (int) $row['commission_amount']);
    }

    public function testOrderLineCommissionColumnsAcceptAssignedSnapshotValues(): void
    {
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => 'oline0mv3002',
            'order_uuid' => 'order0mv3001',
            'variant_uuid' => 'var000mv3002',
            'product_name' => 'Widget',
            'sku' => 'WIDGET-MV3-2',
            'option_values' => '{}',
            'unit_price' => 1000,
            'quantity' => 2,
            'line_total' => 2000,
            'commission_source' => 'product',
            'commission_kind' => 'percentage',
            'commission_bps' => 500,
            'commission_basis' => 2000,
            'commission_amount' => 100,
        ]);

        $row = $this->connection->table('commerce_order_lines')->where('uuid', '=', 'oline0mv3002')->first();
        self::assertNotNull($row);
        self::assertSame('product', $row['commission_source']);
        self::assertSame('percentage', $row['commission_kind']);
        self::assertSame(500, (int) $row['commission_bps']);
        self::assertSame(2000, (int) $row['commission_basis']);
        self::assertSame(100, (int) $row['commission_amount']);
    }

    // === commerce_sellers (folded columns) =========================================

    public function testSellerCommissionColumnsAreNullableAndDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => 'seller0mv3001',
            'tenant_uuid' => 'tenant0mv3001',
            'slug' => 'mv3-seller-fixture',
            'name' => 'MV3 Seller Fixture',
        ]);

        $row = $this->connection->table('commerce_sellers')->where('uuid', '=', 'seller0mv3001')->first();
        self::assertNotNull($row);
        self::assertNull($row['commission_kind']);
        self::assertNull($row['commission_bps']);
        self::assertNull($row['commission_fixed']);
    }

    public function testSellerCommissionColumnsAcceptAssignedValues(): void
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => 'seller0mv3002',
            'tenant_uuid' => 'tenant0mv3001',
            'slug' => 'mv3-seller-fixture-2',
            'name' => 'MV3 Seller Fixture 2',
            'commission_kind' => 'fixed',
            'commission_fixed' => 250,
        ]);

        $row = $this->connection->table('commerce_sellers')->where('uuid', '=', 'seller0mv3002')->first();
        self::assertNotNull($row);
        self::assertSame('fixed', $row['commission_kind']);
        self::assertNull($row['commission_bps']);
        self::assertSame(250, (int) $row['commission_fixed']);
    }

    // === commerce_marketplace_settings (folded columns) ============================

    public function testSettingsCommissionColumnsAreNullableAndDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsettmv3001',
            'tenant_uuid' => 'tenant0mv3010',
            'status' => 'disabled',
        ]);

        $row = $this->connection->table('commerce_marketplace_settings')
            ->where('uuid', '=', 'mktsettmv3001')
            ->first();
        self::assertNotNull($row);
        self::assertNull($row['commission_kind']);
        self::assertNull($row['commission_bps']);
        self::assertNull($row['commission_fixed']);
    }

    public function testSettingsCommissionColumnsAcceptAssignedValues(): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsettmv3002',
            'tenant_uuid' => 'tenant0mv3011',
            'status' => 'disabled',
            'commission_kind' => 'percentage',
            'commission_bps' => 1000,
        ]);

        $row = $this->connection->table('commerce_marketplace_settings')
            ->where('uuid', '=', 'mktsettmv3002')
            ->first();
        self::assertNotNull($row);
        self::assertSame('percentage', $row['commission_kind']);
        self::assertSame(1000, (int) $row['commission_bps']);
        self::assertNull($row['commission_fixed']);
    }

    // === commerce_seller_orders (folded column) =====================================

    public function testSellerOrderCommissionAmountDefaultsToZeroWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_orders')->insert([
            'uuid' => 'selordmv30001',
            'tenant_uuid' => 'tenant0mv3020',
            'order_uuid' => 'order0mv3020',
            'seller_uuid' => 'seller0mv3020',
            'seller_name_snapshot' => 'Acme Seller',
            'partition_number' => 1,
            'seller_reference' => 'MV3-1001-1',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
            'tax_attribution_method' => 'aggregate_allocated',
        ]);

        $row = $this->connection->table('commerce_seller_orders')->where('uuid', '=', 'selordmv30001')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['commission_amount']);
    }

    public function testSellerOrderCommissionAmountAcceptsAssignedValue(): void
    {
        $this->connection->table('commerce_seller_orders')->insert([
            'uuid' => 'selordmv30002',
            'tenant_uuid' => 'tenant0mv3021',
            'order_uuid' => 'order0mv3021',
            'seller_uuid' => 'seller0mv3021',
            'seller_name_snapshot' => 'Acme Seller',
            'partition_number' => 1,
            'seller_reference' => 'MV3-1002-1',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
            'tax_attribution_method' => 'aggregate_allocated',
            'commission_amount' => 75,
        ]);

        $row = $this->connection->table('commerce_seller_orders')->where('uuid', '=', 'selordmv30002')->first();
        self::assertNotNull($row);
        self::assertSame(75, (int) $row['commission_amount']);
    }

    // === commerce_marketplace_ledger (new table, §3.2) ==============================

    public function testLedgerTableExists(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        self::assertTrue($schema->hasTable('commerce_marketplace_ledger'), 'missing table commerce_marketplace_ledger');
    }

    public function testLedgerHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'account_key', 'account_kind', 'seller_uuid',
            'currency', 'entry_type', 'amount', 'order_uuid', 'seller_order_uuid',
            'refund_uuid', 'payout_uuid', 'idempotency_key', 'reason', 'created_by',
            'created_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_marketplace_ledger', $column),
                "commerce_marketplace_ledger missing column {$column}"
            );
        }
    }

    private function minimalSellerLedgerRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'ledgerrow001',
            'tenant_uuid' => 'tenant0mv3ldg',
            'account_key' => 'seller:sellerldgr01',
            'account_kind' => 'seller',
            'seller_uuid' => 'sellerldgr01',
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => 1000,
            'idempotency_key' => 'idem-mv3-ldg-001',
        ], $overrides);
    }

    public function testLedgerTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_marketplace_ledger')->insert(
            array_diff_key($this->minimalSellerLedgerRow(['uuid' => 'ledgerrow002']), ['tenant_uuid' => true])
        );

        $row = $this->connection->table('commerce_marketplace_ledger')->where('uuid', '=', 'ledgerrow002')->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testLedgerSignedAmountRoundTripsNegativeValue(): void
    {
        $this->connection->table('commerce_marketplace_ledger')->insert($this->minimalSellerLedgerRow([
            'uuid' => 'ledgerrow003',
            'entry_type' => 'commission_debit',
            'amount' => -350,
            'idempotency_key' => 'idem-mv3-ldg-003',
        ]));

        $row = $this->connection->table('commerce_marketplace_ledger')->where('uuid', '=', 'ledgerrow003')->first();
        self::assertNotNull($row);
        self::assertSame(-350, (int) $row['amount']);
    }

    public function testLedgerUniqueTenantUuidIdempotencyKeyIsEnforced(): void
    {
        $this->connection->table('commerce_marketplace_ledger')->insert($this->minimalSellerLedgerRow([
            'uuid' => 'ledgerrow010',
            'idempotency_key' => 'idem-mv3-ldg-dup',
        ]));

        try {
            $this->connection->table('commerce_marketplace_ledger')->insert($this->minimalSellerLedgerRow([
                'uuid' => 'ledgerrow011',
                'idempotency_key' => 'idem-mv3-ldg-dup',
            ]));
            self::fail('duplicate (tenant_uuid, idempotency_key) ledger insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testLedgerAccountKindSellerRequiresSellerUuidCheckIsEnforced(): void
    {
        try {
            $this->connection->table('commerce_marketplace_ledger')->insert($this->minimalSellerLedgerRow([
                'uuid' => 'ledgerrow020',
                'idempotency_key' => 'idem-mv3-ldg-020',
                'account_kind' => 'seller',
                'seller_uuid' => null,
            ]));
            self::fail('a seller account row without seller_uuid must violate the CHECK constraint');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testLedgerMarketplaceAccountRequiresNullSellerUuidCheckIsEnforced(): void
    {
        try {
            $this->connection->table('commerce_marketplace_ledger')->insert($this->minimalSellerLedgerRow([
                'uuid' => 'ledgerrow021',
                'idempotency_key' => 'idem-mv3-ldg-021',
                'account_key' => 'marketplace',
                'account_kind' => 'marketplace',
                // seller_uuid still set from the base row -- must be rejected.
            ]));
            self::fail('a marketplace account row with a non-null seller_uuid must violate the CHECK constraint');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testLedgerAccountKeyCanonicalMismatchCheckIsEnforced(): void
    {
        try {
            $this->connection->table('commerce_marketplace_ledger')->insert($this->minimalSellerLedgerRow([
                'uuid' => 'ledgerrow022',
                'idempotency_key' => 'idem-mv3-ldg-022',
                // Non-canonical account_key for a seller account.
                'account_key' => 'seller:someone-else',
            ]));
            self::fail('a non-canonical account_key must violate the CHECK constraint');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testLedgerMarketplaceAccountRowInsertsSuccessfully(): void
    {
        $this->connection->table('commerce_marketplace_ledger')->insert($this->minimalSellerLedgerRow([
            'uuid' => 'ledgerrow023',
            'idempotency_key' => 'idem-mv3-ldg-023',
            'account_key' => 'marketplace',
            'account_kind' => 'marketplace',
            'seller_uuid' => null,
            'entry_type' => 'refund_debit',
            'amount' => -50,
        ]));

        $row = $this->connection->table('commerce_marketplace_ledger')->where('uuid', '=', 'ledgerrow023')->first();
        self::assertNotNull($row);
        self::assertSame('marketplace', $row['account_key']);
        self::assertNull($row['seller_uuid']);
    }

    public function testLedgerHasAccountKeyCurrencyIndex(): void
    {
        $this->assertIndexExists(
            'commerce_marketplace_ledger',
            'commerce_ledger_account_key_currency_index',
            ['tenant_uuid', 'account_key', 'currency']
        );
    }

    public function testLedgerHasAccountKindSellerCurrencyIndex(): void
    {
        $this->assertIndexExists(
            'commerce_marketplace_ledger',
            'commerce_ledger_account_kind_seller_currency_index',
            ['tenant_uuid', 'account_kind', 'seller_uuid', 'currency']
        );
    }

    public function testLedgerHasOrderUuidIndex(): void
    {
        $this->assertIndexExists(
            'commerce_marketplace_ledger',
            'commerce_marketplace_ledger_order_uuid_index',
            ['order_uuid']
        );
    }

    public function testLedgerHasRefundUuidIndex(): void
    {
        $this->assertIndexExists(
            'commerce_marketplace_ledger',
            'commerce_marketplace_ledger_refund_uuid_index',
            ['refund_uuid']
        );
    }

    public function testLedgerHasPayoutUuidIndex(): void
    {
        $this->assertIndexExists(
            'commerce_marketplace_ledger',
            'commerce_marketplace_ledger_payout_uuid_index',
            ['payout_uuid']
        );
    }

    // === commerce_ledger_account_locks (new table, §3.3) ============================

    public function testAccountLocksTableExists(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        self::assertTrue(
            $schema->hasTable('commerce_ledger_account_locks'),
            'missing table commerce_ledger_account_locks'
        );
    }

    public function testAccountLocksHasEverySpecColumn(): void
    {
        $columns = ['id', 'tenant_uuid', 'account_key', 'currency', 'revision', 'created_at', 'updated_at'];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_ledger_account_locks', $column),
                "commerce_ledger_account_locks missing column {$column}"
            );
        }
    }

    public function testAccountLocksHasNoBalanceColumns(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        foreach (['balance', 'available', 'amount'] as $column) {
            self::assertFalse(
                $schema->hasColumn('commerce_ledger_account_locks', $column),
                "commerce_ledger_account_locks must not carry a {$column} column -- balances are always derived"
            );
        }
    }

    private function minimalAccountLockRow(array $overrides = []): array
    {
        return array_merge([
            'tenant_uuid' => 'tenant0mv3lck',
            'account_key' => 'seller:sellerldgr01',
            'currency' => 'USD',
        ], $overrides);
    }

    public function testAccountLocksTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_ledger_account_locks')->insert(
            array_diff_key(
                $this->minimalAccountLockRow(['account_key' => 'seller:sellerlckdflt']),
                ['tenant_uuid' => true]
            )
        );

        $row = $this->connection->table('commerce_ledger_account_locks')
            ->where('account_key', '=', 'seller:sellerlckdflt')
            ->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
        self::assertSame(0, (int) $row['revision']);
    }

    public function testAccountLocksUniqueTenantUuidAccountKeyCurrencyIsEnforced(): void
    {
        $this->connection->table('commerce_ledger_account_locks')->insert($this->minimalAccountLockRow());

        try {
            $this->connection->table('commerce_ledger_account_locks')->insert($this->minimalAccountLockRow());
            self::fail('duplicate (tenant_uuid, account_key, currency) account-lock insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        // A different currency for the SAME account -- must succeed (independent lock row).
        $this->connection->table('commerce_ledger_account_locks')->insert(
            $this->minimalAccountLockRow(['currency' => 'EUR'])
        );
        self::assertSame(
            2,
            $this->connection->table('commerce_ledger_account_locks')
                ->where('tenant_uuid', '=', 'tenant0mv3lck')
                ->count()
        );
    }

    // Unlike plain `index()` calls, UNIQUE constraints are emitted INLINE inside
    // CREATE TABLE (framework house rule -- see TableBuilder::create()), and on
    // SQLite an inline `UNIQUE (...)` clause always gets an engine-assigned
    // `sqlite_autoindex_*` name regardless of the name passed to `->unique()`
    // (unlike PostgreSQL's `CONSTRAINT name UNIQUE (...)`, which does honor it --
    // the custom short name above exists for that driver's 63-byte NAMEDATALEN
    // limit, proven in the Task 12 pgsql lane). Matching this codebase's
    // established convention (see {@see MarketplaceOrderShapeTest}), uniqueness
    // is exercised behaviorally above rather than by introspecting a name that
    // SQLite itself does not preserve.

    // === commerce_commission_policy_events (new table, §3.4) ========================

    public function testCommissionPolicyEventsTableExists(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        self::assertTrue(
            $schema->hasTable('commerce_commission_policy_events'),
            'missing table commerce_commission_policy_events'
        );
    }

    public function testCommissionPolicyEventsHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'subject_kind', 'subject_uuid', 'actor_uuid',
            'before_policy', 'after_policy', 'created_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_commission_policy_events', $column),
                "commerce_commission_policy_events missing column {$column}"
            );
        }
    }

    private function minimalCommissionPolicyEventRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'cpevent0mv001',
            'tenant_uuid' => 'tenant0mv3evt',
            'subject_kind' => 'seller',
            'subject_uuid' => 'sellerevt0001',
            'actor_uuid' => 'operatorevt01',
            'before_policy' => json_encode(['kind' => null, 'bps' => null, 'fixed' => null]),
            'after_policy' => json_encode(['kind' => 'percentage', 'bps' => 500, 'fixed' => null]),
        ], $overrides);
    }

    public function testCommissionPolicyEventsTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_commission_policy_events')->insert(
            array_diff_key(
                $this->minimalCommissionPolicyEventRow(['uuid' => 'cpevent0mv002']),
                ['tenant_uuid' => true]
            )
        );

        $row = $this->connection->table('commerce_commission_policy_events')
            ->where('uuid', '=', 'cpevent0mv002')
            ->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testCommissionPolicyEventsUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_commission_policy_events')->insert(
            $this->minimalCommissionPolicyEventRow(['uuid' => 'cpevent0mv010'])
        );

        try {
            $this->connection->table('commerce_commission_policy_events')->insert(
                $this->minimalCommissionPolicyEventRow(['uuid' => 'cpevent0mv010'])
            );
            self::fail('duplicate (tenant_uuid, uuid) commission-policy-event insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testCommissionPolicyEventsHasSubjectCreatedIndex(): void
    {
        $this->assertIndexExists(
            'commerce_commission_policy_events',
            'commerce_commission_events_subject_created_index',
            ['tenant_uuid', 'subject_kind', 'subject_uuid', 'created_at']
        );
    }

    public function testRerunning012MigrationIsANoOp(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $migration = new CreateMarketplaceLedgerTables();

        // setUp() already ran this migration once via CommerceTestCase::MIGRATIONS;
        // re-running up() must be a no-op guarded by hasTable().
        $migration->up($schema);
        $migration->up($schema);

        self::assertTrue($schema->hasTable('commerce_marketplace_ledger'));
        self::assertTrue($schema->hasTable('commerce_ledger_account_locks'));
        self::assertTrue($schema->hasTable('commerce_commission_policy_events'));
    }

    // === commerce_payouts (new table, §3.5) =========================================

    public function testPayoutsTableExists(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        self::assertTrue($schema->hasTable('commerce_payouts'), 'missing table commerce_payouts');
    }

    public function testPayoutsHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'seller_uuid', 'currency', 'amount',
            'external_ref', 'note', 'created_by', 'idempotency_key', 'created_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_payouts', $column),
                "commerce_payouts missing column {$column}"
            );
        }
    }

    private function minimalPayoutRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'payoutmv30001',
            'tenant_uuid' => 'tenant0mv3pay',
            'seller_uuid' => 'sellerpay0001',
            'currency' => 'USD',
            'amount' => 5000,
            'external_ref' => 'wire-ref-0001',
            'created_by' => 'operatorpay01',
            'idempotency_key' => 'idem-mv3-pay-001',
        ], $overrides);
    }

    public function testPayoutsTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_payouts')->insert(
            array_diff_key(
                $this->minimalPayoutRow(['uuid' => 'payoutmv30002', 'idempotency_key' => 'idem-mv3-pay-002']),
                ['tenant_uuid' => true]
            )
        );

        $row = $this->connection->table('commerce_payouts')->where('uuid', '=', 'payoutmv30002')->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testPayoutsExternalRefAndCreatedByAreNullableAtTheSchemaLevel(): void
    {
        // MV4 (design spec §3.1) folds the provider-payout columns into this
        // still-unreleased table and makes external_ref/created_by nullable at
        // the SCHEMA level: a provider row is written before a provider
        // reference is known, and a scheduled batch payout has no human
        // actor. The manual `record()` service continues to require both --
        // that is a SERVICE-level rule (Task 7), not a DB constraint.
        foreach (['external_ref', 'created_by'] as $nowNullable) {
            $row = $this->minimalPayoutRow([
                'uuid' => 'payoutnull' . substr($nowNullable, 0, 3),
                'idempotency_key' => 'idem-null-' . $nowNullable,
            ]);
            unset($row[$nowNullable]);

            $this->connection->table('commerce_payouts')->insert($row);

            $inserted = $this->connection->table('commerce_payouts')
                ->where('uuid', '=', $row['uuid'])
                ->first();
            self::assertNotNull($inserted);
            self::assertNull($inserted[$nowNullable], "commerce_payouts.{$nowNullable} must be nullable");
        }
    }

    public function testPayoutsUniqueTenantUuidIdempotencyKeyIsEnforced(): void
    {
        $this->connection->table('commerce_payouts')->insert($this->minimalPayoutRow([
            'uuid' => 'payoutmv30010',
            'idempotency_key' => 'idem-mv3-pay-dup',
        ]));

        try {
            $this->connection->table('commerce_payouts')->insert($this->minimalPayoutRow([
                'uuid' => 'payoutmv30011',
                'idempotency_key' => 'idem-mv3-pay-dup',
            ]));
            self::fail('duplicate (tenant_uuid, idempotency_key) payout insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testPayoutsUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_payouts')->insert($this->minimalPayoutRow([
            'uuid' => 'payoutmv30020',
            'idempotency_key' => 'idem-mv3-pay-020',
        ]));

        try {
            $this->connection->table('commerce_payouts')->insert($this->minimalPayoutRow([
                'uuid' => 'payoutmv30020',
                'idempotency_key' => 'idem-mv3-pay-021',
            ]));
            self::fail('duplicate (tenant_uuid, uuid) payout insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testPayoutsPositiveAmountCheckIsEnforced(): void
    {
        try {
            $this->connection->table('commerce_payouts')->insert($this->minimalPayoutRow([
                'uuid' => 'payoutmv30030',
                'idempotency_key' => 'idem-mv3-pay-030',
                'amount' => -100,
            ]));
            self::fail('a non-positive payout amount must violate the CHECK constraint');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testPayoutsHasSellerCurrencyIndex(): void
    {
        $this->assertIndexExists(
            'commerce_payouts',
            'commerce_payouts_tenant_uuid_seller_uuid_currency_index',
            ['tenant_uuid', 'seller_uuid', 'currency']
        );
    }

    public function testRerunning013MigrationIsANoOp(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $migration = new CreatePayoutTable();

        // setUp() already ran this migration once via CommerceTestCase::MIGRATIONS;
        // re-running up() must be a no-op guarded by hasTable().
        $migration->up($schema);
        $migration->up($schema);

        self::assertTrue($schema->hasTable('commerce_payouts'));
    }

    // === config/commerce.php marketplace.commission (§3.6) ==========================

    public function testConfigCommissionDefaultsToConcreteNeverAllNullPercentagePolicy(): void
    {
        $commission = config($this->appContext(), 'commerce.marketplace.commission');

        self::assertIsArray($commission);
        self::assertSame('percentage', $commission['kind']);
        self::assertSame(0, $commission['bps']);
        self::assertNull($commission['fixed']);
    }

    // === DiagnosticsReport (§3.7) =====================================================

    public function testDiagnosticsCommerceTablesIncludesAllFourNewTables(): void
    {
        $tables = DiagnosticsReport::commerceTables();

        foreach (
            [
                'commerce_marketplace_ledger',
                'commerce_ledger_account_locks',
                'commerce_commission_policy_events',
                'commerce_payouts',
            ] as $table
        ) {
            self::assertContains($table, $tables, "DiagnosticsReport::commerceTables() missing {$table}");
        }
    }

    public function testDiagnosticsTenantTablesIncludesAllFourNewTables(): void
    {
        $tables = DiagnosticsReport::tenantTables();

        foreach (
            [
                'commerce_marketplace_ledger',
                'commerce_ledger_account_locks',
                'commerce_commission_policy_events',
                'commerce_payouts',
            ] as $table
        ) {
            self::assertContains($table, $tables, "DiagnosticsReport::tenantTables() missing {$table}");
        }
    }

    public function testDiagnosticsReportBuildShowsAllFourNewTablesPresent(): void
    {
        $report = DiagnosticsReport::build($this->appContext());

        foreach (
            [
                'commerce_marketplace_ledger',
                'commerce_ledger_account_locks',
                'commerce_commission_policy_events',
                'commerce_payouts',
            ] as $table
        ) {
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
    // Real-PostgreSQL convergence lanes (design spec §3/§10, MV3 plan Task
    // 12): the SQLite tests above prove the SHAPE; these prove the SAME
    // migrations converge on a genuinely different engine -- a fresh install
    // produces the folded commission columns and the four new tables, every
    // index is asserted via `pg_indexes` (the schema builder exposes no
    // `hasIndex`, and index names are generated identically across drivers --
    // {@see \Glueful\Database\Schema\TableBuilder::generateIndexName()} in
    // the framework -- so the SAME pinned names from the SQLite tests above
    // apply here unchanged), the PostgreSQL-only CHECK constraints that DO
    // exist (the ledger account_kind/account_key identity CHECK and the
    // payout positive-amount CHECK -- design spec's "commission shape" is
    // validated only in {@see \Glueful\Extensions\Commerce\Marketplace\CommissionPolicyResolver::validate()},
    // service-side on every driver; there is no DB-level CHECK for it,
    // deliberately, since `commission_kind`/`bps`/`fixed` alone cannot
    // express "all-null inherits, percentage needs only bps, fixed needs
    // only fixed" as a portable column CHECK) actually fire on real
    // PostgreSQL, and re-running 012/013 is a no-op. Gating, fixture-width
    // discipline, and the throwaway `Connection`/`ApplicationContext`
    // construction all mirror `Migrations\MarketplaceOrderShapeTest`/
    // `Marketplace\MarketplacePgsqlTest` exactly.
    // =====================================================================

    public function testFreshInstallConvergesOnRealPostgresWithFoldedCommissionColumnsAndTheFourNewTables(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();

        foreach (['commission_kind', 'commission_bps', 'commission_fixed'] as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_products', $column),
                "commerce_products missing folded column {$column} on PostgreSQL"
            );
            self::assertTrue(
                $schema->hasColumn('commerce_sellers', $column),
                "commerce_sellers missing folded column {$column} on PostgreSQL"
            );
            self::assertTrue(
                $schema->hasColumn('commerce_marketplace_settings', $column),
                "commerce_marketplace_settings missing folded column {$column} on PostgreSQL"
            );
        }
        foreach (
            [
                'commission_source', 'commission_kind', 'commission_bps', 'commission_fixed',
                'commission_basis', 'commission_amount',
            ] as $column
        ) {
            self::assertTrue(
                $schema->hasColumn('commerce_order_lines', $column),
                "commerce_order_lines missing folded column {$column} on PostgreSQL"
            );
        }
        self::assertTrue(
            $schema->hasColumn('commerce_seller_orders', 'commission_amount'),
            'commerce_seller_orders missing folded column commission_amount on PostgreSQL'
        );

        self::assertTrue($schema->hasTable('commerce_marketplace_ledger'), 'missing commerce_marketplace_ledger');
        foreach (
            [
                'id', 'uuid', 'tenant_uuid', 'account_key', 'account_kind', 'seller_uuid',
                'currency', 'entry_type', 'amount', 'order_uuid', 'seller_order_uuid',
                'refund_uuid', 'payout_uuid', 'idempotency_key', 'reason', 'created_by', 'created_at',
            ] as $column
        ) {
            self::assertTrue(
                $schema->hasColumn('commerce_marketplace_ledger', $column),
                "commerce_marketplace_ledger missing column {$column} on PostgreSQL"
            );
        }

        self::assertTrue(
            $schema->hasTable('commerce_ledger_account_locks'),
            'missing commerce_ledger_account_locks'
        );
        foreach (
            ['id', 'tenant_uuid', 'account_key', 'currency', 'revision', 'created_at', 'updated_at'] as $column
        ) {
            self::assertTrue(
                $schema->hasColumn('commerce_ledger_account_locks', $column),
                "commerce_ledger_account_locks missing column {$column} on PostgreSQL"
            );
        }

        self::assertTrue(
            $schema->hasTable('commerce_commission_policy_events'),
            'missing commerce_commission_policy_events'
        );
        foreach (
            [
                'id', 'uuid', 'tenant_uuid', 'subject_kind', 'subject_uuid', 'actor_uuid',
                'before_policy', 'after_policy', 'created_at',
            ] as $column
        ) {
            self::assertTrue(
                $schema->hasColumn('commerce_commission_policy_events', $column),
                "commerce_commission_policy_events missing column {$column} on PostgreSQL"
            );
        }

        self::assertTrue($schema->hasTable('commerce_payouts'), 'missing commerce_payouts');
        foreach (
            [
                'id', 'uuid', 'tenant_uuid', 'seller_uuid', 'currency', 'amount',
                'external_ref', 'note', 'created_by', 'idempotency_key', 'created_at',
            ] as $column
        ) {
            self::assertTrue(
                $schema->hasColumn('commerce_payouts', $column),
                "commerce_payouts missing column {$column} on PostgreSQL"
            );
        }
    }

    public function testLedgerAndPayoutIndexesExistOnRealPostgresViaPgIndexes(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());

        $this->assertPgIndexExists(
            $connection,
            'commerce_marketplace_ledger',
            'commerce_ledger_account_key_currency_index',
            ['tenant_uuid', 'account_key', 'currency']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_marketplace_ledger',
            'commerce_ledger_account_kind_seller_currency_index',
            ['tenant_uuid', 'account_kind', 'seller_uuid', 'currency']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_marketplace_ledger',
            'commerce_marketplace_ledger_order_uuid_index',
            ['order_uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_marketplace_ledger',
            'commerce_marketplace_ledger_refund_uuid_index',
            ['refund_uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_marketplace_ledger',
            'commerce_marketplace_ledger_payout_uuid_index',
            ['payout_uuid']
        );

        // Unlike SQLite (see the docblock note above `testAccountLocksUniqueTenantUuidAccountKeyCurrencyIsEnforced()`),
        // PostgreSQL DOES honor an inline `->unique(..., 'name')`'s custom
        // name via `CONSTRAINT name UNIQUE (...)` -- so this composite
        // uniqueness, unverifiable by name on SQLite, IS verifiable here.
        $this->assertPgIndexExists(
            $connection,
            'commerce_ledger_account_locks',
            'commerce_ledger_account_locks_key_currency_unique',
            ['tenant_uuid', 'account_key', 'currency']
        );

        $this->assertPgIndexExists(
            $connection,
            'commerce_commission_policy_events',
            'commerce_commission_events_subject_created_index',
            ['tenant_uuid', 'subject_kind', 'subject_uuid', 'created_at']
        );

        $this->assertPgIndexExists(
            $connection,
            'commerce_payouts',
            'commerce_payouts_tenant_uuid_seller_uuid_currency_index',
            ['tenant_uuid', 'seller_uuid', 'currency']
        );
    }

    public function testLedgerAccountIdentityCheckConstraintIsEnforcedOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        // Self-healing cleanup (mirrors `Marketplace\MarketplacePgsqlTest`'s
        // per-test `cleanupTenant()` convention): the valid row inserted
        // below is a REAL commit against the persistent PostgreSQL test
        // database, unlike every SQLite test in this file -- it must not
        // survive to collide with a later run.
        $connection->table('commerce_marketplace_ledger')->where('tenant_uuid', '=', 'tenantpgckl1')->delete();

        try {
            $connection->table('commerce_marketplace_ledger')->insert([
                'uuid' => 'pgcklgr0001',
                'tenant_uuid' => 'tenantpgckl1',
                'account_key' => 'seller:pgckseller1',
                'account_kind' => 'seller',
                'seller_uuid' => null,
                'currency' => 'USD',
                'entry_type' => 'sale_credit',
                'amount' => 1000,
                'idempotency_key' => 'idem-pg-ckl-1',
            ]);
            self::fail('a seller account row without seller_uuid must violate the CHECK constraint on PostgreSQL');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        try {
            $connection->table('commerce_marketplace_ledger')->insert([
                'uuid' => 'pgcklgr0002',
                'tenant_uuid' => 'tenantpgckl1',
                'account_key' => 'marketplace',
                'account_kind' => 'marketplace',
                'seller_uuid' => 'pgckseller1',
                'currency' => 'USD',
                'entry_type' => 'refund_debit',
                'amount' => -500,
                'idempotency_key' => 'idem-pg-ckl-2',
            ]);
            self::fail(
                'a marketplace account row with a non-null seller_uuid must violate the CHECK constraint '
                    . 'on PostgreSQL'
            );
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        // A valid row still succeeds -- the CHECK is precise, not overbroad.
        $connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => 'pgcklgr0003',
            'tenant_uuid' => 'tenantpgckl1',
            'account_key' => 'seller:pgckseller1',
            'account_kind' => 'seller',
            'seller_uuid' => 'pgckseller1',
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => 1000,
            'idempotency_key' => 'idem-pg-ckl-3',
        ]);
        self::assertSame(
            1,
            $connection->table('commerce_marketplace_ledger')->where('uuid', '=', 'pgcklgr0003')->count()
        );

        $connection->table('commerce_marketplace_ledger')->where('tenant_uuid', '=', 'tenantpgckl1')->delete();
    }

    public function testPayoutPositiveAmountCheckConstraintIsEnforcedOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());

        foreach ([0, -100] as $amount) {
            try {
                $connection->table('commerce_payouts')->insert([
                    'uuid' => 'pgckpay' . abs($amount),
                    'tenant_uuid' => 'tenantpgckp1',
                    'seller_uuid' => 'pgckpayslr01',
                    'currency' => 'USD',
                    'amount' => $amount,
                    'external_ref' => 'pg-ck-payout-ref',
                    'created_by' => 'pgckpayop001',
                    'idempotency_key' => 'idem-pg-ckp-' . $amount,
                ]);
                self::fail("a payout amount of {$amount} must violate the CHECK constraint on PostgreSQL");
            } catch (\Throwable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRerunning012And013MigrationsAreNoOpsOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();

        // migratedConnection() already ran every migration (including 012/013)
        // once; re-running up() again must be a no-op guarded by hasTable().
        (new CreateMarketplaceLedgerTables())->up($schema);
        (new CreateMarketplaceLedgerTables())->up($schema);
        (new CreatePayoutTable())->up($schema);
        (new CreatePayoutTable())->up($schema);

        self::assertTrue($schema->hasTable('commerce_marketplace_ledger'));
        self::assertTrue($schema->hasTable('commerce_ledger_account_locks'));
        self::assertTrue($schema->hasTable('commerce_commission_policy_events'));
        self::assertTrue($schema->hasTable('commerce_payouts'));
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
