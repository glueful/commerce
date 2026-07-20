<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundValidationException;

/**
 * Validation-time line-attribution gate for a `marketplace_partitioned`
 * order's refund (design spec §2.8, MV3 Task 7). Called from
 * {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundService::validate()}
 * AFTER the existing (unconditional) `validateLines()` shape validation and
 * the order's own financial claim -- this class layers ON TOP of that
 * validation, never replaces it, and never itself claims anything.
 *
 * This class NORMALIZES persisted line attribution only -- it never computes
 * `delta_R`/commission reversal. That is completion-time work, done by
 * {@see LedgerPostingService::postRefund()}, because a gateway refund can sit
 * `pending` for an arbitrary time between this validation and its eventual
 * completion, during which OTHER refunds on the same lines may complete and
 * change the true cumulative-refunded-merchandise picture (design spec
 * §2.8's explicit rationale for deferring `delta_R` to completion time).
 *
 * - A **partial** refund (`amount` less than the order's own remaining
 *   refundable balance) with no attributed lines (empty, or every line's
 *   `amount` is zero -- effectively line-less) is rejected `422` via
 *   {@see RefundValidationException} (the same DomainException
 *   {@see \Glueful\Extensions\Commerce\Http\Admin\AdminRefundController}
 *   already catches and turns into `Response::validation()`) -- attribution
 *   is mandatory once a marketplace order is only PARTLY being refunded.
 * - A **full-remaining** refund (`amount` === the order's own remaining
 *   refundable balance) with no attributed lines auto-expands across every
 *   refundable order line, in `order_line_uuid` ascending order for a stable,
 *   deterministic result: each line is attributed
 *   `min(remaining_basis_for_line, amount - already_attributed_so_far)`,
 *   where `remaining_basis_for_line` mirrors {@see
 *   LedgerPostingService::postRefund()}'s own `R_before` definition exactly
 *   -- `max(0, commission_basis - min(commission_basis, Σ COMPLETED
 *   refund_lines.amount for that line))` -- so the guard and the poster
 *   always agree on how much basis a line has left. Once the running
 *   attributed total reaches `amount`, every remaining line gets nothing.
 *   This guarantees the auto-expanded lines sum to AT MOST the refund
 *   amount -- the same `Σ lines ≤ amount` invariant {@see
 *   \Glueful\Extensions\Commerce\Orders\Refunds\RefundService::validateLines()}
 *   enforces on the manual path -- so {@see LedgerPostingService::postRefund()}'s
 *   marketplace remainder can never go negative. Any shortfall (a line whose
 *   remaining basis is smaller than the cash left to attribute, or
 *   shipping/tax the guard never attributes at all) is the marketplace-funded
 *   remainder {@see LedgerPostingService::postRefund()} posts at completion
 *   time.
 * - A refund that already carries non-empty, non-zero-sum lines passes
 *   through UNCHANGED -- the caller's own attribution is honored (and may
 *   legitimately under-cover the refund amount; the gap is marketplace
 *   funded the same way as the auto-expand case).
 *
 * Non-partitioned orders never reach this class at all --
 * {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundService} only
 * calls it for its own `marketplace_partitioned` snapshot, so this class
 * issues zero queries in that case by construction, not by an internal
 * check here.
 *
 * `$chargebacks` is an APPENDED OPTIONAL collaborator (design spec §2.5/§2.6,
 * MV5a Task 12) -- the same "APPENDED OPTIONAL collaborator" idiom used
 * throughout this package (e.g. {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundService}'s
 * own `$marketplaceGuard`/`$ledgerPosting`) -- so every pre-Task-12 direct
 * construction call site (tests included) stays source-compatible. When
 * wired, {@see self::completedAmountByLine()} folds in prior POSTED
 * `commerce_chargeback_lines` amounts alongside prior COMPLETED refund
 * amounts, keeping this guard's `remaining_basis` in agreement with {@see
 * LedgerPostingService::postRefund()}'s own (equally Task-12-extended)
 * `R_before` -- a chargeback that already reversed part of a line's basis
 * must shrink what this guard is willing to auto-expand onto that line, the
 * same as an earlier refund already does. A `null` `$chargebacks`
 * (pre-Task-12 construction) folds in nothing, byte-identical to before.
 */
final class MarketplaceRefundGuard
{
    public function __construct(
        private RefundRepository $refunds,
        private ?ChargebackRepository $chargebacks = null,
    ) {
    }

