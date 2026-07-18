<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Atomic payment-side settlement posting (design spec §2.7, MV3 Task 6): for
 * a `marketplace_partitioned` order, {@see
 * \Glueful\Extensions\Commerce\Orders\OrderPaymentService::markPaid()} calls
 * {@see self::postSale()} from INSIDE its own transaction -- after
 * {@see SellerOrderPaymentConfirmation::confirm()} and before the
 * `OrderPaid` after-commit dispatch -- so the parent's
 * `pending_payment -> paid` CAS, the confirmation stamp, and every seller's
 * ledger posting share ONE commit: any {@see LedgerException} (or any other
 * throw) rolls the whole transaction back, never leaving a partial posting
 * set. A non-partitioned order never reaches this class at all (the
 * caller's own gate on its OWN `marketplace_partitioned` snapshot), so this
 * class issues zero queries in that case by construction, not by an
 * internal check here.
 */
final class LedgerPostingService
{
    public function __construct(
        private LedgerRepository $ledger,
        private LedgerAccountLock $lock,
    ) {
    }

    /**
     * Per participating seller, sorted by `account_key` ascending (design
     * spec §2.6 lock order -- deadlock avoidance across a multi-seller
     * payment, regardless of the caller's own row order): claim that
     * seller's account lock, then post `sale_credit = +attributed_total`
     * and `commission_debit = -commission_amount`. Deterministic
     * idempotency keys (`{order_uuid}:{seller_uuid}:{entry_type}`) make a
     * replay of this method (e.g. `markPaid()` somehow re-entering, or a
     * test calling it twice directly) a no-op via
     * {@see LedgerRepository::post()}'s own verify -- never a duplicate row.
     *
     * Zero-amount postings are skipped: `sale_credit` never posts for a
     * non-positive `attributed_total` (should never happen for a real paid
     * order, but validating that is not this class's job), and
     * `commission_debit` never posts for a zero `commission_amount` -- a
     * zero-amount commission entry would be pure ledger noise the Task 10
     * reconciliation scan would then have to specially ignore, so it is
     * never written in the first place.
     *
     * @param array<string,mixed> $order the parent order row (only `uuid` is read)
     * @param list<array{
     *     uuid: string,
     *     seller_uuid: string,
     *     currency: string,
     *     attributed_total: int,
     *     commission_amount?: int
     * }> $sellerOrders every `commerce_seller_orders` child of `$order`
     *     ({@see SellerOrderRepository::forOrder()})
     */
    public function postSale(ApplicationContext $context, string $tenant, array $order, array $sellerOrders): void
    {
        $orderUuid = (string) $order['uuid'];

        $sorted = $sellerOrders;
        usort($sorted, static function (array $a, array $b): int {
            $keyA = LedgerRepository::accountKeyForSeller((string) $a['seller_uuid']);
            $keyB = LedgerRepository::accountKeyForSeller((string) $b['seller_uuid']);

            return $keyA <=> $keyB ?: ((string) $a['currency']) <=> ((string) $b['currency']);
        });

        foreach ($sorted as $sellerOrder) {
            $this->postOneSeller($context, $tenant, $orderUuid, $sellerOrder);
        }
    }

    /** @param array<string,mixed> $sellerOrder */
    private function postOneSeller(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        array $sellerOrder
    ): void {
        $sellerUuid = (string) $sellerOrder['seller_uuid'];
        $currency = (string) $sellerOrder['currency'];
        $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);
        $sellerOrderUuid = (string) $sellerOrder['uuid'];
        $attributedTotal = (int) $sellerOrder['attributed_total'];
        $commissionAmount = (int) ($sellerOrder['commission_amount'] ?? 0);

        $this->lock->claim($context, $tenant, $accountKey, $currency);

        if ($attributedTotal > 0) {
            $this->ledger->post($context, $tenant, [
                'account_kind' => 'seller',
                'account_key' => $accountKey,
                'seller_uuid' => $sellerUuid,
                'currency' => $currency,
                'entry_type' => 'sale_credit',
                'amount' => $attributedTotal,
                'order_uuid' => $orderUuid,
                'seller_order_uuid' => $sellerOrderUuid,
                'idempotency_key' => "{$orderUuid}:{$sellerUuid}:sale_credit",
            ]);
        }

        if ($commissionAmount > 0) {
            $this->ledger->post($context, $tenant, [
                'account_kind' => 'seller',
                'account_key' => $accountKey,
                'seller_uuid' => $sellerUuid,
                'currency' => $currency,
                'entry_type' => 'commission_debit',
                'amount' => -$commissionAmount,
                'order_uuid' => $orderUuid,
                'seller_order_uuid' => $sellerOrderUuid,
                'idempotency_key' => "{$orderUuid}:{$sellerUuid}:commission_debit",
            ]);
        }
    }

    /**
     * Refund-side settlement posting (design spec §2.8, MV3 Task 7): called
     * from {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundService::applyCompletion()}
     * INSIDE its own transaction -- immediately after the `refunded_total`
     * CAS -- for a `marketplace_partitioned` order only. `$persistedRefundLines`
     * is exactly what {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository}
     * already persisted for this refund (the manual path's freshly-inserted
     * rows, or the gateway path's `linesFor()` re-read); this method never
     * re-derives attribution, only the money math on top of it.
     *
     * **`delta_R` (the merchandise-capped contribution of THIS refund to
     * each line) is computed here, at completion time, never at validation
     * time** -- design spec §2.8's explicit reason: a gateway refund can sit
     * `pending` for an arbitrary time, during which OTHER refunds against
     * the SAME line may complete and change the true cumulative-refunded
     * picture. For each refund line, joined to its immutable order-line
     * commission snapshot (`B` = `commission_basis`, `C` = `commission_amount`):
     *
     *  - `R_before = min(B, Σ prior COMPLETED refund_lines.amount for this
     *    order_line, EXCLUDING this refund's own uuid)`. This is
     *    mathematically exact (not an approximation): each prior refund's own
     *    `delta_R_i = min(a_i, max(0, B - R_{i-1}))` makes the running `R`
     *    monotonically non-decreasing and capped at `B`, so by induction
     *    `R_n = min(B, Σ a_i)` regardless of how many prior refunds
     *    contributed or in what order.
     *  - `delta_R = min(refund_line.amount, max(0, B - R_before))`,
     *    `R_after = R_before + delta_R`.
     *  - `target(R) = 0` when `B = 0`, else `min(C, intdiv(C*R +
     *    intdiv(B,2), B))` (half-up). This refund's commission reversal for
     *    the line is `target(R_after) - target(R_before)`.
     *
     * Grouped by seller (via each line's immutable `seller_uuid` snapshot):
     * `refund_debit = -Σ delta_R` (idempotency `{refund}:{seller}:refund_debit`),
     * `commission_reversal = +Σ (target_after - target_before)` (idempotency
     * `{refund}:{seller}:commission_reversal`). Any `refund_line.amount -
     * delta_R` (an over-large/tax-inclusive per-line amount that pushed past
     * the line's own remaining basis) PLUS any `refund.amount - Σ
     * refund_lines.amount` (an amount the caller never attributed to any
     * line at all -- typically shipping/tax) posts to the marketplace
     * account as a single `refund_debit` (idempotency
     * `{refund}:marketplace:refund_debit`). By construction
     * `Σ |seller refund_debit| + |marketplace refund_debit| = refund.amount`
     * exactly.
     *
     * Two guardrails enforce this, both meant to be unreachable given a
     * well-behaved caller ({@see MarketplaceRefundGuard} caps the
     * auto-expand path's `Σ lines` at `amount`, and {@see
     * \Glueful\Extensions\Commerce\Orders\Refunds\RefundService::validateLines()}
     * does the same for the manual path) but real enough to actually FIRE if
     * that ever regresses, mirroring {@see CommissionCalculator::perSeller()}'s
     * "unreachable by construction, kept as a guardrail" discipline:
     *
     *  - If the marketplace remainder (`refund.amount - Σ delta_R`) is
     *    NEGATIVE -- i.e. the lines attributed more merchandise basis than
     *    the refund's own cash covers -- that is an integrity failure and
     *    throws {@see LedgerException} before anything posts, rather than
     *    silently skipping the marketplace leg (its `> 0` posting guard)
     *    and over-debiting the seller for cash that was never refunded.
     *  - After posting, the amounts ACTUALLY passed to {@see
     *    LedgerRepository::post()} for seller `refund_debit` plus the
     *    marketplace `refund_debit` (zero/skipped legs contribute zero) are
     *    hard-asserted to sum to exactly `refund.amount`. Unlike a check
     *    computed purely from the pre-posting `delta_R`/`lineAmount`
     *    arithmetic (which is a tautology of its own construction and can
     *    never fire), this compares the posting loop's real side effects
     *    against the refund's independent cash amount, so a future
     *    regression in the posting loop itself (wrong account, dropped leg,
     *    wrong variable) is caught here.
     *
     * Every affected seller account lock plus the marketplace account lock
     * are claimed in sorted `account_key` order (§2.6 deadlock avoidance) --
     * a seller is "affected" whenever ANY of this refund's lines belongs to
     * it, even if that line's own `delta_R`/reversal both resolve to zero
     * (already fully reversed by an earlier refund). Zero-amount postings
     * are skipped, mirroring {@see postSale()}'s own discipline. Any thrown
     * {@see LedgerException} (or any other throw) propagates out and rolls
     * back the WHOLE transaction -- the CAS, the refund row itself (manual
     * path), and every already-claimed lock/posting together.
     *
     * @param array<string,mixed> $order the parent order row (only `uuid` is read)
     * @param array<string,mixed> $refund the refund row (`uuid`, `amount`, `currency` are read)
     * @param list<array{order_line_uuid:string,quantity:int,amount:int}> $persistedRefundLines
     */
    public function postRefund(
        ApplicationContext $context,
        string $tenant,
        array $order,
        array $refund,
        array $persistedRefundLines
    ): void {
        $orderUuid = (string) $order['uuid'];
        $refundUuid = (string) $refund['uuid'];
        $currency = (string) $refund['currency'];
        $refundAmount = (int) $refund['amount'];

        $orderLinesByUuid = $persistedRefundLines !== []
            ? $this->orderLinesByUuid($context, $orderUuid, array_column($persistedRefundLines, 'order_line_uuid'))
            : [];

        /** @var array<string,int> $sellerDebit seller_uuid => Σ delta_R (positive) */
        $sellerDebit = [];
        /** @var array<string,int> $sellerReversal seller_uuid => Σ (target_after - target_before) */
        $sellerReversal = [];
        $attributedSum = 0;
        $marketplaceRemainder = 0;

        foreach ($persistedRefundLines as $refundLine) {
            $lineUuid = (string) $refundLine['order_line_uuid'];
            $lineAmount = (int) $refundLine['amount'];
            $attributedSum += $lineAmount;

            $orderLine = $orderLinesByUuid[$lineUuid]
                ?? throw new LedgerException(
                    "Refund posting failure (refund {$refundUuid}): "
                        . "order line '{$lineUuid}' not found for order '{$orderUuid}'."
                );

            $sellerUuid = $orderLine['seller_uuid'] ?? null;
            if ($sellerUuid === null || $sellerUuid === '') {
                throw new LedgerException(
                    "Refund posting failure (refund {$refundUuid}): "
                        . "order line '{$lineUuid}' has no seller_uuid."
                );
            }
            $sellerUuid = (string) $sellerUuid;

            $basis = (int) $orderLine['commission_basis'];
            $commissionAmount = (int) $orderLine['commission_amount'];

            $priorCompleted = $this->priorCompletedAmountForLine($context, $lineUuid, $refundUuid);
            $rBefore = min($basis, $priorCompleted);
            $deltaR = min($lineAmount, max(0, $basis - $rBefore));
            $rAfter = $rBefore + $deltaR;

            $targetBefore = self::target($commissionAmount, $basis, $rBefore);
            $targetAfter = self::target($commissionAmount, $basis, $rAfter);

            $sellerDebit[$sellerUuid] = ($sellerDebit[$sellerUuid] ?? 0) + $deltaR;
            $sellerReversal[$sellerUuid] = ($sellerReversal[$sellerUuid] ?? 0) + ($targetAfter - $targetBefore);

            $marketplaceRemainder += $lineAmount - $deltaR;
        }

        $marketplaceRemainder += $refundAmount - $attributedSum;

        // Integrity guardrail: unreachable given a well-behaved caller (see the
        // class docblock), but if the attributed lines ever claim more merchandise
        // basis than this refund's own cash covers, that is a real bug -- fail loud
        // rather than let the `> 0` posting guard below silently skip the
        // marketplace leg and over-debit the seller for cash that was never
        // refunded.
        if ($marketplaceRemainder < 0) {
            throw new LedgerException(sprintf(
                'Refund posting integrity failure (refund %s): marketplace remainder %d is negative -- '
                    . 'attributed lines claim more merchandise basis than the refund amount %d covers.',
                $refundUuid,
                $marketplaceRemainder,
                $refundAmount
            ));
        }

        /** @var list<array{account_key:string,seller_uuid:?string}> $accounts */
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
            $this->lock->claim($context, $tenant, $account['account_key'], $currency);
        }

        // Tallied strictly from the amounts actually handed to $this->ledger->post()
        // below (not re-derived from the pre-posting delta_R/lineAmount arithmetic) --
        // this is what makes the hard-assert after the loop a real guardrail rather
        // than a restatement of an identity that holds by construction.
        $postedTotal = 0;

        foreach ($accounts as $account) {
            if ($account['seller_uuid'] === null) {
                continue;
            }

            $sellerUuid = $account['seller_uuid'];
            $debit = $sellerDebit[$sellerUuid] ?? 0;
            $reversal = $sellerReversal[$sellerUuid] ?? 0;

            if ($debit > 0) {
                $this->ledger->post($context, $tenant, [
                    'account_kind' => 'seller',
                    'account_key' => $account['account_key'],
                    'seller_uuid' => $sellerUuid,
                    'currency' => $currency,
                    'entry_type' => 'refund_debit',
                    'amount' => -$debit,
                    'order_uuid' => $orderUuid,
                    'refund_uuid' => $refundUuid,
                    'idempotency_key' => "{$refundUuid}:{$sellerUuid}:refund_debit",
                ]);
                $postedTotal += $debit;
            }

            if ($reversal > 0) {
                $this->ledger->post($context, $tenant, [
                    'account_kind' => 'seller',
                    'account_key' => $account['account_key'],
                    'seller_uuid' => $sellerUuid,
                    'currency' => $currency,
                    'entry_type' => 'commission_reversal',
                    'amount' => $reversal,
                    'order_uuid' => $orderUuid,
                    'refund_uuid' => $refundUuid,
                    'idempotency_key' => "{$refundUuid}:{$sellerUuid}:commission_reversal",
                ]);
            }
        }

        if ($marketplaceRemainder > 0) {
            $this->ledger->post($context, $tenant, [
                'account_kind' => 'marketplace',
                'account_key' => LedgerRepository::MARKETPLACE_ACCOUNT_KEY,
                'seller_uuid' => null,
                'currency' => $currency,
                'entry_type' => 'refund_debit',
                'amount' => -$marketplaceRemainder,
                'order_uuid' => $orderUuid,
                'refund_uuid' => $refundUuid,
                'idempotency_key' => "{$refundUuid}:marketplace:refund_debit",
            ]);
            $postedTotal += $marketplaceRemainder;
        }

        // Hard-assert on what was ACTUALLY posted (never on the pre-posting
        // delta_R/lineAmount arithmetic that produced it -- that identity holds by
        // construction and can never fire). A mismatch here means the posting loop
        // itself diverged from the refund's own cash amount.
        if ($postedTotal !== $refundAmount) {
            throw new LedgerException(sprintf(
                'Refund posting reconciliation failure (refund %s): posted seller + marketplace refund_debit'
                    . ' total %d != refund amount %d.',
                $refundUuid,
                $postedTotal,
                $refundAmount
            ));
        }
    }

    /**
     * The cumulative-target formula (design spec §2.8): `0` when the line
     * carries no basis at all (a zero-basis line can never owe commission),
     * else `min(C, intdiv(C*R + intdiv(B,2), B))` -- half-up rounding, the
     * same house idiom as {@see CommissionCalculator::lineCommission()} --
     * capped at the line's own original commission so a `R` at or above `B`
     * never reverses more than `C`.
     */
    private static function target(int $commissionAmount, int $basis, int $cumulativeRefunded): int
    {
        if ($basis === 0) {
            return 0;
        }

        return min(
            $commissionAmount,
            intdiv($commissionAmount * $cumulativeRefunded + intdiv($basis, 2), $basis)
        );
    }

    /**
     * SUM of `commerce_refund_lines.amount` for this order line across every
     * COMPLETED refund on the order EXCLUDING `$excludeRefundUuid` (this
     * refund's own uuid -- relevant on a replay, where this refund's row may
     * itself already be `completed` by the time this re-runs). Deliberately
     * excludes `pending` refunds: only a completed refund ever actually
     * reversed commission, so only completed history feeds `R_before`.
     */
    private function priorCompletedAmountForLine(
        ApplicationContext $context,
        string $orderLineUuid,
        string $excludeRefundUuid
    ): int {
        $row = db($context)->table('commerce_refund_lines')
            ->join('commerce_refunds', 'commerce_refund_lines.refund_uuid', '=', 'commerce_refunds.uuid')
            ->selectRaw('SUM(commerce_refund_lines.amount) as total')
            ->where('commerce_refunds.status', '=', 'completed')
            ->where('commerce_refund_lines.order_line_uuid', '=', $orderLineUuid)
            ->where('commerce_refund_lines.refund_uuid', '!=', $excludeRefundUuid)
            ->first();

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param list<string> $orderLineUuids
     * @return array<string,array<string,mixed>> order_line_uuid => row
     */
    private function orderLinesByUuid(ApplicationContext $context, string $orderUuid, array $orderLineUuids): array
    {
        $rows = db($context)->table('commerce_order_lines')
            ->where('order_uuid', '=', $orderUuid)
            ->whereIn('uuid', array_values(array_unique($orderLineUuids)))
            ->get();

        $byUuid = [];
        foreach ($rows as $row) {
            $byUuid[(string) $row['uuid']] = $row;
        }

        return $byUuid;
    }
}
