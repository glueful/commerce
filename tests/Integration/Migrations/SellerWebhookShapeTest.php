<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Database\Migrations\CreateSellerWebhookTables;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyCapabilityCatalog;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEventCatalog;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Freezes the MV5c-2 seller-webhook schema foundation (design spec §3)
 * before any endpoint/secret/outbox/delivery/replay service code consumes
 * it: the five brand-new tables (019) -- `commerce_seller_webhook_endpoints`,
 * `commerce_seller_webhook_secrets`, `commerce_seller_webhook_events`
 * (durable snapshot), `commerce_seller_webhook_deliveries`, and
 * `commerce_seller_webhook_endpoint_events` (append-only audit) -- plus the
 * `commerce.seller.webhooks.manage` role grant (owner/admin ONLY, and
 * excluded from `SellerApiKeyCapabilityCatalog`), the dedicated
 * `SellerWebhookEventCatalog`, the `marketplace.webhooks.*` config defaults,
 * and `DiagnosticsReport`/`TenantAdopter` registration.
 *
 * Because the schema builder exposes `hasTable`/`hasColumn` but no
 * `hasIndex`, every index assertion here is made via direct SQLite driver
 * introspection (`PRAGMA index_list` / `PRAGMA index_info`), matching this
 * codebase's established convention (see {@see SellerApiKeyShapeTest}).
 */
final class SellerWebhookShapeTest extends CommerceTestCase
{
    // === commerce_seller_webhook_endpoints (§3) ========================================

    public function testWebhookEndpointsTableExists(): void
    {
        self::assertTrue(
            $this->connection->getSchemaBuilder()->hasTable('commerce_seller_webhook_endpoints'),
            'missing table commerce_seller_webhook_endpoints'
        );
    }

