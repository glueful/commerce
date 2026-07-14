<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders\Refunds;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Events\RefundCompleted;
use Glueful\Extensions\Commerce\Events\RefundFailed;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\OrderStateMachine;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\RefundCollector;
use Glueful\Extensions\Contracts\Payments\RefundRequest;
use Glueful\Extensions\Contracts\Payments\RefundResult;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;

/**
 * Manual-first refund issuance plus the gateway saga. Every mutation claims the order
 * via an affected-row checked `refund_revision` bump before any validation or capacity
 * read runs — that claim, not an aggregate read, is the serialization primitive (see
 * class docs in the Layer 1 design doc).
 *
 * The gateway saga (`issueGateway`/`callAndFinalize`/`finalize`/`settle`) never performs
 * network I/O inside a `db()->transaction()`: the RESERVE transaction claims the order
 * and inserts a `pending` refund, commits, and only THEN calls the collector; the
 * FINALIZE transaction (`finalize`) claims the order again, then atomically claims the
 * refund out of `pending` via `RefundRepository::claimPending()` — the idempotency point
 * where exactly one finalizer wins and losers get back the already-terminal row.
 */
final class RefundService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly RefundRepository $refunds,
        private readonly StockRepository $stock,
        private readonly CurrentTenantResolver $tenants,
        private readonly ?RefundCollector $collector = null,
    ) {
    }

    /**
     * Canonical request fingerprint: sha256 of the order, amount, reason, restock flag,
     * and lines (sorted by order_line_uuid so attribution order never affects the hash).
     */
    public static function fingerprint(string $orderUuid, RefundInput $input): string
    {
        $lines = $input->lines;
        usort(
            $lines,
            static fn (array $a, array $b): int => ((string) $a['order_line_uuid']) <=> ((string) $b['order_line_uuid'])
        );

        $payload = [
            'order' => $orderUuid,
            'amount' => $input->amount,
            'reason' => $input->reason,
            'restock' => $input->restock,
            'lines' => array_values($lines),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> the refund row plus its 'lines' */
    public function issue(
        ApplicationContext $c,
        string $orderUuid,
        RefundInput $input,
        string $idempotencyKey,
        ?string $initiatedBy = null
    ): array {
        $tenant = $this->tenants->tenantUuid($c);
        $fingerprint = self::fingerprint($orderUuid, $input);

        // Idempotency lookup happens BEFORE any order-state validation: a replayed
        // completed full refund must still answer after the order has become `refunded`.
        $existing = $this->refunds->findByIdempotencyKey($c, $tenant, $idempotencyKey);
        if ($existing !== null) {
            return $this->replay($c, $tenant, $existing, $fingerprint);
        }

        return $this->collector === null
            ? $this->issueManual($c, $tenant, $orderUuid, $input, $idempotencyKey, $fingerprint, $initiatedBy)
            : $this->issueGateway($c, $tenant, $orderUuid, $input, $idempotencyKey, $fingerprint, $initiatedBy);
    }

    /** @return array<string,mixed> */
    private function issueManual(
        ApplicationContext $c,
        string $tenant,
        string $orderUuid,
        RefundInput $input,
        string $idempotencyKey,
        string $fingerprint,
        ?string $initiatedBy
    ): array {
        try {
            return db($c)->transaction(function () use (
                $c,
                $tenant,
                $orderUuid,
                $input,
                $idempotencyKey,
                $fingerprint,
                $initiatedBy
            ): array {
                if (!$this->orders->claimRefundMutation($c, $tenant, $orderUuid)) {
                    throw new NotFoundException('Resource not found.');
                }

                // Re-probe after the claim: a same-key winner may have committed while
                // this transaction waited, including a full refund that changed order
                // status out from under us.
                $existing = $this->refunds->findByIdempotencyKey($c, $tenant, $idempotencyKey);
                if ($existing !== null) {
                    return $this->replay($c, $tenant, $existing, $fingerprint);
                }

                [$order, $amount, $lines] = $this->validate($c, $tenant, $orderUuid, $input);

                $refund = $this->buildRow(
                    $c,
                    $tenant,
                    $orderUuid,
                    (string) $order['currency'],
                    $amount,
                    $input,
                    $idempotencyKey,
                    $fingerprint,
                    'manual',
                    'completed',
                    $initiatedBy
                );
                $this->refunds->insert($c, $refund, $lines);

                // Re-check capacity after the insert (defense-in-depth; the claim above
                // is the real serialization primitive).
                $this->assertCapacity($c, $tenant, $order);

                $this->applyCompletion($c, $tenant, $order, $refund, $lines, $input->restock);

                $refund['lines'] = $lines;

                return $refund;
            });
        } catch (\PDOException $e) {
            // Unique (tenant_uuid, idempotency_key) backstop: only classify this as an
            // idempotent replay if the key is now genuinely present; otherwise the
            // failure is unrelated and must not be swallowed.
            $existing = $this->refunds->findByIdempotencyKey($c, $tenant, $idempotencyKey);
            if ($existing === null) {
                throw $e;
            }

            return $this->replay($c, $tenant, $existing, $fingerprint);
        }
    }

    /**
     * Gateway saga step 1: RESERVE. Same validation and row shape as the manual path, but
     * the refund is inserted `pending` and the transaction commits with no network I/O.
     * The collector call (step 2, `callAndFinalize`) only ever happens after this commits.
     *
     * @return array<string,mixed>
     */
    private function issueGateway(
        ApplicationContext $c,
        string $tenant,
        string $orderUuid,
        RefundInput $input,
        string $idempotencyKey,
        string $fingerprint,
        ?string $initiatedBy
    ): array {
        try {
            $refund = db($c)->transaction(function () use (
                $c,
                $tenant,
                $orderUuid,
                $input,
                $idempotencyKey,
                $fingerprint,
                $initiatedBy
            ): array {
                if (!$this->orders->claimRefundMutation($c, $tenant, $orderUuid)) {
                    throw new NotFoundException('Resource not found.');
                }

                // Re-probe after the claim: a same-key winner may have committed while this
                // transaction waited. Resume outside the transaction; never call the
                // collector here.
                $existing = $this->refunds->findByIdempotencyKey($c, $tenant, $idempotencyKey);
                if ($existing !== null) {
                    $this->assertFingerprint($existing, $fingerprint);
                    return $existing;
                }

                [$order, $amount, $lines] = $this->validate($c, $tenant, $orderUuid, $input);

                $row = $this->buildRow(
                    $c,
                    $tenant,
                    $orderUuid,
                    (string) $order['currency'],
                    $amount,
                    $input,
                    $idempotencyKey,
                    $fingerprint,
                    'gateway',
                    'pending',
                    $initiatedBy
                );
                $this->refunds->insert($c, $row, $lines);

                // Re-check capacity after the insert (defense-in-depth; the claim above is
                // the real serialization primitive).
                $this->assertCapacity($c, $tenant, $order);

                return $row;
            });
        } catch (\PDOException $e) {
            // Unique (tenant_uuid, idempotency_key) backstop, same as the manual path.
            $existing = $this->refunds->findByIdempotencyKey($c, $tenant, $idempotencyKey);
            if ($existing === null) {
                throw $e;
            }
            $this->assertFingerprint($existing, $fingerprint);
            $refund = $existing;
        }

        if (($refund['status'] ?? null) !== RefundResult::PENDING) {
            return $refund + ['lines' => $this->refunds->linesFor($c, $tenant, (string) $refund['uuid'])];
        }

        return $this->callAndFinalize($c, $tenant, $refund);
    }

    /**
     * Gateway saga step 2: CALL. Outside every transaction; the refund uuid IS the
     * collector's idempotency key, so replays of the same refund always present the same
     * key to the gateway regardless of how many times the HTTP request is retried.
     *
     * @param array<string,mixed> $refund
     * @return array<string,mixed>
     */
    private function callAndFinalize(ApplicationContext $c, string $tenant, array $refund): array
    {
        $collector = $this->collector
            ?? throw new \LogicException('Gateway refund path invoked without a bound collector.');

        try {
            $result = $collector->refund(
                $c,
                new PayableReference(
                    'commerce_order',
                    (string) $refund['order_uuid'],
                    (int) $refund['amount'],
                    (string) $refund['currency']
                ),
                new RefundRequest(
                    (int) $refund['amount'],
                    (string) $refund['currency'],
                    (string) $refund['uuid'],
                    isset($refund['reason']) ? (string) $refund['reason'] : null
                )
            );
        } catch (\Throwable $e) {
            $this->refunds->setFailureReason($c, $tenant, (string) $refund['uuid'], $e->getMessage());
            throw new RefundOutcomeUnknownException(
                'Refund outcome unknown; retry with the same Idempotency-Key.',
                0,
                $e
            );
        }

        return $this->finalize($c, $tenant, (string) $refund['uuid'], $result);
    }

    /**
     * Tenant-scoped, idempotent settlement callback for asynchronous gateway outcomes.
     * Terminal refunds return unchanged; a pending refund runs the same finalize
     * transaction the inline saga uses.
     *
     * @return array<string,mixed>
     */
    public function settle(ApplicationContext $c, string $refundUuid, RefundResult $result): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $refund = $this->refunds->findByUuid($c, $tenant, $refundUuid)
            ?? throw new NotFoundException('Resource not found.');

        if ($refund['status'] !== 'pending') {
            return $refund + ['lines' => $this->refunds->linesFor($c, $tenant, (string) $refund['uuid'])];
        }

        return $this->finalize($c, $tenant, $refundUuid, $result);
    }

    /**
     * Gateway saga step 3: FINALIZE. Claims the order, then atomically claims the refund
     * out of `pending` (the idempotency point: exactly one finalizer wins, losers get the
     * already-terminal row back). Completed transitions reuse `applyCompletion()` — the
     * SAME totals/restock/transition/event logic as the manual path. Failed transitions
     * record the failure and dispatch `RefundFailed` after commit; because a failed
     * refund is no longer `pending`, `RefundRepository::pendingAmountSum()` and
     * `reservedAmountSum()` stop counting it — the capacity hold is released implicitly.
     *
     * @return array<string,mixed>
     */
    private function finalize(ApplicationContext $c, string $tenant, string $refundUuid, RefundResult $result): array
    {
        // Boundary validation: an unknown collector status is an infrastructure error, not
        // a business outcome. The refund stays pending so a corrected settle() can retry.
        if (!in_array($result->status, [RefundResult::COMPLETED, RefundResult::PENDING, RefundResult::FAILED], true)) {
            $this->refunds->setFailureReason($c, $tenant, $refundUuid, 'Unknown collector status: ' . $result->status);
            throw new RefundOutcomeUnknownException('Collector returned an unknown status.');
        }

        if ($result->status === RefundResult::PENDING) {
            $pending = $this->refunds->findByUuid($c, $tenant, $refundUuid)
                ?? throw new NotFoundException('Resource not found.');
            return $pending + ['lines' => $this->refunds->linesFor($c, $tenant, $refundUuid)];
        }

        $snapshot = $this->refunds->findByUuid($c, $tenant, $refundUuid)
            ?? throw new NotFoundException('Resource not found.');

        return db($c)->transaction(function () use ($c, $tenant, $refundUuid, $result, $snapshot): array {
            // Global refund lock order: order claim first, refund status claim second.
            if (!$this->orders->claimRefundMutation($c, $tenant, (string) $snapshot['order_uuid'])) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->refunds->findByUuid($c, $tenant, $refundUuid)
                ?? throw new NotFoundException('Resource not found.');
            if ($current['status'] !== 'pending') {
                return $current + ['lines' => $this->refunds->linesFor($c, $tenant, $refundUuid)];
            }

            $to = $result->status; // completed | failed
            $set = $to === RefundResult::COMPLETED
                ? ['provider_ref' => $result->providerRef, 'completed_at' => db($c)->getDriver()->formatDateTime()]
                : ['failure_reason' => $result->failureReason];

            if (!$this->refunds->claimPending($c, $tenant, $refundUuid, $to, $set)) {
                // Already finalized by a concurrent replay/settle — idempotent no-op.
                return $this->refunds->findByUuid($c, $tenant, $refundUuid)
                    + ['lines' => $this->refunds->linesFor($c, $tenant, $refundUuid)];
            }

            // We hold the order+refund claims exclusively within this transaction, so
            // $current (fetched just above, before claimPending applied $set) overlaid with
            // $set is the up-to-date row — no need to re-fetch it from the database.
            // array_merge (not the `+` operator) so $set's values win over $current's stale
            // (null) ones for the same keys.
            $refund = array_merge($current, $set, ['status' => $to]);
            $order = $this->orders->findByUuid($c, $tenant, (string) $refund['order_uuid'])
                ?? throw new NotFoundException('Resource not found.');

            if ($to === RefundResult::COMPLETED) {
                $this->applyCompletion(
                    $c,
                    $tenant,
                    $order,
                    $refund,
                    $this->refunds->linesFor($c, $tenant, $refundUuid),
                    (bool) $refund['restocked']
                );
            } else {
                $this->orders->recordEvent(
                    $c,
                    (string) $order['uuid'],
                    'refund.failed',
                    ['amount' => $refund['amount'], 'reason' => $result->failureReason],
                    null,
                    'internal'
                );
                db($c)->afterCommit(fn () => $this->dispatch($c, new RefundFailed($order, $refund)));
            }

            return $refund + ['lines' => $this->refunds->linesFor($c, $tenant, $refundUuid)];
        });
    }

    /**
     * @return array{0: array<string,mixed>, 1: int, 2: list<array{order_line_uuid:string,quantity:int,amount:int}>}
     */
    private function validate(ApplicationContext $c, string $tenant, string $orderUuid, RefundInput $input): array
    {
        $order = $this->orders->findByUuid($c, $tenant, $orderUuid);
        if ($order === null) {
            throw new NotFoundException('Resource not found.');
        }

        if (!in_array((string) $order['status'], ['paid', 'fulfilled'], true)) {
            throw new RefundValidationException('status: order must be paid or fulfilled to accept a refund.');
        }

        $remaining = (int) $order['grand_total']
            - (int) $order['refunded_total']
            - $this->refunds->pendingAmountSum($c, $tenant, $orderUuid);

        $amount = $input->amount ?? $remaining;

        if ($amount <= 0) {
            throw new RefundValidationException('amount: must be greater than zero.');
        }
        if ($amount > $remaining) {
            throw new RefundValidationException('amount: exceeds the remaining refundable balance.');
        }

        $lines = $this->validateLines($c, $tenant, $orderUuid, $input, $amount);

        return [$order, $amount, $lines];
    }

    /** @return list<array{order_line_uuid:string,quantity:int,amount:int}> */
    private function validateLines(
        ApplicationContext $c,
        string $tenant,
        string $orderUuid,
        RefundInput $input,
        int $amount
    ): array {
        if ($input->restock && $input->lines === []) {
            throw new RefundValidationException('lines: required when restock is requested.');
        }

        if ($input->lines === []) {
            return [];
        }

        $reserved = $input->restock
            ? $this->refunds->restockReservedByLine($c, $tenant, $orderUuid)
            : [];

        $lines = [];
        $sum = 0;

        foreach ($input->lines as $index => $line) {
            $lineUuid = (string) $line['order_line_uuid'];
            $orderLine = db($c)->table('commerce_order_lines')
                ->where('order_uuid', '=', $orderUuid)
                ->where('uuid', '=', $lineUuid)
                ->first();

            if ($orderLine === null) {
                throw new RefundValidationException(
                    "lines.{$index}.order_line_uuid: does not belong to this order."
                );
            }

            $lineAmount = (int) $line['amount'];
            if ($lineAmount < 0) {
                throw new RefundValidationException("lines.{$index}.amount: must not be negative.");
            }
            $sum += $lineAmount;

            $quantity = (int) $line['quantity'];
            if ($input->restock) {
                if ($quantity <= 0) {
                    throw new RefundValidationException(
                        "lines.{$index}.quantity: must be greater than zero to restock."
                    );
                }
                $already = $reserved[$lineUuid] ?? 0;
                if ($already + $quantity > (int) $orderLine['quantity']) {
                    throw new RefundValidationException(
                        "lines.{$index}.quantity: exceeds the remaining restockable quantity."
                    );
                }
            }

            $lines[] = [
                'order_line_uuid' => $lineUuid,
                'quantity' => $quantity,
                'amount' => $lineAmount,
            ];
        }

        if ($sum > $amount) {
            throw new RefundValidationException('lines: attributed amount exceeds the refund amount.');
        }

        return $lines;
    }

    /** @param array<string,mixed> $order */
    private function assertCapacity(ApplicationContext $c, string $tenant, array $order): void
    {
        $reserved = $this->refunds->reservedAmountSum($c, $tenant, (string) $order['uuid']);
        if ($reserved > (int) $order['grand_total']) {
            throw new ConcurrentRefundException('Refund capacity exceeded by a concurrent request.');
        }
    }

    /** @return array<string,mixed> */
    private function buildRow(
        ApplicationContext $c,
        string $tenant,
        string $orderUuid,
        string $currency,
        int $amount,
        RefundInput $input,
        string $idempotencyKey,
        string $fingerprint,
        string $method,
        string $status,
        ?string $initiatedBy
    ): array {
        return [
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => $fingerprint,
            'amount' => $amount,
            'currency' => $currency,
            'method' => $method,
            'status' => $status,
            'reason' => $input->reason,
            'restocked' => $input->restock,
            'initiated_by' => $initiatedBy,
            'completed_at' => $status === 'completed' ? db($c)->getDriver()->formatDateTime() : null,
        ];
    }

    /**
     * Bumps totals, transitions the order when fully refunded, restocks, records the
     * internal audit event, and registers the after-commit `RefundCompleted` dispatch.
     * Shared by the manual path here and the gateway saga's `finalize()`.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $refund
     * @param list<array{order_line_uuid:string,quantity:int,amount:int}> $lines
     */
    private function applyCompletion(
        ApplicationContext $c,
        string $tenant,
        array $order,
        array $refund,
        array $lines,
        bool $restock
    ): void {
        $orderUuid = (string) $order['uuid'];
        $amount = (int) $refund['amount'];
        $refundedTotal = (int) $order['refunded_total'];
        $grandTotal = (int) $order['grand_total'];
        $newTotal = $refundedTotal + $amount;

        $affected = db($c)->table('commerce_orders')->executeModification(
            <<<'SQL'
UPDATE commerce_orders
SET refunded_total = refunded_total + ?, updated_at = ?
WHERE tenant_uuid = ? AND uuid = ? AND refunded_total = ? AND refunded_total + ? <= grand_total
SQL,
            [$amount, db($c)->getDriver()->formatDateTime(), $tenant, $orderUuid, $refundedTotal, $amount]
        );

        if ($affected !== 1) {
            throw new ConcurrentRefundException('Order totals changed concurrently.');
        }

        if ($newTotal === $grandTotal) {
            OrderStateMachine::assertTransition((string) $order['status'], 'refunded');
            $this->orders->transition($c, $tenant, $orderUuid, 'refunded');
        }

        if ($restock) {
            $this->restockLines($c, $tenant, $orderUuid, $refund, $lines);
        }

        $actorUuid = $refund['initiated_by'] === null ? null : (string) $refund['initiated_by'];
        $this->orders->recordEvent(
            $c,
            $orderUuid,
            'refund.completed',
            [
                'refund_uuid' => $refund['uuid'],
                'amount' => $amount,
                'method' => $refund['method'],
                'reason' => $refund['reason'],
            ],
            $actorUuid,
            'internal'
        );

        $orderFresh = $this->orders->findByUuid($c, $tenant, $orderUuid);
        $refundFresh = $this->refunds->findByUuid($c, $tenant, (string) $refund['uuid']);

        if ($orderFresh !== null && $refundFresh !== null) {
            $refundFresh['lines'] = $this->refunds->linesFor($c, $tenant, (string) $refund['uuid']);
            db($c)->afterCommit(function () use ($c, $orderFresh, $refundFresh): void {
                $this->dispatch($c, new RefundCompleted($orderFresh, $refundFresh));
            });
        }
    }

    /**
     * @param array<string,mixed> $refund
     * @param list<array{order_line_uuid:string,quantity:int,amount:int}> $lines
     */
    private function restockLines(
        ApplicationContext $c,
        string $tenant,
        string $orderUuid,
        array $refund,
        array $lines
    ): void {
        foreach ($lines as $line) {
            $orderLine = db($c)->table('commerce_order_lines')
                ->where('order_uuid', '=', $orderUuid)
                ->where('uuid', '=', $line['order_line_uuid'])
                ->first();
            if ($orderLine === null) {
                continue;
            }

            $variantUuid = (string) $orderLine['variant_uuid'];
            $tracked = $this->stock->trackedState($c, $tenant, $variantUuid);

            // A missing stock row is an integrity violation, not a legitimate skip: every
            // variant gets a row via StockRepository::ensureRow() (tracked flag distinguishes
            // physical/digital), so its absence means the row vanished between validation and
            // completion. Fail the whole refund rather than silently completing with
            // restocked=true and no stock movement.
            if ($tracked === null) {
                throw new ConcurrentRefundException('Stock state changed during refund; retry.');
            }

            if (!$tracked) {
                continue;
            }

            if (!$this->stock->incrementChecked($c, $tenant, $variantUuid, (int) $line['quantity'])) {
                throw new ConcurrentRefundException('Stock state changed during refund; retry.');
            }

            $this->stock->recordMovement(
                $c,
                $tenant,
                $variantUuid,
                (int) $line['quantity'],
                'refund_restock',
                (string) $refund['uuid']
            );
        }
    }

    /**
     * @param array<string,mixed> $existing
     * @return array<string,mixed>
     */
    private function replay(ApplicationContext $c, string $tenant, array $existing, string $fingerprint): array
    {
        $this->assertFingerprint($existing, $fingerprint);

        $status = (string) $existing['status'];

        if ($status === 'pending' && $this->collector !== null) {
            // Resume the call+finalize saga with the SAME refund uuid as the collector
            // idempotency key, regardless of how many times the HTTP request is retried.
            return $this->callAndFinalize($c, $tenant, $existing);
        }

        $existing['lines'] = $this->refunds->linesFor($c, $tenant, (string) $existing['uuid']);

        return $existing;
    }

    /** @param array<string,mixed> $existing */
    private function assertFingerprint(array $existing, string $fingerprint): void
    {
        if ((string) $existing['request_fingerprint'] !== $fingerprint) {
            throw new IdempotencyConflictException('This idempotency key was already used with a different request.');
        }
    }

    private function dispatch(ApplicationContext $c, object $event): void
    {
        $container = container($c);
        if ($container->has(EventService::class)) {
            $container->get(EventService::class)->dispatch($event);
        }
    }
}
