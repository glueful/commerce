<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerException;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * The settlement-ledger core (design spec §2.5/§2.6/§2.9, MV3 Task 5): the
 * append-only per-account ledger with full-field idempotency VERIFY,
 * balance-component sign formulas, reconciliation scans, and the
 * `(tenant_uuid, account_key, currency)` account-posting lock every
 * balance-affecting posting (Tasks 6-10) claims before writing.
 *
 * MV5a (design spec §2.5/§2.9/§3.4, Task 4) expands the idempotency VERIFY
 * from 12 to 14 immutable fields with `reserve_uuid`/`chargeback_uuid` --
 * {@see self::testVerifiedFieldsAllowlistCoversExactlyFourteenImmutableFields()}
 * and the two new {@see self::mismatchedFieldProvider()} cases below.
 */
final class LedgerRepositoryTest extends CommerceTestCase
{
    private const TENANT = '';
    private const SELLER = 'sellerLEDGR01';

    private LedgerRepository $ledger;
    private LedgerAccountLock $lock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new LedgerRepository();
        $this->lock = new LedgerAccountLock();
    }

    // -----------------------------------------------------------------
    // Append-only insert: signed amounts round-trip.
    // -----------------------------------------------------------------

    public function testAppendOnlyInsertRoundTripsSignedAmountsForSaleAndCommission(): void
    {
        $accountKey = LedgerRepository::accountKeyForSeller(self::SELLER);

        $this->ledger->post($this->context, self::TENANT, $this->baseEntry([
            'entry_type' => 'sale_credit',
            'amount' => 5000,
            'idempotency_key' => 'order0000001:' . self::SELLER . ':sale_credit',
        ]));
        $this->ledger->post($this->context, self::TENANT, $this->baseEntry([
            'entry_type' => 'commission_debit',
            'amount' => -500,
            'idempotency_key' => 'order0000001:' . self::SELLER . ':commission_debit',
        ]));

        $rows = $this->connection()->table('commerce_marketplace_ledger')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('account_key', '=', $accountKey)
            ->orderBy('id', 'ASC')
            ->get();

        self::assertCount(2, $rows);

        self::assertSame('sale_credit', $rows[0]['entry_type']);
        self::assertSame(5000, (int) $rows[0]['amount']);
        self::assertSame('seller', $rows[0]['account_kind']);
        self::assertSame(self::SELLER, $rows[0]['seller_uuid']);
        self::assertNotNull($rows[0]['uuid']);

        self::assertSame('commission_debit', $rows[1]['entry_type']);
        self::assertSame(-500, (int) $rows[1]['amount']);
    }

    /**
     * §2.5 account-identity invariant enforced in service code (the only defense
     * on MySQL, where the migration CHECK is omitted) at the single post()
     * chokepoint every ledger entry flows through.
     *
     * @dataProvider inconsistentAccountIdentities
     * @param array<string,mixed> $overrides
     */
    public function testPostRejectsInconsistentAccountIdentity(array $overrides): void
    {
        $this->expectException(LedgerException::class);
        $this->ledger->post($this->context, self::TENANT, $this->baseEntry($overrides));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function inconsistentAccountIdentities(): array
    {
        return [
            'seller kind, null seller_uuid' => [['seller_uuid' => null, 'account_key' => 'marketplace']],
            'seller kind, key not matching seller' => [['account_key' => 'seller:someoneelse1']],
            'marketplace kind but seller_uuid set' => [[
                'account_kind' => 'marketplace', 'account_key' => 'marketplace',
            ]],
            'marketplace kind, seller-shaped key' => [[
                'account_kind' => 'marketplace', 'seller_uuid' => null,
                'account_key' => LedgerRepository::accountKeyForSeller(self::SELLER),
            ]],
            'unknown account_kind' => [['account_kind' => 'house']],
        ];
    }

    // -----------------------------------------------------------------
    // Deterministic idempotency: same key + same fields => one row, no-op.
    // -----------------------------------------------------------------

    public function testPostingTheSameEntryTwiceIsAnIdempotentNoOp(): void
    {
        $entry = $this->baseEntry([
            'idempotency_key' => 'order0000002:' . self::SELLER . ':sale_credit',
        ]);

        $this->ledger->post($this->context, self::TENANT, $entry);
        $this->ledger->post($this->context, self::TENANT, $entry);

        self::assertSame(
            1,
            $this->connection()->table('commerce_marketplace_ledger')
                ->where('tenant_uuid', '=', self::TENANT)
                ->where('idempotency_key', '=', $entry['idempotency_key'])
                ->count(),
            'a byte-identical replay must never create a second row'
        );
    }

    // -----------------------------------------------------------------
    // Duplicate key + mismatched field => LedgerException, no second row,
    // original row untouched.
    // -----------------------------------------------------------------

    /** @dataProvider mismatchedFieldProvider */
    public function testDuplicateIdempotencyKeyWithAMismatchedFieldThrowsLedgerException(
        array $overrides
    ): void {
        $key = 'order0000003:' . self::SELLER . ':sale_credit';
        $original = $this->baseEntry(['idempotency_key' => $key]);

        $this->ledger->post($this->context, self::TENANT, $original);

        try {
            $this->ledger->post($this->context, self::TENANT, $this->baseEntry(
                ['idempotency_key' => $key] + $overrides
            ));
            self::fail('a mismatched replay must throw LedgerException, never silently succeed');
        } catch (LedgerException) {
            $this->addToAssertionCount(1);
        }

        $rows = $this->connection()->table('commerce_marketplace_ledger')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('idempotency_key', '=', $key)
            ->get();

        self::assertCount(1, $rows, 'the mismatched replay must never produce a second row');
        self::assertSame((int) $original['amount'], (int) $rows[0]['amount'], 'the original row is untouched');
    }

    /** @return array<string, array{array<string,mixed>}> */
    public static function mismatchedFieldProvider(): array
    {
        return [
            'amount' => [['amount' => 9999]],
            'currency' => [['currency' => 'EUR']],
            'entry_type' => [['entry_type' => 'adjustment']],
            'account_kind+key+seller' => [[
                'account_kind' => 'marketplace',
                'account_key' => LedgerRepository::MARKETPLACE_ACCOUNT_KEY,
                'seller_uuid' => null,
            ]],
            'order_uuid' => [['order_uuid' => 'orderDIFFER01']],
            'seller_order_uuid' => [['seller_order_uuid' => 'selordDIFFER1']],
            'refund_uuid' => [['refund_uuid' => 'refundDIFFR01']],
            'payout_uuid' => [['payout_uuid' => 'payoutDIFFR01']],
            'reserve_uuid' => [['reserve_uuid' => 'reserveDIFFR1']],
            'chargeback_uuid' => [['chargeback_uuid' => 'chargebckDF1']],
            'reason' => [['reason' => 'a different reason']],
            'created_by' => [['created_by' => 'operatorDIFF1']],
        ];
    }

    /**
     * Locks the MV5a expansion (design spec §2.5/§2.9/§3.4): a correlation-id
     * mismatch on replay is an integrity failure like any other immutable
     * field, and the allowlist itself is exactly 14 fields -- neither a
     * silent no-op regression (a field dropped back out) nor an accidental
     * narrowing.
     */
    public function testVerifiedFieldsAllowlistCoversExactlyFourteenImmutableFields(): void
    {
        $fields = (new \ReflectionClass(LedgerRepository::class))->getConstant('VERIFIED_FIELDS');

        self::assertCount(14, $fields);
        self::assertContains('reserve_uuid', $fields);
        self::assertContains('chargeback_uuid', $fields);
    }

    // -----------------------------------------------------------------
    // MV5a: reserve_uuid/chargeback_uuid round-trip and replay like every
    // other correlation column (design spec §2.5/§2.9/§3.4).
    // -----------------------------------------------------------------

    public function testReserveUuidRoundTripsAndReplaysIdempotentlyWhenSet(): void
    {
        $entry = $this->baseEntry([
            'entry_type' => 'reserve_hold',
            'amount' => -800,
            'reserve_uuid' => 'reserveuuid01',
            'idempotency_key' => 'orderRSV00001:' . self::SELLER . ':reserve_hold',
        ]);

        $this->ledger->post($this->context, self::TENANT, $entry);
        // Exact replay -- idempotent no-op, never a second row.
        $this->ledger->post($this->context, self::TENANT, $entry);

        $rows = $this->connection()->table('commerce_marketplace_ledger')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('idempotency_key', '=', $entry['idempotency_key'])
            ->get();

        self::assertCount(1, $rows);
        self::assertSame('reserveuuid01', $rows[0]['reserve_uuid']);
        self::assertNull($rows[0]['chargeback_uuid']);
    }

    public function testChargebackUuidRoundTripsAndReplaysIdempotentlyWhenSet(): void
    {
        $entry = $this->baseEntry([
            'entry_type' => 'chargeback_debit',
            'amount' => -400,
            'order_uuid' => null,
            'seller_order_uuid' => null,
            'chargeback_uuid' => 'chargebckuu01',
            'idempotency_key' => 'chargebackCB1:' . self::SELLER . ':chargeback_debit',
        ]);

        $this->ledger->post($this->context, self::TENANT, $entry);
        // Exact replay -- idempotent no-op, never a second row.
        $this->ledger->post($this->context, self::TENANT, $entry);

        $rows = $this->connection()->table('commerce_marketplace_ledger')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('idempotency_key', '=', $entry['idempotency_key'])
            ->get();

        self::assertCount(1, $rows);
        self::assertSame('chargebckuu01', $rows[0]['chargeback_uuid']);
        self::assertNull($rows[0]['reserve_uuid']);
    }

    // -----------------------------------------------------------------
    // balanceComponents(): the §2.9 exact sign formulas, every entry_type.
    // -----------------------------------------------------------------

    public function testBalanceComponentsAcrossEveryEntryTypeMatchTheSignFormulas(): void
    {
        $accountKey = LedgerRepository::accountKeyForSeller(self::SELLER);
        $orderUuid = 'orderBALANCE1';

        $entries = [
            ['entry_type' => 'sale_credit', 'amount' => 10000],
            ['entry_type' => 'commission_debit', 'amount' => -1000],
            ['entry_type' => 'refund_debit', 'amount' => -2000],
            ['entry_type' => 'commission_reversal', 'amount' => 200],
            ['entry_type' => 'adjustment', 'amount' => 50, 'idempotency_key' => 'adjustment:' . $accountKey . ':req1'],
            ['entry_type' => 'adjustment', 'amount' => -20, 'idempotency_key' => 'adjustment:' . $accountKey . ':req2'],
            ['entry_type' => 'reserve_hold', 'amount' => -500],
            ['entry_type' => 'reserve_release', 'amount' => 300],
            ['entry_type' => 'payout_debit', 'amount' => -700],
            ['entry_type' => 'payout_reversal', 'amount' => 100],
        ];

        foreach ($entries as $i => $entry) {
            $this->ledger->post($this->context, self::TENANT, $this->baseEntry(array_merge(
                ['idempotency_key' => $orderUuid . ':' . self::SELLER . ':' . $entry['entry_type'] . ':' . $i],
                $entry
            )));
        }

        $components = $this->ledger->balanceComponents($this->context, self::TENANT, $accountKey, 'USD');

        // available = raw signed SUM over every entry posted above.
        $expectedAvailable = 10000 - 1000 - 2000 + 200 + 50 - 20 - 500 + 300 - 700 + 100;
        self::assertSame(6430, $expectedAvailable, 'sanity: hand-computed expectation');

        self::assertSame($expectedAvailable, $components['available']);
        self::assertSame(200, $components['reserved']);
        self::assertSame(600, $components['paid_out']);
        self::assertSame(10000, $components['gross_sales']);
        self::assertSame(1000, $components['commission']);
        self::assertSame(2000, $components['refunds']);
        self::assertSame(200, $components['commission_reversed']);
        self::assertSame(30, $components['adjustments']);
    }

    public function testBalanceComponentsAreScopedByCurrencyIndependently(): void
    {
        $accountKey = LedgerRepository::accountKeyForSeller(self::SELLER);

        $this->ledger->post($this->context, self::TENANT, $this->baseEntry([
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => 4000,
            'idempotency_key' => 'orderCUR00001:' . self::SELLER . ':sale_credit',
        ]));
        $this->ledger->post($this->context, self::TENANT, $this->baseEntry([
            'currency' => 'EUR',
            'entry_type' => 'sale_credit',
            'amount' => 900,
            'idempotency_key' => 'orderCUR00002:' . self::SELLER . ':sale_credit',
        ]));

        $usd = $this->ledger->balanceComponents($this->context, self::TENANT, $accountKey, 'USD');
        $eur = $this->ledger->balanceComponents($this->context, self::TENANT, $accountKey, 'EUR');

        self::assertSame(4000, $usd['available']);
        self::assertSame(900, $eur['available']);
    }

    public function testBalanceComponentsForAnAccountWithNoEntriesAreAllZero(): void
    {
        $components = $this->ledger->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller('sellerNOTHING'),
            'USD'
        );

        self::assertSame(
            [
                'available' => 0, 'pending' => 0, 'reserved' => 0, 'paid_out' => 0, 'gross_sales' => 0,
                'commission' => 0, 'refunds' => 0, 'commission_reversed' => 0, 'adjustments' => 0, 'debt' => 0,
            ],
            $components
        );
    }

    // -----------------------------------------------------------------
    // Marketplace account: distinct key, seller_uuid null, posts + balances
    // independently of any seller account.
    // -----------------------------------------------------------------

    public function testMarketplaceAccountPostsAndBalancesIndependentlyOfSellerAccounts(): void
    {
        $this->ledger->post($this->context, self::TENANT, $this->baseEntry([
            'entry_type' => 'sale_credit',
            'amount' => 3000,
            'idempotency_key' => 'orderMKT00001:' . self::SELLER . ':sale_credit',
        ]));

        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'marketplace',
            'account_key' => LedgerRepository::MARKETPLACE_ACCOUNT_KEY,
            'seller_uuid' => null,
            'currency' => 'USD',
            'entry_type' => 'refund_debit',
            'amount' => -150,
            'refund_uuid' => 'refundMKT0001',
            'idempotency_key' => 'refundMKT0001:' . LedgerRepository::MARKETPLACE_ACCOUNT_KEY . ':refund_debit',
        ]);

        $row = $this->connection()->table('commerce_marketplace_ledger')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('account_key', '=', LedgerRepository::MARKETPLACE_ACCOUNT_KEY)
            ->first();

        self::assertNotNull($row);
        self::assertSame('marketplace', $row['account_kind']);
        self::assertNull($row['seller_uuid']);
        self::assertSame(-150, (int) $row['amount']);

        $marketplaceComponents = $this->ledger->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::MARKETPLACE_ACCOUNT_KEY,
            'USD'
        );
        $sellerComponents = $this->ledger->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller(self::SELLER),
            'USD'
        );

        self::assertSame(-150, $marketplaceComponents['available']);
        self::assertSame(3000, $sellerComponents['available']);
    }

    // -----------------------------------------------------------------
    // Reconciliation scans (design spec §2.11).
    // -----------------------------------------------------------------

    public function testEntriesForOrderRefundAndPayoutReturnOnlyTheirOwnRowsOldestFirst(): void
    {
        $this->ledger->post($this->context, self::TENANT, $this->baseEntry([
            'entry_type' => 'sale_credit',
            'amount' => 1000,
            'order_uuid' => 'orderSCAN0001',
            'idempotency_key' => 'orderSCAN0001:' . self::SELLER . ':sale_credit',
        ]));
        $this->ledger->post($this->context, self::TENANT, $this->baseEntry([
            'entry_type' => 'commission_debit',
            'amount' => -100,
            'order_uuid' => 'orderSCAN0001',
            'idempotency_key' => 'orderSCAN0001:' . self::SELLER . ':commission_debit',
        ]));
        $this->ledger->post($this->context, self::TENANT, $this->baseEntry([
            'entry_type' => 'refund_debit',
            'amount' => -400,
            'order_uuid' => null,
            'refund_uuid' => 'refundSCAN001',
            'idempotency_key' => 'refundSCAN001:' . self::SELLER . ':refund_debit',
        ]));
        $this->ledger->post($this->context, self::TENANT, $this->baseEntry([
            'entry_type' => 'payout_debit',
            'amount' => -600,
            'order_uuid' => null,
            'payout_uuid' => 'payoutSCAN001',
            'idempotency_key' => 'payoutSCAN001:' . self::SELLER . ':payout_debit',
        ]));

        $orderEntries = $this->ledger->entriesForOrder($this->context, self::TENANT, 'orderSCAN0001');
        self::assertCount(2, $orderEntries);
        self::assertSame('sale_credit', $orderEntries[0]['entry_type']);
        self::assertSame('commission_debit', $orderEntries[1]['entry_type']);

        $refundEntries = $this->ledger->entriesForRefund($this->context, self::TENANT, 'refundSCAN001');
        self::assertCount(1, $refundEntries);
        self::assertSame('refund_debit', $refundEntries[0]['entry_type']);

        $payoutEntries = $this->ledger->entriesForPayout($this->context, self::TENANT, 'payoutSCAN001');
        self::assertCount(1, $payoutEntries);
        self::assertSame('payout_debit', $payoutEntries[0]['entry_type']);
    }

    // -----------------------------------------------------------------
    // LedgerAccountLock: savepoint-guarded lazy create + revision bump.
    // -----------------------------------------------------------------

    public function testAccountLockLazilyCreatesTheRowAndBumpsRevisionOnEachClaim(): void
    {
        $accountKey = LedgerRepository::accountKeyForSeller('sellerLOCK001');

        $this->connection()->transaction(function () use ($accountKey): void {
            $this->lock->claim($this->context, self::TENANT, $accountKey, 'USD');
        });
        $first = $this->lockRow($accountKey, 'USD');
        self::assertNotNull($first);
        self::assertSame(1, (int) $first['revision']);

        $this->connection()->transaction(function () use ($accountKey): void {
            $this->lock->claim($this->context, self::TENANT, $accountKey, 'USD');
        });
        $second = $this->lockRow($accountKey, 'USD');
        self::assertNotNull($second);
        self::assertSame(2, (int) $second['revision']);

        self::assertSame(
            1,
            $this->connection()->table('commerce_ledger_account_locks')
                ->where('tenant_uuid', '=', self::TENANT)
                ->where('account_key', '=', $accountKey)
                ->where('currency', '=', 'USD')
                ->count(),
            'exactly one lock row ever exists for this (tenant, account_key, currency)'
        );
    }

    public function testAccountLockClaimOnARowCreatedOutOfBandJustClaimsItWithoutDuplicating(): void
    {
        $accountKey = LedgerRepository::accountKeyForSeller('sellerLOCK002');

        // Simulate a row a different request/process already ensured (but
        // never claimed) for this account -- the pre-insert bypasses the
        // lock entirely (this is also the deterministic, single-connection
        // proxy for two callers racing the first claim: the claim below
        // genuinely collides with this pre-existing row).
        $this->connection()->table('commerce_ledger_account_locks')->insert([
            'tenant_uuid' => self::TENANT,
            'account_key' => $accountKey,
            'currency' => 'USD',
            'revision' => 0,
        ]);

        $this->connection()->transaction(function () use ($accountKey): void {
            $this->lock->claim($this->context, self::TENANT, $accountKey, 'USD');
        });

        $row = $this->lockRow($accountKey, 'USD');
        self::assertNotNull($row);
        self::assertSame(1, (int) $row['revision']);
        self::assertSame(
            1,
            $this->connection()->table('commerce_ledger_account_locks')
                ->where('tenant_uuid', '=', self::TENANT)
                ->where('account_key', '=', $accountKey)
                ->where('currency', '=', 'USD')
                ->count()
        );
    }

    public function testAccountLockKeysAreDistinctForMarketplaceVersusSellerAndAcrossCurrencies(): void
    {
        $sellerKey = LedgerRepository::accountKeyForSeller('sellerLOCK003');
        $marketplaceKey = LedgerRepository::MARKETPLACE_ACCOUNT_KEY;

        $this->connection()->transaction(function () use ($sellerKey, $marketplaceKey): void {
            $this->lock->claim($this->context, self::TENANT, $sellerKey, 'USD');
            $this->lock->claim($this->context, self::TENANT, $sellerKey, 'EUR');
            $this->lock->claim($this->context, self::TENANT, $marketplaceKey, 'USD');
        });

        self::assertSame(1, (int) $this->lockRow($sellerKey, 'USD')['revision']);
        self::assertSame(1, (int) $this->lockRow($sellerKey, 'EUR')['revision']);
        self::assertSame(1, (int) $this->lockRow($marketplaceKey, 'USD')['revision']);

        self::assertSame(
            3,
            $this->connection()->table('commerce_ledger_account_locks')
                ->where('tenant_uuid', '=', self::TENANT)
                ->count(),
            'seller/currency and marketplace/currency each produce their own distinct anchor row'
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function baseEntry(array $overrides = []): array
    {
        return array_merge([
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller(self::SELLER),
            'seller_uuid' => self::SELLER,
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => 1000,
            'order_uuid' => 'orderDEFAULT01',
            'seller_order_uuid' => 'selordDEFAULT1',
            'refund_uuid' => null,
            'payout_uuid' => null,
            'reserve_uuid' => null,
            'chargeback_uuid' => null,
            'idempotency_key' => 'orderDEFAULT01:' . self::SELLER . ':sale_credit',
            'reason' => null,
            'created_by' => null,
        ], $overrides);
    }

    /** @return array<string,mixed>|null */
    private function lockRow(string $accountKey, string $currency): ?array
    {
        return $this->connection()->table('commerce_ledger_account_locks')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('account_key', '=', $accountKey)
            ->where('currency', '=', $currency)
            ->first();
    }
}
