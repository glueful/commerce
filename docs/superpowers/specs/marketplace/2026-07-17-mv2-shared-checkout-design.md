# Commerce Marketplace MV2 — Shared Checkout & Seller Orders

> **Status:** held / uncommitted. Design authority for the MV2 implementation plan.
> **Predecessors:** MV1 (`2026-07-17-mv1-seller-foundation-design.md`) shipped seller identity,
> membership, catalog ownership, activation, and seller-scoped catalog/inventory surfaces.
> Overview: `2026-07-16-multi-vendor-overview-design.md` (§5.2, §6.3, §6.5, §8, §10).

## 1. Scope & Non-Goals

**In scope.** In a marketplace-active workspace, one shared checkout produces:

- one customer order with **unchanged payment** (one parent-order collection, one grand total);
- immutable per-seller **order-line attribution** (seller, allocated discount, per-line tax);
- one immutable **`commerce_seller_orders`** partition per `(order, seller)` carrying exactly-
  reconciled money facts (subtotal, allocated discount, allocated shipping-discount, allocated
  shipping, allocated tax, `attributed_total`) and independent fulfillment (status, carrier,
  tracking);
- **parent-derived fulfillment** — the parent order's fulfillment rolls up from its seller orders;
- seller-scoped order APIs, operator order breakdown + fan-out, and a customer projection that
  adds seller-group attribution.

**Explicitly deferred to MV3+ (do NOT build seams for these now).** Commission policy/precedence,
platform fees, seller **net/payable**, the settlement ledger, seller balances, payouts, provider
integration, seller refund debits / commission reversal, partial (single-seller) cancellation
after payment, seller-origin (independent per-seller) shipping, and merchant-of-record tax
liability. MV2 persists the *checkout facts* MV3 will consume; it computes no seller earnings.

**Naming guard.** The MV2 seller-order money result is `attributed_total` (a.k.a. seller order
total). The identifiers `seller_net` / `seller_payable` are **reserved for MV3** (post-commission,
post-fee). Nothing at the MV2 layer may imply a final payout.

## 2. Pinned Decisions (authoritative contract)

### 2.1 Money attribution model — full non-commission

MV2 establishes the immutable, exactly reconcilable commercial facts of a checkout and nothing
downstream of them. For each seller partition of an order:

- `subtotal` — sum of that seller's lines' `line_total` (`unit_price * quantity`, merchandise gross)
- `allocated_discount` — the seller's share of an order-level **value** discount
- `allocated_shipping_discount` — the seller's share of an order-level **free-shipping** waiver
- `allocated_shipping` — the seller's share of the effective order shipping
- `allocated_tax` — the seller's attributed tax
- `attributed_total = subtotal − allocated_discount + allocated_shipping + allocated_tax`

`allocated_shipping_discount` is **not** subtracted in `attributed_total`: when a free-shipping
discount applies, effective shipping is already zero, so `allocated_shipping` is zero for every
seller and the waiver is recorded only for display/audit. MV3 consumes these facts to compute
commission, fees, seller payable, and ledger entries; it never rewrites them.

### 2.2 Discount & free-shipping allocation

Today `PricingEngine` records a **free-shipping** discount as `discount_total` while setting the
effective `shipping_total` to zero — so `discount_total` does not always mean "merchandise
discount". MV2 therefore splits by discount kind (a cart carries at most one discount code):

- **Value discount** (`percentage` | `fixed`): allocate `discount_total` across **order lines** via
  `DiscountAllocation::allocate` (integer largest-remainder, stable **line-UUID** tie-break),
  persist each line's `discount_amount`, and set each seller's `allocated_discount` = sum of its
  lines' `discount_amount`. `allocated_shipping_discount` = 0.
- **Free-shipping discount**: `allocated_discount` = 0 and every line's `discount_amount` = 0;
  allocate `discount_total` across **sellers** into `allocated_shipping_discount` on the seller's
  merchandise-subtotal basis (largest-remainder, seller-UUID tie-break; zero-basis ⇒ even
  deterministic split). `allocated_shipping` = 0 for all.
- **No discount**: both are 0.

Refunds consume the persisted per-line/per-seller allocations; they never recompute discount.

> **Confirmed against source** (`PricingEngine::price()`, `src/Pricing/PricingEngine.php:13-56`):
> `free_shipping` captures `discount_total = shippingTotal` *before* zeroing shipping, sets
> `shippingTotal = 0`, leaves `discountedSubtotal = subtotal` (not reduced), so
> `grand_total = subtotal + 0 + tax` — the waiver nets out via `shipping_total = 0` and is **not**
> double-subtracted. Invariant 5 (§2.5) therefore holds. Note for the calculator: the `Totals` VO
> (`src/Pricing/Totals.php`) uses **camelCase** (`discountTotal`, `shippingTotal`, `grandTotal`);
> the persisted order row uses snake_case. The calculator consumes the persisted order totals; do
> not read `$totals->discount_total`.

