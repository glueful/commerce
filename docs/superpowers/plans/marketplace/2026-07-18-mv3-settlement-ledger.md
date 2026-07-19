# Commerce Marketplace MV3 — Commission & Settlement Ledger — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to
> implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every payment-confirmed and refund-completed partitioned order posts commission and
settlement facts to an append-only per-account ledger atomically with the lifecycle transition;
balances derive from the ledger; operators run manual payouts and audited commission-policy changes;
sellers and operators get financial reports — all inert when marketplace is off.

**Architecture:** Commission policy resolves product→seller→workspace→config and snapshots immutably
onto order lines at checkout; a `LedgerPostingService` posts `sale_credit`/`commission_debit` inside
`markPaid()`'s transaction and `refund_debit`/`commission_reversal` inside `applyCompletion()`'s, each
serialized by a `(tenant, account_key, currency)` account lock; balances are a signed `SUM` over the
ledger; manual payouts and operator adjustments post further entries; a read-only command reconciles.

**Tech Stack:** PHP 8.3, Glueful framework, PHPUnit 10, SQLite (suite) + PostgreSQL (race lanes).

**Spec:** `docs/superpowers/specs/marketplace/2026-07-18-mv3-settlement-ledger-design.md` (authoritative;
every §-reference points into it).

## Global Constraints

*(Every task's requirements implicitly include this section. Exact values from the spec.)*

- **MARKETPLACE-OFF / NON-PARTITIONED = BYTE-IDENTICAL.** When marketplace is off or an order's
  `marketplace_partitioned` is false, `markPaid`/`applyCompletion`/checkout post nothing and execute
  **zero** ledger/lock/payout queries; route manifest unchanged when off. All behavior branches on the
  order's own `marketplace_partitioned` snapshot, never current `activeFor`.
- **INTEGER MINOR UNITS** throughout; `amount` columns bigInt. Ledger `amount` is **signed**.
- **COMMISSION MATH (§2.1).** Per line `commission_basis = max(0, line_total − discount_amount)`
  (shipping/shipping-discount/tax excluded). `percentage`: `intdiv(basis × bps + 5000, 10000)`;
  `fixed`: `min(commission_fixed, basis)`. Seller-order `commission_amount` = exact sum of its lines.
- **COMMISSION POLICY (§2.2).** Typed nullable `commission_kind` (`percentage|fixed`), `commission_bps`
  (0..10000), `commission_fixed` (≥0) on products/sellers/settings + `config/commerce.php`
  `marketplace.commission` (same shape). Validation: all-null ⇒ inherit next level; `percentage` needs
  only `bps`; `fixed` needs only `fixed`; mixed rejected. Service validation authoritative on every
  driver; PostgreSQL/SQLite CHECKs only (MySQL has none). Precedence **product → seller → workspace
  → config**. The config tail is concrete and defaults to `{percentage, 0, null}`; it may never be
  all-null, so policy resolution is total.
- **COMMISSION AUTHORITY (§2.3).** Setting policy is **operator-only**, with a **durable append-only
  `commerce_commission_policy_events`** audit record written in the SAME transaction as the policy
  mutation (audit-insert failure rolls back the mutation). `CommissionPolicyChanged` is an OPTIONAL
  after-commit signal, NOT the audit authority. Sellers may READ effective + snapshotted policy but
  never mutate; the seller catalog write path rejects any commission field with a field-specific
  `422` on **both create and update** (raw-body inspection + `CatalogService::updateSellerProduct`
  backstop). `403` is reserved for missing route capability.
- **CHECKOUT SNAPSHOT (§2.4).** Snapshot per order line `{commission_source, commission_kind,
  commission_bps, commission_fixed, commission_basis, commission_amount}` + seller-order
  `commission_amount` sum, immutable. Widen `CartService::pricedLines()` projection (3 product
  commission cols), add a `MarketplaceMode` settings-row accessor, and extend the
  `OrderRepository::orderLineRow()` + `SellerOrderRepository::insertForOrder()` insert whitelists or
  the snapshot won't persist.
- **LEDGER (§2.5).** Append-only `commerce_marketplace_ledger`; account identity `(tenant_uuid,
  account_key, currency)` with canonical non-null `account_key` = `seller:{uuid}` | `marketplace` and
  explicit `account_kind`. Signed `amount`; closed `entry_type` vocab. **Deterministic idempotency**
  (`{order|refund|payout}_uuid`:`{seller_uuid|account_key}`:`{entry_type}`); a duplicate
  `(tenant, idempotency_key)` triggers a **verify** — existing row must match expected amount/currency/
  account plus every immutable semantic field (`entry_type`, seller/source UUIDs, reason, actor)
  exactly, else integrity failure (never a new row). Never updated/deleted.
- **ACCOUNT LOCK (§2.6).** Every balance-affecting posting claims the `(tenant, account_key, currency)`
  lock in `commerce_ledger_account_locks` (revision-only anchor, savepoint-guarded lazy create — the
  MV1 `MarketplaceWorkspaceLock` idiom). Multi-account transactions claim in **sorted `account_key`
  then `currency`** order.
- **PAYMENT POSTING (§2.7).** Inside `markPaid()`'s txn (after `confirmation->confirm()`, before the
  `OrderPaid` afterCommit): per seller (sorted, lock-claimed) post `sale_credit = +attributed_total`
  and `commission_debit = −commission_amount`. Any ledger failure rolls back the paid CAS.
