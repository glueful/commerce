<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerException;
use Glueful\Extensions\Commerce\Marketplace\LedgerPostingService;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyEventRepository;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyService;
use Glueful\Extensions\Commerce\Marketplace\ReserveRepository;
use Glueful\Extensions\Commerce\Marketplace\ReserveService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Rolling reserve hold at settlement (design spec §2.2, MV5a Task 7):
 * {@see ReserveService::holdForSettlement()} is called by
 * {@see LedgerPostingService::postSale()} for every settling seller order,
 * under the SAME seller/currency account lock, immediately after
 * `sale_credit`/`commission_debit`. Covers the reserve-base math (both
 * equivalent formulas), the guardrail integrity throw, the policy
 * zero/floor-to-zero no-op rules, the `reserved`/`available` balance
 * effect, and settlement-replay immutability (never recomputing current
 * policy or current time).
 */
final class ReserveHoldTest extends CommerceTestCase
{
    private const TENANT = '';

    // A single, reused, internally-consistent seller-order fixture (design
    // spec §2.2 worked example): subtotal 10000, discount 1000 => merchandise
    // after discount 9000; shipping 500 + tax 300 => attributed_total 9800
    // (9000 + 500 + 300, proving the two formulas agree); commission 900 =>
    // reserve_base = 9000 - 900 = 8100.
    private const BASE_SELLER_ORDER = [
        'uuid' => 'selordRSV001',
        'order_uuid' => 'orderRSV0001',
        'seller_uuid' => 'sellerRSV001',
        'currency' => 'USD',
        'subtotal' => 10000,
        'allocated_discount' => 1000,
        'allocated_shipping_discount' => 5000, // never subtracted -- deliberately absurd to prove it's ignored
        'allocated_shipping' => 500,
        'allocated_tax' => 300,
        'attributed_total' => 9800,
        'commission_amount' => 900,
        'confirmed_at' => '2026-01-01 12:00:00',
    ];

    // -----------------------------------------------------------------
    // Reserve base math: both equivalent formulas, floor, snapshot, dates.
    // -----------------------------------------------------------------

    public function testHoldForSettlementComputesReserveBaseBothEquivalentWaysAndPostsTheFlooredHold(): void
    {
        $sellerOrder = self::BASE_SELLER_ORDER;

        // Prove the equivalence the guardrail itself enforces, on THIS real
        // multi-component order, before even calling the service:
        $viaSubtotal = $sellerOrder['subtotal'] - $sellerOrder['allocated_discount']
            - $sellerOrder['commission_amount'];
        $viaAttributed = $sellerOrder['attributed_total'] - $sellerOrder['allocated_shipping']
            - $sellerOrder['allocated_tax'] - $sellerOrder['commission_amount'];
        self::assertSame(8100, $viaSubtotal);
        self::assertSame(8100, $viaAttributed, 'both reserve-base formulas must agree on this order');

        $this->seedWorkspace(250, 7);
        $this->seedSeller('sellerRSV001', null, null);

        $this->reserveService()->holdForSettlement($this->context, self::TENANT, $sellerOrder);

        $rows = $this->connection->table('commerce_seller_reserves')->get();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('rolling', $row['source_kind']);
        self::assertSame('selordRSV001', $row['seller_order_uuid']);
        self::assertSame('sellerRSV001', $row['seller_uuid']);
        self::assertSame('USD', $row['currency']);
        self::assertSame(202, (int) $row['amount'], 'floor(8100 * 250 / 10000) = 202.5 -> 202');
        self::assertSame(250, (int) $row['reserve_bps_snapshot']);
        self::assertSame(7, (int) $row['reserve_days_snapshot']);
        self::assertSame('held', $row['status']);
        self::assertSame('2026-01-01 12:00:00', $row['held_at'], 'held_at must be the PERSISTED confirmed_at');
        self::assertSame('2026-01-08 12:00:00', $row['release_at'], 'release_at = confirmed_at + reserve_days');
        self::assertNull($row['closed_at']);

        $ledgerRows = $this->ledgerRowsForOrder('orderRSV0001');
        self::assertCount(1, $ledgerRows);
        $hold = $ledgerRows[0];
        self::assertSame('reserve_hold', $hold['entry_type']);
        self::assertSame('seller', $hold['account_kind']);
        self::assertSame('seller:sellerRSV001', $hold['account_key']);
        self::assertSame(-202, (int) $hold['amount']);
        self::assertNull($hold['payout_uuid']);
        self::assertSame($row['uuid'], $hold['reserve_uuid']);
        self::assertSame('orderRSV0001:sellerRSV001:reserve_hold', $hold['idempotency_key']);
    }

