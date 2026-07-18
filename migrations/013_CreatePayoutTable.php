<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Marketplace MV3 manual payouts (design spec §2.10/§3.5): the durable record
 * of an operator-confirmed, Commerce-local manual payout, written atomically
 * alongside its matching `payout_debit` ledger entry
 * ({@see CreateMarketplaceLedgerTables}). A genuinely new table -- there is
 * nothing existing to fold into.
 */
final class CreatePayoutTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_payouts')) {
            $schema->createTable('commerce_payouts', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('seller_uuid', 12);
                $table->string('currency', 3);
                // Service validation requires amount > 0 on every driver; the CHECK
                // below is defense in depth on PostgreSQL/SQLite only (the framework's
                // MySQL generator omits ColumnBuilder::check()).
                $table->bigInteger('amount')->check('amount > 0');
                // §2.10/§3.5: a payout requires a non-empty external reference and a
                // non-null operator actor -- DB NOT NULL is the defense-in-depth backstop
                // behind PayoutService's service validation. Only `note` is optional.
                $table->string('external_ref', 191);
                $table->string('note', 255)->nullable();
                $table->string('created_by', 12);
                // Deterministic idempotency identity ({payout_uuid}:{seller_uuid}:payout_debit,
                // design spec §2.5); a duplicate (tenant_uuid, idempotency_key) insert
                // verifies both this row and its ledger entry -- never a new row.
                $table->string('idempotency_key', 191);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique(['tenant_uuid', 'idempotency_key']);
                $table->unique(['tenant_uuid', 'uuid']);
                $table->index(['tenant_uuid', 'seller_uuid', 'currency']);
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_payouts');
    }

    public function getDescription(): string
    {
        return 'Creates the commerce_payouts table for MV3 manual operator payouts.';
    }
}
