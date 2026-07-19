<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Auth\ApiKey\ApiKey;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Database\Migrations\CreateSellerApiKeysTables;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyException;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyScopeValidator;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyService;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\ConflictException;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL race lanes for Marketplace MV5c-1's seller-API-key
 * lineage/credential lifecycle (design spec §2.8/§2.9/§3; Task 7, GATES).
 * Every case here requires TRUE two-connection row-lock interleaving that
 * SQLite -- a single-process, single-connection engine in this test harness
 * -- cannot exercise at all; {@see SellerApiKeyRotationTest} already proves
 * the identical outcomes SEQUENTIALLY (one call fully committing before the
 * next begins) -- this file proves the SAME outcomes hold when the two
 * operations genuinely CONTEND for the same row lock, not merely run in a
 * chosen order.
 *
 * Mirrors `SellerSuspensionPgsqlTest` primitive-for-primitive: `skipUnlessPgsql()`,
 * `pgConfig()`, `migratedConnection()` (extended here to ALSO create the
 * framework's own `api_keys` table -- {@see SellerApiKeyService::create()}/
 * `rotate()`/`revoke()` all delegate to `Glueful\Auth\ApiKey\ApiKeyService`,
 * which persists/mutates rows there; the framework's own migration class
 * lives outside this package's autoload map, so the shape is reproduced
 * inline exactly as {@see SellerApiKeyRotationTest::setUp()} already does for
 * SQLite), `pgsqlContext()`, `launchRaceChild()`/`collectRaceChild()`
 * (extended with `apiKeyCreate`/`apiKeyRotate`/`apiKeyRevoke` actions -- see
 * `fixtures/marketplace_race_child.php`), and the fixture-width discipline
 * (every `uuid`/`tenant_uuid` literal here is 12 characters or fewer).
 *
 * Connection A manually replicates the claim-then-write critical section of
 * the service under test directly via the repositories/framework
 * `ApiKeyService` (so it can pause mid-transaction and hold its claim open on
 * demand); connection B is always a genuinely separate subprocess running the
 * REAL service end to end.
 *
 * **Design spec §2.9's pinned lock order -- `seller revision -> fresh actor
 * membership/capability re-read -> lineage revision -> framework/credential
 * writes` -- is the shared serialization primitive proven here.** `create()`,
 * `rotate()`, and `revoke()` all claim the SAME seller revision FIRST
 * (byte-identical to how `SellerMembershipService::changeRole()`/`revoke()`
 * and `SellerService::suspend()` already claim it for every other MV1-MV5b
 * mutation), so a concurrent authority/lifecycle change and a concurrent
 * key mutation genuinely block on ONE row lock, never merely race in
 * application-level timing.
 */
final class SellerApiKeyPgsqlTest extends CommerceTestCase
{
    private const OWNER_SCOPES = ['commerce.seller.orders.read'];

    // =====================================================================
    // 1. Rotate vs revoke serialize on the LINEAGE revision (design spec
    //    §2.9), BOTH orderings -- mirrors
    //    `SellerApiKeyRotationTest::testRotateFirstThenRevokeKillsTheSuccessorToo()`/
    //    `testRevokeFirstThenRotateReturns409()` under TRUE concurrent
    //    blocking rather than a chosen sequential order.
    // =====================================================================

    /**
     * Ordering (a): ROTATE commits first. Connection A manually replicates
     * `rotate()`'s full claim-then-write critical section (seller revision ->
     * authority re-read -> lineage revision -> framework rotate ->
     * demote/insert/advance), holding it open. Connection B (subprocess, the
     * REAL `revoke()`) blocks entirely on A's held LINEAGE-revision claim;
     * once A commits, B's own claim succeeds and revokes EVERY generation --
     * the successor A just minted included. Final state: no active successor
     * survives the committed revoke.
     */
    public function testRotateCommitsFirstThenConcurrentRevokeKillsTheSuccessorTooOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'akpgrvfs001';
        $this->cleanupTenant($connectionA, $tenant, 'ownerRVFS001');

        $seller = $this->seedActiveSeller($contextA, $tenant, 'akpg-rvfs-s1', 'ownerRVFS001');
        $seeded = $this->seedLineage($contextA, $tenant, $seller['uuid'], 'ownerRVFS001');

        $connectionA->getTransactionManager()->begin();
        $held = $this->manuallyClaimSellerLineageAndBeginRotate(
            $contextA,
            $tenant,
            $seller['uuid'],
            $seeded['lineage_uuid'],
            'ownerRVFS001'
        );

        $handle = $this->launchRaceChild($pgConfig, 'apiKeyRevoke', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'lineageUuid' => $seeded['lineage_uuid'],
            'actor' => 'ownerRVFS001',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'revoke must succeed once the in-flight rotate has committed: ' . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame('revoked', $result['status']);

        $lineage = $connectionA->table('commerce_seller_api_keys')
            ->where('uuid', '=', $seeded['lineage_uuid'])->first();
        self::assertSame('revoked', $lineage['status']);

        $successorCredential = $connectionA->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', $held['new_credential_uuid'])->first();
        self::assertSame(
            'revoked',
            $successorCredential['relationship'],
            'a committed revoke must kill a successor a preceding in-flight rotate just minted'
        );
        self::assertSame(
            0,
            $connectionA->table('commerce_seller_api_key_credentials')
                ->where('lineage_uuid', '=', $seeded['lineage_uuid'])
                ->where('relationship', '=', 'current')
                ->count(),
            'no active successor may survive a committed revoke'
        );

        $this->cleanupTenant($connectionA, $tenant, 'ownerRVFS001');
    }

    /**
     * Ordering (b): REVOKE commits first. Connection A manually replicates
     * `revoke()`'s full claim-then-write critical section, holding it open.
     * Connection B (subprocess, the REAL `rotate()`) blocks entirely on A's
     * held LINEAGE-revision claim; once unblocked, its own re-read observes
     * the lineage already `revoked` and returns 409 -- zero surviving
     * `current` credentials, no new generation ever inserted.
     */
    public function testRevokeCommitsFirstThenConcurrentRotateReturns409WithNoSurvivingCurrentCredentialOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'akpgrvfs002';
        $this->cleanupTenant($connectionA, $tenant, 'ownerRVFS002');

        $seller = $this->seedActiveSeller($contextA, $tenant, 'akpg-rvfs-s2', 'ownerRVFS002');
        $seeded = $this->seedLineage($contextA, $tenant, $seller['uuid'], 'ownerRVFS002');

        $connectionA->getTransactionManager()->begin();
        $this->manuallyClaimSellerLineageAndBeginRevoke(
            $contextA,
            $tenant,
            $seller['uuid'],
            $seeded['lineage_uuid'],
            'ownerRVFS002'
        );

        $handle = $this->launchRaceChild($pgConfig, 'apiKeyRotate', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'lineageUuid' => $seeded['lineage_uuid'],
            'actor' => 'ownerRVFS002',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'rotate against an already-revoked lineage must fail');
        self::assertSame(ConflictException::class, $result['exceptionClass'] ?? null);

        self::assertSame(
            0,
            $connectionA->table('commerce_seller_api_key_credentials')
                ->where('lineage_uuid', '=', $seeded['lineage_uuid'])
                ->where('relationship', '=', 'current')
                ->count(),
            'no active successor may survive a committed revoke, even one a waiting rotate almost minted'
        );
        self::assertSame(
            1,
            $connectionA->table('commerce_seller_api_key_credentials')
                ->where('lineage_uuid', '=', $seeded['lineage_uuid'])
                ->count(),
            'the failed rotate must never have inserted a second generation'
        );

        $this->cleanupTenant($connectionA, $tenant, 'ownerRVFS002');
    }

    // =====================================================================
    // 2. create() vs manager demotion (design spec §2.8, seller revision),
    //    BOTH orderings.
    // =====================================================================

    /**
     * Ordering (a): CREATE commits first. Connection A manually replicates
     * `create()`'s claim-then-write critical section for a `seller_admin`
     * (never the sole owner -- `changeRole()`'s own last-owner guard would
     * otherwise refuse the demotion below for a reason unrelated to this
     * race) `changeRole()` is about to demote, holding it open. Connection B
     * (subprocess, the REAL `SellerMembershipService::changeRole()`) blocks
     * entirely on A's held seller-revision claim; once A commits, B's own
     * claim succeeds (demotion carries no in-flight-key guard) -- the key
     * created BEFORE demotion committed exists and stays active (its
     * EFFECTIVE reach shrinks only on the next per-request read, design spec
     * §2.4/§2.7 -- not proven here, see `SellerApiKeyAuthTest`).
     */
    public function testCreateCommitsFirstThenConcurrentDemotionAppliesAfterAndTheKeyStaysMintedOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'akpgcrfs001';
        $this->cleanupTenant($connectionA, $tenant, 'adminCRFS001');

        $seller = $this->seedActiveSeller($contextA, $tenant, 'akpg-crfs-s1', 'ownerCRFS001');
        $this->seedAdminMembership($contextA, $tenant, $seller['uuid'], 'adminCRFS001');

        $connectionA->getTransactionManager()->begin();
        $held = $this->manuallyClaimSellerAndBeginCreate(
            $contextA,
            $tenant,
            $seller['uuid'],
            'adminCRFS001',
            self::OWNER_SCOPES,
            'Create-first race key'
        );

        $handle = $this->launchRaceChild($pgConfig, 'changeRole', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'userUuid' => 'adminCRFS001',
            'role' => 'seller_staff',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'demotion must succeed once the in-flight create has committed: ' . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame('seller_staff', $result['role']);

        $lineage = $connectionA->table('commerce_seller_api_keys')
            ->where('uuid', '=', $held['lineage_uuid'])->first();
        self::assertNotNull($lineage, 'the key committed BEFORE demotion must exist');
        self::assertSame('active', $lineage['status']);

        $membership = $connectionA->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', $seller['uuid'])
            ->where('user_uuid', '=', 'adminCRFS001')
            ->first();
        self::assertSame('seller_staff', $membership['role']);

        $this->cleanupTenant($connectionA, $tenant, 'adminCRFS001');
    }

    /**
     * Ordering (b): DEMOTION commits first. Connection A manually replicates
     * `changeRole()`'s claim-then-update for a `seller_admin` (never the sole
     * owner, see this section's ordering-(a) sibling), committing FIRST.
     * Connection B (subprocess, the REAL `create()`) attempts to mint a key
     * for the just-demoted actor -- its own seller-revision claim blocks
     * entirely on A's held claim; once unblocked, its fresh membership
     * re-read observes `seller_staff` (no `apikeys.manage`) and REFUSES with
     * `capability_denied` -- no lineage, no credential, no framework key ever
     * created.
     */
    public function testDemotionCommitsFirstThenConcurrentCreateRefusesWithNoLineageCreatedOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'akpgcrfs002';
        $this->cleanupTenant($connectionA, $tenant, 'adminCRFS002');

        $seller = $this->seedActiveSeller($contextA, $tenant, 'akpg-crfs-s2', 'ownerCRFS002');
        $this->seedAdminMembership($contextA, $tenant, $seller['uuid'], 'adminCRFS002');

        $connectionA->getTransactionManager()->begin();
        $this->manuallyChangeRole($contextA, $tenant, $seller['uuid'], 'adminCRFS002', 'seller_staff');

        $handle = $this->launchRaceChild($pgConfig, 'apiKeyCreate', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'actor' => 'adminCRFS002',
            'scopes' => self::OWNER_SCOPES,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'a create against a just-demoted actor must be refused');
        self::assertSame(SellerApiKeyException::class, $result['exceptionClass'] ?? null);
        self::assertSame('capability_denied', $result['errorCode'] ?? null);

        self::assertSame(
            0,
            $connectionA->table('commerce_seller_api_keys')->where('tenant_uuid', '=', $tenant)->count(),
            'no lineage may exist once the create was refused'
        );

        $this->cleanupTenant($connectionA, $tenant, 'adminCRFS002');
    }

    // =====================================================================
    // 3. rotate() vs seller suspension (design spec §2.9, seller revision),
    //    BOTH orderings.
    // =====================================================================

    /**
     * Ordering (a): ROTATE commits first. Connection A manually replicates
     * `rotate()`'s full critical section, holding it open. Connection B
     * (subprocess, the REAL `SellerService::suspend()`) blocks entirely on
     * A's held seller-revision claim; once A commits, B's own claim succeeds
     * (suspend carries no live-key guard) -- the successor minted BEFORE
     * suspension committed exists.
     */
    public function testRotateCommitsFirstThenConcurrentSuspendAppliesAfterAndTheSuccessorStaysMintedOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'akpgrtsu001';
        $this->cleanupTenant($connectionA, $tenant, 'ownerRTSU001');

        $seller = $this->seedActiveSeller($contextA, $tenant, 'akpg-rtsu-s1', 'ownerRTSU001');
        $seeded = $this->seedLineage($contextA, $tenant, $seller['uuid'], 'ownerRTSU001');

        $connectionA->getTransactionManager()->begin();
        $held = $this->manuallyClaimSellerLineageAndBeginRotate(
            $contextA,
            $tenant,
            $seller['uuid'],
            $seeded['lineage_uuid'],
            'ownerRTSU001'
        );

        $handle = $this->launchRaceChild($pgConfig, 'suspend', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'reason' => 'API-key rotate-first race probe.',
            'actor' => 'operatorAKS1',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'suspend must succeed once the in-flight rotate has committed: ' . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame('suspended', $result['status']);

        $finalSeller = $connectionA->table('commerce_sellers')->where('uuid', '=', $seller['uuid'])->first();
        self::assertSame('suspended', $finalSeller['status']);

        $lineage = $connectionA->table('commerce_seller_api_keys')
            ->where('uuid', '=', $seeded['lineage_uuid'])->first();
        self::assertSame($held['new_credential_uuid'], $lineage['current_credential_uuid']);
        $successorCredential = $connectionA->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', $held['new_credential_uuid'])->first();
        self::assertSame('current', $successorCredential['relationship']);

        $this->cleanupTenant($connectionA, $tenant, 'ownerRTSU001');
    }

    /**
     * Ordering (b): SUSPEND commits first. Connection A manually replicates
     * `SellerService::suspend()`'s claim-then-update, committing FIRST.
     * Connection B (subprocess, the REAL `rotate()`) attempts to rotate the
     * just-suspended seller's key -- its own seller-revision claim blocks
     * entirely on A's held claim; once unblocked, its fresh seller re-read
     * observes `suspended` and REFUSES with `seller_inactive` -- no new
     * generation is ever inserted.
     */
    public function testSuspendCommitsFirstThenConcurrentRotateRefusesWithNoNewGenerationOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'akpgrtsu002';
        $this->cleanupTenant($connectionA, $tenant, 'ownerRTSU002');

        $seller = $this->seedActiveSeller($contextA, $tenant, 'akpg-rtsu-s2', 'ownerRTSU002');
        $seeded = $this->seedLineage($contextA, $tenant, $seller['uuid'], 'ownerRTSU002');

        $connectionA->getTransactionManager()->begin();
        $this->manuallySuspend($contextA, $tenant, $seller['uuid']);

        $handle = $this->launchRaceChild($pgConfig, 'apiKeyRotate', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'lineageUuid' => $seeded['lineage_uuid'],
            'actor' => 'ownerRTSU002',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'a rotate against a just-suspended seller must be refused');
        self::assertSame(SellerApiKeyException::class, $result['exceptionClass'] ?? null);
        self::assertSame('seller_inactive', $result['errorCode'] ?? null);

        self::assertSame(
            1,
            $connectionA->table('commerce_seller_api_key_credentials')
                ->where('lineage_uuid', '=', $seeded['lineage_uuid'])
                ->count(),
            'the refused rotate must never have inserted a second generation'
        );
        $lineage = $connectionA->table('commerce_seller_api_keys')
            ->where('uuid', '=', $seeded['lineage_uuid'])->first();
        self::assertSame($seeded['credential_uuid'], $lineage['current_credential_uuid']);

        $this->cleanupTenant($connectionA, $tenant, 'ownerRTSU002');
    }

    // =====================================================================
    // 4. revoke() vs manager demotion (design spec §2.9, seller revision),
    //    BOTH orderings.
    // =====================================================================

    /**
     * Ordering (a): REVOKE commits first. The lineage's SUBJECT stays the
     * seller's owner (`rotate()`/`revoke()` never require the acting manager
     * to be the lineage's own subject -- design spec §2.9, "even when a
     * DIFFERENT administrator performs the rotation"), but the ACTOR
     * performing the revoke -- and the one demoted below -- is a separate
     * `seller_admin` (never the sole owner: `changeRole()`'s own last-owner
     * guard would otherwise refuse the demotion for a reason unrelated to
     * this race). Connection A manually replicates `revoke()`'s full
     * critical section, holding it open. Connection B (subprocess, the REAL
     * `changeRole()`) blocks entirely on A's held seller-revision claim;
     * once A commits, B's own claim succeeds -- the lineage revoked BEFORE
     * demotion committed stays revoked.
     */
    public function testRevokeCommitsFirstThenConcurrentDemotionAppliesAfterOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'akpgrvdm001';
        $this->cleanupTenant($connectionA, $tenant, 'ownerRVDM001');

        $seller = $this->seedActiveSeller($contextA, $tenant, 'akpg-rvdm-s1', 'ownerRVDM001');
        $this->seedAdminMembership($contextA, $tenant, $seller['uuid'], 'adminRVDM001');
        $seeded = $this->seedLineage($contextA, $tenant, $seller['uuid'], 'ownerRVDM001');

        $connectionA->getTransactionManager()->begin();
        $this->manuallyClaimSellerLineageAndBeginRevoke(
            $contextA,
            $tenant,
            $seller['uuid'],
            $seeded['lineage_uuid'],
            'adminRVDM001'
        );

        $handle = $this->launchRaceChild($pgConfig, 'changeRole', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'userUuid' => 'adminRVDM001',
            'role' => 'seller_staff',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'demotion must succeed once the in-flight revoke has committed: ' . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame('seller_staff', $result['role']);

        $lineage = $connectionA->table('commerce_seller_api_keys')
            ->where('uuid', '=', $seeded['lineage_uuid'])->first();
        self::assertSame('revoked', $lineage['status']);

        $this->cleanupTenant($connectionA, $tenant, 'ownerRVDM001');
    }

    /**
     * Ordering (b): DEMOTION commits first. As above, the lineage's subject
     * stays the owner while the ACTOR revoking (and being demoted) is a
     * separate `seller_admin`. Connection A manually replicates
     * `changeRole()`'s claim-then-update, committing FIRST. Connection B
     * (subprocess, the REAL `revoke()`) attempts to revoke the owner's key AS
     * the just-demoted admin -- its own seller-revision claim blocks entirely
     * on A's held claim; once unblocked, its fresh membership re-read
     * observes `seller_staff` and REFUSES with `capability_denied` -- the
     * lineage stays active, untouched.
     */
    public function testDemotionCommitsFirstThenConcurrentRevokeRefusesAndTheLineageStaysActiveOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'akpgrvdm002';
        $this->cleanupTenant($connectionA, $tenant, 'ownerRVDM002');

        $seller = $this->seedActiveSeller($contextA, $tenant, 'akpg-rvdm-s2', 'ownerRVDM002');
        $this->seedAdminMembership($contextA, $tenant, $seller['uuid'], 'adminRVDM002');
        $seeded = $this->seedLineage($contextA, $tenant, $seller['uuid'], 'ownerRVDM002');

        $connectionA->getTransactionManager()->begin();
        $this->manuallyChangeRole($contextA, $tenant, $seller['uuid'], 'adminRVDM002', 'seller_staff');

        $handle = $this->launchRaceChild($pgConfig, 'apiKeyRevoke', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'lineageUuid' => $seeded['lineage_uuid'],
            'actor' => 'adminRVDM002',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'a revoke by a just-demoted actor must be refused');
        self::assertSame(SellerApiKeyException::class, $result['exceptionClass'] ?? null);
        self::assertSame('capability_denied', $result['errorCode'] ?? null);

        $lineage = $connectionA->table('commerce_seller_api_keys')
            ->where('uuid', '=', $seeded['lineage_uuid'])->first();
        self::assertSame('active', $lineage['status'], 'a refused revoke must leave the lineage untouched');

        $this->cleanupTenant($connectionA, $tenant, 'ownerRVDM002');
    }

    // =====================================================================
    // 5. Migration-shape live (design spec §3): a thin, self-contained
    //    confirmation local to this race-test file. The EXHAUSTIVE
    //    column/unique/index/dedupe-behavior proof for all three
    //    migration-018 tables already lives in `SellerApiKeyShapeTest`'s own
    //    `skipUnlessPgsql()`-gated lanes (Task 2) -- run as part of the SAME
    //    full pgsql suite this file's lanes run in; not duplicated here.
    // =====================================================================

    public function testMigration018ConvergesOnRealPostgresAndRerunningIsANoOp(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());

        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_api_keys',
            'commerce_seller_api_keys_current_credential_unique',
            ['tenant_uuid', 'current_credential_uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_api_key_credentials',
            'commerce_seller_api_key_credentials_lineage_generation_unique',
            ['tenant_uuid', 'lineage_uuid', 'generation']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_api_key_events',
            'commerce_seller_api_key_events_dedupe_unique',
            ['tenant_uuid', 'lineage_uuid', 'action', 'reason_code', 'bucket_start']
        );

        $schema = $connection->getSchemaBuilder();
        (new CreateSellerApiKeysTables())->up($schema);
        (new CreateSellerApiKeysTables())->up($schema);

        self::assertTrue($schema->hasTable('commerce_seller_api_keys'));
        self::assertTrue($schema->hasTable('commerce_seller_api_key_credentials'));
        self::assertTrue($schema->hasTable('commerce_seller_api_key_events'));
    }

    // -----------------------------------------------------------------
    // Helpers: manual critical-section replicas for connection A.
    // -----------------------------------------------------------------

    /**
     * Mirrors {@see SellerApiKeyService::create()}'s critical section
     * exactly, minus its own input-syntax pre-validation (irrelevant to the
     * race) -- claim the seller revision, re-read seller + actor membership +
     * role, mint the framework key, insert the lineage + its first
     * credential. Deliberately skips the audit-event insert (immaterial to
     * every assertion in this file); the transaction is left OPEN for the
     * caller to commit/rollback.
     *
     * @param list<string> $scopes
     * @return array{lineage_uuid: string, credential_uuid: string, framework_key_uuid: string}
     */
    private function manuallyClaimSellerAndBeginCreate(
        ApplicationContext $contextA,
        string $tenant,
        string $sellerUuid,
        string $actorUuid,
        array $scopes,
        string $name
    ): array {
        $sellers = new SellerRepository();
        $memberships = new SellerMembershipRepository();
        $apiKeys = new SellerApiKeyRepository();

        self::assertTrue($sellers->claimRevision($contextA, $tenant, $sellerUuid));
        $seller = $sellers->findByUuid($contextA, $tenant, $sellerUuid);
        self::assertSame('active', $seller['status']);

        $membership = $memberships->findBySellerAndUser($contextA, $tenant, $sellerUuid, $actorUuid);
        self::assertSame('active', $membership['status']);
        self::assertTrue(
            (new FixedSellerRoleAuthority())->allows((string) $membership['role'], FixedSellerRoleAuthority::APIKEYS_MANAGE)
        );

        $lineageUuid = Utils::generateNanoID();
        $credentialUuid = Utils::generateNanoID();

        $created = ApiKeyService::create($contextA, [
            'user_uuid' => $actorUuid,
            'name' => $name,
            'scopes' => $scopes,
            'expires_at' => null,
        ]);
        $frameworkKeyUuid = (string) $created['key']->uuid;

        $apiKeys->insertLineage($contextA, $tenant, [
            'uuid' => $lineageUuid,
            'seller_uuid' => $sellerUuid,
            'subject_user_uuid' => $actorUuid,
            'declared_scopes' => $scopes,
            'name' => $name,
            'status' => 'active',
            'current_credential_uuid' => $credentialUuid,
            'expires_at' => null,
            'created_by' => $actorUuid,
        ]);
        $apiKeys->insertCredential($contextA, $tenant, [
            'uuid' => $credentialUuid,
            'lineage_uuid' => $lineageUuid,
            'framework_key_uuid' => $frameworkKeyUuid,
            'generation' => 1,
            'relationship' => 'current',
        ]);

        return [
            'lineage_uuid' => $lineageUuid,
            'credential_uuid' => $credentialUuid,
            'framework_key_uuid' => $frameworkKeyUuid,
        ];
    }

    /**
     * Mirrors {@see SellerApiKeyService::rotate()}'s critical section
     * exactly: claim the seller revision, re-read authority, claim the
     * LINEAGE revision, re-read the current credential + its framework key,
     * delegate to the framework's OWN `ApiKeyService::rotate()`, demote the
     * predecessor, insert the successor credential, advance the pointer.
     * Left OPEN for the caller to commit/rollback.
     *
     * @return array{new_credential_uuid: string}
     */
    private function manuallyClaimSellerLineageAndBeginRotate(
        ApplicationContext $contextA,
        string $tenant,
        string $sellerUuid,
        string $lineageUuid,
        string $actorUuid
    ): array {
        $sellers = new SellerRepository();
        $memberships = new SellerMembershipRepository();
        $apiKeys = new SellerApiKeyRepository();

        self::assertTrue($sellers->claimRevision($contextA, $tenant, $sellerUuid));
        $seller = $sellers->findByUuid($contextA, $tenant, $sellerUuid);
        self::assertSame('active', $seller['status']);

        $membership = $memberships->findBySellerAndUser($contextA, $tenant, $sellerUuid, $actorUuid);
        self::assertSame('active', $membership['status']);
        self::assertTrue(
            (new FixedSellerRoleAuthority())->allows((string) $membership['role'], FixedSellerRoleAuthority::APIKEYS_MANAGE)
        );

        self::assertTrue($apiKeys->claimActiveLineageRevision($contextA, $tenant, $lineageUuid));
        $lineage = $apiKeys->findLineageByUuid($contextA, $tenant, $lineageUuid);
        self::assertNotNull($lineage);
        $credential = $apiKeys->findCredentialByUuid($contextA, $tenant, (string) $lineage['current_credential_uuid']);
        self::assertNotNull($credential);

        $frameworkKey = ApiKey::query($contextA)->where('uuid', '=', $credential['framework_key_uuid'])->first();
        self::assertInstanceOf(ApiKey::class, $frameworkKey);

        $rotated = ApiKeyService::rotate($contextA, $frameworkKey);
        $apiKeys->demoteCredentialToPredecessor(
            $contextA,
            $tenant,
            (string) $credential['uuid'],
            (string) $rotated['old_expires_at']
        );

        $newCredentialUuid = Utils::generateNanoID();
        $apiKeys->insertCredential($contextA, $tenant, [
            'uuid' => $newCredentialUuid,
            'lineage_uuid' => $lineageUuid,
            'framework_key_uuid' => $rotated['new_uuid'],
            'generation' => ((int) $credential['generation']) + 1,
            'relationship' => 'current',
        ]);
        $apiKeys->advanceLineageCurrentCredential($contextA, $tenant, $lineageUuid, $newCredentialUuid, gmdate('Y-m-d H:i:s'));

        return ['new_credential_uuid' => $newCredentialUuid];
    }

    /**
     * Mirrors {@see SellerApiKeyService::revoke()}'s critical section
     * exactly: claim the seller revision, re-read authority, claim the
     * LINEAGE revision, revoke EVERY recorded credential's framework key,
     * mark every credential + the lineage itself revoked. Left OPEN for the
     * caller to commit/rollback.
     */
    private function manuallyClaimSellerLineageAndBeginRevoke(
        ApplicationContext $contextA,
        string $tenant,
        string $sellerUuid,
        string $lineageUuid,
        string $actorUuid
    ): void {
        $sellers = new SellerRepository();
        $memberships = new SellerMembershipRepository();
        $apiKeys = new SellerApiKeyRepository();

        self::assertTrue($sellers->claimRevision($contextA, $tenant, $sellerUuid));
        $seller = $sellers->findByUuid($contextA, $tenant, $sellerUuid);
        self::assertSame('active', $seller['status']);

        $membership = $memberships->findBySellerAndUser($contextA, $tenant, $sellerUuid, $actorUuid);
        self::assertSame('active', $membership['status']);
        self::assertTrue(
            (new FixedSellerRoleAuthority())->allows((string) $membership['role'], FixedSellerRoleAuthority::APIKEYS_MANAGE)
        );

        self::assertTrue($apiKeys->claimActiveLineageRevision($contextA, $tenant, $lineageUuid));

        $revokedAt = gmdate('Y-m-d H:i:s');
        foreach ($apiKeys->findCredentialsForLineage($contextA, $tenant, $lineageUuid) as $credential) {
            if ((string) $credential['relationship'] === 'revoked') {
                continue;
            }
            $frameworkKey = ApiKey::query($contextA)->where('uuid', '=', $credential['framework_key_uuid'])->first();
            if ($frameworkKey instanceof ApiKey && !$frameworkKey->isRevoked()) {
                ApiKeyService::revoke($contextA, $frameworkKey);
            }
            $apiKeys->markCredentialRevoked($contextA, $tenant, (string) $credential['uuid'], $revokedAt);
        }
        $apiKeys->markLineageRevoked($contextA, $tenant, $lineageUuid, $revokedAt);
    }

    /**
     * Mirrors {@see \Glueful\Extensions\Commerce\Marketplace\SellerMembershipService::changeRole()}'s
     * claim-then-update exactly (no live-key guard, mirroring
     * `SellerSuspensionPgsqlTest`'s identical `suspend()` replica).
     */
    private function manuallyChangeRole(
        ApplicationContext $contextA,
        string $tenant,
        string $sellerUuid,
        string $userUuid,
        string $newRole
    ): void {
        $sellers = new SellerRepository();
        $memberships = new SellerMembershipRepository();

        self::assertTrue($sellers->claimRevision($contextA, $tenant, $sellerUuid));
        $membership = $memberships->findBySellerAndUser($contextA, $tenant, $sellerUuid, $userUuid);
        self::assertNotNull($membership);
        $memberships->update($contextA, $tenant, (string) $membership['uuid'], ['role' => $newRole]);
    }

    /**
     * Mirrors {@see \Glueful\Extensions\Commerce\Marketplace\SellerService::suspend()}'s
     * claim-then-update exactly -- byte-identical to
     * `SellerSuspensionPgsqlTest`'s own inline replica.
     */
    private function manuallySuspend(ApplicationContext $contextA, string $tenant, string $sellerUuid): void
    {
        $sellers = new SellerRepository();
        self::assertTrue($sellers->claimRevision($contextA, $tenant, $sellerUuid));
        $sellers->update($contextA, $tenant, $sellerUuid, ['status' => 'suspended']);
    }

    // -----------------------------------------------------------------
    // Helpers: seeding.
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function seedActiveSeller(
        ApplicationContext $context,
        string $tenant,
        string $slug,
        string $ownerUserUuid
    ): array {
        return (new SellerService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            new SellerLifecycleEventRepository()
        ))->create(
            $context,
            $tenant,
            $slug,
            ucfirst(str_replace('-', ' ', $slug)),
            null,
            $ownerUserUuid
        );
    }

    /**
     * Seeds a `seller_admin` membership directly (bypassing
     * `SellerMembershipService::grant()`'s own claim -- irrelevant setup, not
     * part of the race under test) -- an admin is NEVER the sole owner, so
     * demoting/using one as a race actor never trips `changeRole()`'s own
     * last-owner guard the way demoting the seller's auto-created OWNER
     * would.
     */
    private function seedAdminMembership(
        ApplicationContext $contextA,
        string $tenant,
        string $sellerUuid,
        string $adminUuid
    ): void {
        (new SellerMembershipRepository())->insert($contextA, [
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $tenant,
            'seller_uuid' => $sellerUuid,
            'user_uuid' => $adminUuid,
            'role' => 'seller_admin',
            'status' => 'active',
            'created_by' => $adminUuid,
        ]);
    }

    /**
     * Seeds a lineage through the REAL {@see SellerApiKeyService::create()}
     * flow (sequential, fully committed BEFORE the race begins) -- so
     * `rotate()`/`revoke()` race lanes exercise a genuine, fully-wired
     * lineage/credential/framework-key triple, mirroring
     * `SellerApiKeyRotationTest::seedLineage()`'s identical convention.
     *
     * @return array{lineage_uuid: string, credential_uuid: string, framework_key_uuid: string}
     */
    private function seedLineage(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $subjectUuid
    ): array {
        $roles = new FixedSellerRoleAuthority();
        $service = new SellerApiKeyService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            new SellerApiKeyRepository(),
            $roles,
            new SellerApiKeyScopeValidator($roles)
        );

        $created = $service->create(
            $context,
            $tenant,
            $sellerUuid,
            'Pgsql race seed key',
            self::OWNER_SCOPES,
            null,
            $subjectUuid
        );

        $lineage = $created['lineage'];
        $credential = db($context)->table('commerce_seller_api_key_credentials')
            ->where('uuid', '=', $lineage['current_credential_uuid'])->first();

        return [
            'lineage_uuid' => (string) $lineage['uuid'],
            'credential_uuid' => (string) $credential['uuid'],
            'framework_key_uuid' => (string) $credential['framework_key_uuid'],
        ];
    }

    // -----------------------------------------------------------------
    // Helpers: harness plumbing (mirrors SellerSuspensionPgsqlTest exactly).
    // -----------------------------------------------------------------

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane for true two-connection row-lock interleaving.');
        }
    }

    /**
     * `api_keys` is the framework's OWN table (no `tenant_uuid` column at
     * all -- see this class's own docblock) -- `$ownerUuid` is the exact
     * subject/actor uuid the calling test seeded, so this cleans up ONLY the
     * framework key(s) that test itself minted, never a wildcard sweep that
     * could race a concurrently-running test on this SAME persistent
     * database.
     */
    private function cleanupTenant(Connection $connection, string $tenant, string $ownerUuid): void
    {
        $connection->table('commerce_seller_api_key_events')->where('tenant_uuid', '=', $tenant)->delete();
        $lineageUuids = array_column(
            $connection->table('commerce_seller_api_keys')->where('tenant_uuid', '=', $tenant)->get(),
            'uuid'
        );
        foreach ($lineageUuids as $lineageUuid) {
            $connection->table('commerce_seller_api_key_credentials')
                ->where('lineage_uuid', '=', (string) $lineageUuid)->delete();
        }
        $connection->table('commerce_seller_api_keys')->where('tenant_uuid', '=', $tenant)->delete();

        $connection->table('api_keys')->where('user_uuid', '=', $ownerUuid)->delete();

        $connection->table('commerce_seller_memberships')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_sellers')->where('tenant_uuid', '=', $tenant)->delete();
    }

    /**
     * @param array<string,mixed> $pgConfig
     * @param array<string,mixed> $args
     * @return array{0: resource, 1: array<int,resource>}
     */
    private function launchRaceChild(array $pgConfig, string $action, array $args): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/marketplace_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $action,
                json_encode($args, JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int,resource>} $handle
     * @return array<string,mixed>
     */
    private function collectRaceChild(array $handle): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "subprocess produced no parseable result. stderr: {$stderr}");

        return $result;
    }

    /**
     * `pg_indexes.indexdef` looks like `CREATE INDEX name ON public.table
     * USING btree (col_a, col_b)` (or `CREATE UNIQUE INDEX ...` for a named
     * unique constraint) -- the column list (in order) is the content of the
     * LAST parenthesized group. Mirrors `SellerApiKeyShapeTest`'s /
     * `SellerSuspensionPgsqlTest`'s identical helper exactly.
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

    /**
     * Runs every commerce migration PLUS the framework's own `api_keys`
     * table (outside this package's autoload map -- see this class's own
     * docblock) so {@see SellerApiKeyService::create()}/`rotate()`/`revoke()`
     * can genuinely delegate to `Glueful\Auth\ApiKey\ApiKeyService` on this
     * connection, exactly as {@see SellerApiKeyRotationTest::setUp()}
     * reproduces inline for SQLite.
     *
     * @param array<string,mixed> $pgConfig
     */
    private function migratedConnection(array $pgConfig): Connection
    {
        $connection = new Connection($pgConfig);
        $schema = $connection->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

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

        return $connection;
    }

    private function pgsqlContext(Connection $connection): ApplicationContext
    {
        $container = new class ($connection) implements ContainerInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database' || $id === Connection::class;
            }
        };

        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->setContainer($container);
        $context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../config/commerce.php');
        $context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);

        return $context;
    }
}
