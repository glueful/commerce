<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\LargestRemainder;
use Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent;

/**
 * Chargeback INGESTION + classification (design spec §2.4, MV5a Task 10):
 * resolves and validates a dispatched {@see ProviderChargebackEvent} against
 * Commerce's own order data, then persists it event-first into
 * `commerce_chargebacks` via {@see ChargebackRepository::insert()} -- the
 * unique `(tenant, provider, provider_event_id)` is the idempotency claim,
 * and a conflicting replay is a {@see ChargebackIntegrityException}, never a
 * silent skip.
 *
 * **Historical partition authority (design spec §2.11, Global Constraints).**
 * Eligibility keys ONLY on the resolved order's OWN immutable
 * `marketplace_partitioned` flag -- current `MarketplaceMode::activeFor()` is
 * NEVER consulted (mirrors {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundService::validate()}'s
 * identical discipline). A historical partitioned order therefore keeps
 * ingesting chargebacks after workspace deactivation; a non-partitioned
 * order is outside MV5a entirely and this method inserts NO
 * `commerce_chargebacks` row for it (an explicit `ignored` result, not
 * `integrity_hold`).
 *
 * **Scope seam -- posting is Task 11 (DONE, this class), reversal correlation
 * is Task 14.** This class INGESTS, CLASSIFIES, and (MV5a Task 11) POSTS:
 *  - a resolvable, coherent, partitioned **full** chargeback (`amount` equals
 *    the order's own `grand_total` -- the order's original captured/charge
 *    amount; see design spec §2.5 "a full chargeback (equal to the original
 *    charge)") persists as `received`, then -- in the SAME transaction --
 *    {@see self::autoExpandFull()} auto-expands every seller order's
 *    immutable `attributed_total` across its lines, persists the generated
 *    `commerce_chargeback_lines`, reverses each attributed seller
 *    proportionally, and transitions the row to `posted` (or, if the
 *    auto-expanded attribution would exceed a line's own derived remaining
 *    after earlier chargebacks/refunds, to `integrity_hold` instead -- see
 *    {@see self::postAttributedLines()});
 *  - a resolvable, coherent, partitioned **partial** chargeback (`amount` <
 *    `grand_total`) persists as `awaiting_attribution` here (no operator-
 *    supplied `commerce_chargeback_lines` exist yet); {@see self::attributeAndPost()}
 *    is the SEPARATE later entry point Task 16's operator surface calls once
 *    lines exist -- it validates the supplied `(order_line_uuid, amount)`
 *    rows sum exactly to the chargeback amount, then posts through the SAME
 *    shared {@see self::postAttributedLines()} machinery;
 *  - an unresolvable/incoherent event (unsupported payable type, unknown
 *    order, currency mismatch, or amount exceeding the order's original
 *    charge) STILL persists event-first, but as `integrity_hold` -- a repair
 *    path (not built here) handles that state later, and it is never
 *    guessed into a posting;
 *  - a `kind=reversal` event runs through the SAME resolve/validate/
 *    event-first-insert pipeline as a chargeback (tenant/payable/order/
 *    currency/amount/partition), but is deliberately NOT classified
 *    full/partial (that concept doesn't apply to a reversal) and its
 *    `related_chargeback_uuid` is deliberately left `null` here -- Task 14
 *    resolves `relatedEventId` under `(tenant, provider, provider_event_id)`
 *    into the internal `related_chargeback_uuid`, posts the compensating
 *    `chargeback_credit`/`commission_debit`, and reinstates any consumed
 *    reserve. A resolvable reversal therefore persists `received` here too,
 *    a clearly-marked TODO seam for Task 14 to fill -- this method never
 *    guesses reversal semantics, and it is deliberately NEVER routed into
 *    {@see self::autoExpandFull()} (that path is chargeback-kind only).
 */