### 2.3 Shipping allocation

Central quote, allocated (seller-origin shipping is deferred). The shipping **method** stays an
order-level snapshot (`commerce_orders.shipping_method`) and is **not** duplicated as a seller-owned
method. Allocate the **effective** order `shipping_total` across sellers:

- basis: each seller's **post-discount merchandise** subtotal (`subtotal − allocated_discount`)
- integer largest-remainder with **seller-UUID** tie-break; allocations sum exactly to `shipping_total`
- zero-basis fallback ⇒ even split by largest-remainder + seller-UUID order
- free-shipping ⇒ `shipping_total` is 0, so every seller receives 0 (see 2.2)

`commerce_seller_orders` stores only `allocated_shipping`. Refund attribution uses the persisted
allocation, never a recomputation.

### 2.4 Tax attribution — per line when available, else allocate

The tax engine already computes tax per line by each line's `tax_class` + address before summing
to one `tax_total`; MV2 keeps that precision when present.

**Tax seam.** Extend `TaxQuote` additively; its constructor becomes
`TaxQuote(int $amount, string $label = 'Tax', ?TaxBreakdown $breakdown = null)`. Existing callers
remain source-compatible, and aggregate calculators continue returning a quote with a null
breakdown. `DbTaxCalculator` supplies a structured `TaxBreakdown` on its detailed path:

- `taxByLine: array<string line_uuid, int>` — per-line merchandise tax
- `shippingTaxTotal: int`
- **invariant** (constructor-enforced against the quote amount):
  `sum(taxByLine) + shippingTaxTotal === TaxQuote::amount`

The exact contract is
`TaxBreakdown::__construct(array $taxByLine, int $shippingTaxTotal, array $knownLineUuids)`.
Duplicate values in the **known-line UUID list** are rejected before map construction (duplicate
keys cannot be detected after PHP has formed an associative array); unknown `taxByLine` keys are
rejected and omitted known lines are canonicalized to zero. `TaxBreakdown::total()` returns
`sum(taxByLine) + shippingTaxTotal`, and `TaxQuote` rejects a non-null breakdown unless
`$breakdown->total() === $amount`. Checkout never treats a sparse or foreign-keyed breakdown as
authoritative.

`DiscountAllocation::taxableLines()` currently strips `line_uuid`; it must **preserve** `line_uuid`
in the detailed input so `taxByLine` is keyable. A calculator returns a breakdown **only** when it
genuinely computed per line.

**Attribution method — detected by breakdown presence, not `instanceof`.** `DelegatingTaxCalculator`
implements the line interface even when it falls back to aggregate flat-rate; therefore checkout
selects the path by whether a `TaxBreakdown` was actually produced:

- **`line_detailed`** (breakdown present): each line's merchandise tax persisted to
  `commerce_order_lines.tax_amount`; seller `allocated_tax` = sum of its lines' `tax_amount` **plus**
  the seller's share of `shippingTaxTotal` allocated by `allocated_shipping` (largest-remainder,
  seller-UUID tie-break; zero-basis ⇒ post-discount merchandise basis; still zero ⇒ deterministic
  split).
- **`aggregate_allocated`** (no breakdown): `tax_amount` = 0 on every line; seller `allocated_tax`
  = the seller's share of `tax_total` allocated by (post-discount merchandise + `allocated_shipping`)
  basis, largest-remainder + seller-UUID tie-break.

Every path reconciles exactly to `orders.tax_total`. The chosen method is snapshotted per seller
order as `tax_attribution_method` (`line_detailed` | `aggregate_allocated`) for auditability.
Refunds consume the persisted line/seller tax allocations and never recalculate current rates.

### 2.5 Exact reconciliation invariants (hard asserts at checkout)

For an order with sellers `S`, integer minor units throughout:

1. `Σ_s subtotal_s = orders.subtotal`
2. value discount ⇒ `Σ_s allocated_discount_s = orders.discount_total` and
   `Σ_lines discount_amount = orders.discount_total`; free-shipping ⇒
   `Σ_s allocated_shipping_discount_s = orders.discount_total`; else both 0
3. `Σ_s allocated_shipping_s = orders.shipping_total`
4. `Σ_s allocated_tax_s = orders.tax_total`; `line_detailed` ⇒ `Σ_lines tax_amount = Σ_s (line tax)`
5. `Σ_s attributed_total_s = orders.grand_total`

A mismatch on any invariant is an integrity failure that aborts the checkout transaction (it must
be impossible by construction — the assert is a guardrail, not control flow).

### 2.6 Immutable partition marker

Add `commerce_orders.marketplace_partitioned` (boolean, default false), set **once** at placement to
`MarketplaceMode::activeFor(tenant)` and never mutated. Historical behavior follows the **order
snapshot**, not the workspace's current activation:

