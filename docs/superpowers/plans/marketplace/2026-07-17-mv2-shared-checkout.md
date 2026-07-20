# Commerce Marketplace MV2 — Shared Checkout & Seller Orders — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to
> implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In a marketplace-active workspace, one shared checkout produces one customer order
(unchanged payment) plus immutable per-seller order partitions carrying exactly-reconciled
attribution facts and independent, parent-rolling-up fulfillment — inert when marketplace is off.

**Architecture:** A pure `SellerAllocationCalculator` (fed by a generic `LargestRemainder` helper and
an extended tax breakdown) computes per-seller money facts inside `CheckoutService`'s existing
transaction, gated by an immutable `commerce_orders.marketplace_partitioned` marker; seller orders
persist to a new `commerce_seller_orders` table; payment confirmation stamps a PII gate; fulfillment
claims the parent then children and rolls the parent status up.

**Tech Stack:** PHP 8.3, Glueful framework, PHPUnit 10, SQLite (suite) + PostgreSQL (race lanes).

**Spec:** `docs/superpowers/specs/marketplace/2026-07-17-mv2-shared-checkout-design.md` (authoritative;
every §-reference below points into it).

## Global Constraints

*(Every task's requirements implicitly include this section. Exact values copied from the spec.)*

- **MARKETPLACE-OFF / NON-PARTITIONED = BYTE-IDENTICAL.** When `MarketplaceMode::installEnabled` is
  false **or** an order's `marketplace_partitioned` is false, checkout/payment/fulfill/cancel/
  projection behave exactly as pre-MV2 and execute **zero** seller-table queries.
- **IMMUTABLE PARTITION MARKER.** `commerce_orders.marketplace_partitioned` is set **once** at
  placement to `MarketplaceMode::activeFor(tenant)` and never mutated. ALL historical behavior
  branches on the order's own flag, **not** current `activeFor` (§2.6).
- **INTEGER MINOR UNITS** throughout. No float/decimal money math.
- **NAMING GUARD.** The MV2 money result is `attributed_total`. `seller_net` / `seller_payable` are
  reserved for MV3 — never used at the MV2 layer (§1).
- **ATTRIBUTED TOTAL.** `attributed_total = subtotal − allocated_discount + allocated_shipping +
  allocated_tax`. `allocated_shipping_discount` is **never** subtracted (§2.1).
- **DISCOUNT KIND SPLIT (§2.2).** Value discount (`percentage`|`fixed`) ⇒ per-line `discount_amount`,
  seller `allocated_discount` = sum of its lines, `allocated_shipping_discount` = 0. Free-shipping ⇒
  per-line `discount_amount` = 0, `allocated_discount` = 0, `allocated_shipping_discount` allocated
  across sellers, `allocated_shipping` = 0 for all. No discount ⇒ both 0.
- **EXACT RECONCILIATION (§2.5), hard-asserted at checkout, abort transaction on mismatch:**
  Σ subtotal = orders.subtotal; value ⇒ Σ allocated_discount = discount_total AND Σ line
  discount_amount = discount_total; free-shipping ⇒ Σ allocated_shipping_discount = discount_total;
  Σ allocated_shipping = shipping_total; Σ allocated_tax = tax_total; Σ attributed_total = grand_total.
- **TAX (§2.4).** `line_detailed` when a `TaxBreakdown` is **produced**, else `aggregate_allocated` —
  detected by **breakdown presence, never `instanceof`**. `tax_attribution_method` snapshotted per
  seller order. `TaxBreakdown` invariant: `sum(taxByLine) + shippingTaxTotal === TaxQuote::amount`;
  unknown line-UUID keys rejected, omitted known lines canonicalized to 0, duplicate values in the
  known-line UUID list rejected (PHP map keys cannot retain duplicates), and `TaxQuote` validates
  `TaxBreakdown::total()` against its amount.
- **LARGEST-REMAINDER TIE-BREAK.** Distribute leftover units by **descending remainder, ascending
  key** (line UUID or seller UUID). Deterministic in both uses.
- **CLAIM ORDERS.** Checkout: sellers (UUID order) → products (UUID order), **no workspace lock**
  (§2.7). Fulfillment: parent `fulfillment_revision` → seller orders (UUID order) (§2.8). Both are
  subsequences of MV1's workspace → sellers → products; never a product before its seller, never a
  child before its parent (§4).
- **CHECKOUT DRIFT (§2.7).** First ownership drift ⇒ roll back the **whole** transaction (cart claim
  + all order writes) and retry the complete flow **exactly once** from a fresh snapshot; second
  drift ⇒ HTTP `409` code `checkout_conflict`. A participating seller not `active` ⇒ the same `409`
  immediately (retry cannot fix it).
- **PAYMENT PII GATE (§2.12).** `confirmed_at` stamped on every child in the **same transaction** as
  the parent `pending_payment → paid` CAS (both `OrderPaymentService` callers route through it);
  `OrderPaid` registered via `db($context)->afterCommit(...)` so it waits for the **outermost** commit.
  Seller list/detail/fulfill require `confirmed_at IS NOT NULL`; an unconfirmed/pre-payment-canceled
  partition is non-revealing `404`. `confirmed_at` is immutable.
- **SELLER SHIPPING ALLOWLIST (§2.12).** Seller detail exposes only
  `{name, company, line1, line2, city, region, postcode, country, phone}`; never spreads raw address
  JSON; excludes billing, email, user UUID, guest/download/payment tokens, and order metadata.
- **FULFILLMENT (§2.8).** Child status ∈ {`unfulfilled`,`fulfilled`}; parent ∈
  {`unfulfilled`,`partial`,`fulfilled`} via a new `FulfillmentStatus` vocabulary; `OrderStateMachine`
  **unchanged**. Parent order `status` → `fulfilled` only when every non-`canceled` child is
  fulfilled. Events after commit: `SellerOrderFulfilled` per transitioned child, `OrderFulfilled`
  once on parent-fulfilled. `partial` is never an order-lifecycle status.
- **CANCELLATION (§2.9).** Whole-order cancel from `pending_payment` **or** `paid` fans out to child
  `status = canceled`. Partial single-seller cancellation is deferred to MV3. Spelling `canceled`.
- **MIGRATIONS (§3.4).** House fold convention (schema unreleased, no external installs). `004`
  creates BOTH `commerce_orders` and `commerce_order_lines`, so **both folds go into `004`**: add
  `commerce_order_lines` columns (`seller_uuid`, `discount_amount`, `tax_amount`) + index
  `(order_uuid, seller_uuid)` to its `commerce_order_lines` `createTable`, and `commerce_orders`
  columns (`marketplace_partitioned`, `fulfillment_revision`) to its `commerce_orders` `createTable`
  (`fulfillment_status` already exists there — do not re-add). `001` is untouched. Add **new**
  `011_CreateSellerOrderTables` (`hasTable`-guarded) for `commerce_seller_orders`; append `011` to
  `CommerceTestCase::MIGRATIONS`. Folded columns ride their `createTable` (no separate guard);
  already-migrated dev/test DBs synced manually. There is **no `hasIndex`** on the schema builder, so
  every index assertion uses driver introspection (SQLite `PRAGMA index_list`/`index_info`; pgsql
  `pg_indexes`).
- **HOUSE STYLE.** `use` imports (no inline FQCNs); `UtcNowSql` for SQL timestamps; `Response`
  envelopes and DTO idiom matching existing controllers; phpcs + PHPStan (level per repo config)
  clean. No AI/Anthropic attribution in commits. Commit only the commerce repo; leave
  `docs/superpowers/**` and `.superpowers/**` unstaged.

---

## GROUP A — Schema, allocation core, tax breakdown, seller allocation

### Task 1: Migration folds + new seller-orders table + shape tests
**Files:** modify `migrations/004_CreateCommerceOrderTables.php` (this migration creates BOTH
`commerce_orders` and `commerce_order_lines` — fold the three `commerce_order_lines` columns +
`(order_uuid, seller_uuid)` index into its `commerce_order_lines` `createTable`, and
`marketplace_partitioned` + `fulfillment_revision` into its `commerce_orders` `createTable`); create
`migrations/011_CreateSellerOrderTables.php`; modify `tests/Support/CommerceTestCase.php`
(`MIGRATIONS` list → append `CreateSellerOrderTables`); create
`tests/Integration/Migrations/MarketplaceOrderShapeTest.php`. `001` is untouched. **NO commit until Task 4.**

**Interfaces (later tasks consume EXACTLY):**
- `commerce_order_lines` + `seller_uuid` varchar(12) nullable, `discount_amount` bigInt default 0,
  `tax_amount` bigInt default 0, index `(order_uuid, seller_uuid)` (§3.1) — folded into `004`.
- `commerce_orders` + `marketplace_partitioned` bool default false, `fulfillment_revision` bigInt
  default 0 (§3.2) — folded into `004`. `commerce_orders.fulfillment_status` already exists (`004`);
  do NOT re-add it — `partial` is a vocabulary concern (Task 7), not a schema change.
- `commerce_seller_orders` exactly per §3.3 (all columns, the four uniques, the two indexes incl.
  `(tenant_uuid, seller_uuid, confirmed_at, fulfillment_status)`) — new `011`.

- [ ] TDD: shape test asserts, after a fresh `001→011` install, (a) `commerce_order_lines` has
  `seller_uuid`/`discount_amount`/`tax_amount` with correct defaults and the `(order_uuid,
  seller_uuid)` index exists via driver introspection (`PRAGMA index_list('commerce_order_lines')`
  on SQLite); (b) `commerce_orders` has `marketplace_partitioned`/`fulfillment_revision` with correct
  defaults; (c) `commerce_seller_orders` columns/uniques/indexes/defaults by exact name (uniques via
  duplicate-insert attempts; indexes via `PRAGMA index_list`); (d) re-running `011` is a no-op
  (`hasTable` guard). → implement (`011` `hasTable`-guarded, `down()` drops the table; folded columns
  ride their `createTable`) → GREEN. Full suite green; NO commit.

### Task 2: `LargestRemainder` helper + `DiscountAllocation` refactor
**Files:** create `src/Support/LargestRemainder.php`; modify `src/Tax/DiscountAllocation.php`
(extract the largest-remainder core `:89-116` into the helper; call it from `allocate()`; make
`taxableLines()` **preserve** `line_uuid` in its returned rows); create
`tests/Unit/Support/LargestRemainderTest.php`; extend `tests/Unit/Tax/DiscountAllocationTest.php`.
**NO commit until Task 4.**

**Interfaces:**
- `LargestRemainder::distribute(array $weights, int $total): array` — `$weights` is
  `array<string key, int weight>`; returns `array<string key, int>` summing **exactly** to `$total`;
  leftover units distributed by descending remainder then **ascending key**; all-zero weights ⇒ even
  distribution by ascending key; `$total` may be 0 (⇒ all zero).
- `DiscountAllocation::allocate(...)` return shape unchanged (`array<line_uuid,int>`), now delegating
  to the helper.
- `DiscountAllocation::taxableLines(...)` rows now additionally carry `line_uuid` (string); existing
  named-key consumers unaffected; update the array-shape docblocks on `LineTaxCalculator`,
  `DbTaxCalculator`, `DelegatingTaxCalculator` to include `line_uuid:string`.

- [ ] TDD: helper unit matrix (exact-sum for non-trivial remainders; ascending-key tie-break;
  all-zero-weight even split; single bucket; total 0); `DiscountAllocation::allocate()` unchanged
  behavior (existing tests still green) now via the helper; `taxableLines()` includes `line_uuid` and
  the existing tax path is unaffected. → implement → GREEN. NO commit.

### Task 3: Tax breakdown seam
**Files:** create `src/Pricing/TaxBreakdown.php`; modify `src/Pricing/TaxQuote.php` (additive 3rd
param), `src/Contracts/LineTaxCalculator.php` (docblock), `src/Tax/DbTaxCalculator.php` (accumulate a
per-line map + a separate shipping-tax scalar and attach a `TaxBreakdown`), `src/Tax/
DelegatingTaxCalculator.php` (attach breakdown only on the DB path; leave null on flat fallback);
create `tests/Unit/Pricing/TaxBreakdownTest.php`; extend `tests/Integration/Tax/*` (or unit) for the
calculators. **NO commit until Task 4.**

**Interfaces:**
- `new TaxQuote(int $amount, string $label = 'Tax', ?TaxBreakdown $breakdown = null)`; read via
  `$quote->breakdown` (nullable). All existing call sites remain valid. When non-null, the constructor
  throws unless `$breakdown->total() === $amount`.
- `TaxBreakdown` (readonly VO): constructor
  `__construct(array $taxByLine, int $shippingTaxTotal, array $knownLineUuids)` — rejects duplicate
  values in `$knownLineUuids` before constructing the canonical map, throws on an unknown
  `$taxByLine` UUID key, and canonicalizes an omitted known line to 0. Accessors:
  `taxByLine(): array<string,int>`, `shippingTaxTotal(): int`, and `total(): int`. Duplicate
  associative-array keys are not a promised check because PHP overwrites them before construction.
- `DbTaxCalculator::quoteDetailed(...)` returns a `TaxQuote` **with** a `TaxBreakdown`;
  `DelegatingTaxCalculator::quoteDetailed(...)` returns one **without** a breakdown on flat fallback.

- [ ] TDD: `TaxBreakdown` normalization + rejection cases (unknown map key, duplicate known-line UUID,
  omitted-known→0); `TaxQuote` rejects a breakdown total that differs from its amount;
  `DbTaxCalculator::quoteDetailed` produces `taxByLine` keyed by the passed
  `line_uuid`s summing (with `shippingTaxTotal`) to `amount`; `DelegatingTaxCalculator` returns a
  null breakdown when `!hasRatesForCurrentTenant` (flat fallback) and a non-null one on the DB path;
  `TaxQuote` 2-arg construction still valid. → implement → GREEN. NO commit.

### Task 4: `SellerAllocationCalculator` — **GROUP A COMMIT**
**Files:** create `src/Marketplace/SellerAllocationCalculator.php`; create
`tests/Unit/Marketplace/SellerAllocationCalculatorTest.php`.

**Interfaces (consumed by Task 5):**
- Input DTO/array per line: `{line_uuid, seller_uuid, line_total, discount_amount, tax_amount}`
  (discount_amount/tax_amount already resolved by the caller for the value-discount / line_detailed
  cases); plus order totals `{subtotal, discount_total, shipping_total, tax_total, grand_total}`, the
  discount kind (`none`|`value`|`free_shipping`), and the optional `TaxBreakdown`.
- `SellerAllocationCalculator::allocate(array $lines, array $totals, string $discountKind,
  ?TaxBreakdown $breakdown): array` → per-seller
  `array<seller_uuid, {subtotal:int, allocated_discount:int, allocated_shipping_discount:int,
  allocated_shipping:int, allocated_tax:int, attributed_total:int, tax_attribution_method:string}>`.
  Pure (no DB/context). Throws an integrity exception if any §2.5 invariant fails.
- Allocation math per Global Constraints: shipping by post-discount merchandise basis; shipping
  discount (free-shipping) by merchandise basis; tax `line_detailed` = Σ line tax + shipping-tax by
  `allocated_shipping` (zero-basis ⇒ post-discount merch ⇒ deterministic), `aggregate_allocated` = by
  (post-discount merch + allocated_shipping); all via `LargestRemainder::distribute` with seller-UUID
  keys.

- [ ] TDD (property + example): every §2.5 invariant asserted to the minor unit across value /
  free-shipping / no-discount × `line_detailed` / `aggregate_allocated` × single- / multi-seller;
  zero-basis fallbacks deterministic; `tax_attribution_method` correct per branch; integrity
  exception fires on a deliberately inconsistent input. → implement → GREEN. Full suite + phpcs +
  analyze.
- [ ] **COMMIT (Group A):** `feat(marketplace): mv2 schema, allocation core, tax breakdown, and seller allocation`

---

## GROUP B — Partitioned checkout & payment confirmation

### Task 5: Partitioned checkout write path
**Files:** modify `src/Orders/OrderRepository.php` (persist incoming `line_uuid` in `orderLineRow()`;
seller-order insert helper; `claimFulfillmentMutation`; children read/rollup helpers — the last two
may land here or in Task 7, keep the insert here), `src/Orders/CheckoutService.php` (partition branch
gated on `activeFor`: the §2.7 claim protocol, call `SellerAllocationCalculator`, write per-line
attribution + `commerce_seller_orders`, set `marketplace_partitioned`, hard-assert §2.5); create
`src/Marketplace/SellerOrderRepository.php`; create `tests/Integration/Marketplace/
CheckoutPartitionTest.php`. **NO commit until Task 6.**

**Interfaces (consumed later):**
- `OrderRepository::orderLineRow()` persists `(string) ($line['line_uuid'] ?? Utils::generateNanoID())`.
- `SellerOrderRepository::insertForOrder(ApplicationContext $c, string $tenant, array $sellerOrderRows): void`
  — one INSERT per `(order,seller)` with all §3.3 columns; `partition_number` 1-based by sorted
  `seller_uuid`; `seller_reference = {order_number}-{partition_number}`; `confirmed_at` NULL at
  creation; `status='open'`; `fulfillment_status='unfulfilled'`.
- `SellerOrderRepository::forOrder(ApplicationContext $c, string $tenant, string $orderUuid): array`
  (all children of an order, for rollup and operator breakdown).
- Checkout claim protocol per §2.7 exactly (snapshot product→seller in one query; claim sellers then
  products in UUID order via MV1 `SellerRepository::claimRevision` and the product revision claim;
  re-read; drift ⇒ one full retry then `409 checkout_conflict`; inactive seller ⇒ `409`).
- Non-partitioned path unchanged; zero seller-table reads.

- [ ] TDD: partitioned multi-seller checkout writes correct line attribution + seller-order rows with
  every §2.5 invariant holding; `line_uuid` on the persisted order lines equals the cart-line UUIDs
  (so `taxByLine`/discount maps addressed real rows); `partition_number`/`seller_reference`
  deterministic; free-shipping and value-discount and no-discount cases; `aggregate_allocated`
  (FlatRate) vs `line_detailed` (Db); non-partitioned (inactive workspace) checkout writes no seller
  rows and reads zero seller tables (query-count); deterministic ownership-drift ⇒ one full retry
  then `409 checkout_conflict`; inactive participating seller ⇒ `409`. → implement → GREEN. NO commit.

### Task 6: Payment confirmation & PII gate — **GROUP B COMMIT**
**Files:** modify `src/Orders/OrderPaymentService.php` (wrap `markPaid` in a `db()->transaction()`:
parent `pending_payment → paid` CAS, then stamp `confirmed_at` on all children of a
`marketplace_partitioned` order with `UtcNowSql` in the same transaction; register `OrderPaid` via
`db($context)->afterCommit(...)` **inside** that transaction so nested callers wait for the outermost
commit; a non-partitioned paid transition performs zero seller-table queries); create
`src/Marketplace/SellerOrderPaymentConfirmation.php` (the stamping operation, called inside the
transaction — no listener fallback); create `tests/Integration/Marketplace/PaymentConfirmationTest.php`.

**Interfaces:**
- `SellerOrderPaymentConfirmation::confirm(ApplicationContext $c, string $tenant, string $orderUuid): void`
  — affected-row-safe UPDATE of every child `confirmed_at` where currently NULL, `UtcNowSql`; called
  ONLY inside `OrderPaymentService::markPaid`'s transaction, ONLY when `marketplace_partitioned`.
- `SellerOrderRepository` gains `confirmed`-scoped reads (used by Task 8): a listing/detail read that
  filters `confirmed_at IS NOT NULL`.

- [ ] TDD: partitioned paid transition stamps every child `confirmed_at` and commits atomically with
  the parent CAS (force a failure after the CAS ⇒ neither the paid status nor any stamp persists);
  an outer transaction wrapped around `markPaid()` proves `OrderPaid` does not dispatch at the inner
  savepoint release and observes committed stamps only after the outer commit; outer rollback emits
  nothing; non-partitioned paid transition executes zero seller-table queries (query-count); both
  callers (provider callback handler + admin markPaid) go through the one path; `confirmed_at`
  immutable on re-entry. → implement → GREEN. Full suite + phpcs + analyze.
- [ ] **COMMIT (Group B):** `feat(marketplace): partitioned checkout and payment confirmation`

---

## GROUP C — Fulfillment rollup & order surfaces

### Task 7: Fulfillment rollup + fan-out + cancellation
**Files:** create `src/Orders/FulfillmentStatus.php` (parent/child vocabularies + validation), create
`src/Marketplace/SellerOrderFulfillmentService.php`; modify `src/Orders/OrderRepository.php`
(`claimFulfillmentMutation` parent CAS if not already added in T5; rollup write helper),
`src/Http/Admin/AdminOrderController.php` (partitioned `cancel()` fan-out to child `canceled`); create
`src/Events/SellerOrderFulfilled.php`; create `tests/Integration/Marketplace/FulfillmentRollupTest.php`.
**NO commit until Task 9.**

**Interfaces (consumed by Tasks 8–9):**
- `FulfillmentStatus` — parent values `unfulfilled|partial|fulfilled`, child values
  `unfulfilled|fulfilled`; `assertParent(string)`, `assertChild(string)`; a `rollup(array
  $childStatuses): string` returning the parent value over non-`canceled` children. `OrderStateMachine`
  untouched.
- `SellerOrderFulfillmentService::fulfill(ApplicationContext $c, string $tenant, string $orderUuid,
  string $sellerOrderUuid, array $tracking, ?string $actorSellerUuid): array` — claim parent
  `fulfillment_revision` → claim the child revision (UUID order) → set child fulfilled +
  carrier/tracking → re-read all children → roll up parent `fulfillment_status` (+ parent `status`
  `paid → fulfilled` guarded CAS when all non-`canceled` fulfilled) → after commit dispatch
  `SellerOrderFulfilled` (+ `OrderFulfilled` once if parent fulfilled). `$actorSellerUuid` null ⇒
  operator (any child); non-null ⇒ must equal the child's seller (else the surface returns 404).
- `SellerOrderFulfillmentService::fanOutFulfill(ApplicationContext $c, string $tenant, string
  $orderUuid, array $tracking, string $actorContext): array` — operator fan-out: claim parent, mark
  every non-`canceled` child fulfilled, roll up (§2.9).
- Fulfilling a `canceled` or already-`fulfilled` child ⇒ `409`; unconfirmed child ⇒ non-revealing
  `404` (confirmed_at gate).

- [ ] TDD: `unfulfilled → partial → fulfilled` progression; single-seller order rolls straight to
  `fulfilled`; parent `status` flips `paid → fulfilled` only when all non-`canceled` children
  fulfilled; canceled child excluded from rollup; fan-out fulfills all children and rolls up;
  whole-order cancel fans out from `pending_payment` AND `paid`; fulfilling canceled/fulfilled ⇒ 409;
  unconfirmed ⇒ 404; events fire after commit (one `OrderFulfilled` on parent-fulfilled). → implement
  → GREEN. NO commit.

### Task 8: Seller-scoped order surfaces
**Files:** create `src/Marketplace/SellerOrderService.php` (seller-scoped read), create
`src/Http/Seller/SellerOrderController.php`; modify `src/Marketplace/FixedSellerRoleAuthority.php`
(add `commerce.seller.orders.read` to all four roles, `commerce.seller.orders.fulfill` to
owner/admin/staff), `routes.php` (three config-gated routes under the existing `/commerce/seller`
group with `commerce_seller:<capability>` middleware); create
`tests/Integration/Marketplace/SellerOrderSurfaceTest.php`. **NO commit until Task 9.**

**Interfaces:**
- `GET /commerce/seller/{sellerUuid}/orders` (cap `commerce.seller.orders.read`) — paginated,
  `confirmed_at IS NOT NULL` only, filter by `fulfillment_status`.
- `GET /commerce/seller/{sellerUuid}/orders/{sellerOrderUuid}` (cap `…orders.read`) — this seller's
  lines + allocated totals + fulfillment + the §2.12 shipping-only allowlist; unconfirmed ⇒ 404.
- `POST /commerce/seller/{sellerUuid}/orders/{sellerOrderUuid}/fulfill` (cap
  `commerce.seller.orders.fulfill`) — body `{carrier, tracking_number, tracking_url}`; delegates to
  `SellerOrderFulfillmentService::fulfill` with `$actorSellerUuid = {sellerUuid}`; unconfirmed ⇒ 404.
- Cross-seller access, unknown/non-partitioned seller order ⇒ 404 non-revealing (§6.4).

- [ ] TDD: capability × role matrix over real routes (read all four roles; fulfill owner/admin/staff
  200, analyst 403); cross-seller 404; unconfirmed partition 404 on read AND fulfill; shipping-only
  allowlist proven with poison strings (billing/email/user/token/metadata absent from raw + decoded
  body); confirmed-only listing. → implement → GREEN. NO commit.

### Task 9: Operator breakdown/fan-out + customer projection — **GROUP C COMMIT**
**Files:** modify `src/Http/Admin/AdminOrderController.php` (partitioned order detail gains the
seller-order breakdown; `POST …/{orderUuid}/seller-orders/{sellerOrderUuid}/fulfill`; partitioned
`POST …/{orderUuid}/fulfill` becomes the operator fan-out; reject independent parent-only fulfill for
partitioned orders), `src/Http/Storefront/OrderController.php` (add allowlisted `seller_groups` to the
`/commerce/orders/{number}` projection when `marketplace_partitioned`), `routes.php` (operator
seller-order route); create `tests/Integration/Http/StorefrontSellerGroupsProjectionTest.php`; extend
`tests/Integration/Http/AdminOrder*` coverage.

**Interfaces:**
- Operator: `GET …/{orderUuid}` detail includes `seller_orders` breakdown when partitioned;
  `POST …/{orderUuid}/seller-orders/{sellerOrderUuid}/fulfill` (operator fulfills any child);
  partitioned `POST …/{orderUuid}/fulfill` ⇒ `fanOutFulfill`; independent parent-only fulfill on a
  partitioned order ⇒ 409/422.
- Customer `seller_groups[]` per group: `seller_reference`, seller display name, its line subset,
  `allocated_subtotal`, `allocated_discount`, `allocated_shipping_discount`, `allocated_shipping`,
  `allocated_tax`, `attributed_total`, fulfillment `{fulfillment_status, carrier, tracking_number,
  tracking_url}`. Excluded: `seller_uuid`, `revision`, internal `status`, `tenant_uuid`,
  `tax_attribution_method`, all MV3 settlement fields.

- [ ] TDD: operator breakdown present only when partitioned; operator fan-out fulfills all children +
  rolls up; independent parent-only fulfill on partitioned ⇒ 409/422; non-partitioned admin fulfill
  unchanged; customer `seller_groups` present only when partitioned, allowlist proven with poison
  strings (no `seller_uuid`/settlement/internal leak in raw + decoded body); non-partitioned customer
  projection byte-identical. → implement → GREEN. Full suite + phpcs + analyze.
- [ ] **COMMIT (Group C):** `feat(marketplace): seller orders, fulfillment rollup, and order projections`

---

## GROUP D — Gates

### Task 10: Regression, races, convergence, docs — **GROUP D COMMIT**
**Files:** extend `tests/Integration/Marketplace/MarketplaceRegressionTest.php`; create
`tests/Integration/Marketplace/CheckoutClaimPgsqlTest.php`,
`tests/Integration/Marketplace/FulfillmentPgsqlTest.php`; ensure `MarketplaceOrderShapeTest`
convergence lanes run under pgsql; modify `tests/Integration/Http/HttpDocumentationTest.php` (flag-ON
walk covers the new routes), `CHANGELOG.md` (`[Unreleased]`).

- [ ] Regression: route manifest identical with `installEnabled` false; a **non-partitioned** order's
  checkout/payment/fulfill/cancel/projection execute **zero** seller-table queries (query-count
  instrumentation, matched on all four marketplace tables: `commerce_marketplace_settings`,
  `commerce_sellers`, `commerce_seller_memberships`, and `commerce_seller_orders`); non-partitioned
  customer projection byte-identical; `DiagnosticsReport` lists `commerce_seller_orders` and
  tenant-adopt rekeys it with the switch off.
- [ ] Partition invariance: an order placed while active stays child-aware + exposes `seller_groups`
  after the workspace deactivates (behavior follows `marketplace_partitioned`, not `activeFor`); a
  non-partitioned order stays byte-identical regardless of later activation.
- [ ] pgsql lanes (live, both orderings where stated): checkout-vs-transfer (no torn mapping — the
  order snapshots the committed seller or the checkout `409`s; product never lands on a stale seller),
  checkout-vs-suspend, checkout-vs-close; two children fulfilling concurrently ⇒ the parent
  `fulfillment_revision` claim serializes them so the rollup is computed once and `OrderFulfilled`
  fires once; paid-CAS-plus-`confirmed_at` stamps commit-or-rollback together across two connections.
- [ ] Migration shape live on pgsql: a fresh install produces the folded order/order-line columns and
  the `commerce_seller_orders` table; the `commerce_order_lines` `(order_uuid, seller_uuid)` and the
  seller-orders indexes asserted via `pg_indexes`; `011` re-run is a no-op.
- [ ] CHANGELOG `[Unreleased]`: MV2 shared checkout + seller orders; schema additions folded into
  `001`/`004` plus new `011_CreateSellerOrderTables`, with the manual-sync note for existing
  databases; default-off, non-partitioned behavior unchanged.
- [ ] Full suite (SQLite + live pgsql lanes) + phpcs + analyze. **COMMIT (Group D):**
  `feat(marketplace): mv2 gates, races, and regression proof`

---

## Self-Review Notes

- **Spec coverage:** §2.1–2.5 → T4 (+ T2/T3 inputs); §2.6 marker → T1 schema + T5 set + T9/T10
  branch; §2.7 checkout claims → T5; §2.8 fulfillment claim/rollup → T7; §2.9 fan-out/cancel → T7+T9;
  §2.10 status → T5 (`open`) + T7 (`canceled`); §2.11 line UUID → T5; §2.12 confirmed_at + allowlist →
  T6 (stamp) + T8 (gate/allowlist); §3.1–3.3 schema → T1; §3.4 migration convergence → T1 + T10;
  §5 services → T2–T9 as mapped; §6 APIs → T8/T9; §7 off-invariance → T10; §8 tests → distributed;
  §9 file map → matches; §10 seams → all RESOLVED and encoded in the constraints/interfaces above.
- **Verify-at-implementation (already resolved in spec §10, restated for implementers):** persist the
  cart-line `line_uuid` at `OrderRepository::orderLineRow()`; `markPaid` has no transaction yet (T6
  introduces it); `DbTaxCalculator::quoteDetailed` folds line+shipping tax into one `$total` today
  (T3 splits it); no `hasIndex` on the schema builder (T1 uses `hasColumn` + driver introspection).
- **Type consistency:** `attributed_total`, `allocated_*`, `tax_attribution_method`,
  `fulfillment_revision`, `confirmed_at`, `marketplace_partitioned`, `FulfillmentStatus`,
  `LargestRemainder::distribute`, `SellerAllocationCalculator::allocate`, `SellerOrderRepository`,
  `SellerOrderFulfillmentService::{fulfill,fanOutFulfill}`, `SellerOrderPaymentConfirmation::confirm`
  used identically across tasks.
- **Invariant-bearing subtleties for reviewers:** (a) every product-create-adjacent path is
  irrelevant here — MV2 touches checkout, not create — but the §2.5 reconciliation asserts and the
  free-shipping `allocated_shipping_discount` split are where a rounding or kind-detection error hides
  (T4/T5). (b) The `confirmed_at` gate is the only thing between a pending order and seller PII
  exposure (T6/T8). (c) The parent `fulfillment_revision` claim is what prevents two concurrent child
  fulfillments from both computing `partial` (T7/T10). (d) `marketplace_partitioned`, not `activeFor`,
  governs all historical behavior (T5 set-once; T9/T10 branch).
