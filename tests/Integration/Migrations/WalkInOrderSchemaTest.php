<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Database\Migrations\AddWalkInOrderFieldsAndDraftAttemptLedger;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Admin-order-creation cycle 2, Task 6 (design spec §2.6): migration-022 shape,
 * nullable relaxations, backfills, and the `commerce_order_draft_attempts` ledger.
 * Gating/fixture-width/self-healing discipline mirrors
 * `Migrations\StockNotNullBackfillTest` and `Orders\OrderNumberGeneratorTest`'s
 * pgsql lanes exactly.
 */
final class WalkInOrderSchemaTest extends CommerceTestCase
{
    // =====================================================================
    // Fresh install (setUp() already ran migration 022 via CommerceTestCase::MIGRATIONS)
    // =====================================================================

    public function testFreshInstallCommerceOrdersHasWalkInColumnsWithBackfilledDefaults(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        foreach (
            ['phone_normalized', 'phone_display', 'customer_name', 'origin', 'fulfillment_mode', 'draft_revision']
            as $column
        ) {
            self::assertTrue($schema->hasColumn('commerce_orders', $column), "missing column {$column}");
        }

        // A row that omits every walk-in column entirely still gets the documented defaults.
        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'walkindef001',
            'tenant_uuid' => '',
            'order_number' => 'ORD-WALKINDEF1',
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);