- Partitioned orders (`true`) always use child-aware fulfill/cancel and expose `seller_groups`,
  even after the workspace later deactivates — historical tracking and per-seller state are never
  hidden, and parent-only fulfillment is never permitted.
- Non-partitioned orders (`false`) retain byte-identical pre-MV2 behavior and perform **zero**
  seller-table reads on every path (checkout, fulfill, cancel, projection).

### 2.7 Checkout transfer-safe claim protocol

Checkout does **not** claim the workspace-wide lock (that would serialize every marketplace
checkout on one row). "Snapshot only" is insufficient — a concurrent transfer/suspend/close could
tear the mapping. In partitioned checkout, **inside `CheckoutService::placeOrder`'s existing
transaction**:

1. Snapshot all product→seller mappings for the cart's products in **one** query.
2. Claim the participating **sellers** (distinct `seller_uuid`s) in UUID order (`SellerRepository`
   revision claim).
3. Claim the participating **products** in UUID order (product revision claim).
4. Re-read products and sellers.
5. On ownership drift (a product's seller changed), roll back the **entire** checkout transaction
   (including the cart revision claim and every provisional order write) and automatically retry
   the complete placement flow **exactly once** from a fresh snapshot. A second drift returns HTTP
   `409` with code `checkout_conflict`. A participating seller that is not `active` returns the same
   controlled conflict immediately; retrying cannot make that state valid. Otherwise proceed with
   the re-read mapping.

This serializes checkout against transfer, suspension, closure, and deletion. The claim order
(sellers → products, UUID-ordered) is a strict subsequence of MV1's global lock order
(workspace → sellers → products); since checkout never claims the workspace row and never claims a
product before its seller, no cross-operation deadlock is introduced.

> MV1 guarantees every product in an active workspace has a non-null seller (activation adoption
> gate + active-mode create) and blocks closing a seller that owns live products. A null-seller
> line in partitioned checkout is thus a guarded integrity error, not a normal case.

### 2.8 Fulfillment rollup + parent claim

Each `commerce_seller_orders` owns `fulfillment_status` (`unfulfilled` | `fulfilled`), `fulfilled_at`,
`carrier`, `tracking_number`, `tracking_url`, and a `revision`. Claiming only the child would let two
concurrent child fulfillments each compute `partial`. Every fulfillment mutation therefore:

1. Claims the parent `commerce_orders.fulfillment_revision` (affected-row-checked bump) **first**.
2. Claims the affected seller order(s) `revision` in UUID order.
3. Applies the child transition(s) (set fulfilled + carrier/tracking).
4. Re-reads **all** children of the parent and rolls up: the parent `fulfillment_status` becomes
   `fulfilled` iff every non-`canceled` child is fulfilled, `partial` if some are, else `unfulfilled`;
   when it becomes `fulfilled` the parent order `status` transitions `paid → fulfilled` (guarded via
   the existing state-CAS).
5. Commits, then dispatches events **after commit**: `SellerOrderFulfilled` per transitioned child
   and `OrderFulfilled` once, only if the parent reached `fulfilled`.

Because concurrent child fulfillments serialize on the parent `fulfillment_revision` claim, each
rollup is computed on committed state and retries cannot duplicate fulfillment events.

`partial` is a **fulfillment** value, never an order-lifecycle status. A dedicated
`FulfillmentStatus` vocabulary validates parent values (`unfulfilled` | `partial` | `fulfilled`) and
child values (`unfulfilled` | `fulfilled`). `OrderStateMachine` remains unchanged.

Authority: a seller may mutate only its own seller order; an operator may mutate any. Actor and
capability are checked before the claim.

### 2.9 Parent fulfillment endpoint & cancellation

- **Parent fulfill in partitioned mode is an operator fan-out**: it claims the parent, marks every
  non-`canceled` child fulfilled, and rolls up. Independent parent-only fulfillment writes are
  rejected for partitioned orders. Non-partitioned orders keep today's direct parent fulfill.
- **Whole-order cancellation is preserved** from **either** currently supported source state
  (`pending_payment → canceled` and `paid → canceled`) and **fans out** to set every child
  `status = canceled`. Only **partial** (single-seller) cancellation is deferred to MV3.
- Spelling is `canceled` (single `l`), matching the existing state machine.

### 2.10 Seller-order operational status

`commerce_seller_orders.status` ∈ {`open`, `canceled`}. Created `open`; set `canceled` only by a
whole-order cancel fan-out (2.9). Fulfillment lives in the separate `fulfillment_status` field.

### 2.11 Order-line UUID preservation

The priced line **already carries a stable `line_uuid`** — `CartService::pricedLines()`
(`src/Cart/CartService.php:359`) stamps `'line_uuid' => (string) $line['uuid']` before any
discount/tax calculation, and it threads unmodified through `CheckoutService::placeOrder()` into
`OrderRepository::insert()`. The only defect is that `OrderRepository::orderLineRow()`
(`src/Orders/OrderRepository.php:281`) **discards** it and mints a fresh `Utils::generateNanoID()`.
MV2 therefore does not add a preassignment step: it makes `orderLineRow()` persist the incoming
`line_uuid` (`(string) ($line['line_uuid'] ?? Utils::generateNanoID())`), so `taxByLine` and the
discount allocation map — both keyed on that same `line_uuid` — address the real immutable order
lines.

### 2.12 Payment confirmation & seller PII gate

Seller partitions are created during checkout while the parent is still `pending_payment`, but a
seller must not learn the customer's ship-to address until payment is confirmed. Each seller order
therefore starts with `confirmed_at = null`. The successful `pending_payment → paid` path stamps
`confirmed_at` on every child using DB time **in the same transaction** as the parent status CAS.
`OrderPaid` dispatch is registered through `db($context)->afterCommit(...)`, so it runs only after the
successful **outermost** commit even when `markPaid()` participates in a caller-owned transaction.
Admin mark-paid and provider callback paths both pass through this one `OrderPaymentService`
operation.

`OrderPaymentService` checks the parent snapshot before touching seller rows. A non-partitioned paid
transition executes zero seller-table queries, preserving the marketplace-off path.

Seller list/detail queries return only rows with `confirmed_at IS NOT NULL`; seller fulfillment also
requires confirmation. A pending-payment or pre-payment-canceled partition is non-revealing `404` on
seller surfaces. `confirmed_at` is immutable: a paid order that is later canceled or refunded remains
visible as paid history, subject to its operational status. Operator and customer-own-order surfaces
retain their existing visibility rules.

The seller detail exposes a normalized **shipping-only** allowlist:
`{name, company, line1, line2, city, region, postcode, country, phone}`. `name` may be assembled from
the stored recipient fields, and `region`/`postcode` normalize the supported state/postal aliases.
The implementation must never spread the raw address JSON. Billing address, email, user UUID,
guest/download/payment tokens, order metadata, and every non-allowlisted customer field are excluded.

## 3. Schema (exact)

Integer minor units throughout (`bigInteger`). New tables/columns are written **only** for
partitioned orders; non-partitioned installs never touch them.

### 3.1 `commerce_order_lines` — added columns
- `seller_uuid` varchar(12) **nullable** — immutable seller snapshot (null for non-partitioned)
- `discount_amount` bigInteger default 0 — allocated value-discount share (0 for free-shipping/none)
- `tax_amount` bigInteger default 0 — per-line merchandise tax (`line_detailed` only; else 0)
- index `(order_uuid, seller_uuid)`

### 3.2 `commerce_orders` — added columns
- `marketplace_partitioned` boolean default false — immutable partition marker (2.6)
- `fulfillment_revision` bigInteger default 0 — parent fulfillment claim (2.8)
- `fulfillment_status` vocabulary extends with `partial` (only ever set for partitioned orders)

### 3.3 `commerce_seller_orders` — new table
- `id` bigInteger PK autoincrement
- `uuid` varchar(12), `tenant_uuid` varchar(12)
- `order_uuid` varchar(12), `seller_uuid` varchar(12)
- `seller_name_snapshot` varchar(160) — immutable display name at checkout
- `partition_number` int — 1-based, deterministic by sorted `seller_uuid` within the order
- `seller_reference` varchar(96) — deterministic composite `{order_number}-{partition_number}`
- `currency` varchar(3)
- `subtotal` bigInteger, `allocated_discount` bigInteger default 0,
  `allocated_shipping_discount` bigInteger default 0, `allocated_shipping` bigInteger default 0,
  `allocated_tax` bigInteger default 0, `attributed_total` bigInteger
- `tax_attribution_method` varchar(20) — `line_detailed` | `aggregate_allocated`
- `confirmed_at` timestamp nullable — immutable successful-payment visibility gate (2.12)
- `fulfillment_status` varchar(16) default `unfulfilled` — `unfulfilled` | `fulfilled`
- `fulfilled_at` timestamp nullable, `carrier` varchar(96) nullable,
  `tracking_number` varchar(191) nullable, `tracking_url` varchar(512) nullable
- `status` varchar(16) default `open` — `open` | `canceled`
- `revision` bigInteger default 0
- `created_at` timestamp default CURRENT_TIMESTAMP, `updated_at` timestamp nullable

**Backstops (DB constraints):**
- unique `(order_uuid, seller_uuid)`
- unique `(order_uuid, partition_number)`
- unique `(tenant_uuid, seller_reference)`
- unique `(tenant_uuid, uuid)`
- index `(tenant_uuid, seller_uuid, confirmed_at, fulfillment_status)` — confirmed seller listing
- index `(order_uuid)` — parent rollup

### 3.4 Migration placement

The marketplace schema is unreleased with no external installs, so MV2 follows the house fold
convention: **column additions to existing tables fold into their original create migrations**, and
only the **new table** gets its own migration.

1. **Fold into `004_CreateCommerceOrderTables`** — this migration creates BOTH `commerce_orders` and
   `commerce_order_lines`. Inside the `commerce_order_lines` `createTable` block add `seller_uuid`
   varchar(12) nullable, `discount_amount` bigInt default 0, `tax_amount` bigInt default 0, and the
   index `(order_uuid, seller_uuid)`; inside the `commerce_orders` `createTable` block add
   `marketplace_partitioned` bool default false and `fulfillment_revision` bigInt default 0.
   (`commerce_orders.fulfillment_status` already exists here — do not re-add it.) `001`
   (`commerce_products`/`commerce_variants`) is untouched, keeping its already-folded
   `commerce_products.seller_uuid`.
2. `011_CreateSellerOrderTables` (**new**) — create `commerce_seller_orders` exactly as §3.3, guarded
   by `hasTable`.

Folded columns ride their table's `createTable` (no separate guard); already-migrated dev/test
databases are **synced manually** (the suite rebuilds fresh SQLite per run, so tests are unaffected;
persistent Postgres lanes migrate a fresh schema). Migration/shape tests assert the folded columns +
the new table's columns/uniques/indexes/defaults by exact name. Because the schema builder exposes
`hasTable`/`hasColumn` but **no `hasIndex`**, every index assertion (the folded `commerce_products`
`(tenant_uuid, seller_uuid)` and `commerce_order_lines` `(order_uuid, seller_uuid)` indexes, and the
`commerce_seller_orders` indexes) is made **explicitly** via driver index introspection (SQLite
`PRAGMA index_list`/`index_info`; PostgreSQL `pg_indexes`). The CHANGELOG records the schema additions
and the manual-sync note for existing databases.

## 4. Global claim/lock order (extends MV1)

```
workspace-settings  →  sellers (UUID order)  →  products (UUID order)     [MV1: transfer, activation, attribution]
                       sellers (UUID order)  →  products (UUID order)     [MV2: checkout — no workspace claim]
order.fulfillment_revision  →  seller_orders (UUID order)                 [MV2: fulfillment]
order.refund_revision                                                     [MV1/existing: refunds, download mints]
```

`fulfillment_revision` and `refund_revision` are **independent** parent claims for disjoint concerns.
Checkout's `(sellers → products)` is a strict subsequence of the MV1 chain; fulfillment's parent-then-
children order is self-contained. No path acquires a product before its seller, or a child before its
parent.

## 5. Services & seams

- **`LargestRemainder::distribute(array<string,int> $weights, int $total): array<string,int>`**
  (new generic helper) — the integer largest-remainder algorithm (floor each share, distribute the
  leftover units by descending remainder with **ascending key** tie-break) extracted from
  `DiscountAllocation::allocate()` (`src/Tax/DiscountAllocation.php:89-116`). `allocate()` cannot be
  reused directly for seller buckets: it hardwires line-shaped `product_scope` eligibility, a
  `unit_price*quantity` weight, and discount-type gating. Both `DiscountAllocation::allocate()` and
  `SellerAllocationCalculator` call this helper; keys are line UUIDs in the former and seller UUIDs
  in the latter (so the tie-break is stable and deterministic in both).
- **`SellerAllocationCalculator`** (pure, deterministic) — input: priced lines (each with stable
  `line_uuid`, `line_total`, `seller_uuid`, per-line `discount_amount`, per-line `tax_amount` when
  `line_detailed`), the order totals, the discount kind, and the optional `TaxBreakdown`; output:
  per-seller `{subtotal, allocated_discount, allocated_shipping_discount, allocated_shipping,
  allocated_tax, attributed_total, tax_attribution_method}` with the §2.5 asserts. Uses
  `LargestRemainder::distribute` for shipping, shipping-discount, and aggregate-tax allocation
  across seller buckets.
- **`TaxQuote` + `TaxBreakdown`** (§2.4) — additive nullable-breakdown constructor contract;
  `DbTaxCalculator` populates it, `DelegatingTaxCalculator` leaves it null on flat fallback;
  `DiscountAllocation::taxableLines()` preserves `line_uuid` (only its named-key consumers read the
  rows, so the extra key is safe — a doc-only array-shape update on `LineTaxCalculator` /
  `DbTaxCalculator` / `DelegatingTaxCalculator` docblocks).
- **`CheckoutService`** — partitioned branch (gated on `activeFor`), the §2.7 claim protocol, calling
  the calculator and writing line attribution + seller orders inside the existing transaction; sets
  `marketplace_partitioned`. Non-partitioned path unchanged, zero seller-table reads.
- **`OrderRepository`** — persist preassigned line UUIDs; `claimFulfillmentMutation` (parent
  `fulfillment_revision` CAS); seller-order insert; children read + rollup helpers.
- **`SellerOrderRepository`** — seller-order reads/writes, revision claim, per-seller and per-order
  queries.
- **`SellerOrderFulfillmentService`** — the §2.8 claim/rollup/dispatch flow (seller + operator).
- **`SellerOrderService`** — seller-facing read (list/detail scoped to one seller).
- **`SellerOrderPaymentConfirmation`** — stamps every partition `confirmed_at` in the same database
  transaction as `OrderPaymentService`'s successful parent paid transition; no listener fallback.
- **`FixedSellerRoleAuthority`** — add `commerce.seller.orders.read` (all four roles) and
  `commerce.seller.orders.fulfill` (owner/admin/staff; **not** analyst).
- **Events** — new `SellerOrderFulfilled`; existing `OrderFulfilled` fires only on parent-fulfilled.
- **`DiagnosticsReport`** + tenant-adopt — `commerce_seller_orders` is marketplace-aware regardless
  of the switch (rekeyed on adopt, listed in diagnostics).

## 6. APIs & projections + error semantics

### 6.1 Seller (`/commerce/seller/{sellerUuid}/orders`, behind `commerce_seller` middleware)
- `GET …/orders` — paginated list of this seller's **payment-confirmed** seller orders (filter by
  `fulfillment_status`); capability `commerce.seller.orders.read`.