    public function testWebhookEndpointsHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'seller_uuid', 'url', 'subscribed_events', 'status',
            'revision', 'consecutive_failures', 'created_by', 'disabled_at', 'disabled_reason',
            'deleted_at', 'created_at', 'updated_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_webhook_endpoints', $column),
                "commerce_seller_webhook_endpoints missing column {$column}"
            );
        }
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function minimalEndpointRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'endpointwh001',
            'tenant_uuid' => 'tenant0mv5c2w',
            'seller_uuid' => 'sellerwhmv01',
            'url' => 'https://seller.example.test/webhooks/commerce',
            'subscribed_events' => '["order.placed","order.paid"]',
            'created_by' => 'subjectwhmv1',
        ], $overrides);
    }

    public function testWebhookEndpointsTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_webhook_endpoints')->insert(
            array_diff_key($this->minimalEndpointRow(['uuid' => 'endpointwh002']), ['tenant_uuid' => true])
        );

        $row = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', 'endpointwh002')->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testWebhookEndpointsStatusDefaultsToActiveWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_webhook_endpoints')->insert(
            $this->minimalEndpointRow(['uuid' => 'endpointwh003'])
        );

        $row = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', 'endpointwh003')->first();
        self::assertNotNull($row);
        self::assertSame('active', $row['status']);
    }

    public function testWebhookEndpointsRevisionAndConsecutiveFailuresDefaultToZeroWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_webhook_endpoints')->insert(
            $this->minimalEndpointRow(['uuid' => 'endpointwh004'])
        );

        $row = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', 'endpointwh004')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['revision']);
        self::assertSame(0, (int) $row['consecutive_failures']);
    }

    public function testWebhookEndpointsNullableColumnsDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_webhook_endpoints')->insert(
            $this->minimalEndpointRow(['uuid' => 'endpointwh005'])
        );

        $row = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', 'endpointwh005')->first();
        self::assertNotNull($row);
        self::assertNull($row['disabled_at']);
        self::assertNull($row['disabled_reason']);
        self::assertNull($row['deleted_at']);
        self::assertNull($row['updated_at']);
    }

    /**
     * Design spec §2.2/§2.9: DELETE is a tombstone, not a row removal -- the
     * `deleted` status + `deleted_at` must be assignable and durable, and a
     * deleted endpoint's row must remain queryable (never actually dropped).
     */
    public function testWebhookEndpointsAcceptsDeletedStatusAndDeletedAt(): void
    {
        $this->connection->table('commerce_seller_webhook_endpoints')->insert($this->minimalEndpointRow([
            'uuid' => 'endpointwh006',
            'status' => 'deleted',
            'deleted_at' => '2026-07-20 00:00:00',
        ]));

        // The framework's query builder auto-applies `WHERE deleted_at IS NULL`
        // to any table carrying a `deleted_at` column (its soft-delete
        // convention) -- a nice match for design spec §2.10's "list endpoints
        // (no ... deleted rows)", but this test only proves the row itself
        // durably persists with the tombstone fields set, so it reads via
        // `withTrashed()` explicitly.
        $row = $this->connection->table('commerce_seller_webhook_endpoints')
            ->withTrashed()
            ->where('uuid', '=', 'endpointwh006')->first();
        self::assertNotNull($row);
        self::assertSame('deleted', $row['status']);
        self::assertNotNull($row['deleted_at']);

        // And confirm the auto-filter itself: an ordinary read must NOT see
        // the tombstoned row.
        self::assertNull(
            $this->connection->table('commerce_seller_webhook_endpoints')
                ->where('uuid', '=', 'endpointwh006')->first()
        );
    }

    public function testWebhookEndpointsAcceptsAssignedValues(): void
    {
        $this->connection->table('commerce_seller_webhook_endpoints')->insert($this->minimalEndpointRow([
            'uuid' => 'endpointwh007',
            'status' => 'disabled',
            'revision' => 4,
            'consecutive_failures' => 20,
            'disabled_at' => '2026-07-19 00:00:00',
            'disabled_reason' => 'consecutive_failure_threshold',
        ]));

        $row = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', 'endpointwh007')->first();
        self::assertNotNull($row);
        self::assertSame('tenant0mv5c2w', $row['tenant_uuid']);
        self::assertSame('sellerwhmv01', $row['seller_uuid']);
        self::assertSame('https://seller.example.test/webhooks/commerce', $row['url']);
        self::assertSame('["order.placed","order.paid"]', $row['subscribed_events']);
        self::assertSame('disabled', $row['status']);
        self::assertSame(4, (int) $row['revision']);
        self::assertSame(20, (int) $row['consecutive_failures']);
        self::assertSame('subjectwhmv1', $row['created_by']);
        self::assertNotNull($row['disabled_at']);
        self::assertSame('consecutive_failure_threshold', $row['disabled_reason']);
        self::assertNotNull($row['created_at']);
    }

    public function testWebhookEndpointsUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_seller_webhook_endpoints')->insert(
            $this->minimalEndpointRow(['uuid' => 'endpointwh010'])
        );

        $rejected = false;
        try {
            $this->connection->table('commerce_seller_webhook_endpoints')->insert(
                $this->minimalEndpointRow(['uuid' => 'endpointwh010'])
            );
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'duplicate (tenant_uuid, uuid) endpoint insert must be rejected');

        // A different tenant with the SAME uuid must succeed (per-tenant uniqueness).
        $this->connection->table('commerce_seller_webhook_endpoints')->insert(
            $this->minimalEndpointRow(['uuid' => 'endpointwh010', 'tenant_uuid' => 'tenant0mv5c2wb'])
        );
        self::assertSame(
            2,
            $this->connection->table('commerce_seller_webhook_endpoints')->where('uuid', '=', 'endpointwh010')->count()
        );
    }

    public function testWebhookEndpointsHasSellerStatusIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_webhook_endpoints',
            'commerce_seller_webhook_endpoints_seller_status_index',
            ['tenant_uuid', 'seller_uuid', 'status']
        );
    }

    // === commerce_seller_webhook_secrets (§3) ==========================================

    public function testWebhookSecretsTableExists(): void
    {
        self::assertTrue(
            $this->connection->getSchemaBuilder()->hasTable('commerce_seller_webhook_secrets'),
            'missing table commerce_seller_webhook_secrets'
        );
    }

    public function testWebhookSecretsHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'endpoint_uuid', 'secret_ciphertext', 'secret_fingerprint',
            'relationship', 'overlap_expires_at', 'created_at', 'revoked_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_webhook_secrets', $column),
                "commerce_seller_webhook_secrets missing column {$column}"
            );
        }
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function minimalSecretRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'secretwh0001',
            'tenant_uuid' => 'tenant0mv5c2w',
            'endpoint_uuid' => 'endpointwh001',
            'secret_ciphertext' => 'gAAAAA-ciphertext-placeholder',
            'relationship' => 'current',
        ], $overrides);
    }

    public function testWebhookSecretsTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_webhook_secrets')->insert(
            array_diff_key($this->minimalSecretRow(['uuid' => 'secretwh0002']), ['tenant_uuid' => true])
        );

        $row = $this->connection->table('commerce_seller_webhook_secrets')->where('uuid', '=', 'secretwh0002')->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testWebhookSecretsNullableColumnsDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_webhook_secrets')->insert(
            $this->minimalSecretRow(['uuid' => 'secretwh0003'])
        );

        $row = $this->connection->table('commerce_seller_webhook_secrets')->where('uuid', '=', 'secretwh0003')->first();
        self::assertNotNull($row);
        self::assertNull($row['secret_fingerprint']);
        self::assertNull($row['overlap_expires_at']);
        self::assertNull($row['revoked_at']);
    }

    public function testWebhookSecretsAcceptsAssignedValues(): void
    {
        $this->connection->table('commerce_seller_webhook_secrets')->insert($this->minimalSecretRow([
            'uuid' => 'secretwh0004',
            'relationship' => 'previous',
            'secret_fingerprint' => 'fp_' . str_repeat('a', 61),
            'overlap_expires_at' => '2026-07-21 00:00:00',
            'revoked_at' => '2026-07-20 00:00:00',
        ]));

        $row = $this->connection->table('commerce_seller_webhook_secrets')->where('uuid', '=', 'secretwh0004')->first();
        self::assertNotNull($row);
        self::assertSame('tenant0mv5c2w', $row['tenant_uuid']);
        self::assertSame('endpointwh001', $row['endpoint_uuid']);
        self::assertSame('gAAAAA-ciphertext-placeholder', $row['secret_ciphertext']);
        self::assertSame('previous', $row['relationship']);
        self::assertNotNull($row['secret_fingerprint']);
        self::assertNotNull($row['overlap_expires_at']);
        self::assertNotNull($row['created_at']);
        self::assertNotNull($row['revoked_at']);
    }

    public function testWebhookSecretsUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_seller_webhook_secrets')->insert(
            $this->minimalSecretRow(['uuid' => 'secretwh0010'])
        );

        $rejected = false;
        try {
            $this->connection->table('commerce_seller_webhook_secrets')->insert(
                $this->minimalSecretRow(['uuid' => 'secretwh0010'])
            );
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'duplicate (tenant_uuid, uuid) secret insert must be rejected');
    }

    public function testWebhookSecretsHasEndpointRelationshipIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_webhook_secrets',
            'commerce_seller_webhook_secrets_endpoint_rel_index',
            ['tenant_uuid', 'endpoint_uuid', 'relationship']
        );
    }

    // === commerce_seller_webhook_events (durable snapshot, §3) =========================

    public function testWebhookEventsTableExists(): void
    {
        self::assertTrue(
            $this->connection->getSchemaBuilder()->hasTable('commerce_seller_webhook_events'),
            'missing table commerce_seller_webhook_events'
        );
    }

    public function testWebhookEventsHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'seller_uuid', 'event_type', 'payload', 'occurred_at',
            'source_ref', 'created_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_webhook_events', $column),
                "commerce_seller_webhook_events missing column {$column}"
            );
        }
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function minimalEventSnapshotRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'wheventmv001',
            'tenant_uuid' => 'tenant0mv5c2w',
            'seller_uuid' => 'sellerwhmv01',
            'event_type' => 'order.placed',
            'payload' => '{"order_uuid":"orderwhmv001"}',
            'occurred_at' => '2026-07-19 12:00:00',
        ], $overrides);
    }

    public function testWebhookEventsTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_webhook_events')->insert(
            array_diff_key($this->minimalEventSnapshotRow(['uuid' => 'wheventmv002']), ['tenant_uuid' => true])
        );

        $row = $this->connection->table('commerce_seller_webhook_events')->where('uuid', '=', 'wheventmv002')->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testWebhookEventsNullableColumnsDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_webhook_events')->insert(
            $this->minimalEventSnapshotRow(['uuid' => 'wheventmv003'])
        );

        $row = $this->connection->table('commerce_seller_webhook_events')->where('uuid', '=', 'wheventmv003')->first();
        self::assertNotNull($row);
        self::assertNull($row['source_ref']);
    }

    public function testWebhookEventsAcceptsAssignedValues(): void
    {
        $this->connection->table('commerce_seller_webhook_events')->insert($this->minimalEventSnapshotRow([
            'uuid' => 'wheventmv004',
            'event_type' => 'seller_order.fulfilled',
            'source_ref' => 'sellerorderwhmv001',
        ]));

        $row = $this->connection->table('commerce_seller_webhook_events')->where('uuid', '=', 'wheventmv004')->first();
        self::assertNotNull($row);
        self::assertSame('tenant0mv5c2w', $row['tenant_uuid']);
        self::assertSame('sellerwhmv01', $row['seller_uuid']);
        self::assertSame('seller_order.fulfilled', $row['event_type']);
        self::assertSame('{"order_uuid":"orderwhmv001"}', $row['payload']);
        self::assertNotNull($row['occurred_at']);
        self::assertSame('sellerorderwhmv001', $row['source_ref']);
        self::assertNotNull($row['created_at']);
    }

    public function testWebhookEventsUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_seller_webhook_events')->insert(
            $this->minimalEventSnapshotRow(['uuid' => 'wheventmv010'])
        );

        $rejected = false;
        try {
            $this->connection->table('commerce_seller_webhook_events')->insert(
                $this->minimalEventSnapshotRow(['uuid' => 'wheventmv010'])
            );
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'duplicate (tenant_uuid, uuid) event-snapshot insert must be rejected');
    }

    public function testWebhookEventsHasSellerCreatedIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_webhook_events',
            'commerce_seller_webhook_events_seller_created_index',
            ['tenant_uuid', 'seller_uuid', 'created_at']
        );
    }

    // === commerce_seller_webhook_deliveries (§3) ========================================

    public function testWebhookDeliveriesTableExists(): void
    {
        self::assertTrue(
            $this->connection->getSchemaBuilder()->hasTable('commerce_seller_webhook_deliveries'),
            'missing table commerce_seller_webhook_deliveries'
        );
    }

    public function testWebhookDeliveriesHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'endpoint_uuid', 'webhook_event_uuid', 'seller_uuid', 'status',
            'attempts', 'next_attempt_at', 'paused_at', 'paused_remaining_seconds', 'pause_reason',
            'claim_token', 'claim_expires_at', 'last_attempt_at', 'last_status_code', 'last_error',
            'replay_of_uuid', 'created_at', 'updated_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_webhook_deliveries', $column),
                "commerce_seller_webhook_deliveries missing column {$column}"
            );
        }
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function minimalDeliveryRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'deliverywh001',
            'tenant_uuid' => 'tenant0mv5c2w',
            'endpoint_uuid' => 'endpointwh001',
            'webhook_event_uuid' => 'wheventmv001',
            'seller_uuid' => 'sellerwhmv01',
        ], $overrides);
    }

    public function testWebhookDeliveriesTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_webhook_deliveries')->insert(
            array_diff_key($this->minimalDeliveryRow(['uuid' => 'deliverywh002']), ['tenant_uuid' => true])
        );

        $row = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', 'deliverywh002')->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testWebhookDeliveriesStatusDefaultsToPendingAndAttemptsToZeroWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_webhook_deliveries')->insert(
            $this->minimalDeliveryRow(['uuid' => 'deliverywh003'])
        );

        $row = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', 'deliverywh003')->first();
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
    }

    public function testWebhookDeliveriesNullableColumnsDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_webhook_deliveries')->insert(
            $this->minimalDeliveryRow(['uuid' => 'deliverywh004'])
        );

        $row = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', 'deliverywh004')->first();
        self::assertNotNull($row);
        self::assertNull($row['next_attempt_at']);
        self::assertNull($row['paused_at']);
        self::assertNull($row['paused_remaining_seconds']);
        self::assertNull($row['pause_reason']);
        self::assertNull($row['claim_token']);
        self::assertNull($row['claim_expires_at']);
        self::assertNull($row['last_attempt_at']);
        self::assertNull($row['last_status_code']);
        self::assertNull($row['last_error']);
        self::assertNull($row['replay_of_uuid']);
        self::assertNull($row['updated_at']);
    }

    /**
     * Design spec §2.7: the crash-safe claim lease -- `status=delivering`
     * paired with a random `claim_token` + `claim_expires_at` -- must be
     * assignable, since every finalizer/reclaim is conditional on this exact
     * token/expiry pair.
     */
    public function testWebhookDeliveriesAcceptsClaimLeaseFields(): void
    {
        $this->connection->table('commerce_seller_webhook_deliveries')->insert($this->minimalDeliveryRow([
            'uuid' => 'deliverywh005',
            'status' => 'delivering',
            'attempts' => 1,
            'claim_token' => str_repeat('c', 32),
            'claim_expires_at' => '2026-07-20 00:05:00',
        ]));

        $row = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', 'deliverywh005')->first();
        self::assertNotNull($row);
        self::assertSame('delivering', $row['status']);
        self::assertSame(str_repeat('c', 32), $row['claim_token']);
        self::assertNotNull($row['claim_expires_at']);
    }

    /**
     * Design spec §2.9: a suspension-paused delivery snapshots
     * `paused_at`/`paused_remaining_seconds`/`pause_reason` and consumes no
     * attempt; an endpoint-disabled pause uses the sibling reason value.
     */
    public function testWebhookDeliveriesAcceptsPauseFieldsForBothPauseReasons(): void
    {
        $this->connection->table('commerce_seller_webhook_deliveries')->insert($this->minimalDeliveryRow([
            'uuid' => 'deliverywh006',
            'status' => 'paused',
            'paused_at' => '2026-07-20 00:00:00',
            'paused_remaining_seconds' => 120,
            'pause_reason' => 'seller_suspended',
        ]));
        $this->connection->table('commerce_seller_webhook_deliveries')->insert($this->minimalDeliveryRow([
            'uuid' => 'deliverywh007',
            'status' => 'paused',
            'paused_at' => '2026-07-20 00:00:00',
            'paused_remaining_seconds' => 0,
            'pause_reason' => 'endpoint_disabled',
        ]));

        $suspended = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', 'deliverywh006')->first();
        $disabled = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', 'deliverywh007')->first();

        self::assertSame('seller_suspended', $suspended['pause_reason']);
        self::assertSame(120, (int) $suspended['paused_remaining_seconds']);
        self::assertSame('endpoint_disabled', $disabled['pause_reason']);
        self::assertSame(0, (int) $disabled['paused_remaining_seconds']);
    }

    public function testWebhookDeliveriesAcceptsAssignedValues(): void
    {
        $this->connection->table('commerce_seller_webhook_deliveries')->insert($this->minimalDeliveryRow([
            'uuid' => 'deliverywh008',
            'status' => 'dead_letter',
            'attempts' => 10,
            'next_attempt_at' => null,
            'last_attempt_at' => '2026-07-20 00:10:00',
            'last_status_code' => 503,
            'last_error' => 'Service Unavailable',
            'replay_of_uuid' => 'deliverywh001',
        ]));

        $row = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', 'deliverywh008')->first();
        self::assertNotNull($row);
        self::assertSame('tenant0mv5c2w', $row['tenant_uuid']);
        self::assertSame('endpointwh001', $row['endpoint_uuid']);
        self::assertSame('wheventmv001', $row['webhook_event_uuid']);
        self::assertSame('sellerwhmv01', $row['seller_uuid']);
        self::assertSame('dead_letter', $row['status']);
        self::assertSame(10, (int) $row['attempts']);
        self::assertNotNull($row['last_attempt_at']);
        self::assertSame(503, (int) $row['last_status_code']);
        self::assertSame('Service Unavailable', $row['last_error']);
        self::assertSame('deliverywh001', $row['replay_of_uuid']);
        self::assertNotNull($row['created_at']);
    }

    /**
     * Design spec §3 verbatim: `commerce_seller_webhook_deliveries`
     * deliberately carries NO `unique(tenant_uuid, uuid)` of its own -- the
     * four spec-pinned indexes below are the complete index set. This is a
     * documented deviation from every OTHER table in this migration (all
     * four of which DO carry that unique), proven here so a well-intentioned
     * future addition of the constraint is caught as a spec deviation.
     */
    public function testWebhookDeliveriesHasNoTenantUuidUniqueConstraint(): void
    {
        $pdo = $this->connection->getPDO();
        $stmt = $pdo->query("PRAGMA index_list('commerce_seller_webhook_deliveries')");
        self::assertNotFalse($stmt);
        $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($indexes as $index) {
            if ((int) $index['unique'] === 1) {
                self::fail("commerce_seller_webhook_deliveries must carry no unique index, found {$index['name']}");
            }
        }
        self::assertTrue(true);
    }

    public function testWebhookDeliveriesHasDueSweepIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_webhook_deliveries',
            'commerce_seller_webhook_deliveries_status_next_index',
            ['tenant_uuid', 'status', 'next_attempt_at']
        );
    }

    /**
     * The expired-claim sweep index (design spec §2.7): the recovery sweep's
     * "stuck `delivering` rows whose lease has lapsed" scan.
     */
    public function testWebhookDeliveriesHasExpiredClaimSweepIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_webhook_deliveries',
            'commerce_seller_webhook_deliveries_status_claim_index',
            ['tenant_uuid', 'status', 'claim_expires_at']
        );
    }

    public function testWebhookDeliveriesHasEndpointStatusIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_webhook_deliveries',
            'commerce_seller_webhook_deliveries_endpoint_status_index',
            ['tenant_uuid', 'endpoint_uuid', 'status']
        );
    }

    public function testWebhookDeliveriesHasEventIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_webhook_deliveries',
            'commerce_seller_webhook_deliveries_event_index',
            ['tenant_uuid', 'webhook_event_uuid']
        );
    }

    // === commerce_seller_webhook_endpoint_events (append-only audit, §3) ===============

    public function testWebhookEndpointEventsTableExists(): void
    {
        self::assertTrue(
            $this->connection->getSchemaBuilder()->hasTable('commerce_seller_webhook_endpoint_events'),
            'missing table commerce_seller_webhook_endpoint_events'
        );
    }

    public function testWebhookEndpointEventsHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'endpoint_uuid', 'seller_uuid', 'action', 'actor_uuid',
            'reason', 'detail', 'created_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_webhook_endpoint_events', $column),
                "commerce_seller_webhook_endpoint_events missing column {$column}"
            );
        }

        // Append-only audit: no secret-shaped column ever lives here.
        self::assertFalse($schema->hasColumn('commerce_seller_webhook_endpoint_events', 'secret_ciphertext'));
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function minimalEndpointEventRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'whepevmv0001',
            'tenant_uuid' => 'tenant0mv5c2w',
            'endpoint_uuid' => 'endpointwh001',
            'seller_uuid' => 'sellerwhmv01',
            'action' => 'register',
        ], $overrides);
    }

    public function testWebhookEndpointEventsTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_webhook_endpoint_events')->insert(
            array_diff_key($this->minimalEndpointEventRow(['uuid' => 'whepevmv0002']), ['tenant_uuid' => true])
        );

        $row = $this->connection->table('commerce_seller_webhook_endpoint_events')
            ->where('uuid', '=', 'whepevmv0002')->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testWebhookEndpointEventsNullableColumnsDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_webhook_endpoint_events')->insert(
            $this->minimalEndpointEventRow(['uuid' => 'whepevmv0003'])
        );

        $row = $this->connection->table('commerce_seller_webhook_endpoint_events')
            ->where('uuid', '=', 'whepevmv0003')->first();
        self::assertNotNull($row);
        self::assertNull($row['actor_uuid']);
        self::assertNull($row['reason']);
        self::assertNull($row['detail']);
    }

    public function testWebhookEndpointEventsAcceptsAssignedValues(): void
    {
        $this->connection->table('commerce_seller_webhook_endpoint_events')->insert(
            $this->minimalEndpointEventRow([
                'uuid' => 'whepevmv0004',
                'action' => 'auto_disable',
                'actor_uuid' => null,
                'reason' => 'consecutive_failure_threshold',
                'detail' => 'Disabled after 20 consecutive failures.',
            ])
        );

        $row = $this->connection->table('commerce_seller_webhook_endpoint_events')
            ->where('uuid', '=', 'whepevmv0004')->first();
        self::assertNotNull($row);
        self::assertSame('tenant0mv5c2w', $row['tenant_uuid']);
        self::assertSame('endpointwh001', $row['endpoint_uuid']);
        self::assertSame('sellerwhmv01', $row['seller_uuid']);
        self::assertSame('auto_disable', $row['action']);
        self::assertNull($row['actor_uuid']);
        self::assertSame('consecutive_failure_threshold', $row['reason']);
        self::assertSame('Disabled after 20 consecutive failures.', $row['detail']);
        self::assertNotNull($row['created_at']);
    }

    public function testWebhookEndpointEventsUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_seller_webhook_endpoint_events')->insert(
            $this->minimalEndpointEventRow(['uuid' => 'whepevmv0010'])
        );

        $rejected = false;
        try {
            $this->connection->table('commerce_seller_webhook_endpoint_events')->insert(
                $this->minimalEndpointEventRow(['uuid' => 'whepevmv0010'])
            );
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'duplicate (tenant_uuid, uuid) endpoint-event insert must be rejected');
    }

    public function testWebhookEndpointEventsHasEndpointCreatedIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_webhook_endpoint_events',
            'commerce_seller_webhook_endpoint_events_endpoint_created_index',
            ['tenant_uuid', 'endpoint_uuid', 'created_at']
        );
    }

    // === Migration idempotency =========================================================

    public function testRerunning019MigrationIsANoOp(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $migration = new CreateSellerWebhookTables();

        // setUp() already ran this migration once via CommerceTestCase::MIGRATIONS;
        // re-running up() must be a no-op guarded by hasTable().
        $migration->up($schema);
        $migration->up($schema);

        self::assertTrue($schema->hasTable('commerce_seller_webhook_endpoints'));
        self::assertTrue($schema->hasTable('commerce_seller_webhook_secrets'));
        self::assertTrue($schema->hasTable('commerce_seller_webhook_events'));
        self::assertTrue($schema->hasTable('commerce_seller_webhook_deliveries'));
        self::assertTrue($schema->hasTable('commerce_seller_webhook_endpoint_events'));
        self::assertTrue($schema->hasColumn('commerce_seller_webhook_endpoints', 'subscribed_events'));
    }

    // === FixedSellerRoleAuthority (§3/§2.10) ===========================================

    public function testWebhooksManageCapabilityConstantHasTheSpecSlug(): void
    {
        self::assertSame('commerce.seller.webhooks.manage', FixedSellerRoleAuthority::WEBHOOKS_MANAGE);
    }

    public function testWebhooksManageIsGrantedToOwnerAndAdminOnly(): void
    {
        $authority = new FixedSellerRoleAuthority();

        self::assertTrue($authority->allows('seller_owner', FixedSellerRoleAuthority::WEBHOOKS_MANAGE));
        self::assertTrue($authority->allows('seller_admin', FixedSellerRoleAuthority::WEBHOOKS_MANAGE));
        self::assertFalse($authority->allows('seller_staff', FixedSellerRoleAuthority::WEBHOOKS_MANAGE));
        self::assertFalse($authority->allows('seller_analyst', FixedSellerRoleAuthority::WEBHOOKS_MANAGE));
    }

    // === SellerApiKeyCapabilityCatalog exclusion (§2.10) ================================

    /**
     * The load-bearing security proof (Task 2 brief): a seller API key must
     * NEVER be able to carry `webhooks.manage` -- registering/redirecting an
     * endpoint or reading/rotating its signing secret is a JWT-interactive-
     * only management concern (design spec §2.10), never a machine-credential
     * one.
     */
    public function testCapabilityCatalogExcludesWebhooksManage(): void
    {
        self::assertFalse(SellerApiKeyCapabilityCatalog::contains('commerce.seller.webhooks.manage'));
        self::assertFalse(SellerApiKeyCapabilityCatalog::contains(FixedSellerRoleAuthority::WEBHOOKS_MANAGE));
        self::assertNotContains(FixedSellerRoleAuthority::WEBHOOKS_MANAGE, SellerApiKeyCapabilityCatalog::all());
    }

    // === SellerWebhookEventCatalog (§2.3) ===============================================

    public function testEventCatalogListsExactlyTheNineV1Slugs(): void
    {
        self::assertSame([
            'order.placed',
            'order.paid',
            'order.canceled',
            'seller_order.fulfilled',
            'refund.completed',
            'payout.recorded',
            'stock.adjusted',
            'product.adopted',
            'product.transferred',
        ], SellerWebhookEventCatalog::all());
    }

    public function testEventCatalogContainsEveryV1Slug(): void
    {
        foreach (SellerWebhookEventCatalog::all() as $slug) {
            self::assertTrue(SellerWebhookEventCatalog::contains($slug));
        }
    }

    public function testEventCatalogRejectsDeferredAndUnknownSlugs(): void
    {
        self::assertFalse(SellerWebhookEventCatalog::contains(''));
        self::assertFalse(SellerWebhookEventCatalog::contains('*'));
        self::assertFalse(SellerWebhookEventCatalog::contains('refund.failed'));
        self::assertFalse(SellerWebhookEventCatalog::contains('order.note_added'));
        self::assertFalse(SellerWebhookEventCatalog::contains('order.fulfilled'));
        self::assertFalse(SellerWebhookEventCatalog::contains('commerce.seller.*'));
    }

    // === config/commerce.php (§2.4/§2.6/§2.7/§2.9) ======================================

    public function testWebhookConfigDefaultsAreSetAndClaimLeaseExceedsDeliveryTimeout(): void
    {
        $context = $this->appContext();

        self::assertSame(10, config($context, 'commerce.marketplace.webhooks.max_attempts'));
        self::assertSame(30, config($context, 'commerce.marketplace.webhooks.backoff_base_seconds'));
        self::assertSame(3600, config($context, 'commerce.marketplace.webhooks.backoff_cap_seconds'));
        self::assertSame(0.2, config($context, 'commerce.marketplace.webhooks.jitter'));
        self::assertSame(20, config($context, 'commerce.marketplace.webhooks.consecutive_failure_disable_threshold'));
        self::assertSame(24, config($context, 'commerce.marketplace.webhooks.secret_overlap_hours'));
        self::assertSame(10, config($context, 'commerce.marketplace.webhooks.delivery_timeout_seconds'));
        self::assertSame(30, config($context, 'commerce.marketplace.webhooks.claim_lease_seconds'));
        self::assertSame(65536, config($context, 'commerce.marketplace.webhooks.max_response_bytes'));
        self::assertSame(90, config($context, 'commerce.marketplace.webhooks.retention_days'));
        self::assertSame(100, config($context, 'commerce.marketplace.webhooks.sweep_batch_size'));

        // Design spec §2.7: the claim lease MUST exceed the delivery timeout,
        // otherwise a healthy in-flight attempt could be reclaimed before it
        // can even time out on its own.
        $claimLease = (int) config($context, 'commerce.marketplace.webhooks.claim_lease_seconds');
        $deliveryTimeout = (int) config($context, 'commerce.marketplace.webhooks.delivery_timeout_seconds');
        self::assertGreaterThan(
            $deliveryTimeout,
            $claimLease,
            'claim_lease_seconds must exceed delivery_timeout_seconds'
        );
    }

    // === DiagnosticsReport (§3) =========================================================

    public function testDiagnosticsCommerceTablesIncludesAllFiveWebhookTables(): void
    {
        foreach ($this->webhookTables() as $table) {
            self::assertContains(
                $table,
                DiagnosticsReport::commerceTables(),
                "DiagnosticsReport::commerceTables() missing {$table}"
            );
        }
    }

    public function testDiagnosticsTenantTablesIncludesAllFiveWebhookTables(): void
    {
        foreach ($this->webhookTables() as $table) {
            self::assertContains(
                $table,
                DiagnosticsReport::tenantTables(),
                "DiagnosticsReport::tenantTables() missing {$table}"
            );
        }
    }

    public function testDiagnosticsReportBuildShowsAllFiveWebhookTablesPresent(): void
    {
        $report = DiagnosticsReport::build($this->appContext());

        foreach ($this->webhookTables() as $table) {
            self::assertTrue(
                $report['database']['commerce_tables_present'][$table] ?? false,
                "DiagnosticsReport::build() must report {$table} as present"
            );
        }
    }

    /** @return list<string> */
    private function webhookTables(): array
    {
        return [
            'commerce_seller_webhook_endpoints',
            'commerce_seller_webhook_secrets',
            'commerce_seller_webhook_events',
            'commerce_seller_webhook_deliveries',
            'commerce_seller_webhook_endpoint_events',
        ];
    }

    /**
     * @param list<string> $expectedColumns ordered, leading column first
     */
    private function assertIndexExists(string $table, string $indexName, array $expectedColumns): void
    {
        $pdo = $this->connection->getPDO();

        $stmt = $pdo->query(sprintf("PRAGMA index_list('%s')", $table));
        self::assertNotFalse($stmt);
        $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $names = array_column($indexes, 'name');
        self::assertContains($indexName, $names, "missing index {$indexName} on {$table}");

        $infoStmt = $pdo->query(sprintf("PRAGMA index_info('%s')", $indexName));
        self::assertNotFalse($infoStmt);
        $columns = $infoStmt->fetchAll(\PDO::FETCH_ASSOC);
        $actualColumns = array_column($columns, 'name');

        self::assertSame($expectedColumns, $actualColumns, "unexpected column set/order for {$indexName}");
    }

    // =====================================================================
    // Real-PostgreSQL convergence lane (design spec §3/§7): the SQLite tests
    // above prove the SHAPE; this proves the SAME migration (019's five
    // brand-new tables) converges on a genuinely different engine -- every
    // index via `pg_indexes` (identical generated names across drivers --
    // {@see \Glueful\Database\Schema\TableBuilder::generateIndexName()} in
    // the framework -- so the SAME pinned names from the SQLite tests above
    // apply here unchanged). Gating, fixture-width discipline, and the
    // throwaway `Connection`/`ApplicationContext` construction all mirror
    // `SellerApiKeyShapeTest`'s own pgsql lanes exactly.
    // =====================================================================

    public function testFreshInstallConvergesOnRealPostgresWithAllFiveWebhookTables(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();

        foreach (
            [
            'commerce_seller_webhook_endpoints' => [
                'id', 'uuid', 'tenant_uuid', 'seller_uuid', 'url', 'subscribed_events', 'status',
                'revision', 'consecutive_failures', 'created_by', 'disabled_at', 'disabled_reason',
                'deleted_at', 'created_at', 'updated_at',
            ],
            'commerce_seller_webhook_secrets' => [
                'id', 'uuid', 'tenant_uuid', 'endpoint_uuid', 'secret_ciphertext', 'secret_fingerprint',
                'relationship', 'overlap_expires_at', 'created_at', 'revoked_at',
            ],
            'commerce_seller_webhook_events' => [
                'id', 'uuid', 'tenant_uuid', 'seller_uuid', 'event_type', 'payload', 'occurred_at',
                'source_ref', 'created_at',
            ],
            'commerce_seller_webhook_deliveries' => [
                'id', 'uuid', 'tenant_uuid', 'endpoint_uuid', 'webhook_event_uuid', 'seller_uuid', 'status',
                'attempts', 'next_attempt_at', 'paused_at', 'paused_remaining_seconds', 'pause_reason',
                'claim_token', 'claim_expires_at', 'last_attempt_at', 'last_status_code', 'last_error',
                'replay_of_uuid', 'created_at', 'updated_at',
            ],
            'commerce_seller_webhook_endpoint_events' => [
                'id', 'uuid', 'tenant_uuid', 'endpoint_uuid', 'seller_uuid', 'action', 'actor_uuid',
                'reason', 'detail', 'created_at',
            ],
            ] as $table => $columns
        ) {
            self::assertTrue($schema->hasTable($table), "missing {$table} on PostgreSQL");
            foreach ($columns as $column) {
                self::assertTrue(
                    $schema->hasColumn($table, $column),
                    "{$table} missing column {$column} on PostgreSQL"
                );
            }
        }
    }

    public function testWebhookIndexesExistOnRealPostgresViaPgIndexes(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());

        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_endpoints',
            'commerce_seller_webhook_endpoints_seller_status_index',
            ['tenant_uuid', 'seller_uuid', 'status']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_secrets',
            'commerce_seller_webhook_secrets_endpoint_rel_index',
            ['tenant_uuid', 'endpoint_uuid', 'relationship']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_events',
            'commerce_seller_webhook_events_seller_created_index',
            ['tenant_uuid', 'seller_uuid', 'created_at']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_deliveries',
            'commerce_seller_webhook_deliveries_status_claim_index',
            ['tenant_uuid', 'status', 'claim_expires_at']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_deliveries',
            'commerce_seller_webhook_deliveries_endpoint_status_index',
            ['tenant_uuid', 'endpoint_uuid', 'status']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_endpoint_events',
            'commerce_seller_webhook_endpoint_events_endpoint_created_index',
            ['tenant_uuid', 'endpoint_uuid', 'created_at']
        );
    }

    public function testRerunning019MigrationIsANoOpOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();

        // migratedConnection() already ran every migration (including 019)
        // once; re-running up() again must be a no-op guarded by hasTable().
        (new CreateSellerWebhookTables())->up($schema);

        self::assertTrue($schema->hasTable('commerce_seller_webhook_endpoints'));
        self::assertTrue($schema->hasTable('commerce_seller_webhook_secrets'));
        self::assertTrue($schema->hasTable('commerce_seller_webhook_events'));
        self::assertTrue($schema->hasTable('commerce_seller_webhook_deliveries'));
        self::assertTrue($schema->hasTable('commerce_seller_webhook_endpoint_events'));
    }

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove migration convergence is portable.');
        }
    }

    /**
     * `pg_indexes.indexdef` looks like `CREATE INDEX name ON public.table
     * USING btree (col_a, col_b)` (or `CREATE UNIQUE INDEX ...` for a named
     * unique constraint) -- the column list (in order) is the content of the
     * LAST parenthesized group.
     *
     * @param list<string> $expectedColumns ordered, leading column first
     */
    private function assertPgIndexExists(
        Connection $connection,
        string $table,
        string $indexName,
        array $expectedColumns
    ): void {
        $pdo = $connection->getPDO();
        $stmt = $pdo->prepare('SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?');
        $stmt->execute([$table, $indexName]);
        $indexDef = $stmt->fetchColumn();

        self::assertIsString($indexDef, "missing index {$indexName} on {$table} (pg_indexes)");
        self::assertMatchesRegularExpression('/\(([^()]+)\)\s*$/', $indexDef, "unparseable indexdef: {$indexDef}");
        preg_match('/\(([^()]+)\)\s*$/', $indexDef, $matches);
        $actualColumns = array_map('trim', explode(',', $matches[1]));

        self::assertSame($expectedColumns, $actualColumns, "unexpected column set/order for {$indexName}");
    }

    /** @return array<string,mixed> */
    private function pgConfig(): array
    {
        return [
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'glueful_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
            ],
            'pooling' => ['enabled' => false],
        ];
    }

    /** @param array<string,mixed> $pgConfig */
    private function migratedConnection(array $pgConfig): Connection
    {
        $connection = new Connection($pgConfig);
        $schema = $connection->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        return $connection;
    }
}
