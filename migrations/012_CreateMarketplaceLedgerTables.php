<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Marketplace MV3 settlement-ledger foundation (design spec §2.5/§2.6/§2.3,
 * §3.2-§3.4): the append-only per-account ledger, the minimal (balance-less)
 * account-posting lock anchor table, and the durable commission-policy-change
 * audit trail. All three are genuinely new tables -- unlike the commission
 * columns folded into migrations 001/004/010/011, there is nothing existing
 * to fold into here.
 *
 * All three declare `tenant_uuid` `string(12)->default('')` -- the
 * tenant-adopt sentinel every existing tenant-scoped commerce table follows
 * (design spec §3.7): `TenantAdopter` rekeys rows `WHERE tenant_uuid = ''`,
 * so without the empty-string default the adopt sweep would silently match
 * nothing.
 *
 * MV5a (design spec §2.5/§2.9/§3.4) folds nullable `reserve_uuid`/
 * `chargeback_uuid` correlation columns directly into the still-unreleased
 * `commerce_marketplace_ledger`: every rolling/manual risk `reserve_hold`/
 * `reserve_release` entry carries `reserve_uuid`, and every chargeback
 * `chargeback_debit`/`chargeback_credit` (plus its paired commission entry)
 * carries `chargeback_uuid`. {@see \Glueful\Extensions\Commerce\Marketplace\LedgerRepository}'s
 * insert row and replay-verify allowlist are expanded from 12 to 14
 * immutable fields in the same task -- see `LedgerRepository::VERIFIED_FIELDS`.
 */
final class CreateMarketplaceLedgerTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_marketplace_ledger')) {
            $schema->createTable('commerce_marketplace_ledger', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                // Account identity (design spec §2.5): canonical non-null account_key
                // ('seller:{uuid}' | 'marketplace') plus the explicit queryable
                // account_kind/seller_uuid pair -- service validation always enforces
                // their bidirectional relationship; the CHECK below is defense in depth
                // on PostgreSQL/SQLite only (the framework's MySQL generator omits
                // ColumnBuilder::check()).
                $table->string('account_key', 32);
                $table->string('account_kind', 12)->check(
                    "(account_kind = 'seller' AND seller_uuid IS NOT NULL "
                    . "AND account_key = 'seller:' || seller_uuid) "
                    . "OR (account_kind = 'marketplace' AND seller_uuid IS NULL "
                    . "AND account_key = 'marketplace')"
                );
                $table->string('seller_uuid', 12)->nullable();
                $table->string('currency', 3);
                // Closed entry_type vocabulary (design spec §2.5 table); amount is a
                // SIGNED bigint in minor units -- no ->unsigned().
                $table->string('entry_type', 24);
                $table->bigInteger('amount');
                $table->string('order_uuid', 12)->nullable();
                $table->string('seller_order_uuid', 12)->nullable();
                $table->string('refund_uuid', 12)->nullable();
                $table->string('payout_uuid', 12)->nullable();
                // MV5a correlation columns (design spec §2.5/§2.9/§3.4), folded in
                // ahead of release -- see class docblock. Both nullable: the vast
                // majority of entry types (sale_credit, commission_debit,
                // refund_debit, payout_debit, adjustment, ...) carry neither.
                $table->string('reserve_uuid', 12)->nullable();
                $table->string('chargeback_uuid', 12)->nullable();
                // Deterministic idempotency identity (design spec §2.5): a duplicate
                // (tenant_uuid, idempotency_key) insert is a verify, never a new row.
                $table->string('idempotency_key', 191);
                $table->string('reason', 255)->nullable();
                $table->string('created_by', 12)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique(['tenant_uuid', 'idempotency_key']);
                $table->index(
                    ['tenant_uuid', 'account_key', 'currency'],
                    'commerce_ledger_account_key_currency_index'
                );
                $table->index(
                    ['tenant_uuid', 'account_kind', 'seller_uuid', 'currency'],
                    'commerce_ledger_account_kind_seller_currency_index'
                );
                $table->index('order_uuid');
                $table->index('refund_uuid');
                $table->index('payout_uuid');
                $table->index('reserve_uuid');
                $table->index('chargeback_uuid');
            });
        }

        if (!$schema->hasTable('commerce_ledger_account_locks')) {
            $schema->createTable('commerce_ledger_account_locks', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('tenant_uuid', 12)->default('');
                $table->string('account_key', 32);
                $table->string('currency', 3);
                // Minimal anchor row: identity + revision only, NO balance columns --
                // balances stay a derived SUM over the ledger (design spec §2.6). The
                // affected-row-checked revision bump is claimed by every
                // balance-affecting posting, savepoint-guarded lazy-created on first
                // claim (the MV1 MarketplaceWorkspaceLock idiom).
                $table->bigInteger('revision')->default(0);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique(
                    ['tenant_uuid', 'account_key', 'currency'],
                    'commerce_ledger_account_locks_key_currency_unique'
                );
            });
        }

        if (!$schema->hasTable('commerce_commission_policy_events')) {
            $schema->createTable('commerce_commission_policy_events', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                // Durable, append-only audit record for every commission-policy mutation
                // (design spec §2.3): actor, subject (level + UUID), and exact typed
                // before/after snapshots. Rows are never updated or deleted; the policy
                // write and this audit insert share one transaction.
                $table->string('subject_kind', 16);
                $table->string('subject_uuid', 12);
                $table->string('actor_uuid', 12);
                $table->json('before_policy');
                $table->json('after_policy');
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique(['tenant_uuid', 'uuid']);
                $table->index(
                    ['tenant_uuid', 'subject_kind', 'subject_uuid', 'created_at'],
                    'commerce_commission_events_subject_created_index'
                );
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_commission_policy_events');
        $schema->dropTableIfExists('commerce_ledger_account_locks');
        $schema->dropTableIfExists('commerce_marketplace_ledger');
    }

    public function getDescription(): string
    {
        return 'Creates the marketplace settlement ledger, account-posting locks, '
            . 'and commission-policy audit event tables (MV3 foundation).';
    }
}
