<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders\Refunds;

use DateTimeImmutable;
use DateTimeZone;
use Glueful\Bootstrap\ApplicationContext;

final class RefundRepository
{
    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_refunds')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findByIdempotencyKey(ApplicationContext $context, string $tenant, string $key): ?array
    {
        return db($context)->table('commerce_refunds')
            ->where('tenant_uuid', '=', $tenant)
            ->where('idempotency_key', '=', $key)
            ->first();
    }

    /**
     * @return list<array<string,mixed>> refunds each with 'lines' attached
     */
    public function listForOrder(ApplicationContext $context, string $tenant, string $orderUuid): array
    {
        $refunds = db($context)->table('commerce_refunds')
            ->where('tenant_uuid', '=', $tenant)
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('created_at', 'DESC')
            ->get();

        if ($refunds === []) {
            return $refunds;
        }

        $refundUuids = array_map(static fn (array $refund): string => (string) $refund['uuid'], $refunds);

        // One batched query for every refund's lines (joined through commerce_refunds for
        // the same tenant scoping linesFor() applies per-refund), grouped in PHP below --
        // avoids one commerce_refund_lines query per refund (N+1) when listing an order's
        // refunds.
        $lines = db($context)->table('commerce_refund_lines')
            ->join('commerce_refunds', 'commerce_refund_lines.refund_uuid', '=', 'commerce_refunds.uuid')
            ->select(['commerce_refund_lines.*'])
            ->where('commerce_refunds.tenant_uuid', '=', $tenant)
            ->whereIn('commerce_refund_lines.refund_uuid', $refundUuids)
            ->get();

        $linesByRefund = [];
        foreach ($lines as $line) {
            $linesByRefund[(string) $line['refund_uuid']][] = $line;
        }

        foreach ($refunds as &$refund) {
            $refund['lines'] = $linesByRefund[(string) $refund['uuid']] ?? [];
        }
        unset($refund);

        return $refunds;
    }

    /**
     * Cross-order paginated admin list (design spec Layer 6 §2 decision 4, new
     * `GET /refunds`): `status`/`order` (order uuid) are exact matches; `from`/
     * `to` are HALF-OPEN `[from 00:00:00, to+1day 00:00:00)` bounds on
     * `completed_at` -- the same half-open shape {@see
     * \Glueful\Extensions\Commerce\Reports\ReportWindow} uses, computed directly
     * here rather than through that class (no defaulting, no 366-day cap, per
     * spec). A plain `completed_at >= ?`/`completed_at < ?` predicate already
     * excludes `NULL` under standard SQL comparison semantics, so pending/failed
     * refunds (which never carry a `completed_at`) never match a date-bounded
     * request without any extra guard. Ordered `created_at DESC, uuid ASC`
     * (stable tie-break); count and row queries apply the identical predicate
     * set.
     *
     * @param array<string,mixed> $filters 'status'/'order' (exact) and/or 'from'/'to' (Y-m-d)
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function paginatedForTenant(
        ApplicationContext $context,
        string $tenant,
        array $filters,
        int $page,
        int $perPage
    ): array {
        $count = db($context)->table('commerce_refunds')->where('tenant_uuid', '=', $tenant);
        $rows = db($context)->table('commerce_refunds')->where('tenant_uuid', '=', $tenant);

        if (isset($filters['status']) && (string) $filters['status'] !== '') {
            $count->where('status', '=', (string) $filters['status']);
            $rows->where('status', '=', (string) $filters['status']);
        }
        if (isset($filters['order']) && (string) $filters['order'] !== '') {
            $count->where('order_uuid', '=', (string) $filters['order']);
            $rows->where('order_uuid', '=', (string) $filters['order']);
        }
        if (isset($filters['from']) && (string) $filters['from'] !== '') {
            $fromSql = self::inclusiveDayStartSql((string) $filters['from']);
            $count->where('completed_at', '>=', $fromSql);
            $rows->where('completed_at', '>=', $fromSql);
        }
        if (isset($filters['to']) && (string) $filters['to'] !== '') {
            $toExclusiveSql = self::exclusiveDayEndSql((string) $filters['to']);
            $count->where('completed_at', '<', $toExclusiveSql);
            $rows->where('completed_at', '<', $toExclusiveSql);
        }

        $items = $rows->orderBy('created_at', 'DESC')
            ->orderBy('uuid', 'ASC')
            ->limit($perPage)
            ->offset(max(0, $page - 1) * $perPage)
            ->get();

        return [
            'items' => $items,
            'total' => $count->count(),
        ];
    }

    /** Inclusive UTC window start, `Y-m-d 00:00:00`, mirroring `ReportWindow::fromSql()`. */
    private static function inclusiveDayStartSql(string $ymd): string
    {
        return self::parseYmd($ymd)->format('Y-m-d H:i:s');
    }

