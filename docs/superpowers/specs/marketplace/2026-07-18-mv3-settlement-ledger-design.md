# Commerce Marketplace MV3 — Commission & Settlement Ledger

> **Status:** held / uncommitted. Design authority for the MV3 implementation plan.
> **Predecessors:** MV1 (seller identity/membership/catalog ownership/activation), MV2
> (`2026-07-17-mv2-shared-checkout-design.md` — shared checkout, immutable per-seller order
> partitions, payment confirmation, parent-derived fulfillment).
> Overview: `2026-07-16-multi-vendor-overview-design.md` (§5.2 ledger/commission/payouts, §6.4
> settlement, §6.6 refunds, invariants 6/9/13/14).

## 1. Scope & Non-Goals

**In scope.** Commission policy (resolved at checkout, snapshotted immutably), an append-only
per-account settlement ledger, atomic ledger posting on payment-confirmed and refund-completed,
derived seller balances, manual operator payouts, operator adjustments, a read-only reconciliation
diagnostic, and seller/operator financial reports — all inert when marketplace is off or an order
is non-partitioned.

**Explicitly deferred (do NOT build seams for these now).** Provider-backed payout execution and a
`glueful/extension-contracts` payout/transfer port (**MV4**); automatic reserve policy and
scheduling (**MV5** — `reserve_hold`/`reserve_release` exist as ledger vocabulary only); tiered,
category, and shipping/tax-inclusive commission (later policy types); merchant-of-record tax
withholding; per-currency fixed-rate maps; a materialized balance projection (a later,
rebuildable-from-ledger optimization).

**Tax responsibility (pinned).** MV3 treats **sellers as responsible for their attributed tax**:
`sale_credit` credits the seller's full `attributed_total` (merchandise + shipping + tax), and
commission applies **only** to net merchandise. Merchant-of-record tax withholding is a deferred
concern, explicitly not modeled here.

