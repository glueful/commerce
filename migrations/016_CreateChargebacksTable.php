<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Marketplace MV5a chargeback foundation (design spec §2.4-§2.5/§2.10,
 * §3.3): the durable, idempotent `commerce_chargebacks` provider-event
 * record and its `commerce_chargeback_lines` attribution rows. Both are
 * genuinely new tables. Marketplace migrations 010-016 first publish
 * together in Commerce 1.2.0, so this is a REAL new migration, never a fold.
 *
 * `commerce_chargebacks.kind` (`chargeback | reversal`) means a later
 * provider-reported reversal / dispute win (design spec §2.10) is a SEPARATE
 * row in this SAME table, never a mutation of the original -- correlated via
 * the nullable `related_chargeback_uuid`.
 */
final class CreateChargebacksTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_chargebacks')) {
            $schema->createTable('commerce_chargebacks', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('provider', 32);
                $table->string('provider_event_id', 191);
                // The disputed charge/order -- Payvia's durable payment reference
                // (design spec §2.4).
                $table->string('payment_reference', 191);
                $table->string('order_uuid', 12)->nullable();
                $table->bigInteger('amount');
                $table->string('currency', 3);
                $table->string('reason_code', 64)->nullable();
                $table->timestamp('occurred_at');
                // `chargeback` (default) | `reversal` -- design spec §2.10: a
                // reversal shares this table rather than a mutation of the original.
                $table->string('kind', 16)->default('chargeback');
                // A reversal points at its original chargeback (design spec §2.10);
                // null for an ordinary `chargeback` row.
                $table->string('related_chargeback_uuid', 12)->nullable();
                // `received` (default) | `awaiting_attribution` | `posted` |
                // `integrity_hold` -- design spec §2.4/§2.5.
                $table->string('status', 24)->default('received');
                $table->timestamp('posted_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                // Idempotency authority (design spec §2.4, load-bearing ingestion
                // order): the insert here is the FIRST write of the processing
                // transaction, and a conflict on this key is re-read and verified
                // exactly, never silently skipped.
                $table->unique(
                    ['tenant_uuid', 'provider', 'provider_event_id'],
                    'commerce_chargebacks_provider_event_unique'
                );
                $table->index(['tenant_uuid', 'order_uuid'], 'commerce_chargebacks_tenant_order_index');
                // Explicit non-posted-state repair scan (design spec §2.4).
                $table->index(['tenant_uuid', 'status'], 'commerce_chargebacks_tenant_status_index');
            });
        }

        if (!$schema->hasTable('commerce_chargeback_lines')) {
            $schema->createTable('commerce_chargeback_lines', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('chargeback_uuid', 12);
                $table->string('order_line_uuid', 12);
                $table->string('seller_uuid', 12);
                $table->bigInteger('amount');
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                // Durable attribution authority (design spec §2.5): one row per
                // (chargeback, order line) -- the operator-supplied partial-event
                // rows and the persisted full-event auto-expansion result alike.
                $table->unique(
                    ['tenant_uuid', 'chargeback_uuid', 'order_line_uuid'],
                    'commerce_chargeback_lines_chargeback_order_line_unique'
                );
                $table->unique(['tenant_uuid', 'uuid'], 'commerce_chargeback_lines_tenant_uuid_unique');
                $table->index(['tenant_uuid', 'seller_uuid'], 'commerce_chargeback_lines_tenant_seller_index');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_chargeback_lines');
        $schema->dropTableIfExists('commerce_chargebacks');
    }

    public function getDescription(): string
    {
        return 'Creates the commerce_chargebacks and commerce_chargeback_lines tables for MV5a chargebacks.';
    }
}