    /**
     * @param array<string,mixed> $order
     * @param list<array{order_line_uuid:string,quantity:int,amount:int}> $validatedInputLines
     *   already shape-validated by {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundService::validateLines()}
     * @return list<array{order_line_uuid:string,quantity:int,amount:int}>
     */
    public function validateAndNormalize(
        ApplicationContext $c,
        string $tenant,
        array $order,
        int $amount,
        array $validatedInputLines
    ): array {
        $attributedSum = 0;
        foreach ($validatedInputLines as $line) {
            $attributedSum += (int) $line['amount'];
        }

        if ($validatedInputLines !== [] && $attributedSum > 0) {
            return $validatedInputLines;
        }

        $orderUuid = (string) $order['uuid'];
        $remaining = (int) $order['grand_total']
            - (int) $order['refunded_total']
            - $this->refunds->pendingAmountSum($c, $tenant, $orderUuid);

        if ($amount !== $remaining) {
            throw new RefundValidationException(
                'lines: required to attribute a partial refund on a marketplace order.'
            );
        }

        return $this->autoExpand($c, $tenant, $orderUuid, $amount);
    }

    /**
     * Attributes each refundable line up to `min(remaining_basis, amount -
     * already_attributed)`, iterating lines in `order_line_uuid` ascending
     * order for a deterministic result, so the CUMULATIVE attributed amount
     * across every line never exceeds `$amount` -- restoring, for this
     * auto-expand path, the same `Σ lines ≤ amount` invariant the manual
     * (caller-attributed) path already gets from {@see
     * \Glueful\Extensions\Commerce\Orders\Refunds\RefundService::validateLines()}.
     * Without this cap, a later full-remaining refund could re-attribute a
     * line's whole remaining commission basis even when that exceeds the
     * refund's own cash amount (e.g. a prior partial refund deliberately
     * under-attributed to the line), driving {@see
     * LedgerPostingService::postRefund()}'s marketplace remainder negative
     * and silently over-debiting the seller.
     *
     * @return list<array{order_line_uuid:string,quantity:int,amount:int}>
     */
    private function autoExpand(ApplicationContext $c, string $tenant, string $orderUuid, int $amount): array
    {
        $orderLines = db($c)->table('commerce_order_lines')
            ->where('order_uuid', '=', $orderUuid)
            ->whereNotNull('seller_uuid')
            ->orderBy('uuid', 'ASC')
            ->get();

        if ($orderLines === []) {
            return [];
        }

        $completed = $this->completedAmountByLine($c, $tenant, $orderUuid);

        $lines = [];
        $attributedTotal = 0;

        foreach ($orderLines as $orderLine) {
            $available = $amount - $attributedTotal;
            if ($available <= 0) {
                continue;
            }

            $lineUuid = (string) $orderLine['uuid'];
            $basis = (int) $orderLine['commission_basis'];
            $priorCompleted = $completed[$lineUuid] ?? 0;
            // Mirrors LedgerPostingService::postRefund()'s R_before exactly:
            // max(0, B - min(B, Σ prior COMPLETED refund_line.amount)).
            $remainingBasis = max(0, $basis - min($basis, $priorCompleted));

            if ($remainingBasis <= 0) {
                continue;
            }

            $attribute = min($remainingBasis, $available);

            $lines[] = [
                'order_line_uuid' => $lineUuid,
                'quantity' => (int) $orderLine['quantity'],
                'amount' => $attribute,
            ];

            $attributedTotal += $attribute;
        }

        return $lines;
    }

    /**
     * Cumulative amount this order's COMPLETED refunds PLUS (design spec
     * §2.5/§2.6, MV5a Task 12) POSTED chargebacks have already attributed to
     * each order line -- deliberately mirrors {@see
     * LedgerPostingService::postRefund()}'s own (equally Task-12-extended)
     * `R_before` derivation so the guard's `remaining_basis` and the poster's
     * eventual `R_before`/`delta_R` agree on the same cumulative picture. A
     * still-`pending` refund's attribution is intentionally NOT subtracted
     * here: {@see autoExpand()}'s own `Σ lines ≤ amount` cap is what actually
     * bounds this refund's exposure, and `postRefund()` recomputes `R_before`
     * fresh (from completed/posted history only) at each refund's own
     * completion time regardless of what this validation-time estimate
     * produced -- so a pending sibling refund never causes double-counting of
     * merchandise basis at settlement time. A `null` `$this->chargebacks`
     * (pre-Task-12 construction) folds in nothing, byte-identical to before.
     *
     * @return array<string,int> order_line_uuid => already-attributed amount
     */
    private function completedAmountByLine(ApplicationContext $c, string $tenant, string $orderUuid): array
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

        if ($this->chargebacks !== null) {
            // No chargeback is ever "this refund itself" (distinct uuid namespaces),
            // so nothing is excluded -- mirrors postRefund()'s identical no-exclusion
            // call.
            $chargedBack = $this->chargebacks->priorPostedChargedBackByLine($c, $tenant, $orderUuid, '');
            foreach ($chargedBack as $key => $amount) {
                $sums[$key] = ($sums[$key] ?? 0) + $amount;
            }
        }

        return $sums;
    }
}
