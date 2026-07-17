<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Catalog ownership column (design spec §2.7/§3): a nullable `seller_uuid` on
 * `commerce_products`. Nullable because ordinary (non-marketplace) products
 * never carry a seller, and products created before a workspace activates
 * marketplace mode start unowned until the activation adoption gate (or a
 * dedicated adoption/transfer operation) assigns one -- see
 * `Glueful\Extensions\Commerce\Marketplace\MarketplaceMode`. Commerce is a
 * PUBLISHED extension (1.1.0) -- this is a REAL new migration, never a fold
 * into the original `commerce_products` create-table migration.
 */
final class AddSellerToProducts implements MigrationInterface
{
    private const INDEX_NAME = 'idx_commerce_products_tenant_seller';

    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasColumn('commerce_products', 'seller_uuid')) {
            return;
        }

        $schema->alterTable('commerce_products', function ($table): void {
            $table->string('seller_uuid', 12)->nullable();
            $table->index(['tenant_uuid', 'seller_uuid'], self::INDEX_NAME);
        });
    }

    /**
     * KNOWN FRAMEWORK LIMITATION (verified against the pinned `glueful/framework`
     * dev copy): `SQLiteSqlGenerator::alterTable()` emits only a SQL comment for
     * `drop_columns` -- it never generates a real `ALTER TABLE ... DROP COLUMN`
     * (SQLite's own `DROP COLUMN` support, available since 3.35.0, is simply not
     * wired up there) -- so on SQLite this drops the index but leaves the
     * `seller_uuid` column itself in place; the equivalent MySQL/PostgreSQL
     * generators both emit a real `DROP COLUMN` statement and roll back cleanly.
     * Not a commerce-side bug and not worked around here with a hand-rolled
     * SQLite table-rebuild (out of this migration's scope); this project's own
     * migrations are never rolled back on SQLite in practice (no `down()` is
     * exercised by the test suite), so this only matters for a real
     * `migrate:rollback` against a SQLite-backed install.
     */
    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasColumn('commerce_products', 'seller_uuid')) {
            return;
        }

        $schema->alterTable('commerce_products', function ($table): void {
            $table->dropIndex(self::INDEX_NAME);
            $table->dropColumn('seller_uuid');
        });
    }

    public function getDescription(): string
    {
        return 'Adds nullable seller_uuid catalog-ownership column to commerce_products (MV1 foundation).';
    }
}
