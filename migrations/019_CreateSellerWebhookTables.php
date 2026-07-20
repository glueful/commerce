<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Marketplace MV5c-2 seller outbound-webhooks foundation (design spec
 * §2.2-§2.10/§3): five tenant-scoped tables backing a seller's OWN
 * registered webhook endpoints, reusing the framework's
 * `Glueful\Api\Webhooks\WebhookSignature`, `Glueful\Encryption\EncryptionService`,
 * and the new `SafeOutboundTargetResolver`/`safeWebhookRequestAsync()` seam
 * (framework 1.71.0) for signing/encryption/SSRF-safe delivery while Commerce
 * owns only the endpoint/secret/event/delivery/audit data and lifecycle.
 *
 * `commerce_seller_webhook_endpoints` is one seller-registered HTTPS
 * destination (design spec §2.2): tenant/seller/url/subscribed-events, a
 * `status` (`active|disabled|deleted`), a mutation `revision` (optimistic
 * concurrency for the §2.10 management lock order), `consecutive_failures`
 * (auto-disable counter, §2.7), and the disable/delete tombstone fields.
 *
 * `commerce_seller_webhook_secrets` holds the endpoint's signing secret(s)
 * (design spec §2.2), encrypted at rest with the framework `EncryptionService`
 * and AAD-bound to `tenant_uuid:endpoint_uuid:secret_uuid`. An endpoint has
 * exactly one `current` secret and at most one unexpired `previous` secret
 * during a rotation overlap window -- a service/repository invariant, not a
 * schema-level constraint (no partial-unique-index support across every
 * driver this framework targets). `secret_fingerprint` is a NON-secret,
 * OPTIONAL correlation value for support -- never sufficient to sign or
 * verify a payload.
 *
 * `commerce_seller_webhook_events` is the durable, immutable snapshot (design
 * spec §2.3-§2.4): the exact canonical payload BYTES that get signed and
 * sent, keyed by `uuid` (= `event_id`, the receiver dedup key). Written
 * transactionally alongside the authoritative business transition that
 * produced it -- see `SellerWebhookOutboxPublisher` (a later task).
 *
 * `commerce_seller_webhook_deliveries` is one per-endpoint delivery attempt
 * lineage against one event snapshot (design spec §2.4/§2.7/§2.9): retry/
 * backoff bookkeeping (`attempts`, `next_attempt_at`), the crash-safe claim
 * lease (`claim_token`/`claim_expires_at`), the suspend/close pause fields
 * (`paused_at`/`paused_remaining_seconds`/`pause_reason`), and `replay_of_uuid`
 * linking a replay attempt back to the delivery it replays (append-only
 * lineage -- historical attempts are never mutated). Deliberately carries NO
 * `unique(tenant_uuid, uuid)` constraint of its own (design spec §3
 * verbatim) -- the four indexes below are the complete, spec-pinned index
 * set for this high-write-volume table.
 *
 * `commerce_seller_webhook_endpoint_events` is the append-only management
 * audit trail (design spec §2.10): register/url_change/secret_rotate/
 * disable/enable/auto_disable/delete, actor/reason/detail. NEVER a secret.
 *
 * Every index/unique below is given an EXPLICIT short name (never the schema
 * builder's auto-generated form) -- several auto-generated multi-column
 * names on `commerce_seller_webhook_endpoint_events` would approach or
 * exceed PostgreSQL's 63-byte NAMEDATALEN limit (see
 * `CreateSellerApiKeysTables`'s identical note for its own long-table-name
 * indexes).
 *
 * Marketplace migrations 010-019 first publish together in Commerce 1.2.0,
 * so all five tables here are genuinely NEW -- nothing existing to fold
 * into.
 */
