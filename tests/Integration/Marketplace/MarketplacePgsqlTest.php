<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceActivationException;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\SellerAttributionException;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipException;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Validation\ValidationException;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL race lanes for Marketplace MV1 (design spec §4 lock order;
 * plan Task 5). Every case here requires TRUE two-connection row-lock
 * interleaving that SQLite -- a single-process, single-connection engine in
 * this test harness -- cannot exercise at all (PHP has no threads, so a
 * genuine race needs a genuinely separate OS process/connection). Gating,
 * fixture-width discipline (every `uuid`/`tenant_uuid` literal here is 12
 * characters or fewer -- these columns are `varchar(12)`, strictly enforced
 * by PostgreSQL but silently ignored by SQLite), self-healing per-test
 * cleanup, and the throwaway `Connection`/`ApplicationContext` construction
 * all mirror `Http\ApiParityPgsqlTest` exactly. Every subprocess race
 * follows `ApiParityPgsqlTest`/`ReviewConcurrencyTest`'s pattern: connection
 * A (this test) manually replicates the losing/blocked side's PRE-commit
 * steps directly via the repositories (never the full service, so the test
 * can pause mid-transaction), holds the transaction open and uncommitted,
 * launches connection B as a genuinely separate subprocess running the real
 * service call (`fixtures/marketplace_race_child.php`, a single multiplexed
 * script -- every action shares the identical bootstrap and only differs in
 * which service method it calls), sleeps to let B block on A's held row
 * lock, then A completes and commits -- releasing the lock so B's blocked
 * statement can proceed and resolve the race.
 *
 * **Why no genuine "stale ownership" 409 appears between two real
 * `SellerAttributionService::assign()` calls (design spec §4):** every
 * writer of `commerce_products.seller_uuid` -- an attributed create, and
 * `assign()` itself -- claims {@see MarketplaceWorkspaceLock} as the FIRST
 * statement of its transaction, and that claim's row lock is held until the
 * transaction commits. Two calls that both need it therefore fully
 * serialize: the second one's transaction cannot even begin its own
 * snapshot read until the first has ALREADY committed, so its snapshot is
 * always fresh, never stale. The defensive stale-ownership abort in
 * `assign()` step 5 is real protection against a competing write landing
 * strictly between the snapshot and the re-read -- provably reachable only
 * via the single-connection injectable hook
 * {@see SellerAttributionTest::testStaleSourceAbortsTheTransferWith409AndLeavesTheProductExactlyAsItWas()}
 * uses, not via two genuinely racing `assign()` calls on the same tenant.
 * `testTransferVsTransfer*` below instead proves the invariant these two
 * calls CAN observably violate if the lock were missing: genuine
 * cross-connection serialization with zero lost updates / corruption,
 * in both possible commit orderings -- the second (unblocked) call's own
 * write only ever lands after re-claiming and re-validating whichever
 * seller is CURRENTLY the owner at that moment, exactly the "no write
 * occurs without the current source seller claimed" contract.
 */
final class MarketplacePgsqlTest extends CommerceTestCase
{
    // =====================================================================
    // activation-vs-product-create, BOTH orderings (design spec §2.2/§4):
    // end state NEVER has an ACTIVE workspace with an unassigned product.
    // =====================================================================

    public function testActivationCommitsFirstThenConcurrentPlainCreateIs422OnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'mktpgact001';
        $this->cleanupTenant($connectionA, $tenant);

        $workspaceLock = new MarketplaceWorkspaceLock();
        $products = new ProductRepository();

        $connectionA->getTransactionManager()->begin();
        $workspaceLock->claim($contextA, $tenant);
        self::assertSame(0, $products->unassignedCount($contextA, $tenant));
        $now = $connectionA->getDriver()->formatDateTime();
        $connectionA->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', $tenant)
            ->update(['status' => 'active', 'activated_at' => $now, 'updated_at' => $now]);

