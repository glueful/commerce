<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyAuthorizationContext;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyAuthorizer;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyException;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyScopeValidator;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyService;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\ConflictException;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Marketplace MV5c-1 Task 5 (design spec §2.9): seller-API-key ROTATION and
 * whole-lineage REVOCATION -- serialized on the lineage revision, atomic,
 * with live manager authority re-derived under the SAME
 * `seller revision -> fresh actor membership/capability -> lineage revision`
 * lock order {@see SellerApiKeyService::create()} (Task 3) already
 * establishes.
 *
 * Like {@see SellerApiKeyCreateTest}, this suite creates the FRAMEWORK's own
 * `api_keys` table inline ({@see self::setUp()}) -- `rotate()`/`revoke()`
 * both call into the framework's `Glueful\Auth\ApiKey\ApiKeyService`, which
 * persists/mutates `ApiKey` ORM rows on that table.
 */
final class SellerApiKeyRotationTest extends CommerceTestCase
{
    private const TENANT = 'tenantAPIKEY5';

    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->connection->getSchemaBuilder();
        if (!$schema->hasTable('api_keys')) {
            $schema->createTable('api_keys', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('user_uuid', 12);
                $table->string('name', 255);
                $table->string('key_prefix', 24);
                $table->string('key_hash', 64);
                $table->text('scopes')->nullable();
                $table->text('allowed_ips')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->bigInteger('rotated_from_id')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

                $table->unique('uuid');
                $table->unique('key_hash');
                $table->index('user_uuid');
                $table->index('key_prefix');
            });
        }
    }

    // -----------------------------------------------------------------
    // Global lock order (design spec §2.9): seller revision -> fresh
    // manager authority -> lineage revision.
    // -----------------------------------------------------------------

    /**
     * Deterministic order proof: an actor whose LIVE role no longer holds
     * `apikeys.manage` is refused with `capability_denied` even when the
     * `lineageUuid` supplied doesn't exist at all. If the lineage revision
     * were claimed BEFORE authority were re-derived, this would surface as
     * a 404 instead -- the fact that it surfaces as the AUTHORITY refusal
     * proves authority is checked strictly before the lineage is ever
     * touched.
     */
    public function testRotateChecksManagerAuthorityBeforeTheLineageRevisionClaim(): void
    {
        $this->seedSeller(self::TENANT, 'sellerROT0001', 'active');
        $this->seedMembership(self::TENANT, 'sellerROT0001', 'staffROT0001', 'seller_staff');

        try {
            $this->service()->rotate(
                $this->context,
                self::TENANT,
                'sellerROT0001',
                'doesNotExist1',
                'staffROT0001'
            );
            self::fail('expected SellerApiKeyException: seller_staff does not hold apikeys.manage');
        } catch (SellerApiKeyException $e) {
            self::assertSame('capability_denied', $e->errorCode);
        }
    }

    public function testRevokeChecksManagerAuthorityBeforeTheLineageRevisionClaim(): void
    {
        $this->seedSeller(self::TENANT, 'sellerROT0002', 'active');
        $this->seedMembership(self::TENANT, 'sellerROT0002', 'staffROT0002', 'seller_staff');

        try {
            $this->service()->revoke(
                $this->context,
                self::TENANT,
                'sellerROT0002',
                'doesNotExist2',
                'staffROT0002'
            );
            self::fail('expected SellerApiKeyException: seller_staff does not hold apikeys.manage');
        } catch (SellerApiKeyException $e) {
            self::assertSame('capability_denied', $e->errorCode);
        }
    }

    public function testRotateRefusesADemotedActorAndRollsBackAtomically(): void
    {
        $seeded = $this->seedLineage('sellerROT0003', 'ownerROT0003');

        $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', 'sellerROT0003')
            ->where('user_uuid', '=', 'ownerROT0003')
            ->update(['role' => 'seller_staff']);

        try {
            $this->service()->rotate(
                $this->context,
                self::TENANT,
                'sellerROT0003',
                $seeded['lineage_uuid'],
                'ownerROT0003'
            );
            self::fail('expected SellerApiKeyException: seller_staff does not hold apikeys.manage');
        } catch (SellerApiKeyException $e) {
            self::assertSame('capability_denied', $e->errorCode);
        }

        $this->assertRotateRolledBackNothingChanged($seeded);
    }

    public function testRotateRefusesARemovedMembershipAndRollsBackAtomically(): void
    {
        $seeded = $this->seedLineage('sellerROT0004', 'ownerROT0004');

        $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', 'sellerROT0004')
            ->where('user_uuid', '=', 'ownerROT0004')
            ->update(['status' => 'revoked']);

        try {
            $this->service()->rotate(
                $this->context,
                self::TENANT,
                'sellerROT0004',
                $seeded['lineage_uuid'],
                'ownerROT0004'
            );
            self::fail('expected SellerApiKeyException: membership was revoked');
        } catch (SellerApiKeyException $e) {
            self::assertSame('membership_inactive', $e->errorCode);
        }

        $this->assertRotateRolledBackNothingChanged($seeded);
    }

    public function testRotateRefusesASuspendedSellerAndRollsBackAtomically(): void
    {
        $seeded = $this->seedLineage('sellerROT0005', 'ownerROT0005');

        $this->connection->table('commerce_sellers')
            ->where('uuid', '=', 'sellerROT0005')
            ->update(['status' => 'suspended']);

        try {
            $this->service()->rotate(
                $this->context,
                self::TENANT,
                'sellerROT0005',
                $seeded['lineage_uuid'],
                'ownerROT0005'
            );
            self::fail('expected SellerApiKeyException: seller is suspended');
        } catch (SellerApiKeyException $e) {
            self::assertSame('seller_inactive', $e->errorCode);
        }

        $this->assertRotateRolledBackNothingChanged($seeded);
    }

    public function testRevokeRefusesADemotedActor(): void
    {
        $seeded = $this->seedLineage('sellerROT0006', 'ownerROT0006');

        $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', 'sellerROT0006')
            ->where('user_uuid', '=', 'ownerROT0006')
            ->update(['role' => 'seller_analyst']);

        try {
            $this->service()->revoke(
                $this->context,
                self::TENANT,
                'sellerROT0006',
                $seeded['lineage_uuid'],
                'ownerROT0006'
            );
            self::fail('expected SellerApiKeyException: seller_analyst does not hold apikeys.manage');
        } catch (SellerApiKeyException $e) {
            self::assertSame('capability_denied', $e->errorCode);
        }

        $lineage = $this->connection->table('commerce_seller_api_keys')
            ->where('uuid', '=', $seeded['lineage_uuid'])->first();
        self::assertSame('active', $lineage['status'], 'a refused revoke must leave the lineage untouched');
        self::assertSame(0, $this->countEventsForAction($seeded['lineage_uuid'], 'revoked'));
    }

    public function testRevokeRefusesASuspendedSeller(): void
    {
        $seeded = $this->seedLineage('sellerROT0007', 'ownerROT0007');

        $this->connection->table('commerce_sellers')
            ->where('uuid', '=', 'sellerROT0007')
            ->update(['status' => 'suspended']);

        try {
            $this->service()->revoke(
                $this->context,
                self::TENANT,
                'sellerROT0007',
                $seeded['lineage_uuid'],
                'ownerROT0007'
            );
            self::fail('expected SellerApiKeyException: seller is suspended');
        } catch (SellerApiKeyException $e) {
            self::assertSame('seller_inactive', $e->errorCode);
        }

        $lineage = $this->connection->table('commerce_seller_api_keys')
            ->where('uuid', '=', $seeded['lineage_uuid'])->first();
        self::assertSame('active', $lineage['status']);
    }

    // -----------------------------------------------------------------
    // Lineage resolution: unknown/cross-tenant/cross-seller = 404; the
    // 0-row claim classification never collapses outcomes.
    // -----------------------------------------------------------------

    public function testRotateOnAnUnknownLineageThrowsNotFound(): void
    {
        $this->seedSeller(self::TENANT, 'sellerROT0008', 'active');
        $this->seedMembership(self::TENANT, 'sellerROT0008', 'ownerROT0008', 'seller_owner');

        $this->expectException(NotFoundException::class);
        $this->service()->rotate(
            $this->context,
            self::TENANT,
            'sellerROT0008',
            'noSuchLineage',
            'ownerROT0008'
        );
    }

    public function testRotateOnAnotherSellersLineageThrowsNotFound(): void
    {
        $seeded = $this->seedLineage('sellerROT0009', 'ownerROT0009');
        $this->seedSeller(self::TENANT, 'sellerROT0010', 'active');
        $this->seedMembership(self::TENANT, 'sellerROT0010', 'ownerROT0010', 'seller_owner');

        $this->expectException(NotFoundException::class);
        // A valid, active lineage -- but for a DIFFERENT seller than the
        // route/actor's own.
        $this->service()->rotate(
            $this->context,
            self::TENANT,
            'sellerROT0010',
            $seeded['lineage_uuid'],
            'ownerROT0010'
        );
    }

    public function testRevokeOnAnUnknownLineageThrowsNotFound(): void
    {
        $this->seedSeller(self::TENANT, 'sellerROT0011', 'active');
        $this->seedMembership(self::TENANT, 'sellerROT0011', 'ownerROT0011', 'seller_owner');

        $this->expectException(NotFoundException::class);
        $this->service()->revoke(
            $this->context,
            self::TENANT,
            'sellerROT0011',
            'noSuchLineage2',
            'ownerROT0011'
        );
    }

    public function testRevokeOnAnotherSellersLineageThrowsNotFound(): void
    {
        $seeded = $this->seedLineage('sellerROT0012', 'ownerROT0012');
        $this->seedSeller(self::TENANT, 'sellerROT0013', 'active');
        $this->seedMembership(self::TENANT, 'sellerROT0013', 'ownerROT0013', 'seller_owner');

        $this->expectException(NotFoundException::class);
        $this->service()->revoke(
            $this->context,
            self::TENANT,
            'sellerROT0013',
            $seeded['lineage_uuid'],
            'ownerROT0013'
        );
    }

    // -----------------------------------------------------------------
    // Rotate on a revoked/expired lineage or credential: 409, never a
    // collapsed/ambiguous outcome.
    // -----------------------------------------------------------------

    public function testRotateOnAnAlreadyRevokedLineageThrows409(): void
    {
        $seeded = $this->seedLineage('sellerROT0014', 'ownerROT0014');
        $this->service()->revoke(
            $this->context,
            self::TENANT,
            'sellerROT0014',
            $seeded['lineage_uuid'],
            'ownerROT0014'
        );

        try {
            $this->service()->rotate(
                $this->context,
                self::TENANT,
                'sellerROT0014',
                $seeded['lineage_uuid'],
                'ownerROT0014'
            );
            self::fail('expected ConflictException: lineage is already revoked');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
    }

    public function testRotateOnAnExpiredCurrentCredentialThrows409(): void
    {
        $seeded = $this->seedLineage('sellerROT0015', 'ownerROT0015');

        // Simulate the current framework key having expired naturally
        // (rotate() re-reads the LIVE framework row, never a stale copy).
        $this->connection->table('api_keys')
            ->where('uuid', '=', $seeded['framework_key_uuid'])
            ->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);

        try {
            $this->service()->rotate(
                $this->context,
                self::TENANT,
                'sellerROT0015',
                $seeded['lineage_uuid'],
                'ownerROT0015'
            );
            self::fail('expected ConflictException: current credential is expired');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->getStatusCode());
        }

        $credential = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('lineage_uuid', '=', $seeded['lineage_uuid'])->first();
        self::assertSame('current', $credential['relationship'], 'a refused rotate must not demote the credential');
    }

    public function testRotateOnARevokedCurrentCredentialThrows409(): void
    {
        $seeded = $this->seedLineage('sellerROT0016', 'ownerROT0016');

        // Simulate the current framework key having been revoked
        // out-of-band (e.g. directly via the framework's own surface).
        $this->connection->table('api_keys')
            ->where('uuid', '=', $seeded['framework_key_uuid'])
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);

        try {
            $this->service()->rotate(
                $this->context,
                self::TENANT,
                'sellerROT0016',
                $seeded['lineage_uuid'],
                'ownerROT0016'
            );
            self::fail('expected ConflictException: current credential is revoked');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
    }

    // -----------------------------------------------------------------
    // Successor inheritance (design spec §2.9): tenant/seller/subject/
    // scopes/expiry unchanged, even under a DIFFERENT manager.
    // -----------------------------------------------------------------

    public function testRotateSuccessorInheritsSubjectScopesAndExpiryUnchanged(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 7200);
        $seeded = $this->seedLineage(
            'sellerROT0017',
            'ownerROT0017',
            ['commerce.seller.orders.read', 'commerce.seller.inventory.read'],
            $future
        );

        $result = $this->service()->rotate(
            $this->context,
            self::TENANT,
            'sellerROT0017',
            $seeded['lineage_uuid'],
            'ownerROT0017'
        );

        self::assertArrayHasKey('plain_key', $result);
        self::assertNotSame('', $result['plain_key']);

        $lineage = $result['lineage'];
        self::assertSame('sellerROT0017', $lineage['seller_uuid']);
        self::assertSame('ownerROT0017', $lineage['subject_user_uuid']);
        self::assertSame(
            ['commerce.seller.inventory.read', 'commerce.seller.orders.read'],
            $lineage['declared_scopes']
        );
        self::assertSame($future, $lineage['expires_at'], 'rotation must never change the lineage expiry');

        $newCredential = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', $lineage['current_credential_uuid'])->first();
        self::assertNotNull($newCredential);
        self::assertSame(2, (int) $newCredential['generation']);
        self::assertSame('current', $newCredential['relationship']);

        $newFrameworkKey = $this->connection->table('api_keys')
            ->where('uuid', '=', $newCredential['framework_key_uuid'])->first();
        self::assertNotNull($newFrameworkKey);
        self::assertSame('ownerROT0017', $newFrameworkKey['user_uuid'], 'the successor must inherit the SAME subject');
        self::assertSame(
            $future,
            $newFrameworkKey['expires_at'],
            'the successor must inherit the SAME expiry, unshortened'
        );
    }

    public function testRotateByADifferentManagerPreservesTheOriginalSubject(): void
    {
        $seeded = $this->seedLineage('sellerROT0018', 'ownerROT0018');
        $this->seedMembership(self::TENANT, 'sellerROT0018', 'adminROT0018', 'seller_admin');

        // A DIFFERENT active manager (admin, not the original owner/subject)
        // performs the rotation.
        $result = $this->service()->rotate(
            $this->context,
            self::TENANT,
            'sellerROT0018',
            $seeded['lineage_uuid'],
            'adminROT0018'
        );

        self::assertSame(
            'ownerROT0018',
            $result['lineage']['subject_user_uuid'],
            'the rotating admin must NEVER become the bound subject'
        );

        $newCredential = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', $result['lineage']['current_credential_uuid'])->first();
        $newFrameworkKey = $this->connection->table('api_keys')
            ->where('uuid', '=', $newCredential['framework_key_uuid'])->first();
        self::assertSame(
            'ownerROT0018',
            $newFrameworkKey['user_uuid'],
            'the framework key itself must carry the ORIGINAL subject, never the rotating admin'
        );

        // The audit event still records the ACTOR who performed the
        // rotation, distinct from the subject it never changed.
        $event = $this->connection->table('commerce_seller_api_key_events')
            ->where('lineage_uuid', '=', $seeded['lineage_uuid'])
            ->where('action', '=', 'rotated')
            ->first();
        self::assertSame('adminROT0018', $event['actor_uuid']);
        self::assertSame('ownerROT0018', $event['subject_user_uuid']);
    }

    // -----------------------------------------------------------------
    // Predecessor grace expiry: UTC, and equal to the applied (min) expiry.
    // -----------------------------------------------------------------

    public function testRotatePredecessorGraceExpiryIsTheEarlierExistingExpiryWhenItIsSooner(): void
    {
        // An expiry ONE HOUR from now is sooner than the default 24h grace
        // deadline -- rotation must SHORTEN to this earlier bound, not
        // extend past it.
        $earlierExpiry = gmdate('Y-m-d H:i:s', time() + 3600);
        $seeded = $this->seedLineage('sellerROT0019', 'ownerROT0019', ['commerce.seller.orders.read'], $earlierExpiry);

        $this->service()->rotate(
            $this->context,
            self::TENANT,
            'sellerROT0019',
            $seeded['lineage_uuid'],
            'ownerROT0019'
        );

        $predecessor = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', $seeded['credential_uuid'])->first();
        self::assertSame('predecessor', $predecessor['relationship']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $predecessor['grace_expires_at']);
        self::assertSame(
            $earlierExpiry,
            $predecessor['grace_expires_at'],
            'grace_expires_at must equal the earlier existing expiry, never extended to the grace deadline'
        );
    }

    public function testRotatePredecessorGraceExpiryDefaultsToTheGraceDeadlineWhenNoExpiryExisted(): void
    {
        $seeded = $this->seedLineage('sellerROT0020', 'ownerROT0020');

        $before = time();
        $this->service()->rotate(
            $this->context,
            self::TENANT,
            'sellerROT0020',
            $seeded['lineage_uuid'],
            'ownerROT0020'
        );
        $after = time();

        $predecessor = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', $seeded['credential_uuid'])->first();
        self::assertSame('predecessor', $predecessor['relationship']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $predecessor['grace_expires_at']);

        $graceTs = strtotime((string) $predecessor['grace_expires_at']);
        self::assertNotFalse($graceTs);
        // Default grace is 24h -- the applied deadline must fall within the
        // [before, after] wall-clock window plus 24h.
        self::assertGreaterThanOrEqual($before + (24 * 3600) - 5, $graceTs);
        self::assertLessThanOrEqual($after + (24 * 3600) + 5, $graceTs);
    }

    // -----------------------------------------------------------------
    // Direct resolution: current + predecessor BOTH authenticate during
    // grace, via the REAL T4 authorizer.
    // -----------------------------------------------------------------

    public function testCurrentAndPredecessorCredentialsBothResolveToOneLineageDuringGraceViaTheAuthorizer(): void
    {
        $seeded = $this->seedLineage('sellerROT0021', 'ownerROT0021', ['commerce.seller.orders.read']);

        $result = $this->service()->rotate(
            $this->context,
            self::TENANT,
            'sellerROT0021',
            $seeded['lineage_uuid'],
            'ownerROT0021'
        );

        $newCredential = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', $result['lineage']['current_credential_uuid'])->first();

        $authorizer = new SellerApiKeyAuthorizer(new SellerApiKeyRepository());

        $predecessorRequest = $this->apiKeyRequest(
            'ownerROT0021',
            $seeded['framework_key_uuid'],
            ['commerce.seller.orders.read']
        );
        $predecessorResult = $authorizer->authorize(
            $this->context,
            $predecessorRequest,
            self::TENANT,
            'sellerROT0021',
            'commerce.seller.orders.read'
        );
        self::assertInstanceOf(
            SellerApiKeyAuthorizationContext::class,
            $predecessorResult,
            'the predecessor must still authenticate inside its grace window'
        );

        $currentRequest = $this->apiKeyRequest(
            'ownerROT0021',
            (string) $newCredential['framework_key_uuid'],
            ['commerce.seller.orders.read']
        );
        $currentResult = $authorizer->authorize(
            $this->context,
            $currentRequest,
            self::TENANT,
            'sellerROT0021',
            'commerce.seller.orders.read'
        );
        self::assertInstanceOf(SellerApiKeyAuthorizationContext::class, $currentResult);

        self::assertSame($predecessorResult->lineageUuid(), $currentResult->lineageUuid());
    }

    // -----------------------------------------------------------------
    // Whole-lineage revocation (design spec §2.9): every generation stops
    // authenticating.
    // -----------------------------------------------------------------

    public function testRevokeKillsEveryGenerationCurrentAndPredecessorBothStopAuthenticating(): void
    {
        $seeded = $this->seedLineage('sellerROT0022', 'ownerROT0022', ['commerce.seller.orders.read']);
        $rotated = $this->service()->rotate(
            $this->context,
            self::TENANT,
            'sellerROT0022',
            $seeded['lineage_uuid'],
            'ownerROT0022'
        );
        $newCredential = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', $rotated['lineage']['current_credential_uuid'])->first();

        $this->service()->revoke(
            $this->context,
            self::TENANT,
            'sellerROT0022',
            $seeded['lineage_uuid'],
            'ownerROT0022'
        );

        $credentials = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('lineage_uuid', '=', $seeded['lineage_uuid'])->get();
        self::assertCount(2, $credentials);
        foreach ($credentials as $credential) {
            self::assertSame('revoked', $credential['relationship']);
        }

        $lineage = $this->connection->table('commerce_seller_api_keys')
            ->where('uuid', '=', $seeded['lineage_uuid'])->first();
        self::assertSame('revoked', $lineage['status']);

        $authorizer = new SellerApiKeyAuthorizer(new SellerApiKeyRepository());

        $predecessorResult = $authorizer->authorize(
            $this->context,
            $this->apiKeyRequest('ownerROT0022', $seeded['framework_key_uuid'], ['commerce.seller.orders.read']),
            self::TENANT,
            'sellerROT0022',
            'commerce.seller.orders.read'
        );
        self::assertInstanceOf(Response::class, $predecessorResult);

        $currentResult = $authorizer->authorize(
            $this->context,
            $this->apiKeyRequest(
                'ownerROT0022',
                (string) $newCredential['framework_key_uuid'],
                ['commerce.seller.orders.read']
            ),
            self::TENANT,
            'sellerROT0022',
            'commerce.seller.orders.read'
        );
        self::assertInstanceOf(Response::class, $currentResult);
    }

    public function testRevokeIsAStableNoOpOnAnAlreadyRevokedLineageWithNoSecondEvent(): void
    {
        $seeded = $this->seedLineage('sellerROT0023', 'ownerROT0023');

        $first = $this->service()->revoke(
            $this->context,
            self::TENANT,
            'sellerROT0023',
            $seeded['lineage_uuid'],
            'ownerROT0023'
        );
        self::assertSame('revoked', $first['lineage']['status']);

        $second = $this->service()->revoke(
            $this->context,
            self::TENANT,
            'sellerROT0023',
            $seeded['lineage_uuid'],
            'ownerROT0023'
        );
        self::assertSame('revoked', $second['lineage']['status'], 're-revoke must remain a stable success');

        self::assertSame(
            1,
            $this->countEventsForAction($seeded['lineage_uuid'], 'revoked'),
            're-revoke must write NO second audit event'
        );
    }

    // -----------------------------------------------------------------
    // Rotate vs revoke serialize on the lineage revision: both orderings.
    // -----------------------------------------------------------------

    public function testRotateFirstThenRevokeKillsTheSuccessorToo(): void
    {
        $seeded = $this->seedLineage('sellerROT0024', 'ownerROT0024');

        $rotated = $this->service()->rotate(
            $this->context,
            self::TENANT,
            'sellerROT0024',
            $seeded['lineage_uuid'],
            'ownerROT0024'
        );

        $this->service()->revoke(
            $this->context,
            self::TENANT,
            'sellerROT0024',
            $seeded['lineage_uuid'],
            'ownerROT0024'
        );

        $successorCredential = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', $rotated['lineage']['current_credential_uuid'])->first();
        self::assertSame(
            'revoked',
            $successorCredential['relationship'],
            'a committed revoke must kill a successor a preceding rotate just minted'
        );

        $lineage = $this->connection->table('commerce_seller_api_keys')
            ->where('uuid', '=', $seeded['lineage_uuid'])->first();
        self::assertSame('revoked', $lineage['status']);
    }

    public function testRevokeFirstThenRotateReturns409(): void
    {
        $seeded = $this->seedLineage('sellerROT0025', 'ownerROT0025');

        $this->service()->revoke(
            $this->context,
            self::TENANT,
            'sellerROT0025',
            $seeded['lineage_uuid'],
            'ownerROT0025'
        );

        try {
            $this->service()->rotate(
                $this->context,
                self::TENANT,
                'sellerROT0025',
                $seeded['lineage_uuid'],
                'ownerROT0025'
            );
            self::fail('expected ConflictException: the lineage was already revoked by the winning revoke');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->getStatusCode());
        }

        self::assertSame(
            0,
            $this->connection->table('commerce_seller_api_key_credentials')
                ->where('lineage_uuid', '=', $seeded['lineage_uuid'])
                ->where('relationship', '=', 'current')
                ->count(),
            'no active successor may survive a committed revoke'
        );
    }

    // -----------------------------------------------------------------
    // Atomicity: a forced audit-uuid collision rolls everything back.
    // -----------------------------------------------------------------

    public function testForcedFailureMidRotateRollsBackNoUnboundSuccessorSurvives(): void
    {
        $seeded = $this->seedLineage('sellerROT0026', 'ownerROT0026');

        $this->connection->table('commerce_seller_api_key_events')->insert([
            'uuid' => 'collideRot001',
            'tenant_uuid' => self::TENANT,
            'lineage_uuid' => 'someOtherLin',
            'seller_uuid' => 'someOtherSlr',
            'subject_user_uuid' => 'someoneElse1',
            'action' => 'created',
        ]);

        $service = $this->service(static fn (): string => 'collideRot001');

        try {
            $service->rotate(
                $this->context,
                self::TENANT,
                'sellerROT0026',
                $seeded['lineage_uuid'],
                'ownerROT0026'
            );
            self::fail('expected the forced audit-insert collision to propagate');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(
            0,
            $this->connection->table('api_keys')->where('uuid', '=', 'collideRot001')->count(),
            'no unbound successor framework key may survive a rolled-back rotation'
        );

        $credential = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', $seeded['credential_uuid'])->first();
        self::assertSame('current', $credential['relationship'], 'the demotion must roll back too');
        self::assertNull($credential['grace_expires_at']);

        $lineage = $this->connection->table('commerce_seller_api_keys')
            ->where('uuid', '=', $seeded['lineage_uuid'])->first();
        self::assertSame(
            $seeded['credential_uuid'],
            $lineage['current_credential_uuid'],
            'the pointer advance must roll back too'
        );

        $frameworkKey = $this->connection->table('api_keys')
            ->where('uuid', '=', $seeded['framework_key_uuid'])->first();
        self::assertNull($frameworkKey['expires_at'], 'the framework rotate()\'s own expiry shortening must roll back too');
    }

    public function testForcedFailureMidRevokeRollsBackNothingIsMarkedRevoked(): void
    {
        $seeded = $this->seedLineage('sellerROT0027', 'ownerROT0027');

        $this->connection->table('commerce_seller_api_key_events')->insert([
            'uuid' => 'collideRvk001',
            'tenant_uuid' => self::TENANT,
            'lineage_uuid' => 'someOtherLin',
            'seller_uuid' => 'someOtherSlr',
            'subject_user_uuid' => 'someoneElse1',
            'action' => 'created',
        ]);

        $service = $this->service(static fn (): string => 'collideRvk001');

        try {
            $service->revoke(
                $this->context,
                self::TENANT,
                'sellerROT0027',
                $seeded['lineage_uuid'],
                'ownerROT0027'
            );
            self::fail('expected the forced audit-insert collision to propagate');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        $credential = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', $seeded['credential_uuid'])->first();
        self::assertSame('current', $credential['relationship'], 'a rolled-back revoke must not mark the credential revoked');

        $lineage = $this->connection->table('commerce_seller_api_keys')
            ->where('uuid', '=', $seeded['lineage_uuid'])->first();
        self::assertSame('active', $lineage['status'], 'a rolled-back revoke must not mark the lineage revoked');

        $frameworkKey = $this->connection->table('api_keys')
            ->where('uuid', '=', $seeded['framework_key_uuid'])->first();
        self::assertNull($frameworkKey['revoked_at'], 'the framework revoke() itself must roll back too');
    }

    // -----------------------------------------------------------------
    // Repository hardening (T4 carry-forward): recordAuthDenied() must
    // NEVER throw, even when the classification re-read itself fails.
    // -----------------------------------------------------------------

    public function testRecordAuthDeniedNeverThrowsEvenWhenTheReReadItselfFails(): void
    {
        // Drop the table entirely so BOTH the initial insert attempt AND
        // the classification re-read inside the catch block fail --
        // proving the re-read is itself guarded, not just the insert.
        $this->connection->getSchemaBuilder()->dropTableIfExists('commerce_seller_api_key_events');

        $repo = new SellerApiKeyRepository();
        $repo->recordAuthDenied($this->context, self::TENANT, [
            'uuid' => 'someDenialUu1',
            'lineage_uuid' => 'someLineage01',
            'seller_uuid' => 'someSeller001',
            'subject_user_uuid' => 'someSubject01',
            'reason_code' => 'principal_mismatch',
            'bucket_start' => gmdate('Y-m-d H:i:00'),
        ]);

        $this->addToAssertionCount(1);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function service(?callable $uuidGenerator = null): SellerApiKeyService
    {
        $roles = new FixedSellerRoleAuthority();

        return new SellerApiKeyService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            new SellerApiKeyRepository(),
            $roles,
            new SellerApiKeyScopeValidator($roles),
            $uuidGenerator
        );
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

    /**
     * Seeds a seller (active) + an owner membership for `$subjectUuid`, then
     * creates a lineage through the REAL {@see SellerApiKeyService::create()}
     * flow -- so `rotate()`/`revoke()` tests exercise a genuine,
     * fully-wired lineage/credential/framework-key triple rather than a
     * hand-assembled row.
     *
     * @param list<string> $scopes
     * @return array{
     *     lineage_uuid: string,
     *     credential_uuid: string,
     *     framework_key_uuid: string
     * }
     */
    private function seedLineage(
        string $sellerUuid,
        string $subjectUuid,
        array $scopes = ['commerce.seller.orders.read'],
        ?string $expiresAt = null
    ): array {
        $this->seedSeller(self::TENANT, $sellerUuid, 'active');
        $this->seedMembership(self::TENANT, $sellerUuid, $subjectUuid, 'seller_owner');

        $created = $this->service()->create(
            $this->context,
            self::TENANT,
            $sellerUuid,
            'Rotation test key',
            $scopes,
            $expiresAt,
            $subjectUuid
        );

        $lineage = $created['lineage'];
        $credential = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', $lineage['current_credential_uuid'])->first();

        return [
            'lineage_uuid' => (string) $lineage['uuid'],
            'credential_uuid' => (string) $credential['uuid'],
            'framework_key_uuid' => (string) $credential['framework_key_uuid'],
        ];
    }

    /** @param array{lineage_uuid: string, credential_uuid: string, framework_key_uuid: string} $seeded */
    private function assertRotateRolledBackNothingChanged(array $seeded): void
    {
        $credential = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', $seeded['credential_uuid'])->first();
        self::assertSame('current', $credential['relationship'], 'a refused rotate must not demote the credential');
        self::assertNull($credential['grace_expires_at']);

        $lineage = $this->connection->table('commerce_seller_api_keys')
            ->where('uuid', '=', $seeded['lineage_uuid'])->first();
        self::assertSame(
            $seeded['credential_uuid'],
            $lineage['current_credential_uuid'],
            'a refused rotate must not advance the pointer'
        );

        self::assertSame(0, $this->countEventsForAction($seeded['lineage_uuid'], 'rotated'));
        self::assertSame(
            1,
            $this->connection->table('api_keys')->count(),
            'a refused rotate must never leave a successor framework key behind'
        );
    }

    private function countEventsForAction(string $lineageUuid, string $action): int
    {
        return $this->connection->table('commerce_seller_api_key_events')
            ->where('lineage_uuid', '=', $lineageUuid)
            ->where('action', '=', $action)
            ->count();
    }

    /** @param list<string> $scopes */
    private function apiKeyRequest(string $subjectUuid, string $frameworkKeyUuid, array $scopes): Request
    {
        $request = Request::create('/irrelevant', 'GET');
        $request->attributes->set('auth_method', 'api_key');
        $request->attributes->set('user_id', $subjectUuid);
        $request->attributes->set('api_key_scopes', $scopes);
        $request->attributes->set('api_key_uuid', $frameworkKeyUuid);
        $request->attributes->set('user', ['uuid' => $subjectUuid]);

        return $request;
    }
}
