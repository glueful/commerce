<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\OrderScope;

/**
 * Read-only settlement-ledger reconciliation (design spec §2.11, MV3 Task 10):
 * a defense-in-depth backstop behind the §2.7/§2.8 atomic posting guarantee.
 * {@see self::scan()} NEVER inserts, updates, or deletes -- every method in
 * this class issues SELECT queries only.
 *
 * Scans DIRECTLY from the same durable facts {@see LedgerPostingService}
 * itself posts from, never by parent order status (a partial refund leaves
 * the parent order `paid`, which is deliberately irrelevant here):
 *
 *  (a) every `commerce_seller_orders` partition with `confirmed_at IS NOT
 *      NULL` -- expects `sale_credit = +attributed_total` and, only when
 *      `commission_amount > 0`, `commission_debit = -commission_amount`
 *      (mirroring {@see LedgerPostingService::postSale()}'s own zero-amount
 *      skip -- a zero-commission partition is never flagged for a "missing"
 *      commission_debit it was never supposed to have);
 *  (b) every **completed** refund on a `marketplace_partitioned` order --
 *      every `refund_debit` row referencing the refund (seller-attributed
 *      plus any marketplace-funded remainder) must sum, in absolute value,
 *      to EXACTLY the refund's own cash `amount` -- the same invariant
 *      {@see LedgerPostingService::postRefund()} itself hard-asserts at
 *      posting time. This class deliberately does NOT re-derive the full
 *      per-line `delta_R`/cumulative-commission-reversal formula (that
 *      would duplicate `postRefund()`'s own involved math without adding
 *      real backstop value beyond the sum check and the duplicate-detection
 *      below); `refund_debit` and `commission_reversal` rows are BOTH
 *      checked for duplicates (more than one row for the same
 *      `(refund, account)`), and `commission_reversal` is additionally
 *      checked for a fully-MISSING row per seller -- see {@see
 *      self::scanRefunds()} for the exact-amount limitation this leaves and
 *      the false-positive guard that bounds it;
 *  (c) every `commerce_payouts` row -- a `paid` or `reversed` row expects a matching
 *      `payout_debit = -amount` referencing the payout (MV4 Task 9, design spec
 *      §2.3/§2.4/§2.6/§2.8); an in-flight `pending` hold or a `failed` row (retryable or
 *      already released) is never flagged for a "missing" debit it was never supposed to
 *      have yet. A row carrying a positive `reversed_total` (partially reversed while
 *      still `paid`, or fully `reversed`) additionally requires cumulative
 *      `payout_reversal` postings summing to EXACTLY `reversed_total`, and a `reversed`
 *      row requires `reversed_total == amount`. Provider-side truth is never consulted
 *      here -- Commerce's only provider-state seam anywhere is
 *      `PayoutCollector::status()` ({@see PayoutService::reconcile()}); this class never
 *      reads or names the Payvia extension's own durable provider-transfer-attempt table.
 *
 * **Missing** = the expected posting is entirely absent. **Duplicate** =
 * more than one ledger row exists for a posting slot that the deterministic
 * idempotency key is supposed to make unique (the idempotency verify is
 * supposed to prevent this; reconciliation is the backstop that DETECTS a
 * regression). **Mismatched** = exactly one posting exists but its amount,
 * account, or currency differs from what the source data implies.
 *
 * Every finding is an associative array carrying `source`
 * (`seller_order`|`refund`|`payout`), whichever identifying uuid(s) apply
 * (`order_uuid`/`seller_order_uuid`/`refund_uuid`/`payout_uuid`),
 * `seller_uuid` (nullable), `account_key`, `entry_type`, `expected_amount`,
 * `found_amount`, and a human-readable `detail` string -- enough context to
 * locate the problem without a second query.
 */
final class ReconciliationService
{
    /**
     * @return array{
     *     missing: list<array<string,mixed>>,
     *     duplicate: list<array<string,mixed>>,
     *     mismatched: list<array<string,mixed>>
     * }
     */
    public function scan(ApplicationContext $context, string $tenant): array
    {
        $report = self::emptyReport();
        $report = self::mergeReports($report, $this->scanSellerOrders($context, $tenant));
        $report = self::mergeReports($report, $this->scanRefunds($context, $tenant));
        $report = self::mergeReports($report, $this->scanPayouts($context, $tenant));

        return $report;
    }