    // -----------------------------------------------------------------
    // reserved/available balance effect.
    // -----------------------------------------------------------------

    public function testPostSaleWithReservesWiredPostsSaleCommissionAndReserveHoldAndReflectsInBalance(): void
    {
        $this->seedWorkspace(250, 7);
        $this->seedSeller('sellerRSV001', null, null);

        $this->ledgerPostingService()->postSale(
            $this->context,
            self::TENANT,
            ['uuid' => 'orderRSV0001'],
            [self::BASE_SELLER_ORDER]
        );

        $ledgerRows = $this->ledgerRowsForOrder('orderRSV0001');
        self::assertCount(3, $ledgerRows, 'sale_credit + commission_debit + reserve_hold');
        self::assertSame(
            ['sale_credit', 'commission_debit', 'reserve_hold'],
            array_column($ledgerRows, 'entry_type')
        );

        $reserveRows = $this->connection->table('commerce_seller_reserves')->get();
        self::assertCount(1, $reserveRows);

        $balance = (new LedgerRepository())->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller('sellerRSV001'),
            'USD'
        );
        self::assertSame(202, $balance['reserved'], 'reserved must reflect the unreleased hold');
        self::assertSame(
            9800 - 900 - 202,
            $balance['available'],
            'available must be reduced by the reserve hold too (available sums every entry)'
        );
    }

    // -----------------------------------------------------------------
    // Off-invariance: 0 bps, 0 days, and a base that floors to 0 -- no row,
    // no ledger entry.
    // -----------------------------------------------------------------

    public function testZeroReserveBpsPostsNoHoldAndNoRow(): void
    {
        $this->seedWorkspace(0, 7);
        $this->seedSeller('sellerRSV001', null, null);

        $this->reserveService()->holdForSettlement($this->context, self::TENANT, self::BASE_SELLER_ORDER);

        self::assertSame(0, $this->connection->table('commerce_seller_reserves')->count());
        self::assertSame(0, $this->connection->table('commerce_marketplace_ledger')->count());
    }

    public function testZeroReserveDaysPostsNoHoldAndNoRow(): void
    {
        $this->seedWorkspace(250, 0);
        $this->seedSeller('sellerRSV001', null, null);

        $this->reserveService()->holdForSettlement($this->context, self::TENANT, self::BASE_SELLER_ORDER);

        self::assertSame(0, $this->connection->table('commerce_seller_reserves')->count());
        self::assertSame(0, $this->connection->table('commerce_marketplace_ledger')->count());
    }

    public function testReserveBaseFlooringToZeroPostsNoHoldAndNoRow(): void
    {
        $this->seedWorkspace(1, 1);
        $this->seedSeller('sellerRSV002', null, null);

        // merchandise_after_discount = 100 - 99 = 1 = attributed_total(1) - 0 - 0.
        // reserve_base = max(0, 1 - 0) = 1; floor(1 * 1 / 10000) = 0.
        $sellerOrder = [
            'uuid' => 'selordRSV002',
            'order_uuid' => 'orderRSV0002',
            'seller_uuid' => 'sellerRSV002',
            'currency' => 'USD',
            'subtotal' => 100,
            'allocated_discount' => 99,
            'allocated_shipping_discount' => 0,
            'allocated_shipping' => 0,
            'allocated_tax' => 0,
            'attributed_total' => 1,
            'commission_amount' => 0,
            'confirmed_at' => '2026-01-01 00:00:00',
        ];

        $this->reserveService()->holdForSettlement($this->context, self::TENANT, $sellerOrder);

        self::assertSame(0, $this->connection->table('commerce_seller_reserves')->count());
        self::assertSame(0, $this->connection->table('commerce_marketplace_ledger')->count());
    }

    // -----------------------------------------------------------------
    // Guardrail integrity throw.
    // -----------------------------------------------------------------

    public function testGuardrailMismatchThrowsIntegrityException(): void
    {
        $this->seedWorkspace(250, 7);
        $this->seedSeller('sellerRSV001', null, null);

        // attributed_total is off by 1 relative to subtotal/allocated_discount:
        // merchandise_after_discount (9000) != attributed_total - shipping - tax (9001).
        $sellerOrder = self::BASE_SELLER_ORDER;
        $sellerOrder['attributed_total'] = 9801;

        $this->expectException(LedgerException::class);
        $this->reserveService()->holdForSettlement($this->context, self::TENANT, $sellerOrder);
    }

    public function testMissingConfirmedAtThrowsBeforeAnyHoldIsPosted(): void
    {
        $this->seedWorkspace(250, 7);
        $this->seedSeller('sellerRSV001', null, null);

        $sellerOrder = self::BASE_SELLER_ORDER;
        $sellerOrder['confirmed_at'] = null;

        try {
            $this->reserveService()->holdForSettlement($this->context, self::TENANT, $sellerOrder);
            self::fail('expected a LedgerException for a null confirmed_at');
        } catch (LedgerException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(0, $this->connection->table('commerce_seller_reserves')->count());
        self::assertSame(0, $this->connection->table('commerce_marketplace_ledger')->count());
    }

    // -----------------------------------------------------------------
    // Degrade safely: a missing seller (resolve() -> NotFoundException)
    // never breaks the sale posting already committed above it.
    // -----------------------------------------------------------------

    public function testUnknownSellerDegradesToNoHoldWithoutAbortingTheSalePosting(): void
    {
        // Deliberately NO seedSeller() call -- ReservePolicyService::resolve()
        // throws NotFoundException for this seller_uuid.
        $sellerOrder = self::BASE_SELLER_ORDER;
        $sellerOrder['seller_uuid'] = 'missingSlr01';

        $this->ledgerPostingService()->postSale(
            $this->context,
            self::TENANT,
            ['uuid' => 'orderRSV0003'],
            [$sellerOrder]
        );

        $ledgerRows = $this->ledgerRowsForOrder('orderRSV0003');
        self::assertCount(2, $ledgerRows, 'sale_credit + commission_debit must still post; no reserve_hold');
        self::assertSame(['sale_credit', 'commission_debit'], array_column($ledgerRows, 'entry_type'));
        self::assertSame(0, $this->connection->table('commerce_seller_reserves')->count());
    }

    // -----------------------------------------------------------------
    // Settlement replay: exactly one hold, no recomputation from current
    // policy or current time, and a genuinely conflicting row throws.
    // -----------------------------------------------------------------

    public function testCallingHoldForSettlementTwicePostsExactlyOneReserveRowAndOneLedgerEntry(): void
    {
        $this->seedWorkspace(250, 7);
        $this->seedSeller('sellerRSV001', null, null);

        $service = $this->reserveService();
        $service->holdForSettlement($this->context, self::TENANT, self::BASE_SELLER_ORDER);
        $service->holdForSettlement($this->context, self::TENANT, self::BASE_SELLER_ORDER);

        self::assertSame(1, $this->connection->table('commerce_seller_reserves')->count());
        $ledgerRows = $this->ledgerRowsForOrder('orderRSV0001');
        self::assertCount(1, $ledgerRows);
        self::assertSame(-202, (int) $ledgerRows[0]['amount']);
    }

    public function testCallingPostSaleTwicePostsExactlyOneReserveHoldAlongsideTheUnchangedSaleRows(): void
    {
        $this->seedWorkspace(250, 7);
        $this->seedSeller('sellerRSV001', null, null);

        $service = $this->ledgerPostingService();
        $service->postSale($this->context, self::TENANT, ['uuid' => 'orderRSV0001'], [self::BASE_SELLER_ORDER]);
        $service->postSale($this->context, self::TENANT, ['uuid' => 'orderRSV0001'], [self::BASE_SELLER_ORDER]);

        self::assertSame(1, $this->connection->table('commerce_seller_reserves')->count());
        self::assertCount(3, $this->ledgerRowsForOrder('orderRSV0001'));
    }

    public function testReplayIgnoresAPolicyChangeMadeBetweenTheOriginalHoldAndTheReplay(): void
    {
        $this->seedWorkspace(250, 7);
        $this->seedSeller('sellerRSV001', null, null);

        $service = $this->reserveService();
        $service->holdForSettlement($this->context, self::TENANT, self::BASE_SELLER_ORDER);

        // Policy changes AFTER the original hold -- a fresh resolve() would
        // return a completely different bps/days. The replay below must
        // never call resolve() again: there is also no wall-clock read
        // anywhere in this computation (release_at is always derived from
        // the PERSISTED confirmed_at, never `now()`), so "advancing the
        // clock" cannot affect the outcome either, by construction.
        $this->connection->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', self::TENANT)
            ->update(['reserve_bps' => 9999, 'reserve_days' => 1]);

        $service->holdForSettlement($this->context, self::TENANT, self::BASE_SELLER_ORDER);

        $rows = $this->connection->table('commerce_seller_reserves')->get();
        self::assertCount(1, $rows, 'the policy change must not create a second, differently-sized hold');
        self::assertSame(202, (int) $rows[0]['amount'], 'the historical amount must never be recalculated');
        self::assertSame(250, (int) $rows[0]['reserve_bps_snapshot']);
        self::assertSame(7, (int) $rows[0]['reserve_days_snapshot']);
        self::assertSame('2026-01-08 12:00:00', $rows[0]['release_at']);

        self::assertCount(1, $this->ledgerRowsForOrder('orderRSV0001'));
    }

    public function testReplayWithAGenuinelyConflictingPreexistingRowThrows(): void
    {
        // No seedSeller()/seedWorkspace() at all -- proves the replay path
        // never touches policy resolution: the pre-existing row alone is
        // enough for holdForSettlement() to detect and reject a conflict.
        $this->connection->table('commerce_seller_reserves')->insert([
            'uuid' => 'resvCONFLICT',
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => 'sellerRSV001',
            'currency' => 'USD',
            'source_kind' => 'rolling',
            'seller_order_uuid' => 'selordRSV001',
            'amount' => 999,
            'reserve_bps_snapshot' => 250,
            'reserve_days_snapshot' => 7,
            'status' => 'held',
            'held_at' => '2026-01-01 12:00:00',
            'release_at' => '2026-01-08 12:00:00',
        ]);

        $this->expectException(LedgerException::class);
        $this->reserveService()->holdForSettlement($this->context, self::TENANT, self::BASE_SELLER_ORDER);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function reserveService(): ReserveService
    {
        return new ReserveService(
            new ReservePolicyService(
                new SellerRepository(),
                new MarketplaceWorkspaceLock(),
                new ReservePolicyEventRepository()
            ),
            new ReserveRepository(),
            new LedgerRepository()
        );
    }

    private function ledgerPostingService(): LedgerPostingService
    {
        return new LedgerPostingService(new LedgerRepository(), new LedgerAccountLock(), $this->reserveService());
    }

    private function seedWorkspace(int $bps, int $days): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'wsRSV0000001',
            'tenant_uuid' => self::TENANT,
            'status' => 'active',
            'reserve_bps' => $bps,
            'reserve_days' => $days,
        ]);
    }

    private function seedSeller(string $uuid, ?int $bps, ?int $days): void
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'status' => 'active',
            'reserve_bps' => $bps,
            'reserve_days' => $days,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function ledgerRowsForOrder(string $orderUuid): array
    {
        return $this->connection->table('commerce_marketplace_ledger')
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }
}
