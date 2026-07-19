<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * `SellerBalanceService` (design spec §2.9, MV3 Task 8): the derived,
 * currency-separated seller/marketplace balance + component set over the
 * append-only ledger. Never a stored balance -- every assertion here re-reads
 * straight through to {@see LedgerRepository::balanceComponents()} (or its
 * own minimal currency-enumeration query), so this suite is really proving
 * the SERVICE's account-key scoping and currency separation, not re-deriving
 * the sign-formula math already proven in {@see LedgerRepositoryTest}.
 */
final class BalanceTest extends CommerceTestCase
{
    private const TENANT = '';
    private const SELLER = 'sellerBALSVC1';
    private const OTHER_SELLER = 'sellerBALSVC2';

    private LedgerRepository $ledger;
    private SellerBalanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new LedgerRepository();
        $this->service = new SellerBalanceService($this->ledger);
    }

    // -----------------------------------------------------------------
    // Exact component values across a realistic mix of every entry type.
    // -----------------------------------------------------------------

    public function testBalanceReturnsExactComponentsAcrossARealisticEntryMix(): void
    {
        $entries = [
            ['entry_type' => 'sale_credit', 'amount' => 5000],
            ['entry_type' => 'commission_debit', 'amount' => -500],
            ['entry_type' => 'refund_debit', 'amount' => -1000],
            ['entry_type' => 'commission_reversal', 'amount' => 100],
            ['entry_type' => 'adjustment', 'amount' => 50, 'suffix' => 'adj1'],
            ['entry_type' => 'adjustment', 'amount' => -30, 'suffix' => 'adj2'],
            ['entry_type' => 'reserve_hold', 'amount' => -200],
            ['entry_type' => 'reserve_release', 'amount' => 50],
            ['entry_type' => 'payout_debit', 'amount' => -300],
            ['entry_type' => 'payout_reversal', 'amount' => 100],
        ];

        $this->postAll(self::SELLER, 'orderBAL00001', $entries);

        $balance = $this->service->balance($this->context, self::TENANT, self::SELLER, 'USD');

        // available = raw signed SUM over every entry posted above.
        $expectedAvailable = 5000 - 500 - 1000 + 100 + 50 - 30 - 200 + 50 - 300 + 100;
        self::assertSame(3270, $expectedAvailable, 'sanity: hand-computed expectation');

        self::assertSame([
            'available' => 3270,
            'pending' => 0,
            'reserved' => 150,
            'paid_out' => 200,
            'gross_sales' => 5000,
            'commission' => 500,
            'refunds' => 1000,
            'commission_reversed' => 100,
            'adjustments' => 20,
            'debt' => 0,
        ], $balance);
    }

    public function testAvailableConvenienceReturnsOnlyTheAvailableComponent(): void
    {
        $this->postAll(self::SELLER, 'orderBAL00002', [
            ['entry_type' => 'sale_credit', 'amount' => 4000],
            ['entry_type' => 'commission_debit', 'amount' => -400],
        ]);

        self::assertSame(
            3600,
            $this->service->available($this->context, self::TENANT, self::SELLER, 'USD')
        );
    }

    // -----------------------------------------------------------------
    // Currency separation: independent balances per currency.
    // -----------------------------------------------------------------

    public function testBalanceIsScopedByCurrencyIndependently(): void
    {
        $this->postAll(self::SELLER, 'orderBAL00003', [
            ['entry_type' => 'sale_credit', 'amount' => 4000, 'currency' => 'USD'],
        ]);
        $this->postAll(self::SELLER, 'orderBAL00004', [
            ['entry_type' => 'sale_credit', 'amount' => 900, 'currency' => 'EUR'],
        ]);

        $usd = $this->service->balance($this->context, self::TENANT, self::SELLER, 'USD');
        $eur = $this->service->balance($this->context, self::TENANT, self::SELLER, 'EUR');

        self::assertSame(4000, $usd['available']);
        self::assertSame(900, $eur['available']);
    }

    public function testCurrenciesReturnsEveryDistinctCurrencyTheSellerHasEntriesIn(): void
    {
        $this->postAll(self::SELLER, 'orderBAL00005', [
            ['entry_type' => 'sale_credit', 'amount' => 1000, 'currency' => 'USD'],
        ]);
        $this->postAll(self::SELLER, 'orderBAL00006', [
            ['entry_type' => 'sale_credit', 'amount' => 500, 'currency' => 'EUR'],
        ]);

        $currencies = $this->service->currencies($this->context, self::TENANT, self::SELLER);

        sort($currencies);
        self::assertSame(['EUR', 'USD'], $currencies);
    }

    public function testCurrenciesForASellerWithNoEntriesIsEmpty(): void
    {
        self::assertSame(
            [],
            $this->service->currencies($this->context, self::TENANT, 'sellerNOENTRIES')
        );
    }

    // -----------------------------------------------------------------
    // Negative balance via a large adjustment -- reported faithfully, no clamp.
    // -----------------------------------------------------------------

    public function testANegativeBalanceFromALargeAdjustmentIsReportedWithoutClamping(): void
    {
        $this->postAll(self::SELLER, 'orderBAL00007', [
            ['entry_type' => 'sale_credit', 'amount' => 5000],
            ['entry_type' => 'adjustment', 'amount' => -10000, 'suffix' => 'bignegadj'],
        ]);

        $balance = $this->service->balance($this->context, self::TENANT, self::SELLER, 'USD');

        self::assertSame(-5000, $balance['available']);
        self::assertSame(5000, $balance['debt'], 'debt = max(0, -available)');
        self::assertSame(
            -5000,
            $this->service->available($this->context, self::TENANT, self::SELLER, 'USD')
        );
    }

    // -----------------------------------------------------------------
    // MV5a §2.6/§2.9: `debt` is the derived, surfaced magnitude of a
    // negative `available` -- never a separate mutable balance, never
    // clamping `available` itself. `debt = max(0, -available)`.
    // -----------------------------------------------------------------

    /**
     * A chargeback that exceeds the seller's sale proceeds drives `available`
     * negative (design spec §2.6: "post the remaining debit in FULL, allowing
     * `available` to go negative"). `debt` surfaces the exact positive
     * magnitude while `available` itself stays negative, unclamped.
     */
    public function testDebtSurfacesTheExactMagnitudeOfANegativeAvailableFromAChargeback(): void
    {
        $this->postAll(self::SELLER, 'orderBAL00011', [
            ['entry_type' => 'sale_credit', 'amount' => 2000],
            ['entry_type' => 'chargeback_debit', 'amount' => -3500, 'suffix' => 'cb1'],
        ]);

        $balance = $this->service->balance($this->context, self::TENANT, self::SELLER, 'USD');

        self::assertSame(-1500, $balance['available'], 'available is NOT clamped -- stays negative');
        self::assertSame(1500, $balance['debt'], 'debt == -available (positive magnitude)');
    }

    /**
     * Same derivation via a large `refund_debit` that exceeds credits
     * (design spec §2.6 example) -- `debt` is not tied to any one entry_type,
     * it is purely `max(0, -available)` over the whole account.
     */
    public function testDebtSurfacesTheExactMagnitudeOfANegativeAvailableFromAnOversizedRefund(): void
    {
        $this->postAll(self::SELLER, 'orderBAL00012', [
            ['entry_type' => 'sale_credit', 'amount' => 1000],
            ['entry_type' => 'refund_debit', 'amount' => -4000, 'suffix' => 'rf1'],
        ]);

        $balance = $this->service->balance($this->context, self::TENANT, self::SELLER, 'USD');

        self::assertSame(-3000, $balance['available']);
        self::assertSame(3000, $balance['debt']);
    }

    /**
     * A solvent seller (`available >= 0`) always has `debt == 0` -- both the
     * exact zero-crossing boundary and a comfortably positive balance.
     */
    public function testDebtIsZeroForASolventSellerIncludingExactlyZeroAvailable(): void
    {
        $this->postAll(self::SELLER, 'orderBAL00013', [
            ['entry_type' => 'sale_credit', 'amount' => 4200],
        ]);
        $solvent = $this->service->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(4200, $solvent['available']);
        self::assertSame(0, $solvent['debt']);

        $this->postAll(self::OTHER_SELLER, 'orderBAL00014', [
            ['entry_type' => 'sale_credit', 'amount' => 900],
            ['entry_type' => 'refund_debit', 'amount' => -900, 'suffix' => 'rf1'],
        ]);
        $zero = $this->service->balance($this->context, self::TENANT, self::OTHER_SELLER, 'USD');
        self::assertSame(0, $zero['available'], 'sanity: exactly zero, the debt/solvent boundary');
        self::assertSame(0, $zero['debt']);
    }

    /**
     * Additive-only guardrail (design spec §2.9): adding `debt` must never
     * disturb the pre-existing `available`/`pending`/`reserved`/`paid_out`
     * semantics for a seller who is simultaneously in debt AND has payout
     * holds/reserves/paid_out history -- every prior key keeps its exact
     * pre-MV5a value alongside the new `debt` key.
     */
    public function testDebtIsAdditiveAndLeavesEveryExistingBalanceKeyUnchanged(): void
    {
        $accountKey = LedgerRepository::accountKeyForSeller(self::SELLER);

        $this->postAll(self::SELLER, 'orderBAL00015', [
            ['entry_type' => 'sale_credit', 'amount' => 1000],
            ['entry_type' => 'commission_debit', 'amount' => -100],
            ['entry_type' => 'chargeback_debit', 'amount' => -2000, 'suffix' => 'cb1'],
            ['entry_type' => 'payout_debit', 'amount' => -50],
        ]);
        $this->ledger->post($this->context, self::TENANT, $this->reserveEntry(
            $accountKey,
            amount: -75,
            payoutUuid: null,
            idempotencyKey: 'riskBAL00099:' . self::SELLER . ':reserve_hold'
        ));

        $balance = $this->service->balance($this->context, self::TENANT, self::SELLER, 'USD');

        $expectedAvailable = 1000 - 100 - 2000 - 50 - 75;
        self::assertSame(-1225, $expectedAvailable, 'sanity: hand-computed expectation');

        self::assertSame($expectedAvailable, $balance['available'], 'available unchanged by the debt addition');
        self::assertSame(0, $balance['pending'], 'pending unchanged');
        self::assertSame(75, $balance['reserved'], 'reserved unchanged');
        self::assertSame(50, $balance['paid_out'], 'paid_out unchanged');
        self::assertSame(1000, $balance['gross_sales'], 'gross_sales unchanged');
        self::assertSame(100, $balance['commission'], 'commission unchanged');
        self::assertSame(1225, $balance['debt'], 'debt == -available, the new additive key');
    }

    // -----------------------------------------------------------------
    // MV4 §2.4: `pending` (payout-referenced holds) vs `reserved` (MV5 risk
    // holds) -- the two are disambiguated purely by whether the
    // `reserve_hold`/`reserve_release` entry carries a `payout_uuid`.
    // -----------------------------------------------------------------

    public function testPendingReflectsOnlyPayoutReferencedHoldsAndReservedOnlyNonPayoutHolds(): void
    {
        $accountKey = LedgerRepository::accountKeyForSeller(self::SELLER);

        $this->ledger->post($this->context, self::TENANT, $this->reserveEntry(
            $accountKey,
            amount: -400,
            payoutUuid: 'payoutBAL0001',
            idempotencyKey: 'payoutBAL0001:' . self::SELLER . ':reserve_hold'
        ));
        $this->ledger->post($this->context, self::TENANT, $this->reserveEntry(
            $accountKey,
            amount: -250,
            payoutUuid: null,
            idempotencyKey: 'riskBAL00001:' . self::SELLER . ':reserve_hold'
        ));

        $balance = $this->service->balance($this->context, self::TENANT, self::SELLER, 'USD');

        self::assertSame(400, $balance['pending'], 'the payout-referenced hold shows as pending');
        self::assertSame(250, $balance['reserved'], 'the non-payout hold shows as reserved (MV5 risk reserve)');
        self::assertSame(-650, $balance['available'], 'available sums every signed entry -- split is unchanged');
    }

    public function testAPayoutHoldThenItsReleaseNetsPendingToZeroWithoutTouchingReserved(): void
    {
        $accountKey = LedgerRepository::accountKeyForSeller(self::SELLER);

        $this->ledger->post($this->context, self::TENANT, $this->reserveEntry(
            $accountKey,
            amount: -500,
            payoutUuid: 'payoutBAL0002',
            idempotencyKey: 'payoutBAL0002:' . self::SELLER . ':reserve_hold',
            entryType: 'reserve_hold'
        ));
        $this->ledger->post($this->context, self::TENANT, $this->reserveEntry(
            $accountKey,
            amount: 500,
            payoutUuid: 'payoutBAL0002',
            idempotencyKey: 'payoutBAL0002:' . self::SELLER . ':reserve_release',
            entryType: 'reserve_release'
        ));
        $this->ledger->post($this->context, self::TENANT, $this->reserveEntry(
            $accountKey,
            amount: -75,
            payoutUuid: null,
            idempotencyKey: 'riskBAL00002:' . self::SELLER . ':reserve_hold'
        ));

        $balance = $this->service->balance($this->context, self::TENANT, self::SELLER, 'USD');

        self::assertSame(0, $balance['pending'], 'a released payout hold nets pending back to 0');
        self::assertSame(75, $balance['reserved'], 'the unrelated risk hold is untouched by the payout release');
        self::assertSame(-75, $balance['available']);
    }

    public function testSellerBalanceServiceBalanceSurfacesPending(): void
    {
        $this->postAll(self::SELLER, 'orderBAL00099', [
            ['entry_type' => 'sale_credit', 'amount' => 1000],
        ]);

        $balance = $this->service->balance($this->context, self::TENANT, self::SELLER, 'USD');

        self::assertArrayHasKey('pending', $balance);
        self::assertSame(0, $balance['pending']);
    }

    // -----------------------------------------------------------------
    // Marketplace account balance is independent of any seller account.
    // -----------------------------------------------------------------

    public function testMarketplaceBalanceIsIndependentOfAnySellerAccount(): void
    {
        $this->postAll(self::SELLER, 'orderBAL00008', [
            ['entry_type' => 'sale_credit', 'amount' => 3000],
        ]);

        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'marketplace',
            'account_key' => LedgerRepository::MARKETPLACE_ACCOUNT_KEY,
            'seller_uuid' => null,
            'currency' => 'USD',
            'entry_type' => 'refund_debit',
            'amount' => -150,
            'refund_uuid' => 'refundBAL0001',
            'idempotency_key' => 'refundBAL0001:' . LedgerRepository::MARKETPLACE_ACCOUNT_KEY . ':refund_debit',
        ]);

        $marketplace = $this->service->marketplaceBalance($this->context, self::TENANT, 'USD');
        $seller = $this->service->balance($this->context, self::TENANT, self::SELLER, 'USD');

        self::assertSame(-150, $marketplace['available']);
        self::assertSame(3000, $seller['available']);
        self::assertSame(150, $marketplace['refunds']);
    }

    // -----------------------------------------------------------------
    // A seller with no entries -- all-zero components.
    // -----------------------------------------------------------------

    public function testASellerWithNoEntriesHasAllZeroComponents(): void
    {
        $balance = $this->service->balance($this->context, self::TENANT, 'sellerEMPTY001', 'USD');

        self::assertSame([
            'available' => 0,
            'pending' => 0,
            'reserved' => 0,
            'paid_out' => 0,
            'gross_sales' => 0,
            'commission' => 0,
            'refunds' => 0,
            'commission_reversed' => 0,
            'adjustments' => 0,
            'debt' => 0,
        ], $balance);

        self::assertSame(0, $this->service->available($this->context, self::TENANT, 'sellerEMPTY001', 'USD'));
    }

    // -----------------------------------------------------------------
    // Two sellers are never conflated.
    // -----------------------------------------------------------------

    public function testTwoSellersHaveCompletelyIndependentBalances(): void
    {
        $this->postAll(self::SELLER, 'orderBAL00009', [
            ['entry_type' => 'sale_credit', 'amount' => 1000],
        ]);
        $this->postAll(self::OTHER_SELLER, 'orderBAL00010', [
            ['entry_type' => 'sale_credit', 'amount' => 7000],
        ]);

        self::assertSame(
            1000,
            $this->service->available($this->context, self::TENANT, self::SELLER, 'USD')
        );
        self::assertSame(
            7000,
            $this->service->available($this->context, self::TENANT, self::OTHER_SELLER, 'USD')
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @param list<array<string,mixed>> $entries */
    private function postAll(string $seller, string $orderUuid, array $entries): void
    {
        $accountKey = LedgerRepository::accountKeyForSeller($seller);

        foreach ($entries as $i => $entry) {
            $suffix = $entry['suffix'] ?? (string) $i;
            unset($entry['suffix']);

            $this->ledger->post($this->context, self::TENANT, array_merge([
                'account_kind' => 'seller',
                'account_key' => $accountKey,
                'seller_uuid' => $seller,
                'currency' => 'USD',
                'order_uuid' => $orderUuid,
                'seller_order_uuid' => 'sel' . $orderUuid,
                'refund_uuid' => null,
                'payout_uuid' => null,
                'reason' => null,
                'created_by' => null,
                'idempotency_key' => $orderUuid . ':' . $seller . ':' . $entry['entry_type'] . ':' . $suffix,
            ], $entry));
        }
    }

    /**
     * A `reserve_hold`/`reserve_release` entry, optionally carrying a
     * `payout_uuid` -- the MV4 §2.4 signal that disambiguates `pending`
     * (payout-referenced) from `reserved` (MV5 risk-reserve) holds.
     *
     * @return array<string,mixed>
     */
    private function reserveEntry(
        string $accountKey,
        int $amount,
        ?string $payoutUuid,
        string $idempotencyKey,
        string $entryType = 'reserve_hold'
    ): array {
        return [
            'account_kind' => 'seller',
            'account_key' => $accountKey,
            'seller_uuid' => self::SELLER,
            'currency' => 'USD',
            'entry_type' => $entryType,
            'amount' => $amount,
            'payout_uuid' => $payoutUuid,
            'idempotency_key' => $idempotencyKey,
        ];
    }
}