    // -----------------------------------------------------------------
    // (a) Confirmed seller-order partitions -- sale_credit + commission_debit.
    // -----------------------------------------------------------------

    /** @return array{missing: list<array<string,mixed>>, duplicate: list<array<string,mixed>>, mismatched: list<array<string,mixed>>} */
    private function scanSellerOrders(ApplicationContext $context, string $tenant): array
    {
        $sellerOrders = db($context)->table('commerce_seller_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->whereNotNull('confirmed_at')
            ->orderBy('order_uuid', 'ASC')
            ->orderBy('partition_number', 'ASC')
            ->get();

        if ($sellerOrders === []) {
            return self::emptyReport();
        }

        $orderUuids = array_values(array_unique(array_column($sellerOrders, 'order_uuid')));
        $entries = $this->groupEntriesByColumnAndType(
            db($context)->table('commerce_marketplace_ledger')
                ->where('tenant_uuid', '=', $tenant)
                ->whereIn('order_uuid', $orderUuids)
                ->whereIn('entry_type', ['sale_credit', 'commission_debit'])
                ->orderBy('id', 'ASC')
                ->get(),
            'seller_order_uuid'
        );

        $report = self::emptyReport();

        foreach ($sellerOrders as $sellerOrder) {
            $sellerOrderUuid = (string) $sellerOrder['uuid'];
            $orderUuid = (string) $sellerOrder['order_uuid'];
            $sellerUuid = (string) $sellerOrder['seller_uuid'];
            $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);
            $currency = (string) $sellerOrder['currency'];
            $attributedTotal = (int) $sellerOrder['attributed_total'];
            $commissionAmount = (int) $sellerOrder['commission_amount'];

            $identity = [
                'source' => 'seller_order',
                'order_uuid' => $orderUuid,
                'seller_order_uuid' => $sellerOrderUuid,
                'seller_uuid' => $sellerUuid,
            ];

            $report = self::mergeReports($report, $this->checkSlot(
                $entries["{$sellerOrderUuid}:sale_credit"] ?? [],
                $attributedTotal,
                $accountKey,
                $currency,
                'sale_credit',
                $identity
            ));

            // Zero-commission partitions never post a commission_debit row at all
            // (LedgerPostingService::postSale()'s own skip) -- expecting 0 here
            // means checkSlot() treats "no row found" as correct, never "missing".
            $report = self::mergeReports($report, $this->checkSlot(
                $entries["{$sellerOrderUuid}:commission_debit"] ?? [],
                $commissionAmount > 0 ? -$commissionAmount : 0,
                $accountKey,
                $currency,
                'commission_debit',
                $identity
            ));
        }

        return $report;
    }

    // -----------------------------------------------------------------
    // (b) Completed refunds on a partitioned order -- sum(abs(refund_debit))
    //     must equal the refund's own cash amount; duplicates flagged on
    //     both refund_debit and commission_reversal.
    // -----------------------------------------------------------------