        $row = $this->connection->table('commerce_orders')->where('uuid', '=', 'walkindef001')->first();
        self::assertNotNull($row);
        self::assertSame('storefront', $row['origin']);
        self::assertSame('delivery', $row['fulfillment_mode']);
        self::assertSame(0, (int) $row['draft_revision']);
        self::assertNull($row['phone_normalized']);
        self::assertNull($row['phone_display']);
        self::assertNull($row['customer_name']);
    }

    public function testFreshInstallNullableEmailTokenPhoneAndOrderNumberColumnsAcceptNull(): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'walkinnull01',
            'tenant_uuid' => '',
            'order_number' => null,
            'email' => null,
            'guest_token_hash' => null,
            'phone_normalized' => null,
            'phone_display' => null,
            'customer_name' => null,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);

        $row = $this->connection->table('commerce_orders')->where('uuid', '=', 'walkinnull01')->first();
        self::assertNotNull($row);
        self::assertNull($row['order_number']);
        self::assertNull($row['email']);
        self::assertNull($row['guest_token_hash']);
        self::assertNull($row['phone_normalized']);
        self::assertNull($row['phone_display']);
        self::assertNull($row['customer_name']);
    }

    public function testFreshInstallExplicitWalkInValuesRoundTrip(): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'walkinvals01',
            'tenant_uuid' => '',
            'order_number' => null,
            'email' => null,
            'guest_token_hash' => null,
            'phone_normalized' => '+15551234567',
            'phone_display' => '+1 (555) 123-4567',
            'customer_name' => 'Jane Walkin',
            'origin' => 'admin',
            'fulfillment_mode' => 'in_store',
            'draft_revision' => 3,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);

        $row = $this->connection->table('commerce_orders')->where('uuid', '=', 'walkinvals01')->first();
        self::assertNotNull($row);
        self::assertSame('+15551234567', $row['phone_normalized']);
        self::assertSame('+1 (555) 123-4567', $row['phone_display']);
        self::assertSame('Jane Walkin', $row['customer_name']);
        self::assertSame('admin', $row['origin']);
        self::assertSame('in_store', $row['fulfillment_mode']);
        self::assertSame(3, (int) $row['draft_revision']);
    }

    public function testMultipleNullOrderNumbersCoexistButDuplicateNonNullRejectedPerTenantOnSqlite(): void
    {
        $tenant = 'walkinnn0001';

        // Two NULL order_number rows for the SAME tenant must coexist.
        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'walkinnn0001',
            'tenant_uuid' => $tenant,
            'order_number' => null,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'walkinnn0002',
            'tenant_uuid' => $tenant,
            'order_number' => null,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
        self::assertSame(
            2,
            $this->connection->table('commerce_orders')
                ->where('tenant_uuid', '=', $tenant)
                ->whereNull('order_number')
                ->count()
        );

        // A duplicate NON-NULL order_number for the same tenant is still rejected.
        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'walkinnn0003',
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-WALKINNN-1',
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
        try {
            $this->connection->table('commerce_orders')->insert([
                'uuid' => 'walkinnn0004',
                'tenant_uuid' => $tenant,
                'order_number' => 'ORD-WALKINNN-1',
                'currency' => 'USD',
                'subtotal' => 1000,
                'grand_total' => 1000,
            ]);
            self::fail('a duplicate non-null (tenant_uuid, order_number) must still be rejected');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        // The same number for a DIFFERENT tenant is unaffected.
        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'walkinnn0005',
            'tenant_uuid' => 'walkinnnten2',
            'order_number' => 'ORD-WALKINNN-1',
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
        $this->addToAssertionCount(1);
    }

    // =====================================================================
    // commerce_order_draft_attempts shape (SQLite)
    // =====================================================================

    public function testDraftAttemptsTableExistsWithExpectedColumns(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        self::assertTrue($schema->hasTable('commerce_order_draft_attempts'));

        foreach (
            [
                'id', 'tenant_uuid', 'idempotency_key', 'request_fingerprint',
                'order_uuid', 'status', 'completed_at', 'created_at', 'updated_at',
            ] as $column
        ) {
            self::assertTrue(
                $schema->hasColumn('commerce_order_draft_attempts', $column),
                "missing column {$column}"
            );
        }
    }

    public function testDraftAttemptsUniqueTenantIdempotencyKeyRejectsDuplicateButAllowsOtherTenantOrKey(): void
    {
        $this->connection->table('commerce_order_draft_attempts')->insert([
            'tenant_uuid' => 'walkinat0001',
            'idempotency_key' => 'idem-shape-1',
            'request_fingerprint' => str_repeat('a', 64),
            'order_uuid' => 'orderatmpt01',
            'status' => 'pending',
        ]);

        // Same tenant, same key -- rejected.
        try {
            $this->connection->table('commerce_order_draft_attempts')->insert([
                'tenant_uuid' => 'walkinat0001',
                'idempotency_key' => 'idem-shape-1',
                'request_fingerprint' => str_repeat('b', 64),
                'order_uuid' => 'orderatmpt02',
                'status' => 'pending',
            ]);
            self::fail('a duplicate (tenant_uuid, idempotency_key) must be rejected');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        // Same tenant, different key -- allowed.
        $this->connection->table('commerce_order_draft_attempts')->insert([
            'tenant_uuid' => 'walkinat0001',
            'idempotency_key' => 'idem-shape-2',
            'request_fingerprint' => str_repeat('c', 64),
            'order_uuid' => 'orderatmpt03',
            'status' => 'pending',
        ]);

        // Different tenant, same key -- allowed.
        $this->connection->table('commerce_order_draft_attempts')->insert([
            'tenant_uuid' => 'walkinat0002',
            'idempotency_key' => 'idem-shape-1',
            'request_fingerprint' => str_repeat('d', 64),
            'order_uuid' => 'orderatmpt04',
            'status' => 'pending',
        ]);

        self::assertSame(3, $this->connection->table('commerce_order_draft_attempts')->count());
    }

    public function testDraftAttemptsDefaultsAndCompletion(): void
    {
        $id = $this->connection->table('commerce_order_draft_attempts')->insert([
            'tenant_uuid' => 'walkinat0003',
            'idempotency_key' => 'idem-shape-3',
            'request_fingerprint' => str_repeat('e', 64),
            'order_uuid' => 'orderatmpt05',
        ]);
        self::assertGreaterThan(0, $id);

        $row = $this->connection->table('commerce_order_draft_attempts')
            ->where('tenant_uuid', '=', 'walkinat0003')
            ->first();
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['completed_at']);
    }

    // =====================================================================
    // Upgrade path (SQLite): a genuine v1.9.1-shape pre-existing install
    // (migrations 001-021 only) converging through migration 022.
    // =====================================================================

    public function testUpgradePathFromV191ShapeBackfillsOriginAndFulfillmentModeOnSqlite(): void
    {
        $connection = $this->preMigrationConnection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);

        // A pre-existing (already-finalized, v1.9.1-shape) order -- representable
        // ONLY before this migration, since migrations 001-021 alone have no
        // origin/fulfillment_mode/draft_revision/phone_*/customer_name columns.
        $connection->table('commerce_orders')->insert([
            'uuid' => 'walkinupg001',
            'tenant_uuid' => 'walkinupg1',
            'order_number' => 'ORD-WALKINUPG-1',
            'email' => 'legacy@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);

        (new AddWalkInOrderFieldsAndDraftAttemptLedger())->up($connection->getSchemaBuilder());

        $row = $connection->table('commerce_orders')->where('uuid', '=', 'walkinupg001')->first();
        self::assertNotNull($row);
        self::assertSame('storefront', $row['origin'], 'pre-existing rows are historically storefront orders');
        self::assertSame('delivery', $row['fulfillment_mode'], 'conservative compatibility backfill');
        self::assertSame(0, (int) $row['draft_revision']);
        self::assertNull($row['phone_normalized']);
        self::assertNull($row['phone_display']);
        self::assertNull($row['customer_name']);
        // Pre-existing non-null values are untouched by the backfill.
        self::assertSame('ORD-WALKINUPG-1', $row['order_number']);
        self::assertSame('legacy@example.com', $row['email']);

        self::assertTrue($connection->getSchemaBuilder()->hasTable('commerce_order_draft_attempts'));

        // The relaxed columns genuinely accept NULL after the upgrade.
        $connection->table('commerce_orders')->insert([
            'uuid' => 'walkinupg002',
            'tenant_uuid' => 'walkinupg1',
            'order_number' => null,
            'email' => null,
            'guest_token_hash' => null,
            'currency' => 'USD',
            'subtotal' => 500,
            'grand_total' => 500,
        ]);
        self::assertSame(
            1,
            $connection->table('commerce_orders')->where('uuid', '=', 'walkinupg002')->count()
        );
    }

    /**
     * Every migration in this codebase is safe to call `up()` against an
     * already-migrated database (hasTable()-guarded `createTable`, or --
     * migration 021 -- a naturally idempotent `modifyColumn()`). This
     * migration's plain `add_columns` calls are guarded with `hasColumn()`
     * individually to preserve that same contract -- proven here by running
     * `up()` twice and asserting the second run is a genuine no-op, not a
     * duplicate-column failure.
     */
    public function testRerunningUpMigrationIsANoOpOnSqlite(): void
    {
        $connection = $this->preMigrationConnection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);
        $migration = new AddWalkInOrderFieldsAndDraftAttemptLedger();
        $schema = $connection->getSchemaBuilder();

        $migration->up($schema);
        $migration->up($schema);

        foreach (
            ['phone_normalized', 'phone_display', 'customer_name', 'origin', 'fulfillment_mode', 'draft_revision']
            as $column
        ) {
            self::assertTrue($schema->hasColumn('commerce_orders', $column));
        }
        self::assertTrue($schema->hasTable('commerce_order_draft_attempts'));

        // The relaxed/backfilled shape still works exactly as before after the rerun.
        $connection->table('commerce_orders')->insert([
            'uuid' => 'walkinrerun1',
            'tenant_uuid' => '',
            'order_number' => null,
            'currency' => 'USD',
            'subtotal' => 100,
            'grand_total' => 100,
        ]);
        $row = $connection->table('commerce_orders')->where('uuid', '=', 'walkinrerun1')->first();
        self::assertNotNull($row);
        self::assertSame('storefront', $row['origin']);
        self::assertSame('delivery', $row['fulfillment_mode']);
    }

    // =====================================================================
    // Rollback safety
    // =====================================================================

    public function testDownRemovesNewColumnsAndTableAndUpConvergesAgain(): void
    {
        $connection = $this->preMigrationConnection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);
        $migration = new AddWalkInOrderFieldsAndDraftAttemptLedger();
        $schema = $connection->getSchemaBuilder();

        $migration->up($schema);
        self::assertTrue($schema->hasColumn('commerce_orders', 'origin'));
        self::assertTrue($schema->hasTable('commerce_order_draft_attempts'));

        $migration->down($schema);

        foreach (
            ['phone_normalized', 'phone_display', 'customer_name', 'origin', 'fulfillment_mode', 'draft_revision']
            as $column
        ) {
            self::assertFalse($schema->hasColumn('commerce_orders', $column), "{$column} must be dropped by down()");
        }
        self::assertFalse($schema->hasTable('commerce_order_draft_attempts'));

        // The three relaxed columns are restored to NOT NULL by down().
        try {
            $connection->getPDO()->exec(
                "INSERT INTO commerce_orders (uuid, tenant_uuid, order_number, email, guest_token_hash, "
                . "currency, subtotal, grand_total) VALUES "
                . "('walkindwn01', '', NULL, 'a@example.com', 'hash', 'USD', 100, 100)"
            );
            self::fail('order_number must be NOT NULL again after down()');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        // up() again converges back to the same working shape with no error.
        $migration->up($schema);
        self::assertTrue($schema->hasColumn('commerce_orders', 'origin'));
        self::assertTrue($schema->hasTable('commerce_order_draft_attempts'));

        $connection->table('commerce_orders')->insert([
            'uuid' => 'walkindwn02',
            'tenant_uuid' => '',
            'order_number' => null,
            'currency' => 'USD',
            'subtotal' => 100,
            'grand_total' => 100,
        ]);
        self::assertSame(
            1,
            $connection->table('commerce_orders')->where('uuid', '=', 'walkindwn02')->count()
        );

        // Review fix (minor): the re-converged shape after up()->down()->up() must
        // still enforce every guarantee migration 022 makes, not just accept NULLs --
        // a duplicate non-null (tenant_uuid, order_number) is still rejected.
        $connection->table('commerce_orders')->insert([
            'uuid' => 'walkindwn03',
            'tenant_uuid' => 'walkindwntn1',
            'order_number' => 'ORD-WALKINDWN-1',
            'currency' => 'USD',
            'subtotal' => 100,
            'grand_total' => 100,
        ]);
        try {
            $connection->table('commerce_orders')->insert([
                'uuid' => 'walkindwn04',
                'tenant_uuid' => 'walkindwntn1',
                'order_number' => 'ORD-WALKINDWN-1',
                'currency' => 'USD',
                'subtotal' => 100,
                'grand_total' => 100,
            ]);
            self::fail('duplicate non-null (tenant_uuid, order_number) must still be rejected after up->down->up');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Review fix (Important 1): `down()`'s precondition is that no `commerce_orders`
     * row is relying on the nullable shape (a draft/walk-in row with a NULL
     * `order_number`/`email`/`guest_token_hash`). Restoring NOT NULL against such a
     * row must fail LOUDLY (never silently drop the row or invent a placeholder) AND
     * leave the database EXACTLY as `up()` left it -- no partial column drops, and
     * critically, `commerce_order_draft_attempts` must NOT have been dropped either,
     * even though `down()` normally drops it (a real bug this test would have caught:
     * an earlier draft of this migration dropped the ledger table via a SEPARATE,
     * already-committed statement BEFORE the column-restoration step failed).
     */
    public function testDownFailsLoudlyAndNonDestructivelyWhenADraftRowExists(): void
    {
        $connection = $this->preMigrationConnection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);
        $migration = new AddWalkInOrderFieldsAndDraftAttemptLedger();
        $schema = $connection->getSchemaBuilder();

        $migration->up($schema);

        // A draft order -- representable ONLY because this migration relaxed
        // order_number to nullable.
        $connection->table('commerce_orders')->insert([
            'uuid' => 'walkindraft1',
            'tenant_uuid' => '',
            'order_number' => null,
            'customer_name' => 'In-progress walk-in',
            'currency' => 'USD',
            'subtotal' => 100,
            'grand_total' => 100,
        ]);

        try {
            $migration->down($schema);
            self::fail('down() must throw loudly when a draft row violates the restored NOT NULL constraint');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        // Non-destructive: every up()-added column is still there...
        foreach (
            ['phone_normalized', 'phone_display', 'customer_name', 'origin', 'fulfillment_mode', 'draft_revision']
            as $column
        ) {
            self::assertTrue(
                $schema->hasColumn('commerce_orders', $column),
                "{$column} must survive a failed down() -- no partial column drops"
            );
        }
        // ...and critically, the ledger table was never dropped either, even though
        // down() normally drops it as its first step.
        self::assertTrue(
            $schema->hasTable('commerce_order_draft_attempts'),
            'commerce_order_draft_attempts must survive a failed down() -- the whole rollback is one unit'
        );

        // The draft row itself is untouched.
        $row = $connection->table('commerce_orders')->where('uuid', '=', 'walkindraft1')->first();
        self::assertNotNull($row);
        self::assertNull($row['order_number']);
        self::assertSame('In-progress walk-in', $row['customer_name']);

        // The database is still fully usable: a fresh draft insert still works.
        $connection->table('commerce_orders')->insert([
            'uuid' => 'walkindraft2',
            'tenant_uuid' => '',
            'order_number' => null,
            'currency' => 'USD',
            'subtotal' => 200,
            'grand_total' => 200,
        ]);
        self::assertSame(
            1,
            $connection->table('commerce_orders')->where('uuid', '=', 'walkindraft2')->count()
        );
    }

    // =====================================================================
    // Real-PostgreSQL convergence lanes.
    // =====================================================================

    public function testFreshInstallAcceptsWalkInShapeOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedPgConnection();
        $tenant = 'pgwalkin0001';
        $connection->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->delete();

        try {
            $connection->table('commerce_orders')->insert([
                'uuid' => 'pgwalkin0001',
                'tenant_uuid' => $tenant,
                'order_number' => null,
                'email' => null,
                'guest_token_hash' => null,
                'phone_normalized' => '+15559990001',
                'phone_display' => '+1 (555) 999-0001',
                'customer_name' => 'PG Walkin',
                'origin' => 'admin',
                'fulfillment_mode' => 'in_store',
                'currency' => 'USD',
                'subtotal' => 1000,
                'grand_total' => 1000,
            ]);
            $connection->table('commerce_orders')->insert([
                'uuid' => 'pgwalkin0002',
                'tenant_uuid' => $tenant,
                'order_number' => null,
                'currency' => 'USD',
                'subtotal' => 1000,
                'grand_total' => 1000,
            ]);

            self::assertSame(
                2,
                $connection->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->count()
            );

            try {
                $connection->table('commerce_orders')->insert([
                    'uuid' => 'pgwalkin0003',
                    'tenant_uuid' => $tenant,
                    'order_number' => 'ORD-PGWALKIN-1',
                    'currency' => 'USD',
                    'subtotal' => 1000,
                    'grand_total' => 1000,
                ]);
                $connection->table('commerce_orders')->insert([
                    'uuid' => 'pgwalkin0004',
                    'tenant_uuid' => $tenant,
                    'order_number' => 'ORD-PGWALKIN-1',
                    'currency' => 'USD',
                    'subtotal' => 1000,
                    'grand_total' => 1000,
                ]);
                self::fail('a duplicate non-null (tenant_uuid, order_number) must be rejected on real PostgreSQL');
            } catch (\PDOException) {
                $this->addToAssertionCount(1);
            }
        } finally {
            $connection->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->delete();
        }
    }

    public function testUpgradePathFromV191ShapeBackfillsOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->preMigrationConnection($this->pgConfig());
        $tenant = 'pgwalkinupg1';
        $connection->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->delete();

        try {
            $connection->table('commerce_orders')->insert([
                'uuid' => 'pgwalkinupg1',
                'tenant_uuid' => $tenant,
                'order_number' => 'ORD-PGWALKINUPG-1',
                'email' => 'legacy@example.com',
                'guest_token_hash' => str_repeat('a', 64),
                'currency' => 'USD',
                'subtotal' => 1000,
                'grand_total' => 1000,
            ]);

            (new AddWalkInOrderFieldsAndDraftAttemptLedger())->up($connection->getSchemaBuilder());

            $row = $connection->table('commerce_orders')->where('uuid', '=', 'pgwalkinupg1')->first();
            self::assertNotNull($row);
            self::assertSame('storefront', $row['origin']);
            self::assertSame('delivery', $row['fulfillment_mode']);
            self::assertSame(0, (int) $row['draft_revision']);
            self::assertTrue($connection->getSchemaBuilder()->hasTable('commerce_order_draft_attempts'));

            $connection->table('commerce_orders')->insert([
                'uuid' => 'pgwalkinupg2',
                'tenant_uuid' => $tenant,
                'order_number' => null,
                'email' => null,
                'guest_token_hash' => null,
                'currency' => 'USD',
                'subtotal' => 500,
                'grand_total' => 500,
            ]);
            self::assertSame(
                1,
                $connection->table('commerce_orders')->where('uuid', '=', 'pgwalkinupg2')->count()
            );
        } finally {
            $connection->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->delete();
        }
    }

    public function testDraftAttemptsUniqueConstraintOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedPgConnection();
        $tenant = 'pgwalkinat01';
        $connection->table('commerce_order_draft_attempts')->where('tenant_uuid', '=', $tenant)->delete();

        try {
            $connection->table('commerce_order_draft_attempts')->insert([
                'tenant_uuid' => $tenant,
                'idempotency_key' => 'idem-pg-shape-1',
                'request_fingerprint' => str_repeat('a', 64),
                'order_uuid' => 'pgorderatmp1',
                'status' => 'pending',
            ]);

            try {
                $connection->table('commerce_order_draft_attempts')->insert([
                    'tenant_uuid' => $tenant,
                    'idempotency_key' => 'idem-pg-shape-1',
                    'request_fingerprint' => str_repeat('b', 64),
                    'order_uuid' => 'pgorderatmp2',
                    'status' => 'pending',
                ]);
                self::fail('a duplicate (tenant_uuid, idempotency_key) must be rejected on real PostgreSQL');
            } catch (\PDOException) {
                $this->addToAssertionCount(1);
            }
        } finally {
            $connection->table('commerce_order_draft_attempts')->where('tenant_uuid', '=', $tenant)->delete();
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove migration convergence is portable.');
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

    /**
     * Every migration EXCEPT the one under test -- the exact pre-upgrade shape a
     * real install would have immediately before this migration ships. Drops
     * `commerce_orders`/`commerce_order_draft_attempts` first (no-op on a fresh
     * SQLite `:memory:` database; necessary on the shared PostgreSQL fixture
     * database, which an earlier run may have already carried through migration
     * 022) so migration 004 genuinely recreates the pre-022 shape.
     *
     * @param array<string,mixed> $config
     */
    private function preMigrationConnection(array $config): Connection
    {
        $connection = new Connection($config + ['pooling' => ['enabled' => false]]);
        $connection->getPDO()->exec('DROP TABLE IF EXISTS commerce_order_draft_attempts');
        $connection->getPDO()->exec('DROP TABLE IF EXISTS commerce_orders');
        $schema = $connection->getSchemaBuilder();
        foreach (self::MIGRATIONS as $migration) {
            if ($migration === AddWalkInOrderFieldsAndDraftAttemptLedger::class) {
                continue;
            }
            (new $migration())->up($schema);
        }

        return $connection;
    }

    private function migratedPgConnection(): Connection
    {
        $connection = new Connection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();
        foreach (self::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        return $connection;
    }
}
