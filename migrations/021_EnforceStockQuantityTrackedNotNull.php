<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Rider 2 (admin-order-creation cycle 2, Task 2) hygiene fix: migration 002 declared
 * `commerce_stock.quantity` and `commerce_stock.tracked` nullable at the DB level even
 * though neither has ever had a legitimate NULL meaning -- every application read
 * already treats a NULL the same as the column's own intended default:
 * {@see \Glueful\Extensions\Commerce\Inventory\StockRepository::isTracked()} folds a
 * NULL `tracked` to `false` (`(int) ($row['tracked'] ?? 0) === 1`), and
 * {@see \Glueful\Extensions\Commerce\Inventory\StockRepository::quantity()} folds a
 * NULL `quantity` to `0` (`(int) ($row['quantity'] ?? 0)`). Backfilling any existing
 * NULL rows to those SAME values before constraining NOT NULL therefore changes
 * nothing observable at runtime -- it only makes the schema say what already held.
 *
 * Backfill-then-constrain, mirroring the discipline documented on
 * `Subscriptions\Database\Migrations\SubjectModel`: the backfill UPDATEs run before the
 * NOT NULL constraint is applied, so a partially-upgraded row is never admitted. Both
 * steps are naturally idempotent -- a fresh install never has a NULL row (
 * {@see \Glueful\Extensions\Commerce\Inventory\StockRepository::ensureRow()} always
 * writes explicit `quantity`/`tracked` values), so the UPDATEs are no-ops there, and a
 * re-run against an already-migrated table converges to the same shape without error.
 *
 * `modifyColumn()` is the framework's fluent alter-column seam (see its own
 * docblock/tests, `Database\Schema\SqliteAlterationCorrectnessTest`): on SQLite it
 * drives a full create-copy-swap table rebuild via `SqliteAlterationPlan`/
 * `SchemaBuilder::executeSqliteRebuild()`; on MySQL/PostgreSQL it compiles to a native
 * `ALTER TABLE ... MODIFY/ALTER COLUMN`. Both paths preserve the original column's
 * default (0 / true, set by migration 002) -- a modifyColumn() replacement is a full
 * redefinition, so the default is re-declared explicitly here rather than relying on
 * whatever the column already had.
 */
final class EnforceStockQuantityTrackedNotNull implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $this->backfillNulls($schema);

        $schema->alterTable('commerce_stock', function ($table): void {
            $table->modifyColumn('quantity')->bigInteger()->notNull()->default(0);
            $table->modifyColumn('tracked')->boolean()->notNull()->default(true);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->alterTable('commerce_stock', function ($table): void {
            $table->modifyColumn('quantity')->bigInteger()->nullable()->default(0);
            $table->modifyColumn('tracked')->boolean()->nullable()->default(true);
        });
    }

    public function getDescription(): string
    {
        return 'Backfills NULL commerce_stock.quantity/tracked to their existing runtime '
            . 'defaults (0 / false) and constrains both columns NOT NULL.';
    }

    /**
     * `FALSE` is a portable boolean literal accepted by SQLite (>= 3.23), MySQL, and
     * PostgreSQL alike -- no driver-specific branch needed.
     */
    private function backfillNulls(SchemaBuilderInterface $schema): void
    {
        $pdo = $schema->getConnection()->getPDO();
        $pdo->exec('UPDATE commerce_stock SET quantity = 0 WHERE quantity IS NULL');
        $pdo->exec('UPDATE commerce_stock SET tracked = FALSE WHERE tracked IS NULL');
    }
}