- **REFUND POSTING (§2.8).** Inside `applyCompletion()`'s txn (after the `refunded_total` CAS): partial
  refund on a partitioned order requires line attribution (else `422`); full-remaining auto-expands.
  At completion time after the order financial claim, derive `R_before` from completed refund history
  excluding the current refund UUID, then per line
  `delta_R = min(refund_line.amount, max(0, commission_basis − R_before))`;
  `refund_debit` per seller = sum of `delta_R` (merchandise-capped, never raw input);
  `commission_reversal` per line = `target_after − target_before` where
  `target = min(C, intdiv(C × R + intdiv(B, 2), B))` (0 when B=0), grouped by seller. Any
  `refund_line.amount − delta_R` and other unattributable minor units post to the **marketplace
  account**; `Σ abs(seller refund_debit) + abs(marketplace refund_debit) = refund amount`.
- **BALANCES (§2.9).** Derived, currency-separated: `available = SUM(all signed entries)`;
  `reserved = −(S(reserve_hold)+S(reserve_release))`; `paid_out = −(S(payout_debit)+S(payout_reversal))`;
  lifetime `gross_sales/commission/refunds/commission_reversed/adjustments`. No `pending`. No stored
  mutable balance.
- **PAYOUT (§2.10).** Requires positive amount, non-empty external reference, non-null actor, and
  idempotency key. One transaction: claim seller account lock → recheck available under lock →
  refuse if `amount > available` (`422`) → insert `commerce_payouts` row → insert `payout_debit`.
  Duplicate idempotency verifies **both** the payout row AND its ledger entry across every immutable
  request field; mismatch = integrity failure.
- **ADJUSTMENTS (§2.10).** Signed non-zero `adjustment` with mandatory reason + actor + request
  idempotency key; matching replay verifies/no-ops, mismatched replay integrity-fails; may drive a
  balance negative; never edit/delete — compensating entries only. `reserve_hold`/`reserve_release`
  are vocabulary only (no engine).
- **RECONCILIATION (§2.11).** Read-only command scans DIRECTLY (never by parent order status): every
  seller-order partition with `confirmed_at IS NOT NULL`, every completed refund on a partitioned
  order, every payout — reports missing/
  duplicate/mismatched posting sets; never posts.
- **MIGRATIONS (§3, fold convention — no external installs).** Fold commission columns into `001`
  (products), `004` (order_lines), `010` (sellers + settings), `011` (seller_orders); new
  `012_CreateMarketplaceLedgerTables` (ledger + account-locks + commission-policy-events),
  `013_CreatePayoutTable`; all four new tables declare `tenant_uuid` `default('')` (tenant-adopt
  sentinel). No `hasIndex` on the builder — assert indexes via driver introspection. Register the
  four new tables in `DiagnosticsReport` (commerceTables + tenantTables).