    /** @return array{missing: list<array<string,mixed>>, duplicate: list<array<string,mixed>>, mismatched: list<array<string,mixed>>} */
    private function scanRefunds(ApplicationContext $context, string $tenant): array
    {
        // Joined through commerce_orders so a non-partitioned order's completed
        // refunds (which never post to the ledger at all -- §2.8) are never
        // scanned here. Draft isolation (admin-order-creation cycle 2, Task 8):
        // the shared predicate is applied even though a draft can neither be
        // partitioned nor carry a completed refund -- this is an enumerated
        // `commerce_orders` reader, so it states its scope explicitly rather
        // than relying on two upstream invariants staying true.
        $refunds = OrderScope::excludeDrafts(
            db($context)->table('commerce_refunds')
                ->join('commerce_orders', 'commerce_refunds.order_uuid', '=', 'commerce_orders.uuid')
                ->select(['commerce_refunds.*'])
                ->where('commerce_refunds.tenant_uuid', '=', $tenant)
                ->where('commerce_refunds.status', '=', 'completed')
                ->where('commerce_orders.marketplace_partitioned', '=', true),
            'commerce_orders.status'
        )
            ->orderBy('commerce_refunds.id', 'ASC')
            ->get();

        if ($refunds === []) {
            return self::emptyReport();
        }

        $refundUuids = array_values(array_unique(array_column($refunds, 'uuid')));
        $entries = $this->groupEntriesByColumn(
            db($context)->table('commerce_marketplace_ledger')
                ->where('tenant_uuid', '=', $tenant)
                ->whereIn('refund_uuid', $refundUuids)
                ->whereIn('entry_type', ['refund_debit', 'commission_reversal'])
                ->orderBy('id', 'ASC')
                ->get(),
            'refund_uuid'
        );

        // Missing-commission_reversal candidates: batched refund_lines JOIN
        // order_lines (never re-derives R_before/delta_R/target() -- that is
        // Task 7's own involved math, and duplicating it here would not add
        // real backstop value beyond presence-detection, per the class
        // docblock). A seller is a candidate for THIS refund only when at
        // least one of their refunded lines has BOTH a non-zero immutable
        // order-line `commission_amount` snapshot AND a non-zero refunded
        // merchandise amount on THIS refund's own line -- the guard against
        // false-positiving a genuinely zero-reversal case (a fully-discounted
        // line always snapshots `commission_amount = 0`, and `commission_basis
        // = max(0, line_total - discount_amount)` is independent of the
        // commission rate, so `commission_amount = 0` is exactly the
        // zero-commission signal, not a zero-basis one). This is a
        // presence-only check: it cannot detect a per-seller AMOUNT that is
        // wrong-but-nonzero, only a commission_reversal that is entirely
        // absent when one was expected.
        $reversalCandidates = $this->groupEntriesByColumn(
            db($context)->table('commerce_refund_lines')
                ->join(
                    'commerce_order_lines',
                    'commerce_refund_lines.order_line_uuid',
                    '=',
                    'commerce_order_lines.uuid'
                )
                ->select(['commerce_refund_lines.refund_uuid', 'commerce_order_lines.seller_uuid'])
                ->whereIn('commerce_refund_lines.refund_uuid', $refundUuids)
                ->where('commerce_refund_lines.amount', '>', 0)
                ->where('commerce_order_lines.commission_amount', '>', 0)
                ->get(),
            'refund_uuid'
        );

        $report = self::emptyReport();

        foreach ($refunds as $refund) {
            $refundUuid = (string) $refund['uuid'];
            $orderUuid = (string) $refund['order_uuid'];
            $refundAmount = (int) $refund['amount'];

            $rows = $entries[$refundUuid] ?? [];
            $debitRows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (string) $row['entry_type'] === 'refund_debit'
            ));
            $reversalRows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (string) $row['entry_type'] === 'commission_reversal'
            ));

            $identity = ['source' => 'refund', 'refund_uuid' => $refundUuid, 'order_uuid' => $orderUuid];

            // Duplicate detection, per account: the deterministic idempotency key
            // is supposed to make each (refund, account, entry_type) unique -- more
            // than one row for the SAME account here is a genuine integrity hit.
            $report = self::mergeReports(
                $report,
                $this->duplicatesByAccount($debitRows, 'refund_debit', $identity)
            );
            $report = self::mergeReports(
                $report,
                $this->duplicatesByAccount($reversalRows, 'commission_reversal', $identity)
            );

            // Missing commission_reversal, per candidate seller (see the batched
            // query above for the expectation guard): any candidate seller with
            // ZERO commission_reversal rows for this refund at all is flagged.
            $reversalAccountKeys = [];
            foreach ($reversalRows as $row) {
                $reversalAccountKeys[(string) $row['account_key']] = true;
            }
            $expectedReversalSellers = array_values(array_unique(
                array_column($reversalCandidates[$refundUuid] ?? [], 'seller_uuid')
            ));
            foreach ($expectedReversalSellers as $sellerUuid) {
                $sellerUuid = (string) $sellerUuid;
                $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);
                if (isset($reversalAccountKeys[$accountKey])) {
                    continue;
                }

                $report['missing'][] = $identity + [
                    'seller_uuid' => $sellerUuid,
                    'account_key' => $accountKey,
                    'entry_type' => 'commission_reversal',
                    'expected_amount' => null,
                    'found_amount' => 0,
                    'detail' => sprintf(
                        'Missing commission_reversal posting(s) for refund %s, seller %s '
                            . '(refunded line(s) carried a non-zero commission_amount snapshot, but no '
                            . 'commission_reversal ledger row exists for this seller).',
                        $refundUuid,
                        $sellerUuid
                    ),
                ];
            }

            $actualSum = 0;
            foreach ($debitRows as $row) {
                $actualSum += abs((int) $row['amount']);
            }

            if ($debitRows === []) {
                $report['missing'][] = $identity + [
                    'seller_uuid' => null,
                    'account_key' => null,
                    'entry_type' => 'refund_debit',
                    'expected_amount' => $refundAmount,
                    'found_amount' => 0,
                    'detail' => sprintf(
                        'Missing refund_debit posting(s) for refund %s (expected total %d, found none).',
                        $refundUuid,
                        $refundAmount
                    ),
                ];
            } elseif ($actualSum !== $refundAmount) {
                $report['mismatched'][] = $identity + [
                    'seller_uuid' => null,
                    'account_key' => null,
                    'entry_type' => 'refund_debit',
                    'expected_amount' => $refundAmount,
                    'found_amount' => $actualSum,
                    'detail' => sprintf(
                        'Mismatched refund_debit total for refund %s: expected sum(abs(refund_debit)) = %d, '
                            . 'found %d.',
                        $refundUuid,
                        $refundAmount,
                        $actualSum
                    ),
                ];
            }
        }

        return $report;
    }

    // -----------------------------------------------------------------
    // (c) Payouts -- payout_debit = -amount for a paid/reversed row; a reversed row
    //     additionally requires cumulative payout_reversal == reversed_total (MV4 Task 9,
    //     design spec §2.6/§2.8). Provider-side truth is NEVER consulted here -- this is a
    //     pure ledger-vs-row scan, exactly like sources (a)/(b); Commerce's ONLY provider-state
    //     seam anywhere is `PayoutCollector::status()` (see {@see PayoutService::reconcile()}),
    //     and this class never reads or names the Payvia extension's own transfer-attempt table.
    // -----------------------------------------------------------------

    /** @return array{missing: list<array<string,mixed>>, duplicate: list<array<string,mixed>>, mismatched: list<array<string,mixed>>} */
    private function scanPayouts(ApplicationContext $context, string $tenant): array
    {
        $payouts = db($context)->table('commerce_payouts')
            ->where('tenant_uuid', '=', $tenant)
            ->orderBy('id', 'ASC')
            ->get();

        if ($payouts === []) {
            return self::emptyReport();
        }

        $payoutUuids = array_values(array_unique(array_column($payouts, 'uuid')));
        $ledgerRows = db($context)->table('commerce_marketplace_ledger')
            ->where('tenant_uuid', '=', $tenant)
            ->whereIn('payout_uuid', $payoutUuids)
            ->whereIn('entry_type', ['payout_debit', 'payout_reversal'])
            ->orderBy('id', 'ASC')
            ->get();
        $debitEntries = $this->groupEntriesByColumn(
            array_values(array_filter(
                $ledgerRows,
                static fn (array $row): bool => (string) $row['entry_type'] === 'payout_debit'
            )),
            'payout_uuid'
        );
        $reversalEntries = $this->groupEntriesByColumn(
            array_values(array_filter(
                $ledgerRows,
                static fn (array $row): bool => (string) $row['entry_type'] === 'payout_reversal'
            )),
            'payout_uuid'
        );

        $report = self::emptyReport();

        foreach ($payouts as $payout) {
            $payoutUuid = (string) $payout['uuid'];
            $sellerUuid = (string) $payout['seller_uuid'];
            $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);
            $currency = (string) $payout['currency'];
            $amount = (int) $payout['amount'];
            $status = (string) $payout['status'];
            $reversedTotal = (int) $payout['reversed_total'];
            $identity = ['source' => 'payout', 'payout_uuid' => $payoutUuid, 'seller_uuid' => $sellerUuid];

            // In-flight (`pending` -- an active reserve_hold, no debit yet) and `failed`
            // (retryable=true keeps the hold, retryable=false already released it via
            // reserve_release) rows never post a payout_debit -- not a coherence violation,
            // just money that never (yet) actually left the account (design spec §2.3/§2.4).
            if ($status === 'pending' || $status === 'failed') {
                continue;
            }

            // `paid` and `reversed` BOTH keep their original payout_debit = -amount posting
            // forever -- a reversal offsets it via payout_reversal, it never alters/removes
            // the debit itself (design spec §2.8).
            $report = self::mergeReports($report, $this->checkSlot(
                $debitEntries[$payoutUuid] ?? [],
                -$amount,
                $accountKey,
                $currency,
                'payout_debit',
                $identity
            ));

            if ($reversedTotal <= 0 && $status !== 'reversed') {
                continue;
            }

            $reversalRows = $reversalEntries[$payoutUuid] ?? [];
            $reversalSum = 0;
            foreach ($reversalRows as $row) {
                $reversalSum += (int) $row['amount'];
            }

            if ($reversalRows === []) {
                $report['missing'][] = $identity + [
                    'account_key' => $accountKey,
                    'entry_type' => 'payout_reversal',
                    'expected_amount' => $reversedTotal,
                    'found_amount' => 0,
                    'detail' => sprintf(
                        'Missing payout_reversal posting(s) for payout %s (reversed_total %d, found none).',
                        $payoutUuid,
                        $reversedTotal
                    ),
                ];
            } elseif ($reversalSum !== $reversedTotal) {
                $report['mismatched'][] = $identity + [
                    'account_key' => $accountKey,
                    'entry_type' => 'payout_reversal',
                    'expected_amount' => $reversedTotal,
                    'found_amount' => $reversalSum,
                    'detail' => sprintf(
                        'Mismatched cumulative payout_reversal total for payout %s: expected %d, found %d.',
                        $payoutUuid,
                        $reversedTotal,
                        $reversalSum
                    ),
                ];
            }

            if ($status === 'reversed' && $reversedTotal !== $amount) {
                $report['mismatched'][] = $identity + [
                    'account_key' => $accountKey,
                    'entry_type' => 'reversed_total',
                    'expected_amount' => $amount,
                    'found_amount' => $reversedTotal,
                    'detail' => sprintf(
                        'Payout %s is status=reversed but reversed_total (%d) != amount (%d) '
                            . '-- a full reversal requires reversed_total == amount.',
                        $payoutUuid,
                        $reversedTotal,
                        $amount
                    ),
                ];
            }
        }

        return $report;
    }

    // -----------------------------------------------------------------
    // Shared primitives.
    // -----------------------------------------------------------------

    /**
     * The single-row-expected posting check shared by sources (a) and (c):
     * `$rows` is every ledger row found for ONE exact posting slot (already
     * narrowed to a specific seller-order/payout + entry_type by the caller).
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $identity merged verbatim into any finding
     *     (source + identifying uuid(s) + seller_uuid)
     * @return array{
     *     missing: list<array<string,mixed>>,
     *     duplicate: list<array<string,mixed>>,
     *     mismatched: list<array<string,mixed>>
     * }
     */
    private function checkSlot(
        array $rows,
        int $expectedAmount,
        string $expectedAccountKey,
        string $expectedCurrency,
        string $entryType,
        array $identity
    ): array {
        if (count($rows) > 1) {
            return ['missing' => [], 'duplicate' => [$identity + [
                'account_key' => $expectedAccountKey,
                'entry_type' => $entryType,
                'count' => count($rows),
                'detail' => sprintf(
                    '%d duplicate %s ledger row(s) found (expected exactly 1).',
                    count($rows),
                    $entryType
                ),
            ]], 'mismatched' => []];
        }

        if ($rows === []) {
            if ($expectedAmount === 0) {
                return self::emptyReport();
            }

            return ['missing' => [$identity + [
                'account_key' => $expectedAccountKey,
                'entry_type' => $entryType,
                'expected_amount' => $expectedAmount,
                'found_amount' => null,
                'detail' => sprintf(
                    'Missing %s posting (expected amount %d).',
                    $entryType,
                    $expectedAmount
                ),
            ]], 'duplicate' => [], 'mismatched' => []];
        }

        $row = $rows[0];
        $foundAmount = (int) $row['amount'];
        $foundAccountKey = (string) $row['account_key'];
        $foundCurrency = (string) $row['currency'];

        if (
            $foundAmount === $expectedAmount
            && $foundAccountKey === $expectedAccountKey
            && $foundCurrency === $expectedCurrency
        ) {
            return self::emptyReport();
        }

        return ['missing' => [], 'duplicate' => [], 'mismatched' => [$identity + [
            'account_key' => $expectedAccountKey,
            'entry_type' => $entryType,
            'expected_amount' => $expectedAmount,
            'found_amount' => $foundAmount,
            'expected_currency' => $expectedCurrency,
            'found_currency' => $foundCurrency,
            'found_account_key' => $foundAccountKey,
            'detail' => sprintf(
                'Mismatched %s posting: expected amount %d (account %s, currency %s), '
                    . 'found %d (account %s, currency %s).',
                $entryType,
                $expectedAmount,
                $expectedAccountKey,
                $expectedCurrency,
                $foundAmount,
                $foundAccountKey,
                $foundCurrency
            ),
        ]]];
    }

    /**
     * Duplicate-only check, grouped by `account_key` (sources (b)'s
     * refund_debit/commission_reversal can legitimately have BOTH a seller
     * row and a marketplace row for the same refund -- only more than one
     * row for the SAME account is a duplicate).
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $identity
     * @return array{
     *     missing: list<array<string,mixed>>,
     *     duplicate: list<array<string,mixed>>,
     *     mismatched: list<array<string,mixed>>
     * }
     */
    private function duplicatesByAccount(array $rows, string $entryType, array $identity): array
    {
        $byAccount = [];
        foreach ($rows as $row) {
            $byAccount[(string) $row['account_key']][] = $row;
        }

        $duplicate = [];
        foreach ($byAccount as $accountKey => $accountRows) {
            if (count($accountRows) > 1) {
                $duplicate[] = $identity + [
                    'seller_uuid' => $accountRows[0]['seller_uuid'] ?? null,
                    'account_key' => $accountKey,
                    'entry_type' => $entryType,
                    'count' => count($accountRows),
                    'detail' => sprintf(
                        '%d duplicate %s ledger rows found for account %s (expected at most 1).',
                        count($accountRows),
                        $entryType,
                        $accountKey
                    ),
                ];
            }
        }

        return ['missing' => [], 'duplicate' => $duplicate, 'mismatched' => []];
    }

    /**
     * Used by source (a) -- a seller-order partition checks TWO distinct
     * single-row slots (`sale_credit` and `commission_debit`), so the entry
     * type must be part of the grouping key.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string, list<array<string,mixed>>> keyed by
     *     "{$row[$groupColumn]}:{$row['entry_type']}"
     */
    private function groupEntriesByColumnAndType(array $rows, string $groupColumn): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = ((string) ($row[$groupColumn] ?? '')) . ':' . (string) $row['entry_type'];
            $grouped[$key][] = $row;
        }

        return $grouped;
    }

    /**
     * Used by sources (b)/(c) -- both group solely by their own identifying
     * uuid (`refund_uuid`/`payout_uuid`); callers filter by `entry_type` (or
     * there is only one relevant type to begin with) once grouped.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string, list<array<string,mixed>>> keyed by $row[$groupColumn]
     */
    private function groupEntriesByColumn(array $rows, string $groupColumn): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) ($row[$groupColumn] ?? '')][] = $row;
        }

        return $grouped;
    }

    /** @return array{missing: list<array<string,mixed>>, duplicate: list<array<string,mixed>>, mismatched: list<array<string,mixed>>} */
    private static function emptyReport(): array
    {
        return ['missing' => [], 'duplicate' => [], 'mismatched' => []];
    }

    /**
     * @param array{
     *     missing: list<array<string,mixed>>,
     *     duplicate: list<array<string,mixed>>,
     *     mismatched: list<array<string,mixed>>
     * } $a
     * @param array{
     *     missing: list<array<string,mixed>>,
     *     duplicate: list<array<string,mixed>>,
     *     mismatched: list<array<string,mixed>>
     * } $b
     * @return array{
     *     missing: list<array<string,mixed>>,
     *     duplicate: list<array<string,mixed>>,
     *     mismatched: list<array<string,mixed>>
     * }
     */
    private static function mergeReports(array $a, array $b): array
    {
        return [
            'missing' => [...$a['missing'], ...$b['missing']],
            'duplicate' => [...$a['duplicate'], ...$b['duplicate']],
            'mismatched' => [...$a['mismatched'], ...$b['mismatched']],
        ];
    }
}
