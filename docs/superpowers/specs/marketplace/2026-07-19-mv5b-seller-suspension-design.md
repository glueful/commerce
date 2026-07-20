# Marketplace MV5b — Seller Suspension & Lifecycle Enforcement (design)

**Status:** held / uncommitted draft for user hardening.
**Slice of:** the marketplace overview (`2026-07-16-multi-vendor-overview-design.md`, §MV5 "marketplace hardening"). MV5b makes **seller suspension actually mean something** across storefront, checkout, orders, and payouts. The lifecycle *state machine* already exists (`SellerService::suspend`/`reactivate`/`close`, status `onboarding|active|suspended|closed`); today suspension only blocks NEW product creation and NEW memberships — existing listings stay buyable, in-flight orders stay fulfillable, and payouts stay open. MV5b wires suspension's **consequences**. MV5c (seller API keys/webhooks) is the remaining sibling slice.

**Scope:** commerce-only — NO framework/contracts/payvia changes and no new cross-package release dependency. MV5b joins Commerce's existing unpublished 1.2.0 train (§Release) but does not change the framework → contracts → payvia ordering. Suspension is **prospective**: it stops new activity, never rewrites history.

**Builds on:** the existing `SellerService` transition primitive (`claimRevision` → status write), `ProductRepository` buyer-facing active reads, the MV2 checkout `workspace → seller → product` claim order and existing seller-status recheck, the MV2/MV3 immutable seller-order snapshots, MV4/MV5a `PayoutService` reserve gate (`available`/`debt`/readiness), and `LedgerAccountLock`.

**Default-off:** no seller is ever suspended without an explicit operator action; with no suspensions the storefront/checkout/payout paths are byte-identical to today.

