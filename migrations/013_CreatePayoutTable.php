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
 *
 * MV4 (design spec §3.1) folds the provider-payout saga columns directly into
 * this still-unreleased table (Commerce v1.1.0 ships only migrations 001-009,
 * so 013 has never shipped) rather than adding a follow-up migration:
 * `status`/`method` distinguish manual vs. provider rows, the
 * provider/provider_ref/destination_ref/failure_* columns carry provider
 * attempt state, `retryable`/`attempt_count`/`last_attempt_at`/
 * `next_attempt_at`/`next_reconcile_at` drive the retry and reconcile sweeps,
 * and `reversed_total`/`completed_at` support partial/full provider-reported
 * reversal. `external_ref` and `created_by` become nullable at the schema
 * level -- a provider row exists before a provider reference is known, and a
 * scheduled batch payout has no human actor -- while the manual `record()`
 * service continues to require both at the SERVICE level.
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
                // §2.10/§3.5, folded per §3.1: the manual `record()` service still
                // requires a non-empty external reference and a non-null operator
                // actor, but a provider-initiated row is written before either is
                // known -- so both are nullable at the schema level. Only `note`
                // was ever optional.
                $table->string('external_ref', 191)->nullable();
                $table->string('note', 255)->nullable();
                $table->string('created_by', 12)->nullable();
                // Deterministic idempotency identity ({payout_uuid}:{seller_uuid}:payout_debit,
                // design spec §2.5); a duplicate (tenant_uuid, idempotency_key) insert
                // verifies both this row and its ledger entry -- never a new row.
                $table->string('idempotency_key', 191);

                // MV4 provider-payout saga columns (design spec §3.1), folded in
                // ahead of release -- see class docblock.
                $table->string('status', 16)->default('paid');
                $table->string('method', 16)->default('manual');
                $table->string('provider', 32)->nullable();
                $table->string('provider_ref', 191)->nullable();
                $table->string('destination_ref', 191)->nullable();
                $table->string('failure_code', 64)->nullable();
                $table->text('failure_reason')->nullable();
                $table->boolean('retryable')->default(false);
                $table->integer('attempt_count')->default(0);
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamp('next_attempt_at')->nullable();
                $table->timestamp('next_reconcile_at')->nullable();
                $table->bigInteger('reversed_total')->default(0);

                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('completed_at')->nullable();

                $table->unique(['tenant_uuid', 'idempotency_key']);
                $table->unique(['tenant_uuid', 'uuid']);
                $table->index(['tenant_uuid', 'seller_uuid', 'currency']);
                // MV4 retry/reconcile sweep indexes (§3.1): the CLI sweeps scan for
                // due rows by (tenant, status, next_*_at) -- explicit short names
                // avoid the auto-generated-name pattern's 63-byte PostgreSQL
                // NAMEDATALEN risk (see CreateSellerOrderTables's identical note).
                $table->index(
                    ['tenant_uuid', 'status', 'next_attempt_at'],
                    'commerce_payouts_status_next_attempt_index'
                );
                $table->index(
                    ['tenant_uuid', 'status', 'next_reconcile_at'],
                    'commerce_payouts_status_next_reconcile_index'
                );
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_payouts');
    }

    public function getDescription(): string
    {
        return 'Creates the commerce_payouts table for MV3 manual and MV4 provider payouts.';
    }
}
