# Layer 1 — Order Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship refunds (partial/full, restock, gateway saga), order notes, transactional email, and invoice data per `docs/superpowers/specs/2026-07-14-layer1-order-lifecycle-design.md`.

**Architecture:** Manual-first refunds through a new `RefundService` whose gateway path is a persisted reserve → external call → finalize saga (no network I/O inside `Connection::transaction()`); HTTP idempotency via `(tenant_uuid, idempotency_key)` unique + request fingerprint; notes/emails ride the existing order-events + BaseEvent spine with after-commit dispatch.

**Tech Stack:** PHP 8.3, Glueful framework ^1.65 (dev via path repo), PHPUnit 10.5, extension-contracts path dep.

## Global Constraints

- Two repos: `~/Sites/glueful/extensions/contracts` (Task 1 only) and `~/Sites/glueful/extensions/commerce` (everything else). Commerce already consumes contracts via a path repository — no version pin changes in this plan.
- The framework query builder has **no `lockForUpdate()`**. Every refund mutation first performs an affected-row-checked `UPDATE commerce_orders SET refund_revision = refund_revision + 1 WHERE tenant_uuid = ? AND uuid = ?`. That row claim serializes reserve/finalize work per order; validation and capacity reads occur only after it. Aggregate re-checks remain defense-in-depth, never the serialization primitive.
- Network I/O (RefundCollector calls) must NEVER run inside `db($context)->transaction()` (the framework retries deadlocked callbacks).
- All events new in this layer (`RefundCompleted`, `RefundFailed`, `OrderNoteAdded`) dispatch **after the outermost commit** via `db($context)->afterCommit(...)`; dispatch itself uses the container-checked `EventService` pattern copied from `CheckoutService::dispatch()`.
- Operator refund `reason` never appears in storefront responses, customer events, invoices, or emails.
- Money is integer minor units everywhere; currency must equal the order's currency.
- Tenant rule: every repository read/write constrains `tenant_uuid` AND resource identity; cross-tenant UUIDs → the same non-revealing 404 (`NotFoundException('Resource not found.')`).
- Scopes: `require_scope:commerce:read` / `require_scope:commerce:write` (existing) — no new permission system.
- Master email switch `commerce.email.enabled` defaults **false**.
- Column additions fold into migration 004 (pre-release posture); new tables are migration 006. Dev/test DBs sync manually after Task 2.
- Coding style: PSR-12, `declare(strict_types=1)`, final classes, constructor property promotion — match neighboring files.
- Commits: one per task **group** (marked below), conventional-commit style, no AI attribution.
- Quality gates per group: `composer test && composer phpcs && composer phpstan` (in the repo touched).

---

## GROUP A — Contracts (repo: `extensions/contracts`)

### Task 1: RefundCollector contract + VOs

**Files:**
- Create: `src/Payments/RefundCollector.php`
- Create: `src/Payments/RefundRequest.php`
- Create: `src/Payments/RefundResult.php`

**Interfaces:**
- Produces: `RefundCollector::refund(ApplicationContext, PayableReference, RefundRequest): RefundResult`; `RefundRequest{int amount, string currency, string idempotencyKey, ?string reason}`; `RefundResult{string status, ?string providerRef, ?string failureReason}` with status constants `COMPLETED|PENDING|FAILED`.

- [ ] **Step 1: Write the three files** (mirror `PaymentCollector.php` header style):

```php
<?php
// src/Payments/RefundCollector.php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Payments;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Returns money for a previously collected payable.
 *
 * Implementations must be idempotent per RefundRequest::$idempotencyKey:
 * repeat calls report the same logical refund and never move money twice.
 * Throwing signals infrastructure failure (unknown outcome); business
 * failure is a RefundResult with status RefundResult::FAILED.
 */
interface RefundCollector
{
    public function refund(
        ApplicationContext $context,
        PayableReference $payable,
        RefundRequest $request
    ): RefundResult;
}
```

```php
<?php
// src/Payments/RefundRequest.php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Payments;

/** Amount is integer minor units in $currency. */
final class RefundRequest
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $idempotencyKey,
        public readonly ?string $reason = null,
    ) {
    }
}
```

```php
<?php
// src/Payments/RefundResult.php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Payments;

final class RefundResult
{
    public const COMPLETED = 'completed';
    public const PENDING = 'pending';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?string $providerRef = null,
        public readonly ?string $failureReason = null,
    ) {
    }
}
```

- [ ] **Step 2: Lint** — `composer phpcs` (contracts repo), expected: no errors.
- [ ] **Step 3: COMMIT (contracts repo)** — `feat(payments): RefundCollector contract with RefundRequest/RefundResult`

---

## GROUP B — Data model + RefundService (repo: `extensions/commerce`)

### Task 2: Migrations, tenant registration, diagnostics

**Files:**
- Modify: `migrations/004_CreateCommerceOrderTables.php`
- Create: `migrations/006_CreateCommerceRefundTables.php`
- Modify: `src/Support/DiagnosticsReport.php` (tenantTables list)
- Modify: `tests/Integration/MigrationsTest.php`