    /** Exclusive UTC window end (`$ymd` + 1 day, `00:00:00`), mirroring `ReportWindow::toExclusiveSql()`. */
    private static function exclusiveDayEndSql(string $ymd): string
    {
        return self::parseYmd($ymd)->modify('+1 day')->format('Y-m-d H:i:s');
    }

    /**
     * The `#[Rule('date:Y-m-d')]` DTO boundary already guarantees a
     * strictly-formatted `Y-m-d` string by the time it reaches here.
     */
    private static function parseYmd(string $ymd): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new \InvalidArgumentException("Invalid date: {$ymd}");
        }

        return $date;
    }

    /** @param list<array{order_line_uuid:string,quantity:int,amount:int}> $lines */
    public function insert(ApplicationContext $context, array $refund, array $lines): void
    {
        db($context)->table('commerce_refunds')->insert($refund);

        foreach ($lines as $line) {
            db($context)->table('commerce_refund_lines')->insert([
                'refund_uuid' => (string) $refund['uuid'],
                'order_line_uuid' => (string) $line['order_line_uuid'],
                'quantity' => (int) $line['quantity'],
                'amount' => (int) $line['amount'],
            ]);
        }
    }

    /** Affected-row-checked pending→$to claim; the idempotent serialization point. */
    public function claimPending(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $to,
        array $set = []
    ): bool {
        $affected = db($context)->table('commerce_refunds')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->where('status', '=', 'pending')
            ->update($set + ['status' => $to, 'updated_at' => db($context)->getDriver()->formatDateTime()]);

        return $affected === 1;
    }

    /** SUM(amount) of status='pending' refunds for the order. */
    public function pendingAmountSum(ApplicationContext $context, string $tenant, string $orderUuid): int
    {
        $row = db($context)->table('commerce_refunds')
            ->selectRaw('SUM(amount) as total')
            ->where('tenant_uuid', '=', $tenant)
            ->where('order_uuid', '=', $orderUuid)
            ->where('status', '=', 'pending')
            ->first();

        return (int) ($row['total'] ?? 0);
    }

    /** SUM(amount) of status IN (pending, completed) refunds for the order (capacity re-check). */
    public function reservedAmountSum(ApplicationContext $context, string $tenant, string $orderUuid): int
    {
        $row = db($context)->table('commerce_refunds')
            ->selectRaw('SUM(amount) as total')
            ->where('tenant_uuid', '=', $tenant)
            ->where('order_uuid', '=', $orderUuid)
            ->whereIn('status', ['pending', 'completed'])
            ->first();

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Cumulative restock qty reserved per order line across non-failed restock refunds.
     *
     * @return array<string,int> keyed by order_line_uuid
     */
    public function restockReservedByLine(ApplicationContext $context, string $tenant, string $orderUuid): array
    {
        $rows = db($context)->table('commerce_refund_lines')
            ->join('commerce_refunds', 'commerce_refund_lines.refund_uuid', '=', 'commerce_refunds.uuid')
            ->select(['commerce_refund_lines.order_line_uuid', 'commerce_refund_lines.quantity'])
            ->where('commerce_refunds.tenant_uuid', '=', $tenant)
            ->where('commerce_refunds.order_uuid', '=', $orderUuid)
            ->where('commerce_refunds.restocked', '=', true)
            ->where('commerce_refunds.status', '!=', 'failed')
            ->get();

        $sums = [];
        foreach ($rows as $row) {
            $sums[(string) $row['order_line_uuid']] =
                ($sums[(string) $row['order_line_uuid']] ?? 0) + (int) $row['quantity'];
        }

        return $sums;
    }

    public function setFailureReason(ApplicationContext $context, string $tenant, string $uuid, string $reason): void
    {
        db($context)->table('commerce_refunds')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update([
                'failure_reason' => $reason,
                'updated_at' => db($context)->getDriver()->formatDateTime(),
            ]);
    }

    /**
     * Joins through commerce_refunds so the child read is tenant constrained.
     *
     * @return list<array<string,mixed>>
     */
    public function linesFor(ApplicationContext $context, string $tenant, string $refundUuid): array
    {
        return db($context)->table('commerce_refund_lines')
            ->join('commerce_refunds', 'commerce_refund_lines.refund_uuid', '=', 'commerce_refunds.uuid')
            ->select(['commerce_refund_lines.*'])
            ->where('commerce_refunds.tenant_uuid', '=', $tenant)
            ->where('commerce_refund_lines.refund_uuid', '=', $refundUuid)
            ->get();
    }
}
