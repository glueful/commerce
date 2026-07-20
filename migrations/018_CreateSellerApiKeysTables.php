<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Marketplace MV5c-1 seller API keys foundation (design spec §2.2/§2.10/§3):
 * three tenant-scoped tables backing a seller's OWN scoped machine
 * credentials, reusing the framework's `Glueful\Auth\ApiKey\ApiKeyService`
 * for generation/hashing/verification/rotation/revocation while Commerce
 * owns only the binding + authorization semantics.
 *
 * `commerce_seller_api_keys` is one logical, seller-visible key LINEAGE
 * (design spec §2.2) -- its `uuid` IS the stable lineage identity, there is
 * no separate `lineage_uuid` column on this table. It owns tenant/seller/
 * subject/scopes/name/status/expiry, a mutation `revision` (optimistic
 * concurrency for the §2.9 lock order), and a pointer to the current
 * Commerce credential row.
 *
 * `commerce_seller_api_key_credentials` is one row PER GENERATED FRAMEWORK
 * KEY (design spec §2.2): it maps `framework_key_uuid` directly to the
 * lineage and records generation/relationship/grace, so the current key and
 * any still-valid grace predecessor resolve by one indexed lookup on the
 * exact authenticated `api_key_uuid` -- Commerce never walks the framework's
 * forward-only `rotated_from_id` chain.
 *
 * `commerce_seller_api_key_events` is the append-only audit (design spec
 * §2.10): key creation, rotation, revocation, and bound-key authorization
 * denials, actor/subject/lineage/predecessor/successor framework-key uuids,
 * grace expiry, seller, closed reason code, timestamp -- NEVER a secret. The
 * composite unique `(tenant_uuid, lineage_uuid, action, reason_code,
 * bucket_start)` is populated ONLY for `auth_denied` rows (permanent
 * mutation events -- `created`/`rotated`/`revoked` -- always leave
 * `reason_code`/`bucket_start` NULL). This relies on standard ANSI SQL
 * unique-index semantics (NULL is never equal to NULL, so a NULL-bearing
 * column is exempt from the constraint) being portable across every driver
 * this framework targets -- the SAME null-exempt-unique idiom already
 * proven by `commerce_seller_reserves.idempotency_key`/`seller_order_uuid`
 * (migration 015) and `commerce_downloads`/`commerce_download_grants`
 * (migration 008): multiple permanent events with null `reason_code`/
 * `bucket_start` coexist freely, while two `auth_denied` rows sharing the
 * same (tenant, lineage, reason, minute-bucket) collide -- see
 * {@see \Glueful\Extensions\Commerce\Tests\Integration\Migrations\SellerApiKeyShapeTest}.
 *
 * Every index/unique below is given an EXPLICIT short name (never the
 * schema builder's auto-generated form) -- `commerce_seller_api_key_credentials`
 * is long enough that several auto-generated multi-column names would
 * approach or exceed PostgreSQL's 63-byte NAMEDATALEN limit (see
 * `CreateSellerLifecycleEventsTable`'s identical note for its own index).
 *
 * Marketplace migrations 010-018 first publish together in Commerce 1.2.0,
 * so all three tables here are genuinely NEW -- nothing existing to fold
 * into.
 */
final class CreateSellerApiKeysTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_seller_api_keys')) {
            $schema->createTable('commerce_seller_api_keys', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                // The stable lineage identity (design spec §2.2) -- NOT a
                // duplicate uuid + lineage_uuid pair.
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('seller_uuid', 12);
                // The seller-member authorized to authenticate through this
                // lineage (design spec §2.3 step 2) -- rotation never
                // changes this (design spec §2.9).
                $table->string('subject_user_uuid', 12);
                // Canonical JSON array of exact grantable capability slugs
                // (design spec §2.5) -- never a wildcard, never empty.
                $table->text('declared_scopes');
                $table->string('name', 120);
                // active|revoked (design spec §2.9).
                $table->string('status', 16)->default('active');
                $table->string('current_credential_uuid', 12);
                $table->timestamp('expires_at')->nullable();
                // Optimistic-concurrency claim for the design spec §2.9 lock
                // order (seller revision -> actor re-read -> lineage revision).
                $table->integer('revision')->default(0);
                $table->string('created_by', 12);
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('last_rotated_at')->nullable();
                $table->timestamp('revoked_at')->nullable();

                $table->unique(['tenant_uuid', 'uuid'], 'commerce_seller_api_keys_tenant_uuid_unique');
                $table->unique(
                    ['tenant_uuid', 'current_credential_uuid'],
                    'commerce_seller_api_keys_current_credential_unique'
                );
                // Self-service list scan (design spec §2.8): tenant-bound,
                // per-seller, filterable by status.
                $table->index(
                    ['tenant_uuid', 'seller_uuid', 'status'],
                    'commerce_seller_api_keys_seller_status_index'
                );
            });
        }

        if (!$schema->hasTable('commerce_seller_api_key_credentials')) {
            $schema->createTable('commerce_seller_api_key_credentials', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('lineage_uuid', 12);
                // Matches the framework's `api_keys.uuid` (design spec §2.2) --
                // the exact identity the framework authenticates and returns as
                // the request's `api_key_uuid` attribute.
                $table->string('framework_key_uuid', 12);
                $table->integer('generation');
                // current|predecessor|revoked (design spec §2.2/§2.9).
                $table->string('relationship', 16);
                $table->timestamp('grace_expires_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('revoked_at')->nullable();

                $table->unique(['tenant_uuid', 'uuid'], 'commerce_seller_api_key_credentials_tenant_uuid_unique');
                // The exact-credential lookup the per-request authorizer runs
                // on every API-key request (design spec §2.3 step 1).
                $table->unique(
                    ['tenant_uuid', 'framework_key_uuid'],
                    'commerce_seller_api_key_credentials_framework_key_unique'
                );
                $table->unique(
                    ['tenant_uuid', 'lineage_uuid', 'generation'],
                    'commerce_seller_api_key_credentials_lineage_generation_unique'
                );
                // Whole-lineage revocation scan (design spec §2.9): enumerate
                // every credential row for a lineage.
                $table->index(
                    ['tenant_uuid', 'lineage_uuid'],
                    'commerce_seller_api_key_credentials_lineage_index'
                );
                // Grace-window resolution (design spec §2.2): current +
                // still-valid predecessor rows for a lineage.
                $table->index(
                    ['tenant_uuid', 'lineage_uuid', 'relationship'],
                    'commerce_seller_api_key_credentials_lineage_rel_index'
                );
            });
        }

        if (!$schema->hasTable('commerce_seller_api_key_events')) {
            $schema->createTable('commerce_seller_api_key_events', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('lineage_uuid', 12);
                $table->string('seller_uuid', 12);
                $table->string('subject_user_uuid', 12);
                // created|rotated|revoked|auth_denied (design spec §2.10).
                $table->string('action', 16);
                $table->string('actor_uuid', 12)->nullable();
                // Closed vocabulary (design spec §2.10): principal_mismatch |
                // scope_drift | scope_missing | seller_mismatch |
                // membership_inactive | seller_inactive | capability_denied.
                // Populated ONLY for `auth_denied` rows.
                $table->string('reason_code', 48)->nullable();
                // UTC-minute dedupe backstop for `auth_denied` (design spec
                // §2.10) -- populated ONLY for `auth_denied` rows, alongside
                // reason_code.
                $table->timestamp('bucket_start')->nullable();
                $table->string('predecessor_key_uuid', 12)->nullable();
                $table->string('successor_key_uuid', 12)->nullable();
                $table->timestamp('grace_expires_at')->nullable();
                $table->text('detail')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique(['tenant_uuid', 'uuid'], 'commerce_seller_api_key_events_tenant_uuid_unique');
                // `auth_denied` dedupe backstop (design spec §2.10): at most
                // one denial row per (tenant, lineage, reason, UTC-minute).
                // NULL-exempt on every driver (see the class docblock above),
                // so permanent mutation events (reason_code/bucket_start both
                // NULL) never collide against each other or this constraint.
                $table->unique(
                    ['tenant_uuid', 'lineage_uuid', 'action', 'reason_code', 'bucket_start'],
                    'commerce_seller_api_key_events_dedupe_unique'
                );
                // Self-service/operator audit-history scan (design spec §2.10):
                // tenant-bound, per-seller, ordered by created_at.
                $table->index(
                    ['tenant_uuid', 'seller_uuid', 'created_at'],
                    'commerce_seller_api_key_events_seller_created_index'
                );
                // Retention-cleanup sweep (design spec §2.10): due auth_denied
                // rows by created_at, across all tenants.
                $table->index(
                    ['action', 'created_at'],
                    'commerce_seller_api_key_events_action_created_index'
                );
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_seller_api_key_events');
        $schema->dropTableIfExists('commerce_seller_api_key_credentials');
        $schema->dropTableIfExists('commerce_seller_api_keys');
    }

    public function getDescription(): string
    {
        return 'Creates the commerce_seller_api_keys, commerce_seller_api_key_credentials, and '
            . 'commerce_seller_api_key_events tables for MV5c-1 seller API keys.';
    }
}