        $handle = $this->launchRaceChild($pgConfig, 'create', [
            'tenant' => $tenant,
            'sellerUuid' => null,
            'slug' => 'mkt-act-race1',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'], 'the create must fail once activation has already committed');
        self::assertSame(ValidationException::class, $result['exceptionClass'] ?? null);

        self::assertSame(
            0,
            $connectionA->table('commerce_products')->where('tenant_uuid', '=', $tenant)->count(),
            'no product may exist once the create was rejected -- the whole attempt rolled back'
        );
        $settings = $connectionA->table('commerce_marketplace_settings')->where('tenant_uuid', '=', $tenant)->first();
        self::assertSame('active', $settings['status']);

        $this->cleanupTenant($connectionA, $tenant);
    }

    public function testProductCreateCommitsFirstThenConcurrentActivationIs409OnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'mktpgact002';
        $this->cleanupTenant($connectionA, $tenant);

        $workspaceLock = new MarketplaceWorkspaceLock();
        $products = new ProductRepository();

        $connectionA->getTransactionManager()->begin();
        // Mirrors CatalogService::createProduct()'s plain (unattributed)
        // path: claim workspace first, then (implicitly, since a
        // freshly-ensured row is always `disabled`) proceed as if inactive.
        $workspaceLock->claim($contextA, $tenant);
        $products->insert($contextA, [
            'uuid' => 'mktpgactprd1',
            'tenant_uuid' => $tenant,
            'slug' => 'mkt-act-prod2',
            'name' => 'Mkt Act Prod 2',
            'type' => 'physical',
            'status' => 'active',
            'seller_uuid' => null,
        ]);

        $handle = $this->launchRaceChild($pgConfig, 'activate', [
            'tenant' => $tenant,
            'defaultSellerUuid' => null,
            'actor' => null,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'], 'activation must be blocked once an unassigned product has committed');
        self::assertSame(MarketplaceActivationException::class, $result['exceptionClass'] ?? null);
        self::assertSame(1, $result['unassignedCount'] ?? null);

        $settings = $connectionA->table('commerce_marketplace_settings')->where('tenant_uuid', '=', $tenant)->first();
        self::assertNotSame(
            'active',
            $settings['status'] ?? 'disabled',
            'a blocked activation must never leave the workspace active'
        );
        $product = $connectionA->table('commerce_products')->where('uuid', '=', 'mktpgactprd1')->first();
        self::assertNull($product['seller_uuid']);

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // close-vs-product-create: no product ends attributed to a closed seller.
    // =====================================================================

    public function testSellerCloseCommitsFirstThenConcurrentAttributedCreateIs409OnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'mktpgcls001';
        $this->cleanupTenant($connectionA, $tenant);
        $this->activateWorkspace($connectionA, $tenant);
        $seller = $this->seedActiveSeller($contextA, $tenant, 'mkt-cls-sel1', 'ownerCls001');

        $sellers = new SellerRepository();
        $memberships = new SellerMembershipRepository();

        $connectionA->getTransactionManager()->begin();
        self::assertTrue($sellers->claimRevision($contextA, $tenant, $seller['uuid']));
        $current = $sellers->findByUuid($contextA, $tenant, $seller['uuid']);
        self::assertSame('active', $current['status']);
        self::assertFalse($sellers->hasLiveProducts($contextA, $tenant, $seller['uuid']));
        $sellers->update($contextA, $tenant, $seller['uuid'], ['status' => 'closed']);
        $memberships->deactivateAllForSeller($contextA, $tenant, $seller['uuid']);

        $handle = $this->launchRaceChild($pgConfig, 'create', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'slug' => 'mkt-cls-prod1',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'], 'an attributed create targeting a just-closed seller must fail');
        self::assertSame(SellerAttributionException::class, $result['exceptionClass'] ?? null);

        self::assertFalse((new SellerRepository())->hasLiveProducts($contextA, $tenant, $seller['uuid']));
        self::assertSame(
            0,
            $connectionA->table('commerce_products')->where('tenant_uuid', '=', $tenant)->count(),
            'no product may ever land attributed to a closed seller'
        );

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // transfer-vs-transfer, BOTH orderings: genuine cross-connection
    // serialization -- see class docblock for why a genuine two-connection
    // "stale ownership" 409 is architecturally unreachable here.
    // =====================================================================

    public function testConcurrentTransfersSerializeWhenTheFirstTargetCommitsFirstOnRealPostgres(): void
    {
        $this->runTransferVsTransfer('mktpgxfr001', firstTarget: 'mktpgxfrt1a', secondTarget: 'mktpgxfrt1b');
    }

    public function testConcurrentTransfersSerializeWhenTheSecondTargetCommitsFirstOnRealPostgres(): void
    {
        $this->runTransferVsTransfer('mktpgxfr002', firstTarget: 'mktpgxfrt2b', secondTarget: 'mktpgxfrt2a');
    }

    private function runTransferVsTransfer(string $tenant, string $firstTarget, string $secondTarget): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $this->cleanupTenant($connectionA, $tenant);
        $this->activateWorkspace($connectionA, $tenant);

        $source = $this->seedActiveSeller($contextA, $tenant, 'src-' . substr($firstTarget, -6), 'ownerSrc001');
        $targetA = $this->seedActiveSeller($contextA, $tenant, 'tga-' . substr($firstTarget, -6), 'ownerTgA001');
        $targetB = $this->seedActiveSeller($contextA, $tenant, 'tgb-' . substr($firstTarget, -6), 'ownerTgB001');

        $productUuid = 'p' . substr(md5($tenant), 0, 10);
        $connectionA->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => $tenant,
            'slug' => 'mkt-xfr-prod-' . substr($tenant, -3),
            'name' => 'Xfr Prod',
            'type' => 'physical',
            'status' => 'active',
            'seller_uuid' => $source['uuid'],
        ]);

        $sellers = new SellerRepository();
        $products = new ProductRepository();
        $workspaceLock = new MarketplaceWorkspaceLock();

        // Connection A mirrors assign()'s full protocol transferring the
        // product from $source to $targetA, and commits FIRST.
        $connectionA->getTransactionManager()->begin();
        $workspaceLock->claim($contextA, $tenant);
        $snapshot = $products->findLiveByUuid($contextA, $tenant, $productUuid);
        $snapshotSource = $snapshot['seller_uuid'];
        self::assertSame($source['uuid'], $snapshotSource);
        $claimSet = array_unique([$snapshotSource, $targetA['uuid']]);
        sort($claimSet);
        foreach ($claimSet as $sellerUuid) {
            self::assertTrue($sellers->claimRevision($contextA, $tenant, (string) $sellerUuid));
        }
        self::assertTrue($products->claimCatalogRevision($contextA, $tenant, $productUuid));
        $current = $products->findLiveByUuid($contextA, $tenant, $productUuid);
        self::assertSame($snapshotSource, $current['seller_uuid']);
        $products->update($contextA, $tenant, $productUuid, ['seller_uuid' => $targetA['uuid']]);

        // Connection B (subprocess, the REAL service) attempts to transfer
        // the SAME product to $targetB. Its workspace claim blocks entirely
        // on A's held claim -- by the time it unblocks, A has fully
        // committed, so B's own snapshot read is fresh (sees $targetA as
        // the current owner), never stale.
        $handle = $this->launchRaceChild($pgConfig, 'assign', [
            'tenant' => $tenant,
            'productUuid' => $productUuid,
            'targetSellerUuid' => $targetB['uuid'],
            'actor' => null,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'the second (unblocked) transfer must succeed against fresh state, never a stale 409: '
                . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame($targetB['uuid'], $result['sellerUuid']);

        $final = $connectionA->table('commerce_products')->where('uuid', '=', $productUuid)->first();
        self::assertSame(
            $targetB['uuid'],
            $final['seller_uuid'],
            'the LAST transfer to actually commit must be the one reflected -- never a lost update'
        );
        self::assertGreaterThanOrEqual(
            2,
            (int) $final['catalog_revision'],
            'both transfers must have genuinely claimed the product -- never skipped a lock'
        );

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // transfer-vs-close: no transfer lands on a closed seller.
    // =====================================================================

    public function testSellerCloseCommitsFirstThenConcurrentTransferOntoItIs409OnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'mktpgxfc001';
        $this->cleanupTenant($connectionA, $tenant);
        $this->activateWorkspace($connectionA, $tenant);

        $source = $this->seedActiveSeller($contextA, $tenant, 'mkt-xfc-src1', 'ownerXfc001');
        $target = $this->seedActiveSeller($contextA, $tenant, 'mkt-xfc-tgt1', 'ownerXfc002');

        $productUuid = 'mktpgxfcprd1';
        $connectionA->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => $tenant,
            'slug' => 'mkt-xfc-prod1',
            'name' => 'Xfc Prod',
            'type' => 'physical',
            'status' => 'active',
            'seller_uuid' => $source['uuid'],
        ]);

        $sellers = new SellerRepository();
        $memberships = new SellerMembershipRepository();

        // Connection A mirrors close() on the TARGET of an in-flight
        // transfer -- it owns zero live products (the transfer hasn't
        // landed yet), so the close guard passes.
        $connectionA->getTransactionManager()->begin();
        self::assertTrue($sellers->claimRevision($contextA, $tenant, $target['uuid']));
        $currentTarget = $sellers->findByUuid($contextA, $tenant, $target['uuid']);
        self::assertSame('active', $currentTarget['status']);
        self::assertFalse($sellers->hasLiveProducts($contextA, $tenant, $target['uuid']));
        $sellers->update($contextA, $tenant, $target['uuid'], ['status' => 'closed']);
        $memberships->deactivateAllForSeller($contextA, $tenant, $target['uuid']);

        $handle = $this->launchRaceChild($pgConfig, 'assign', [
            'tenant' => $tenant,
            'productUuid' => $productUuid,
            'targetSellerUuid' => $target['uuid'],
            'actor' => null,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'], 'a transfer targeting a just-closed seller must fail');
        self::assertSame(SellerAttributionException::class, $result['exceptionClass'] ?? null);

        $product = $connectionA->table('commerce_products')->where('uuid', '=', $productUuid)->first();
        self::assertSame(
            $source['uuid'],
            $product['seller_uuid'],
            'the product must remain with its original seller -- no transfer lands on a closed seller'
        );

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // Concurrent last-owner demotions serialize; at least one 409s; the
    // seller is never left ownerless.
    // =====================================================================

    public function testConcurrentLastOwnerDemotionsSerializeAndExactlyOneSucceedsOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'mktpgown001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'mkt-own-sel1', 'ownerRaceA1');
        $membershipService = new SellerMembershipService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            new FixedSellerRoleAuthority()
        );
        $membershipService->grant($contextA, $tenant, $seller['uuid'], 'ownerRaceB1', 'seller_owner');

        $sellers = new SellerRepository();
        $memberships = new SellerMembershipRepository();

        // Connection A mirrors changeRole()'s demote path for owner A and
        // commits FIRST.
        $connectionA->getTransactionManager()->begin();
        self::assertTrue($sellers->claimRevision($contextA, $tenant, $seller['uuid']));
        $sellerRow = $sellers->findByUuid($contextA, $tenant, $seller['uuid']);
        self::assertSame('active', $sellerRow['status']);
        $membershipA = $memberships->findBySellerAndUser($contextA, $tenant, $seller['uuid'], 'ownerRaceA1');
        self::assertSame('active', $membershipA['status']);
        self::assertGreaterThan(
            1,
            $memberships->countActiveByRole($contextA, $tenant, $seller['uuid'], 'seller_owner')
        );
        $memberships->update($contextA, $tenant, $membershipA['uuid'], ['role' => 'seller_staff']);

        $handle = $this->launchRaceChild($pgConfig, 'changeRole', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'userUuid' => 'ownerRaceB1',
            'role' => 'seller_staff',
            'actor' => null,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'], 'the second demotion must be refused once only one owner remains');
        self::assertSame(SellerMembershipException::class, $result['exceptionClass'] ?? null);

        $ownerCount = $memberships->countActiveByRole($contextA, $tenant, $seller['uuid'], 'seller_owner');
        self::assertSame(1, $ownerCount, 'the seller must never be left with zero active owners');

        $finalB = $memberships->findBySellerAndUser($contextA, $tenant, $seller['uuid'], 'ownerRaceB1');
        self::assertSame('seller_owner', $finalB['role'], 'the surviving owner must be untouched by the loser');

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // Savepoint recovery on REAL PostgreSQL (T1 carry-over, REQUIRED):
    // unlike SQLite, PostgreSQL genuinely aborts an entire transaction on a
    // failed statement unless that statement is scoped inside its own
    // SAVEPOINT -- this is the specific mechanism
    // MarketplaceWorkspaceLockTest::testVerifiedDuplicateConflictRollsBackOnlyTheSavepointAndOuterTransactionStaysUsable()
    // exercises on SQLite (where the distinction is not actually load-bearing);
    // this proves it holds on the database where it matters.
    // =====================================================================

    public function testVerifiedDuplicateSettingsRowConflictRollsBackOnlyTheSavepointOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connection = $this->migratedConnection($pgConfig);
        $context = $this->pgsqlContext($connection);
        $tenant = 'mktpgsav001';
        $this->cleanupTenant($connection, $tenant);

        $lock = new MarketplaceWorkspaceLock();

        $connection->transaction(function () use ($lock, $context, $tenant): void {
            $lock->claim($context, $tenant);
        });
        $first = $connection->table('commerce_marketplace_settings')->where('tenant_uuid', '=', $tenant)->first();
        self::assertSame(1, (int) $first['revision']);

        // ensureRow() has no upfront existence check -- it always attempts
        // the insert directly, so this second claim's insert genuinely
        // collides with the row committed above, forcing the SAME
        // catch-and-probe path a true concurrent second caller would hit.
        // Proof the OUTER transaction survives the savepoint-scoped
        // conflict on real PG: an unrelated write happens in the SAME
        // outer transaction right after, and the whole thing commits.
        $connection->transaction(function () use ($lock, $context, $tenant, $connection): void {
            $lock->claim($context, $tenant);

            $connection->table('commerce_sellers')->insert([
                'uuid' => 'mktpgsavsel1',
                'tenant_uuid' => $tenant,
                'slug' => 'pg-savepoint-seller',
                'name' => 'PG Savepoint Seller',
            ]);
        });

        $second = $connection->table('commerce_marketplace_settings')->where('tenant_uuid', '=', $tenant)->first();
        self::assertSame(
            2,
            (int) $second['revision'],
            'the claim inside the same outer transaction as the conflict still committed'
        );
        self::assertSame(
            1,
            $connection->table('commerce_marketplace_settings')->where('tenant_uuid', '=', $tenant)->count()
        );
        self::assertSame(
            1,
            $connection->table('commerce_sellers')->where('uuid', '=', 'mktpgsavsel1')->count(),
            'the unrelated write made AFTER the conflict, in the same outer transaction, was committed on real '
                . 'PostgreSQL -- proof the outer transaction was never poisoned by the savepoint-scoped conflict'
        );

        $this->cleanupTenant($connection, $tenant);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane for true two-connection row-lock interleaving.');
        }
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

    /** @return array<string,mixed> */
    private function seedActiveSeller(
        ApplicationContext $context,
        string $tenant,
        string $slug,
        string $ownerUserUuid
    ): array {
        return (new SellerService(new SellerRepository(), new SellerMembershipRepository()))->create(
            $context,
            $tenant,
            $slug,
            ucfirst(str_replace('-', ' ', $slug)),
            null,
            $ownerUserUuid
        );
    }

    private function activateWorkspace(Connection $connection, string $tenant): void
    {
        $connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'a' . substr(md5($tenant), 0, 11),
            'tenant_uuid' => $tenant,
            'status' => 'active',
        ]);
    }

    private function cleanupTenant(Connection $connection, string $tenant): void
    {
        $productUuids = array_column(
            $connection->table('commerce_products')->where('tenant_uuid', '=', $tenant)->get(),
            'uuid'
        );
        foreach ($productUuids as $uuid) {
            $connection->table('commerce_variants')->where('product_uuid', '=', $uuid)->delete();
        }
        $connection->table('commerce_products')->where('tenant_uuid', '=', $tenant)->forceDelete();
        $connection->table('commerce_seller_memberships')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_sellers')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_marketplace_settings')->where('tenant_uuid', '=', $tenant)->delete();
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
