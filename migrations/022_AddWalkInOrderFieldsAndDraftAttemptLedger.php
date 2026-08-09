<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Admin-order-creation cycle 2, Task 6 (design spec §2.6): schema for
 * admin-created ("walk-in") orders, plus the finalize idempotency ledger.
 *
 * `commerce_orders` relaxations -- three columns that were NOT NULL purely
 * because every pre-existing order came through storefront checkout, which
 * always has an email, always mints a guest-access credential, and always
 * assigns a number at placement time:
 *  - `order_number` becomes nullable. A draft admin order has no number until
 *    finalize allocates one. The EXISTING `(tenant_uuid, order_number)` unique
 *    index is left untouched -- SQLite and PostgreSQL both treat NULL as
 *    distinct-from-NULL in a unique index (so many draft rows may coexist),
 *    while a duplicate non-null value for the same tenant is still rejected.
 *  - `email` becomes nullable. Ruling 4: a walk-in customer may be fully
 *    anonymous; no placeholder email is ever invented.
 *  - `guest_token_hash` becomes nullable. An admin-created order mints no
 *    guest-access credential -- {@see \Glueful\Extensions\Commerce\Http\Storefront\OrderController::tokenMatches()}
 *    already treats a NULL value as "no credential" via PHP `isset()`
 *    (false for a NULL array value), so a NULL row here grants no guest
 *    access with no code change required.
 *
 * `commerce_orders` additions:
 *  - `phone_normalized` (nullable) / `phone_display` (nullable, <=32 chars):
 *    the canonical E.164 form and the trimmed operator input respectively.
 *    Phone is contact information only -- never an identity/access lookup.
 *  - `customer_name` (nullable): free-text walk-in customer name.
 *  - `origin` (NOT NULL, `storefront|admin`): every pre-existing row is a
 *    storefront order by construction, so the backfill default is exact
 *    historical truth, not an approximation.
 *  - `fulfillment_mode` (NOT NULL, `in_store|delivery`): backfilled
 *    `delivery` for every pre-existing row. This is a conservative
 *    compatibility label ("not eligible for automatic in-store completion"),
 *    not a factual claim that every historical order actually shipped.
 *  - `draft_revision` (int NOT NULL default 0): the CAS counter for draft
 *    customer/line/shipping mutations (finalize itself is a separate,
 *    dedicated CAS -- draft_revision is not read by it). Every pre-existing
 *    (already-finalized) row starts at 0, matching a draft that has never
 *    been mutated.
 *
 * Because these five are brand-new columns rather than existing nullable-with-
 * NULL-rows columns, a single `NOT NULL ... DEFAULT ...` column addition
 * backfills every pre-existing row at the DB level in the same statement --
 * unlike migration 021's two-step backfill-then-constrain (which had to clean
 * up NULLs already sitting in an existing column), there is no separate
 * UPDATE step here.
 *
 * New table `commerce_order_draft_attempts` (engine-owned; the host's
 * `CheckoutAttemptAuthority` is deliberately NOT reused -- its completion
 * shape assumes an order reference plus a raw guest credential that a
 * finalized walk-in order never has). UNIQUE `(tenant_uuid, idempotency_key)`
 * is the SOLE key authority for finalize idempotency -- no `draft_uuid`, no
 * generic `key` column, no guest credential, no separate lookup index. See
 * `Orders\DraftAttemptRepository` for the savepoint-isolated claim/replay
 * mechanics this table exists to support.
 *
 * Both changes are registered in `DiagnosticsReport::commerceTables()` (the
 * tenant purge/adoption inventory) in this same task.
 *
 * SQLite index dance: `unique(['tenant_uuid', 'order_number'])` (migration 004)
 * is an INLINE table constraint, so SQLite names the physical index itself
 * (`sqlite_autoindex_commerce_orders_N`) rather than using the framework's
 * generated name. SQLite has no native `ALTER COLUMN`, so modifying
 * `order_number` goes through the framework's full create-copy-swap rebuild,
 * which fails closed on a composite unique index touching a modified column
 * unless the SAME `alterTable()` call both drops it (by its exact live name)
 * and restates it -- see `SQLiteTableRebuilder::resolveTargetAutoIndexes()`'s
 * own docblock. Because a rebuild also re-creates the index under the
 * framework's OWN generated name (not the original SQLite auto-name), `up()`
 * and `down()` each resolve the CURRENT live name via `PRAGMA index_list`/
 * `PRAGMA index_info` rather than hardcoding either name -- self-healing
 * across an up()-then-down()-then-up() cycle, not just a fresh install.
 * MySQL/PostgreSQL modify `order_number` via a native
 * `ALTER TABLE ... ALTER/MODIFY COLUMN`, which never touches the index at
 * all, so this dance is SQLite-only.
 */
final class AddWalkInOrderFieldsAndDraftAttemptLedger implements MigrationInterface
{
    /** @var list<string> */
    private const NEW_ORDER_COLUMNS = [
        'phone_normalized', 'phone_display', 'customer_name', 'origin', 'fulfillment_mode', 'draft_revision',
    ];

