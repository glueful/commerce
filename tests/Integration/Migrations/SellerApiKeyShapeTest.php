<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Database\Migrations\CreateSellerApiKeysTables;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyCapabilityCatalog;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Freezes the MV5c-1 seller-API-key schema foundation (design spec §3)
 * before any binding/auth/rotation service code consumes it: the three
 * brand-new tables (018) -- `commerce_seller_api_keys` (lineage),
 * `commerce_seller_api_key_credentials` (one row per generated framework
 * key), and `commerce_seller_api_key_events` (append-only audit) -- plus
 * the `commerce.seller.apikeys.manage` role grant (owner/admin ONLY), the
 * dedicated `SellerApiKeyCapabilityCatalog`, the `auth_denied_retention_days`
 * config default, and `DiagnosticsReport` registration in both inventories.
 *
 * Because the schema builder exposes `hasTable`/`hasColumn` but no `hasIndex`,
 * every index assertion here is made via direct SQLite driver introspection
 * (`PRAGMA index_list` / `PRAGMA index_info`), matching this codebase's
 * established convention (see {@see SellerLifecycleShapeTest}).
 */
final class SellerApiKeyShapeTest extends CommerceTestCase
{
    // === commerce_seller_api_keys (lineage, §3) =======================================

    public function testSellerApiKeysTableExists(): void
    {
        self::assertTrue(
            $this->connection->getSchemaBuilder()->hasTable('commerce_seller_api_keys'),
            'missing table commerce_seller_api_keys'
        );
    }

