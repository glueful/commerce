<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders\Refunds;

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

    /** @return list<array<string,mixed>> refunds each with 'lines' attached */
    public function listForOrder(ApplicationContext $context, string $tenant, string $orderUuid): array
    {
        $refunds = db($context)->table('commerce_refunds')
            ->where('tenant_uuid', '=', $tenant)
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('created_at', 'DESC')
            ->get();

        foreach ($refunds as &$refund) {
            $refund['lines'] = $this->linesFor($context, $tenant, (string) $refund['uuid']);
        }
        unset($refund);

        return $refunds;
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
