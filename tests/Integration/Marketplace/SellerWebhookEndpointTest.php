<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Encryption\EncryptionService;
use Glueful\Encryption\Exceptions\DecryptionException;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookException;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookSecretService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Security\SafeOutboundTargetResolver;
use Glueful\Validation\ValidationException;

/**
 * Marketplace MV5c-2 Task 3 (design spec §2.2/§2.6/§2.9/§2.10): the
 * seller-webhook endpoint management SERVICE -- register/update/
 * rotate-secret/disable/enable/delete -- live authority under seller
 * revision, encrypted AAD-bound secrets, SSRF-at-registration, and the
 * tombstone-delete lifecycle.
 */
final class SellerWebhookEndpointTest extends CommerceTestCase
{
    private const TENANT = 'tenantWEBHOOK1';

    /** A genuinely public, non-reserved unicast address -- the default "safe" DNS answer. */
    private const SAFE_IP = '1.1.1.1';
    private const PRIVATE_IP = '10.0.0.5';
    private const METADATA_IP = '169.254.169.254';

    // -----------------------------------------------------------------
    // register(): SSRF validation
    // -----------------------------------------------------------------

    public function testRegisterSsrfRejectsAnHttpUrl(): void
    {
        $this->seedSeller(self::TENANT, 'sellerWH00001', 'active');
        $this->seedMembership(self::TENANT, 'sellerWH00001', 'ownerWH00001', 'seller_owner');

        try {
            $this->service()->register(
                $this->context,
                self::TENANT,
                'sellerWH00001',
                'http://whsafe.example.test/hook',
                ['order.placed'],
                'ownerWH00001'
            );
            self::fail('expected SellerWebhookException: http is not allowed');
        } catch (SellerWebhookException $e) {
            self::assertSame('unsafe_url', $e->errorCode);
            $this->assertMessageLeaksNoInternalAddress($e->getMessage());
        }
    }

    public function testRegisterSsrfRejectsCredentialsInUrl(): void
    {
        $this->seedSeller(self::TENANT, 'sellerWH00002', 'active');
        $this->seedMembership(self::TENANT, 'sellerWH00002', 'ownerWH00002', 'seller_owner');

        try {
            $this->service()->register(
                $this->context,
                self::TENANT,
                'sellerWH00002',
                'https://user:pass@whsafe.example.test/hook',
                ['order.placed'],
                'ownerWH00002'
            );
            self::fail('expected SellerWebhookException: credentials are not allowed');
        } catch (SellerWebhookException $e) {
            self::assertSame('unsafe_url', $e->errorCode);
            $this->assertMessageLeaksNoInternalAddress($e->getMessage());
        }
    }

    public function testRegisterSsrfRejectsAnIpLiteralHost(): void
    {
        $this->seedSeller(self::TENANT, 'sellerWH00003', 'active');
        $this->seedMembership(self::TENANT, 'sellerWH00003', 'ownerWH00003', 'seller_owner');

        try {
            $this->service()->register(
                $this->context,
                self::TENANT,
                'sellerWH00003',
                'https://93.184.216.34/hook',
                ['order.placed'],
                'ownerWH00003'
            );
            self::fail('expected SellerWebhookException: IP-literal hosts are not allowed');
        } catch (SellerWebhookException $e) {
            self::assertSame('unsafe_url', $e->errorCode);
            $this->assertMessageLeaksNoInternalAddress($e->getMessage());
        }
    }

    public function testRegisterSsrfRejectsAHostResolvingToAPrivateAddress(): void
    {
        $this->seedSeller(self::TENANT, 'sellerWH00004', 'active');
        $this->seedMembership(self::TENANT, 'sellerWH00004', 'ownerWH00004', 'seller_owner');

        try {
            $this->service()->register(
                $this->context,
                self::TENANT,
                'sellerWH00004',
                'https://whprivate.example.test/hook',
                ['order.placed'],
                'ownerWH00004'
            );
            self::fail('expected SellerWebhookException: resolves to a private address');
        } catch (SellerWebhookException $e) {
            self::assertSame('unsafe_url', $e->errorCode);
            $this->assertMessageLeaksNoInternalAddress($e->getMessage());
        }
    }

