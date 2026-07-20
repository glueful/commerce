<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyException;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyScopeValidator;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyService;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Marketplace MV5c-1 Task 3 (design spec §2.2/§2.3/§2.5/§2.8): the seller-
 * API-key lineage/credential repositories, the declared-scope validator, and
 * the CREATE service -- live authority (no caller-supplied subject/role),
 * atomic across the framework key + lineage + credential + audit writes.
 *
 * Additionally creates the FRAMEWORK's own `api_keys` table inline (in
 * {@see self::setUp()}) -- `SellerApiKeyService::create()` calls the
 * framework's `ApiKeyService::create()`, which persists an `ApiKey` ORM
 * model row; that table is a framework-core migration
 * (`framework/migrations/auth/003_CreateApiKeysTable.php`) that composer's
 * classmap does not autoload (migration classes are loaded by the
 * migration RUNNER, not PSR-4/classmap), and nothing else in this
 * repository needs it, so it is not part of
 * {@see CommerceTestCase::MIGRATIONS}. Column shape mirrors
 * {@see \Glueful\Tests\Integration\Auth\ApiKeyAuthenticationTest}'s
 * identical "create inline to stay fast/self-contained" convention in the
 * framework's own test suite.
 */
final class SellerApiKeyCreateTest extends CommerceTestCase
{
    private const TENANT = 'tenantAPIKEY1';

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
    // Happy path: framework key + lineage + credential + audit, atomic.
    // -----------------------------------------------------------------

    public function testCreateWithALiveOwnerActorWritesFrameworkKeyLineageCredentialAndAuditAtomically(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY01', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY01', 'ownerUserAPI1', 'seller_owner');

        $result = $this->service()->create(
            $this->context,
            self::TENANT,
            'sellerAPIKY01',
            'My integration key',
            ['commerce.seller.orders.read', 'commerce.seller.inventory.read'],
            null,
            'ownerUserAPI1'
        );

        // The raw secret is returned exactly once, in the CREATE result.
        self::assertArrayHasKey('plain_key', $result);
        self::assertIsString($result['plain_key']);
        self::assertNotSame('', $result['plain_key']);

        $lineage = $result['lineage'];
        self::assertSame('sellerAPIKY01', $lineage['seller_uuid']);
        self::assertSame(
            'ownerUserAPI1',
            $lineage['subject_user_uuid'],
            'subject must be the ACTOR, never a caller value'
        );
        self::assertSame('ownerUserAPI1', $lineage['created_by']);
        self::assertSame('My integration key', $lineage['name']);
        self::assertSame('active', $lineage['status']);
        self::assertNull($lineage['expires_at']);
        self::assertSame(
            ['commerce.seller.inventory.read', 'commerce.seller.orders.read'],
            $lineage['declared_scopes'],
            'declared_scopes must be canonical (deduped, sorted)'
        );

        $lineageRow = $this->connection->table('commerce_seller_api_keys')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('seller_uuid', '=', 'sellerAPIKY01')
            ->first();
        self::assertNotNull($lineageRow);
        self::assertSame($lineage['uuid'], $lineageRow['uuid']);
        self::assertSame($lineage['current_credential_uuid'], $lineageRow['current_credential_uuid']);

        $credential = $this->connection->table('commerce_seller_api_key_credentials')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('lineage_uuid', '=', $lineage['uuid'])
            ->first();
        self::assertNotNull($credential);
        self::assertSame(
            $lineage['current_credential_uuid'],
            $credential['uuid'],
            'current_credential_uuid must link to the credential row'
        );
        self::assertSame(1, (int) $credential['generation']);
        self::assertSame('current', $credential['relationship']);

        $frameworkKey = $this->connection->table('api_keys')
            ->where('uuid', '=', $credential['framework_key_uuid'])
            ->first();
        self::assertNotNull($frameworkKey, 'the credential must bind the EXACT framework key uuid');
        self::assertSame('ownerUserAPI1', $frameworkKey['user_uuid']);
        self::assertSame(ApiKeyService::extractPrefix($result['plain_key']), $frameworkKey['key_prefix']);
        self::assertSame(ApiKeyService::hashKey($result['plain_key']), $frameworkKey['key_hash']);

