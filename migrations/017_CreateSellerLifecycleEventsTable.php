<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Marketplace MV5b seller-lifecycle audit foundation (design spec §2.2/§3):
 * the durable, append-only `commerce_seller_lifecycle_events` row written in
 * the SAME transaction as every suspend/reactivate/close status write on
 * `commerce_sellers`, mirroring the MV5a `commerce_reserve_policy_events`
 * audit idiom (migration 015) rather than the commission-specific table
 * itself. This is the authoritative reason+actor history -- `commerce_sellers`
 * keeps only `status` (design spec §2.2, no denormalized reason column).
 *
 * Marketplace migrations 010-017 first publish together in Commerce 1.2.0, so
 * this is a genuinely NEW table -- nothing existing to fold into.
 *
 * `actor_uuid` is NOT NULL (design spec §3): the schema enforces the §2.1
 * mandatory-actor requirement directly instead of relying only on service
 * validation. Every other column stays schema-nullable (no DB-level
 * enforcement) -- `reason` mandatory-non-empty is a request/service-layer
 * validation (§2.1, 422 before any write), not a DB constraint, matching the
 * `commerce_seller_reserves.reason` precedent in migration 015.
 */
final class CreateSellerLifecycleEventsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_seller_lifecycle_events')) {
            $schema->createTable('commerce_seller_lifecycle_events', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('seller_uuid', 12);
                $table->string('from_status', 16);
                $table->string('to_status', 16);
                // Design spec §2.1/§3: the $actor param is now threaded to the audit
                // and mandatory -- enforced at the schema level, not just service
                // validation.
                $table->string('actor_uuid', 12)->notNullable();
                $table->string('reason', 255)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique(
                    ['tenant_uuid', 'uuid'],
                    'commerce_seller_lifecycle_events_tenant_uuid_unique'
                );
                // Operator lifecycle-history read scan (design spec §4): tenant-bound,
                // paginated, ordered `created_at DESC, id DESC` per seller. Short
                // explicit name -- the auto-generated
                // `commerce_seller_lifecycle_events_tenant_uuid_seller_uuid_created_at_index`
                // form exceeds PostgreSQL's 63-byte NAMEDATALEN limit (see
                // CreateSellerReservesTable's identical note for its FIFO index).
                $table->index(
                    ['tenant_uuid', 'seller_uuid', 'created_at'],
                    'commerce_seller_lifecycle_events_seller_created_index'
                );
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_seller_lifecycle_events');
    }

    public function getDescription(): string
    {
        return 'Creates the commerce_seller_lifecycle_events table for MV5b seller-lifecycle audit.';
    }
}