- `GET …/orders/{sellerOrderUuid}` — detail: **only** this seller's lines, its allocated totals, its
  fulfillment, and the §2.12 normalized customer **ship-to allowlist** (required to fulfill).
  Capability `…orders.read`. An unconfirmed partition is non-revealing `404`.
- `POST …/orders/{sellerOrderUuid}/fulfill` — body `{carrier, tracking_number, tracking_url}`;
  capability `commerce.seller.orders.fulfill`; unconfirmed partitions cannot be fulfilled.

### 6.2 Operator (`/commerce/admin/orders`)
- `GET …/{orderUuid}` — detail gains the seller-order breakdown when `marketplace_partitioned`.
- `POST …/{orderUuid}/seller-orders/{sellerOrderUuid}/fulfill` — operator fulfills any seller order.
- `POST …/{orderUuid}/fulfill` — partitioned ⇒ operator fan-out (2.9); non-partitioned ⇒ unchanged.
- `POST …/{orderUuid}/cancel` — whole-order cancel; partitioned ⇒ fan-out to `canceled` children.

### 6.3 Customer (`/commerce/orders/{number}`)
Consolidated order unchanged (one grand total, flat `lines`). When `marketplace_partitioned`, add a
strictly **allowlisted** `seller_groups` array — per group: `seller_reference`, seller display name,
its line subset, `allocated_subtotal`, `allocated_discount`, `allocated_shipping_discount`,
`allocated_shipping`, `allocated_tax`, `attributed_total`, and fulfillment (`fulfillment_status`,
`carrier`, `tracking_number`, `tracking_url`). **Excluded:** `seller_uuid`, `revision`, internal
`status`, `tenant_uuid`, `tax_attribution_method`, and every MV3 settlement field. Poison-string
projection tests per the house pattern.

