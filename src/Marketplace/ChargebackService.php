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
 * **Scope -- posting is Task 11, reversal correlation + compensation is Task
 * 14 (both DONE, this class).** This class INGESTS, CLASSIFIES, and (MV5a
 * Task 11) POSTS:
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
 *    `related_chargeback_uuid` is deliberately left `null` at insert time --
 *    it is NEVER routed into {@see self::autoExpandFull()} (that path is
 *    chargeback-kind only). Instead (MV5a Task 14, design spec §2.10), a
 *    freshly-`received` reversal is handed to
 *    {@see self::postReversalCompensation()}, which resolves `relatedEventId`
 *    under `(tenant, provider, provider_event_id)` into the internal
 *    `related_chargeback_uuid`, posts the compensating
 *    `chargeback_credit`/`commission_debit` per attributed seller of the
 *    original (capped so cumulative compensation never exceeds the
 *    original's own postings), and reinstates any still-unexpired reserve
 *    the original consumed -- see that method's own docblock for the full
 *    contract. It never guesses reversal semantics: an unknown/cross-provider
 *    relation, an original that isn't yet `posted`, or an over-amount/
 *    regressing cumulative observation all land on `integrity_hold`, never a
 *    fabricated post.
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
                // Task 14 review fix: the RAW relatedEventId, passed through
                // (never persisted as a column) so ChargebackRepository::insert()
                // can verify a conflicting replay's relation on a duplicate-key
                // hit -- see ChargebackRepository::verifyReversalRelation().
                'related_event_id' => $event->kind === ProviderChargebackEvent::KIND_REVERSAL
                    ? $event->relatedEventId
                    : null,
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

            // MV5a Task 14 (design spec §2.10): a fresh, resolvable, coherent,
            // partitioned REVERSAL -- `received` is likewise ONLY ever the
            // initial_status for a first-time resolvable reversal (see
            // self::resolve()) -- is correlated to its original and, if
            // postable, compensated right here, in this SAME transaction. A
            // REPLAY never re-enters this branch either, for the identical
            // reason the full-chargeback branch above never does.
            if ($row['status'] === 'received' && $event->kind === ProviderChargebackEvent::KIND_REVERSAL) {
                $row = $this->postReversalCompensation(
                    $c,
                    $tenant,
                    $row,
                    (string) $event->relatedEventId,
                    $event->provider
                );
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
     * MV5a Task 14 (design spec §2.10): posts the compensating credit for a
     * `kind=reversal` chargeback event -- a provider-reported dispute WIN or
     * re-credit -- WITHOUT ever mutating or deleting the original chargeback's
     * own rows. Always called from `ingest()`'s open transaction, for a
     * freshly-inserted `received` reversal row only (a replay never re-enters,
     * mirroring {@see self::autoExpandFull()}'s identical discipline).
     *
     * **Relation resolution (load-bearing, never a guessed uuid):**
     * `$relatedEventId` is looked up under `(tenant, $provider, relatedEventId)`
     * -- the SAME `(tenant, provider, provider_event_id)` idempotency tuple
     * every chargeback is keyed by, so a lookup scoped to the reversal's OWN
     * `provider` can never cross-match a same-string event id filed under a
     * DIFFERENT provider (design spec §2.10 "cross-provider... never a guessed
     * uuid" falls out of this WHERE clause for free, no separate branch
     * needed). Three outcomes:
     *  - **no matching row, or a matching row that isn't itself `kind=chargeback`**:
     *    genuinely UNKNOWN -- `related_chargeback_uuid` stays `null` forever,
     *    `integrity_hold`;
     *  - **a matching `kind=chargeback` row that isn't yet `status=posted`**
     *    (still `awaiting_attribution`, or itself `integrity_hold`): the
     *    relation IS known and IS persisted (a later repair path can find it),
     *    but nothing postable exists yet -- `integrity_hold`, no money moves;
     *  - **a matching `posted` `kind=chargeback` row**: proceeds to compensate.
     *
     * **Per-seller ceiling + cumulative cap (design spec §2.10 "cumulative
     * compensation... may not exceed the original's postings, per seller and
     * in total"):** {@see LedgerRepository::sellerEntryTotalsForChargeback()}
     * reads, in ONE grouped scan, every seller's ORIGINAL `chargeback_debit`
     * (`D`) / `commission_reversal` (`C`) AND every EARLIER reversal's
     * `chargeback_credit` already posted against this SAME original (`P`,
     * `chargeback_uuid` on every reversal-driven entry is deliberately the
     * ORIGINAL's own uuid -- see class-level design note below) -- because all
     * four entry types share that one correlation column, exactly mirroring
     * how `order_uuid` already groups a whole order's lifecycle. Each seller's
     * remaining room is `max(0, D - P)`.
     *
     * **Marketplace-remainder inclusion (fix, MV5a Task 14 review, design
     * spec §2.10 "may not exceed the original chargeback's postings"):** the
     * original's own marketplace-funded unattributable remainder (design
     * spec §2.5, {@see self::postAttributedLines()}'s `$marketplaceRemainder`)
     * is a genuine original POSTING too -- {@see LedgerRepository::marketplaceEntryTotalsForChargeback()}
     * reads that SAME `chargeback_debit`/`chargeback_credit` pair, scoped to
     * `account_kind = 'marketplace'` instead of a seller, and its own
     * remaining room (`max(0, marketplace D - marketplace P)`) is folded
     * into the SAME weights map the sellers' rooms live in, under the
     * reserved {@see LedgerRepository::MARKETPLACE_ACCOUNT_KEY} sentinel
     * (never a real seller uuid, so it can never collide). A provider
     * reverses the FULL disputed amount with no knowledge of the internal
     * seller/marketplace split; capping against sellers alone would strand
     * every remainder-bearing chargeback's full reversal in `integrity_hold`
     * forever, even though the original's TOTAL postings (sellers +
     * marketplace) fully cover it.
     *
     * This combined weights map's SUM is `$totalCap`; this event's own
     * `amount` is validated against it BEFORE claiming a single lock or
     * posting a single entry (mirrors {@see self::postAttributedLines()}'s
     * over-attribution guard) -- `amount` exceeding that sum (a GENUINE
     * over-amount, now measured against the original's true total, or a
     * regressing observation) is an integrity finding: `integrity_hold`,
     * NEVER a fabricated positive post, not even a truncated one.
     *
     * **Distribution:** {@see LargestRemainder::distribute()} proportions this
     * event's own `amount` across every weight (sellers AND, when positive,
     * the marketplace) -- because `amount <= sum(weights)` is already
     * guaranteed by the cap check above, the algorithm's own floor+largest-
     * remainder construction can mathematically never allocate any ONE key
     * more than ITS OWN weight (a `total <= sum(weights)` invariant proof,
     * not merely an empirical observation), so no key's own cap needs a
     * separate clamping step. A PARTIAL reversal therefore distributes
     * proportionally across whichever components still have remaining room,
     * seller and marketplace alike.
     *
     * **Posting, per credited account, under that account's account-key-
     * sorted {@see LedgerAccountLock}:**
     *  - every credited SELLER gets `chargeback_credit = +(this event's
     *    seller share)` (undoes the original `chargeback_debit`),
     *    `commission_debit = -(commission re-application)` computed via
     *    {@see self::target()} REUSED VERBATIM against cumulative `P`/`P +
     *    thisShare` (the identical `R_before`/`R_after` cumulative-cap shape
     *    {@see self::postAttributedLines()} already uses at line-level, just
     *    applied at seller-level here, automatically capped at `C` in total
     *    no matter how many separate reversal events eventually cover the
     *    full original), and {@see ReserveConsumptionService::reinstate()}
     *    (reinstates whatever still-unexpired reserve protection the
     *    ORIGINAL chargeback consumed, bounded by this event's own posted
     *    credit -- see that method's own docblock for the full three-way cap);
     *  - the MARKETPLACE, when its own remaining room is positive and this
     *    event's distribution credits it, gets ONLY a mirrored
     *    `chargeback_credit = +(this event's marketplace share)` under the
     *    marketplace account's own lock (the marketplace's original
     *    remainder never carried a commission entry, so there is nothing to
     *    re-apply, and the marketplace never holds a reserve, so
     *    {@see ReserveConsumptionService::reinstate()} is never called for it).
     *
     * Every reversal-driven ledger entry's `chargeback_uuid` is the ORIGINAL's
     * own uuid (not this reversal row's uuid) -- entry_type is what
     * disambiguates a reversal's compensating rows from the original's own
     * rows, the SAME "one correlation id, many entry types" convention
     * `order_uuid` already establishes. Every idempotency_key embeds THIS
     * reversal's own uuid, so each discrete reversal event's postings stay
     * independently unique and replay-safe regardless of what they share a
     * `chargeback_uuid` with.
     *
     * @param array<string,mixed> $cb the freshly inserted `received` reversal row
     * @return array<string,mixed> the updated row (`posted` or `integrity_hold`)
     */
    private function postReversalCompensation(
        ApplicationContext $c,
        string $tenant,
        array $cb,
        string $relatedEventId,
        string $provider
    ): array {
        $reversalUuid = (string) $cb['uuid'];
        $currency = (string) $cb['currency'];
        $amount = (int) $cb['amount'];

        $original = $this->chargebacks->findByProviderEvent($c, $tenant, $provider, $relatedEventId);

        if ($original === null || (string) $original['kind'] !== 'chargeback') {
            // Genuinely unknown / cross-provider / mistargeted relation --
            // NEVER a guessed uuid (design spec §2.10).
            return $this->chargebacks->transitionStatus($c, $tenant, $reversalUuid, 'received', 'integrity_hold');
        }

        $originalUuid = (string) $original['uuid'];

        if ((string) $original['status'] !== 'posted') {
            // The relation IS known -- persisted for a later repair path --
            // but there is nothing posted yet to compensate against.
            return $this->chargebacks->transitionStatus(
                $c,
                $tenant,
                $reversalUuid,
                'received',
                'integrity_hold',
                null,
                ['related_chargeback_uuid' => $originalUuid]
            );
        }

        $totals = $this->ledger->sellerEntryTotalsForChargeback($c, $tenant, $originalUuid, [
            'chargeback_debit',
            'commission_reversal',
            'chargeback_credit',
        ]);

        /** @var array<string,int> $remainingCap seller_uuid => remaining compensable room */
        $remainingCap = [];
        foreach ($totals as $sellerUuid => $sellerTotals) {
            $debit = abs($sellerTotals['chargeback_debit'] ?? 0);
            if ($debit <= 0) {
                continue;
            }
            $priorCompensated = $sellerTotals['chargeback_credit'] ?? 0;
            $remainingCap[$sellerUuid] = max(0, $debit - $priorCompensated);
        }

        // Marketplace-remainder inclusion (fix, MV5a Task 14 review, design
        // spec §2.10): the original's own marketplace-funded unattributable
        // remainder is a genuine original POSTING too -- folded into the
        // SAME weights map under a reserved sentinel key that can never
        // collide with a real seller uuid.
        $marketplaceTotals = $this->ledger->marketplaceEntryTotalsForChargeback($c, $tenant, $originalUuid, [
            'chargeback_debit',
            'chargeback_credit',
        ]);
        $marketplaceDebit = abs($marketplaceTotals['chargeback_debit'] ?? 0);
        $marketplacePriorCompensated = $marketplaceTotals['chargeback_credit'] ?? 0;
        $marketplaceRemainingCap = max(0, $marketplaceDebit - $marketplacePriorCompensated);

        $capWeights = $remainingCap;
        if ($marketplaceRemainingCap > 0) {
            $capWeights[LedgerRepository::MARKETPLACE_ACCOUNT_KEY] = $marketplaceRemainingCap;
        }

        $totalCap = array_sum($capWeights);
        if ($capWeights === [] || $amount > $totalCap) {
            // Over-amount / regressing (design spec §2.10) -- integrity
            // finding, never a fabricated positive post, not even a truncated
            // one.
            return $this->chargebacks->transitionStatus(
                $c,
                $tenant,
                $reversalUuid,
                'received',
                'integrity_hold',
                null,
                ['related_chargeback_uuid' => $originalUuid]
            );
        }

        $creditByAccount = LargestRemainder::distribute($capWeights, $amount);

        $accounts = [];
        foreach ($creditByAccount as $key => $creditAmount) {
            if ($creditAmount <= 0) {
                continue;
            }
            $accounts[] = $key === LedgerRepository::MARKETPLACE_ACCOUNT_KEY
                ? ['account_key' => LedgerRepository::MARKETPLACE_ACCOUNT_KEY, 'seller_uuid' => null]
                : ['account_key' => LedgerRepository::accountKeyForSeller($key), 'seller_uuid' => $key];
        }
        usort($accounts, static fn (array $a, array $b): int => $a['account_key'] <=> $b['account_key']);

        foreach ($accounts as $account) {
            $this->lock->claim($c, $tenant, $account['account_key'], $currency);
        }

        foreach ($accounts as $account) {
            if ($account['seller_uuid'] === null) {
                // The marketplace leg: only a mirrored `chargeback_credit`
                // (design spec §2.10 fix) -- no commission entry (the
                // original remainder never carried one) and no reserve
                // reinstatement (the marketplace never holds a reserve).
                $creditAmount = $creditByAccount[LedgerRepository::MARKETPLACE_ACCOUNT_KEY];

                $this->ledger->post($c, $tenant, [
                    'account_kind' => 'marketplace',
                    'account_key' => LedgerRepository::MARKETPLACE_ACCOUNT_KEY,
                    'seller_uuid' => null,
                    'currency' => $currency,
                    'entry_type' => 'chargeback_credit',
                    'amount' => $creditAmount,
                    'order_uuid' => $original['order_uuid'] ?? null,
                    'chargeback_uuid' => $originalUuid,
                    'idempotency_key' => "{$reversalUuid}:marketplace:chargeback_credit",
                ]);

                continue;
            }

            $sellerUuid = $account['seller_uuid'];
            $creditAmount = $creditByAccount[$sellerUuid];
            $sellerTotals = $totals[$sellerUuid] ?? [];
            $basis = abs($sellerTotals['chargeback_debit'] ?? 0);
            $commissionCredited = $sellerTotals['commission_reversal'] ?? 0;
            $priorCompensated = $sellerTotals['chargeback_credit'] ?? 0;

            $rBefore = $priorCompensated;
            $rAfter = $priorCompensated + $creditAmount;
            $commissionDebit = self::target($commissionCredited, $basis, $rAfter)
                - self::target($commissionCredited, $basis, $rBefore);

            $this->ledger->post($c, $tenant, [
                'account_kind' => 'seller',
                'account_key' => $account['account_key'],
                'seller_uuid' => $sellerUuid,
                'currency' => $currency,
                'entry_type' => 'chargeback_credit',
                'amount' => $creditAmount,
                'order_uuid' => $original['order_uuid'] ?? null,
                'chargeback_uuid' => $originalUuid,
                'idempotency_key' => "{$reversalUuid}:{$sellerUuid}:chargeback_credit",
            ]);

            if ($commissionDebit > 0) {
                $this->ledger->post($c, $tenant, [
                    'account_kind' => 'seller',
                    'account_key' => $account['account_key'],
                    'seller_uuid' => $sellerUuid,
                    'currency' => $currency,
                    'entry_type' => 'commission_debit',
                    'amount' => -$commissionDebit,
                    'order_uuid' => $original['order_uuid'] ?? null,
                    'chargeback_uuid' => $originalUuid,
                    'idempotency_key' => "{$reversalUuid}:{$sellerUuid}:commission_debit",
                ]);
            }

            $this->reserveConsumption->reinstate(
                $c,
                $tenant,
                $sellerUuid,
                $currency,
                $creditAmount,
                $originalUuid,
                $reversalUuid
            );
        }

        return $this->chargebacks->transitionStatus(
            $c,
            $tenant,
            $reversalUuid,
            'received',
            'posted',
            db($c)->getDriver()->formatDateTime(),
            ['related_chargeback_uuid' => $originalUuid]
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