- **HOUSE STYLE.** `use` imports; `UtcNowSql`; `Response`/DTO idiom; append optional nullable
  collaborators to existing final services (never subclass); phpcs + PHPStan clean. No AI attribution.
  Commit only the commerce repo; leave `docs/superpowers/**` and `.superpowers/**` unstaged.

---

## GROUP A — Commission: schema, resolution, snapshot, authority

### Task 1: Migrations + config + shape tests
**Files:** fold commission columns into `migrations/001` (products), `004` (order_lines), `010`
(sellers + settings), `011` (seller_orders); create `migrations/012_CreateMarketplaceLedgerTables.php`
(`commerce_marketplace_ledger` §3.2, `commerce_ledger_account_locks` §3.3,
`commerce_commission_policy_events` §3.4) and `migrations/013_CreatePayoutTable.php`
(`commerce_payouts` §3.5); modify `config/commerce.php` (`marketplace.commission`),
`src/Support/DiagnosticsReport.php` (+4 tables), `tests/Support/CommerceTestCase.php` (`MIGRATIONS`);
create `tests/Integration/Migrations/SettlementShapeTest.php`. **NO commit until Task 4.**

**Interfaces (later tasks consume EXACTLY):** columns/uniques/indexes exactly per §3.1–3.5; all four
new tables' `tenant_uuid` `default('')`; ledger unique `(tenant_uuid, idempotency_key)`; account-lock
unique `(tenant_uuid, account_key, currency)`; payout unique `(tenant_uuid, idempotency_key)`.