### 6.4 Error semantics
| Condition | Result |
|---|---|
| Seller A requests seller B's order (cross-seller) | 404 non-revealing |
| Seller order of a non-partitioned/absent order | 404 |
| Seller reads/fulfills a partition before payment confirmation | 404 non-revealing |
| Fulfill a `canceled` seller order | 409 |
| Fulfill an already-`fulfilled` seller order | 409 (invalid transition) |
| Independent parent-only fulfill on a partitioned order | 409/422 (use fan-out) |
| First checkout ownership drift | whole transaction rolls back; one automatic retry |
| Second ownership drift / participating seller not active | 409 `checkout_conflict` |
| Value discount on ordinary create carrying `seller_uuid` etc. | unchanged MV1 DTO rejection |

## 7. Marketplace-off & partition invariance

- Partition decided **once** at placement (`marketplace_partitioned = activeFor(tenant)`); thereafter
  the order's own flag governs — deactivation never changes historical orders.
- Non-partitioned orders: no seller orders, null line attribution, no `seller_groups`, direct parent
  fulfill, **zero** seller-table queries on checkout/payment/fulfill/cancel/projection.
- Regression pins (MV1 pattern): route manifest identical with `installEnabled` false; a
  non-partitioned order's checkout/fulfill/cancel/projection execute zero seller-table queries
  (query-count instrumentation); non-partitioned projection byte-identical.