- [ ] **Step 1: Fold columns into 004.** In the `commerce_orders` create block, after `grand_total`: `$table->bigInteger('refunded_total')->default(0);` and `$table->bigInteger('refund_revision')->default(0);`. In `commerce_order_events` after `payload`: `$table->string('actor_uuid', 12)->nullable();` and `$table->string('visibility', 16)->default('internal');`
- [ ] **Step 2: Write 006** (copy 004's class conventions):

```php
$schema->createTable('commerce_refunds', function ($table): void {
    $table->bigInteger('id')->primary()->autoIncrement();
    $table->string('uuid', 12);
    $table->string('tenant_uuid', 12)->default('');
    $table->string('order_uuid', 12);
    $table->string('idempotency_key', 128);
    $table->string('request_fingerprint', 64);
    $table->bigInteger('amount');
    $table->string('currency', 3);
    $table->string('method', 16);
    $table->string('status', 16)->default('pending');
    $table->text('reason')->nullable();
    $table->boolean('restocked')->default(false);
    $table->string('provider_ref', 191)->nullable();
    $table->text('failure_reason')->nullable();
    $table->string('initiated_by', 12)->nullable();
    $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
    $table->timestamp('updated_at')->nullable();
    $table->timestamp('completed_at')->nullable();

    $table->unique('uuid');
    $table->unique(['tenant_uuid', 'idempotency_key']);
    $table->index('tenant_uuid');
    $table->index('order_uuid');
});

$schema->createTable('commerce_refund_lines', function ($table): void {
    $table->bigInteger('id')->primary()->autoIncrement();
    $table->string('refund_uuid', 12);
    $table->string('order_line_uuid', 12);
    $table->integer('quantity');
    $table->bigInteger('amount');

    $table->unique(['refund_uuid', 'order_line_uuid']);
    $table->index('refund_uuid');
});
```

- [ ] **Step 3: Register tenancy.** Add `'commerce_refunds'` to `DiagnosticsReport::tenantTables()` (after `commerce_order_lines`). `CommerceServiceProvider::boot()` already registers that complete list, so no provider edit is needed. `commerce_refund_lines` is a child table — deliberately NOT registered.
- [ ] **Step 4: Update MigrationsTest** — extend its table/column assertions with both new tables, the folded columns, and the `(tenant_uuid, idempotency_key)` unique.
- [ ] **Step 5: Run** — `vendor/bin/phpunit tests/Integration/MigrationsTest.php`, expected PASS.
- [ ] **Step 6: Sync dev/test DBs manually** (folded columns): run the extension's test-schema reset, note in the task report that shared dev DBs need `ALTER TABLE` equivalents.

### Task 3: RefundRepository + input/exceptions

**Files:**
- Create: `src/Orders/Refunds/RefundInput.php`
- Create: `src/Orders/Refunds/RefundRepository.php`
- Create: `src/Orders/Refunds/RefundValidationException.php` (extends `\DomainException`)
- Create: `src/Orders/Refunds/IdempotencyConflictException.php` (extends `\DomainException`)
- Create: `src/Orders/Refunds/ConcurrentRefundException.php` (extends `\DomainException`)
- Create: `src/Orders/Refunds/RefundOutcomeUnknownException.php` (extends `\RuntimeException`)
- Test: `tests/Integration/Refunds/RefundRepositoryTest.php`

**Interfaces (Produces — later tasks depend on these exact signatures):**

```php
final class RefundInput {
    /** @param list<array{order_line_uuid:string,quantity:int,amount:int}> $lines */
    public function __construct(
        public readonly ?int $amount,
        public readonly ?string $reason,
        public readonly array $lines,
        public readonly bool $restock,
    ) {}
}

final class RefundRepository {
    public function findByUuid(ApplicationContext $c, string $tenant, string $uuid): ?array;
    public function findByIdempotencyKey(ApplicationContext $c, string $tenant, string $key): ?array;
    /** @return list<array<string,mixed>> refunds each with 'lines' attached */
    public function listForOrder(ApplicationContext $c, string $tenant, string $orderUuid): array;
    /** @param list<array{order_line_uuid:string,quantity:int,amount:int}> $lines */
    public function insert(ApplicationContext $c, array $refund, array $lines): void;
    /** Affected-row-checked pending→$to claim; the idempotent serialization point. */
    public function claimPending(ApplicationContext $c, string $tenant, string $uuid, string $to, array $set = []): bool;
    /** SUM(amount) of status='pending' refunds for the order. */
    public function pendingAmountSum(ApplicationContext $c, string $tenant, string $orderUuid): int;
    /** SUM(amount) of status IN (pending, completed) refunds for the order (capacity re-check). */
    public function reservedAmountSum(ApplicationContext $c, string $tenant, string $orderUuid): int;
    /** Cumulative restock qty reserved per order line across non-failed restock refunds. @return array<string,int> keyed by order_line_uuid */
    public function restockReservedByLine(ApplicationContext $c, string $tenant, string $orderUuid): array;
    public function setFailureReason(ApplicationContext $c, string $tenant, string $uuid, string $reason): void;
    /** @return list<array<string,mixed>> */
    /** Joins through commerce_refunds so the child read is tenant constrained. @return list<array<string,mixed>> */
    public function linesFor(ApplicationContext $c, string $tenant, string $refundUuid): array;
}
```

- [ ] **Step 1: Write a failing repository test** (use `CommerceTestCase`; seed one refund row via `insert()`, assert `findByIdempotencyKey` round-trip, `claimPending` returns true once then false, `restockReservedByLine` sums across two refunds and excludes a `failed` one).
- [ ] **Step 2: Run it** — expected FAIL (classes missing).
- [ ] **Step 3: Implement.** Follow `OrderRepository`'s query style (`db($c)->table(...)`). `linesFor()` MUST join `commerce_refunds` and constrain `commerce_refunds.tenant_uuid = $tenant` plus `refund_uuid`; no child-table method accepts a bare caller-provided refund UUID. Key implementations:

```php
public function claimPending(ApplicationContext $c, string $tenant, string $uuid, string $to, array $set = []): bool
{
    $affected = db($c)->table('commerce_refunds')
        ->where('tenant_uuid', '=', $tenant)
        ->where('uuid', '=', $uuid)
        ->where('status', '=', 'pending')
        ->update($set + ['status' => $to, 'updated_at' => db($c)->getDriver()->formatDateTime()]);

    return $affected === 1;
}

public function restockReservedByLine(ApplicationContext $c, string $tenant, string $orderUuid): array
{
    $rows = db($c)->table('commerce_refund_lines')
        ->join('commerce_refunds', 'commerce_refund_lines.refund_uuid', 'commerce_refunds.uuid')
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
```

  (If the query builder's `join`/aggregate syntax differs, match whatever `src/` already uses — search for `->join(` in the extension/framework before inventing; fall back to two queries.) `restocked` records restock **intent** at insert; completion performs the stock movement.
- [ ] **Step 4: Run test** — expected PASS.

### Task 4: RefundService — manual path

**Files:**
- Create: `src/Orders/Refunds/RefundService.php`
- Create: `src/Events/RefundCompleted.php` (Task 4 consumes it; do not defer it to Group C)
- Modify: `src/Orders/OrderRepository.php` — add `claimRefundMutation()`; `recordEvent()` gains optional `?string $actorUuid = null, string $visibility = 'internal'` params writing the new columns (existing callers unchanged).
- Modify: `src/Inventory/StockRepository.php` — add an affected-row-checked `incrementChecked()` used by refund restocking; keep the existing `increment()` API unchanged.
- Modify: `src/CommerceServiceProvider.php` — register `RefundRepository` + `RefundService` (factory injects `OrderRepository`, `RefundRepository`, `StockRepository`, tenant resolver, and `$container->has(RefundCollector::class) ? $container->get(...) : null`).
- Test: `tests/Unit/Orders/RefundFingerprintTest.php`, `tests/Integration/Refunds/ManualRefundTest.php`, `tests/Integration/Refunds/RefundOrderClaimTest.php`

**Interfaces:**
- Produces: `RefundService::issue(ApplicationContext $c, string $orderUuid, RefundInput $input, string $idempotencyKey, ?string $initiatedBy = null): array` (returns the refund row + `lines`); `RefundService::settle(...)` arrives in Task 5.
- Produces: `OrderRepository::claimRefundMutation(ApplicationContext $c, string $tenant, string $orderUuid): bool`; `StockRepository::incrementChecked(...): bool`.
- Consumes: Task 3 repository, `OrderStateMachine::assertTransition`, `StockRepository::incrementChecked/recordMovement/isTracked`, `Support/TokenHasher` NOT used here (raw idempotency keys are not secrets).

- [ ] **Step 1: Failing unit test — fingerprint canonicalization** (same input → same hash; line order irrelevant; different amount → different hash).
- [ ] **Step 2: Failing integration tests — manual refunds.** Using `CommerceTestCase` + checkout fixtures (copy the seeding helpers from `tests/Integration/Orders`): place+pay an order, then:
  - full refund (omitted amount) → status `completed`, `refunded_total == grand_total`, order status `refunded`, internal `refund.completed` event exists with visibility `internal`;
  - partial refund → order stays `paid`, `refunded_total` correct;
  - second partial exceeding remainder → `RefundValidationException`;
  - wrong state (`pending_payment`) → `RefundValidationException`;
  - restock: attributed line restores stock, one `refund_restock` movement referenced by refund uuid, cumulative restock over two refunds cannot exceed line quantity;
  - checked restock: a tracked stock row removed between validation and completion causes the transaction to roll back; no refund/totals/movement persists;
  - idempotent replay: same key + same payload returns the SAME refund uuid (even after order is `refunded`); same key + different payload → `IdempotencyConflictException`.
- [ ] **Step 2b: Failing serialization test.** Open two independent PostgreSQL connections/transactions against one paid order. Hold transaction A after `claimRefundMutation()` and prove transaction B cannot pass its claim until A commits/rolls back. The claim is an `UPDATE refund_revision = refund_revision + 1`, not an aggregate read.
- [ ] **Step 3: Implement.** Core structure (complete logic — adjust query-builder calls to house style):

```php
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

    /** @return array<string,mixed> */
    public function issue(
        ApplicationContext $c,
        string $orderUuid,
        RefundInput $input,
        string $idempotencyKey,
        ?string $initiatedBy = null
    ): array {
        $tenant = $this->tenants->tenantUuid($c);
        $fingerprint = self::fingerprint($orderUuid, $input);

        // Idempotency FIRST — a replayed completed full refund must still answer
        // after the order has moved to `refunded`.
        $existing = $this->refunds->findByIdempotencyKey($c, $tenant, $idempotencyKey);
        if ($existing !== null) {
            return $this->replay($c, $tenant, $existing, $fingerprint);
        }

        return $this->collector === null
            ? $this->issueManual($c, $tenant, $orderUuid, $input, $idempotencyKey, $fingerprint, $initiatedBy)
            : $this->issueGateway($c, $tenant, $orderUuid, $input, $idempotencyKey, $fingerprint, $initiatedBy);
    }

    private function issueManual(...): array
    {
        try {
            return db($c)->transaction(function () use (...): array {
                if (!$this->orders->claimRefundMutation($c, $tenant, $orderUuid)) {
                    throw new NotFoundException('Resource not found.');
                }
                // Re-probe after the order claim: a same-key winner may have committed while
                // this transaction waited, including a full refund that changed order status.
                $existing = $this->refunds->findByIdempotencyKey($c, $tenant, $idempotencyKey);
                if ($existing !== null) {
                    return $this->replay($c, $tenant, $existing, $fingerprint);
                }
                [$order, $amount, $lines] = $this->validate($c, $tenant, $orderUuid, $input);
                $refund = $this->buildRow(..., 'manual', 'completed', completedAt: now);
                $this->refunds->insert($c, $refund, $lines);
                $this->assertCapacity($c, $tenant, $order);          // re-check after insert
                $this->applyCompletion($c, $tenant, $order, $refund, $lines, $input->restock);
                return $refund + ['lines' => $lines];
            });
        } catch (\PDOException $e) {                 // unique (tenant, idempotency_key) backstop
            $existing = $this->refunds->findByIdempotencyKey($c, $tenant, $idempotencyKey);
            if ($existing === null) throw $e;
            return $this->replay($c, $tenant, $existing, $fingerprint);
        }
    }
```

  Detailed rules the implementation MUST encode:
  - **claimRefundMutation()**: `UPDATE commerce_orders SET refund_revision = refund_revision + 1 WHERE tenant_uuid = ? AND uuid = ?`; return `affected === 1`. Every issue/reserve/finalize transaction calls it before reading state/capacity. This is the serialization primitive.
  - **validate()** (inside the transaction, after the claim): order exists tenant-scoped (else `NotFoundException`); status ∈ {`paid`,`fulfilled`}; resolve omitted amount as `grand_total - refunded_total - pendingAmountSum()`; `amount > 0`; `amount <= remaining`; currency check is implicit (refund inherits `order.currency`; if a currency is ever passed it must equal it); lines reference real `commerce_order_lines` of this order, amounts ≥ 0 summing ≤ refund amount; if `restock=true`, lines are REQUIRED and per line `restockReservedByLine()[line] + qty <= order line quantity`. Violations throw `RefundValidationException` with a field-keyed message.
  - **assertCapacity()** (after insert, same txn): `reservedAmountSum() <= grand_total` — else throw `ConcurrentRefundException` (transaction rolls back; the concurrent-reserve race loser).
  - **applyCompletion()** (same claimed txn): affected-row-checked totals bump —
    `UPDATE commerce_orders SET refunded_total = refunded_total + :a WHERE tenant_uuid=:t AND uuid=:o AND refunded_total = :asRead AND refunded_total + :a <= grand_total` — 0 rows → `ConcurrentRefundException`. If new total == grand_total: `OrderStateMachine::assertTransition($order['status'], 'refunded')` + `$this->orders->transition(...)`. If restock: per attributed line whose variant `isTracked()`, require `$this->stock->incrementChecked(...) === true` or throw; only then `recordMovement(..., 'refund_restock', $refund['uuid'])`. Append `refund.completed` event (payload: amount, method, reason — visibility `internal`). Reload the committed-shape order/refund arrays before registering `db($c)->afterCommit(fn () => $this->dispatch($c, new RefundCompleted($orderFresh, $refundFresh)))`.
  - **replay()**: fingerprint mismatch → `IdempotencyConflictException`. Status `completed`/`failed` → return stored row + lines. Status `pending` + collector bound → resume Task 5's call+finalize with the SAME refund uuid. Status `pending` + no collector → return the pending row (operator will `settle()`).
  - **fingerprint()**: sha256 of JSON of `{order, amount(raw input, null if omitted), reason, restock, lines sorted by order_line_uuid}` with `JSON_THROW_ON_ERROR`.
  - **Duplicate-key detection (verified):** `QueryExecutor::executeStatement()` rethrows `\PDOException`; there is no framework duplicate-key exception. Catch `\PDOException`, re-probe `findByIdempotencyKey` (if found → replay; otherwise rethrow the original exception). Do not classify unrelated PDO failures as idempotent success.
- [ ] **Step 4: Run all Task 4 tests** — expected PASS.
- [ ] **Step 5:** `composer phpstan && composer phpcs` — clean.

### Task 5: RefundService — gateway saga + settle()

**Files:**
- Modify: `src/Orders/Refunds/RefundService.php`
- Create: `src/Events/RefundFailed.php` (Task 5 consumes it; do not defer it to Group C)
- Test: `tests/Integration/Refunds/GatewayRefundTest.php` (with a scripted fake `RefundCollector`)

**Interfaces:**
- Produces: `RefundService::settle(ApplicationContext $c, string $refundUuid, RefundResult $result): array` — tenant-scoped, idempotent (pending-only transitions; terminal refunds return unchanged).

- [ ] **Step 1: Failing tests** with a `FakeRefundCollector` (constructor takes a queue of results/throwables; records every call's `idempotencyKey` and whether a transaction was open via the verified `db($c)->withinTransaction()`):
  - completed result → refund `completed`, totals/restock/events as manual path;
  - failed result → refund `failed` + `failure_reason`, totals unchanged, pending reservation released (subsequent full refund of the whole amount succeeds), `RefundFailed` dispatched after commit, no customer event;
  - pending result → refund stays `pending`, `refunded_total` unchanged, remaining-refundable honors the hold;
  - collector THROWS → refund stays `pending`, `failure_reason` recorded, `RefundOutcomeUnknownException` bubbles; replaying the same HTTP key calls the collector again **with the same refund-uuid idempotency key** (assert the fake saw the same key twice) and a completed result then finalizes;
  - `settle()` on a pending refund with completed result → finalizes; `settle()` again → returns unchanged, no double totals/restock; unknown result status → treated as infrastructure error (still pending);
  - unknown refund uuid / cross-tenant settle → `NotFoundException`.
- [ ] **Step 1b: Concurrent gateway reservation test.** Use two independent connections and different idempotency keys to race two 60-unit reservations against a 100-unit order. Hold A after the order claim, start B, release A, and assert exactly one pending refund commits while the loser receives `RefundValidationException`/`ConcurrentRefundException`. Collector calls remain outside both transactions.
- [ ] **Step 2: Implement.** Saga structure:

```php
private function issueGateway(...): array
{
    // 1. RESERVE — same validation, insert PENDING, commit. No network I/O.
    $refund = db($c)->transaction(function () use (...): array {
        if (!$this->orders->claimRefundMutation($c, $tenant, $orderUuid)) {
            throw new NotFoundException('Resource not found.');
        }
        $existing = $this->refunds->findByIdempotencyKey($c, $tenant, $idempotencyKey);
        if ($existing !== null) {
            $this->assertFingerprint($existing, $fingerprint);
            return $existing; // resume outside the transaction; never call the collector here
        }
        [$order, $amount, $lines] = $this->validate(...);
        $row = $this->buildRow(..., 'gateway', 'pending');
        $this->refunds->insert($c, $row, $lines);
        $this->assertCapacity($c, $tenant, $order);
        return $row;
    }); // catch PDOException exactly as Task 4: re-probe key, otherwise rethrow

    // 2. CALL — outside every transaction; refund uuid IS the collector idempotency key.
    if (($refund['status'] ?? null) !== RefundResult::PENDING) {
        return $refund + ['lines' => $this->refunds->linesFor($c, $tenant, (string) $refund['uuid'])];
    }
    return $this->callAndFinalize($c, $tenant, $refund);
}

private function callAndFinalize(ApplicationContext $c, string $tenant, array $refund): array
{
    try {
        $result = $this->collector->refund(
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
        throw new RefundOutcomeUnknownException('Refund outcome unknown; retry with the same Idempotency-Key.', 0, $e);
    }

    return $this->finalize($c, $tenant, (string) $refund['uuid'], $result);
}

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

private function finalize(ApplicationContext $c, string $tenant, string $refundUuid, RefundResult $result): array
{
    // Boundary validation: unknown status == infrastructure error, refund stays pending.
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

    return db($c)->transaction(function () use (..., $snapshot): array {
        // Global refund lock order: order claim first, refund status claim second.
        if (!$this->orders->claimRefundMutation($c, $tenant, (string) $snapshot['order_uuid'])) {
            throw new NotFoundException('Resource not found.');
        }
        $current = $this->refunds->findByUuid($c, $tenant, $refundUuid)
            ?? throw new NotFoundException('Resource not found.');
        if ($current['status'] !== 'pending') {
            return $current + ['lines' => $this->refunds->linesFor($c, $tenant, $refundUuid)];
        }
        $to = $result->status;                         // completed | failed
        $set = $to === RefundResult::COMPLETED
            ? ['provider_ref' => $result->providerRef, 'completed_at' => now]
            : ['failure_reason' => $result->failureReason];
        if (!$this->refunds->claimPending($c, $tenant, $refundUuid, $to, $set)) {
            // Already finalized by a concurrent replay/settle — idempotent no-op.
            return $this->refunds->findByUuid($c, $tenant, $refundUuid)
                + ['lines' => $this->refunds->linesFor($c, $tenant, $refundUuid)];
        }
        $refund = $this->refunds->findByUuid($c, $tenant, $refundUuid);
        $order = $this->orders->findByUuid($c, $tenant, (string) $refund['order_uuid']);
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
            $this->orders->recordEvent($c, (string) $order['uuid'], 'refund.failed',
                ['amount' => $refund['amount'], 'reason' => $result->failureReason], null, 'internal');
            db($c)->afterCommit(fn () => $this->dispatch($c, new RefundFailed($order, $refund)));
        }
        return $refund + ['lines' => $this->refunds->linesFor($c, $tenant, $refundUuid)];
    });
}
```

  `claimPending()` is the idempotency point: exactly one finalizer wins; losers return the terminal row. `applyCompletion()` is the SAME method as the manual path (single source of totals/restock/transition/event truth).
- [ ] **Step 3: Run Task 5 tests** — PASS; re-run Task 4 tests — still PASS.
- [ ] **Step 4:** `composer phpstan && composer phpcs` — clean.
- [ ] **Step 5: COMMIT (commerce)** — `feat(refunds): refund domain — tables, repository, manual + gateway saga with idempotency`

---

## GROUP C — Events + HTTP surface

### Task 6: Events + OrderFulfilled dispatch fix

**Files:**
- Create: `src/Events/OrderNoteAdded.php` (`RefundCompleted`/`RefundFailed` already landed with their first consumers in Tasks 4–5)
- Modify: `src/Http/Admin/AdminOrderController.php` (`fulfill()` dispatches `OrderFulfilled` after persist)
- Test: `tests/Integration/Orders/OrderFulfilledDispatchTest.php`

- [ ] **Step 1: Event class** — `OrderNoteAdded {array $order, array $note}` mirrors `OrderPaid` exactly. Verify the refund event classes from Tasks 4–5 call `parent::__construct()` and retain the same `{order, refund}` shape:

```php
final class OrderNoteAdded extends BaseEvent
{
    /** @param array<string,mixed> $order @param array<string,mixed> $note */
    public function __construct(public readonly array $order, public readonly array $note)
    {
        parent::__construct();
    }
}
```

- [ ] **Step 2: Failing test** — fulfilling a paid order dispatches `OrderFulfilled` once (register a listener via `EventService::addListener` in the test); canceling does not.
- [ ] **Step 3: Fix `fulfill()`** — after the update + `recordEvent`, add the container-checked dispatch (`OrderFulfilled` already exists in `src/Events/`): copy the `dispatch()` helper pattern. The update is not inside a transaction, so dispatch directly after persist.
- [ ] **Step 4: Run** — PASS.

### Task 7: Refund + note endpoints, DTOs, route removal

**Files:**
- Create: `src/Http/DTOs/CreateRefundData.php`, `src/Http/DTOs/CreateOrderNoteData.php`
- Create: `src/Http/Admin/AdminRefundController.php`
- Modify: `src/Http/Admin/AdminOrderController.php` (add `addNote()`; include events in `show()`; DELETE `markRefunded()`)
- Modify: `src/Orders/OrderRepository.php` — add tenant-constrained `eventsForOrder()` (join through `commerce_orders`)
- Modify: `routes.php` (add 3 routes, remove mark-refunded)
- Modify: `src/CommerceServiceProvider.php` (controller factory)
- Test: `tests/Integration/Http/RefundEndpointTest.php`, `tests/Integration/Http/OrderNoteEndpointTest.php` (follow the construction style of existing `tests/Integration/Http` tests)

**Interfaces (Consumes):** Task 4/5 `RefundService`; Task 3 exceptions map to HTTP: `RefundValidationException` → `Response::validation` 422 · `IdempotencyConflictException` → 409 · `ConcurrentRefundException` → 409 (message: retry) · `RefundOutcomeUnknownException` → 503 · `NotFoundException` → 404 (framework handler).

- [ ] **Step 1: DTOs**

```php
final class CreateRefundData implements RequestData
{
    /** @param list<array{order_line_uuid:string,quantity:int,amount:int}>|null $lines */
    public function __construct(
        #[Rule('integer')]
        public readonly ?int $amount = null,
        #[Rule('string|max:1000')]
        public readonly ?string $reason = null,
        public readonly ?array $lines = null,        // element validation in controller (nested DTO support pending)
        #[Rule('boolean')]
        public readonly bool $restock = false,
    ) {
    }
}

final class CreateOrderNoteData implements RequestData
{
    public function __construct(
        #[Rule('required|string|min:1|max:4000')]
        public readonly string $body,
        #[Rule('required|in:internal,customer')]
        public readonly string $visibility,
        #[Rule('boolean')]
        public readonly bool $notify = false,
    ) {
    }
}
```

  Glueful's `in:internal,customer` grammar is verified. Its `min`/`max` rules are string-length rules, so `amount` uses `integer` only and `RefundInput`/domain validation enforces `> 0`; string `min`/`max` remain valid for note/reason fields.
- [ ] **Step 2: Failing endpoint tests** — refund create: missing `Idempotency-Key` header → 422; oversize key (>128) → 422; happy full refund → 200 + admin refund payload (which MAY include operator reason); replay same key+payload → 200 with the same UUID; different payload → 409. Storefront reason exclusion is asserted in Task 8. Note create: unknown/cross-tenant order → the standard 404 and no event row; internal note is not in storefront response; `notify` + `visibility=internal` → 422. Admin `show()` returns every event including `actor_uuid` and `visibility` for its tenant-scoped order.
- [ ] **Step 3: AdminRefundController** (constructor style copied from `AdminOrderController` — context + soft-resolved deps + tenant resolver):

```php
public function store(CreateRefundData $input, Request $request, string $uuid): Response
{
    $key = trim((string) $request->headers->get('Idempotency-Key', ''));
    if ($key === '' || strlen($key) > 128) {
        return Response::validation(['idempotency_key' => 'A non-empty Idempotency-Key header (max 128 chars) is required.']);
    }
    $lines = $this->validateLines($input->lines);         // shape-check each element, 422 on bad shape
    try {
        $refund = $this->refunds->issue(
            $this->context,
            $uuid,
            new RefundInput($input->amount, $input->reason, $lines, $input->restock),
            $key,
            $this->actorUuid($request)                     // canonical auth.user UserIdentity; null if absent
        );
        return Response::success($refund, 'Refund recorded');
    } catch (IdempotencyConflictException | ConcurrentRefundException $e) {
        return Response::error($e->getMessage(), 409);
    } catch (RefundValidationException $e) {
        return Response::validation(['refund' => $e->getMessage()]);
    } catch (RefundOutcomeUnknownException $e) {
        return Response::error($e->getMessage(), 503);
    }
}

public function index(Request $request, string $uuid): Response
{
    // 404 for unknown/cross-tenant order first (non-revealing), then list.
}
```

  `addNote()` on AdminOrderController: first resolve the order through `findByUuid($context, $tenant, $uuid)` and emit the standard non-revealing 404 when absent; only then validate `notify` ⇒ `visibility === 'customer'` (else 422) and call `recordEvent(...)`. Build the event note payload with `body`, `visibility`, `notify`, and actor; when `notify`, dispatch `OrderNoteAdded` after persist (direct dispatch is valid because `recordEvent` is not transactional). Actor UUID comes from the framework-guaranteed `auth.user` attribute when it is a `Glueful\Auth\UserIdentity`, via `$identity->uuid()`; do not depend on the legacy raw `'user'` array shape.
  `eventsForOrder($c, $tenant, $uuid)` joins `commerce_order_events` to `commerce_orders` and constrains both tenant and order UUID. `show()` appends those events after its existing tenant-scoped order guard.
- [ ] **Step 4: routes.php** — inside the admin group: `POST /orders/{uuid}/refunds` ($write), `GET /orders/{uuid}/refunds` ($read), `POST /orders/{uuid}/notes` ($write); delete the `mark-refunded` line and the `markRefunded` method.
- [ ] **Step 5: Run endpoint tests + full suite** — PASS (any test exercising mark-refunded gets rewritten to use the refund endpoint).

### Task 8: Storefront projections

**Files:**
- Modify: `src/Http/Storefront/OrderController.php` (or the service composing its payload — locate where the storefront order response is built)
- Test: `tests/Integration/Http/StorefrontOrderProjectionTest.php`

- [ ] **Step 1: Failing tests** — storefront order lookup on an order with: one completed refund (with an operator reason), one failed refund, one internal note, one customer note. Assert response contains `refunds: [{date, amount_minor, method}]` (completed ONLY, NO reason key), `notes` with only the customer note; assert the serialized response string does not contain the operator reason text at all.
- [ ] **Step 2: Implement** — after `authorizedOrder()` has tenant-scoped the order, refunds projection reads `RefundRepository::listForOrder` filtered to `completed`, mapping ONLY `['date' => completed_at, 'amount_minor' => amount, 'method' => method]`; notes use Task 7's tenant-constrained `OrderRepository::eventsForOrder()` and filter `type === 'note' && visibility === 'customer'`, mapping `['date', 'body']`. Additive keys; existing payload untouched.
- [ ] **Step 3: Run** — PASS.
- [ ] **Step 4: COMMIT (commerce)** — `feat(refunds): refund/note endpoints, storefront projections, OrderFulfilled dispatch`

---

## GROUP D — Invoice + Mail + hardening

### Task 9: Seller identity + invoice-data endpoint

**Files:**
- Create: `src/Invoices/SellerIdentityProvider.php`, `src/Invoices/ConfigSellerIdentityProvider.php`, `src/Invoices/InvoiceData.php`
- Modify: `config/commerce.php` (seller block), `src/Http/Admin/AdminOrderController.php` (`invoiceData()`), `routes.php` (GET invoice-data, $read), provider registration
- Modify: `src/Orders/OrderRepository.php` — add `linesForOrder($c, $tenant, $orderUuid)` joined through `commerce_orders`; no bare child-table lookup
- Test: `tests/Integration/Http/InvoiceDataTest.php`, `tests/Unit/Support/ConfigSellerIdentityProviderTest.php`

- [ ] **Step 1: Config block** (null-tolerant):

```php
'seller' => [
    'name' => env('COMMERCE_SELLER_NAME'),
    'address' => env('COMMERCE_SELLER_ADDRESS'),
    'tax_id' => env('COMMERCE_SELLER_TAX_ID'),
],
```

- [ ] **Step 2: Port + default** — interface exactly as in the spec §7; `ConfigSellerIdentityProvider` reads `config($context, 'commerce.seller.*')` ignoring `$tenantUuid`. Provider binds the interface to the config default (`shared`).
- [ ] **Step 3: Failing test** — invoice payload has `schema_version: 1`; all money keys `*_minor` are integers; `refunds` contains completed only, keys `{date, amount_minor, method}` (no reason); null seller fields serialize as nulls, not missing keys.
- [ ] **Step 4: `InvoiceData::build(context, order, lines, refunds, seller): array`** — pure assembly per spec §7 (buyer from `order.email` + `order.addresses`; totals from order columns + `refunded_minor` = refunded_total). Controller: resolve tenant, 404-guard the order, fetch lines via tenant-constrained `linesForOrder()`, refunds via tenant-constrained `listForOrder()`, and seller via `forTenant($context, $tenant)`, then `Response::success(InvoiceData::build(...))`.
- [ ] **Step 5: Run** — PASS.

### Task 10: CommerceMailer + listeners + templates

**Files:**
- Create: `src/Mail/CommerceMailer.php` (interface per spec §6), `src/Mail/NotificationCommerceMailer.php`, `src/Mail/OrderNotifiable.php`, `src/Mail/MailTemplates.php`, `src/Mail/OrderMailListener.php`
- Modify: `config/commerce.php` (email block), `src/CommerceServiceProvider.php` (bind mailer; register listeners in `boot()` via `EventService::addListener` — container-checked), `src/Support/DiagnosticsReport.php` (email active/inactive line)
- Test: `tests/Unit/Mail/MailTemplatesTest.php`, `tests/Integration/Mail/OrderMailListenerTest.php`

- [ ] **Step 1: Config**

```php
'email' => [
    'enabled' => (bool) env('COMMERCE_EMAIL_ENABLED', false),   // master switch: OFF
    'templates' => [
        'order_placed' => true, 'order_paid' => true, 'order_fulfilled' => true,
        'order_refunded' => true, 'order_note' => true,
    ],
],
```

- [ ] **Step 2: `OrderNotifiable`** — implements the verified five-method `Glueful\Notifications\Contracts\Notifiable` contract: `routeNotificationFor(string)` (`email` → order email, otherwise null), `getNotifiableId()` (order UUID), `getNotifiableType()` (`commerce_order`), `shouldReceiveNotification()` (true only for email), and `getNotificationPreferences()` (`['email' => true]`).
- [ ] **Step 3: `MailTemplates::render(string $template, array $order, array $payload): array{subject:string,body:string}`** — plain string building (order number, totals via `Money` display helpers, tracking ref for fulfilled, amount + partial/full for refunded, note body for order_note). NO operator refund reason anywhere. Unit-test each template's subject + key facts.
- [ ] **Step 4: `NotificationCommerceMailer`** — soft posture:

```php
public function send(ApplicationContext $context, string $template, array $order, array $payload = []): void
{
    if (!(bool) config($context, 'commerce.email.enabled', false)) return;
    if (!(bool) config($context, 'commerce.email.templates.' . $template, true)) return;
    $container = container($context);
    $dispatcher = $container->get(NotificationDispatcher::class); // core binding is always present
    $channels = $dispatcher->getChannelManager();
    if (!in_array('email', $channels->getActiveChannelNames(), true)) {
        $this->markInactiveOnce();
        return;
    }
    try {
        $rendered = MailTemplates::render($template, $order, $payload);
        $container->get(NotificationService::class)->send(
            'commerce.' . $template,
            new OrderNotifiable($order),
            $rendered['subject'],
            ['body' => $rendered['body'], 'order_uuid' => $order['uuid']],
            ['channels' => ['email']]
        );
    } catch (\Throwable $e) {
        // Log-only: mail must never fail a persisted order operation.
        error_log('[commerce] mail send failed: ' . $e->getMessage());
    }
}
```

  The channel option is source-verified: `NotificationService::send(..., ['channels' => ['email']])`. `NotificationService`/`NotificationDispatcher` presence alone is not an email-availability signal because core binds them with the database channel; availability is `email ∈ ChannelManager::getActiveChannelNames()`.
- [ ] **Step 5: `OrderMailListener`** — one class, one method per event mapping to the template table in spec §6; `OrderNoteAdded` sends only when the note payload carried `notify`. Every handler catches `\Throwable` around the `CommerceMailer` call and logs it, so even a host-rebound throwing mailer cannot escape event dispatch. Registered in provider `boot()`:

```php
if ($container->has(EventService::class)) {
    $events = $container->get(EventService::class);
    $listener = $container->get(OrderMailListener::class);
    $events->addListener(OrderPlaced::class, [$listener, 'onOrderPlaced']);
    // ... OrderPaid, OrderFulfilled, RefundCompleted, OrderNoteAdded
}
```

- [ ] **Step 6: Diagnostics** — add `email` line: `disabled` when the master switch is off; otherwise `active` only when `NotificationDispatcher::getChannelManager()->getActiveChannelNames()` contains `email`, and `inactive` when it does not. Do not use `NotificationService` presence as the signal.
- [ ] **Step 7: Failing→passing integration tests** — with a recording fake bound as `CommerceMailer`: `OrderPaid` triggers `order_paid`; refund completion triggers `order_refunded` WITHOUT the reason string. For the default mailer, register a recording active `email` channel and assert it, not the default database channel, receives the notification; remove/disable that channel and assert diagnostics says inactive + send is a no-op; master switch off sends nothing even with the active email channel. Listener exceptions do not propagate (bind a throwing mailer, complete a refund, assert refund persisted).
- [ ] **Step 8: Run** — PASS.

### Task 11: Tenancy tests, regression gate, docs

**Files:**
- Create: `tests/Integration/Tenancy/RefundTenancyTest.php`
- Modify: `docs/woocommerce-migration-comparison.md` (move refunds/notes/emails to "can migrate"), `README.md` if it lists endpoints
- Verify: full suites in both repos

- [ ] **Step 1: Two-tenant test** — same fixture shapes under tenants A and B (use `FailClosedTenantResolver`/explicit tenant values like existing `tests/Integration/Tenancy` tests): tenant B cannot see/replay/settle/list A's refunds, add/read A's notes, or fetch A's invoice data (all standard 404s); the SAME idempotency key used by both tenants creates two independent refunds (unique is per-tenant); `TenantAdopter`/diagnostics cover `commerce_refunds`.
- [ ] **Step 2: Regression gate** — full commerce suite: `composer test` green; grep assertion that no test needed changes except mark-refunded rewrites and MigrationsTest (list any others in the report — they indicate accidental behavior change).
- [ ] **Step 3: Email-off gate** — with `commerce.email.enabled=false` + an active recording email channel registered, run the default-mailer integration test's "no send" case.
- [ ] **Step 4: Docs** — comparison doc: refunds (partial+restock), order notes, transactional email move from gaps to "What Can Migrate Today" with one-line caveats (email off by default; gateway refunds need a collector).
- [ ] **Step 5: Quality gates** — `composer test && composer phpcs && composer phpstan` both repos, all clean.
- [ ] **Step 6: COMMIT (commerce)** — `feat(orders): invoice data, transactional email, tenancy hardening for refunds`

---

## Self-Review Notes

- Spec §4.1/4.2 rules are encoded in Task 4/5 (order-level `refund_revision` claim, validation list, capacity re-check, pending-status claim, after-commit dispatch). Spec §5 notes → Task 7. §6 email → Task 10 (master OFF default + active-email-channel probe). §7 invoice → Task 9 (schema_version, `*_minor`). §8 routes/scopes → Task 7/9. §10 test matrix → Tasks 3–5, 7–11.
- Previously open source details are resolved: duplicate violations are rethrown `PDOException`s; `in:internal,customer` is valid while numeric `min` is not; `Notifiable` has five methods; notification channel selection is the fifth `send()` argument `['channels' => ['email']]`; actor identity uses guaranteed `auth.user`/`UserIdentity::uuid()`; transaction-state tests use `Connection::withinTransaction()`.
- `commerce_refund_lines` carries no tenant column by design — every read path uses `linesFor($context, $tenant, $refundUuid)`, which joins through a tenant-scoped refund. No repository method accepts a bare refund-lines query.