final class ChargebackService
{
    /** The one Commerce payable type this service accepts (design spec §2.4). */
    private const SUPPORTED_PAYABLE_TYPE = 'commerce_order';

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ChargebackRepository $chargebacks,
        private readonly SellerOrderRepository $sellerOrders,
        private readonly LedgerRepository $ledger,
        private readonly LedgerAccountLock $lock,
        private readonly ReserveConsumptionService $reserveConsumption,
    ) {
    }

    /**
     * @return array<string,mixed> either the persisted `commerce_chargebacks`
     *     row (freshly inserted, or the verified pre-existing row on an
     *     identical-payload replay), or, for a non-partitioned order, the
     *     explicit no-op shape `['status' => 'ignored', 'ignored_reason' =>
     *     'order_not_partitioned', 'chargeback' => null]` -- no row is ever
     *     inserted for that case.
     */
    public function ingest(ApplicationContext $c, ProviderChargebackEvent $event): array
    {
        // Tenant is resolved FROM THE EVENT -- Payvia already correlated the
        // provider transaction to exactly one persisted payment/tenant/payable
        // before ever dispatching it (design spec §2.4 "Delivery boundary");
        // this is not a request-scoped tenant lookup like
        // CurrentTenantResolver::tenantUuid(). May legitimately be '' in
        // single-store mode.
        $tenant = $event->tenantUuid;

        // Insert-first (design spec §2.4, load-bearing): the whole resolve +
        // persist happens inside ONE transaction, so Task 11 can extend this
        // exact method to add attribution/posting/`posted` transition for a
        // full chargeback without introducing a second transaction boundary.
        return db($c)->transaction(function () use ($c, $tenant, $event): array {
            $resolution = $this->resolve($c, $tenant, $event);

            if ($resolution['status'] === 'ignored') {
                return [
                    'status' => 'ignored',
                    'ignored_reason' => $resolution['ignored_reason'],
                    'chargeback' => null,
                ];
            }

            $row = $this->chargebacks->insert($c, $tenant, [
                'provider' => $event->provider,
                'provider_event_id' => $event->providerEventId,
                'payment_reference' => $event->paymentReference,
                'order_uuid' => $resolution['order_uuid'],
                'amount' => $event->amount,
                'currency' => $event->currency,
                'reason_code' => $event->reasonCode,
                'occurred_at' => db($c)->getDriver()->formatDateTime($event->occurredAt),
                'kind' => $event->kind,
                // Task 14 seam: a reversal's relation to its original chargeback
                // is resolved there, never guessed here.
                'related_chargeback_uuid' => null,
                'status' => $resolution['initial_status'],
            ]);

            // MV5a Task 11: a fresh, resolvable, coherent, partitioned FULL
            // chargeback -- `received` is ONLY ever the initial_status for that
            // case (never a reversal, never partial, never integrity_hold) -- is
            // auto-expanded and posted right here, inside this SAME transaction
            // (design spec §2.4/§2.5's "same transaction" requirement). A REPLAY
            // of an already-processed event (the row returned by insert() on a
            // duplicate-key verify) never re-enters this branch: its status is
            // whatever the FIRST successful attempt left it at (`posted` or
            // `integrity_hold`), never `received` again.
            if ($row['status'] === 'received' && $event->kind === ProviderChargebackEvent::KIND_CHARGEBACK) {
                $row = $this->autoExpandFull($c, $tenant, $row);
            }

            return $row;
        });
    }

    /**
     * Task 16 seam: the SEPARATE entry point an operator's line-attribution
     * surface calls once it has persisted (or is about to persist, via this
     * very method) `(order_line_uuid, amount)` rows for an `awaiting_attribution`
     * PARTIAL chargeback (design spec §2.5). Opens its OWN transaction --
     * unlike the full-chargeback path, this is inherently a LATER, separate
     * operator action, never continuous with the original event-first insert.
     *
     * Idempotent/no-op guard: if the chargeback is no longer `awaiting_attribution`
     * (already `posted` by an earlier call, or in `integrity_hold`, or -- a
     * caller error -- still `received`/never resolved), the row is returned
     * UNCHANGED without attempting anything, mirroring the house "re-read and
     * verify before touching state" discipline used throughout this package.
     *
     * @param list<array{order_line_uuid:string, amount:int}> $lines operator-supplied
     *   attribution; MUST sum EXACTLY to the chargeback's own `amount` (design
     *   spec §2.5 "REQUIRES explicit persisted line attribution... sum must
     *   equal the chargeback amount before posting") -- a mismatch (including
     *   an empty list) is a {@see ChargebackAttributionException}, a caller-input
     *   rejection, never a silent partial post.
     * @return array<string,mixed> the updated (or unchanged) `commerce_chargebacks` row
     */
    public function attributeAndPost(
        ApplicationContext $c,
        string $tenant,
        string $chargebackUuid,
        array $lines
    ): array {
        return db($c)->transaction(function () use ($c, $tenant, $chargebackUuid, $lines): array {
            $cb = $this->chargebacks->findByUuid($c, $tenant, $chargebackUuid)
                ?? throw new ChargebackAttributionException(
                    "Chargeback attribution failure: unknown chargeback '{$chargebackUuid}'."
                );

            if ($cb['status'] !== 'awaiting_attribution') {
                return $cb;
            }

            $amount = (int) $cb['amount'];
            $sum = 0;
            /** @var array<string,int> $lineAmounts */
            $lineAmounts = [];
            foreach ($lines as $line) {
                $lineUuid = (string) $line['order_line_uuid'];
                $lineAmount = (int) $line['amount'];
                $lineAmounts[$lineUuid] = ($lineAmounts[$lineUuid] ?? 0) + $lineAmount;
                $sum += $lineAmount;
            }

            if ($lines === [] || $sum !== $amount) {
                throw new ChargebackAttributionException(
                    "Chargeback attribution failure ({$chargebackUuid}): supplied lines sum {$sum} "
                        . "!= chargeback amount {$amount}."
                );
            }

            return $this->postAttributedLines($c, $tenant, $cb, $lineAmounts, 'awaiting_attribution');
        });
    }

    /**
     * MV5a Task 11: a FULL chargeback (`amount` === the order's own `grand_total`)
     * auto-expands each of the order's immutable `commerce_seller_orders` --
     * distributing that seller order's own `attributed_total` across its
     * immutable lines with {@see LargestRemainder::distribute()}, weighted by
     * `max(0, line_total - discount_amount + tax_amount)` (design spec §2.5;
     * `LargestRemainder` itself tie-breaks by ASCENDING key -- line UUID here --
     * and falls back to equal unit weights when every weight is zero) -- then
     * posts through the SAME shared {@see self::postAttributedLines()} machinery
     * partial attribution uses.
     *
     * Hard-assert (design spec §2.5, unconditional -- a "should never happen"
     * internal-consistency guardrail, never a soft business-condition classification):
     * each seller order's distributed line sum must equal its own `attributed_total`
     * EXACTLY ({@see LargestRemainder::distribute()} guarantees this by construction;
     * this only fires on a genuine structural bug, e.g. a seller order with zero
     * matching `commerce_order_lines` rows) -- and the summed `attributed_total`
     * across every seller order must never EXCEED the chargeback's own amount. Any
     * shortfall (design spec §2.5 "or exceeds total seller allocations") becomes an
     * EXPLICIT marketplace-funded remainder, never silently assigned to a seller.
     *
     * @param array<string,mixed> $cb the freshly inserted `received` chargeback row
     * @return array<string,mixed> the updated row (`posted` or `integrity_hold`)
     */
    private function autoExpandFull(ApplicationContext $c, string $tenant, array $cb): array
    {
        $orderUuid = (string) $cb['order_uuid'];
        $amount = (int) $cb['amount'];

        $lineAmounts = $this->fullDistributionCeilings($c, $tenant, $orderUuid, (string) $cb['uuid']);
        $attributedTotalSum = array_sum($lineAmounts);

        if ($attributedTotalSum > $amount) {
            throw new LedgerException(sprintf(
                'Chargeback auto-expand integrity failure (%s): seller attributed_total sum %d exceeds '
                    . 'the chargeback amount %d.',
                (string) $cb['uuid'],
                $attributedTotalSum,
                $amount
            ));
        }

        $marketplaceGap = $amount - $attributedTotalSum;

        return $this->postAttributedLines($c, $tenant, $cb, $lineAmounts, 'received', $marketplaceGap);
    }

    /**
     * The FULL, historical-context-free auto-expand distribution (design spec
     * §2.5): for EVERY seller order on this order, `attributed_total` distributed
     * across its own lines via {@see LargestRemainder::distribute()}, weighted by
     * `max(0, line_total - discount_amount + tax_amount)`. This is a PURE function
     * of the order's own immutable structure -- it is what {@see self::autoExpandFull()}
     * posts VERBATIM for a full chargeback, AND it is the per-line CEILING
     * {@see self::postAttributedLines()}'s over-attribution cap subtracts prior
     * refund/chargeback history from for BOTH the full and partial path. Using
     * raw per-line weight as that ceiling would be WRONG whenever a seller order's
     * `attributed_total` exceeds the sum of its own lines' weights (shipping/tax
     * living at the seller-order level, not the line level, routinely inflates
     * each line's proportional cash share past its own raw weight) -- this method
     * is the single, consistent source of truth for "the most this line could
     * ever be attributed in one shot" both callers key off.
     *
     * Hard-assert (unconditional -- a "should never happen" internal-consistency
     * guardrail, never a soft business-condition classification): each seller
     * order's distributed line sum must equal its own `attributed_total` EXACTLY
     * ({@see LargestRemainder::distribute()} guarantees this by construction; this
     * only fires on a genuine structural bug, e.g. a seller order with zero
     * matching `commerce_order_lines` rows).
     *
     * @return array<string,int> order_line_uuid => ceiling amount
     */
    private function fullDistributionCeilings(
        ApplicationContext $c,
        string $tenant,
        string $orderUuid,
        string $chargebackUuidForErrors
    ): array {
        $sellerOrders = $this->sellerOrders->forOrder($c, $tenant, $orderUuid);

        /** @var array<string,int> $ceilings */
        $ceilings = [];

        foreach ($sellerOrders as $sellerOrder) {
            $sellerUuid = (string) $sellerOrder['seller_uuid'];
            $attributedTotal = (int) $sellerOrder['attributed_total'];

            $orderLines = db($c)->table('commerce_order_lines')
                ->where('order_uuid', '=', $orderUuid)
                ->where('seller_uuid', '=', $sellerUuid)
                ->orderBy('uuid', 'ASC')
                ->get();

            /** @var array<string,int> $weights */
            $weights = [];
            foreach ($orderLines as $orderLine) {
                $weights[(string) $orderLine['uuid']] = max(
                    0,
                    (int) $orderLine['line_total'] - (int) $orderLine['discount_amount']
                        + (int) $orderLine['tax_amount']
                );
            }

            $distributed = LargestRemainder::distribute($weights, $attributedTotal);

            $sellerLineSum = array_sum($distributed);
            if ($sellerLineSum !== $attributedTotal) {
                throw new LedgerException(sprintf(
                    'Chargeback auto-expand integrity failure (%s, seller %s): distributed line sum %d '
                        . "!= seller order's attributed_total %d.",
                    $chargebackUuidForErrors,
                    $sellerUuid,
                    $sellerLineSum,
                    $attributedTotal
                ));
            }

            foreach ($distributed as $lineUuid => $lineAmount) {
                $ceilings[$lineUuid] = $lineAmount;
            }
        }

        return $ceilings;
    }

    /**
     * The SHARED posting core (design spec §2.5) both {@see self::autoExpandFull()}
     * (full, `$fromStatus = 'received'`) and {@see self::attributeAndPost()} (partial,
     * `$fromStatus = 'awaiting_attribution'`) funnel into, always from inside the
     * caller's own open transaction:
     *
     *  1. Resolves each `order_line_uuid` in `$lineAmounts` against the order's own
     *     `commerce_order_lines` -- an unresolvable reference is a caller-input bug
     *     ({@see ChargebackAttributionException}), never silently dropped. A line
     *     with NO seller (`seller_uuid` is nullable) contributes its amount straight
     *     into the marketplace-funded remainder (design spec §2.5 "resolves to no
     *     seller line") and never gets a `commerce_chargeback_lines` row.
     *  2. **Over-attribution cap (CARRY-FORWARD from T10, design spec §2.5):** for
     *     every seller-attributed line, its {@see self::fullDistributionCeilings()}
     *     ceiling (its share of a from-scratch full auto-expand -- NOT its raw
     *     `line_total - discount_amount + tax_amount` weight, which undercounts
     *     once seller-order-level shipping/tax inflates the cash a line can carry)
     *     minus this order's prior COMPLETED `commerce_refund_lines` for that line
     *     minus prior POSTED `commerce_chargeback_lines` for that line (excluding
     *     this chargeback itself) is the line's own derived CASH remaining. If this
     *     chargeback's own line amount exceeds it -- e.g. a full chargeback arriving
     *     after a prior partial refund already reversed some of that line's cash --
     *     NOTHING posts: the chargeback transitions straight to `integrity_hold`
     *     (never a partial/adjusted posting, and never a thrown exception -- this is
     *     a business-DATA condition, the same "never guessed into a posting"
     *     discipline T10's own unresolvable/incoherent classification uses).
     *  3. Otherwise: persists the seller-attributed lines, computes each seller's
     *     `chargeback_debit` (the FULL attributed amount, uncapped -- reverses the
     *     same attributed proceeds credited at sale, shipping/tax included) and
     *     `commission_reversal` (capped to the cumulative merchandise-basis target,
     *     REUSING {@see LedgerPostingService::postRefund()}'s exact `target()`
     *     formula and `R_before`/`delta_R`/`R_after` shape -- just computed here
     *     against `commission_basis`, where `R_before` now also folds in prior
     *     POSTED chargeback lines, not only prior completed refund lines, so a
     *     refund and a chargeback against the SAME line can never each reverse the
     *     line's commission independently past its own single commission_amount).
     *  4. Claims every affected seller's account lock PLUS the marketplace lock,
     *     sorted `account_key` ascending (design spec §2.6 deadlock avoidance,
     *     mirroring {@see LedgerPostingService::postRefund()}'s identical discipline
     *     -- the marketplace lock is claimed UNCONDITIONALLY, even when its own
     *     remainder resolves to 0).
     *  5. Per seller, in that SAME sorted order, under that seller's now-held lock:
     *     {@see ReserveConsumptionService::consume()} for the NET liability (debit
     *     minus commission reversal) FIRST -- releasing held reserve BEFORE the
     *     debit lands -- then posts `chargeback_debit` in FULL (never truncated,
     *     design spec §2.6 -- `available` may go negative) and, if positive,
     *     `commission_reversal`.
     *  6. Any marketplace remainder (explicit auto-expand shortfall PLUS any
     *     no-seller line amounts) posts as a single marketplace `chargeback_debit`.
     *  7. Hard-assert: seller debits + marketplace remainder sum EXACTLY to the
     *     chargeback's own amount (a should-never-happen guardrail -- every amount
     *     fed in is accounted for by construction) -- checked BEFORE any lock is
     *     claimed or anything posts.
     *  8. Transitions the chargeback row to `posted` (`posted_at` stamped).
     *
     * @param array<string,mixed> $cb
     * @param array<string,int> $lineAmounts order_line_uuid => amount, already
     *   validated (by the caller) to sum to the chargeback's own amount MINUS
     *   `$explicitMarketplaceRemainder`
     * @return array<string,mixed> the updated row (`posted` or `integrity_hold`)
     */
    private function postAttributedLines(
        ApplicationContext $c,
        string $tenant,
        array $cb,
        array $lineAmounts,
        string $fromStatus,
        int $explicitMarketplaceRemainder = 0
    ): array {
        $chargebackUuid = (string) $cb['uuid'];
        $orderUuid = (string) $cb['order_uuid'];
        $currency = (string) $cb['currency'];
        $amount = (int) $cb['amount'];

        $orderLines = $this->orderLinesByUuid($c, $orderUuid, array_keys($lineAmounts));
        $lineCeilings = $this->fullDistributionCeilings($c, $tenant, $orderUuid, $chargebackUuid);
        $priorRefunded = $this->priorCompletedRefundedByLine($c, $tenant, $orderUuid);
        $priorChargedBack = $this->chargebacks->priorPostedChargedBackByLine(
            $c,
            $tenant,
            $orderUuid,
            $chargebackUuid
        );

        /** @var array<string,list<array{order_line_uuid:string,amount:int,basis:int,commission_amount:int,prior_cash:int}>> $sellerLines */
        $sellerLines = [];
        $marketplaceRemainder = $explicitMarketplaceRemainder;
        $overAttributed = false;

        foreach ($lineAmounts as $lineUuid => $lineAmount) {
            $orderLine = $orderLines[$lineUuid]
                ?? throw new ChargebackAttributionException(
                    "Chargeback attribution failure ({$chargebackUuid}): order line '{$lineUuid}' "
                        . "not found for order '{$orderUuid}'."
                );

            $sellerUuid = $orderLine['seller_uuid'] ?? null;
            $sellerUuid = ($sellerUuid === null || $sellerUuid === '') ? null : (string) $sellerUuid;

            if ($sellerUuid === null) {
                $marketplaceRemainder += $lineAmount;
                continue;
            }

            // Over-attribution cap (CARRY-FORWARD from T10, design spec §2.5): the
            // line's own CEILING (its share of a from-scratch full auto-expand,
            // {@see self::fullDistributionCeilings()} -- NOT its raw weight, which
            // routinely undercounts once seller-order-level shipping/tax is spread
            // across lines) minus prior COMPLETED refund cash minus prior POSTED
            // chargeback cash for that SAME line is what remains attributable.
            $ceiling = $lineCeilings[$lineUuid] ?? 0;
            $priorCash = ($priorRefunded[$lineUuid] ?? 0) + ($priorChargedBack[$lineUuid] ?? 0);
            $remainingCash = max(0, $ceiling - $priorCash);

            if ($lineAmount > $remainingCash) {
                $overAttributed = true;
            }

            $sellerLines[$sellerUuid][] = [
                'order_line_uuid' => $lineUuid,
                'amount' => $lineAmount,
                'basis' => (int) $orderLine['commission_basis'],
                'commission_amount' => (int) $orderLine['commission_amount'],
                'prior_cash' => $priorCash,
            ];
        }

        if ($overAttributed) {
            return $this->chargebacks->transitionStatus($c, $tenant, $chargebackUuid, $fromStatus, 'integrity_hold');
        }

        /** @var list<array{order_line_uuid:string, seller_uuid:string, amount:int}> $linesToPersist */
        $linesToPersist = [];
        foreach ($sellerLines as $sellerUuid => $lines) {
            foreach ($lines as $line) {
                $linesToPersist[] = [
                    'order_line_uuid' => $line['order_line_uuid'],
                    'seller_uuid' => $sellerUuid,
                    'amount' => $line['amount'],
                ];
            }
        }

        /** @var array<string,int> $sellerDebit */
        $sellerDebit = [];
        /** @var array<string,int> $sellerReversal */
        $sellerReversal = [];
        foreach ($sellerLines as $sellerUuid => $lines) {
            foreach ($lines as $line) {
                $basis = $line['basis'];
                $commissionAmount = $line['commission_amount'];
                $rBefore = min($basis, $line['prior_cash']);
                $deltaR = min($line['amount'], max(0, $basis - $rBefore));
                $rAfter = $rBefore + $deltaR;
                $targetBefore = self::target($commissionAmount, $basis, $rBefore);
                $targetAfter = self::target($commissionAmount, $basis, $rAfter);

                $sellerDebit[$sellerUuid] = ($sellerDebit[$sellerUuid] ?? 0) + $line['amount'];
                $sellerReversal[$sellerUuid] = ($sellerReversal[$sellerUuid] ?? 0) + ($targetAfter - $targetBefore);
            }
        }

        $totalAssigned = array_sum($sellerDebit) + $marketplaceRemainder;
        if ($totalAssigned !== $amount) {
            throw new LedgerException(sprintf(
                'Chargeback posting integrity failure (%s): assigned total %d != chargeback amount %d.',
                $chargebackUuid,
                $totalAssigned,
                $amount
            ));
        }

        $this->chargebacks->insertLines($c, $tenant, $chargebackUuid, $linesToPersist);

        /** @var list<array{account_key:string, seller_uuid:?string}> $accounts */
        $accounts = [];
        foreach (array_keys($sellerDebit) as $sellerUuid) {
            $accounts[] = [
                'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
                'seller_uuid' => $sellerUuid,
            ];
        }
        $accounts[] = ['account_key' => LedgerRepository::MARKETPLACE_ACCOUNT_KEY, 'seller_uuid' => null];
        usort($accounts, static fn (array $a, array $b): int => $a['account_key'] <=> $b['account_key']);

        foreach ($accounts as $account) {
            $this->lock->claim($c, $tenant, $account['account_key'], $currency);
        }

        foreach ($accounts as $account) {
            if ($account['seller_uuid'] === null) {
                continue;
            }

            $sellerUuid = $account['seller_uuid'];
            $debit = $sellerDebit[$sellerUuid];
            $reversal = $sellerReversal[$sellerUuid] ?? 0;
            $netLiability = $debit - $reversal;

            // Reserve-first (design spec §2.5/§2.6): consumed BEFORE the debit
            // lands, under this seller's already-claimed lock.
            $this->reserveConsumption->consume(
                $c,
                $tenant,
                $sellerUuid,
                $currency,
                $netLiability,
                'chargeback',
                $chargebackUuid
            );

            if ($debit > 0) {
                $this->ledger->post($c, $tenant, [
                    'account_kind' => 'seller',
                    'account_key' => $account['account_key'],
                    'seller_uuid' => $sellerUuid,
                    'currency' => $currency,
                    'entry_type' => 'chargeback_debit',
                    'amount' => -$debit,
                    'order_uuid' => $orderUuid,
                    'chargeback_uuid' => $chargebackUuid,
                    'idempotency_key' => "{$chargebackUuid}:{$sellerUuid}:chargeback_debit",
                ]);
            }

            if ($reversal > 0) {
                $this->ledger->post($c, $tenant, [
                    'account_kind' => 'seller',
                    'account_key' => $account['account_key'],
                    'seller_uuid' => $sellerUuid,
                    'currency' => $currency,
                    'entry_type' => 'commission_reversal',
                    'amount' => $reversal,
                    'order_uuid' => $orderUuid,
                    'chargeback_uuid' => $chargebackUuid,
                    'idempotency_key' => "{$chargebackUuid}:{$sellerUuid}:commission_reversal",
                ]);
            }
        }

        if ($marketplaceRemainder > 0) {
            $this->ledger->post($c, $tenant, [
                'account_kind' => 'marketplace',
                'account_key' => LedgerRepository::MARKETPLACE_ACCOUNT_KEY,
                'seller_uuid' => null,
                'currency' => $currency,
                'entry_type' => 'chargeback_debit',
                'amount' => -$marketplaceRemainder,
                'order_uuid' => $orderUuid,
                'chargeback_uuid' => $chargebackUuid,
                'idempotency_key' => "{$chargebackUuid}:marketplace:chargeback_debit",
            ]);
        }

        return $this->chargebacks->transitionStatus(
            $c,
            $tenant,
            $chargebackUuid,
            $fromStatus,
            'posted',
            db($c)->getDriver()->formatDateTime()
        );
    }

    /**
     * The cumulative-target formula, REUSED VERBATIM from
     * {@see LedgerPostingService::target()} (design spec §2.5 "reuse that exact
     * cap so commission can't over-reverse"): `0` when the line carries no basis
     * at all, else `min(C, intdiv(C*R + intdiv(B,2), B))` -- half-up rounding, the
     * same house idiom -- capped at the line's own original commission so an `R`
     * at or above `B` never reverses more than `C`.
     */
    private static function target(int $commissionAmount, int $basis, int $cumulativeReversed): int
    {
        if ($basis === 0) {
            return 0;
        }

        return min(
            $commissionAmount,
            intdiv($commissionAmount * $cumulativeReversed + intdiv($basis, 2), $basis)
        );
    }

    /**
     * SUM of `commerce_refund_lines.amount` for each order line across every
     * COMPLETED refund on the order (design spec §2.5's refund-side half of the
     * shared "remaining after earlier chargebacks/refunds" cap) -- mirrors
     * {@see MarketplaceRefundGuard::completedAmountByLine()}'s identical
     * "COMPLETED only, one batched query" discipline exactly (a `pending` refund
     * never actually reversed anything, so it must never count here).
     *
     * @return array<string,int> order_line_uuid => already-refunded amount
     */
    private function priorCompletedRefundedByLine(ApplicationContext $c, string $tenant, string $orderUuid): array
    {
        $rows = db($c)->table('commerce_refund_lines')
            ->join('commerce_refunds', 'commerce_refund_lines.refund_uuid', '=', 'commerce_refunds.uuid')
            ->select(['commerce_refund_lines.order_line_uuid', 'commerce_refund_lines.amount'])
            ->where('commerce_refunds.tenant_uuid', '=', $tenant)
            ->where('commerce_refunds.order_uuid', '=', $orderUuid)
            ->where('commerce_refunds.status', '=', 'completed')
            ->get();

        $sums = [];
        foreach ($rows as $row) {
            $key = (string) $row['order_line_uuid'];
            $sums[$key] = ($sums[$key] ?? 0) + (int) $row['amount'];
        }

        return $sums;
    }

    /**
     * @param list<string> $orderLineUuids
     * @return array<string,array<string,mixed>> order_line_uuid => row
     */
    private function orderLinesByUuid(ApplicationContext $c, string $orderUuid, array $orderLineUuids): array
    {
        if ($orderLineUuids === []) {
            return [];
        }

        $rows = db($c)->table('commerce_order_lines')
            ->where('order_uuid', '=', $orderUuid)
            ->whereIn('uuid', array_values(array_unique($orderLineUuids)))
            ->get();

        $byUuid = [];
        foreach ($rows as $row) {
            $byUuid[(string) $row['uuid']] = $row;
        }

        return $byUuid;
    }

    /**
     * Resolves the event's payable to an order and validates general
     * coherence -- supported payable type, order exists in this tenant,
     * currency matches, amount does not exceed the order's original charge
     * -- then applies the design spec §2.4/§2.5 classification. The payable
     * tuple is always revalidated against the freshly-read, immutable
     * historical order; nothing here trusts the event's own fields beyond
     * using them as lookup keys.
     *
     * @return array<string,mixed> either `['status' => 'ignored', 'ignored_reason'
     *     => string]` (a non-partitioned order -- no row is ever inserted), or
     *     `['status' => 'eligible', 'order_uuid' => string|null, 'initial_status'
     *     => string]` (persisted event-first regardless of resolvability).
     */
    private function resolve(ApplicationContext $c, string $tenant, ProviderChargebackEvent $event): array
    {
        $order = $event->payable->type === self::SUPPORTED_PAYABLE_TYPE
            ? $this->orders->findByUuid($c, $tenant, $event->payable->id)
            : null;

        $coherent = $order !== null
            && (string) $order['currency'] === $event->currency
            && $event->amount <= (int) $order['grand_total'];

        if (!$coherent) {
            // Unresolvable/incoherent -- STILL persists event-first (unlike the
            // non-partitioned case below), as `integrity_hold`. order_uuid is
            // whatever was resolvable (null for an unsupported payable type or
            // an unknown order; the real uuid for a currency/amount mismatch).
            return [
                'status' => 'eligible',
                'order_uuid' => $order !== null ? (string) $order['uuid'] : null,
                'initial_status' => 'integrity_hold',
            ];
        }

        // Historical partition authority (design spec §2.11, Global
        // Constraints): the order's OWN immutable flag, never current
        // MarketplaceMode::activeFor(). A non-partitioned order is outside
        // MV5a entirely -- no marketplace chargeback row, ever.
        if (!((bool) ($order['marketplace_partitioned'] ?? false))) {
            return ['status' => 'ignored', 'ignored_reason' => 'order_not_partitioned'];
        }

        $orderUuid = (string) $order['uuid'];

        if ($event->kind === ProviderChargebackEvent::KIND_REVERSAL) {
            // Task 14 seam (see class docblock): classified/persisted, never
            // posted or correlated to its original chargeback here.
            return ['status' => 'eligible', 'order_uuid' => $orderUuid, 'initial_status' => 'received'];
        }

        // Full vs partial (design spec §2.5): compared against the ORDER's own
        // grand_total -- the original captured/charge amount -- never against
        // a refunded-net "remaining" figure (a chargeback disputes the
        // original charge, not what's currently left refundable).
        $isFull = $event->amount === (int) $order['grand_total'];

        return [
            'status' => 'eligible',
            'order_uuid' => $orderUuid,
            'initial_status' => $isFull ? 'received' : 'awaiting_attribution',
        ];
    }
}