    public function up(SchemaBuilderInterface $schema): void
    {
        $isSqlite = $schema->getConnection()->getDriverName() === 'sqlite';
        $existingIndex = $isSqlite ? $this->tenantOrderNumberUniqueIndexName($schema) : null;
        // Every existing migration in this codebase is safe to call up() against an
        // already-migrated database (hasTable()-guarded createTable, or -- migration
        // 021 -- a naturally idempotent modifyColumn()). Plain `add_columns` calls are
        // NOT naturally idempotent, so each is guarded individually here to preserve
        // that same contract (test helpers across this suite freely re-run the full
        // migration list against a persistent, shared PostgreSQL fixture database).
        $needsColumn = array_combine(
            self::NEW_ORDER_COLUMNS,
            array_map(
                static fn (string $column): bool => !$schema->hasColumn('commerce_orders', $column),
                self::NEW_ORDER_COLUMNS
            )
        );

        $schema->alterTable('commerce_orders', function ($table) use ($isSqlite, $existingIndex, $needsColumn): void {
            if ($existingIndex !== null) {
                $table->dropIndex($existingIndex);
            }

            $table->modifyColumn('order_number')->string(64)->nullable();
            $table->modifyColumn('email')->string(255)->nullable();
            $table->modifyColumn('guest_token_hash')->string(64)->nullable();

            if ($needsColumn['phone_normalized']) {
                $table->string('phone_normalized', 20)->nullable();
            }
            if ($needsColumn['phone_display']) {
                $table->string('phone_display', 32)->nullable();
            }
            if ($needsColumn['customer_name']) {
                $table->string('customer_name', 255)->nullable();
            }
            if ($needsColumn['origin']) {
                $table->string('origin', 16)->notNull()->default('storefront');
            }
            if ($needsColumn['fulfillment_mode']) {
                $table->string('fulfillment_mode', 16)->notNull()->default('delivery');
            }
            if ($needsColumn['draft_revision']) {
                $table->integer('draft_revision')->notNull()->default(0);
            }

            if ($isSqlite) {
                $table->unique(['tenant_uuid', 'order_number']);
            }
        });

        if (!$schema->hasTable('commerce_order_draft_attempts')) {
            $schema->createTable('commerce_order_draft_attempts', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('tenant_uuid', 12)->default('');
                $table->string('idempotency_key', 191);
                $table->string('request_fingerprint', 64);
                $table->string('order_uuid', 12);
                $table->string('status', 16)->default('pending');
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique(['tenant_uuid', 'idempotency_key']);
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_order_draft_attempts');

        $isSqlite = $schema->getConnection()->getDriverName() === 'sqlite';
        $existingIndex = $isSqlite ? $this->tenantOrderNumberUniqueIndexName($schema) : null;

        $schema->alterTable('commerce_orders', function ($table) use ($isSqlite, $existingIndex): void {
            if ($existingIndex !== null) {
                $table->dropIndex($existingIndex);
            }

            $table->dropColumn('draft_revision');
            $table->dropColumn('fulfillment_mode');
            $table->dropColumn('origin');
            $table->dropColumn('customer_name');
            $table->dropColumn('phone_display');
            $table->dropColumn('phone_normalized');

            $table->modifyColumn('guest_token_hash')->string(64)->notNull();
            $table->modifyColumn('email')->string(255)->notNull();
            $table->modifyColumn('order_number')->string(64)->notNull();

            if ($isSqlite) {
                $table->unique(['tenant_uuid', 'order_number']);
            }
        });
    }

    public function getDescription(): string
    {
        return 'Adds walk-in customer/fulfillment columns and draft_revision to commerce_orders '
            . '(with order_number/email/guest_token_hash relaxed to nullable), and creates the '
            . 'commerce_order_draft_attempts finalize-idempotency ledger.';
    }

    /**
     * The LIVE name of the `(tenant_uuid, order_number)` unique index on
     * `commerce_orders`, resolved by column membership rather than assumed from
     * naming history -- it may be SQLite's original `sqlite_autoindex_%` (a fresh
     * install/upgrade that has never run this migration before) or the
     * framework's generated name (a table this migration's `up()`/`down()` has
     * already rebuilt at least once). Returns null only if the table has no such
     * index at all, which never happens for `commerce_orders` (migration 004
     * always declares it) but is handled defensively rather than assumed.
     */
    private function tenantOrderNumberUniqueIndexName(SchemaBuilderInterface $schema): ?string
    {
        $pdo = $schema->getConnection()->getPDO();
        $indexes = $pdo->query("PRAGMA index_list('commerce_orders')")->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($indexes as $index) {
            if ((int) $index['unique'] !== 1) {
                continue;
            }

            $columns = $pdo->query('PRAGMA index_info(' . $pdo->quote((string) $index['name']) . ')')
                ->fetchAll(\PDO::FETCH_ASSOC);
            $names = array_map(static fn (array $c): string => (string) $c['name'], $columns);
            sort($names);

            if ($names === ['order_number', 'tenant_uuid']) {
                return (string) $index['name'];
            }
        }

        return null;
    }
}