    public function testRegisterSsrfRejectsAHostResolvingToTheCloudMetadataAddress(): void
    {
        $this->seedSeller(self::TENANT, 'sellerWH00005', 'active');
        $this->seedMembership(self::TENANT, 'sellerWH00005', 'ownerWH00005', 'seller_owner');

        try {
            $this->service()->register(
                $this->context,
                self::TENANT,
                'sellerWH00005',
                'https://whmetadata.example.test/hook',
                ['order.placed'],
                'ownerWH00005'
            );
            self::fail('expected SellerWebhookException: resolves to the metadata address');
        } catch (SellerWebhookException $e) {
            self::assertSame('unsafe_url', $e->errorCode);
            $this->assertMessageLeaksNoInternalAddress($e->getMessage());
        }
    }

    private function assertMessageLeaksNoInternalAddress(string $message): void
    {
        foreach ([self::PRIVATE_IP, self::METADATA_IP, '127.0.0.1', '10.0.0.', '169.254.'] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $message,
                "SSRF error message must never leak an internal address; got: {$message}"
            );
        }
    }

    // -----------------------------------------------------------------
    // register(): event catalog validation
    // -----------------------------------------------------------------

    public function testRegisterRejectsAnEmptyEventList(): void
    {
        $this->seedSeller(self::TENANT, 'sellerWH00006', 'active');
        $this->seedMembership(self::TENANT, 'sellerWH00006', 'ownerWH00006', 'seller_owner');

        $this->expectException(ValidationException::class);

        $this->service()->register(
            $this->context,
            self::TENANT,
            'sellerWH00006',
            'https://whsafe.example.test/hook',
            [],
            'ownerWH00006'
        );
    }

    public function testRegisterRejectsANonCatalogEvent(): void
    {
        $this->seedSeller(self::TENANT, 'sellerWH00007', 'active');
        $this->seedMembership(self::TENANT, 'sellerWH00007', 'ownerWH00007', 'seller_owner');

        $this->expectException(ValidationException::class);

        $this->service()->register(
            $this->context,
            self::TENANT,
            'sellerWH00007',
            'https://whsafe.example.test/hook',
            ['order.placed', 'refund.failed'],
            'ownerWH00007'
        );
    }

    public function testRegisterRejectsAWildcardEvent(): void
    {
        $this->seedSeller(self::TENANT, 'sellerWH00008', 'active');
        $this->seedMembership(self::TENANT, 'sellerWH00008', 'ownerWH00008', 'seller_owner');

        $this->expectException(ValidationException::class);

        $this->service()->register(
            $this->context,
            self::TENANT,
            'sellerWH00008',
            'https://whsafe.example.test/hook',
            ['*'],
            'ownerWH00008'
        );
    }

    // -----------------------------------------------------------------
    // register(): happy path -- secret returned once, ciphertext stored,
    // AAD-bound, no plaintext leakage.
    // -----------------------------------------------------------------

    public function testRegisterReturnsTheRawSecretOnceAndStoresAadBoundCiphertext(): void
    {
        $this->seedSeller(self::TENANT, 'sellerWH00009', 'active');
        $this->seedMembership(self::TENANT, 'sellerWH00009', 'ownerWH00009', 'seller_owner');

        $result = $this->service()->register(
            $this->context,
            self::TENANT,
            'sellerWH00009',
            'https://whsafe.example.test/hook',
            ['order.placed', 'order.paid'],
            'ownerWH00009'
        );

        self::assertArrayHasKey('secret', $result);
        self::assertIsString($result['secret']);
        self::assertNotSame('', $result['secret']);

        $endpoint = $result['endpoint'];
        self::assertSame('sellerWH00009', $endpoint['seller_uuid']);
        self::assertSame('https://whsafe.example.test/hook', $endpoint['url']);
        // Canonical (sorted) form, mirroring SellerApiKeyScopeValidator's convention.
        self::assertSame(['order.paid', 'order.placed'], $endpoint['subscribed_events']);
        self::assertSame('active', $endpoint['status']);
        self::assertSame(0, (int) $endpoint['revision']);
        self::assertSame('ownerWH00009', $endpoint['created_by']);

        $secretRow = $this->connection->table('commerce_seller_webhook_secrets')
            ->where('endpoint_uuid', '=', $endpoint['uuid'])
            ->first();
        self::assertNotNull($secretRow);
        self::assertSame('current', $secretRow['relationship']);

        // Plaintext is NEVER in the row.
        self::assertStringNotContainsString($result['secret'], $secretRow['secret_ciphertext']);
        self::assertNotSame($result['secret'], $secretRow['secret_ciphertext']);

        // Decryptable with the correct AAD.
        $encryption = $this->encryptionService();
        $aad = self::TENANT . ':' . $endpoint['uuid'] . ':' . $secretRow['uuid'];
        self::assertSame($result['secret'], $encryption->decrypt($secretRow['secret_ciphertext'], $aad));

        // AAD mismatch fails to decrypt.
        $this->expectException(DecryptionException::class);
        $encryption->decrypt($secretRow['secret_ciphertext'], 'wrong-aad');
    }

    public function testRegisterWritesARegisterAuditRow(): void
    {
        $this->seedSeller(self::TENANT, 'sellerWH00010', 'active');
        $this->seedMembership(self::TENANT, 'sellerWH00010', 'ownerWH00010', 'seller_owner');

        $result = $this->service()->register(
            $this->context,
            self::TENANT,
            'sellerWH00010',
            'https://whsafe.example.test/hook',
            ['order.placed'],
            'ownerWH00010'
        );

        $event = $this->connection->table('commerce_seller_webhook_endpoint_events')
            ->where('endpoint_uuid', '=', $result['endpoint']['uuid'])
            ->where('action', '=', 'register')
            ->first();
        self::assertNotNull($event);
        self::assertSame('ownerWH00010', $event['actor_uuid']);
        self::assertSame('sellerWH00010', $event['seller_uuid']);
    }

    // -----------------------------------------------------------------
    // Live authority under seller revision
    // -----------------------------------------------------------------

    public function testRegisterRefusesASuspendedSeller(): void
    {
        $this->seedSeller(self::TENANT, 'sellerWH00011', 'suspended');
        $this->seedMembership(self::TENANT, 'sellerWH00011', 'ownerWH00011', 'seller_owner');

        try {
            $this->service()->register(
                $this->context,
                self::TENANT,
                'sellerWH00011',
                'https://whsafe.example.test/hook',
                ['order.placed'],
                'ownerWH00011'
            );
            self::fail('expected SellerWebhookException: seller suspended');
        } catch (SellerWebhookException $e) {
            self::assertSame('seller_inactive', $e->errorCode);
        }
    }

    public function testRegisterRefusesAnActorWithoutWebhooksManage(): void
    {
        $this->seedSeller(self::TENANT, 'sellerWH00012', 'active');
        $this->seedMembership(self::TENANT, 'sellerWH00012', 'staffWH00012', 'seller_staff');

        try {
            $this->service()->register(
                $this->context,
                self::TENANT,
                'sellerWH00012',
                'https://whsafe.example.test/hook',
                ['order.placed'],
                'staffWH00012'
            );
            self::fail('expected SellerWebhookException: seller_staff lacks webhooks.manage');
        } catch (SellerWebhookException $e) {
            self::assertSame('capability_denied', $e->errorCode);
        }
    }

    public function testMutationRefusesADemotedActor(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00013', 'ownerWH00013');

        $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', 'sellerWH00013')
            ->where('user_uuid', '=', 'ownerWH00013')
            ->update(['role' => 'seller_staff']);

        try {
            $this->service()->disable(
                $this->context,
                self::TENANT,
                'sellerWH00013',
                $seeded['endpoint']['uuid'],
                'ownerWH00013'
            );
            self::fail('expected SellerWebhookException: demoted actor lacks webhooks.manage');
        } catch (SellerWebhookException $e) {
            self::assertSame('capability_denied', $e->errorCode);
        }

        // Rolled back: still active, revision unchanged.
        $endpoint = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $seeded['endpoint']['uuid'])->first();
        self::assertSame('active', $endpoint['status']);
        self::assertSame(0, (int) $endpoint['revision']);
    }

    public function testMutationRefusesARemovedMembership(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00014', 'ownerWH00014');

        $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', 'sellerWH00014')
            ->where('user_uuid', '=', 'ownerWH00014')
            ->update(['status' => 'revoked']);

        try {
            $this->service()->disable(
                $this->context,
                self::TENANT,
                'sellerWH00014',
                $seeded['endpoint']['uuid'],
                'ownerWH00014'
            );
            self::fail('expected SellerWebhookException: removed membership');
        } catch (SellerWebhookException $e) {
            self::assertSame('membership_inactive', $e->errorCode);
        }
    }

    public function testMutationRefusesASuspendedSeller(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00015', 'ownerWH00015');

        $this->connection->table('commerce_sellers')
            ->where('uuid', '=', 'sellerWH00015')
            ->update(['status' => 'suspended']);

        try {
            $this->service()->disable(
                $this->context,
                self::TENANT,
                'sellerWH00015',
                $seeded['endpoint']['uuid'],
                'ownerWH00015'
            );
            self::fail('expected SellerWebhookException: suspended seller');
        } catch (SellerWebhookException $e) {
            self::assertSame('seller_inactive', $e->errorCode);
        }
    }

    // -----------------------------------------------------------------
    // update(): url/event revalidation, never leaks a secret.
    // -----------------------------------------------------------------

    public function testUpdateUrlChangeRevalidatesSsrfAndAcceptsASafeUrl(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00016', 'ownerWH00016');

        $result = $this->service()->updateEndpoint(
            $this->context,
            self::TENANT,
            'sellerWH00016',
            $seeded['endpoint']['uuid'],
            'https://whsafe.example.test/new-hook',
            null,
            'ownerWH00016'
        );

        self::assertSame('https://whsafe.example.test/new-hook', $result['endpoint']['url']);
        self::assertArrayNotHasKey('secret', $result);

        $event = $this->connection->table('commerce_seller_webhook_endpoint_events')
            ->where('endpoint_uuid', '=', $seeded['endpoint']['uuid'])
            ->where('action', '=', 'url_change')
            ->first();
        self::assertNotNull($event);
    }

    public function testUpdateUrlChangeRejectsAnUnsafeUrlAndNeverPersistsIt(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00017', 'ownerWH00017');

        try {
            $this->service()->updateEndpoint(
                $this->context,
                self::TENANT,
                'sellerWH00017',
                $seeded['endpoint']['uuid'],
                'https://whprivate.example.test/hook',
                null,
                'ownerWH00017'
            );
            self::fail('expected SellerWebhookException: unsafe url');
        } catch (SellerWebhookException $e) {
            self::assertSame('unsafe_url', $e->errorCode);
        }

        $endpoint = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $seeded['endpoint']['uuid'])->first();
        self::assertSame($seeded['endpoint']['url'], $endpoint['url']);
    }

    public function testUpdateEventsRevalidatesAgainstTheCatalog(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00018', 'ownerWH00018');

        $this->expectException(ValidationException::class);

        $this->service()->updateEndpoint(
            $this->context,
            self::TENANT,
            'sellerWH00018',
            $seeded['endpoint']['uuid'],
            null,
            ['order.note_added'],
            'ownerWH00018'
        );
    }

    public function testUpdateEventsAcceptsACatalogSubset(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00019', 'ownerWH00019');

        $result = $this->service()->updateEndpoint(
            $this->context,
            self::TENANT,
            'sellerWH00019',
            $seeded['endpoint']['uuid'],
            null,
            ['stock.adjusted', 'product.adopted'],
            'ownerWH00019'
        );

        self::assertSame(['product.adopted', 'stock.adjusted'], $result['endpoint']['subscribed_events']);
    }

    // -----------------------------------------------------------------
    // rotateSecret()
    // -----------------------------------------------------------------

    public function testRotateSecretMintsANewSecretOnceAndMovesCurrentToPreviousWithOverlap(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00020', 'ownerWH00020');
        $endpointUuid = $seeded['endpoint']['uuid'];
        $originalSecret = $seeded['secret'];

        $result = $this->service()->rotateSecret(
            $this->context,
            self::TENANT,
            'sellerWH00020',
            $endpointUuid,
            'ownerWH00020'
        );

        self::assertArrayHasKey('secret', $result);
        self::assertNotSame($originalSecret, $result['secret']);

        $rows = $this->connection->table('commerce_seller_webhook_secrets')
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->orderBy('created_at', 'ASC')
            ->orderBy('uuid', 'ASC')
            ->get();
        self::assertCount(2, $rows);

        $byRelationship = [];
        foreach ($rows as $row) {
            $byRelationship[$row['relationship']][] = $row;
        }

        self::assertCount(1, $byRelationship['current'] ?? []);
        self::assertCount(1, $byRelationship['previous'] ?? []);

        $current = $byRelationship['current'][0];
        $previous = $byRelationship['previous'][0];

        // The successor is the new current secret.
        $encryption = $this->encryptionService();
        $currentAad = self::TENANT . ':' . $endpointUuid . ':' . $current['uuid'];
        self::assertSame($result['secret'], $encryption->decrypt($current['secret_ciphertext'], $currentAad));

        // The OLD current secret became previous, ciphertext untouched, still decrypts to the ORIGINAL secret.
        $previousAad = self::TENANT . ':' . $endpointUuid . ':' . $previous['uuid'];
        self::assertSame($originalSecret, $encryption->decrypt($previous['secret_ciphertext'], $previousAad));
        self::assertNotNull($previous['overlap_expires_at']);
        self::assertNull($previous['revoked_at']);

        $event = $this->connection->table('commerce_seller_webhook_endpoint_events')
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->where('action', '=', 'secret_rotate')
            ->first();
        self::assertNotNull($event);
    }

    public function testRotateSecretTwiceRetiresTheOlderPreviousSecret(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00021', 'ownerWH00021');
        $endpointUuid = $seeded['endpoint']['uuid'];

        $first = $this->service()->rotateSecret(
            $this->context,
            self::TENANT,
            'sellerWH00021',
            $endpointUuid,
            'ownerWH00021'
        );
        $this->service()->rotateSecret($this->context, self::TENANT, 'sellerWH00021', $endpointUuid, 'ownerWH00021');

        $rows = $this->connection->table('commerce_seller_webhook_secrets')
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->get();
        self::assertCount(3, $rows, 'original (now retired) + previous + current');

        $byRelationship = [];
        foreach ($rows as $row) {
            $byRelationship[$row['relationship']][] = $row;
        }

        // Exactly one live (unrevoked) previous.
        $isLive = static fn (array $r): bool => $r['revoked_at'] === null;
        $isRetired = static fn (array $r): bool => $r['revoked_at'] !== null;
        $livePrevious = array_filter($byRelationship['previous'] ?? [], $isLive);
        self::assertCount(1, $livePrevious);

        // The original secret (now demoted-then-retired) carries a revoked_at.
        $retired = array_filter($byRelationship['previous'] ?? [], $isRetired);
        self::assertCount(1, $retired);

        self::assertCount(1, $byRelationship['current'] ?? []);
        self::assertNotSame($first['secret'], null);
    }

    // -----------------------------------------------------------------
    // disable()/enable()
    // -----------------------------------------------------------------

    public function testDisablePausesPendingDeliveriesWithEndpointDisabledReason(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00022', 'ownerWH00022');
        $endpointUuid = $seeded['endpoint']['uuid'];

        $this->seedDelivery('deliverywh0022a', $endpointUuid, 'sellerWH00022', [
            'status' => 'pending',
            'next_attempt_at' => null,
        ]);
        $this->seedDelivery('deliverywh0022b', $endpointUuid, 'sellerWH00022', [
            'status' => 'pending',
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 300),
        ]);

        $result = $this->service()->disable(
            $this->context,
            self::TENANT,
            'sellerWH00022',
            $endpointUuid,
            'ownerWH00022'
        );

        self::assertSame('disabled', $result['endpoint']['status']);
        self::assertNotNull($result['endpoint']['disabled_at']);

        $a = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', 'deliverywh0022a')->first();
        self::assertSame('paused', $a['status']);
        self::assertSame('endpoint_disabled', $a['pause_reason']);
        self::assertSame(0, (int) $a['paused_remaining_seconds']);
        self::assertNotNull($a['paused_at']);

        $b = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', 'deliverywh0022b')->first();
        self::assertSame('paused', $b['status']);
        self::assertSame('endpoint_disabled', $b['pause_reason']);
        self::assertGreaterThan(0, (int) $b['paused_remaining_seconds']);
        self::assertLessThanOrEqual(300, (int) $b['paused_remaining_seconds']);

        $event = $this->connection->table('commerce_seller_webhook_endpoint_events')
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->where('action', '=', 'disable')
            ->first();
        self::assertNotNull($event);
    }

    public function testEnableRevalidatesTheStoredUrlResetsFailuresAndResumesOnlyEndpointDisabledDeliveries(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00023', 'ownerWH00023');
        $endpointUuid = $seeded['endpoint']['uuid'];

        // Simulate accumulated failures.
        $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $endpointUuid)
            ->update(['consecutive_failures' => 7]);

        $this->seedDelivery('deliverywh0023a', $endpointUuid, 'sellerWH00023', [
            'status' => 'paused',
            'pause_reason' => 'endpoint_disabled',
            'paused_at' => gmdate('Y-m-d H:i:s'),
            'paused_remaining_seconds' => 120,
        ]);
        // A seller_suspended-paused row must NEVER be touched by enable().
        $this->seedDelivery('deliverywh0023b', $endpointUuid, 'sellerWH00023', [
            'status' => 'paused',
            'pause_reason' => 'seller_suspended',
            'paused_at' => gmdate('Y-m-d H:i:s'),
            'paused_remaining_seconds' => 60,
        ]);

        $this->service()->disable($this->context, self::TENANT, 'sellerWH00023', $endpointUuid, 'ownerWH00023');

        $result = $this->service()->enable(
            $this->context,
            self::TENANT,
            'sellerWH00023',
            $endpointUuid,
            'ownerWH00023'
        );

        self::assertSame('active', $result['endpoint']['status']);
        self::assertSame(0, (int) $result['endpoint']['consecutive_failures']);
        self::assertNull($result['endpoint']['disabled_at']);

        $resumed = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', 'deliverywh0023a')->first();
        self::assertSame('pending', $resumed['status']);
        self::assertNull($resumed['pause_reason']);
        self::assertNull($resumed['paused_remaining_seconds']);
        self::assertNotNull($resumed['next_attempt_at']);

        $untouched = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', 'deliverywh0023b')->first();
        self::assertSame('paused', $untouched['status']);
        self::assertSame('seller_suspended', $untouched['pause_reason']);

        $event = $this->connection->table('commerce_seller_webhook_endpoint_events')
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->where('action', '=', 'enable')
            ->first();
        self::assertNotNull($event);
    }

    public function testEnableRejectsAndLeavesTheEndpointDisabledWhenTheStoredUrlIsNowUnsafe(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00024', 'ownerWH00024');
        $endpointUuid = $seeded['endpoint']['uuid'];

        $this->service()->disable($this->context, self::TENANT, 'sellerWH00024', $endpointUuid, 'ownerWH00024');

        // A different resolver instance -- the SAME hostname now resolves
        // (simulating a DNS rebind between registration and enable) to a
        // private address.
        $unsafeService = $this->service(dnsMap: [
            'whsafe.example.test' => [self::PRIVATE_IP],
        ]);

        try {
            $unsafeService->enable($this->context, self::TENANT, 'sellerWH00024', $endpointUuid, 'ownerWH00024');
            self::fail('expected SellerWebhookException: stored url now unsafe');
        } catch (SellerWebhookException $e) {
            self::assertSame('unsafe_url', $e->errorCode);
        }

        // Rolled back: still disabled.
        $endpoint = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $endpointUuid)->first();
        self::assertSame('disabled', $endpoint['status']);
    }

    // -----------------------------------------------------------------
    // delete(): tombstone
    // -----------------------------------------------------------------

    public function testDeleteTombstonesRevokesSecretsCancelsDeliveriesAndRetainsHistory(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00025', 'ownerWH00025');
        $endpointUuid = $seeded['endpoint']['uuid'];

        $this->seedDelivery('deliverywh0025a', $endpointUuid, 'sellerWH00025', ['status' => 'pending']);
        $this->seedDelivery('deliverywh0025b', $endpointUuid, 'sellerWH00025', [
            'status' => 'paused',
            'pause_reason' => 'seller_suspended',
        ]);

        $result = $this->service()->delete(
            $this->context,
            self::TENANT,
            'sellerWH00025',
            $endpointUuid,
            'ownerWH00025'
        );

        self::assertSame('deleted', $result['endpoint']['status']);
        self::assertNotNull($result['endpoint']['deleted_at']);

        // Invisible to a normal read...
        self::assertNull(
            $this->connection->table('commerce_seller_webhook_endpoints')->where('uuid', '=', $endpointUuid)->first()
        );
        // ...but still findable via withTrashed() (retained history).
        $trashed = $this->connection->table('commerce_seller_webhook_endpoints')
            ->withTrashed()->where('uuid', '=', $endpointUuid)->first();
        self::assertNotNull($trashed);
        self::assertSame('deleted', $trashed['status']);

        // Absent from the seller-facing list.
        $repo = new SellerWebhookEndpointRepository();
        self::assertSame([], $repo->listForSeller($this->context, self::TENANT, 'sellerWH00025'));

        // Secrets revoked.
        $secrets = $this->connection->table('commerce_seller_webhook_secrets')
            ->where('endpoint_uuid', '=', $endpointUuid)->get();
        self::assertNotEmpty($secrets);
        foreach ($secrets as $secret) {
            self::assertNotNull($secret['revoked_at']);
        }

        // Pending/paused deliveries canceled, retained.
        $a = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', 'deliverywh0025a')->first();
        self::assertSame('canceled', $a['status']);
        $b = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', 'deliverywh0025b')->first();
        self::assertSame('canceled', $b['status']);

        $event = $this->connection->table('commerce_seller_webhook_endpoint_events')
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->where('action', '=', 'delete')
            ->first();
        self::assertNotNull($event);
    }

    public function testASecondDeleteIsAStableNonRevealing404(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00026', 'ownerWH00026');
        $endpointUuid = $seeded['endpoint']['uuid'];

        $this->service()->delete($this->context, self::TENANT, 'sellerWH00026', $endpointUuid, 'ownerWH00026');

        $this->expectException(NotFoundException::class);
        $this->service()->delete($this->context, self::TENANT, 'sellerWH00026', $endpointUuid, 'ownerWH00026');
    }

    public function testEnablingADeletedEndpointIsAStableNonRevealing404(): void
    {
        $seeded = $this->registerEndpoint('sellerWH00027', 'ownerWH00027');
        $endpointUuid = $seeded['endpoint']['uuid'];

        $this->service()->delete($this->context, self::TENANT, 'sellerWH00027', $endpointUuid, 'ownerWH00027');

        $this->expectException(NotFoundException::class);
        $this->service()->enable($this->context, self::TENANT, 'sellerWH00027', $endpointUuid, 'ownerWH00027');
    }

    public function testMutatingAnUnknownEndpointIs404JustLikeADeletedOne(): void
    {
        $this->seedSeller(self::TENANT, 'sellerWH00028', 'active');
        $this->seedMembership(self::TENANT, 'sellerWH00028', 'ownerWH00028', 'seller_owner');

        $this->expectException(NotFoundException::class);
        $this->service()->disable(
            $this->context,
            self::TENANT,
            'sellerWH00028',
            'doesNotExist001',
            'ownerWH00028'
        );
    }

    // -----------------------------------------------------------------
    // Reads never return a secret; deleted excluded from list.
    // -----------------------------------------------------------------

    public function testListForSellerNeverIncludesASecretAndExcludesDeletedEndpoints(): void
    {
        $active = $this->registerEndpoint('sellerWH00029', 'ownerWH00029');
        $toDelete = $this->registerEndpoint('sellerWH00029', 'ownerWH00029', suffix: 'b');

        $this->service()->delete(
            $this->context,
            self::TENANT,
            'sellerWH00029',
            $toDelete['endpoint']['uuid'],
            'ownerWH00029'
        );

        $repo = new SellerWebhookEndpointRepository();
        $list = $repo->listForSeller($this->context, self::TENANT, 'sellerWH00029');

        self::assertCount(1, $list);
        self::assertSame($active['endpoint']['uuid'], $list[0]['uuid']);
        foreach ($list as $row) {
            self::assertArrayNotHasKey('secret', $row);
            self::assertArrayNotHasKey('secret_ciphertext', $row);
        }
    }

    // -----------------------------------------------------------------
    // Atomicity: register() rolls back endpoint + secret on an audit-insert collision.
    // -----------------------------------------------------------------

    public function testRegisterRollsBackEndpointAndSecretOnAForcedAuditUuidCollision(): void
    {
        $this->seedSeller(self::TENANT, 'sellerWH00030', 'active');
        $this->seedMembership(self::TENANT, 'sellerWH00030', 'ownerWH00030', 'seller_owner');

        // Pre-seed a row on the AUDIT table itself so the service's own
        // audit insert (forced below to reuse this exact uuid) collides on
        // the real `(tenant_uuid, uuid)` unique constraint.
        $this->connection->table('commerce_seller_webhook_endpoint_events')->insert([
            'uuid' => 'auditwhcollid1',
            'tenant_uuid' => self::TENANT,
            'endpoint_uuid' => 'irrelevantend1',
            'seller_uuid' => 'sellerWH00030',
            'action' => 'register',
        ]);

        $calls = 0;
        $uuidGenerator = static function () use (&$calls): string {
            $calls++;
            // 1st call: endpoint uuid. 2nd call: secret uuid. 3rd call (the
            // audit event uuid) collides with the pre-seeded row above,
            // forcing a unique-constraint failure on the LAST write.
            return match ($calls) {
                1 => 'endpointwhcol1',
                2 => 'secretwhcoll01',
                default => 'auditwhcollid1',
            };
        };

        try {
            $this->service($uuidGenerator)->register(
                $this->context,
                self::TENANT,
                'sellerWH00030',
                'https://whsafe.example.test/hook',
                ['order.placed'],
                'ownerWH00030'
            );
            self::fail('expected a uuid-collision failure to roll back the whole transaction');
        } catch (\Throwable) {
            // expected -- collision on the endpoint_events insert
        }

        self::assertNull(
            $this->connection->table('commerce_seller_webhook_endpoints')
                ->where('uuid', '=', 'endpointwhcol1')->first()
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_webhook_secrets')
                ->where('uuid', '=', 'secretwhcoll01')->count()
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return array{endpoint: array<string,mixed>, secret: string} */
    private function registerEndpoint(string $sellerUuid, string $ownerUuid, string $suffix = 'a'): array
    {
        if ($this->connection->table('commerce_sellers')->where('uuid', '=', $sellerUuid)->first() === null) {
            $this->seedSeller(self::TENANT, $sellerUuid, 'active');
            $this->seedMembership(self::TENANT, $sellerUuid, $ownerUuid, 'seller_owner');
        }

        return $this->service()->register(
            $this->context,
            self::TENANT,
            $sellerUuid,
            'https://whsafe.example.test/hook-' . $suffix,
            ['order.placed'],
            $ownerUuid
        );
    }

    /** @param array<string,mixed> $overrides */
    private function seedDelivery(string $uuid, string $endpointUuid, string $sellerUuid, array $overrides = []): void
    {
        $this->connection->table('commerce_seller_webhook_deliveries')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'endpoint_uuid' => $endpointUuid,
            'webhook_event_uuid' => 'wheventseed01',
            'seller_uuid' => $sellerUuid,
        ], $overrides));
    }

    private function seedSeller(string $tenant, string $uuid, string $status): void
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'status' => $status,
        ]);
    }

    private function seedMembership(
        string $tenant,
        string $sellerUuid,
        string $userUuid,
        string $role,
        string $status = 'active'
    ): void {
        $this->connection->table('commerce_seller_memberships')->insert([
            'uuid' => 'mbr' . substr(md5($sellerUuid . $userUuid), 0, 9),
            'tenant_uuid' => $tenant,
            'seller_uuid' => $sellerUuid,
            'user_uuid' => $userUuid,
            'role' => $role,
            'status' => $status,
        ]);
    }

    private function encryptionService(): EncryptionService
    {
        static $encryption = null;
        if ($encryption === null) {
            $this->context->overrideConfig('encryption.key', 'base64:' . base64_encode(str_repeat('k', 32)));
            $encryption = new EncryptionService($this->context);
        }

        return $encryption;
    }

    /**
     * @param array<string,list<string>> $dnsMap host => list of IPs,
     *     overriding/extending the fixed convention below for this one
     *     service instance (used to simulate a DNS rebind between
     *     registration and a later enable()).
     */
    private function service(?callable $uuidGenerator = null, array $dnsMap = []): SellerWebhookEndpointService
    {
        $roles = new FixedSellerRoleAuthority();
        $endpoints = new SellerWebhookEndpointRepository();

        // Fixed hostname -> IP convention used throughout this file: any
        // OTHER hostname defaults to the safe public address.
        $map = array_merge([
            'whsafe.example.test' => [self::SAFE_IP],
            'whprivate.example.test' => [self::PRIVATE_IP],
            'whmetadata.example.test' => [self::METADATA_IP],
        ], $dnsMap);

        $resolver = new SafeOutboundTargetResolver(static function (string $host) use ($map): array {
            return $map[$host] ?? [self::SAFE_IP];
        });

        return new SellerWebhookEndpointService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            $endpoints,
            new SellerWebhookDeliveryRepository(),
            $roles,
            new SellerWebhookSecretService($endpoints, $this->encryptionService()),
            $resolver,
            $uuidGenerator
        );
    }
}
