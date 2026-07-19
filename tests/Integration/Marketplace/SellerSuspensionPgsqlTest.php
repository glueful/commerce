<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Database\Migrations\CreateSellerLifecycleEventsTable;
use Glueful\Extensions\Commerce\Marketplace\CheckoutConflictException;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\DestinationStatus;
use Glueful\Extensions\Contracts\Payments\PayoutCollector;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;
use Glueful\Extensions\Contracts\Payments\PayoutStatusResult;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL race lanes for Marketplace MV5b's seller-suspension
 * enforcement (design spec §2.4/§2.7/§3; Task 7, GATES). Every case here
 * requires TRUE two-connection row-lock interleaving that SQLite -- a
 * single-process, single-connection engine in this test harness -- cannot
 * exercise at all. This file mirrors `CheckoutClaimPgsqlTest`/
 * `PayoutSagaPgsqlTest` primitive-for-primitive: `skipUnlessPgsql()`,
 * `pgConfig()`, `migratedConnection()`, `pgsqlContext()`,
 * `launchRaceChild()`/`collectRaceChild()`, and the fixture-width discipline
 * (every `uuid`/`tenant_uuid` literal here is 12 characters or fewer --
 * `varchar(12)`, strictly enforced by PostgreSQL but silently ignored by
 * SQLite). Connection A either manually replicates the claim-then-write
 * critical section of the real service under test directly via the
 * repositories (so it can pause mid-transaction and hold its claim open on
 * demand), or manually replicates `SellerService::suspend()`'s own
 * claim-then-update (no live-products guard, unlike `close()`); connection B
 * is always a genuinely separate subprocess
 * (`fixtures/marketplace_race_child.php`, extended with `suspend` and
 * `payoutRecord` actions for this task) running the REAL service end to end.
 *
 * Two invariants are proven under BOTH commit orderings each: (design spec
 * §2.4) an order is NEVER created for a seller after suspension commits, and
 * (design spec §2.7) a payout is NEVER created for a seller after suspension
 * commits -- the seller REVISION is the shared serialization primitive for
 * both `CheckoutService::claimMarketplaceOwnership()` and
 * `PayoutService::record()`/`reserve()`.
 */
final class SellerSuspensionPgsqlTest extends CommerceTestCase
{
    // =====================================================================
    // 1. suspension vs checkout (design spec §2.4, workspace -> seller ->
    //    product claim order), BOTH orderings.
    // =====================================================================

    /**
     * Ordering (a): the CHECKOUT commits first. Connection A manually
     * replicates the claim+write phase of a partitioned checkout, holding
     * the seller claim open (uncommitted). Connection B (subprocess, the
     * REAL `SellerService::suspend()`) blocks entirely on A's held claim;
     * once A commits, B's own claim succeeds (suspend carries no
     * live-products guard) and the seller transitions to `suspended` -- the
     * order, placed BEFORE suspension, exists and remains fulfillable.
     */
    public function testCheckoutCommitsFirstThenConcurrentSuspendAppliesAfterAndTheOrderRemainsFulfillableOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'sspgckfs001';
        $this->cleanupTenant($connectionA, $tenant);
        $this->activateWorkspace($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'sspg-ckf-sel1', 'ownerCKFS001');