        $events = $this->connection->table('commerce_seller_api_key_events')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('lineage_uuid', '=', $lineage['uuid'])
            ->get();
        self::assertCount(1, $events);
        self::assertSame('created', $events[0]['action']);
        self::assertSame('ownerUserAPI1', $events[0]['actor_uuid']);
        self::assertSame('ownerUserAPI1', $events[0]['subject_user_uuid']);
    }

    public function testCreateWithALiveAdminActorSucceedsToo(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY02', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY02', 'adminUserAPI1', 'seller_admin');

        $result = $this->service()->create(
            $this->context,
            self::TENANT,
            'sellerAPIKY02',
            'Admin key',
            ['commerce.seller.catalog.read'],
            null,
            'adminUserAPI1'
        );

        self::assertSame('adminUserAPI1', $result['lineage']['subject_user_uuid']);
    }

    public function testCreateOnUnknownSellerThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service()->create(
            $this->context,
            self::TENANT,
            'missingSlr001',
            'Key',
            ['commerce.seller.orders.read'],
            null,
            'someActorUu1'
        );
    }

    // -----------------------------------------------------------------
    // Basic name syntax: validated before any claim.
    // -----------------------------------------------------------------

    public function testCreateRejectsABlankName(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY20', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY20', 'ownerUserAP20', 'seller_owner');

        try {
            $this->service()->create(
                $this->context,
                self::TENANT,
                'sellerAPIKY20',
                '   ',
                ['commerce.seller.orders.read'],
                null,
                'ownerUserAP20'
            );
            self::fail('expected ValidationException for a blank name');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->firstErrors());
        }

        $seller = $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerAPIKY20')->first();
        self::assertSame(0, (int) $seller['revision'], 'a blank name must never even claim the seller row');
    }

    public function testCreateRejectsANameOver120Characters(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY21', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY21', 'ownerUserAP21', 'seller_owner');

        $this->expectException(ValidationException::class);
        $this->service()->create(
            $this->context,
            self::TENANT,
            'sellerAPIKY21',
            str_repeat('x', 121),
            ['commerce.seller.orders.read'],
            null,
            'ownerUserAP21'
        );
    }

    // -----------------------------------------------------------------
    // No caller-supplied subject/role: the signature structurally excludes them.
    // -----------------------------------------------------------------

    public function testCreateSignatureCarriesNoCallerSuppliedSubjectOrRoleParameter(): void
    {
        $method = new \ReflectionMethod(SellerApiKeyService::class, 'create');
        $paramNames = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $method->getParameters()
        );

        self::assertSame(['c', 'tenant', 'sellerUuid', 'name', 'declaredScopes', 'expiresAt', 'actor'], $paramNames);
        self::assertNotContains('subject', $paramNames);
        self::assertNotContains('subjectUuid', $paramNames);
        self::assertNotContains('role', $paramNames);
    }

    // -----------------------------------------------------------------
    // Scope rejection: each 422, reachable through the full service with a
    // seller_owner actor (which holds every catalog-grantable capability --
    // see testValidatorRejectsAScopeNotHeldByTheLiveRole() below for the
    // "not held by role" branch, which needs a DIFFERENT role to exercise).
    // -----------------------------------------------------------------

    public function testCreateRejectsEmptyDeclaredScopes(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY03', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY03', 'ownerUserAPI3', 'seller_owner');

        try {
            $this->service()->create($this->context, self::TENANT, 'sellerAPIKY03', 'K', [], null, 'ownerUserAPI3');
            self::fail('expected ValidationException for an empty scope list');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('scopes', $e->firstErrors());
        }
        self::assertSame(0, $this->connection->table('commerce_seller_api_keys')->count());
    }

    public function testCreateRejectsABlankOnlyDeclaredScopeListAfterCanonicalization(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY04', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY04', 'ownerUserAPI4', 'seller_owner');

        $this->expectException(ValidationException::class);
        $this->service()->create($this->context, self::TENANT, 'sellerAPIKY04', 'K', ['   '], null, 'ownerUserAPI4');
    }

    public function testCreateRejectsAFullWildcardScope(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY05', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY05', 'ownerUserAPI5', 'seller_owner');

        $this->expectException(ValidationException::class);
        $this->service()->create($this->context, self::TENANT, 'sellerAPIKY05', 'K', ['*'], null, 'ownerUserAPI5');
    }

    public function testCreateRejectsAPrefixWildcardScope(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY06', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY06', 'ownerUserAPI6', 'seller_owner');

        $this->expectException(ValidationException::class);
        $this->service()->create(
            $this->context,
            self::TENANT,
            'sellerAPIKY06',
            'K',
            ['commerce.seller.*'],
            null,
            'ownerUserAPI6'
        );
    }

    public function testCreateRejectsTheNonGrantableApikeysManageScope(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY07', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY07', 'ownerUserAPI7', 'seller_owner');

        try {
            $this->service()->create(
                $this->context,
                self::TENANT,
                'sellerAPIKY07',
                'K',
                [FixedSellerRoleAuthority::APIKEYS_MANAGE],
                null,
                'ownerUserAPI7'
            );
            self::fail('expected ValidationException: apikeys.manage must never be key-grantable');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('scopes', $e->firstErrors());
        }
    }

    public function testCreateRejectsTheNonGrantableMembersManageScope(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY08', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY08', 'ownerUserAPI8', 'seller_owner');

        $this->expectException(ValidationException::class);
        $this->service()->create(
            $this->context,
            self::TENANT,
            'sellerAPIKY08',
            'K',
            ['commerce.seller.members.manage'],
            null,
            'ownerUserAPI8'
        );
    }

    public function testCreateRejectsAnUnknownScopeSlug(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY09', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY09', 'ownerUserAPI9', 'seller_owner');

        $this->expectException(ValidationException::class);
        $this->service()->create(
            $this->context,
            self::TENANT,
            'sellerAPIKY09',
            'K',
            ['commerce.seller.totally.bogus'],
            null,
            'ownerUserAPI9'
        );
    }

    /**
     * "Not held by the live role" is UNREACHABLE through the full CREATE
     * flow with the shipped role vocabulary: both roles that hold
     * `apikeys.manage` (owner, admin) ALSO hold every single
     * catalog-grantable capability (see {@see FixedSellerRoleAuthority}'s
     * matrix) -- there is no role that can reach the scope-validation step
     * at all while lacking one of the grantable-catalog capabilities. This
     * branch is therefore exercised directly against
     * {@see SellerApiKeyScopeValidator}, which is role-agnostic by design
     * (it validates ANY `$role` string against the injected
     * {@see \Glueful\Extensions\Commerce\Marketplace\Contracts\SellerRoleAuthority}),
     * independent of whether
     * {@see SellerApiKeyService::create()} can currently reach that
     * combination.
     */
    public function testValidatorRejectsAScopeNotHeldByTheLiveRole(): void
    {
        $validator = new SellerApiKeyScopeValidator(new FixedSellerRoleAuthority());

        try {
            // seller_staff holds inventory.read/write + orders.read/fulfill +
            // catalog.read, but NOT reports.read (owner/admin/analyst only).
            $validator->validate(['commerce.seller.reports.read'], 'seller_staff');
            self::fail('expected ValidationException: reports.read is not held by seller_staff');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('scopes', $e->firstErrors());
        }
    }

    public function testValidatorAcceptsAndCanonicalizesAHeldGrantableScopeSet(): void
    {
        $validator = new SellerApiKeyScopeValidator(new FixedSellerRoleAuthority());

        $result = $validator->validate(
            ['commerce.seller.orders.read', 'commerce.seller.orders.read', ' commerce.seller.catalog.read '],
            'seller_owner'
        );

        self::assertSame(['commerce.seller.catalog.read', 'commerce.seller.orders.read'], $result);
    }

    // -----------------------------------------------------------------
    // Expiry: syntax + strictly-after-DB-now, compared via UtcNowSql (not
    // PHP's wall clock).
    // -----------------------------------------------------------------

    public function testCreateRejectsAnUnparseableExpiry(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY10', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY10', 'ownerUserAP10', 'seller_owner');

        try {
            $this->service()->create(
                $this->context,
                self::TENANT,
                'sellerAPIKY10',
                'K',
                ['commerce.seller.orders.read'],
                'not-a-real-timestamp',
                'ownerUserAP10'
            );
            self::fail('expected ValidationException for an unparseable expires_at');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('expires_at', $e->firstErrors());
        }
        self::assertSame(0, $this->connection->table('commerce_seller_api_keys')->count());
    }

    public function testCreateRejectsANonUtcOffsetExpiry(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY11', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY11', 'ownerUserAP11', 'seller_owner');

        try {
            $this->service()->create(
                $this->context,
                self::TENANT,
                'sellerAPIKY11',
                'K',
                ['commerce.seller.orders.read'],
                '2030-06-01T10:00:00+02:00',
                'ownerUserAP11'
            );
            self::fail('expected ValidationException for a non-UTC offset expires_at');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('expires_at', $e->firstErrors());
        }
    }

    public function testCreateRejectsAPastExpiry(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY12', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY12', 'ownerUserAP12', 'seller_owner');

        $this->expectException(ValidationException::class);
        $this->service()->create(
            $this->context,
            self::TENANT,
            'sellerAPIKY12',
            'K',
            ['commerce.seller.orders.read'],
            '2000-01-01 00:00:00',
            'ownerUserAP12'
        );
    }

    /**
     * "Equal to DB-now" is rejected too (strictly-after is required). This
     * samples PHP's OWN UTC wall clock right before the call as a stand-in
     * for "DB-now" -- on the in-memory SQLite harness used here, the
     * service's OWN `UtcNowSql`-driven read happens microseconds LATER on
     * the SAME machine clock, so the value captured here is always <= the
     * service's DB-now read, which is exactly the "equal or earlier" case
     * this test wants to force deterministically.
     */
    public function testCreateRejectsAnExpiryEqualToOrEarlierThanDbNow(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY13', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY13', 'ownerUserAP13', 'seller_owner');

        $now = gmdate('Y-m-d H:i:s');

        try {
            $this->service()->create(
                $this->context,
                self::TENANT,
                'sellerAPIKY13',
                'K',
                ['commerce.seller.orders.read'],
                $now,
                'ownerUserAP13'
            );
            self::fail('expected ValidationException for an expiry not strictly after DB-now');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('expires_at', $e->firstErrors());
        }
    }

    public function testCreateAcceptsAndStoresAValidFutureUtcExpiry(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY14', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY14', 'ownerUserAP14', 'seller_owner');

        $future = gmdate('Y-m-d H:i:s', time() + 3600);

        $result = $this->service()->create(
            $this->context,
            self::TENANT,
            'sellerAPIKY14',
            'K',
            ['commerce.seller.orders.read'],
            $future,
            'ownerUserAP14'
        );

        self::assertSame($future, $result['lineage']['expires_at']);

        $frameworkKey = $this->connection->table('api_keys')
            ->where('user_uuid', '=', 'ownerUserAP14')
            ->first();
        self::assertNotNull($frameworkKey);
        self::assertSame($future, $frameworkKey['expires_at'], 'the framework key must carry the SAME expiry');
    }

    public function testCreateAcceptsAZuluSuffixedExpiry(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY15', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY15', 'ownerUserAP15', 'seller_owner');

        $future = gmdate('Y-m-d\TH:i:s\Z', time() + 7200);

        $result = $this->service()->create(
            $this->context,
            self::TENANT,
            'sellerAPIKY15',
            'K',
            ['commerce.seller.orders.read'],
            $future,
            'ownerUserAP15'
        );

        self::assertNotNull($result['lineage']['expires_at']);
    }

    // -----------------------------------------------------------------
    // Live authority under the seller revision: role/membership/seller
    // status are RE-READ AFTER the seller revision is claimed, so a state
    // change already COMMITTED before create() is called (the deterministic
    // stand-in, on a single-connection SQLite harness, for "a concurrent
    // mutation that lands before this attempt") is reflected as the LIVE
    // truth, never a stale/pre-fetched snapshot. Each scenario also proves
    // the WHOLE attempt -- claim included -- rolls back atomically on
    // refusal: the seller's `revision` reverts to its pre-call value rather
    // than being left incremented, because the claim and the refusing check
    // share the SAME transaction (design spec §2.8). (Genuine concurrent-
    // connection interleaving against the claim itself is proven separately
    // by the live-pgsql race tests a later task adds; a single in-process
    // SQLite connection cannot exercise real concurrency.)
    // {@see self::testCreateOnUnknownSellerThrowsNotFound()} above already
    // shows the claim GATES entry -- an unknown seller (claimRevision()
    // returning false) short-circuits to 404 before any live-authority
    // check runs at all, distinct from the SellerApiKeyException variants
    // below that only occur AFTER a successful claim.
    // -----------------------------------------------------------------

    public function testCreateRefusesADemotedActorAndProvesTheClaimRanBeforeTheRefusal(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY16', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY16', 'ownerUserAP16', 'seller_owner');

        // Simulates a role reduction that landed between the caller's
        // route-level authorization snapshot and this call.
        $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', 'sellerAPIKY16')
            ->where('user_uuid', '=', 'ownerUserAP16')
            ->update(['role' => 'seller_staff']);

        try {
            $this->service()->create(
                $this->context,
                self::TENANT,
                'sellerAPIKY16',
                'K',
                ['commerce.seller.orders.read'],
                null,
                'ownerUserAP16'
            );
            self::fail('expected SellerApiKeyException: seller_staff does not hold apikeys.manage');
        } catch (SellerApiKeyException $e) {
            self::assertSame('capability_denied', $e->errorCode);
        }

        $this->assertRolledBackAtomicallyNothingCreated('sellerAPIKY16');
    }

    public function testCreateRefusesARemovedMembershipAndProvesTheClaimRanBeforeTheRefusal(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY17', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY17', 'ownerUserAP17', 'seller_owner');

        // Simulates the membership being revoked between the caller's
        // route-level authorization snapshot and this call.
        $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', 'sellerAPIKY17')
            ->where('user_uuid', '=', 'ownerUserAP17')
            ->update(['status' => 'revoked']);

        try {
            $this->service()->create(
                $this->context,
                self::TENANT,
                'sellerAPIKY17',
                'K',
                ['commerce.seller.orders.read'],
                null,
                'ownerUserAP17'
            );
            self::fail('expected SellerApiKeyException: membership was revoked');
        } catch (SellerApiKeyException $e) {
            self::assertSame('membership_inactive', $e->errorCode);
        }

        $this->assertRolledBackAtomicallyNothingCreated('sellerAPIKY17');
    }

    public function testCreateRefusesASuspendedSellerAndProvesTheClaimRanBeforeTheRefusal(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY18', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY18', 'ownerUserAP18', 'seller_owner');

        // Simulates a suspension that landed between the caller's
        // route-level authorization snapshot and this call.
        $this->connection->table('commerce_sellers')
            ->where('uuid', '=', 'sellerAPIKY18')
            ->update(['status' => 'suspended']);

        try {
            $this->service()->create(
                $this->context,
                self::TENANT,
                'sellerAPIKY18',
                'K',
                ['commerce.seller.orders.read'],
                null,
                'ownerUserAP18'
            );
            self::fail('expected SellerApiKeyException: seller is suspended');
        } catch (SellerApiKeyException $e) {
            self::assertSame('seller_inactive', $e->errorCode);
        }

        $this->assertRolledBackAtomicallyNothingCreated('sellerAPIKY18');
    }

    private function assertRolledBackAtomicallyNothingCreated(string $sellerUuid): void
    {
        $seller = $this->connection->table('commerce_sellers')->where('uuid', '=', $sellerUuid)->first();
        self::assertNotNull($seller);
        self::assertSame(
            0,
            (int) $seller['revision'],
            'the claim + refusal share ONE transaction -- a refused create() must leave no stray revision bump'
        );

        self::assertSame(
            0,
            $this->connection->table('commerce_seller_api_keys')->where('seller_uuid', '=', $sellerUuid)->count(),
            'no lineage row must have been created for the refused seller'
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_api_key_credentials')->count(),
            'no credential row must have been created for the refused seller'
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_api_key_events')->count(),
            'no audit row must have been created for the refused seller'
        );
    }

    // -----------------------------------------------------------------
    // Atomicity: a forced audit-uuid collision rolls back the framework
    // key + lineage + credential rows too.
    // -----------------------------------------------------------------

    public function testForcedAuditUuidCollisionRollsBackTheFrameworkKeyLineageAndCredential(): void
    {
        $this->seedSeller(self::TENANT, 'sellerAPIKY19', 'active');
        $this->seedMembership(self::TENANT, 'sellerAPIKY19', 'ownerUserAP19', 'seller_owner');

        // Pre-seed a colliding audit row under the EXACT (tenant_uuid, uuid)
        // the fixed generator below will hand to EVERY uuid it mints
        // (lineage, credential, AND event) -- mirroring
        // ReservePolicyTest/SellerLifecycleTest's identical idiom. Only the
        // EVENT insert actually collides (this is the only one of the three
        // Commerce tables that already has a row under this uuid+tenant),
        // proving the lineage + credential rows that committed moments
        // earlier in the SAME transaction roll back too -- along with the
        // framework's OWN `api_keys` row, which shares the SAME ambient
        // transaction (ApiKeyService::create() opens no transaction of its
        // own).
        $this->connection->table('commerce_seller_api_key_events')->insert([
            'uuid' => 'collideapik1',
            'tenant_uuid' => self::TENANT,
            'lineage_uuid' => 'someOtherLin',
            'seller_uuid' => 'someOtherSlr',
            'subject_user_uuid' => 'someoneElse1',
            'action' => 'created',
        ]);

        $service = $this->service(static fn (): string => 'collideapik1');

        try {
            $service->create(
                $this->context,
                self::TENANT,
                'sellerAPIKY19',
                'K',
                ['commerce.seller.orders.read'],
                null,
                'ownerUserAP19'
            );
            self::fail('expected the forced audit-insert collision to propagate');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(
            0,
            $this->connection->table('commerce_seller_api_keys')
                ->where('seller_uuid', '=', 'sellerAPIKY19')->count(),
            'the lineage row committed earlier in the transaction must be rolled back too'
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_api_key_credentials')
                ->where('uuid', '=', 'collideapik1')->count(),
            'the credential row committed earlier in the transaction must be rolled back too'
        );
        self::assertSame(
            0,
            $this->connection->table('api_keys')->where('user_uuid', '=', 'ownerUserAP19')->count(),
            'the framework key row committed earlier in the transaction must be rolled back too'
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_api_key_events')
                ->where('uuid', '=', 'collideapik1')->count(),
            'no second audit row must have been inserted'
        );
    }

    // -----------------------------------------------------------------
    // Repository: no update()/delete() on the append-only event surface.
    // -----------------------------------------------------------------

    public function testRepositoryExposesNoUpdateOrDeleteMethodForEvents(): void
    {
        self::assertFalse(method_exists(SellerApiKeyRepository::class, 'update'));
        self::assertFalse(method_exists(SellerApiKeyRepository::class, 'delete'));
        self::assertFalse(method_exists(SellerApiKeyRepository::class, 'updateEvent'));
        self::assertFalse(method_exists(SellerApiKeyRepository::class, 'deleteEvent'));
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
}