**Release (decided):** MV5b **bundles into the still-unpublished Commerce 1.2.0** (alongside MV4 + MV5a) — commerce is unpublished and unused externally, so a separate 1.3.0 boundary adds no value. Lifecycle events are a dedicated **new migration 017**; **migrations `010`–`017` first publish together in Commerce 1.2.0.** No version/pin bump on the branch (release is the user's step).

---

## 1. Goal

Define and enforce, everywhere it matters, what a **suspended** seller can and cannot do — soft-delisted from the storefront and unbuyable at checkout, payouts frozen while the ledger keeps accruing, but already-placed paid orders honored and fulfillable — as an operator-only, audited, prospective, fully-reversible transition.

---

## 2. Design

### 2.1 Lifecycle transition service (operator-only, audited, reason-mandatory, idempotent)
- Suspension and reinstatement stay the **single audited operator transition** (`SellerService::suspend`/`reactivate`), gated `commerce:write`. MV5b makes them require: a non-null **actor** and a **mandatory non-empty reason** (422 before any write if missing). The `$actor` param — currently accepted and IGNORED — is now threaded to the audit.
- **Idempotent / stable errors (decided):** suspending an already-`suspended` seller is a **stable no-op that returns the current suspended state and writes NO new lifecycle event**; likewise reactivating an already-`active` seller is a no-op returning the active state, no event. An **incompatible transition returns a 409** (`SellerLifecycleException` → 409) — in particular reactivating (or suspending) a **terminally `closed`** seller, and `onboarding → suspended`. Never a 500. The existing `allowedFrom` guard is retained (suspend: `active → suspended`; reactivate: `suspended → active`); the no-op short-circuit is checked before the guard so same-state calls don't 409.
- **No inference:** suspension is NEVER derived from debt, chargeback count, reserve use, or provider signals. Automatic risk policies (a later slice) call this SAME transition service rather than introducing a second suspension path.

### 2.2 Durable lifecycle audit (`commerce_seller_lifecycle_events`)
- Every suspend/reactivate (and, folded in, close) writes an append-only `commerce_seller_lifecycle_events` row in the **same transaction** as the status write (a failed audit rolls back the transition — no unaudited lifecycle change), mirroring the MV3 commission-policy / MV5a reserve-policy audit idiom.
- Columns (§3): uuid, tenant_uuid, seller_uuid, `from_status`, `to_status`, `actor_uuid`, `reason`, created_at. This table is the authoritative reason+actor history; the `commerce_sellers` row keeps only `status` (no denormalized reason column — pin in §7 if a current-reason display is wanted).

### 2.3 Catalog / storefront soft-delist (seller-backed products require an active seller)
- Define one centralized buyer-availability predicate: **a seller-backed product is buyer-visible/buyable only when its seller has `status = active`**. `ProductRepository` exposes dedicated buyer reads (`findBuyerAvailableByUuid` / `findBuyerAvailableBySlug`) and applies the same predicate to `activeFilteredQuery`/`listActive`. This excludes `onboarding`, `suspended`, and `closed` sellers without maintaining separate deny-lists. Sellerless products on an ordinary, non-marketplace store remain governed by the existing product predicate and are not excluded by an inner join. A suspended/closed seller's products are treated as unavailable for buyer-facing reads, exactly like a delisted product, WITHOUT mutating any product row (`deleted_at`, `status`, ownership all unchanged).
- **The existing `findLiveByUuid` / `findLiveBySlug` APIs remain tombstone-only.** They are shared by admin, catalog mutation, importer, relationship, inventory, download, media, and seller-attribution paths; adding the seller-status predicate there would incorrectly disable operator/internal work. Only a grep-enumerated buyer call-site inventory moves to the dedicated buyer APIs: storefront direct reads, cart pricing/add/update paths, and public review submit/read paths. Operator/admin/internal reads retain full access to a suspended seller's products.
- **Reinstatement restores visibility automatically** (the predicate re-includes them) — no product-row rewrite, no re-publish step.

### 2.4 Cart + checkout enforcement (reject suspended; revalidate under the claim order)
- **Cart add/update/pricing** resolve products through the dedicated buyer-availability API. A suspended seller's product is rejected with a **stable unavailable-product error** (the same shape a delisted/unpurchasable product returns).
- **Checkout already revalidates seller status** in `claimMarketplaceOwnership()` under the existing **`workspace → seller → product` claim order** (MV2). MV5b retains and regression-pins that guard rather than adding a second status check: a suspended seller fails checkout even for an item added to the cart BEFORE suspension.
- **Invariant (pinned):** a checkout either commits BEFORE suspension takes effect, or suspension wins and checkout fails — **an order is NEVER created for a seller after suspension commits** (see §6 race).

### 2.5 In-flight orders remain fulfillable (prospective; snapshots immutable)
- Already-placed **paid seller orders remain visible and fulfillable through suspension** — fulfillment is NOT gated on the seller's CURRENT status (suspension is prospective). Refunds, chargebacks (MV5a), tracking updates, and customer access to purchased items all continue.
- The immutable seller/order snapshots (MV2/MV3) are NEVER rewritten by a status change. Fulfillment availability does NOT reactivate the seller's products (§2.3 stays in force).

### 2.6 Seller-member fulfillment authorization (suspended: minimum surface)
- Authorization still applies while suspended: `commerce_seller:<capability>` gains an explicit route-level `allow_suspended` policy. The default is fail-closed: a suspended seller receives a stable **409** on every seller route unless that route opts in. The exact opt-in set is: order list/detail (`orders.read`), fulfillment/tracking (`orders.fulfill`), balance/financial summary (`reports.read`), and reserve history (`reports.read`). Catalog, inventory, membership, payout, policy, and all other seller routes remain unavailable while suspended, including read-only routes.
- The capability check still runs on every opted-in route; `allow_suspended` never grants a capability. Missing capability remains **403**. `closed` never qualifies for the suspended allowance (its inactive memberships retain the existing non-revealing behavior). This replaces the current HTTP-method-wide behavior that allows every GET/HEAD route while suspended and blocks every mutation, including fulfillment.
- **Operator fulfillment remains fully available** for a suspended seller's orders.

### 2.7 Payout freeze (reject new, batch skips, in-flight continue, ledger active)
- **Reject new manual AND provider payouts** for a `suspended` seller (a stable 4xx, mirroring the MV5a debt-gate refusal shape); **scheduled payout batches SKIP** suspended sellers (independent-per-candidate).
- **Existing in-flight provider payouts CONTINUE** through reconciliation — suspension must NOT cancel, reverse, or strand a committed payout attempt (they stay governed by their durable saga/provider state, MV4).
- **The ledger stays fully active:** sale_credits for already-paid orders, refunds, chargebacks, commissions, reserves/releases, and adjustments all keep posting normally. **No funds are mutated or moved merely because status changed** — balances remain derived and visible (to operator and the suspended seller's read-only surface).
- **Serialization (decided) — the seller REVISION is the shared primitive.** `PayoutService` receives `SellerRepository` as a required dependency. Inside new-payout transactions the order is strict: **claim seller revision → re-read seller and decide status/idempotent replay → claim seller/currency ledger-account lock → re-read balance/debt/capacity → write**. `suspend()` already claims the same seller revision. So:
  - **Payout claims the revision first:** its reservation commits; a later `suspend()` blocks on the revision until the reserve txn commits, then flips to suspended — the already-committed payout is correctly treated as in-flight (§2.7 in-flight continues).
  - **Suspension claims the revision first:** the payout reserve blocks on the revision, then re-reads status = `suspended` and REFUSES (no row, no hold).
  - A plain status re-check WITHOUT claiming the revision does NOT close the race — the claim is mandatory. Suspension does NOT need to discover and lock every one of a seller's currency accounts; the single seller-revision claim serializes all currencies. Both race orderings are tested (§6).
- Manual-payout idempotency remains replay-safe across suspension: `record()` keeps its fast preflight replay and repeats the idempotency-key lookup after the seller claim. An already-committed matching payout is returned even if suspension committed later; only a genuinely new payout is refused. Provider batch reservation skips a non-active seller, while an explicit provider request returns the stable 422. Retry/reconcile of an in-flight payout never enters this new-payout gate.

### 2.8 Reinstatement (reversible, no mutation)
- `reactivate` (`suspended → active`, audited, reason+actor) restores storefront visibility (§2.3 predicate re-includes) and payout eligibility — the latter **still subject to** MV4/MV5a gates (destination readiness, `available > 0`, reserve, debt). Reinstatement mutates only `status` + writes the audit row; it never rewrites products, orders, snapshots, or the ledger.

### 2.9 Closed sellers (terminal — buyer-unavailable, but NOT collapsed with suspended)
- `close` (existing) stays **terminal** and blocked while the seller owns live products; MV5b folds its lifecycle-audit row into §2.2 (close now also requires a reason).
- **Only the buyer-facing behavior is shared:** §2.3's centralized `seller.status = active` predicate makes both `closed` and `suspended` buyer-unavailable for storefront listing/search/direct-read and cart/checkout, and both are payout-frozen (§2.7). **Do NOT otherwise collapse the two states:** `closed` is terminal and irreversible (reactivating a closed seller is a 409, §2.1), whereas `suspended` is reversible and retains the restricted **fulfillment** path (§2.5/§2.6). The restricted-fulfillment-surface and reinstatement logic are `suspended`-only.
- **Residual-balance handling on close is OUT of MV5b scope** — MV5b neither moves nor clears funds on close; a closed seller's derived balance stays visible and untouched. Final financial closure + residual-balance disposition require a later dedicated policy (§7).

### 2.10 Coupling & continuity
- Commerce-only; operator-only; prospective; fully reversible (except `close`). No new external coupling. Default-off (no suspension ⇒ byte-identical behavior).

---

## 3. Schema
- **New migration `017_CreateSellerLifecycleEventsTable.php`** — `commerce_seller_lifecycle_events`: `id` bigint PK; `uuid` varchar(12); `tenant_uuid` varchar(12) default `''`; `seller_uuid` varchar(12); `from_status` varchar(16); `to_status` varchar(16); `actor_uuid` varchar(12) **NOT NULL**; `reason` varchar(255); `created_at` timestamp; unique `(tenant_uuid, uuid)`; index `(tenant_uuid, seller_uuid, created_at)`. The schema enforces §2.1's mandatory actor instead of relying only on service validation. A genuinely NEW table → no fold pressure regardless of whether commerce 1.2.0 has published (see §7 release note).
- `commerce_sellers`: **no new columns** — `status` already exists; reason/actor live in the audit table. (If a denormalized `suspended_at`/`suspension_reason` for quick display is wanted, it would fold into `010` if 1.2.0 is unpublished, else an ALTER — deferred, §7.)
- `DiagnosticsReport::commerceTables()` and its tenant-table inventory, `CommerceTestCase::MIGRATIONS`, and the tenant-adopter/scoping tests gain the new table.

## 4. Surfaces
- **Operator (`commerce:write`, marketplace-enabled):** `suspend` and `reactivate` now REQUIRE a `reason` in the request body (422 if blank); actor derived from the authenticated admin; tenant-bound (never a body tenant). `close` unchanged except it now also requires a reason + writes the audit row. The operator lifecycle-history read is tenant-bound, paginated with the house `Response::paginated` envelope, ordered `created_at DESC, id DESC`, and returns the same non-revealing 404 for an unknown or cross-tenant seller.
- **Seller (own, read-only while suspended):** the suspended seller's members see their own balance/orders (read) + the minimum fulfillment surface (§2.6); mutating catalog/membership/payout routes are refused.
- No buyer-facing surface change beyond suspended products disappearing from listings/search/direct reads.

## 5. Off-invariance (regression gates)
- No suspension ⇒ storefront listing/search/direct-read, cart, checkout, fulfillment, and payout JSON byte-identical; the seller-status predicate is a no-op for `active` sellers; zero new queries on paths that don't read products/sellers/payouts; the new audit table adopts/scopes per tenant.

## 6. Testing (highlights)
- Transition: reason/actor mandatory (422 before write); atomic status+audit (forced audit-uuid collision rolls back); idempotent re-suspend; invalid transitions stable 4xx; audit row records from/to/actor/reason; every direct caller/fixture uses the new non-optional signature.
- Lifecycle history: tenant-bound pagination/order; unknown and cross-tenant sellers are indistinguishable; tenant adoption/scoping includes the audit table.
- Catalog: the centralized buyer predicate permits sellerless ordinary products and products whose seller is active; onboarding/suspended/closed seller products are excluded from listing/search/direct read + cart add/update rejected (stable error); shared `findLive*` admin/internal reads remain available; reinstatement re-includes with NO product-row change.
- Checkout: an item added pre-suspension fails through the existing claimed seller-status guard; existing paid orders remain fulfillable; suspended member can access only the explicitly marked order/fulfillment/balance/reserve routes, with capability checks still enforced; operator unrestricted.
- Payout: manual + explicit provider payout refused for suspended (422); batch skips; committed manual idempotency replay still succeeds after suspension; an in-flight provider payout continues reconciliation (not cancelled); ledger keeps posting (sale/refund/chargeback/adjustment); reinstate restores eligibility still subject to readiness/available/reserve/debt.
- **Live pgsql races (MV4/MV5a fixture-child harness), BOTH orderings each:**
  - suspension vs checkout under the `workspace → seller → product` claim order: either checkout commits before suspension, or suspension wins and checkout fails — NEVER an order created after suspension commits.
  - suspension vs payout reservation under the seller/currency account lock (+ the §2.7 serialization primitive): either the payout reserves before suspension, or suspension wins and the reserve is refused — never a payout created after suspension commits.

## 7. Resolved decisions
- **Release — RESOLVED:** MV5b **bundles into the unpublished Commerce 1.2.0** (MV4 + MV5a + MV5b); dedicated new **migration 017**; migrations `010`–`017` first publish together in 1.2.0. No version/pin bump on the branch (user's release step).
- **Payout-vs-suspension serialization — RESOLVED (§2.7):** the seller **revision** is the shared primitive; new payout creation follows **revision claim → seller/status + idempotency re-read → account lock → balance/debt/capacity re-read**. A plain re-check without the claim does not close the race. Suspension does not lock every currency account; committed replay and in-flight reconciliation remain available.
- **Idempotent transitions — RESOLVED (§2.1):** re-suspend / re-activate of a same-state seller is a no-op returning current state, NO new lifecycle event; incompatible transitions (esp. reactivate/suspend a `closed` seller, `onboarding → suspended`) → **409**.
- **`closed` buyer-unavailability — RESOLVED (§2.9):** the soft-delist predicate treats `closed` like `suspended` for storefront/cart/checkout ONLY; the states are NOT otherwise collapsed (closed terminal/irreversible; suspended reversible + restricted fulfillment). `close` now also requires a reason + writes the lifecycle-audit row.
- **Residual balance on close — DEFERRED (§2.9):** MV5b neither moves nor clears funds on close; final financial closure / residual-balance disposition is a later dedicated policy.
- **Denormalized suspension reason on the seller row — NOT in MV5b:** the audit table is authoritative; no `commerce_sellers` reason column.