final class CreateSellerWebhookTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('commerce_seller_webhook_endpoints')) {
            $schema->createTable('commerce_seller_webhook_endpoints', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('seller_uuid', 12);
                $table->string('url', 2048);
                // Canonical JSON array of exact catalog event-type slugs (design
                // spec §2.2/§2.3) -- never a wildcard, always a subset of
                // SellerWebhookEventCatalog::all().
                $table->text('subscribed_events');
                // active|disabled|deleted (design spec §2.2).
                $table->string('status', 16)->default('active');
                // Optimistic-concurrency claim for the design spec §2.10 lock
                // order (seller revision -> actor re-read -> endpoint revision).
                $table->integer('revision')->default(0);
                // Auto-disable counter (design spec §2.7) -- reset on success
                // only while the endpoint remains active.
                $table->integer('consecutive_failures')->default(0);
                $table->string('created_by', 12);
                $table->timestamp('disabled_at')->nullable();
                $table->string('disabled_reason', 255)->nullable();
                // Tombstone marker (design spec §2.2) -- DELETE never removes
                // the row; a deleted endpoint can never be re-enabled.
                $table->timestamp('deleted_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique(['tenant_uuid', 'uuid'], 'commerce_seller_webhook_endpoints_tenant_uuid_unique');
                // Self-service list scan (design spec §2.10): tenant-bound,
                // per-seller, filterable by status.
                $table->index(
                    ['tenant_uuid', 'seller_uuid', 'status'],
                    'commerce_seller_webhook_endpoints_seller_status_index'
                );
            });
        }

        if (!$schema->hasTable('commerce_seller_webhook_secrets')) {
            $schema->createTable('commerce_seller_webhook_secrets', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('endpoint_uuid', 12);
                // Framework EncryptionService envelope (AES-256-GCM), AAD
                // `tenant_uuid:endpoint_uuid:secret_uuid` (design spec §2.2). No
                // plaintext/hash-only signing source ever stored.
                $table->text('secret_ciphertext');
                // Optional, NON-secret correlation value for support (design spec
                // §3) -- never sufficient to sign or verify a payload.
                $table->string('secret_fingerprint', 64)->nullable();
                // current|previous (design spec §2.2). Service/repository
                // invariants enforce exactly one current + at most one unexpired
                // previous under the endpoint revision.
                $table->string('relationship', 16);
                $table->timestamp('overlap_expires_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('revoked_at')->nullable();

                $table->unique(['tenant_uuid', 'uuid'], 'commerce_seller_webhook_secrets_tenant_uuid_unique');
                // Current/previous resolution scan (design spec §2.2/§2.5): the
                // exact lookup delivery signing runs for an endpoint.
                $table->index(
                    ['tenant_uuid', 'endpoint_uuid', 'relationship'],
                    'commerce_seller_webhook_secrets_endpoint_rel_index'
                );
            });
        }

        if (!$schema->hasTable('commerce_seller_webhook_events')) {
            $schema->createTable('commerce_seller_webhook_events', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                // The stable event/receiver-dedup identity (design spec §2.3) --
                // this `uuid` IS `event_id`, no separate column.
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('seller_uuid', 12);
                $table->string('event_type', 48);
                // The exact canonical bytes that get signed and sent (design spec
                // §2.3/§2.5) -- never regenerated at delivery time.
                $table->text('payload');
                $table->timestamp('occurred_at');
                // Optional correlation reference to the source order/refund/
                // payout/etc. row (design spec §3) -- diagnostics/support only.
                $table->string('source_ref', 191)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique(['tenant_uuid', 'uuid'], 'commerce_seller_webhook_events_tenant_uuid_unique');
                // Per-seller event history scan (design spec §2.3): tenant-bound,
                // ordered by occurrence.
                $table->index(
                    ['tenant_uuid', 'seller_uuid', 'created_at'],
                    'commerce_seller_webhook_events_seller_created_index'
                );
            });
        }

        if (!$schema->hasTable('commerce_seller_webhook_deliveries')) {
            $schema->createTable('commerce_seller_webhook_deliveries', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('endpoint_uuid', 12);
                $table->string('webhook_event_uuid', 12);
                $table->string('seller_uuid', 12);
                // pending|paused|delivering|delivered|dead_letter|canceled
                // (design spec §2.4/§2.7/§2.9).
                $table->string('status', 16)->default('pending');
                $table->integer('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                // Suspend/close pause snapshot (design spec §2.9) -- persists
                // the remaining retry delay so reinstatement can reconstruct it
                // with DB time rather than merely expiring while paused.
                $table->timestamp('paused_at')->nullable();
                $table->integer('paused_remaining_seconds')->nullable();
                // seller_suspended|endpoint_disabled (design spec §2.7/§2.9).
                $table->string('pause_reason', 24)->nullable();
                // Crash-safe claim lease (design spec §2.7): a random token +
                // expiry set only while status=delivering; every finalizer is
                // conditional on this exact token.
                $table->string('claim_token', 32)->nullable();
                $table->timestamp('claim_expires_at')->nullable();
                $table->timestamp('last_attempt_at')->nullable();
                $table->integer('last_status_code')->nullable();
                $table->string('last_error', 255)->nullable();
                // Replay lineage (design spec §2.8) -- points at the delivery a
                // replay attempt was created from; null for an original delivery.
                $table->string('replay_of_uuid', 12)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                // Due-row sweep scan (design spec §2.4): pending rows ready for
                // dispatch, tenant-bound.
                $table->index(
                    ['tenant_uuid', 'status', 'next_attempt_at'],
                    'commerce_seller_webhook_deliveries_status_next_index'
                );
                // Expired-claim recovery scan (design spec §2.7): stuck
                // `delivering` rows whose lease has lapsed.
                $table->index(
                    ['tenant_uuid', 'status', 'claim_expires_at'],
                    'commerce_seller_webhook_deliveries_status_claim_index'
                );
                // Per-endpoint history/eligibility scan (design spec §2.7): one
                // failing endpoint's own pending/paused work.
                $table->index(
                    ['tenant_uuid', 'endpoint_uuid', 'status'],
                    'commerce_seller_webhook_deliveries_endpoint_status_index'
                );
                // Per-event fan-out scan (design spec §2.3): every delivery
                // (including replays) produced from one event snapshot.
                $table->index(
                    ['tenant_uuid', 'webhook_event_uuid'],
                    'commerce_seller_webhook_deliveries_event_index'
                );
            });
        }

        if (!$schema->hasTable('commerce_seller_webhook_endpoint_events')) {
            $schema->createTable('commerce_seller_webhook_endpoint_events', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('tenant_uuid', 12)->default('');
                $table->string('endpoint_uuid', 12);
                $table->string('seller_uuid', 12);
                // register|url_change|secret_rotate|disable|enable|auto_disable|
                // delete (design spec §2.10).
                $table->string('action', 24);
                $table->string('actor_uuid', 12)->nullable();
                $table->string('reason', 255)->nullable();
                $table->text('detail')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique(
                    ['tenant_uuid', 'uuid'],
                    'commerce_seller_webhook_endpoint_events_tenant_uuid_unique'
                );
                // Self-service/operator audit-history scan (design spec §2.10):
                // tenant-bound, per-endpoint, ordered by created_at.
                $table->index(
                    ['tenant_uuid', 'endpoint_uuid', 'created_at'],
                    'commerce_seller_webhook_endpoint_events_endpoint_created_index'
                );
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('commerce_seller_webhook_endpoint_events');
        $schema->dropTableIfExists('commerce_seller_webhook_deliveries');
        $schema->dropTableIfExists('commerce_seller_webhook_events');
        $schema->dropTableIfExists('commerce_seller_webhook_secrets');
        $schema->dropTableIfExists('commerce_seller_webhook_endpoints');
    }

    public function getDescription(): string
    {
        return 'Creates the commerce_seller_webhook_endpoints, commerce_seller_webhook_secrets, '
            . 'commerce_seller_webhook_events, commerce_seller_webhook_deliveries, and '
            . 'commerce_seller_webhook_endpoint_events tables for MV5c-2 seller outbound webhooks.';
    }
}
