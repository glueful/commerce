<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerException;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * `PayoutService::record()` (design spec §2.10, MV3 Task 9): the atomic
 * manual operator payout -- claim the seller account lock, RECHECK
 * {@see SellerBalanceService::available()} under it, refuse an over-available
 * amount, then insert the `commerce_payouts` row and its `payout_debit`
 * ledger entry as ONE transaction. A duplicate idempotency key is a VERIFY
 * (both the payout row and its ledger entry), never a second row.
 */
final class PayoutTest extends CommerceTestCase
{
    private const TENANT = '';
    private const SELLER = 'sellerPAYOUT01';
    private const ACTOR = 'operatorPAYOU1';

    private LedgerRepository $ledger;
    private PayoutRepository $payouts;
    private SellerBalanceService $balances;
    private PayoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new LedgerRepository();
        $this->payouts = new PayoutRepository();
        $this->balances = new SellerBalanceService($this->ledger);
        $this->service = $this->makeService();
    }

    private function makeService(?callable $uuidGenerator = null): PayoutService
    {
        return new PayoutService(
            $this->payouts,
            $this->ledger,
            new LedgerAccountLock(),
            $this->balances,
            new SellerRepository(),
            $uuidGenerator
        );
    }

    private function seedAvailable(string $seller, int $amount, string $currency = 'USD'): void
    {
        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($seller),
            'seller_uuid' => $seller,
            'currency' => $currency,
            'entry_type' => 'sale_credit',
            'amount' => $amount,
            'order_uuid' => 'orderPAYOUTSD1',
            'idempotency_key' => 'orderPAYOUTSD1:' . $seller . ':sale_credit',
        ]);
    }

    // -----------------------------------------------------------------
    // 1. Happy path: row + debit in ONE transaction; available reduced.
    // -----------------------------------------------------------------

    public function testHappyPathRecordsThePayoutRowAndTheLedgerDebitInOneTransactionAndReducesAvailable(): void
    {
        $this->seedAvailable(self::SELLER, 5000);

        $payout = $this->service->record(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            3000,
            'idem-payout-1',
            'ext-ref-1',
            'first payout',
            self::ACTOR
        );

        self::assertSame(self::SELLER, $payout['seller_uuid']);
        self::assertSame(3000, (int) $payout['amount']);
        self::assertSame('ext-ref-1', $payout['external_ref']);
        self::assertSame('first payout', $payout['note']);
        self::assertSame(self::ACTOR, $payout['created_by']);

        self::assertSame(
            1,
            $this->connection->table('commerce_payouts')
                ->where('tenant_uuid', '=', self::TENANT)
                ->where('seller_uuid', '=', self::SELLER)
                ->count()
        );

        $ledgerRow = $this->connection->table('commerce_marketplace_ledger')
            ->where('payout_uuid', '=', $payout['uuid'])
            ->first();
        self::assertNotNull($ledgerRow);
        self::assertSame('payout_debit', $ledgerRow['entry_type']);
        self::assertSame(-3000, (int) $ledgerRow['amount']);
        self::assertSame(self::ACTOR, $ledgerRow['created_by']);

        self::assertSame(2000, $this->balances->available($this->context, self::TENANT, self::SELLER, 'USD'));
    }

    // -----------------------------------------------------------------
    // 2. Refuse over-available: 422, no payout row, no ledger entry.
    // -----------------------------------------------------------------

    public function testRefusesAPayoutThatExceedsAvailableBalance(): void
    {
        $this->seedAvailable(self::SELLER, 1000);

        try {
            $this->service->record(
                $this->context,
                self::TENANT,
                self::SELLER,
                'USD',
                2000,
                'idem-payout-2',
                'ext-ref-2',
                null,
                self::ACTOR
            );
            self::fail('Expected PayoutException for a payout exceeding available balance.');
        } catch (PayoutException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(0, $this->connection->table('commerce_payouts')->count());
        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('entry_type', '=', 'payout_debit')->count()
        );
    }

    // -----------------------------------------------------------------
    // 3. Refuse zero/negative amount, empty external_ref, empty actor.
    // -----------------------------------------------------------------

    public function testRejectsAZeroAmount(): void
    {
        $this->seedAvailable(self::SELLER, 1000);
        $this->expectException(PayoutException::class);
        $this->service->record(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            0,
            'idem-zero',
            'ext-zero',
            null,
            self::ACTOR
        );
    }

    public function testRejectsANegativeAmount(): void
    {
        $this->seedAvailable(self::SELLER, 1000);
        $this->expectException(PayoutException::class);
        $this->service->record(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            -100,
            'idem-neg',
            'ext-neg',
            null,
            self::ACTOR
        );
    }

    public function testRejectsAnEmptyExternalReference(): void
    {
        $this->seedAvailable(self::SELLER, 1000);
        $this->expectException(PayoutException::class);
        $this->service->record(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            500,
            'idem-ref',
            '',
            null,
            self::ACTOR
        );
    }

    public function testRejectsAnEmptyActor(): void
    {
        $this->seedAvailable(self::SELLER, 1000);
        $this->expectException(PayoutException::class);
        $this->service->record(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            500,
            'idem-actor',
            'ext-actor',
            null,
            ''
        );
    }

    public function testValidationFailuresWriteNothing(): void
    {
        $this->seedAvailable(self::SELLER, 1000);

        foreach ([
            [0, 'ext', self::ACTOR],
            [500, '', self::ACTOR],
            [500, 'ext', ''],
        ] as $i => [$amount, $ref, $actor]) {
            try {
                $this->service->record(
                    $this->context,
                    self::TENANT,
                    self::SELLER,
                    'USD',
                    $amount,
                    'idem-novalid-' . $i,
                    $ref,
                    null,
                    $actor
                );
                self::fail('Expected a PayoutException.');
            } catch (PayoutException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame(0, $this->connection->table('commerce_payouts')->count());
    }

    // -----------------------------------------------------------------
    // 4. Atomic: forced failure after the payouts insert rolls back BOTH.
    // -----------------------------------------------------------------

    public function testForcedLedgerFailureAfterThePayoutRowInsertRollsBackBothTheRowAndTheLedgerPost(): void
    {
        $this->seedAvailable(self::SELLER, 5000);

        $forcedUuid = 'payoutFORCED1';

        // Pre-seed a MISMATCHED ledger row under the EXACT idempotency key the forced
        // payout uuid will compute for its own payout_debit -- forces
        // LedgerRepository::post()'s verify() to throw mid-transaction, AFTER the
        // commerce_payouts row has already been inserted (proving the row rolls back too,
        // not just the ledger post). Amount is -1 (NOT -3000, the amount the real posting
        // below will compute) so the mismatch fires at the ledger-post verify, not
        // earlier at the available-balance recheck -- it only shaves 1 off the 5000
        // seeded available, well clear of the 3000 requested.
        $this->connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => 'ledgerFORCED1',
            'tenant_uuid' => self::TENANT,
            'account_key' => LedgerRepository::accountKeyForSeller(self::SELLER),
            'account_kind' => 'seller',
            'seller_uuid' => self::SELLER,
            'currency' => 'USD',
            'entry_type' => 'payout_debit',
            'amount' => -1,
            'payout_uuid' => $forcedUuid,
            'idempotency_key' => $forcedUuid . ':' . self::SELLER . ':payout_debit',
        ]);

        $service = $this->makeService(static fn (): string => $forcedUuid);

        try {
            $service->record(
                $this->context,
                self::TENANT,
                self::SELLER,
                'USD',
                3000,
                'idem-forced',
                'ext-forced',
                null,
                self::ACTOR
            );
            self::fail('Expected the mismatched ledger replay to throw and roll back the whole transaction.');
        } catch (LedgerException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(
            0,
            $this->connection->table('commerce_payouts')->count(),
            'the payout row must roll back together with the failed ledger post -- no orphan'
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('payout_uuid', '=', $forcedUuid)->count(),
            'only the one pre-seeded row may remain -- no legitimate posting persisted'
        );
        self::assertSame(
            4999,
            $this->balances->available($this->context, self::TENANT, self::SELLER, 'USD'),
            'available balance reflects only the pre-seeded row -- the failed attempt wrote nothing'
        );
    }

    // -----------------------------------------------------------------
    // 5. Idempotent duplicate.
    // -----------------------------------------------------------------

    public function testDuplicateIdempotencyKeyWithTheSameRequestReturnsTheExistingPayoutWithoutADuplicate(): void
    {
        $this->seedAvailable(self::SELLER, 5000);

        $first = $this->service->record(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            1000,
            'idem-dup-1',
            'ext-dup-1',
            'note-a',
            self::ACTOR
        );

        $second = $this->service->record(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            1000,
            'idem-dup-1',
            'ext-dup-1',
            'note-a',
            self::ACTOR
        );

        self::assertSame($first['uuid'], $second['uuid']);
        self::assertSame(1, $this->connection->table('commerce_payouts')->count());
        self::assertSame(
            1,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('entry_type', '=', 'payout_debit')->count()
        );
        self::assertSame(4000, $this->balances->available($this->context, self::TENANT, self::SELLER, 'USD'));
    }

    public function testDuplicateIdempotencyKeyWithADifferentAmountIsAnIntegrityFailure(): void
    {
        $this->seedAvailable(self::SELLER, 5000);

        $this->service->record(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            1000,
            'idem-dup-2',
            'ext-dup-2',
            null,
            self::ACTOR
        );

        $this->expectException(LedgerException::class);
        $this->service->record(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            1500,
            'idem-dup-2',
            'ext-dup-2',
            null,
            self::ACTOR
        );
    }

    public function testDuplicateReplayWherePayoutRowMatchesButItsLedgerEntryWasCorruptedIsAnIntegrityFailure(): void
    {
        $this->seedAvailable(self::SELLER, 5000);

        $payout = $this->service->record(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            1000,
            'idem-corrupt-1',
            'ext-corrupt-1',
            null,
            self::ACTOR
        );

        // Simulate an otherwise-impossible integrity drift: the payout row is untouched
        // (so it will still match a replay of the ORIGINAL request) but its ledger entry
        // amount has been corrupted directly.
        $this->connection->table('commerce_marketplace_ledger')
            ->where('payout_uuid', '=', $payout['uuid'])
            ->update(['amount' => -1]);

        $this->expectException(LedgerException::class);
        $this->service->record(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            1000,
            'idem-corrupt-1',
            'ext-corrupt-1',
            null,
            self::ACTOR
        );
    }

    // -----------------------------------------------------------------
    // 6. The available recheck happens UNDER the account lock.
    // -----------------------------------------------------------------

    public function testTheAccountLockIsClaimedWithinThePayoutTransaction(): void
    {
        $this->seedAvailable(self::SELLER, 5000);

        self::assertSame(
            0,
            $this->connection->table('commerce_ledger_account_locks')
                ->where('account_key', '=', LedgerRepository::accountKeyForSeller(self::SELLER))
                ->count(),
            'sanity: no lock row exists before any payout'
        );

        $this->service->record(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            1000,
            'idem-lock-1',
            'ext-lock-1',
            null,
            self::ACTOR
        );

        $lock = $this->connection->table('commerce_ledger_account_locks')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('account_key', '=', LedgerRepository::accountKeyForSeller(self::SELLER))
            ->where('currency', '=', 'USD')
            ->first();

        self::assertNotNull($lock, 'the account lock must be claimed inside the payout transaction');
        self::assertGreaterThanOrEqual(1, (int) $lock['revision']);
    }
}