## 8. Test plan

- **Allocation reconciliation** (property + example): value discount, free-shipping, no discount;
  `line_detailed` and `aggregate_allocated`; single- and multi-seller; every §2.5 invariant asserted
  to the minor unit; zero-basis fallbacks (shipping, shipping-tax) deterministic.
- **Free-shipping**: `allocated_shipping_discount` sums to `discount_total`, `allocated_shipping` = 0,
  `attributed_total` correct, `discount_amount` = 0 on all lines.
- **Tax**: `DbTaxCalculator` breakdown drives `line_detailed`; `FlatRate`/`DelegatingTaxCalculator`
  fallback detected as `aggregate_allocated` **by breakdown absence** (not `instanceof`);
  additive `TaxQuote` constructor remains compatible; unknown breakdown line UUID rejected, omitted
  known UUID canonicalized to zero, quote reconciliation enforced; `line_uuid` preserved through
  `taxableLines()`.
- **Line UUID preassignment**: `taxByLine` and discount map keys equal the persisted order-line UUIDs.
- **Checkout claims**: first deterministic ownership drift rolls back the cart claim and every order
  write then retries the whole transaction once; second drift returns `409 checkout_conflict`;
  ordered seller/product claims;
  pgsql lanes — checkout-vs-transfer (no torn mapping; snapshots the committed seller or conflicts),
  checkout-vs-suspend, checkout-vs-close.