    public function testSellerApiKeysHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'seller_uuid', 'subject_user_uuid', 'declared_scopes',
            'name', 'status', 'current_credential_uuid', 'expires_at', 'revision', 'created_by',
            'created_at', 'updated_at', 'last_rotated_at', 'revoked_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_api_keys', $column),
                "commerce_seller_api_keys missing column {$column}"
            );
        }
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function minimalLineageRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'lineagemv001',
            'tenant_uuid' => 'tenant0mv5cak',
            'seller_uuid' => 'sellerakmv01',
            'subject_user_uuid' => 'subjectakmv1',
            'declared_scopes' => '["commerce.seller.orders.read"]',
            'name' => 'Order fulfillment key',
            'current_credential_uuid' => 'credentmv001',
            'created_by' => 'subjectakmv1',
        ], $overrides);
    }

    public function testSellerApiKeysTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_api_keys')->insert(
            array_diff_key(
                $this->minimalLineageRow(['uuid' => 'lineagemv002']),
                ['tenant_uuid' => true]
            )
        );

        $row = $this->connection->table('commerce_seller_api_keys')->where('uuid', '=', 'lineagemv002')->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testSellerApiKeysStatusDefaultsToActiveWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_api_keys')->insert(
            $this->minimalLineageRow(['uuid' => 'lineagemv003'])
        );

        $row = $this->connection->table('commerce_seller_api_keys')->where('uuid', '=', 'lineagemv003')->first();
        self::assertNotNull($row);
        self::assertSame('active', $row['status']);
    }

    public function testSellerApiKeysRevisionDefaultsToZeroWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_api_keys')->insert(
            $this->minimalLineageRow(['uuid' => 'lineagemv004'])
        );

        $row = $this->connection->table('commerce_seller_api_keys')->where('uuid', '=', 'lineagemv004')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['revision']);
    }

    public function testSellerApiKeysNullableColumnsDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_api_keys')->insert(
            $this->minimalLineageRow(['uuid' => 'lineagemv005'])
        );

        $row = $this->connection->table('commerce_seller_api_keys')->where('uuid', '=', 'lineagemv005')->first();
        self::assertNotNull($row);
        self::assertNull($row['expires_at']);
        self::assertNull($row['updated_at']);
        self::assertNull($row['last_rotated_at']);
        self::assertNull($row['revoked_at']);
    }

    public function testSellerApiKeysAcceptsAssignedValues(): void
    {
        $this->connection->table('commerce_seller_api_keys')->insert($this->minimalLineageRow([
            'uuid' => 'lineagemv006',
            'status' => 'revoked',
            'expires_at' => '2027-01-01 00:00:00',
            'revision' => 3,
            'last_rotated_at' => '2026-06-01 00:00:00',
            'revoked_at' => '2026-07-01 00:00:00',
        ]));

        $row = $this->connection->table('commerce_seller_api_keys')->where('uuid', '=', 'lineagemv006')->first();
        self::assertNotNull($row);
        self::assertSame('tenant0mv5cak', $row['tenant_uuid']);
        self::assertSame('sellerakmv01', $row['seller_uuid']);
        self::assertSame('subjectakmv1', $row['subject_user_uuid']);
        self::assertSame('["commerce.seller.orders.read"]', $row['declared_scopes']);
        self::assertSame('Order fulfillment key', $row['name']);
        self::assertSame('revoked', $row['status']);
        self::assertSame('credentmv001', $row['current_credential_uuid']);
        self::assertNotNull($row['expires_at']);
        self::assertSame(3, (int) $row['revision']);
        self::assertSame('subjectakmv1', $row['created_by']);
        self::assertNotNull($row['created_at']);
        self::assertNotNull($row['last_rotated_at']);
        self::assertNotNull($row['revoked_at']);
    }

    public function testSellerApiKeysUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_seller_api_keys')->insert(
            $this->minimalLineageRow(['uuid' => 'lineagemv010', 'current_credential_uuid' => 'credentmv010'])
        );

        $rejected = false;
        try {
            $this->connection->table('commerce_seller_api_keys')->insert(
                $this->minimalLineageRow(['uuid' => 'lineagemv010', 'current_credential_uuid' => 'credentmv011'])
            );
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'duplicate (tenant_uuid, uuid) lineage insert must be rejected');

        // A different tenant with the SAME uuid must succeed (per-tenant uniqueness).
        $this->connection->table('commerce_seller_api_keys')->insert(
            $this->minimalLineageRow([
                'uuid' => 'lineagemv010',
                'tenant_uuid' => 'tenant0mv5cakb',
                'current_credential_uuid' => 'credentmv012',
            ])
        );
        self::assertSame(
            2,
            $this->connection->table('commerce_seller_api_keys')->where('uuid', '=', 'lineagemv010')->count()
        );
    }

    public function testSellerApiKeysUniqueCurrentCredentialIsEnforced(): void
    {
        $this->connection->table('commerce_seller_api_keys')->insert(
            $this->minimalLineageRow(['uuid' => 'lineagemv020', 'current_credential_uuid' => 'sharedcred01'])
        );

        $rejected = false;
        try {
            $this->connection->table('commerce_seller_api_keys')->insert(
                $this->minimalLineageRow(['uuid' => 'lineagemv021', 'current_credential_uuid' => 'sharedcred01'])
            );
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue(
            $rejected,
            'duplicate (tenant_uuid, current_credential_uuid) lineage insert must be rejected'
        );

        // A different tenant may point at the same credential uuid string.
        $this->connection->table('commerce_seller_api_keys')->insert(
            $this->minimalLineageRow([
                'uuid' => 'lineagemv022',
                'tenant_uuid' => 'tenant0mv5cakc',
                'current_credential_uuid' => 'sharedcred01',
            ])
        );
        self::assertSame(
            2,
            $this->connection->table('commerce_seller_api_keys')
                ->where('current_credential_uuid', '=', 'sharedcred01')
                ->count()
        );
    }

    public function testSellerApiKeysHasSellerStatusIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_api_keys',
            'commerce_seller_api_keys_seller_status_index',
            ['tenant_uuid', 'seller_uuid', 'status']
        );
    }

    // === commerce_seller_api_key_credentials (§3) =====================================

    public function testSellerApiKeyCredentialsTableExists(): void
    {
        self::assertTrue(
            $this->connection->getSchemaBuilder()->hasTable('commerce_seller_api_key_credentials'),
            'missing table commerce_seller_api_key_credentials'
        );
    }

    public function testSellerApiKeyCredentialsHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'lineage_uuid', 'framework_key_uuid', 'generation',
            'relationship', 'grace_expires_at', 'created_at', 'revoked_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_api_key_credentials', $column),
                "commerce_seller_api_key_credentials missing column {$column}"
            );
        }
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function minimalCredentialRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'credentmv001',
            'tenant_uuid' => 'tenant0mv5cak',
            'lineage_uuid' => 'lineagemv001',
            'framework_key_uuid' => 'fwkeymv00001',
            'generation' => 1,
            'relationship' => 'current',
        ], $overrides);
    }

    public function testSellerApiKeyCredentialsTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_api_key_credentials')->insert(
            array_diff_key(
                $this->minimalCredentialRow(['uuid' => 'credentmv002']),
                ['tenant_uuid' => true]
            )
        );

        $row = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', 'credentmv002')->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testSellerApiKeyCredentialsNullableColumnsDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_api_key_credentials')->insert(
            $this->minimalCredentialRow(['uuid' => 'credentmv003'])
        );

        $row = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', 'credentmv003')->first();
        self::assertNotNull($row);
        self::assertNull($row['grace_expires_at']);
        self::assertNull($row['revoked_at']);
    }

    public function testSellerApiKeyCredentialsAcceptsAssignedValues(): void
    {
        $this->connection->table('commerce_seller_api_key_credentials')->insert($this->minimalCredentialRow([
            'uuid' => 'credentmv004',
            'relationship' => 'predecessor',
            'grace_expires_at' => '2026-07-20 00:00:00',
            'revoked_at' => '2026-07-19 00:00:00',
        ]));

        $row = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', 'credentmv004')->first();
        self::assertNotNull($row);
        self::assertSame('tenant0mv5cak', $row['tenant_uuid']);
        self::assertSame('lineagemv001', $row['lineage_uuid']);
        self::assertSame('fwkeymv00001', $row['framework_key_uuid']);
        self::assertSame(1, (int) $row['generation']);
        self::assertSame('predecessor', $row['relationship']);
        self::assertNotNull($row['grace_expires_at']);
        self::assertNotNull($row['created_at']);
        self::assertNotNull($row['revoked_at']);
    }

    public function testSellerApiKeyCredentialsUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_seller_api_key_credentials')->insert(
            $this->minimalCredentialRow(['uuid' => 'credentmv010', 'framework_key_uuid' => 'fwkeymv00010'])
        );

        $rejected = false;
        try {
            $this->connection->table('commerce_seller_api_key_credentials')->insert(
                $this->minimalCredentialRow(['uuid' => 'credentmv010', 'framework_key_uuid' => 'fwkeymv00011'])
            );
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'duplicate (tenant_uuid, uuid) credential insert must be rejected');
    }

    public function testSellerApiKeyCredentialsUniqueFrameworkKeyIsEnforced(): void
    {
        $this->connection->table('commerce_seller_api_key_credentials')->insert(
            $this->minimalCredentialRow(['uuid' => 'credentmv020', 'framework_key_uuid' => 'sharedfwkey1'])
        );

        $rejected = false;
        try {
            $this->connection->table('commerce_seller_api_key_credentials')->insert(
                $this->minimalCredentialRow(['uuid' => 'credentmv021', 'framework_key_uuid' => 'sharedfwkey1'])
            );
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue(
            $rejected,
            'duplicate (tenant_uuid, framework_key_uuid) credential insert must be rejected'
        );
    }

    public function testSellerApiKeyCredentialsUniqueLineageGenerationIsEnforced(): void
    {
        $this->connection->table('commerce_seller_api_key_credentials')->insert(
            $this->minimalCredentialRow([
                'uuid' => 'credentmv030',
                'lineage_uuid' => 'lineagemv030',
                'generation' => 1,
                'framework_key_uuid' => 'fwkeymv00030',
            ])
        );

        $rejected = false;
        try {
            $this->connection->table('commerce_seller_api_key_credentials')->insert(
                $this->minimalCredentialRow([
                    'uuid' => 'credentmv031',
                    'lineage_uuid' => 'lineagemv030',
                    'generation' => 1,
                    'framework_key_uuid' => 'fwkeymv00031',
                ])
            );
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue(
            $rejected,
            'duplicate (tenant_uuid, lineage_uuid, generation) credential insert must be rejected'
        );

        // The SAME lineage's next generation must succeed.
        $this->connection->table('commerce_seller_api_key_credentials')->insert(
            $this->minimalCredentialRow([
                'uuid' => 'credentmv032',
                'lineage_uuid' => 'lineagemv030',
                'generation' => 2,
                'framework_key_uuid' => 'fwkeymv00032',
            ])
        );
        self::assertSame(
            2,
            $this->connection->table('commerce_seller_api_key_credentials')
                ->where('lineage_uuid', '=', 'lineagemv030')
                ->count()
        );
    }

    public function testSellerApiKeyCredentialsHasLineageIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_api_key_credentials',
            'commerce_seller_api_key_credentials_lineage_index',
            ['tenant_uuid', 'lineage_uuid']
        );
    }

    public function testSellerApiKeyCredentialsHasLineageRelationshipIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_api_key_credentials',
            'commerce_seller_api_key_credentials_lineage_rel_index',
            ['tenant_uuid', 'lineage_uuid', 'relationship']
        );
    }

    // === commerce_seller_api_key_events (append-only audit, §3) =======================

    public function testSellerApiKeyEventsTableExists(): void
    {
        self::assertTrue(
            $this->connection->getSchemaBuilder()->hasTable('commerce_seller_api_key_events'),
            'missing table commerce_seller_api_key_events'
        );
    }

    public function testSellerApiKeyEventsHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'lineage_uuid', 'seller_uuid', 'subject_user_uuid', 'action',
            'actor_uuid', 'reason_code', 'bucket_start', 'predecessor_key_uuid', 'successor_key_uuid',
            'grace_expires_at', 'detail', 'created_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_api_key_events', $column),
                "commerce_seller_api_key_events missing column {$column}"
            );
        }
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function minimalEventRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'eventakmv001',
            'tenant_uuid' => 'tenant0mv5cak',
            'lineage_uuid' => 'lineagemv001',
            'seller_uuid' => 'sellerakmv01',
            'subject_user_uuid' => 'subjectakmv1',
            'action' => 'created',
        ], $overrides);
    }

    public function testSellerApiKeyEventsTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_api_key_events')->insert(
            array_diff_key(
                $this->minimalEventRow(['uuid' => 'eventakmv002']),
                ['tenant_uuid' => true]
            )
        );

        $row = $this->connection->table('commerce_seller_api_key_events')
            ->where('uuid', '=', 'eventakmv002')->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testSellerApiKeyEventsNullableColumnsDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_api_key_events')->insert(
            $this->minimalEventRow(['uuid' => 'eventakmv003'])
        );

        $row = $this->connection->table('commerce_seller_api_key_events')
            ->where('uuid', '=', 'eventakmv003')->first();
        self::assertNotNull($row);
        self::assertNull($row['actor_uuid']);
        self::assertNull($row['reason_code']);
        self::assertNull($row['bucket_start']);
        self::assertNull($row['predecessor_key_uuid']);
        self::assertNull($row['successor_key_uuid']);
        self::assertNull($row['grace_expires_at']);
        self::assertNull($row['detail']);
    }

    public function testSellerApiKeyEventsAcceptsAssignedValues(): void
    {
        $this->connection->table('commerce_seller_api_key_events')->insert($this->minimalEventRow([
            'uuid' => 'eventakmv004',
            'action' => 'rotated',
            'actor_uuid' => 'operatorakmv1',
            'predecessor_key_uuid' => 'fwkeymv00001',
            'successor_key_uuid' => 'fwkeymv00002',
            'grace_expires_at' => '2026-07-21 00:00:00',
            'detail' => 'Rotated at seller request.',
        ]));

        $row = $this->connection->table('commerce_seller_api_key_events')
            ->where('uuid', '=', 'eventakmv004')->first();
        self::assertNotNull($row);
        self::assertSame('tenant0mv5cak', $row['tenant_uuid']);
        self::assertSame('lineagemv001', $row['lineage_uuid']);
        self::assertSame('sellerakmv01', $row['seller_uuid']);
        self::assertSame('subjectakmv1', $row['subject_user_uuid']);
        self::assertSame('rotated', $row['action']);
        self::assertSame('operatorakmv1', $row['actor_uuid']);
        self::assertSame('fwkeymv00001', $row['predecessor_key_uuid']);
        self::assertSame('fwkeymv00002', $row['successor_key_uuid']);
        self::assertNotNull($row['grace_expires_at']);
        self::assertSame('Rotated at seller request.', $row['detail']);
        self::assertNotNull($row['created_at']);
    }

    public function testSellerApiKeyEventsUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_seller_api_key_events')->insert(
            $this->minimalEventRow(['uuid' => 'eventakmv010'])
        );

        $rejected = false;
        try {
            $this->connection->table('commerce_seller_api_key_events')->insert(
                $this->minimalEventRow(['uuid' => 'eventakmv010'])
            );
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'duplicate (tenant_uuid, uuid) event insert must be rejected');
    }

    /**
     * The load-bearing portability proof (design spec §2.10/§3, Task 2
     * brief): permanent mutation events (`created`/`rotated`/`revoked`)
     * ALWAYS leave `reason_code`/`bucket_start` NULL, and standard ANSI SQL
     * unique-index semantics treat NULL as distinct from NULL -- so any
     * number of permanent events for the SAME lineage (even the SAME
     * action) coexist freely. This is the exact null-exempt-unique idiom
     * already proven for `commerce_seller_reserves` (migration 015) and
     * `commerce_downloads`/`commerce_download_grants` (migration 008).
     */
    public function testSellerApiKeyEventsDedupeUniqueAllowsMultiplePermanentEventsWithNullReasonAndBucket(): void
    {
        // Two `created` rows, same tenant + lineage, both null reason_code/bucket_start.
        $this->connection->table('commerce_seller_api_key_events')->insert(
            $this->minimalEventRow(['uuid' => 'eventakmv020', 'lineage_uuid' => 'lineagemv020', 'action' => 'created'])
        );
        $this->connection->table('commerce_seller_api_key_events')->insert(
            $this->minimalEventRow(['uuid' => 'eventakmv021', 'lineage_uuid' => 'lineagemv020', 'action' => 'created'])
        );

        // A `rotated` row for the same lineage, also null reason_code/bucket_start.
        $this->connection->table('commerce_seller_api_key_events')->insert(
            $this->minimalEventRow(['uuid' => 'eventakmv022', 'lineage_uuid' => 'lineagemv020', 'action' => 'rotated'])
        );

        self::assertSame(
            3,
            $this->connection->table('commerce_seller_api_key_events')
                ->where('lineage_uuid', '=', 'lineagemv020')
                ->where('reason_code', '=', null)
                ->where('bucket_start', '=', null)
                ->count()
        );
    }

    /**
     * The other half of the portability proof: two `auth_denied` rows
     * sharing the SAME (tenant, lineage, reason_code, bucket_start) DO
     * collide -- the UTC-minute dedupe backstop (design spec §2.10).
     */
    public function testSellerApiKeyEventsDedupeUniqueRejectsDuplicateAuthDeniedInSameBucket(): void
    {
        $bucket = '2026-07-19 10:05:00';

        $this->connection->table('commerce_seller_api_key_events')->insert(
            $this->minimalEventRow([
                'uuid' => 'eventakmv030',
                'lineage_uuid' => 'lineagemv030',
                'action' => 'auth_denied',
                'reason_code' => 'capability_denied',
                'bucket_start' => $bucket,
            ])
        );

        $rejected = false;
        try {
            $this->connection->table('commerce_seller_api_key_events')->insert(
                $this->minimalEventRow([
                    'uuid' => 'eventakmv031',
                    'lineage_uuid' => 'lineagemv030',
                    'action' => 'auth_denied',
                    'reason_code' => 'capability_denied',
                    'bucket_start' => $bucket,
                ])
            );
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue(
            $rejected,
            'duplicate (tenant, lineage, action, reason_code, bucket_start) auth_denied row must be rejected'
        );

        // A DIFFERENT reason_code in the SAME bucket must be independent and succeed.
        $this->connection->table('commerce_seller_api_key_events')->insert(
            $this->minimalEventRow([
                'uuid' => 'eventakmv032',
                'lineage_uuid' => 'lineagemv030',
                'action' => 'auth_denied',
                'reason_code' => 'scope_drift',
                'bucket_start' => $bucket,
            ])
        );
        self::assertSame(
            2,
            $this->connection->table('commerce_seller_api_key_events')
                ->where('lineage_uuid', '=', 'lineagemv030')
                ->where('action', '=', 'auth_denied')
                ->count()
        );
    }

    public function testSellerApiKeyEventsHasSellerCreatedIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_api_key_events',
            'commerce_seller_api_key_events_seller_created_index',
            ['tenant_uuid', 'seller_uuid', 'created_at']
        );
    }

    public function testSellerApiKeyEventsHasActionCreatedIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_api_key_events',
            'commerce_seller_api_key_events_action_created_index',
            ['action', 'created_at']
        );
    }

    // === Migration idempotency =========================================================

    public function testRerunning018MigrationIsANoOp(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $migration = new CreateSellerApiKeysTables();

        // setUp() already ran this migration once via CommerceTestCase::MIGRATIONS;
        // re-running up() must be a no-op guarded by hasTable().
        $migration->up($schema);
        $migration->up($schema);

        self::assertTrue($schema->hasTable('commerce_seller_api_keys'));
        self::assertTrue($schema->hasTable('commerce_seller_api_key_credentials'));
        self::assertTrue($schema->hasTable('commerce_seller_api_key_events'));
        self::assertTrue($schema->hasColumn('commerce_seller_api_keys', 'declared_scopes'));
    }

    // === FixedSellerRoleAuthority (§3) =================================================

    public function testApiKeysManageCapabilityConstantHasTheSpecSlug(): void
    {
        self::assertSame('commerce.seller.apikeys.manage', FixedSellerRoleAuthority::APIKEYS_MANAGE);
    }

    public function testApiKeysManageIsGrantedToOwnerAndAdminOnly(): void
    {
        $authority = new FixedSellerRoleAuthority();

        self::assertTrue($authority->allows('seller_owner', FixedSellerRoleAuthority::APIKEYS_MANAGE));
        self::assertTrue($authority->allows('seller_admin', FixedSellerRoleAuthority::APIKEYS_MANAGE));
        self::assertFalse($authority->allows('seller_staff', FixedSellerRoleAuthority::APIKEYS_MANAGE));
        self::assertFalse($authority->allows('seller_analyst', FixedSellerRoleAuthority::APIKEYS_MANAGE));
    }

    // === SellerApiKeyCapabilityCatalog (§2.5) ==========================================

    public function testCapabilityCatalogListsExactlyTheGrantableSet(): void
    {
        self::assertSame([
            'commerce.seller.catalog.read',
            'commerce.seller.catalog.write',
            'commerce.seller.inventory.read',
            'commerce.seller.inventory.write',
            'commerce.seller.orders.read',
            'commerce.seller.orders.fulfill',
            'commerce.seller.reports.read',
            'commerce.seller.payouts.read',
        ], SellerApiKeyCapabilityCatalog::all());
    }

    public function testCapabilityCatalogContainsEveryGrantableSlug(): void
    {
        foreach (SellerApiKeyCapabilityCatalog::all() as $capability) {
            self::assertTrue(SellerApiKeyCapabilityCatalog::contains($capability));
        }
    }

    public function testCapabilityCatalogExcludesApiKeysManageAndMembersManage(): void
    {
        self::assertFalse(SellerApiKeyCapabilityCatalog::contains('commerce.seller.apikeys.manage'));
        self::assertFalse(SellerApiKeyCapabilityCatalog::contains('commerce.seller.members.manage'));
        self::assertFalse(SellerApiKeyCapabilityCatalog::contains(FixedSellerRoleAuthority::APIKEYS_MANAGE));
    }

    public function testCapabilityCatalogRejectsUnknownAndWildcardSlugs(): void
    {
        self::assertFalse(SellerApiKeyCapabilityCatalog::contains(''));
        self::assertFalse(SellerApiKeyCapabilityCatalog::contains('*'));
        self::assertFalse(SellerApiKeyCapabilityCatalog::contains('commerce.seller.*'));
        self::assertFalse(SellerApiKeyCapabilityCatalog::contains('commerce.seller.not.a.real.capability'));
    }

    // === config/commerce.php (§2.10) ===================================================

    public function testAuthDeniedRetentionDaysConfigDefaultsToNinety(): void
    {
        self::assertSame(
            90,
            config($this->appContext(), 'commerce.marketplace.api_keys.auth_denied_retention_days')
        );
    }

    // === DiagnosticsReport (§3) =========================================================

    public function testDiagnosticsCommerceTablesIncludesAllThreeSellerApiKeyTables(): void
    {
        foreach (
            [
            'commerce_seller_api_keys',
            'commerce_seller_api_key_credentials',
            'commerce_seller_api_key_events',
            ] as $table
        ) {
            self::assertContains(
                $table,
                DiagnosticsReport::commerceTables(),
                "DiagnosticsReport::commerceTables() missing {$table}"
            );
        }
    }

    public function testDiagnosticsTenantTablesIncludesAllThreeSellerApiKeyTables(): void
    {
        foreach (
            [
            'commerce_seller_api_keys',
            'commerce_seller_api_key_credentials',
            'commerce_seller_api_key_events',
            ] as $table
        ) {
            self::assertContains(
                $table,
                DiagnosticsReport::tenantTables(),
                "DiagnosticsReport::tenantTables() missing {$table}"
            );
        }
    }

    public function testDiagnosticsReportBuildShowsAllThreeSellerApiKeyTablesPresent(): void
    {
        $report = DiagnosticsReport::build($this->appContext());

        foreach (
            [
            'commerce_seller_api_keys',
            'commerce_seller_api_key_credentials',
            'commerce_seller_api_key_events',
            ] as $table
        ) {
            self::assertTrue(
                $report['database']['commerce_tables_present'][$table] ?? false,
                "DiagnosticsReport::build() must report {$table} as present"
            );
        }
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
    // above prove the SHAPE; this proves the SAME migration (018's three
    // brand-new tables) converges on a genuinely different engine -- every
    // index via `pg_indexes` (identical generated names across drivers --
    // {@see \Glueful\Database\Schema\TableBuilder::generateIndexName()} in
    // the framework -- so the SAME pinned names from the SQLite tests above
    // apply here unchanged), and -- the mandatory portability proof (Task 2
    // brief) -- that the nullable composite-unique dedupe backstop behaves
    // IDENTICALLY on real PostgreSQL: permanent events with null reason/
    // bucket coexist, duplicate same-bucket auth_denied rows collide.
    // Gating, fixture-width discipline, and the throwaway `Connection`/
    // `ApplicationContext` construction all mirror `SellerLifecycleShapeTest`'s
    // own pgsql lanes exactly.
    // =====================================================================

    public function testFreshInstallConvergesOnRealPostgresWithAllThreeSellerApiKeyTables(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();

        foreach (
            [
            'commerce_seller_api_keys' => [
                'id', 'uuid', 'tenant_uuid', 'seller_uuid', 'subject_user_uuid', 'declared_scopes',
                'name', 'status', 'current_credential_uuid', 'expires_at', 'revision', 'created_by',
                'created_at', 'updated_at', 'last_rotated_at', 'revoked_at',
            ],
            'commerce_seller_api_key_credentials' => [
                'id', 'uuid', 'tenant_uuid', 'lineage_uuid', 'framework_key_uuid', 'generation',
                'relationship', 'grace_expires_at', 'created_at', 'revoked_at',
            ],
            'commerce_seller_api_key_events' => [
                'id', 'uuid', 'tenant_uuid', 'lineage_uuid', 'seller_uuid', 'subject_user_uuid', 'action',
                'actor_uuid', 'reason_code', 'bucket_start', 'predecessor_key_uuid', 'successor_key_uuid',
                'grace_expires_at', 'detail', 'created_at',
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

    public function testSellerApiKeyIndexesExistOnRealPostgresViaPgIndexes(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());

        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_api_keys',
            'commerce_seller_api_keys_seller_status_index',
            ['tenant_uuid', 'seller_uuid', 'status']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_api_key_credentials',
            'commerce_seller_api_key_credentials_lineage_rel_index',
            ['tenant_uuid', 'lineage_uuid', 'relationship']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_api_key_events',
            'commerce_seller_api_key_events_action_created_index',
            ['action', 'created_at']
        );
    }

    public function testSellerApiKeyEventsDedupeUniqueBehavesIdenticallyOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        // Self-healing cleanup: real commits against the persistent PostgreSQL
        // test database, unlike every SQLite test in this file.
        $connection->table('commerce_seller_api_key_events')
            ->where('tenant_uuid', '=', 'tntpgsellerak')->delete();

        $row = static fn (array $overrides): array => array_merge([
            'tenant_uuid' => 'tntpgsellerak',
            'lineage_uuid' => 'pglineageak01',
            'seller_uuid' => 'pgsellerak001',
            'subject_user_uuid' => 'pgsubjectak01',
            'action' => 'created',
        ], $overrides);

        // Two permanent events, same lineage/action, null reason/bucket -- must coexist.
        $connection->table('commerce_seller_api_key_events')->insert($row(['uuid' => 'pgeventak0001']));
        $connection->table('commerce_seller_api_key_events')->insert($row(['uuid' => 'pgeventak0002']));
        self::assertSame(
            2,
            $connection->table('commerce_seller_api_key_events')
                ->where('lineage_uuid', '=', 'pglineageak01')
                ->count()
        );

        // Two auth_denied rows in the SAME bucket -- must collide.
        $connection->table('commerce_seller_api_key_events')->insert($row([
            'uuid' => 'pgeventak0003',
            'action' => 'auth_denied',
            'reason_code' => 'capability_denied',
            'bucket_start' => '2026-07-19 10:05:00',
        ]));

        $rejected = false;
        try {
            $connection->table('commerce_seller_api_key_events')->insert($row([
                'uuid' => 'pgeventak0004',
                'action' => 'auth_denied',
                'reason_code' => 'capability_denied',
                'bucket_start' => '2026-07-19 10:05:00',
            ]));
        } catch (\Throwable) {
            $rejected = true;
        }

        $connection->table('commerce_seller_api_key_events')
            ->where('tenant_uuid', '=', 'tntpgsellerak')->delete();

        self::assertTrue(
            $rejected,
            'duplicate same-bucket auth_denied rows must collide on PostgreSQL exactly as on SQLite'
        );
    }

    public function testRerunning018MigrationIsANoOpOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();

        // migratedConnection() already ran every migration (including 018)
        // once; re-running up() again must be a no-op guarded by hasTable().
        (new CreateSellerApiKeysTables())->up($schema);

        self::assertTrue($schema->hasTable('commerce_seller_api_keys'));
        self::assertTrue($schema->hasTable('commerce_seller_api_key_credentials'));
        self::assertTrue($schema->hasTable('commerce_seller_api_key_events'));
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
