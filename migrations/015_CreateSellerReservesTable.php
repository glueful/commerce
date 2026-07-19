<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Marketplace MV5a rolling-reserve foundation (design spec §2.1-§2.3/§2.8,
 * §3.2): the durable per-hold `commerce_seller_reserves` row (rolling,
 * settlement-created, or manual/operator-created) and the append-only
 * `commerce_reserve_policy_events` audit trail for workspace/seller reserve
 * policy mutations (the `commerce_commission_policy_events` pattern from
 * migration 012, not that commission-specific table itself). Both are
 * genuinely new tables -- nothing existing to fold into. Marketplace
 * migrations 010-016 first publish together in Commerce 1.2.0, so this is a
 * REAL new migration, never a fold.
 *
 * `commerce_seller_reserves` never carries a mutable running balance -- the
 * remaining amount of a hold is always DERIVED as
 * `max(0, -SUM(reserve_hold + reserve_release))` over
 * `commerce_marketplace_ledger` rows carrying its `reserve_uuid` (§3.2). This
 * row is the durable identity/policy-snapshot/lifecycle anchor only.
 */
final class CreateSellerReservesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_seller_reserves')) {
            $schema->createTable('commerce_seller_reserves', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('seller_uuid', 12);
                $table->string('currency', 3);
                // `rolling` (auto-created at settlement) | `manual` (operator-created,
                // design spec §2.8). Discriminates which of the two nullable columns
                // immediately below is populated.
                $table->string('source_kind', 16);
                // Required for `rolling` (one hold per settled seller-order), null
                // for `manual`.
                $table->string('seller_order_uuid', 12)->nullable();
                // Required for `manual` (the caller `Idempotency-Key`, design spec
                // §2.8), null for `rolling` (which is idempotent via the ledger
                // entry's own `{order_uuid}:{seller_uuid}:reserve_hold` key instead).
                $table->string('idempotency_key', 128)->nullable();
                // Original held amount, minor units. Service validation requires
                // amount > 0 on every driver; the CHECK below is defense in depth on
                // PostgreSQL/SQLite only (the framework's MySQL generator omits
                // ColumnBuilder::check()).
                $table->bigInteger('amount')->check('amount > 0');
                // The resolved policy at creation time, snapshotted and never
                // recomputed (design spec §2.1). `0`/`0` for manual holds.
                $table->integer('reserve_bps_snapshot');
                $table->integer('reserve_days_snapshot');
                $table->string('status', 16)->default('held');
                $table->timestamp('held_at');
                // Required for rolling (confirmed_at + reserve_days), null for an
                // indefinite manual hold (design spec §2.8) that never auto-releases.
                $table->timestamp('release_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->string('created_by', 12)->nullable();
                $table->string('reason', 255)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                // Rolling-hold uniqueness backstop (service-enforced primarily; the
                // nullable seller_order_uuid means manual rows -- all null there --
                // stay exempt from this constraint on every driver).
                $table->unique(
                    ['tenant_uuid', 'seller_order_uuid', 'seller_uuid'],
                    'commerce_seller_reserves_order_seller_unique'
                );
                // Manual-hold replay arbiter (design spec §2.8): nullable rolling
                // rows -- all null there -- stay exempt.
                $table->unique(
                    ['tenant_uuid', 'idempotency_key'],
                    'commerce_seller_reserves_idempotency_unique'
                );
                $table->unique(['tenant_uuid', 'uuid'], 'commerce_seller_reserves_tenant_uuid_unique');
                // Release-sweep scan (design spec §2.3): due `held` rows by release_at.
                $table->index(
                    ['tenant_uuid', 'status', 'release_at'],
                    'commerce_seller_reserves_status_release_index'
                );
                // FIFO consumption scan (design spec §2.5): earliest-release_at-first
                // per seller/currency.
                $table->index(
                    ['tenant_uuid', 'seller_uuid', 'currency', 'status', 'release_at'],
                    'commerce_seller_reserves_fifo_index'
                );
            });
        }

        if (!$schema->hasTable('commerce_reserve_policy_events')) {
            $schema->createTable('commerce_reserve_policy_events', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                // `workspace` | `seller` -- the level the policy mutation applied to
                // (design spec §2.1/§3.2).
                $table->string('subject_kind', 16);
                $table->string('subject_uuid', 12);
                $table->string('actor_uuid', 12);
                $table->json('before_policy');
                $table->json('after_policy');
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique(['tenant_uuid', 'uuid'], 'commerce_reserve_policy_events_tenant_uuid_unique');
                $table->index(
                    ['tenant_uuid', 'subject_kind', 'subject_uuid', 'created_at'],
                    'commerce_reserve_events_subject_created_index'
                );
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_reserve_policy_events');
        $schema->dropTableIfExists('commerce_seller_reserves');
    }

    public function getDescription(): string
    {
        return 'Creates the commerce_seller_reserves and commerce_reserve_policy_events '
            . 'tables for MV5a rolling reserves.';
    }
}