- **Fulfillment rollup**: `unfulfilled → partial → fulfilled`; parent `fulfillment_revision` serializes
  concurrent child fulfillments (pgsql lane: two children fulfill concurrently ⇒ one rollup, one
  `OrderFulfilled`); checked-claim retry idempotence (no duplicate events).
- **Fan-out**: operator parent fulfill fans out; whole-order cancel fans out from `pending_payment`
  **and** `paid`.
- **Payment/PII gate**: checkout-created pending partitions are absent from seller list/detail and
  cannot be fulfilled; parent paid CAS + all child `confirmed_at` stamps commit or roll back together;
  a paid-then-canceled order remains seller-visible; the `OrderPaid` event observes committed stamps.
- **Migrations**: a fresh install produces the folded `commerce_order_lines`/`commerce_orders`
  columns and the new `commerce_seller_orders` table with the expected columns, uniques, and
  defaults; every index (`commerce_products` `(tenant_uuid, seller_uuid)`, `commerce_order_lines`
  `(order_uuid, seller_uuid)`, and the `commerce_seller_orders` indexes) asserted **explicitly** via
  driver index introspection (SQLite `PRAGMA index_list`/`index_info`; pgsql `pg_indexes`); the new
  `011` is re-run safe (`hasTable` guard).
- **Isolation & allowlists**: cross-seller 404; normalized shipping-only seller detail allowlist with
  poison strings proving billing/email/user/token/metadata exclusion;
  customer `seller_groups` allowlist (poison strings) — no settlement/internal leak.
- **Partition invariance**: deactivate after a partitioned order ⇒ still child-aware + `seller_groups`;
  non-partitioned order byte-identical; zero seller-table queries on non-partitioned paths.
- **Off regression**: route manifest, zero-query, projection byte-identical (MV1 harness).

## 9. File map

- **Migrations**: fold `commerce_order_lines` columns + index AND `commerce_orders` columns into
  `004` (both tables live there); add `011_CreateSellerOrderTables`; append `011` to
  `CommerceTestCase::MIGRATIONS`; shape tests with explicit index introspection.
- **Marketplace**: `src/Marketplace/SellerAllocationCalculator.php`,
  `src/Marketplace/SellerOrderRepository.php`, `src/Marketplace/SellerOrderService.php`,
  `src/Marketplace/SellerOrderFulfillmentService.php`,
  `src/Marketplace/SellerOrderPaymentConfirmation.php`, `FixedSellerRoleAuthority.php` (+ capabilities).
- **Tax/allocation**: `src/Pricing/TaxQuote.php` (additive optional breakdown),
  `src/Pricing/TaxBreakdown.php` (new VO), `src/Contracts/LineTaxCalculator.php`,
  `src/Tax/DbTaxCalculator.php` (per-line map + shipping scalar), `src/Tax/DelegatingTaxCalculator.php`,
  `src/Tax/DiscountAllocation.php` (preserve `line_uuid`; call the extracted helper),
  `src/Support/LargestRemainder.php` (new generic largest-remainder helper, §5).
- **Orders/Checkout**: `src/Orders/CheckoutService.php`, `src/Orders/OrderRepository.php`,
  `src/Orders/OrderPaymentService.php`, `src/Orders/FulfillmentStatus.php` (new vocabulary).
- **HTTP**: `src/Http/Seller/SellerOrderController.php` (new),
  `src/Http/Admin/AdminOrderController.php` (breakdown, fan-out, seller-order fulfill),
  `src/Http/Storefront/OrderController.php` (`seller_groups`), `routes.php`.
