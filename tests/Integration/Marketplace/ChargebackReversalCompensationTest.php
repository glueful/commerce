<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\ChargebackRepository;
use Glueful\Extensions\Commerce\Marketplace\ChargebackService;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\ReserveConsumptionService;
use Glueful\Extensions\Commerce\Marketplace\ReserveRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent;

/**
 * Compensating chargeback REVERSAL (design spec §2.10, MV5a Task 14): a
 * provider-reported dispute WIN / re-credit -- its own `provider_event_id`,
 * carrying `relatedEventId` (the ORIGINAL dispute's own provider event id).
 * {@see ChargebackService}'s reversal branch resolves that relation, posts a
 * dedicated `chargeback_credit` + re-applied `commission_debit` per attributed
 * seller of the original (NEVER mutating the original's own rows), enforces
 * the cumulative-compensation cap, and reinstates a still-unexpired rolling
 * reserve the original consumed.
 */
final class ChargebackReversalCompensationTest extends CommerceTestCase
{
    private const TENANT = '';

    private OrderRepository $orders;
    private ChargebackRepository $chargebacks;
    private LedgerRepository $ledgerRepo;
    private ReserveRepository $reserveRepo;
    private ChargebackService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = new OrderRepository();
        $this->chargebacks = new ChargebackRepository();
        $this->ledgerRepo = new LedgerRepository();
        $this->reserveRepo = new ReserveRepository();
        $this->service = new ChargebackService(
            $this->orders,
            $this->chargebacks,
            new SellerOrderRepository(),
            $this->ledgerRepo,
            new LedgerAccountLock(),
            new ReserveConsumptionService($this->reserveRepo, $this->ledgerRepo)
        );
    }

    // -----------------------------------------------------------------
    // Fixture helpers (mirrors ChargebackReversalTest's fixture shapes).
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function seedOrder(array $overrides = []): array
    {
        $uuid = (string) ($overrides['uuid'] ?? 'orderRVXXXX1');
        unset($overrides['uuid']);

        $row = array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'marketplace_partitioned' => true,
            'email' => 'buyer@example.com',
            'guest_token_hash' => hash('sha256', $uuid),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ], $overrides);

        $this->orders->insert($this->context, $row);

        return $this->orders->findByUuid($this->context, self::TENANT, $uuid) ?? [];
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function seedSellerOrder(array $overrides): array
    {
        $row = array_merge([
            'tenant_uuid' => self::TENANT,
            'seller_name_snapshot' => 'Seller',
            'partition_number' => 1,
            'seller_reference' => (string) $overrides['uuid'] . '-ref',
            'tax_attribution_method' => 'proportional',
            'allocated_discount' => 0,
            'allocated_shipping_discount' => 0,
            'allocated_shipping' => 0,
            'allocated_tax' => 0,
            'commission_amount' => 0,
            'confirmed_at' => '2026-01-01 00:00:00',
            'status' => 'open',
            'fulfillment_status' => 'unfulfilled',
            'revision' => 0,
        ], $overrides);

        $this->connection->table('commerce_seller_orders')->insert($row);

        return $row;
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function seedOrderLine(array $overrides): array
    {
        $uuid = (string) $overrides['uuid'];

        $row = array_merge([
            'variant_uuid' => 'variant' . substr(md5($uuid), 0, 8),
            'product_name' => 'Product ' . $uuid,
            'sku' => 'SKU-' . $uuid,
            'option_values' => '[]',
            'unit_price' => (int) ($overrides['line_total'] ?? 0),
            'quantity' => 1,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'commission_basis' => 0,
            'commission_amount' => 0,
            'seller_uuid' => null,
        ], $overrides);

        $this->connection->table('commerce_order_lines')->insert($row);

        return $row;
    }

    private function seedSaleLedger(
        string $orderUuid,
        string $sellerUuid,
        string $currency,
        int $saleCredit,
        int $commissionDebit
    ): void {
        $this->ledgerRepo->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'entry_type' => 'sale_credit',
            'amount' => $saleCredit,
            'order_uuid' => $orderUuid,
            'idempotency_key' => "{$orderUuid}:{$sellerUuid}:sale_credit",
        ]);
        if ($commissionDebit > 0) {
            $this->ledgerRepo->post($this->context, self::TENANT, [
                'account_kind' => 'seller',
                'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
                'seller_uuid' => $sellerUuid,
                'currency' => $currency,
                'entry_type' => 'commission_debit',
                'amount' => -$commissionDebit,
                'order_uuid' => $orderUuid,
                'idempotency_key' => "{$orderUuid}:{$sellerUuid}:commission_debit",
            ]);
        }
    }

    private function seedHeldReserve(
        string $uuid,
        string $sellerUuid,
        string $currency,
        int $amount,
        string $sellerOrderUuid,
        string $releaseAt
    ): void {
        $this->reserveRepo->insertRollingHold($this->context, self::TENANT, [
            'uuid' => $uuid,
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'seller_order_uuid' => $sellerOrderUuid,
            'amount' => $amount,
            'reserve_bps_snapshot' => 250,
            'reserve_days_snapshot' => 7,
            'held_at' => gmdate('Y-m-d H:i:s', time() - 86400),
            'release_at' => $releaseAt,
        ]);

        $this->ledgerRepo->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'entry_type' => 'reserve_hold',
            'amount' => -$amount,
            'seller_order_uuid' => $sellerOrderUuid,
            'payout_uuid' => null,
            'reserve_uuid' => $uuid,
            'idempotency_key' => "{$sellerOrderUuid}:{$sellerUuid}:reserve_hold",
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function chargebackEvent(array $overrides = []): ProviderChargebackEvent
    {
        $currency = (string) ($overrides['currency'] ?? 'USD');
        $payableOverrides = $overrides['payable'] ?? [];

        $payable = new PayableReference(
            (string) ($payableOverrides['type'] ?? 'commerce_order'),
            (string) ($payableOverrides['id'] ?? 'orderRVXXXX1'),
            (int) ($payableOverrides['amount'] ?? 1000),
            (string) ($payableOverrides['currency'] ?? $currency),
        );

        return new ProviderChargebackEvent(
            (string) ($overrides['tenantUuid'] ?? self::TENANT),
            (string) ($overrides['provider'] ?? 'stripe'),
            (string) ($overrides['providerEventId'] ?? 'evt_' . bin2hex(random_bytes(6))),
            (string) ($overrides['paymentReference'] ?? 'pay_ref_1'),
            $payable,
            (int) ($overrides['amount'] ?? 1000),
            $currency,
            $overrides['reasonCode'] ?? 'fraudulent',
            (string) ($overrides['occurredAt'] ?? '2026-07-01T12:00:00Z'),
            (string) ($overrides['kind'] ?? ProviderChargebackEvent::KIND_CHARGEBACK),
            $overrides['relatedEventId'] ?? null,
        );
    }

    /** @return array<string,mixed>|null */
    private function chargebackRow(string $uuid): ?array
    {
        return $this->chargebacks->findByUuid($this->context, self::TENANT, $uuid);
    }

    /** @return list<array<string,mixed>> ordered oldest-first */
    private function ledgerRowsForChargeback(string $chargebackUuid): array
    {
        return $this->connection->table('commerce_marketplace_ledger')
            ->where('chargeback_uuid', '=', $chargebackUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function rowsForType(array $rows, string $entryType): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) $row['entry_type'] === $entryType
        ));
    }

    /** @return array<string,mixed> */
    private function reserveRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_seller_reserves')->where('uuid', '=', $uuid)->first();
        self::assertNotNull($row, "reserve '{$uuid}' not found");

        return $row;
    }

    // -----------------------------------------------------------------
    // 1. Full reversal of a posted full chargeback: per-seller
    //    chargeback_credit (+) + commission_debit (-) re-applied; original
    //    rows byte-unchanged; available returns to pre-chargeback level.
    // -----------------------------------------------------------------

    public function testFullReversalCreditsSellerAndReappliesCommissionWithoutMutatingOriginal(): void
    {
        $this->seedOrder(['uuid' => 'orderRVFULL1', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordRV0001',
            'order_uuid' => 'orderRVFULL1',
            'seller_uuid' => 'sellerRVFL01',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineRVFL0001',
            'order_uuid' => 'orderRVFULL1',
            'seller_uuid' => 'sellerRVFL01',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderRVFULL1', 'sellerRVFL01', 'USD', 1000, 100);

        $original = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_original_1',
            'amount' => 1000,
            'payable' => ['id' => 'orderRVFULL1', 'amount' => 1000],
        ]));
        self::assertSame('posted', $original['status']);
        $originalUuid = (string) $original['uuid'];
        $originalSnapshotBefore = $this->chargebackRow($originalUuid);
        $originalLedgerBefore = $this->ledgerRowsForChargeback($originalUuid);

        $balanceAfterChargeback = $this->ledgerRepo->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller('sellerRVFL01'),
            'USD'
        );
        self::assertSame(
            0,
            $balanceAfterChargeback['available'],
            'sanity: 900 pre-chargeback - 1000 debit + 100 reversal'
        );

        $reversal = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_reversal_1',
            'amount' => 1000,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_rv_original_1',
            'payable' => ['id' => 'orderRVFULL1', 'amount' => 1000],
        ]));

        self::assertSame('posted', $reversal['status']);
        self::assertSame($originalUuid, $reversal['related_chargeback_uuid']);
        $reversalUuid = (string) $reversal['uuid'];

        // The original's own row and ledger entries are byte-unchanged.
        self::assertEquals($originalSnapshotBefore, $this->chargebackRow($originalUuid));
        self::assertEquals($originalLedgerBefore, array_slice($this->ledgerRowsForChargeback($originalUuid), 0, 2));

        $ledger = $this->ledgerRowsForChargeback($originalUuid);
        self::assertCount(4, $ledger, 'original debit+reversal, plus this reversal\'s credit+commission_debit');

        $credit = $this->rowsForType($ledger, 'chargeback_credit')[0];
        self::assertSame(1000, (int) $credit['amount']);
        self::assertSame('sellerRVFL01', $credit['seller_uuid']);
        self::assertSame($originalUuid, $credit['chargeback_uuid']);
        self::assertSame("{$reversalUuid}:sellerRVFL01:chargeback_credit", $credit['idempotency_key']);

        $commissionDebit = $this->rowsForType($ledger, 'commission_debit');
        self::assertCount(1, $commissionDebit);
        self::assertSame(-100, (int) $commissionDebit[0]['amount']);
        self::assertSame("{$reversalUuid}:sellerRVFL01:commission_debit", $commissionDebit[0]['idempotency_key']);

        $balanceAfterReversal = $this->ledgerRepo->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller('sellerRVFL01'),
            'USD'
        );
        self::assertSame(
            900,
            $balanceAfterReversal['available'],
            'a full reversal returns available to EXACTLY its pre-chargeback level (1000 sale - 100 commission)'
        );
        self::assertSame(0, $balanceAfterReversal['debt']);
    }

    // -----------------------------------------------------------------
    // 2. Relation resolution: unknown relatedEventId AND a cross-provider
    //    match both resolve to `integrity_hold` with a null relation --
    //    never a guessed uuid, never a post.
    // -----------------------------------------------------------------

    public function testUnresolvableRelationNeverGuessesAUuidAndNeverPosts(): void
    {
        $this->seedOrder(['uuid' => 'orderRVUNK01', 'grand_total' => 1000]);

        $unknown = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_unknown_1',
            'amount' => 500,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_never_ingested',
            'payable' => ['id' => 'orderRVUNK01', 'amount' => 1000],
        ]));
        self::assertSame('integrity_hold', $unknown['status']);
        self::assertNull($unknown['related_chargeback_uuid']);
        self::assertCount(0, $this->ledgerRowsForChargeback((string) $unknown['uuid']));

        // A genuine original exists, but under a DIFFERENT provider -- the
        // lookup is scoped to (tenant, THIS event's own provider, relatedEventId),
        // so it can never cross-match.
        $this->seedSellerOrder([
            'uuid' => 'selordRVU001',
            'order_uuid' => 'orderRVUNK01',
            'seller_uuid' => 'sellerRVUN01',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineRVUN0001',
            'order_uuid' => 'orderRVUNK01',
            'seller_uuid' => 'sellerRVUN01',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderRVUNK01', 'sellerRVUN01', 'USD', 1000, 100);

        $original = $this->service->ingest($this->context, $this->chargebackEvent([
            'provider' => 'paystack',
            'providerEventId' => 'evt_rv_crossprov_1',
            'amount' => 1000,
            'payable' => ['id' => 'orderRVUNK01', 'amount' => 1000],
        ]));
        self::assertSame('posted', $original['status']);

        $crossProvider = $this->service->ingest($this->context, $this->chargebackEvent([
            'provider' => 'stripe',
            'providerEventId' => 'evt_rv_crossprov_reversal',
            'amount' => 1000,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_rv_crossprov_1',
            'payable' => ['id' => 'orderRVUNK01', 'amount' => 1000],
        ]));
        self::assertSame('integrity_hold', $crossProvider['status']);
        self::assertNull(
            $crossProvider['related_chargeback_uuid'],
            'evt_rv_crossprov_1 exists, but only under provider paystack -- a stripe-scoped lookup must not find it'
        );
        self::assertCount(
            2,
            $this->ledgerRowsForChargeback((string) $original['uuid']),
            'no compensating entries posted for the cross-provider reversal'
        );
    }

    // -----------------------------------------------------------------
    // 3. Over-amount / regressing cumulative reversal -> integrity finding,
    //    no post, no partial post.
    //
    //    UPDATED (FIX ROUND, review finding #3, design spec §2.10): the
    //    compensation cap now spans the original's TOTAL postings (seller
    //    debits/credits AND the marketplace-funded remainder), not the
    //    seller-only cap this test previously encoded as "correct" -- see
    //    {@see self::testFullReversalOfARemainderBearingChargebackCompensatesTheMarketplaceRemainderToo()}
    //    for that fix's own dedicated coverage. One structural consequence:
    //    the original's own hard-assert guarantees seller debits +
    //    marketplace remainder sum EXACTLY to the chargeback's own `amount`,
    //    and `resolve()` already bounds every reversal's own `amount` to
    //    `<= grand_total`, so a SINGLE coherent reversal against a FULL
    //    original chargeback can no longer exceed the (now-correct) total
    //    cap by itself -- only a CUMULATIVE sequence of reversals can
    //    regress past it, which is what this test now exercises.
    // -----------------------------------------------------------------

    public function testOverAmountReversalIsAnIntegrityFindingWithNoPost(): void
    {
        // grand_total (1500) intentionally exceeds the ONE seller's
        // attributed_total (1000, a $500 marketplace-funded gap) -- the
        // original posts a 1000 seller debit (100 commission_reversal) plus
        // a 500 marketplace remainder debit; total original postings = 1500.
        $this->seedOrder(['uuid' => 'orderRVOVR01', 'grand_total' => 1500]);
        $this->seedSellerOrder([
            'uuid' => 'selordRVO001',
            'order_uuid' => 'orderRVOVR01',
            'seller_uuid' => 'sellerRVOV01',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineRVOV0001',
            'order_uuid' => 'orderRVOVR01',
            'seller_uuid' => 'sellerRVOV01',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderRVOVR01', 'sellerRVOV01', 'USD', 1000, 100);

        $original = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_ovr_original',
            'amount' => 1500,
            'payable' => ['id' => 'orderRVOVR01', 'amount' => 1500],
        ]));
        self::assertSame('posted', $original['status']);
        $originalUuid = (string) $original['uuid'];

        // A first, legitimate reversal for 1000 posts fine, proportionally
        // distributed by LargestRemainder across the seller's 1000 weight
        // and the marketplace's 500 weight (sum 1500): floor(1000*1000/1500)
        // = 666 rem 1000, floor(1000*500/1500) = 333 rem 500 -- the seller's
        // larger remainder wins the single leftover unit -> seller 667,
        // marketplace 333.
        $first = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_ovr_reversal_1',
            'amount' => 1000,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_rv_ovr_original',
            'payable' => ['id' => 'orderRVOVR01', 'amount' => 1500],
        ]));
        self::assertSame('posted', $first['status']);

        $ledgerAfterFirst = $this->ledgerRowsForChargeback($originalUuid);
        $sellerCreditFirst = $this->rowsForType(
            array_values(array_filter($ledgerAfterFirst, static fn (array $r): bool => $r['seller_uuid'] === 'sellerRVOV01')),
            'chargeback_credit'
        )[0];
        self::assertSame(667, (int) $sellerCreditFirst['amount']);
        $marketplaceCreditFirst = $this->rowsForType(
            array_values(array_filter($ledgerAfterFirst, static fn (array $r): bool => $r['account_kind'] === 'marketplace')),
            'chargeback_credit'
        )[0];
        self::assertSame(333, (int) $marketplaceCreditFirst['amount']);

        // Remaining cap: seller 1000-667=333, marketplace 500-333=167,
        // total 500. A SECOND reversal for 600 -- exceeding that remaining
        // 500 TOTAL cap, even though it is still coherent against
        // grand_total -- is rejected; the first reversal's postings are
        // untouched.
        $second = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_ovr_reversal_2',
            'amount' => 600,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_rv_ovr_original',
            'payable' => ['id' => 'orderRVOVR01', 'amount' => 1500],
        ]));
        self::assertSame('integrity_hold', $second['status']);
        self::assertSame($originalUuid, $second['related_chargeback_uuid'], 'relation IS known, just not postable');

        $ledger = $this->ledgerRowsForChargeback($originalUuid);
        $credits = $this->rowsForType($ledger, 'chargeback_credit');
        self::assertCount(
            2,
            $credits,
            'only the first reversal\'s two credits (seller 667 + marketplace 333) posted -- the second never did'
        );
    }

    // -----------------------------------------------------------------
    // 4. Partial reversal posts only the delta (uncapped commission target
    //    formula reused).
    // -----------------------------------------------------------------

    public function testPartialReversalPostsOnlyItsOwnDelta(): void
    {
        $this->seedOrder(['uuid' => 'orderRVPRT01', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordRVP001',
            'order_uuid' => 'orderRVPRT01',
            'seller_uuid' => 'sellerRVPT01',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineRVPT0001',
            'order_uuid' => 'orderRVPRT01',
            'seller_uuid' => 'sellerRVPT01',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderRVPRT01', 'sellerRVPT01', 'USD', 1000, 100);

        $original = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_partial_original',
            'amount' => 1000,
            'payable' => ['id' => 'orderRVPRT01', 'amount' => 1000],
        ]));
        $originalUuid = (string) $original['uuid'];

        $reversal = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_partial_reversal',
            'amount' => 400,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_rv_partial_original',
            'payable' => ['id' => 'orderRVPRT01', 'amount' => 1000],
        ]));
        self::assertSame('posted', $reversal['status']);

        $ledger = $this->ledgerRowsForChargeback($originalUuid);
        $credit = $this->rowsForType($ledger, 'chargeback_credit')[0];
        self::assertSame(400, (int) $credit['amount'], 'posts only the 400 delta, not the full 1000 original');

        $commissionDebit = $this->rowsForType($ledger, 'commission_debit')[0];
        self::assertSame(-40, (int) $commissionDebit['amount'], 'target(100,1000,400) = floor(100*400+500,1000) = 40');
    }

    // -----------------------------------------------------------------
    // 5. Reserve reinstatement: an unexpired reserve the original consumed
    //    is re-held (capped at its own original amount, row reopens to
    //    held); an ELAPSED reserve consumed by a DIFFERENT chargeback stays
    //    untouched.
    // -----------------------------------------------------------------

    public function testReserveReinstatementReholdsOnlyTheStillUnexpiredReserve(): void
    {
        $this->seedOrder(['uuid' => 'orderRVRSV01', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordRVR001',
            'order_uuid' => 'orderRVRSV01',
            'seller_uuid' => 'sellerRVRS01',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineRVRS0001',
            'order_uuid' => 'orderRVRSV01',
            'seller_uuid' => 'sellerRVRS01',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderRVRSV01', 'sellerRVRS01', 'USD', 1000, 100);

        // Future release_at -- still unexpired when the reversal arrives.
        $this->seedHeldReserve(
            'resvRVFUTURE1',
            'sellerRVRS01',
            'USD',
            150,
            'selordRVR001',
            gmdate('Y-m-d H:i:s', time() + 7 * 86400)
        );

        $original = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_reserve_original',
            'amount' => 1000,
            'payable' => ['id' => 'orderRVRSV01', 'amount' => 1000],
        ]));
        self::assertSame('posted', $original['status']);
        $originalUuid = (string) $original['uuid'];

        $reserveAfterChargeback = $this->reserveRow('resvRVFUTURE1');
        self::assertSame('consumed', $reserveAfterChargeback['status'], 'sanity: netLiability 900 > reserve 150');

        $reversal = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_reserve_reversal',
            'amount' => 1000,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_rv_reserve_original',
            'payable' => ['id' => 'orderRVRSV01', 'amount' => 1000],
        ]));
        self::assertSame('posted', $reversal['status']);
        $reversalUuid = (string) $reversal['uuid'];

        $reserveAfterReversal = $this->reserveRow('resvRVFUTURE1');
        self::assertSame('held', $reserveAfterReversal['status'], 'reopened to held');
        self::assertNull($reserveAfterReversal['closed_at']);

        $ledger = $this->ledgerRowsForChargeback($originalUuid);
        $reholds = $this->rowsForType($ledger, 'reserve_hold');
        self::assertCount(
            1,
            $reholds,
            'only the reversal\'s own reinstatement -- the original settlement hold is not in this scope'
        );
        self::assertSame(-150, (int) $reholds[0]['amount']);
        self::assertSame('resvRVFUTURE1', $reholds[0]['reserve_uuid']);
        self::assertSame($originalUuid, $reholds[0]['chargeback_uuid']);
        self::assertSame("{$reversalUuid}:resvRVFUTURE1:reserve_reinstate", $reholds[0]['idempotency_key']);

        self::assertSame(
            150,
            $this->ledgerRepo->remainingForReserve($this->context, self::TENANT, 'resvRVFUTURE1'),
            'restored exactly back up to the reserve\'s own original amount, never beyond it'
        );

        $balance = $this->ledgerRepo->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller('sellerRVRS01'),
            'USD'
        );
        self::assertSame(150, $balance['reserved'], 'reserved is restored');
    }

    public function testReserveReinstatementSkipsAnElapsedWindow(): void
    {
        $this->seedOrder(['uuid' => 'orderRVELP01', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordRVE001',
            'order_uuid' => 'orderRVELP01',
            'seller_uuid' => 'sellerRVEL01',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineRVEL0001',
            'order_uuid' => 'orderRVELP01',
            'seller_uuid' => 'sellerRVEL01',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderRVELP01', 'sellerRVEL01', 'USD', 1000, 100);

        // Already-elapsed release_at.
        $this->seedHeldReserve(
            'resvRVPAST01',
            'sellerRVEL01',
            'USD',
            150,
            'selordRVE001',
            gmdate('Y-m-d H:i:s', time() - 3600)
        );

        $original = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_elapsed_original',
            'amount' => 1000,
            'payable' => ['id' => 'orderRVELP01', 'amount' => 1000],
        ]));
        $originalUuid = (string) $original['uuid'];
        self::assertSame('consumed', $this->reserveRow('resvRVPAST01')['status']);

        $reversal = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_elapsed_reversal',
            'amount' => 1000,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_rv_elapsed_original',
            'payable' => ['id' => 'orderRVELP01', 'amount' => 1000],
        ]));
        self::assertSame('posted', $reversal['status']);

        // Nothing re-held: the reserve stays exactly as the original chargeback left it.
        $reserveAfter = $this->reserveRow('resvRVPAST01');
        self::assertSame('consumed', $reserveAfter['status']);

        $ledger = $this->ledgerRowsForChargeback($originalUuid);
        self::assertCount(0, $this->rowsForType($ledger, 'reserve_hold'), 'window elapsed -- nothing re-held');
    }

    // -----------------------------------------------------------------
    // FIX ROUND (CRITICAL, review finding #1): reinstate() must stay
    // seller-scoped. Two sellers on ONE order, EACH with their OWN
    // unexpired reserve fully consumed by the SAME full original
    // chargeback. A full reversal must re-hold EACH seller's reserve under
    // THAT seller's own account -- never seller A's credit paying for
    // seller B's re-hold (or vice versa), and never a `reserve_hold` row
    // whose `seller_uuid` disagrees with the reserve it references.
    // -----------------------------------------------------------------

    public function testMultiSellerReserveReinstatementStaysWithinEachSellersOwnAccount(): void
    {
        $this->seedOrder(['uuid' => 'orderRVMS001', 'grand_total' => 2000]);

        $this->seedSellerOrder([
            'uuid' => 'selordRVMS01',
            'order_uuid' => 'orderRVMS001',
            'seller_uuid' => 'sellerRVMSA1',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
            'partition_number' => 1,
        ]);
        $this->seedSellerOrder([
            'uuid' => 'selordRVMS02',
            'order_uuid' => 'orderRVMS001',
            'seller_uuid' => 'sellerRVMSB1',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
            'partition_number' => 2,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineRVMSA001',
            'order_uuid' => 'orderRVMS001',
            'seller_uuid' => 'sellerRVMSA1',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineRVMSB001',
            'order_uuid' => 'orderRVMS001',
            'seller_uuid' => 'sellerRVMSB1',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderRVMS001', 'sellerRVMSA1', 'USD', 1000, 100);
        $this->seedSaleLedger('orderRVMS001', 'sellerRVMSB1', 'USD', 1000, 100);

        // Each seller has its OWN unexpired reserve -- both will be fully
        // consumed by the original full chargeback (netLiability 900 > 150).
        $this->seedHeldReserve(
            'resvRVMSA001',
            'sellerRVMSA1',
            'USD',
            150,
            'selordRVMS01',
            gmdate('Y-m-d H:i:s', time() + 7 * 86400)
        );
        $this->seedHeldReserve(
            'resvRVMSB001',
            'sellerRVMSB1',
            'USD',
            150,
            'selordRVMS02',
            gmdate('Y-m-d H:i:s', time() + 7 * 86400)
        );

        $original = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_ms_original',
            'amount' => 2000,
            'payable' => ['id' => 'orderRVMS001', 'amount' => 2000],
        ]));
        self::assertSame('posted', $original['status']);
        $originalUuid = (string) $original['uuid'];

        self::assertSame(
            'consumed',
            $this->reserveRow('resvRVMSA001')['status'],
            'sanity: seller A\'s reserve fully consumed by the original'
        );
        self::assertSame(
            'consumed',
            $this->reserveRow('resvRVMSB001')['status'],
            'sanity: seller B\'s reserve fully consumed by the original'
        );

        $reversal = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_ms_reversal',
            'amount' => 2000,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_rv_ms_original',
            'payable' => ['id' => 'orderRVMS001', 'amount' => 2000],
        ]));
        self::assertSame('posted', $reversal['status']);

        $ledger = $this->ledgerRowsForChargeback($originalUuid);
        $reholds = $this->rowsForType($ledger, 'reserve_hold');

        $reholdsForSeller = static fn (array $rows, string $sellerUuid): array => array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) $row['seller_uuid'] === $sellerUuid
        ));

        $reholdsA = $reholdsForSeller($reholds, 'sellerRVMSA1');
        $reholdsB = $reholdsForSeller($reholds, 'sellerRVMSB1');

        self::assertCount(1, $reholdsA, 'seller A gets exactly ONE reinstatement -- its own');
        self::assertSame(
            'resvRVMSA001',
            $reholdsA[0]['reserve_uuid'],
            "seller A's re-hold must reference ITS OWN reserve, never seller B's"
        );

        self::assertCount(1, $reholdsB, 'seller B gets exactly ONE reinstatement -- its own');
        self::assertSame(
            'resvRVMSB001',
            $reholdsB[0]['reserve_uuid'],
            "seller B's re-hold must reference ITS OWN reserve, never seller A's"
        );

        self::assertSame(
            150,
            $this->ledgerRepo->remainingForReserve($this->context, self::TENANT, 'resvRVMSA001')
        );
        self::assertSame(
            150,
            $this->ledgerRepo->remainingForReserve($this->context, self::TENANT, 'resvRVMSB001')
        );

        $balanceA = $this->ledgerRepo->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller('sellerRVMSA1'),
            'USD'
        );
        $balanceB = $this->ledgerRepo->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller('sellerRVMSB1'),
            'USD'
        );

        self::assertSame(150, $balanceA['reserved'], "seller A's own reserve, never seller B's");
        self::assertSame(150, $balanceB['reserved'], "seller B's own reserve, never seller A's");

        self::assertSame(
            750,
            $balanceA['available'],
            'restored to its own pre-chargeback level (1000 sale - 100 commission - 150 settlement reserve), '
                . "docked ONLY by its own re-hold -- never seller B's"
        );
        self::assertSame(
            750,
            $balanceB['available'],
            "restored to its own pre-chargeback level, docked ONLY by its own re-hold -- never seller A's"
        );
    }

    // -----------------------------------------------------------------
    // FIX ROUND (Important, review finding #2): a replayed reversal event
    // (same `provider_event_id`) whose stored row already carries a
    // RESOLVED `related_chargeback_uuid` must re-resolve the REPLAYED
    // event's own `relatedEventId` and verify it maps to that SAME stored
    // relation -- a conflicting event reusing the id with a DIFFERENT
    // `relatedEventId` is an integrity failure, never a silent no-op. The
    // SAME `relatedEventId` replayed twice must stay a verified no-op
    // (never a second post) -- covered by
    // {@see self::testDuplicateReversalEventIsIdempotent()} already; this
    // test isolates the MISMATCH half of that same guarantee.
    // -----------------------------------------------------------------

    public function testReversalReplayWithADifferentRelatedEventIdIsAnIntegrityFailure(): void
    {
        $this->seedOrder(['uuid' => 'orderRVREL01', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordRVL001',
            'order_uuid' => 'orderRVREL01',
            'seller_uuid' => 'sellerRVRL01',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineRVRL0001',
            'order_uuid' => 'orderRVREL01',
            'seller_uuid' => 'sellerRVRL01',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderRVREL01', 'sellerRVRL01', 'USD', 1000, 100);

        $originalA = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_rel_original_a',
            'amount' => 1000,
            'payable' => ['id' => 'orderRVREL01', 'amount' => 1000],
        ]));
        self::assertSame('posted', $originalA['status']);
        $originalAUuid = (string) $originalA['uuid'];

        // A SECOND, unrelated original chargeback (own order/seller) --
        // only its resolvable `provider_event_id` matters here, as the
        // conflicting relation target.
        $this->seedOrder(['uuid' => 'orderRVREL02', 'grand_total' => 500]);
        $this->seedSellerOrder([
            'uuid' => 'selordRVL002',
            'order_uuid' => 'orderRVREL02',
            'seller_uuid' => 'sellerRVRL02',
            'currency' => 'USD',
            'subtotal' => 500,
            'attributed_total' => 500,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineRVRL0002',
            'order_uuid' => 'orderRVREL02',
            'seller_uuid' => 'sellerRVRL02',
            'line_total' => 500,
            'commission_basis' => 500,
            'commission_amount' => 50,
        ]);
        $this->seedSaleLedger('orderRVREL02', 'sellerRVRL02', 'USD', 500, 50);

        $originalB = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_rel_original_b',
            'amount' => 500,
            'payable' => ['id' => 'orderRVREL02', 'amount' => 500],
        ]));
        self::assertSame('posted', $originalB['status']);

        // First delivery of the reversal event -- relates to A, posts, and
        // resolves+persists `related_chargeback_uuid` = A's own uuid.
        $reversalEvent = $this->chargebackEvent([
            'providerEventId' => 'evt_rv_rel_reversal',
            'amount' => 1000,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_rv_rel_original_a',
            'payable' => ['id' => 'orderRVREL01', 'amount' => 1000],
        ]);
        $first = $this->service->ingest($this->context, $reversalEvent);
        self::assertSame('posted', $first['status']);
        self::assertSame($originalAUuid, $first['related_chargeback_uuid']);

        // A CONFLICTING replay: the EXACT SAME `provider_event_id`
        // ('evt_rv_rel_reversal'), every VERIFIED_FIELDS value identical
        // (amount/currency/payable/occurredAt/kind), but a DIFFERENT
        // `relatedEventId` that resolves to a DIFFERENT original (B) --
        // this must raise, never silently return the stored row.
        $conflicting = $this->chargebackEvent([
            'providerEventId' => 'evt_rv_rel_reversal',
            'amount' => 1000,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_rv_rel_original_b',
            'payable' => ['id' => 'orderRVREL01', 'amount' => 1000],
        ]);

        $this->expectException(\Glueful\Extensions\Commerce\Marketplace\ChargebackIntegrityException::class);
        $this->service->ingest($this->context, $conflicting);
    }

    // -----------------------------------------------------------------
    // FIX ROUND (Important, review finding #3, design spec §2.10): the
    // compensation basis must include the original's marketplace-funded
    // unattributable remainder, not just Σ seller postings -- otherwise a
    // full-amount reversal of a remainder-bearing chargeback always exceeds
    // the seller-only cap and parks in `integrity_hold` forever, even
    // though the provider genuinely reversed the FULL disputed amount.
    // -----------------------------------------------------------------

    public function testFullReversalOfARemainderBearingChargebackCompensatesTheMarketplaceRemainderToo(): void
    {
        // grand_total (1000) exceeds the ONE seller's attributed_total
        // (800) -- a 200 marketplace-funded remainder posts alongside the
        // seller's own 800 debit (mirrors
        // ChargebackReversalTest::testUnattributableGapBetweenSellerAllocationsAndChargebackAmountGoesToMarketplace).
        $this->seedOrder(['uuid' => 'orderRVMKT01', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordRVK001',
            'order_uuid' => 'orderRVMKT01',
            'seller_uuid' => 'sellerRVMK01',
            'currency' => 'USD',
            'subtotal' => 800,
            'attributed_total' => 800,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineRVMK0001',
            'order_uuid' => 'orderRVMKT01',
            'seller_uuid' => 'sellerRVMK01',
            'line_total' => 800,
            'commission_basis' => 800,
            'commission_amount' => 80,
        ]);
        $this->seedSaleLedger('orderRVMKT01', 'sellerRVMK01', 'USD', 800, 80);

        $original = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_mkt_original',
            'amount' => 1000,
            'payable' => ['id' => 'orderRVMKT01', 'amount' => 1000],
        ]));
        self::assertSame('posted', $original['status']);
        $originalUuid = (string) $original['uuid'];

        $originalLedger = $this->ledgerRowsForChargeback($originalUuid);
        $marketplaceDebit = $this->rowsForType(
            array_values(array_filter(
                $originalLedger,
                static fn (array $row): bool => $row['account_kind'] === 'marketplace'
            )),
            'chargeback_debit'
        );
        self::assertCount(1, $marketplaceDebit, 'sanity: the original posted a marketplace-funded remainder');
        self::assertSame(-200, (int) $marketplaceDebit[0]['amount']);

        $marketplaceBalanceBefore = $this->ledgerRepo->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::MARKETPLACE_ACCOUNT_KEY,
            'USD'
        );
        self::assertSame(-200, $marketplaceBalanceBefore['available'], 'sanity: marketplace is out the 200 remainder');

        // The provider reverses the FULL disputed amount -- it has no
        // knowledge of the internal seller/marketplace split.
        $reversal = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_mkt_reversal',
            'amount' => 1000,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_rv_mkt_original',
            'payable' => ['id' => 'orderRVMKT01', 'amount' => 1000],
        ]));

        self::assertSame(
            'posted',
            $reversal['status'],
            'a full reversal of a remainder-bearing chargeback must fully compensate, never integrity_hold'
        );
        $reversalUuid = (string) $reversal['uuid'];

        $ledger = $this->ledgerRowsForChargeback($originalUuid);

        $sellerCredit = $this->rowsForType(
            array_values(array_filter($ledger, static fn (array $r): bool => $r['seller_uuid'] === 'sellerRVMK01')),
            'chargeback_credit'
        );
        self::assertCount(1, $sellerCredit);
        self::assertSame(800, (int) $sellerCredit[0]['amount']);

        $marketplaceCredit = $this->rowsForType(
            array_values(array_filter(
                $ledger,
                static fn (array $r): bool => $r['account_kind'] === 'marketplace'
            )),
            'chargeback_credit'
        );
        self::assertCount(1, $marketplaceCredit, 'the marketplace-funded remainder must ALSO be compensated');
        self::assertSame(200, (int) $marketplaceCredit[0]['amount']);
        self::assertNull($marketplaceCredit[0]['seller_uuid']);
        self::assertSame($originalUuid, $marketplaceCredit[0]['chargeback_uuid']);
        self::assertSame("{$reversalUuid}:marketplace:chargeback_credit", $marketplaceCredit[0]['idempotency_key']);

        self::assertSame(
            1000,
            (int) $sellerCredit[0]['amount'] + (int) $marketplaceCredit[0]['amount'],
            'seller credit + marketplace credit must sum EXACTLY to the reversal amount -- fully conserved'
        );

        $marketplaceBalanceAfter = $this->ledgerRepo->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::MARKETPLACE_ACCOUNT_KEY,
            'USD'
        );
        self::assertSame(0, $marketplaceBalanceAfter['available'], 'the marketplace fully recovers its remainder');

        // An over-amount BEYOND the total original postings (seller 800 +
        // marketplace 200 = 1000, already fully compensated above) must
        // still land on integrity_hold -- a genuine over-amount, not a
        // scope gap.
        $overAmount = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_mkt_reversal_over',
            'amount' => 1,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_rv_mkt_original',
            'payable' => ['id' => 'orderRVMKT01', 'amount' => 1000],
        ]));
        self::assertSame('integrity_hold', $overAmount['status']);
        self::assertSame($originalUuid, $overAmount['related_chargeback_uuid'], 'relation IS known, just not postable');

        // Nothing further posted for the over-amount attempt.
        $finalLedger = $this->ledgerRowsForChargeback($originalUuid);
        self::assertCount(
            1,
            $this->rowsForType(
                array_values(array_filter($finalLedger, static fn (array $r): bool => $r['account_kind'] === 'marketplace')),
                'chargeback_credit'
            ),
            'the over-amount attempt posts nothing new'
        );
    }

    // -----------------------------------------------------------------
    // 6. Duplicate reversal event -> idempotent, never a second credit/re-hold.
    // -----------------------------------------------------------------

    public function testDuplicateReversalEventIsIdempotent(): void
    {
        $this->seedOrder(['uuid' => 'orderRVDUP01', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordRVD001',
            'order_uuid' => 'orderRVDUP01',
            'seller_uuid' => 'sellerRVDP01',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineRVDP0001',
            'order_uuid' => 'orderRVDUP01',
            'seller_uuid' => 'sellerRVDP01',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderRVDUP01', 'sellerRVDP01', 'USD', 1000, 100);
        $this->seedHeldReserve(
            'resvRVDUP001',
            'sellerRVDP01',
            'USD',
            150,
            'selordRVD001',
            gmdate('Y-m-d H:i:s', time() + 7 * 86400)
        );

        $original = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_rv_dup_original',
            'amount' => 1000,
            'payable' => ['id' => 'orderRVDUP01', 'amount' => 1000],
        ]));
        $originalUuid = (string) $original['uuid'];

        $event = $this->chargebackEvent([
            'providerEventId' => 'evt_rv_dup_reversal',
            'amount' => 1000,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_rv_dup_original',
            'payable' => ['id' => 'orderRVDUP01', 'amount' => 1000],
        ]);

        $first = $this->service->ingest($this->context, $event);
        self::assertSame('posted', $first['status']);

        $second = $this->service->ingest($this->context, $event);
        self::assertSame('posted', $second['status']);
        self::assertSame($first['uuid'], $second['uuid']);
        self::assertSame($first['related_chargeback_uuid'], $second['related_chargeback_uuid']);

        $ledger = $this->ledgerRowsForChargeback($originalUuid);
        self::assertCount(1, $this->rowsForType($ledger, 'chargeback_credit'), 'never a second credit');
        self::assertCount(1, $this->rowsForType($ledger, 'commission_debit'), 'never a second commission_debit');
        self::assertCount(1, $this->rowsForType($ledger, 'reserve_hold'), 'never a second re-hold');

        self::assertSame(
            'held',
            $this->reserveRow('resvRVDUP001')['status'],
            'reopened exactly once, not repeatedly toggled'
        );
    }
}
