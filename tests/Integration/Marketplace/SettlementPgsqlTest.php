<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentService;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerPostingService;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL race lanes for Marketplace MV3's settlement ledger (design
 * spec §2.5-§2.10; plan Task 12, GROUP E). Every case here requires TRUE
 * two-connection row-lock interleaving that SQLite -- a single-process,
 * single-connection engine in this test harness -- cannot exercise at all
 * (PHP has no threads, so a genuine race needs a genuinely separate OS
 * process/connection). Gating, fixture-width discipline (every `uuid`/
 * `tenant_uuid`/`account_key` literal here is 32 characters or fewer, and
 * every `uuid`/`tenant_uuid` specifically 12 or fewer -- `varchar(12)`,
 * strictly enforced by PostgreSQL but silently ignored by SQLite), and the
 * throwaway `Connection`/`ApplicationContext` construction all mirror
 * `MarketplacePgsqlTest`/`CheckoutClaimPgsqlTest` exactly. Every subprocess
 * race follows the SAME pattern those files establish: connection A (this
 * test) either manually replicates the losing/blocked side's pre-commit
 * steps directly via the repositories, OR -- since every settlement
 * primitive here (`LedgerAccountLock::claim()`, `PayoutService::record()`,
 * `AdjustmentService::post()`, `LedgerPostingService::postSale()/
 * postRefund()`) already wraps its own writes in `db($context)->transaction()`,
 * which NESTS as a savepoint rather than committing when called from inside
 * an already-open outer transaction -- simply calls the REAL service method
 * directly on connection A after an explicit `getTransactionManager()->begin()`,
 * holding it open (uncommitted) rather than manually reimplementing its
 * internals. Connection B is always launched as a genuinely separate
 * subprocess (`fixtures/settlement_race_child.php`, a single multiplexed
 * script mirroring `fixtures/marketplace_race_child.php`'s shape) running
 * the real service call, which blocks on A's held account-lock claim until A
 * commits or rolls back.
 *
 * None of these lanes need a real `commerce_sellers`/workspace-activation
 * row: every settlement primitive under test here (`LedgerAccountLock`,
 * `LedgerRepository`, `PayoutService`, `AdjustmentService`,
 * `LedgerPostingService`) is keyed purely by string `seller_uuid`/
 * `account_key` -- none of them join against `commerce_sellers` or
 * `commerce_marketplace_settings` (design spec §2.5-§2.10). Only the
 * `markPaid()` lane needs real `commerce_orders`/`commerce_seller_orders`
 * rows, seeded directly (mirroring
 * `CheckoutClaimPgsqlTest::manuallyPlaceMinimalPartitionedOrder()`) rather
 * than via a full checkout, since `OrderPaymentService::markPaid()` never
 * reads `commerce_sellers` either.
 */
final class SettlementPgsqlTest extends CommerceTestCase
{
    // =====================================================================
    // 1. Concurrent markPaid() postings to ONE seller account (design spec
    //    §2.6/§2.7): two DIFFERENT orders for the SAME seller, marked paid
    //    concurrently -- the account lock serializes postSale()'s claim so
    //    BOTH postings land distinctly, never a lost update / double-post.
    // =====================================================================

    public function testConcurrentMarkPaidPostingsToOneSellerAccountSerializeAndBothLandOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'stlmpay0001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = 'stlmpayslr1';
        $order1 = 'stlmpayord1';
        $order2 = 'stlmpayord2';

        $this->manuallyPlaceMinimalPendingOrder(
            $connectionA,
            $contextA,
            $tenant,
            $seller,
            'Race Seller',
            $order1,
            'STLMPAY-1',
            1000,
            100
        );
        $this->manuallyPlaceMinimalPendingOrder(
            $connectionA,
            $contextA,
            $tenant,
            $seller,
            'Race Seller',
            $order2,
            'STLMPAY-2',
            2000,
            200
        );

        // Connection A: the REAL markPaid(), held open by an explicit outer
        // transaction (postSale()'s own internal `db()->transaction()` calls
        // nest as savepoints, so nothing here actually commits until this
        // outer transaction does).
        $connectionA->getTransactionManager()->begin();
        $this->paymentService()->markPaid($contextA, $tenant, $order1);

        $handle = $this->launchRaceChild($pgConfig, 'markPaid', ['tenant' => $tenant, 'orderUuid' => $order2]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'the second concurrent markPaid() must succeed: ' . json_encode($result, JSON_THROW_ON_ERROR)
        );

        $orderRow1 = $connectionA->table('commerce_orders')->where('uuid', '=', $order1)->first();
        $orderRow2 = $connectionA->table('commerce_orders')->where('uuid', '=', $order2)->first();
        self::assertSame('paid', $orderRow1['status']);
        self::assertSame('paid', $orderRow2['status']);

        $ledgerRows = $connectionA->table('commerce_marketplace_ledger')
            ->where('tenant_uuid', '=', $tenant)
            ->where('account_key', '=', LedgerRepository::accountKeyForSeller($seller))
            ->get();
        self::assertCount(4, $ledgerRows, 'no lost posting -- both orders x (sale_credit + commission_debit)');

        $byOrderAndType = [];
        foreach ($ledgerRows as $row) {
            $byOrderAndType[$row['order_uuid'] . ':' . $row['entry_type']] = (int) $row['amount'];
        }
        self::assertSame(1000, $byOrderAndType[$order1 . ':sale_credit'] ?? null);
        self::assertSame(-100, $byOrderAndType[$order1 . ':commission_debit'] ?? null);
        self::assertSame(2000, $byOrderAndType[$order2 . ':sale_credit'] ?? null);
        self::assertSame(-200, $byOrderAndType[$order2 . ':commission_debit'] ?? null);

        $lock = $connectionA->table('commerce_ledger_account_locks')
            ->where('tenant_uuid', '=', $tenant)
            ->where('account_key', '=', LedgerRepository::accountKeyForSeller($seller))
            ->first();
        self::assertNotNull($lock);
        self::assertGreaterThanOrEqual(2, (int) $lock['revision'], 'both claims must have genuinely landed');

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 2. Payout-vs-refund on ONE account -- THE key money race (design spec
    //    §2.6/§2.10): the account lock must serialize a payout against a
    //    concurrent refund_debit posting so the payout can never overdraw.
    //    Both orderings.
    // =====================================================================

    /**
     * Ordering (a): the REFUND commits first. The concurrent payout's own
     * account-lock claim blocks entirely on the refund's held claim; once
     * unblocked, its available-balance recheck (under the SAME lock)
     * observes the ALREADY-reduced balance and correctly refuses -- the
     * payout never drew against a stale (pre-refund) balance.
     */
    public function testPayoutIsRefusedWhenARefundDebitCommitsFirstOnTheSameAccountOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'stl2arf0001';
        $order = 'stl2arford1';
        $this->cleanupTenant($connectionA, $tenant, [$order]);

        $seller = 'stl2arfslr1';
        $refund = 'stl2arfrfd1';
        $line = 'stl2arfln01';

        $this->seedAvailable($contextA, $tenant, $seller, 5000);
        $this->insertOrderLine($connectionA, $order, $line, $seller, 3000, 0);

        // Connection A: the REAL postRefund(), held open.
        $connectionA->getTransactionManager()->begin();
        $this->ledgerPostingService()->postRefund(
            $contextA,
            $tenant,
            ['uuid' => $order],
            ['uuid' => $refund, 'amount' => 3000, 'currency' => 'USD'],
            [['order_line_uuid' => $line, 'quantity' => 1, 'amount' => 3000]]
        );

        $handle = $this->launchRaceChild($pgConfig, 'payout', [
            'tenant' => $tenant,
            'sellerUuid' => $seller,
            'currency' => 'USD',
            'amount' => 5000,
            'idempotencyKey' => 'idem-lane2a-1',
            'externalRef' => 'ext-lane2a-1',
            'actorUuid' => 'stlpgoperat1',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse(
            $result['ok'] ?? true,
            'a payout for the PRE-refund balance must be refused once a concurrent refund_debit has committed'
        );
        self::assertSame(PayoutException::class, $result['exceptionClass'] ?? null);

        self::assertSame(0, $connectionA->table('commerce_payouts')->where('tenant_uuid', '=', $tenant)->count());
        self::assertSame(
            2000,
            $this->balances()->available($contextA, $tenant, $seller, 'USD'),
            'no overdraw: available reflects exactly the refund debit, untouched by the refused payout'
        );

        $this->cleanupTenant($connectionA, $tenant, [$order]);
    }

    /**
     * Ordering (b): the PAYOUT commits first, evaluated against the correct
     * (pre-refund) balance -- it succeeds. The concurrent refund's own
     * account-lock claim blocks entirely on the payout's held claim; once
     * unblocked, the refund still posts (refunds are never gated on
     * available balance, design spec §2.8) -- the final balance is exactly
     * the arithmetic sum of both postings, never a lost update, and the
     * payout's own decision was correct at the moment it was made (nothing
     * had posted concurrently while it held the lock).
     */
    public function testPayoutSucceedsAgainstThePreRefundBalanceWhenItCommitsFirstOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'stl2bpo0001';
        $order = 'stl2bpoord1';
        $this->cleanupTenant($connectionA, $tenant, [$order]);

        $seller = 'stl2bposlr1';
        $refund = 'stl2bporfd1';
        $line = 'stl2bpoln01';

        $this->seedAvailable($contextA, $tenant, $seller, 5000);
        $this->insertOrderLine($connectionA, $order, $line, $seller, 3000, 0);

        // Connection A: the REAL PayoutService::record(), held open.
        $connectionA->getTransactionManager()->begin();
        $payout = $this->payoutService()->record(
            $contextA,
            $tenant,
            $seller,
            'USD',
            5000,
            'idem-lane2b-1',
            'ext-lane2b-1',
            null,
            'stlpgoperat1'
        );

        $handle = $this->launchRaceChild($pgConfig, 'postRefund', [
            'tenant' => $tenant,
            'orderUuid' => $order,
            'refundUuid' => $refund,
            'currency' => 'USD',
            'amount' => 3000,
            'lines' => [['order_line_uuid' => $line, 'quantity' => 1, 'amount' => 3000]],
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'the refund must still post after the payout has committed: ' . json_encode($result, JSON_THROW_ON_ERROR)
        );

        self::assertSame(5000, (int) $payout['amount']);
        self::assertSame(
            1,
            $connectionA->table('commerce_payouts')->where('uuid', '=', $payout['uuid'])->count()
        );
        self::assertSame(
            5000 - 5000 - 3000,
            $this->balances()->available($contextA, $tenant, $seller, 'USD'),
            'final balance is exactly the arithmetic sum of both postings -- no lost update, no double-post'
        );

        $this->cleanupTenant($connectionA, $tenant, [$order]);
    }

    // =====================================================================
    // 3. Double-refund-completion idempotency: replaying the SAME postRefund()
    //    call on the real DB leaves exactly ONE posting set (refund_debit +
    //    commission_reversal), never a duplicate.
    // =====================================================================

    public function testDoubleRefundCompletionIsIdempotentAndPostsExactlyOnePostingSetOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connection = $this->migratedConnection($pgConfig);
        $context = $this->pgsqlContext($connection);
        $tenant = 'stl3idm0001';
        $order = 'stl3idmord1';
        $this->cleanupTenant($connection, $tenant, [$order]);

        $seller = 'stl3idmslr1';
        $refund = 'stl3idmrfd1';
        $line = 'stl3idmln01';

        $this->insertOrderLine($connection, $order, $line, $seller, 3000, 300);

        $ledgerPosting = $this->ledgerPostingService();
        $refundRow = ['uuid' => $refund, 'amount' => 3000, 'currency' => 'USD'];
        $lines = [['order_line_uuid' => $line, 'quantity' => 1, 'amount' => 3000]];

        $ledgerPosting->postRefund($context, $tenant, ['uuid' => $order], $refundRow, $lines);
        $ledgerPosting->postRefund($context, $tenant, ['uuid' => $order], $refundRow, $lines);

        $rows = $connection->table('commerce_marketplace_ledger')
            ->where('tenant_uuid', '=', $tenant)
            ->where('refund_uuid', '=', $refund)
            ->get();

        self::assertCount(2, $rows, 'exactly one posting set (refund_debit + commission_reversal), no duplicate');

        $byType = [];
        foreach ($rows as $row) {
            $byType[$row['entry_type']] = (int) $row['amount'];
        }
        self::assertSame(-3000, $byType['refund_debit'] ?? null);
        self::assertSame(300, $byType['commission_reversal'] ?? null);

        $this->cleanupTenant($connection, $tenant, [$order]);
    }

    // =====================================================================
    // 4. Concurrent adjustments to ONE account: the lock serializes; BOTH
    //    land; final balance is the exact signed sum.
    // =====================================================================

    public function testConcurrentAdjustmentsToOneAccountSerializeAndBothLandOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'stl4adj0001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = 'stl4adjslr1';
        $accountKey = LedgerRepository::accountKeyForSeller($seller);

        // Connection A: the REAL AdjustmentService::post(), held open.
        $connectionA->getTransactionManager()->begin();
        $this->adjustmentService()->post(
            $contextA,
            $tenant,
            $accountKey,
            'USD',
            1000,
            'lane-4-reason-a',
            'idem-lane4-a',
            'stlpgoperat1'
        );

        $handle = $this->launchRaceChild($pgConfig, 'adjustment', [
            'tenant' => $tenant,
            'accountKey' => $accountKey,
            'currency' => 'USD',
            'signedAmount' => -400,
            'reason' => 'lane-4-reason-b',
            'idempotencyKey' => 'idem-lane4-b',
            'actorUuid' => 'stlpgoperat1',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'the second concurrent adjustment must succeed: ' . json_encode($result, JSON_THROW_ON_ERROR)
        );

        $rows = $connectionA->table('commerce_marketplace_ledger')
            ->where('tenant_uuid', '=', $tenant)
            ->where('account_key', '=', $accountKey)
            ->where('entry_type', '=', 'adjustment')
            ->get();
        self::assertCount(2, $rows, 'both adjustments must land -- no lost update');

        self::assertSame(
            600,
            $this->balances()->balance($contextA, $tenant, $seller, 'USD')['adjustments'],
            'final adjustments component is the exact signed sum'
        );

        $lock = $connectionA->table('commerce_ledger_account_locks')
            ->where('tenant_uuid', '=', $tenant)
            ->where('account_key', '=', $accountKey)
            ->first();
        self::assertGreaterThanOrEqual(2, (int) $lock['revision']);

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 5. Two concurrent FIRST postings claiming the SAME (fresh) account
    //    lock -- the savepoint race (design spec §2.6): exactly ONE anchor
    //    row, both claims land (revision reaches 2), mirroring
    //    `MarketplacePgsqlTest::testVerifiedDuplicateSettingsRowConflictRollsBackOnlyTheSavepointOnRealPostgres()`
    //    for `LedgerAccountLock` specifically, with a genuine second
    //    connection this time.
    // =====================================================================

    public function testTwoConcurrentFirstClaimsOnTheSameAccountProduceExactlyOneLockRowOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'stl5lck0001';
        $this->cleanupTenant($connectionA, $tenant);

        $accountKey = LedgerRepository::accountKeyForSeller('stl5lckslr1');

        self::assertSame(
            0,
            $connectionA->table('commerce_ledger_account_locks')
                ->where('tenant_uuid', '=', $tenant)
                ->where('account_key', '=', $accountKey)
                ->count(),
            'sanity: no lock row exists yet for this account'
        );

        // Connection A: the REAL LedgerAccountLock::claim(), held open --
        // its ensureRow() insert lands via a savepoint nested inside this
        // outer transaction, so the row stays uncommitted (and thus a
        // genuine unique-index contention target for B) until commit below.
        $connectionA->getTransactionManager()->begin();
        (new LedgerAccountLock())->claim($contextA, $tenant, $accountKey, 'USD');

        $handle = $this->launchRaceChild($pgConfig, 'ledgerLockClaim', [
            'tenant' => $tenant,
            'accountKey' => $accountKey,
            'currency' => 'USD',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'the second concurrent first-claim must succeed: ' . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame(
            2,
            $result['revision'] ?? null,
            'both claims landed -- revision reflects exactly 2 successful bumps'
        );

        self::assertSame(
            1,
            $connectionA->table('commerce_ledger_account_locks')
                ->where('tenant_uuid', '=', $tenant)
                ->where('account_key', '=', $accountKey)
                ->count(),
            'exactly ONE anchor row must exist -- no duplicate from the concurrent first-insert race'
        );

        $this->cleanupTenant($connectionA, $tenant);
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

    private function ledgerPostingService(): LedgerPostingService
    {
        return new LedgerPostingService(new LedgerRepository(), new LedgerAccountLock());
    }

    private function paymentService(): OrderPaymentService
    {
        return new OrderPaymentService(
            new OrderRepository(),
            new SellerOrderPaymentConfirmation(),
            null,
            new SellerOrderRepository(),
            $this->ledgerPostingService()
        );
    }

    private function payoutService(): PayoutService
    {
        return new PayoutService(
            new PayoutRepository(),
            new LedgerRepository(),
            new LedgerAccountLock(),
            $this->balances()
        );
    }

    private function adjustmentService(): AdjustmentService
    {
        return new AdjustmentService(new LedgerRepository(), new LedgerAccountLock());
    }

    private function balances(): SellerBalanceService
    {
        return new SellerBalanceService(new LedgerRepository());
    }

    private function seedAvailable(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        int $amount,
        string $currency = 'USD'
    ): void {
        (new LedgerRepository())->post($context, $tenant, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'entry_type' => 'sale_credit',
            'amount' => $amount,
            'order_uuid' => 'seed' . substr(md5($sellerUuid), 0, 8),
            'idempotency_key' => 'seed:' . $sellerUuid . ':sale_credit',
        ]);
    }

    private function insertOrderLine(
        Connection $connection,
        string $orderUuid,
        string $lineUuid,
        string $sellerUuid,
        int $commissionBasis,
        int $commissionAmount
    ): void {
        $connection->table('commerce_order_lines')->insert([
            'uuid' => $lineUuid,
            'order_uuid' => $orderUuid,
            'variant_uuid' => 'v' . substr(md5($lineUuid), 0, 11),
            'product_name' => 'Settlement Race Line',
            'sku' => 'STL-RACE-LN',
            'option_values' => '{}',
            'unit_price' => $commissionBasis,
            'quantity' => 1,
            'line_total' => $commissionBasis,
            'seller_uuid' => $sellerUuid,
            'commission_basis' => $commissionBasis,
            'commission_amount' => $commissionAmount,
        ]);
    }

    /**
     * Mirrors `CheckoutClaimPgsqlTest::manuallyPlaceMinimalPartitionedOrder()`,
     * minus the ownership-claim phase (irrelevant to `markPaid()`, which
     * never reads `commerce_sellers`/`commerce_products`) -- just the
     * `commerce_orders` + `commerce_seller_orders` rows `markPaid()`/
     * `postSale()` actually read, in `pending_payment`.
     */
    private function manuallyPlaceMinimalPendingOrder(
        Connection $connection,
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $sellerName,
        string $orderUuid,
        string $orderNumber,
        int $attributedTotal,
        int $commissionAmount
    ): void {
        (new OrderRepository())->insert($context, [
            'uuid' => $orderUuid,
            'tenant_uuid' => $tenant,
            'order_number' => $orderNumber,
            'status' => 'pending_payment',
            'email' => 'racebuyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => $attributedTotal,
            'grand_total' => $attributedTotal,
            'placed_at' => $connection->getDriver()->formatDateTime(),
            'marketplace_partitioned' => true,
        ], [[
            'line_uuid' => 'l' . substr(md5($orderUuid), 0, 11),
            'variant_uuid' => 'v' . substr(md5($orderUuid), 0, 11),
            'product_name' => 'Settlement Race Prod',
            'sku' => 'STL-RACE-1',
            'option_values' => [],
            'unit_price' => $attributedTotal,
            'quantity' => 1,
            'seller_uuid' => $sellerUuid,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]]);

        (new SellerOrderRepository())->insertForOrder($context, $tenant, [[
            'order_uuid' => $orderUuid,
            'order_number' => $orderNumber,
            'seller_uuid' => $sellerUuid,
            'seller_name_snapshot' => $sellerName,
            'currency' => 'USD',
            'subtotal' => $attributedTotal,
            'allocated_discount' => 0,
            'allocated_shipping_discount' => 0,
            'allocated_shipping' => 0,
            'allocated_tax' => 0,
            'attributed_total' => $attributedTotal,
            'tax_attribution_method' => 'aggregate_allocated',
            'commission_amount' => $commissionAmount,
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
                __DIR__ . '/fixtures/settlement_race_child.php',
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
     * @param list<string> $extraOrderUuids order uuids used ONLY for a
     *     directly-inserted `commerce_order_lines` row (lanes 2a/2b/3, via
     *     {@see insertOrderLine()}) that never got a matching `commerce_orders`
     *     parent row -- `commerce_order_lines` carries no `tenant_uuid` column
     *     of its own, so these cannot be discovered via the tenant-scoped
     *     `commerce_orders` lookup below and must be named explicitly.
     */
    private function cleanupTenant(Connection $connection, string $tenant, array $extraOrderUuids = []): void
    {
        $orderUuids = array_column(
            $connection->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->get(),
            'uuid'
        );
        foreach ([...$orderUuids, ...$extraOrderUuids] as $orderUuid) {
            $connection->table('commerce_order_lines')->where('order_uuid', '=', (string) $orderUuid)->delete();
        }
        $connection->table('commerce_seller_orders')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_marketplace_ledger')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_ledger_account_locks')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_payouts')->where('tenant_uuid', '=', $tenant)->delete();
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
