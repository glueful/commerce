<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Rider 2 (admin-order-creation cycle 2, Task 2) hygiene fix: migration 002 declared
 * `commerce_stock.quantity` and `commerce_stock.tracked` nullable at the DB level even
 * though neither has ever had a legitimate NULL meaning. Backfilling any existing NULL
 * rows to their intended defaults (0 / false) before constraining NOT NULL is
 * EQUIVALENT, for two specific read paths, to what already held at runtime:
 * {@see \Glueful\Extensions\Commerce\Inventory\StockRepository::isTracked()} folds a
 * NULL `tracked` to `false` (`(int) ($row['tracked'] ?? 0) === 1`), and
 * {@see \Glueful\Extensions\Commerce\Inventory\StockRepository::quantity()} folds a
 * NULL `quantity` to `0` (`(int) ($row['quantity'] ?? 0)`) -- for those two methods
 * alone, this migration changes nothing observable.
 *
 * It is NOT equivalence-preserving everywhere, and that divergence is deliberate, not
 * an oversight -- eliminating exactly these NULL states is what this rider is for:
 *  - {@see \Glueful\Extensions\Commerce\Inventory\StockRepository::stockProjectionsForProduct()}/
 *    `stockProjectionsForProducts()` select `commerce_stock.tracked`/`.quantity` RAW
 *    (not coalesced), so a stock row that EXISTS but carries a NULL value is today
 *    indistinguishable, to that projection, from a MISSING row.
 *    {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::stockForProduct()}
 *    treats that as an integrity failure and throws
 *    {@see \Glueful\Extensions\Commerce\Inventory\StockIntegrityException} (bubbling to
 *    a 500); {@see \Glueful\Extensions\Commerce\Http\Admin\AdminProductController}'s
 *    list summary reports `stock_quantity: null` ("unknown"). After this migration,
 *    the SAME row reads as a legitimate, healthy untracked/zero-quantity variant --
 *    the exception stops firing and the summary reports a real number. That is the
 *    healing working as intended: a corrupt-looking row becomes a genuinely healthy
 *    one, on purpose.
 *  - {@see \Glueful\Extensions\Commerce\Inventory\StockRepository::increment()}/
 *    `incrementChecked()` do a raw `quantity = quantity + ?` UPDATE. SQL NULL
 *    propagates through `+`, so against a NULL `quantity` the value silently stays
 *    NULL no matter how many times either method is called, even though the UPDATE
 *    itself reports success (matches the row, `incrementChecked()` still returns
 *    `true` when `tracked` matches). After this migration the backfilled `0` lets the
 *    SAME arithmetic actually accumulate.
 *
 * Both divergences are pinned by `tests/Integration/Migrations/StockNotNullBackfillTest.php`.
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
