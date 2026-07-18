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
 *   refundable order line: each line's own remaining commission basis (its
 *   immutable `commission_basis` snapshot minus whatever this order's
 *   non-failed refunds have already attributed to it) becomes that line's
 *   refund-line amount. A line with nothing left is skipped. The
 *   auto-expanded lines never need to sum to the full refund amount -- any
 *   shortfall (shipping/tax) is the marketplace-funded remainder
 *   {@see LedgerPostingService::postRefund()} posts at completion time.
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
 */
final class MarketplaceRefundGuard
{
    public function __construct(private RefundRepository $refunds)
    {
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

        return $this->autoExpand($c, $tenant, $orderUuid);
    }

    /** @return list<array{order_line_uuid:string,quantity:int,amount:int}> */
    private function autoExpand(ApplicationContext $c, string $tenant, string $orderUuid): array
    {
        $orderLines = db($c)->table('commerce_order_lines')
            ->where('order_uuid', '=', $orderUuid)
            ->whereNotNull('seller_uuid')
            ->get();

        if ($orderLines === []) {
            return [];
        }

        $attributed = $this->attributedAmountByLine($c, $tenant, $orderUuid);

        $lines = [];
        foreach ($orderLines as $orderLine) {
            $lineUuid = (string) $orderLine['uuid'];
            $basis = (int) $orderLine['commission_basis'];
            $already = $attributed[$lineUuid] ?? 0;
            $remainingBasis = max(0, $basis - $already);

            if ($remainingBasis <= 0) {
                continue;
            }

            $lines[] = [
                'order_line_uuid' => $lineUuid,
                'quantity' => (int) $orderLine['quantity'],
                'amount' => $remainingBasis,
            ];
        }

        return $lines;
    }

    /**
     * Cumulative amount this order's non-failed (pending or completed)
     * refunds have already attributed to each order line -- the same
     * pending+completed capacity discipline
     * {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository::reservedAmountSum()}
     * applies at the order level, scoped per line here so the auto-expand
     * never re-attributes merchandise a still-in-flight (pending) or
     * already-completed refund already claimed.
     *
     * @return array<string,int> order_line_uuid => already-attributed amount
     */
    private function attributedAmountByLine(ApplicationContext $c, string $tenant, string $orderUuid): array
    {
        $rows = db($c)->table('commerce_refund_lines')
            ->join('commerce_refunds', 'commerce_refund_lines.refund_uuid', '=', 'commerce_refunds.uuid')
            ->select(['commerce_refund_lines.order_line_uuid', 'commerce_refund_lines.amount'])
            ->where('commerce_refunds.tenant_uuid', '=', $tenant)
            ->where('commerce_refunds.order_uuid', '=', $orderUuid)
            ->where('commerce_refunds.status', '!=', 'failed')
            ->get();

        $sums = [];
        foreach ($rows as $row) {
            $key = (string) $row['order_line_uuid'];
            $sums[$key] = ($sums[$key] ?? 0) + (int) $row['amount'];
        }

        return $sums;
    }
}