        $productUuid = 'sspgckfprd01';
        $orderUuid = 'sspgckford01';
        $connectionA->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => $tenant,
            'slug' => 'sspg-ckf-prod1',
            'name' => 'Suspension Checkout-First Prod',
            'type' => 'physical',
            'status' => 'active',
            'seller_uuid' => $seller['uuid'],
        ]);

        // Connection A manually replicates the CLAIM+WRITE phase of a partitioned
        // checkout for $productUuid, holding the seller claim open (uncommitted).
        $connectionA->getTransactionManager()->begin();
        $this->manuallyPlaceMinimalPartitionedOrder(
            $connectionA,
            $contextA,
            $tenant,
            $seller['uuid'],
            (string) $seller['name'],
            $productUuid,
            $orderUuid,
            'SSPGCKFS001-1'
        );

        // Connection B (subprocess, the REAL SellerService::suspend()) attempts to
        // suspend the seller -- its own seller-revision claim blocks entirely on A's
        // held claim.
        $handle = $this->launchRaceChild($pgConfig, 'suspend', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'reason' => 'Checkout-first race probe.',
            'actor' => 'operatorSSP1',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'suspend must succeed once the in-flight checkout has committed: '
                . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame('suspended', $result['status']);

        $finalSeller = $connectionA->table('commerce_sellers')->where('uuid', '=', $seller['uuid'])->first();
        self::assertSame('suspended', $finalSeller['status']);

        // The order committed BEFORE suspension -- it exists, untouched, attributed
        // to the seller.
        self::assertSame(1, $connectionA->table('commerce_orders')->where('uuid', '=', $orderUuid)->count());
        $sellerOrder = $connectionA->table('commerce_seller_orders')->where('order_uuid', '=', $orderUuid)->first();
        self::assertNotNull($sellerOrder);
        self::assertSame($seller['uuid'], $sellerOrder['seller_uuid']);

        // Fulfillment is prospective-only (design spec §2.5): confirming payment and
        // fulfilling the ALREADY-placed order succeeds even though the seller is now
        // suspended -- fulfillment is never gated on the seller's CURRENT status.
        // Directly stamps the payment-confirmed state fulfill() requires
        // (`commerce_orders.status = 'paid'` -- OrderStateMachine only allows
        // `paid -> fulfilled`, never `pending_payment -> fulfilled` directly -- plus
        // the child's own `confirmed_at`) rather than running the full payment saga,
        // which is orthogonal to the race this test proves.
        $connectionA->table('commerce_orders')
            ->where('uuid', '=', $orderUuid)
            ->update(['status' => 'paid']);
        $connectionA->table('commerce_seller_orders')
            ->where('order_uuid', '=', $orderUuid)
            ->update(['confirmed_at' => $connectionA->getDriver()->formatDateTime()]);

        $fulfillment = new SellerOrderFulfillmentService(new OrderRepository(), new SellerOrderRepository());
        $fulfilled = $fulfillment->fulfill(
            $contextA,
            $tenant,
            $orderUuid,
            (string) $sellerOrder['uuid'],
            ['tracking_number' => 'TRACK-SSPG-CKF-1'],
            null
        );
        self::assertSame('fulfilled', $fulfilled['fulfillment_status']);

        $this->cleanupTenant($connectionA, $tenant);
    }

    /**
     * Ordering (b): the SUSPENSION commits first. Connection A manually
     * replicates `SellerService::suspend()`'s claim-then-update (active ->
     * suspended, no live-products guard), and commits FIRST. Connection B
     * (subprocess, the REAL `CheckoutService`) attempts to check out a
     * product owned by the just-suspended seller -- its own seller claim
     * blocks entirely on A's held claim; once unblocked, its re-read
     * observes `suspended` and the checkout FAILS -- NO order or seller-order
     * row is ever created.
     */
    public function testSuspendCommitsFirstThenConcurrentCheckoutFailsWithNoOrderCreatedOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'sspgsucf001';
        $this->cleanupTenant($connectionA, $tenant);
        $this->activateWorkspace($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'sspg-sucf-sel1', 'ownerSUCF001');

        $productUuid = 'sspgsucfprd1';
        $variantUuid = 'sspgsucfvar1';
        $connectionA->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => $tenant,
            'slug' => 'sspg-sucf-prod1',
            'name' => 'Suspension Suspend-First Prod',
            'type' => 'physical',
            'status' => 'active',
            'seller_uuid' => $seller['uuid'],
        ]);
        $connectionA->table('commerce_variants')->insert([
            'uuid' => $variantUuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'sku' => 'SSPG-SUCF-1',
            'option_values' => '{}',
            'price' => 1000,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $sellers = new SellerRepository();

        // Connection A mirrors SellerService::suspend()'s claim-then-update
        // (active -> suspended, no live-products guard), and commits FIRST.
        $connectionA->getTransactionManager()->begin();
        self::assertTrue($sellers->claimRevision($contextA, $tenant, $seller['uuid']));
        $sellers->update($contextA, $tenant, $seller['uuid'], ['status' => 'suspended']);

        // Connection B (subprocess, the REAL CheckoutService) attempts to check out a
        // product owned by the just-suspended seller -- its own seller claim blocks
        // entirely on A's held claim.
        $handle = $this->launchRaceChild($pgConfig, 'checkout', [
            'tenant' => $tenant,
            'variantUuid' => $variantUuid,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'checkout against a just-suspended seller must fail');
        self::assertSame(CheckoutConflictException::class, $result['exceptionClass'] ?? null);

        // NEVER an order created for a seller after suspension commits.
        self::assertSame(
            0,
            $connectionA->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->count(),
            'no order may exist once the checkout was rejected'
        );
        self::assertSame(
            0,
            $connectionA->table('commerce_seller_orders')->where('seller_uuid', '=', $seller['uuid'])->count()
        );

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 2. suspension vs payout reservation (design spec §2.7, revision ->
    //    account-lock order), BOTH orderings.
    // =====================================================================

    /**
     * Ordering (a): the PAYOUT RESERVATION commits first. Connection A
     * manually replicates `PayoutService::reserve()`'s pre-I/O critical
     * section (claim the seller revision -> re-read status=active -> claim
     * the account lock -> re-read balance under it -> insert the `pending`
     * row -> post `reserve_hold`) directly via the repositories, held open.
     * Connection B (subprocess, the REAL `SellerService::suspend()`) blocks
     * entirely on A's held seller-revision claim; once A commits, B's own
     * claim succeeds and the seller transitions to `suspended` -- the
     * already-committed payout is correctly treated as in-flight and still
     * reconciles to completion afterward (design spec §2.7).
     */
    public function testPayoutReserveCommitsFirstThenConcurrentSuspendAppliesAfterAndThePayoutRemainsInFlightAndReconcilesOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'sspgpofs001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'sspg-pofs-sel1', 'ownerPOFS001');
        $this->seedAvailable($contextA, $tenant, $seller['uuid'], 5000);

        $sellers = new SellerRepository();
        $ledger = new LedgerRepository();
        $payouts = new PayoutRepository();
        $accountKey = LedgerRepository::accountKeyForSeller($seller['uuid']);
        $payoutUuid = 'sspgpofspay1';
        $reserveAmount = 2000;

        // Connection A: manually replicates PayoutService::reserve()'s pre-I/O
        // critical section -- claim the seller revision FIRST (design spec §2.7),
        // re-read status=active, claim the account lock, re-read available under it,
        // insert the pending/provider row, then post reserve_hold -- held open.
        $connectionA->getTransactionManager()->begin();
        self::assertTrue($sellers->claimRevision($contextA, $tenant, $seller['uuid']));
        $sellerUnderClaim = $sellers->findByUuid($contextA, $tenant, $seller['uuid']);
        self::assertSame('active', $sellerUnderClaim['status']);
        (new LedgerAccountLock())->claim($contextA, $tenant, $accountKey, 'USD');
        $availableUnderLock = (new SellerBalanceService($ledger))->available($contextA, $tenant, $seller['uuid'], 'USD');
        self::assertSame(5000, $availableUnderLock);
        $payouts->insert($contextA, [
            'uuid' => $payoutUuid,
            'tenant_uuid' => $tenant,
            'seller_uuid' => $seller['uuid'],
            'currency' => 'USD',
            'amount' => $reserveAmount,
            'idempotency_key' => $payoutUuid,
            'status' => 'pending',
            'method' => 'provider',
            'provider' => 'default',
            'destination_ref' => 'acct-sspg-pofs',
            'retryable' => false,
            'attempt_count' => 1,
        ]);
        $ledger->post($contextA, $tenant, [
            'account_kind' => 'seller',
            'account_key' => $accountKey,
            'seller_uuid' => $seller['uuid'],
            'currency' => 'USD',
            'entry_type' => 'reserve_hold',
            'amount' => -$reserveAmount,
            'payout_uuid' => $payoutUuid,
            'idempotency_key' => "{$payoutUuid}:reserve_hold",
        ]);

        // Connection B (subprocess, the REAL SellerService::suspend()) attempts to
        // suspend the seller -- its own seller-revision claim blocks entirely on A's
        // held claim.
        $handle = $this->launchRaceChild($pgConfig, 'suspend', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'reason' => 'Payout-first race probe.',
            'actor' => 'operatorSSP1',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'suspend must succeed once the in-flight payout reservation has committed: '
                . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame('suspended', $result['status']);

        // The payout committed BEFORE suspension -- exactly one row, still pending,
        // untouched by the later suspend.
        self::assertSame(1, $connectionA->table('commerce_payouts')->where('tenant_uuid', '=', $tenant)->count());
        $payoutRow = $connectionA->table('commerce_payouts')->where('uuid', '=', $payoutUuid)->first();
        self::assertNotNull($payoutRow);
        self::assertSame('pending', $payoutRow['status']);
        self::assertSame(
            1,
            $connectionA->table('commerce_marketplace_ledger')
                ->where('payout_uuid', '=', $payoutUuid)
                ->where('entry_type', '=', 'reserve_hold')
                ->count()
        );

        // The already-committed payout is in-flight (design spec §2.7) -- it
        // continues to reconcile to completion regardless of the seller's now-
        // suspended status; reconcile() never checks it.
        $collector = new SuspensionRacePayoutCollector(
            new PayoutStatusResult(PayoutStatusResult::PAID, 0, 'prov-sspg-pofs-1')
        );
        $service = new PayoutService($payouts, $ledger, new LedgerAccountLock(), new SellerBalanceService($ledger), $sellers, null, $collector);
        $reconciled = $service->reconcile($contextA, $tenant, $payoutRow);
        self::assertSame('paid', $reconciled['status']);
        self::assertSame(
            1,
            $connectionA->table('commerce_marketplace_ledger')
                ->where('payout_uuid', '=', $payoutUuid)
                ->where('entry_type', '=', 'payout_debit')
                ->count(),
            'the in-flight payout must still finalize to a real debit despite the seller now being suspended'
        );

        $this->cleanupTenant($connectionA, $tenant);
    }

    /**
     * Ordering (b): the SUSPENSION commits first. Connection A manually
     * replicates `SellerService::suspend()`'s claim-then-update, and commits
     * FIRST. Connection B (subprocess, the REAL `PayoutService::record()`)
     * attempts a manual payout for the just-suspended seller -- its own
     * seller-revision claim blocks entirely on A's held claim; once
     * unblocked, its re-read observes `suspended` and REFUSES -- no payout
     * row, no hold, no account lock ever claimed.
     */
    public function testSuspendCommitsFirstThenConcurrentPayoutRecordRefusesWithNoPayoutRowOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'sspgsupf001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'sspg-supf-sel1', 'ownerSUPF001');
        $this->seedAvailable($contextA, $tenant, $seller['uuid'], 5000);

        $sellers = new SellerRepository();

        // Connection A mirrors SellerService::suspend()'s claim-then-update
        // (active -> suspended, no live-products guard), and commits FIRST.
        $connectionA->getTransactionManager()->begin();
        self::assertTrue($sellers->claimRevision($contextA, $tenant, $seller['uuid']));
        $sellers->update($contextA, $tenant, $seller['uuid'], ['status' => 'suspended']);

        // Connection B (subprocess, the REAL PayoutService::record()) attempts a
        // manual payout for the just-suspended seller -- its own seller-revision
        // claim blocks entirely on A's held claim.
        $handle = $this->launchRaceChild($pgConfig, 'payoutRecord', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'currency' => 'USD',
            'amount' => 1000,
            'idempotencyKey' => 'idem-sspg-supf-1',
            'externalRef' => 'ext-sspg-supf-1',
            'actorUuid' => 'operatorSSP1',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'a payout against a just-suspended seller must be refused');
        self::assertSame(PayoutException::class, $result['exceptionClass'] ?? null);
        self::assertStringContainsString('not active', (string) ($result['message'] ?? ''));

        // NEVER a payout created for a seller after suspension commits.
        self::assertSame(
            0,
            $connectionA->table('commerce_payouts')->where('tenant_uuid', '=', $tenant)->count(),
            'no payout row may exist once the reservation was refused'
        );
        self::assertSame(
            0,
            $connectionA->table('commerce_marketplace_ledger')->where('tenant_uuid', '=', $tenant)
                ->where('entry_type', '=', 'reserve_hold')->count(),
            'no hold may ever post once the reservation was refused'
        );

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 3. Migration-shape live (design spec §3): commerce_seller_lifecycle_events
    //    columns/unique/index via pg_indexes, re-run 017 no-op.
    // =====================================================================

    public function testSellerLifecycleEventsTableConvergesWithColumnsUniqueAndIndexViaPgIndexesAndRerunning017IsANoOpOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();

        self::assertTrue(
            $schema->hasTable('commerce_seller_lifecycle_events'),
            'missing commerce_seller_lifecycle_events on PostgreSQL'
        );
        foreach (
            ['id', 'uuid', 'tenant_uuid', 'seller_uuid', 'from_status', 'to_status',
                'actor_uuid', 'reason', 'created_at'] as $column
        ) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_lifecycle_events', $column),
                "commerce_seller_lifecycle_events missing column {$column} on PostgreSQL"
            );
        }

        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_lifecycle_events',
            'commerce_seller_lifecycle_events_tenant_uuid_unique',
            ['tenant_uuid', 'uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_lifecycle_events',
            'commerce_seller_lifecycle_events_seller_created_index',
            ['tenant_uuid', 'seller_uuid', 'created_at']
        );

        // migratedConnection() already ran every migration (including 017) once;
        // re-running up() again must be a no-op guarded by hasTable().
        (new CreateSellerLifecycleEventsTable())->up($schema);
        (new CreateSellerLifecycleEventsTable())->up($schema);

        self::assertTrue($schema->hasTable('commerce_seller_lifecycle_events'));
        self::assertTrue($schema->hasColumn('commerce_seller_lifecycle_events', 'actor_uuid'));
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

    private function seedAvailable(ApplicationContext $context, string $tenant, string $sellerUuid, int $amount): void
    {
        (new LedgerRepository())->post($context, $tenant, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => $amount,
            'order_uuid' => 'seed' . substr(md5($sellerUuid), 0, 8),
            'idempotency_key' => 'seed:' . $sellerUuid . ':sale_credit',
        ]);
    }

    /**
     * Manually replicates the ownership-claim + write phase of a partitioned
     * checkout for exactly ONE product/seller/line, matching
     * `CheckoutService::claimMarketplaceOwnership()` (claim the seller, then
     * the product) followed by the order/order-line/seller-order writes it
     * makes afterward -- run directly against $connectionA so the caller can
     * hold the transaction open (uncommitted) to force a genuine concurrent
     * block, then commit at a moment of the caller's own choosing. Mirrors
     * `CheckoutClaimPgsqlTest::manuallyPlaceMinimalPartitionedOrder()`
     * exactly. Every column not exercised by the race itself
     * (subtotal/currency/etc.) is a fixed, deterministic placeholder.
     */
    private function manuallyPlaceMinimalPartitionedOrder(
        Connection $connectionA,
        ApplicationContext $contextA,
        string $tenant,
        string $sellerUuid,
        string $sellerName,
        string $productUuid,
        string $orderUuid,
        string $orderNumber
    ): void {
        $sellers = new SellerRepository();
        $products = new ProductRepository();

        self::assertTrue($sellers->claimRevision($contextA, $tenant, $sellerUuid));
        self::assertTrue($products->claimCatalogRevision($contextA, $tenant, $productUuid));

        (new OrderRepository())->insert($contextA, [
            'uuid' => $orderUuid,
            'tenant_uuid' => $tenant,
            'order_number' => $orderNumber,
            'status' => 'pending_payment',
            'email' => 'racebuyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'placed_at' => $connectionA->getDriver()->formatDateTime(),
            'marketplace_partitioned' => true,
        ], [[
            'line_uuid' => 'l' . substr(md5($orderUuid), 0, 11),
            'variant_uuid' => 'v' . substr(md5($orderUuid), 0, 11),
            'product_name' => 'Race Prod',
            'sku' => 'RACE-SKU-1',
            'option_values' => [],
            'unit_price' => 1000,
            'quantity' => 1,
            'seller_uuid' => $sellerUuid,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]]);

        (new SellerOrderRepository())->insertForOrder($contextA, $tenant, [[
            'order_uuid' => $orderUuid,
            'order_number' => $orderNumber,
            'seller_uuid' => $sellerUuid,
            'seller_name_snapshot' => $sellerName,
            'currency' => 'USD',
            'subtotal' => 1000,
            'allocated_discount' => 0,
            'allocated_shipping_discount' => 0,
            'allocated_shipping' => 0,
            'allocated_tax' => 0,
            'attributed_total' => 1000,
            'tax_attribution_method' => 'aggregate_allocated',
        ]]);
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
        $orderUuids = array_column(
            $connection->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->get(),
            'uuid'
        );
        foreach ($orderUuids as $orderUuid) {
            $connection->table('commerce_order_lines')->where('order_uuid', '=', (string) $orderUuid)->delete();
        }
        $connection->table('commerce_seller_orders')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->delete();

        $productUuids = array_column(
            $connection->table('commerce_products')->where('tenant_uuid', '=', $tenant)->get(),
            'uuid'
        );
        foreach ($productUuids as $uuid) {
            $connection->table('commerce_variants')->where('product_uuid', '=', (string) $uuid)->delete();
        }
        $connection->table('commerce_products')->where('tenant_uuid', '=', $tenant)->forceDelete();

        $connection->table('commerce_marketplace_ledger')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_ledger_account_locks')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_payouts')->where('tenant_uuid', '=', $tenant)->delete();

        $connection->table('commerce_seller_lifecycle_events')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_seller_memberships')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_sellers')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_marketplace_settings')->where('tenant_uuid', '=', $tenant)->delete();
    }

    /**
     * `pg_indexes.indexdef` looks like `CREATE INDEX name ON public.table
     * USING btree (col_a, col_b)` (or `CREATE UNIQUE INDEX ...` for a named
     * unique constraint) -- the column list (in order) is the content of the
     * LAST parenthesized group. Mirrors `SellerLifecycleShapeTest`'s helper
     * exactly.
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

/**
 * Scripted fake payout collector for connection A's own in-process
 * `reconcile()` call, run AFTER the race itself has already resolved --
 * distinct name from every other fake `PayoutCollector` in this namespace
 * (`PgsqlRacePayoutCollector`, `FakePayoutCollector`,
 * `RetryReconcileFakeCollector`, `ReadinessFakeCollector`,
 * `BatchFakeCollector`, `ReversalFakeCollector`, `SurfaceFakePayoutCollector`,
 * `SuspendedPayoutFakeCollector`, `DebtGateFakeCollector`) so every test file
 * in this suite can load in the same PHPUnit process without a class-name
 * collision. Single-shot: scripts at most one `status()` call.
 */
final class SuspensionRacePayoutCollector implements PayoutCollector
{
    public int $transferCalls = 0;
    public int $statusCalls = 0;

    public function __construct(private readonly ?PayoutStatusResult $statusResult = null)
    {
    }

    public function transfer(
        ApplicationContext $context,
        PayoutDestination $destination,
        PayoutRequest $request
    ): \Glueful\Extensions\Contracts\Payments\PayoutResult {
        $this->transferCalls++;

        throw new \RuntimeException('SuspensionRacePayoutCollector: transfer() not scripted.');
    }

    public function status(
        ApplicationContext $context,
        PayoutDestination $destination,
        string $idempotencyKey
    ): PayoutStatusResult {
        $this->statusCalls++;

        return $this->statusResult ?? throw new \RuntimeException('SuspensionRacePayoutCollector: status() not scripted.');
    }

    public function inspectDestination(
        ApplicationContext $context,
        PayoutDestination $destination
    ): DestinationStatus {
        throw new \LogicException('SuspensionRacePayoutCollector::inspectDestination() is not exercised by this suite.');
    }
}