- [ ] TDD: shape test asserts folded columns (defaults) on products/order_lines/sellers/settings/
  seller_orders; the three §3.2/3.3/3.4 tables' columns/uniques/indexes (indexes via `PRAGMA
  index_list`) + `tenant_uuid default('')`; payouts table §3.5; PostgreSQL/SQLite CHECK presence noted
  (SQLite path here, pgsql in Task 12); re-run idempotency of the new migrations. → implement → GREEN.
  Full suite green; NO commit.

### Task 2: `CommissionPolicyResolver` + `CommissionCalculator` (pure)
**Files:** create `src/Marketplace/CommissionPolicyResolver.php`, `src/Marketplace/CommissionCalculator.php`,
`src/Marketplace/CommissionPolicyException.php` (validation); create
`tests/Unit/Marketplace/{CommissionPolicyResolverTest,CommissionCalculatorTest}.php`. **NO commit until Task 4.**

**Interfaces:**
- `CommissionPolicyResolver::validate(?string $kind, ?int $bps, ?int $fixed): void` — all-null OK
  (inherit); `percentage` requires only `bps` (0..10000); `fixed` requires only `fixed` (≥0); mixed ⇒
  throw `CommissionPolicyException`.
- `CommissionPolicyResolver::resolve(array $levels): array` — `$levels` = ordered
  `[product, seller, workspace, config]` each `{kind:?string, bps:?int, fixed:?int}`; returns the first
  with non-null `kind` as `{source: 'product'|'seller'|'workspace'|'config', kind, bps, fixed}`; an
  all-null config tail is invalid configuration. Default config is 0% percentage.
- `CommissionCalculator::lineCommission(int $lineTotal, int $discountAmount, array $policy): array` →
  `{commission_basis:int, commission_amount:int}` per §2.1. `perSeller(array $lines): array<seller,int>`
  asserts seller sum = Σ its lines (hard reconciliation).

- [ ] TDD: validation matrix (all-null inheritable DB level, percentage/fixed shape, mixed rejected,
  bps bounds, negative fixed); precedence resolution across every level; all-null config rejected;
  default config resolves 0% percentage; basis
  `max(0,...)`; percentage half-up rounding; fixed capped at basis; seller sum reconciliation; B=0 line.
  → implement → GREEN. NO commit.

### Task 3: Checkout commission snapshot
**Files:** modify `src/Cart/CartService.php` (widen `pricedLines()` projection with product
`commission_kind/bps/fixed`), `src/Marketplace/MarketplaceMode.php` (add `settingsRowFor(c, tenant):
?array` returning the settings row), `src/Orders/CheckoutService.php` (resolve per line + snapshot,
inside the partition write alongside `SellerAllocationCalculator`), `src/Orders/OrderRepository.php`
(`orderLineRow()` whitelist + 6 commission cols), `src/Marketplace/SellerOrderRepository.php`
(`insertForOrder()` whitelist + `commission_amount`); create
`tests/Integration/Marketplace/CommissionSnapshotTest.php`. **NO commit until Task 4.**

**Interfaces:**
- Each persisted order line carries `commission_source/kind/bps/fixed/basis/amount`; each seller-order
  carries `commission_amount` = sum of its lines. Consumed by Task 6 (payment) and Task 7 (refund).
- `MarketplaceMode::settingsRowFor(ApplicationContext $c, string $tenant): ?array` (O(1)).

- [ ] TDD: partitioned checkout snapshots the resolved per-line policy (source/kind/bps/fixed/basis/
  amount) and the seller-order sum; precedence honored end-to-end (product override vs seller vs
  workspace vs config); snapshot immutable (edit policy after → order unchanged); non-partitioned
  checkout writes only 0-default commission columns and reads zero extra commission surfaces beyond the
  already-loaded rows. → implement → GREEN. NO commit.

### Task 4: Commission-policy authority + durable audit — **GROUP A COMMIT**
**Files:** create `src/Marketplace/CommissionPolicyService.php`,
`src/Marketplace/CommissionPolicyEventRepository.php`, `src/Events/CommissionPolicyChanged.php`; modify
`src/Catalog/CatalogService.php` (operator `updateProduct` commission handling; `updateSellerProduct`
commission-field rejection backstop), `src/Marketplace/SellerService.php` (operator seller commission
handling), `src/Http/Admin/*` (operator commission mutation endpoints + workspace-settings commission),
`src/Http/Seller/SellerCatalogController.php` (raw-body commission-field `422` on create + update),
`routes.php`, `src/CommerceServiceProvider.php`; create
`tests/Integration/Marketplace/CommissionAuthorityTest.php`.

**Interfaces:**
- `CommissionPolicyService::setProduct/setSeller/setWorkspace(c, tenant, subjectUuid, {kind,bps,fixed},
  actorUuid): void` — validates (Task 2 resolver), applies the policy AND inserts the
  `commerce_commission_policy_events` row `{subject_kind, subject_uuid, actor_uuid, before_policy,
  after_policy}` in ONE transaction (audit-insert failure rolls back the mutation); optional
  `CommissionPolicyChanged` after-commit dispatch.
- `CommissionPolicyEventRepository::insert(...)`/`list(...)` — insert/list only, no update/delete.
- Seller catalog write rejects any of `commission_kind|commission_bps|commission_fixed` with a
  field-specific `422` (raw-body check + service backstop).

- [ ] TDD: operator sets product/seller/workspace policy → policy applied + audit row written in one
  txn; forced audit-insert failure rolls back the policy change (neither persists); seller cannot set
  commission via product create OR update (`422`, field-specific) + service backstop; seller lacking
  route capability → `403` (not `422`); audit rows append-only (no update/delete surface); event
  optional (a failed/unbound after-commit dispatch doesn't affect audit durability). → implement →
  GREEN. Full suite + phpcs + analyze.
- [ ] **COMMIT (Group A):** `feat(marketplace): mv3 commission policy, snapshot, and durable audit`

---

## GROUP B — Ledger core + paid/refund posting

### Task 5: `LedgerAccountLock` + `LedgerRepository`
**Files:** create `src/Marketplace/LedgerAccountLock.php`, `src/Marketplace/LedgerRepository.php`,
`src/Marketplace/LedgerException.php` (integrity failure); create
`tests/Integration/Marketplace/LedgerRepositoryTest.php`. **NO commit until Task 7.**

**Interfaces (consumed by Tasks 6–11):**
- `LedgerAccountLock::claim(ApplicationContext $c, string $tenant, string $accountKey, string $currency):
  void` — savepoint-guarded lazy first-row create + affected-row-checked revision bump (reuse the MV1
  `MarketplaceWorkspaceLock` pattern; compound key `(tenant, account_key, currency)`). Callers claim
  multiple in sorted `account_key`,`currency` order.
- `LedgerRepository::post(ApplicationContext $c, string $tenant, array $entry): void` — append-only
  insert with deterministic `idempotency_key`; a duplicate `(tenant, idempotency_key)` triggers a
  **verify** (all immutable semantic fields match ⇒ idempotent no-op, else throw `LedgerException`):
  account identity, amount, currency, entry type, source UUIDs, reason, and actor. `$entry` =
  `{account_kind, account_key, seller_uuid?, currency, entry_type,
  amount(signed), order_uuid?, seller_order_uuid?, refund_uuid?, payout_uuid?, idempotency_key,
  reason?, created_by?}`.
- `LedgerRepository::balanceComponents(c, tenant, accountKey, currency): array` (the §2.9 sign formulas);
  `LedgerRepository::entriesForOrder/Refund/Payout(...)` (reconciliation scans).
- Account-key helper: `seller:{uuid}` for a seller, literal `marketplace`.

- [ ] TDD: append-only insert; deterministic idempotency (same key twice ⇒ one row, second verifies);
  duplicate with a mismatch in each semantic field (including type/reference/reason/actor) ⇒
  `LedgerException`; signed amount round-trips; balanceComponents formulas
  across every entry_type; account-lock claims one row under two concurrent first-claims (deterministic
  here; pgsql lane Task 12); marketplace vs seller account keys. → implement → GREEN. NO commit.

### Task 6: Payment posting (`LedgerPostingService::postSale`)
**Files:** create `src/Marketplace/LedgerPostingService.php`; modify `src/Orders/OrderPaymentService.php`
(append optional `?LedgerPostingService` collaborator; call `postSale` after `confirmation->confirm()`,
before the `OrderPaid` afterCommit, gated on `marketplace_partitioned`), `src/CommerceServiceProvider.php`;
create `tests/Integration/Marketplace/PaymentPostingTest.php`. **NO commit until Task 7.**

**Interfaces:**
- `LedgerPostingService::postSale(ApplicationContext $c, string $tenant, array $order, array $sellerOrders):
  void` — for each seller (sorted): claim the seller account lock, post `sale_credit = +attributed_total`
  (idempotency `{order}:{seller}:sale_credit`) and `commission_debit = −commission_amount` (idempotency
  `{order}:{seller}:commission_debit`). Runs inside `markPaid`'s transaction; propagates
  `LedgerException` (rolls back the paid CAS). Zero work + zero queries when not partitioned.

- [ ] TDD: partitioned paid transition posts per-seller `sale_credit`+`commission_debit`; Σ reconciles
  (sale credits = Σ attributed_total, commission debits = Σ commission_amount); deterministic
  idempotency (a re-run/replay posts no duplicate, verifies); both callers (provider handler + admin)
  route through it; non-partitioned paid transition executes ZERO ledger/lock queries (query count);
  account locks claimed in sorted order (multi-seller); a forced ledger failure rolls back the paid
  status. → implement → GREEN. NO commit.

### Task 7: Refund posting + `MarketplaceRefundGuard` — **GROUP B COMMIT**
**Files:** create `src/Marketplace/MarketplaceRefundGuard.php`; modify
`src/Orders/Refunds/RefundService.php` (append optional `?MarketplaceRefundGuard` + `?LedgerPostingService`
collaborators; marketplace-aware validation at the `validateLines()` call site; `postRefund` after the
`refunded_total` CAS), `src/CommerceServiceProvider.php`; add `LedgerPostingService::postRefund`; create
`tests/Integration/Marketplace/RefundPostingTest.php`.

**Interfaces:**
- `MarketplaceRefundGuard::validateAndNormalize(ApplicationContext $c, string $tenant, array $order,
  int $amount, array $validatedInputLines): array` — called during issue/reserve after the existing
  order financial claim. For a `marketplace_partitioned` order: reject a line-less/under-attributed
  PARTIAL refund (`422`) and auto-expand a FULL-remaining refund across refundable seller lines;
  return normalized persisted refund lines only. It does **not** compute `delta_R`, because a gateway
  refund may remain pending before completion. Non-partitioned ⇒ passthrough unchanged.
- `LedgerPostingService::postRefund(ApplicationContext $c, string $tenant, array $order, array $refund,
  array $persistedRefundLines): void` — inside `applyCompletion()` after the existing order financial
  claim, derive `R_before` from completed refund history excluding the current refund UUID and compute
  each `delta_R`; then per seller (sorted, lock-claimed): `refund_debit = −Σ delta_R`
  (idempotency `{refund}:{seller}:refund_debit`), `commission_reversal = +Σ per-line (target_after −
  target_before)` from the immutable snapshot + prior completed-refund history (idempotency
  `{refund}:{seller}:commission_reversal`); marketplace-funded remainder → marketplace account
  `refund_debit` (idempotency `{refund}:marketplace:refund_debit`). Inside `applyCompletion`'s txn.

- [ ] TDD: partial refund without lines on a partitioned order → `422`; full-remaining auto-expands;
  a gateway refund that remains pending while another refund completes derives `delta_R` from fresh
  history only when it later completes; current refund is excluded from `R_before`; `delta_R` caps at
  remaining basis (over-large/tax-inclusive input can't over-attribute); per-line
  cumulative reversal across differing product rates (multiple partials sum to each line's exact
  snapshot; full line reverses exactly `C`); marketplace-funded remainder to the marketplace account;
  `Σ abs(seller refund_debit) + abs(marketplace refund_debit) = refund amount`; idempotent
  double-completion posts
  once; non-partitioned refund byte-identical (no ledger queries). → implement → GREEN. Full suite +
  phpcs + analyze.
- [ ] **COMMIT (Group B):** `feat(marketplace): settlement ledger and paid/refund posting`

---

## GROUP C — Balances, payouts, adjustments

### Task 8: `SellerBalanceService`
**Files:** create `src/Marketplace/SellerBalanceService.php`; create
`tests/Integration/Marketplace/BalanceTest.php`. **NO commit until Task 9.**

**Interfaces:**
- `SellerBalanceService::balance(ApplicationContext $c, string $tenant, string $sellerUuid, string $currency):
  array` → `{available, reserved, paid_out, gross_sales, commission, refunds, commission_reversed,
  adjustments}` per §2.9 exact sign formulas (delegates to `LedgerRepository::balanceComponents`).
  `available(...)` convenience for the payout check. Currency-separated; marketplace-account balance for
  operator surfaces.

- [ ] TDD: exact sign formulas across all entry types (sale/commission/refund/reversal/adjustment/
  reserve/payout); currency separation (two currencies independent); negative balance via adjustment;
  `available` excludes reserved. → implement → GREEN. NO commit.

### Task 9: `PayoutService` + `AdjustmentService` — **GROUP C COMMIT**
**Files:** create `src/Marketplace/PayoutService.php`, `src/Marketplace/AdjustmentService.php`,
`src/Events/PayoutRecorded.php`, `src/Http/DTOs/{RecordPayoutData,PostAdjustmentData}.php`; modify
operator `src/Http/Admin/*` (payout + adjustment endpoints), `routes.php`,
`src/CommerceServiceProvider.php`; create `tests/Integration/Marketplace/{PayoutTest,AdjustmentTest}.php`.

**Interfaces:**
- `PayoutService::record(ApplicationContext $c, string $tenant, string $sellerUuid, string $currency,
  int $amount, string $idempotencyKey, string $externalRef, ?string $note, string $actorUuid): array`
  — one transaction: claim seller account lock → recheck available under lock → refuse (`422`) if
  `amount > available` or `amount <= 0` → insert `commerce_payouts` row → post `payout_debit`
  (`{payout}:{seller}:payout_debit`). Duplicate idempotency verifies BOTH the payout row and its ledger
  entry across seller/amount/currency/external-ref/note/actor (mismatch ⇒ integrity failure).
- `AdjustmentService::post(ApplicationContext $c, string $tenant, string $accountKey, string $currency,
  int $signedAmount, string $reason, string $idempotencyKey, string $actorUuid): void` — non-zero signed
  `adjustment` with mandatory reason/actor/idempotency; ledger key
  `adjustment:{accountKey}:{idempotencyKey}`; may drive negative; append-only (no edit/delete).

- [ ] TDD: payout atomic (row + recheck + debit one txn; forced failure after row rolls back both);
  refuse over-available (`422`); refuse zero/negative or missing external-ref/actor (`422`); idempotent
  duplicate verifies every immutable payout field plus its entry (mismatch integrity-fails);
  adjustment posts signed entry, rejects zero, requires reason/actor/idempotency, matching replay
  verifies/no-ops, mismatched replay integrity-fails, may go negative, compensating-only. → implement →
  GREEN. Full suite + phpcs + analyze.
- [ ] **COMMIT (Group C):** `feat(marketplace): derived balances, manual payouts, and adjustments`

---

## GROUP D — Reconciliation, reports, surfaces

### Task 10: `ReconciliationService` + console command
**Files:** create `src/Marketplace/ReconciliationService.php`,
`src/Console/MarketplaceReconcileCommand.php`; modify `src/CommerceServiceProvider.php` (command
discovery if needed); create `tests/Integration/Marketplace/ReconciliationTest.php`. **NO commit until Task 11.**

**Interfaces:**
- `ReconciliationService::scan(ApplicationContext $c, string $tenant): array` — READ-ONLY; scans (a)
  every `commerce_seller_orders` row with `confirmed_at IS NOT NULL` ⇒ expect its
  `sale_credit`+`commission_debit`; (b)
  every completed refund on a partitioned order ⇒ expect `refund_debit`+`commission_reversal`
  (+ marketplace-funded); (c) every payout ⇒ expect matching `payout_debit`. Returns
  `{missing[], duplicate[], mismatched[]}`. Never posts.
- `commerce:marketplace:reconcile` console command prints the report (mirror existing console command
  idiom; `BaseCommand`).

- [ ] TDD: clean ledger ⇒ empty report; a deliberately missing sale posting detected; a duplicated
  entry detected; a mismatched amount detected; refunds scanned even though the order is still `paid`
  (partial refund); payout-without-debit detected; service posts nothing. → implement → GREEN. NO commit.

### Task 11: Financial reports + seller/operator surfaces — **GROUP D COMMIT**
**Files:** create `src/Reports/SellerFinancialReportRepository.php`,
`src/Http/Seller/SellerFinancialController.php`, operator financial controller(s); modify
`src/Marketplace/FixedSellerRoleAuthority.php` (add `commerce.seller.reports.read` +
`commerce.seller.payouts.read` to owner/admin/analyst), `routes.php`, `src/CommerceServiceProvider.php`;
create `tests/Integration/Marketplace/{SellerFinancialSurfaceTest,OperatorFinancialSurfaceTest}.php`.

**Interfaces:**
- Seller (`/commerce/seller/{sellerUuid}/...`, `commerce_seller` middleware): `GET` financial report
  (gross/commission/net/refunds/reversals/balance over a window), `GET` balance + components, `GET`
  payouts, `GET` effective + snapshotted commission policy — all read-only, own seller only
  (cross-seller `404`). Capabilities `commerce.seller.reports.read` (report/policy/balance),
  `commerce.seller.payouts.read` (payouts).
- Operator: `GET` marketplace financial summary + per-seller balances; order/seller-order breakdown
  gains commission + net.
- `SellerFinancialReportRepository` mirrors `SalesReportRepository`'s windowing over ledger entries.

- [ ] TDD: seller sees own report/balance/payouts/policy; cross-seller `404`; capability × role matrix
  (reports/payouts read for owner/admin/analyst; staff denied per matrix — confirm against §2.6 MV1
  roles); operator marketplace summary; report windowing (day/week/month) + zero-fill; policy read
  shows effective + snapshotted. → implement → GREEN. Full suite + phpcs + analyze.
- [ ] **COMMIT (Group D):** `feat(marketplace): reconciliation, financial reports, and seller surfaces`

---

## GROUP E — Gates

### Task 12: Regression, races, docs — **GROUP E COMMIT**
**Files:** extend `tests/Integration/Marketplace/MarketplaceRegressionTest.php`; create
`tests/Integration/Marketplace/SettlementPgsqlTest.php`; ensure `SettlementShapeTest` convergence runs
under pgsql; modify `tests/Integration/Http/HttpDocumentationTest.php` (flag-ON walk covers new routes),
`CHANGELOG.md` (`[Unreleased]`).

- [ ] Regression: route manifest identical with marketplace off; a NON-partitioned order's
  checkout/payment/refund/fulfill execute ZERO queries against ALL settlement tables
  (`commerce_marketplace_ledger`, `commerce_ledger_account_locks`, `commerce_commission_policy_events`,
  `commerce_payouts` — plus the MV1/MV2 four); `DiagnosticsReport` lists the new tables; tenant-adopt
  rekeys them (sentinel `''`).
- [ ] Live pgsql lanes (env-gated fixture-child harness): concurrent `markPaid` postings to one account
  (lock serializes; no double-post); **payout-vs-refund on one account** (the account lock prevents a
  payout overdrawing when a refund_debit commits concurrently); double-refund-completion idempotency
  (one posting set); concurrent adjustments; two concurrent first-postings to the marketplace account
  produce exactly one lock row. Verbatim pass output in the report.
- [ ] Migration shape live on pgsql: folded commission columns + the four new tables; indexes via
  `pg_indexes`; PostgreSQL CHECK constraints present (commission shape, ledger account_kind/account_key,
  payout positive amount); re-run no-op.
- [ ] CHANGELOG `[Unreleased]`: MV3 commission + settlement ledger — commission policy (operator-only,
  audited), atomic paid/refund posting, derived balances, manual payouts + adjustments, reconciliation,
  financial reports; schema folded into `001/004/010/011` + new `012`/`013`; default-off unchanged.
- [ ] Full suite (SQLite + live pgsql) + phpcs + analyze. **COMMIT (Group E):**
  `feat(marketplace): mv3 gates, races, and regression proof`

---

## Self-Review Notes

- **Spec coverage:** §2.1–2.2 → T2; §2.3 → T4; §2.4 → T3; §2.5 → T5; §2.6 → T5 (lock) + all posters;
  §2.7 → T6; §2.8 → T7; §2.9 → T8; §2.10 → T9; §2.11 → T10; §3 schema → T1; §5 services → T2–T11 as
  mapped; §6 APIs/authority → T4/T9/T11; §7 off-invariance → T12; §8 tests → distributed; §9 file map →
  matches; §10 seams → all RESOLVED in the spec and encoded here.
- **Type consistency:** `commission_basis/amount/source/kind/bps/fixed`, `account_key`/`account_kind`,
  `LedgerRepository::post`/`balanceComponents`, `LedgerAccountLock::claim`,
  `LedgerPostingService::{postSale,postRefund}`, `MarketplaceRefundGuard::validateAndNormalize`,
  `SellerBalanceService::balance`, `PayoutService::record`, `AdjustmentService::post`,
  `CommissionPolicyService::{setProduct,setSeller,setWorkspace}` used identically across tasks.
- **Invariant-bearing subtleties for reviewers:** (a) the §2.5 idempotency **verify** (not silent skip)
  is what makes reconciliation/crash-retry safe — every poster path must verify-on-duplicate (T5/T6/T7/
  T9). (b) The account lock claimed by EVERY balance-affecting posting is what stops a payout overdraw
  (T5/T6/T7/T9; proven T12). (c) `delta_R` merchandise-capping is the only thing keeping a tax-inclusive
  refund-line amount from over-reversing commission, and it must be derived at completion rather than
  gateway-reservation time (T7). (d) The commission-audit row shares the policy mutation's transaction —
  audit failure must roll back the mutation (T4). (e) Everything branches on the order's own
  `marketplace_partitioned`, never `activeFor` (T3/T6/T7). (f) Payouts and adjustments require non-null
  actors and idempotency verification at the service boundary (T9). (g) Reconciliation keys payment
  truth from `commerce_seller_orders.confirmed_at`, never mutable parent status (T10).