- **Events**: `src/Events/SellerOrderFulfilled.php` (new).
- **Support**: `src/Support/DiagnosticsReport.php`, tenant-adopt command (rekey `commerce_seller_orders`).
- **Docs**: `CHANGELOG.md` `[Unreleased]`.
- **Tests**: `tests/Integration/Marketplace/{SellerAllocationTest, SellerOrderLifecycleTest,
  CheckoutPartitionTest, FulfillmentRollupTest, SellerOrderSurfaceTest, CheckoutClaimPgsqlTest,
  FulfillmentPgsqlTest}.php`, `tests/Integration/Http/StorefrontSellerGroupsProjectionTest.php`,
  `tests/Integration/Marketplace/MarketplaceRegressionTest.php` (extend for MV2), `tests/Support/*`.

## 10. Verify-at-implementation seams

All seams below were verified against source during spec review (2026-07-17); the resolved facts are
recorded here so the plan encodes them directly.

- **`PricingEngine` free-shipping — RESOLVED** (`src/Pricing/PricingEngine.php:13-56`): waiver nets
  out via `shipping_total = 0`, `grand_total = subtotal + tax`, not double-subtracted; invariant 5
  holds (see §2.2). `Totals` VO is camelCase; the calculator reads the persisted snake_case order row.
- **`DiscountAllocation` — RESOLVED** (`src/Tax/DiscountAllocation.php`): `taxableLines()` strips
  `line_uuid` today; its only production caller is `CheckoutService::resolveTax()`
  (`src/Orders/CheckoutService.php:328`) and consumers read named keys only, so adding `line_uuid`
  is safe (doc-only shape updates). `allocate()` is line-shaped and **cannot** be reused for seller
  buckets — extract the largest-remainder core (`:89-116`) into `LargestRemainder::distribute` (§5).
- **Tax calculators — RESOLVED** (`src/Tax/`, `src/Contracts/LineTaxCalculator.php:27`):
  `quoteDetailed` returns `TaxQuote`; `TaxQuote` (`src/Pricing/TaxQuote.php:7-14`) takes `(int
  $amount, string $label = 'Tax')` and every call site passes ≤2 positional args, so the additive
  `?TaxBreakdown` 3rd param is safe. `DelegatingTaxCalculator` implements `LineTaxCalculator` but
  falls back to `flat->quote()` when `!hasRatesForCurrentTenant` (`:60-69`) — hence breakdown-presence
  detection, not `instanceof`. `DbTaxCalculator::quoteDetailed` (`:82-121`) currently folds per-line
  tax (`:94-108`) and shipping tax (`:110-116`) into one `$total`; MV2 changes it to accumulate a
  per-line map + a separate shipping scalar at exactly those points. `FlatRateTaxCalculator`
  (`:11`) does not implement `LineTaxCalculator`.
- **Order-line UUID — RESOLVED** (§2.11): the cart-line `line_uuid` already exists before pricing
  (`CartService::pricedLines()` `src/Cart/CartService.php:359`); the only change is to persist it in
  `OrderRepository::orderLineRow()` (`src/Orders/OrderRepository.php:281`) instead of minting fresh.
- **`OrderPaymentService::markPaid` — RESOLVED** (`src/Orders/OrderPaymentService.php:18-25`): no
  transaction exists yet; the single chokepoint has exactly two callers — the provider callback
  (`src/Payments/OrderPaymentConfirmationHandler.php:62-65`) and admin
  (`src/Http/Admin/AdminOrderController.php:113-122`, itself not transaction-wrapped). MV2 introduces
  the `db()->transaction()` boundary coupling the paid CAS to child `confirmed_at` stamps, with
  `OrderPaid` registered through `db($context)->afterCommit(...)` inside that transaction so nested
  callers cannot dispatch it before the outermost commit.
- **Controllers/routes — RESOLVED**: admin `cancel()`/`fulfill()` are transaction-wrapped
  (`AdminOrderController.php:93-108, 127-148`); customer route is `/commerce/orders/{number}`
  (`routes.php:101`, `OrderController::show()` → `findByNumber`), matching §6.3; storefront
  `linesProjection()` (`OrderController.php:310-323`) excludes line `uuid` — `seller_groups` is a new
  top-level array, not a change to that whitelist.
- **`commerce_seller` middleware — RESOLVED**: `SellerMemberMiddleware` reads the capability
  generically as `$params[0]` and checks `roles->allows(role, capability)` with no hardcoded list, so
  the two new capabilities are pure additions to `FixedSellerRoleAuthority::CAPABILITY_MATRIX` + two
  route registrations.
- **Migration introspection — RESOLVED** (§3.4): no `hasIndex` on `SchemaBuilderInterface`, so all
  index assertions use driver introspection; folded columns ride their `createTable` block, the new
  `011` table is `hasTable`-guarded.