**Naming guard.** MV2's `attributed_total` remains the customer-attributed amount. MV3 introduces
`commission_amount` (the marketplace's cut) and derives seller **available balance** from the
ledger; there is no stored mutable `seller.balance`.

## 2. Pinned Decisions (authoritative contract)

### 2.1 Commission model & basis

Per **order line**: `commission_basis = max(0, line_total − discount_amount)` (the MV2 per-line value
discount; shipping, shipping-discount, and tax are **outside** the basis). Then:

- `percentage`: `commission_amount = intdiv(commission_basis × commission_bps + 5000, 10000)`
  (half-up, the house rounding idiom).
- `fixed`: `commission_amount = min(commission_fixed, commission_basis)` (one fixed minor-unit
  amount per line, capped at its basis, in the order currency).

Seller-order `commission_amount` = the **exact sum** of its lines' `commission_amount`. A pure
`CommissionCalculator` computes this with the same hard-reconciliation discipline as
`SellerAllocationCalculator` (the per-seller sum must equal the sum of that seller's line commissions,
asserted).

### 2.2 Commission policy — storage, precedence, validation

**Storage (dedicated typed nullable columns):** `commission_kind` (`percentage|fixed`),
`commission_bps` (integer 0..10000), `commission_fixed` (non-negative minor units) on
`commerce_products`, `commerce_sellers`, and `commerce_marketplace_settings`; `config/commerce.php`
`marketplace.commission` holds the **same validated shape** as the final fallback (never bypassed as
an untyped special case). Unlike the three inheritable database levels, the config tail is total:
its default is `{kind: 'percentage', bps: 0, fixed: null}`. It may be configured to another valid
concrete policy, but may not be all-null; checkout therefore always resolves and snapshots a policy.

**Validation:** service validation is authoritative on every driver. PostgreSQL and SQLite additionally
receive column `CHECK` constraints as defense in depth; the framework's MySQL generator does not emit
`ColumnBuilder::check()`, so DB checks are not claimed as a portable guarantee. All three null at a
level = **inherit** the next level; `percentage` requires only `commission_bps` (0..10000); `fixed`
requires only `commission_fixed` (≥0); any mixed/invalid state is rejected.

**Precedence at checkout:** the first level whose `commission_kind` is non-null wins, in order
**product → seller → workspace-settings → config**.

### 2.3 Commission policy — authority (operator-only)

Setting commission policy on a product, seller, or workspace is **platform-operator-only** in MV3;
every mutation has a **durable audit record**. No reusable general-purpose audit service exists
(`commerce_order_events` is order-scoped and unusable here), so MV3 owns a focused append-only
`commerce_commission_policy_events` table. The policy write and its audit row share one transaction;
failure to append the audit row rolls back the policy mutation. The record carries actor, subject
(level + product/seller/workspace UUID), exact before/after typed policy snapshots, and DB time. Rows
are never updated or deleted. After commit, an optional `CommissionPolicyChanged` domain event with
the same payload may be soft-dispatched for notifications/integrations; it is **not** the audit
authority. Actor resolution follows the `MarketplaceActivated`/`ResolvesActor` precedent. The
operator mutation attaches to the existing
`CatalogService::updateProduct` (product) and `SellerService::update` (seller — commission handling
added); the workspace-settings level has **no** existing update surface, so `CommissionPolicyService`
introduces one. **Sellers may inspect**
their effective (resolved) and snapshotted policies but **cannot change their own commission** — and
the seller catalog/product write path (MV1/MV2 `SellerCatalogController`) must **reject** any
commission field with a field-specific `422`, so a seller cannot set commission via product edits.
This is explicit on **both** create and update: create DTO hydration currently ignores unknown keys,
while update reads the raw request body. The controller therefore inspects the raw body before DTO/
patch handling, and `CatalogService::updateSellerProduct()` repeats the rejection as the service-level
backstop. `403` remains reserved for callers lacking the seller route capability.

### 2.4 Checkout commission snapshot (immutable)

At checkout, inside `CheckoutService`'s existing partition write, resolve the policy per line and
snapshot onto `commerce_order_lines`: `commission_source` (`product|seller|workspace|config`),
`commission_kind`, `commission_bps`, `commission_fixed`, `commission_basis`, `commission_amount`; the
seller-order `commission_amount` is the sum. These are **immutable checkout facts** — never rewritten
by later policy edits (mirroring MV2's immutable allocation facts). `commission_reversal` on refund
derives from **these snapshots**, never current policy.

**Resolution inputs (no new per-line queries; verified against source):** the product row is already
fetched per line in `CartService::pricedLines()`, but its returned array is a hand-picked projection —
the three product `commission_*` columns must be **added to that projection** to ride the
already-loaded row. The seller row (`commission_*`) is already re-read by the §2.7-MV2 checkout claim
(`findByUuid`, full row). The workspace-settings row is currently read only as a bool by
`MarketplaceMode::activeFor()`; MV3 adds an O(1) accessor returning the settings row (with
`commission_*`) so the workspace level isn't a duplicate query. **Persistence:** both
`OrderRepository::orderLineRow()` and `SellerOrderRepository::insertForOrder()` are **explicit column
whitelists** that silently drop unknown keys — the six order-line commission columns and the
seller-order `commission_amount` must be added to those two whitelists or the snapshot will not persist.

### 2.5 The ledger (`commerce_marketplace_ledger`, append-only)

The single financial source of truth. An **account** has a canonical non-null `account_key`:
`seller:{seller_uuid}` or the literal `marketplace`; its identity is
`(tenant_uuid, account_key, currency)`. `account_kind ∈ {seller, marketplace}` remains an explicit
queryable column; `seller_uuid` is required for `seller` accounts and NULL for the `marketplace`
account. Service validation always enforces that bidirectional relationship, with PostgreSQL/SQLite
CHECKs as defense in depth. Entry `amount` is a **signed** bigint in minor units.
Closed `entry_type` vocabulary and their sign convention:

| entry_type | sign | posted by |
|---|---|---|
| `sale_credit` | + | payment (per seller) |
| `commission_debit` | − | payment (per seller) |
| `refund_debit` | − | refund completion (per seller) |
| `commission_reversal` | + | refund completion (per seller) |
| `adjustment` | ± (signed) | operator |
| `reserve_hold` | − | (vocabulary only, MV5) |
| `reserve_release` | + | (vocabulary only, MV5) |
| `payout_debit` | − | manual payout |
| `payout_reversal` | + | (MV4) |

**Append-only:** rows are never updated or deleted; a correction is a compensating entry.
**Deterministic idempotency identity** (not random): payment `{order_uuid}:{seller_uuid}:{entry_type}`,
refund `{refund_uuid}:{account_key}:{entry_type}` (marketplace-funded uses the marketplace account
key), payout `{payout_uuid}:{seller_uuid}:{entry_type}`. A duplicate `(tenant_uuid, idempotency_key)`
insert triggers a **verify**: the existing row must match the expected `amount`, `currency`, and
account exactly, plus the immutable semantic identity (`entry_type`, seller and source UUID fields,
reason, and actor where supplied) — a mismatch is an **integrity failure** (never silently ignored,
never a new row).

### 2.6 Account-posting lock (balance safety)

Because balances are derived (§2.9), a payout's available-balance check can otherwise race a
concurrent posting. A `(tenant_uuid, account_key, currency)` **account lock** is claimed
by **every balance-affecting posting** (sale_credit/commission_debit, refund_debit/commission_reversal,
adjustment, payout_debit — and the marketplace account for funded remainders). Realized as a minimal
`commerce_ledger_account_locks` anchor table (identity + `revision` only, **no balance** — balances
stay derived); the claim is an affected-row-checked `revision` bump, with savepoint-guarded lazy
first-row creation (the MV1 `MarketplaceWorkspaceLock` idiom). Multi-account transactions (a
multi-seller payment or refund) claim account locks in **sorted account order** (by `account_key`,
then `currency`) to avoid deadlock. The non-null key is mandatory: an ordinary unique containing a
nullable `seller_uuid` would permit multiple marketplace lock rows on the supported databases.

> This anchor table is the pinned lock mechanism. It stores **no balance** and is purely a
> per-`(account_key,currency)` serialization row; balances remain a `SUM` over the ledger. The
> canonical non-null key gives marketplace and seller accounts identical uniqueness semantics on
> every supported database without broad cross-currency seller locks or process-scoped advisory locks.

### 2.7 Payment posting (atomic, inside `markPaid()`)

For a **`marketplace_partitioned`** order (its own immutable snapshot flag, never current
`activeFor`), inside `markPaid()`'s existing transaction — after `SellerOrderPaymentConfirmation::confirm()`
and before the `OrderPaid` afterCommit — `LedgerPostingService::postSale()`: for each participating
seller (sorted), claim the seller account lock, then post `sale_credit = +attributed_total` and
`commission_debit = −commission_amount`. Any ledger failure rolls back the paid CAS + confirmation +
postings as one COMMIT (no crash window; no external I/O). Non-partitioned orders post nothing and
execute zero ledger/lock queries.

### 2.8 Refund posting + validation tightening (atomic, inside `applyCompletion()`)

**Validation tightening (marketplace-aware):** on a `marketplace_partitioned` order, a **partial**
refund **requires** line attribution (a line-less or under-attributed partial refund is rejected
`422`); a **full-remaining** refund auto-expands across the refundable seller lines. (Single-store /
non-partitioned refunds keep today's permissive behavior.)

**Posting** (after the `refunded_total` CAS, inside `applyCompletion()`'s transaction; claim the
affected seller account locks + the marketplace account lock in sorted order):

- `refund_debit` per seller is the sum of the merchandise-capped `delta_R` amounts defined below,
  grouped through `refund_lines → order_line.seller_uuid`; it never uses the raw refund-line amount.
- `commission_reversal` per seller, computed **per order line** from that line's immutable commission
  snapshot, then summed. For line `L`, let `B` be `commission_basis`, `C` be the original
  `commission_amount`, and `R` be cumulative completed refunded merchandise attributed to that line,
  capped at `B`. Its cumulative target is `0` when `B=0`; otherwise
  `min(C, intdiv(C × R + intdiv(B, 2), B))`. The current refund posts the sum of each affected line's
  `target_after − target_before`, grouped by seller. A fully refunded line therefore reverses exactly
  `C`, while different product-level rates under the same seller never contaminate one another.
  The current refund's merchandise contribution is **not** the entire remaining basis and is not the
  raw caller value. Let `R_before` be the cumulative completed refunded merchandise already attributed
  to the line. `LedgerPostingService::postRefund()` computes this at **completion time**, after the
  order financial claim, by reading completed refund history for the line while excluding the current
  refund UUID:
  `delta_R = min(refund_line.amount, max(0, B − R_before))`, then
  `R_after = R_before + delta_R`. Thus a 1-unit partial refund can reverse only the commission
  attributable to 1 unit of basis, while an over-large/tax-inclusive caller amount cannot push `R`
  beyond `B`. Any `refund_line.amount − delta_R` is outside the commission basis and follows the
  marketplace-funded shipping/tax/remainder rule below.
- Any refunded minor unit **not attributable to a seller line** (shipping/tax/remainder) posts to the
  **marketplace account** as an explicit funded entry, so
  `Σ abs(seller refund_debit) + abs(marketplace-funded refund_debit) = refund amount` exactly
  (invariant 13).

Invariant 9 holds by construction: the postings share `applyCompletion()`'s commit.

### 2.9 Balances (derived) — exact sign formulas

`SellerBalanceService::balance(account, currency)` computes over the ledger (signed `amount`),
currency-separated. With `S(type) = SUM(amount WHERE entry_type = type)` for the account:

- `available   = SUM(amount over ALL entries)`  — what a payout may draw against
- `reserved    = −( S(reserve_hold) + S(reserve_release) )`  — net currently held (0 in MV3)
- `paid_out    = −( S(payout_debit) + S(payout_reversal) )`  — net paid out
- lifetime flows (informational): `gross_sales = S(sale_credit)`, `commission = −S(commission_debit)`,
  `refunds = −S(refund_debit)`, `commission_reversed = S(commission_reversal)`,
  `adjustments = S(adjustment)`

`reserve_hold` being negative means `available` already excludes `reserved`. No **`pending`** component
in MV3 (no genuine pending lifecycle; MV4 introduces it with provider payout execution). Never a
stored mutable balance; a materialized cache, if ever added, must be disposable and rebuildable from
the ledger.

### 2.10 Manual payouts (atomic) + operator adjustments

**Payout** (`PayoutService::record`) requires `amount > 0`, a non-empty external reference, and a
non-null operator actor, and executes one transaction: claim the
seller account lock → **recheck**
available balance under the lock → **refuse if `amount > available`** → insert the `commerce_payouts`
row → insert the `payout_debit` ledger entry. `note` is optional. Recorded **after** the operator
confirms funds moved externally (Commerce-local; no provider). **Duplicate idempotency** verifies
**both** the existing payout row and its ledger entry match every immutable request field
(`seller`/`amount`/`currency`/`external_ref`/`note`/`created_by`) — mismatch is an integrity failure.

**Adjustments** (`AdjustmentService`): a **non-zero** signed `adjustment` entry with mandatory reason,
non-null actor, and request idempotency key; may drive a balance **negative** (the ledger reflects
financial truth); never edits/deletes an entry — only compensating entries. A matching replay verifies
the existing ledger row under `adjustment:{account_key}:{request_key}`; any payload mismatch under the
same key is an integrity failure.
`reserve_hold`/`reserve_release` are vocabulary only (no automatic engine).

### 2.11 Reconciliation (read-only, scans directly)

A console command reports **missing / duplicate / mismatched** posting sets by scanning **directly**
(never keyed off order status, since a partial refund leaves the order `paid`):

- every `commerce_seller_orders` partition with `confirmed_at IS NOT NULL` ⇒ expect its
  `sale_credit` + `commission_debit` (this is the durable payment-confirmation fact; parent status is
  deliberately irrelevant);
- every **completed refund on a partitioned order** ⇒ expect `refund_debit` + `commission_reversal`
  (+ marketplace-funded remainder) per its attribution;
- every **payout** ⇒ expect its matching `payout_debit`.

It **never posts or invents** entries — defense-in-depth behind the §2.7/§2.8 atomic guarantee.

## 3. Schema (exact)

Integer minor units throughout. New tables/columns written only for partitioned orders / marketplace
accounts; non-partitioned installs never touch them.

### 3.1 Folded commission columns (fold convention — no external installs)
- `commerce_products` (into `001`): `commission_kind` varchar(16) nullable, `commission_bps` int
  nullable, `commission_fixed` bigInt nullable.
- `commerce_sellers` (into `010`): same three nullable columns.
- `commerce_marketplace_settings` (into `010`): same three nullable columns.
- `commerce_order_lines` (into `004`): `commission_source` varchar(16) nullable, `commission_kind`
  varchar(16) nullable, `commission_bps` int nullable, `commission_fixed` bigInt nullable,
  `commission_basis` bigInt default 0, `commission_amount` bigInt default 0.
- `commerce_seller_orders` (into `011`): `commission_amount` bigInt default 0.

### 3.2 `commerce_marketplace_ledger` — new (`012`)
- `id` bigInt PK autoincrement; `uuid` varchar(12); `tenant_uuid` varchar(12) default `''`
- `account_key` varchar(32) non-null (`seller:{uuid}` | `marketplace`)
- `account_kind` varchar(12) (`seller|marketplace`); `seller_uuid` varchar(12) nullable
- `currency` varchar(3)
- `entry_type` varchar(24) (closed vocab §2.5); `amount` bigInt (signed)
- `order_uuid` varchar(12) nullable; `seller_order_uuid` varchar(12) nullable;
  `refund_uuid` varchar(12) nullable; `payout_uuid` varchar(12) nullable
- `idempotency_key` varchar(191); `reason` varchar(255) nullable; `created_by` varchar(12) nullable;
  `created_at` timestamp default CURRENT_TIMESTAMP
- unique `(tenant_uuid, idempotency_key)`; indexes `(tenant_uuid, account_key, currency)`,
  `(tenant_uuid, account_kind, seller_uuid, currency)`,
  `(order_uuid)`, `(refund_uuid)`, `(payout_uuid)`
- PostgreSQL/SQLite CHECKs: `account_kind='seller'` iff `seller_uuid` IS NOT NULL, and `account_key`
  matches the canonical value; service validation is authoritative on MySQL

### 3.3 `commerce_ledger_account_locks` — new (`012`)
- `id` bigInt PK autoincrement; `tenant_uuid` varchar(12) default `''`; `account_key` varchar(32) non-null;
  `currency` varchar(3); `revision` bigInt default 0;
  `created_at`/`updated_at`
- unique `(tenant_uuid, account_key, currency)` — the non-null lock identity. **No balance columns.**

### 3.4 `commerce_commission_policy_events` — new (`012`)
- `id` bigInt PK autoincrement; `uuid` varchar(12); `tenant_uuid` varchar(12) default `''`
- `subject_kind` varchar(16) (`product|seller|workspace`); `subject_uuid` varchar(12)
- `actor_uuid` varchar(12); `before_policy` JSON; `after_policy` JSON
- `created_at` timestamp default CURRENT_TIMESTAMP
- unique `(tenant_uuid, uuid)`; index `(tenant_uuid, subject_kind, subject_uuid, created_at)`
- append-only repository surface: insert/list only; no update/delete methods

### 3.5 `commerce_payouts` — new (`013`)
- `id` bigInt PK autoincrement; `uuid` varchar(12); `tenant_uuid` varchar(12) default `''`; `seller_uuid` varchar(12)
- `currency` varchar(3); `amount` bigInt; `external_ref` varchar(191); `note` varchar(255) nullable
- `created_by` varchar(12); `idempotency_key` varchar(191); `created_at`
- unique `(tenant_uuid, idempotency_key)`; unique `(tenant_uuid, uuid)`; index `(tenant_uuid, seller_uuid, currency)`
- service validation requires `amount > 0`; PostgreSQL/SQLite add a positive-amount CHECK

### 3.6 Config
`config/commerce.php` `marketplace.commission`: concrete `{ kind: string, bps: ?int, fixed: ?int }`
(same validated shape; env-backed), defaulting to `{percentage, 0, null}`.

### 3.7 Diagnostics / adopt
`commerce_marketplace_ledger`, `commerce_ledger_account_locks`,
`commerce_commission_policy_events`, and `commerce_payouts` added to
`DiagnosticsReport::commerceTables()` (marketplace-aware regardless of switch) and `tenantTables()`
(they carry `tenant_uuid`) — swept by tenant-adopt. **All four declare `tenant_uuid` as
`string(12)->default('')`** — `TenantAdopter` rekeys the sentinel `WHERE tenant_uuid = ''`, so without
the empty-string default the adopt sweep would silently match nothing (the convention every existing
tenant-scoped commerce table follows).

## 4. Global claim / lock order (extends MV1/MV2)

```
MV1  workspace-settings → sorted sellers → sorted products      (transfer, activation, attribution)
MV2  sorted sellers → sorted products                           (checkout)
MV2  order.fulfillment_revision → sorted seller_orders          (fulfillment)
     order.refund_revision                                      (refunds, download mints)
MV3  order paid CAS → sorted ledger accounts                    (payment posting)
MV3  order.refund_revision → sorted ledger accounts             (refund posting)
MV3  ledger account                                             (payout, adjustment)
```

Account locks live in `commerce_ledger_account_locks` — disjoint from the `commerce_sellers`/
`commerce_products` rows MV1/MV2 claim, so no new cross-operation cycle. Within a transaction, ledger
accounts are always claimed by canonical `account_key` in sorted order, after any order-level claim.

## 5. Services & seams

- **`CommissionPolicyResolver`** — resolve product→seller→workspace-settings→config; validation of the
  typed shape.
- **`CommissionCalculator`** (pure) — per-line + per-seller commission from resolved policy + line
  facts, hard-reconciled.
- **`CheckoutService`** — snapshot commission per line + seller-order sum inside the existing partition
  write (alongside `SellerAllocationCalculator`).
- **`LedgerAccountLock`** — `claim(c, tenant, accountKey, currency)` (savepoint-guarded lazy create +
  revision bump); `accountKey` is always non-null and canonical.
- **`LedgerRepository`** — append-only insert with deterministic idempotency + duplicate-verify;
  balance/component SUM queries; reconciliation scan queries.
- **`LedgerPostingService`** — `postSale` (markPaid txn), `postRefund` (applyCompletion txn), each
  claiming account locks in sorted order.
- **`SellerBalanceService`** — derived balance + components (§2.9).
- **`PayoutService`** — atomic record (§2.10); **`AdjustmentService`** — signed adjustment (§2.10).
- **`MarketplaceRefundGuard`** — marketplace-aware validation tightening (§2.8). `RefundService` is
  `final` with `private` `validate()`/`validateLines()`, so the guard is **not** a subclass/wrapper;
  it is an **appended optional nullable collaborator** on `RefundService::__construct` (the house idiom
  already used by `CheckoutService`'s `?MarketplaceMode`/`?SellerRepository` and
  `OrderPaymentService`'s `?SellerOrderPaymentConfirmation`), invoked internally at the `validate()`
  attribution point to validate/normalize persisted lines for partitioned orders. It never computes
  completion-time `delta_R`. The separately injected `LedgerPostingService` owns that calculation at
  the `applyCompletion()` posting seam.
- **`ReconciliationService`** + console command (`commerce:marketplace:reconcile`).
- **`SellerFinancialReportRepository`** + report controllers; **`CommissionPolicyService`** (operator
  mutation + transactional `CommissionPolicyEventRepository` append) + seller read-only inspection.
- **`CommissionPolicyEventRepository`** — append/list only; writes the durable §2.3 record in the same
  transaction as each effective policy change.
- **Events** (optional, afterCommit): `LedgerEntriesPosted`, `PayoutRecorded`,
  `CommissionPolicyChanged` — soft-dispatch idiom; none is a correctness/audit authority.

## 6. APIs, capabilities & authority

### 6.1 Operator (admin, config-gated)
- Set commission policy on product / seller / workspace-settings (audited).
- `POST` record manual payout; `POST` post adjustment.
- `GET` marketplace financial summary + per-seller balances; seller-order/order breakdown gains
  commission + net.
- `commerce:marketplace:reconcile` console command.

### 6.2 Seller (`/commerce/seller/{sellerUuid}/...`, `commerce_seller` middleware)
- `GET` own financial report (gross/commission/net/refunds/reversals/balance over a window).
- `GET` own balance + components; `GET` own payouts.
- `GET` effective + snapshotted commission policy (read-only). **No** commission mutation, **no**
  payout recording.
- New capabilities in `FixedSellerRoleAuthority`: `commerce.seller.reports.read` and
  `commerce.seller.payouts.read` (owner/admin/analyst). Commission-policy read folds into
  `reports.read`.

### 6.3 Error semantics
| Condition | Result |
|---|---|
| Seller attempts to set commission through a permitted seller write route | 422 field rejected |
| Caller lacks the seller write capability | 403 |
| Partial refund on a partitioned order without line attribution | 422 |
| Payout amount > available balance | 422 (refused) |
| Duplicate payout idempotency, matching row+entry | idempotent replay (existing payout returned) |
| Duplicate payout/ledger idempotency, mismatched | integrity failure (500, never a new row) |
| Cross-seller balance/report/payout read | 404 non-revealing |

## 7. Marketplace-off & non-partitioned invariance

- Non-partitioned orders (and switch-off): `markPaid`/`applyCompletion` post nothing and execute
  **zero** ledger/lock/payout queries; checkout writes no commission snapshot beyond the (0-default)
  columns; route manifest unchanged when off.
- Historical behavior follows each order's own `marketplace_partitioned` snapshot, never current
  `activeFor`.

## 8. Test plan

- **Commission resolution & snapshot:** product→seller→workspace→config precedence; validation
  (all-null inherit, percentage/fixed shape, mixed rejected); per-line basis/rounding (percentage
  half-up, fixed capped); PostgreSQL/SQLite CHECK shape plus MySQL service-validation parity;
  seller-order sum exact; snapshot immutable across later policy edits.
- **Payment posting:** per-seller `sale_credit`+`commission_debit`; Σ reconciles; deterministic
  idempotency + duplicate-verify (matching replays, mismatched integrity-fails); non-partitioned zero
  ledger queries; account-lock claimed in sorted canonical-key order (multi-seller); two concurrent
  first postings to the marketplace account produce exactly one lock row.
- **Refund posting:** **per-line cumulative** commission reversal across differing product policies
  (multiple partials sum to each line's exact snapshot; full line refund reverses exactly); seller
  debit and reversal grouping only after line targets are computed; a 1-unit partial refund advances
  basis and seller debit by at most 1, while an over-large line amount is capped at remaining basis;
  only the excess posts to the marketplace account, with seller debits + funded remainder equaling
  the refund exactly; validation tightening (partial requires lines → 422; full-remaining auto-expand).
- **Balances:** exact sign formulas across all entry types; currency separation; negative balance via
  adjustment.
- **Payout:** reject zero/negative amounts and missing actor/external reference; atomic
  row+recheck+debit in one txn;
  refuse-over-available; **serialization** so a
  concurrent refund_debit can't let a payout overdraw (live-pgsql lane: payout-vs-refund on one
  account); idempotent duplicate verifies both row and entry.
- **Adjustments:** non-zero signed amount, mandatory reason + actor + idempotency; matching replay is
  a no-op after verification, mismatched replay integrity-fails; may go negative; compensating-only.
- **Reconciliation:** detects a deliberately missing / duplicated / mismatched posting across all three
  scan sources (paid orders, completed refunds, payouts); never posts.
- **Authority:** operator-only commission mutation; seller commission-write rejected with `422` on
  both create and raw-body update plus a service backstop; seller read-only inspection; capability ×
  role matrix for reports/payouts; policy write + append-only audit row commit/roll back together;
  failed/unbound after-commit event does not affect audit durability.
- **Live pgsql races:** concurrent `markPaid` postings; double-refund-completion idempotency; payout
  vs refund on one account (lock serializes); concurrent adjustments.
- **Off-invariance:** route manifest, zero-query, projection byte-identical.

## 9. File map

- **Migrations:** fold commission columns into `001`/`004`/`010`/`011`; new
  `012_CreateMarketplaceLedgerTables` (ledger + account-locks + commission-policy events),
  `013_CreatePayoutTable`; append to `CommerceTestCase::MIGRATIONS`.
- **Marketplace/settlement:** `src/Marketplace/CommissionPolicyResolver.php`,
  `CommissionCalculator.php`, `CommissionPolicyService.php`, `CommissionPolicyEventRepository.php`,
  `LedgerAccountLock.php`,
  `LedgerRepository.php`, `LedgerPostingService.php`, `SellerBalanceService.php`, `PayoutService.php`,
  `AdjustmentService.php`, `ReconciliationService.php`, `MarketplaceRefundGuard.php`.
- **Orders/Checkout:** `src/Cart/CartService.php` (widen `pricedLines()` projection with the 3 product
  `commission_*`), `src/Marketplace/MarketplaceMode.php` (settings-row accessor), `src/Orders/CheckoutService.php`
  (commission snapshot), `src/Orders/OrderRepository.php` + `src/Marketplace/SellerOrderRepository.php`
  (extend the insert column whitelists with the commission columns), `src/Orders/OrderPaymentService.php`
  (postSale collaborator), `src/Orders/Refunds/RefundService.php` (postRefund + guard collaborators),
  `src/Catalog/CatalogService.php` (operator `updateProduct` commission; seller `updateSellerProduct`
  rejection), `src/Marketplace/SellerService.php` (operator seller commission), `src/Support/DiagnosticsReport.php`,
  `config/commerce.php`.
- **HTTP:** operator commission-policy / payout / adjustment / financial controllers;
  `src/Http/Seller/SellerFinancialController.php`; `src/Marketplace/FixedSellerRoleAuthority.php`
  (+ capabilities); `routes.php`; `src/CommerceServiceProvider.php` registrations.
- **Console:** `src/Console/MarketplaceReconcileCommand.php`.
- **Reports:** `src/Reports/SellerFinancialReportRepository.php`.
- **Events:** `src/Events/{LedgerEntriesPosted,PayoutRecorded,CommissionPolicyChanged}.php`.
- **Tests:** `tests/Integration/Marketplace/{CommissionSnapshotTest, PaymentPostingTest,
  RefundPostingTest, BalanceTest, PayoutTest, AdjustmentTest, ReconciliationTest,
  CommissionAuthorityTest, SettlementPgsqlTest}.php`, `tests/Unit/Marketplace/CommissionCalculatorTest.php`,
  seller-report + off-regression extensions.

## 10. Verify-at-implementation seams

- **`OrderPaymentService::markPaid` — RESOLVED** (`src/Orders/OrderPaymentService.php:63-83`): postSale
  hook slots after `confirmation->confirm()` (:73-75) and before the `OrderPaid` afterCommit (:79-81);
  two callers route through it (provider handler `:63`, admin `AdminOrderController:159`).
- **`RefundService` — RESOLVED**: `final` class, `private` `validate()`(:401-428)/`validateLines()`(:430-507);
  the guard injects at the `validateLines()` call site (:425, where `$order`/`$amount` are in scope) and
  posts after the `refunded_total` CAS (:583); `applyCompletion()` receives `$lines` with `order_line_uuid`.
  Composed as an appended optional collaborator (§5), NOT a subclass. Per-line cumulative reversal is
  derivable from immutable data — `commerce_refund_lines`(refund_uuid, order_line_uuid, quantity, amount,
  `006:46-57`) join `commerce_refunds WHERE status='completed'` — no per-line ledger tracking needed.
  Because gateway refunds may remain pending after validation, the guard only normalizes persisted
  lines; `postRefund()` derives `R_before` during completion under the order claim and excludes the
  current refund UUID from that completed-history query.
- **`CheckoutService` commission inputs — RESOLVED** (see §2.4): product `commission_*` ride the
  already-loaded `pricedLines` row once its projection is widened; seller `commission_*` ride the
  claim-protocol seller re-read; the settings row needs a new `MarketplaceMode` accessor (currently
  `activeFor()` returns only a bool); both insert whitelists must be extended.
- **Commission mutation + audit — RESOLVED** (§2.3): product via `CatalogService::updateProduct`, seller
  via `SellerService::update` (add commission handling), workspace-settings has **no** existing update
  surface (`MarketplaceActivationService` only activate/deactivate) so `CommissionPolicyService` adds one;
  no audit-log table exists to reuse, so `CommissionPolicyEventRepository` appends a focused durable
  event row in the mutation transaction; `CommissionPolicyChanged` is optional after-commit signaling.
- **Tenant-adopt sentinel — RESOLVED** (§3.7): the four new tables need `tenant_uuid` `default('')` for
  the `WHERE tenant_uuid = ''` adopt sweep.
- **Seller catalog authority — RESOLVED:** `SellerCreateProductData` contains no commission fields and
  the hydrator silently ignores unknown keys (`src/Http/DTOs/SellerCreateProductData.php:18-22`), while
  `SellerCatalogController::update()` reads the raw body
  (`src/Http/Seller/SellerCatalogController.php:98-107`). MV3 must inspect raw input and return
  field-specific `422` on both seller create/update; `CatalogService::updateSellerProduct()` repeats
  the update guard before delegating.
- **DB CHECK support — RESOLVED:** `ColumnBuilder::check()` exists, and the PostgreSQL/SQLite generators
  emit it; `MySQLSqlGenerator::buildColumnDefinition()` omits it. Service validation is therefore the
  cross-driver authority, with DB checks tested as PostgreSQL/SQLite defense in depth only.
- **Account-lock savepoint — RESOLVED:** `MarketplaceWorkspaceLock` (savepoint-guarded lazy-first-row
  insert + affected-row-checked `revision` bump) is directly reusable for `LedgerAccountLock`; the only
  change is the compound WHERE/insert `(tenant_uuid, account_key, currency)` vs MV1's single `tenant_uuid`.
  Framework `TransactionManager::begin()` emits a real `SAVEPOINT` at nested level ≥1 (confirmed).
- **Confirmed (recon):** `DiagnosticsReport::commerceTables()` append flows into `tenantTables()` by
  omission from the exclusion list, swept by `TenantAdopter` (see §3.7 sentinel note);
  `FixedSellerRoleAuthority::CAPABILITY_MATRIX` additions are purely additive (the class docblock already
  anticipates reports/payouts capabilities) and `SellerMemberMiddleware` reads the capability generically;
  next migration number is `012`; fold targets `001`(products)/`004`(order_lines)/`010`(sellers+settings)/
  `011`(seller_orders) all create those tables; the pgsql race-lane fixture-child harness
  (`fixtures/*_race_child.php`, env-gated) is reusable for the payout-vs-refund and double-refund lanes.
