# Marketplace MV5b — Seller Suspension & Lifecycle Enforcement — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Make seller suspension actually mean something — soft-delist from storefront/checkout, freeze payouts, but honor in-flight paid orders — as an operator-only, audited, prospective, reversible transition. Commerce-only.

**Architecture:** A durable lifecycle-audit table (017) + the existing `SellerService` transition primitive gains reason/actor/audit + idempotent no-op/409 semantics. Dedicated buyer-availability reads apply one centralized predicate (`seller_uuid IS NULL OR seller.status='active'`) without changing the shared tombstone-only `findLive*` APIs used by admin/internal services. Checkout's existing claimed seller-status guard is regression-pinned. Seller routes opt into suspended access explicitly. New payouts freeze via a seller-**revision** claim and post-claim status/idempotency decision before the account lock. In-flight orders + committed payouts continue untouched.

**Tech Stack:** PHP 8.3, Glueful framework, PostgreSQL + SQLite test lanes.

**Authoritative spec:** `docs/superpowers/specs/marketplace/2026-07-19-mv5b-seller-suspension-design.md` — every task's requirements implicitly include it; §-refs point into it.

## Global Constraints

- **Bundled into the unpublished Commerce 1.2.0** (MV4 + MV5a + MV5b). MV5b is commerce-only — NO framework/contracts/payvia changes, no cross-package dependency. **Do NOT bump any version or composer pin, or create any tag** (release is the USER's step). Lifecycle events are a **new migration 017**; migrations `010`–`017` first publish together in 1.2.0.
- **Per-repo commits on `dev`** (commerce, HEAD `b33f4dc`). Explicit `git add <paths>` only (never `-A`/`.`). **Never stage `docs/superpowers/**` or `.superpowers/**`.** No AI/Anthropic attribution, no `Co-Authored-By`, no trailer.
- **Prospective + reversible + operator-only:** suspension stops NEW activity, NEVER rewrites history (snapshots/ledger/product rows immutable on a status change). No inference from debt/chargebacks/provider signals — the existing `SellerService::suspend`/`reactivate`/`close` is the single audited transition.
- **Centralized buyer predicate (§2.3):** exactly one predicate — a seller-backed product is buyer-visible/buyable only when `seller.status='active'`; a sellerless product (`seller_uuid IS NULL`, ordinary non-marketplace store) is ALWAYS allowed by this predicate (never excluded by an inner join). It belongs only in dedicated buyer reads and `activeFilteredQuery`; shared `findLiveByUuid`/`findLiveBySlug` remain tombstone-only for admin/internal services.
- **Idempotency/409 (§2.1):** same-state re-suspend/re-activate = no-op returning current state, NO new lifecycle event; incompatible transitions (reactivate/suspend a `closed` seller, `onboarding → suspended`) → 409. Never a 500. Reason (non-empty) + actor mandatory (422 before write).
- **Serialization (§2.7):** `PayoutService::record()` and `reserve()` claim the seller **revision** (`SellerRepository::claimRevision`) inside their txn. Exact order: **revision claim → seller/status + idempotency re-read → account lock → balance/debt/capacity re-read**. Suspension already bumps the revision; it does NOT lock per-currency accounts. An existing matching manual-payout replay is returned before a fresh-payout status refusal.
- **Ledger untouched by status:** suspension moves/clears NO funds; balances stay derived + visible. In-flight committed provider payouts continue reconciliation (never cancelled).

---

## Package — commerce 1.2.0 (repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce`, branch `dev`, HEAD `b33f4dc`)

### Task 1: Schema — migration 017 lifecycle-events table + diagnostics + shape test  *(NO commit — Commerce commit 1 lands after Task 2)*

**Files:**
- Create: `migrations/017_CreateSellerLifecycleEventsTable.php`
- Modify: `src/Support/DiagnosticsReport.php` (`commerceTables()` + tenant-table inventory), `tests/Support/CommerceTestCase.php` (`MIGRATIONS`), `tests/Integration/Tenancy/TenantAdopterTest.php`
- Create: `tests/Integration/Migrations/SellerLifecycleShapeTest.php`

**Schema (§3, verbatim):** `commerce_seller_lifecycle_events`: `id` bigint PK autoInc; `uuid` varchar(12); `tenant_uuid` varchar(12) default `''`; `seller_uuid` varchar(12); `from_status` varchar(16); `to_status` varchar(16); `actor_uuid` varchar(12) **NOT NULL**; `reason` varchar(255); `created_at` timestamp default CURRENT_TIMESTAMP; unique `(tenant_uuid, uuid)`; index `(tenant_uuid, seller_uuid, created_at)` (short explicit name — pg NAMEDATALEN, mirror MV5a 015/016). NEW table → no fold. Add it to both DiagnosticsReport inventories, the test migration list, and every exact-list tenant-table assertion.

- [ ] **Step 1: RED** `SellerLifecycleShapeTest` — columns/types/defaults; `actor_uuid` NOT NULL (an insert omitting it fails); the unique + index via driver introspection; DiagnosticsReport lists the table in both inventories; re-run 017 no-op. Extend `TenantAdopterTest` to prove sentinel rows adopt to the resolved tenant and cross-tenant rows remain isolated.
- [ ] Steps 2-4: FAIL → implement → GREEN; full `composer test`; phpcs + analyze. **NO commit.**

### Task 2: Lifecycle transition service — reason/actor/audit + idempotent/409 + operator reason  *(Commerce commit 1)*

**Files:**
- Modify: `src/Marketplace/SellerService.php` (`suspend`/`reactivate`/`close` + `transition`), `src/Marketplace/SellerLifecycleException.php` (ensure → 409 mapping)
- Create: `src/Marketplace/SellerLifecycleEventRepository.php`, `src/Http/DTOs/SellerLifecycleListQuery.php`
- Modify: `src/Http/Admin/MarketplaceAdminController.php` (inject the event repo; `suspendSeller`/`reactivateSeller`/`closeSeller` — require `reason` body, derive actor; `sellerLifecycle` history action), `routes.php`, `src/CommerceServiceProvider.php` (register/wire the repo)
- Test: `tests/Integration/Marketplace/SellerLifecycleTest.php`, the admin HTTP/controller test covering marketplace seller routes

**Interfaces:**
- `SellerService::suspend(c, tenant, uuid, string $reason, string $actor): array` / `reactivate(...same...)` / `close(c, tenant, uuid, string $reason, string $actor): array` — reason non-empty + actor non-empty validated FIRST (422/ValidationException before any claim). Inside ONE `db()->transaction()`: `claimRevision` (existing) → re-read current status → **same-state short-circuit** (already `suspended` for suspend / already `active` for reactivate → return current row, write NO event, NO status write) → else the `allowedFrom` guard (incompatible → `SellerLifecycleException` → 409; specifically reactivate/suspend a `closed` seller, `onboarding → suspended`) → write status → append a `commerce_seller_lifecycle_events` row (`from_status`, `to_status`, `actor_uuid`, `reason`) via the new repo, in the SAME txn (a failed audit rolls the transition back). `close` keeps its live-products 409 guard + membership deactivation, now also writes the audit row.
- `SellerLifecycleEventRepository::insert(c, tenant, array $row): void` (uuid-collision-injectable generator seam like the MV5a policy-event repos, to prove atomicity) and `paginatedForSeller(c, tenant, sellerUuid, page, perPage): array{items:list<array<string,mixed>>,total:int}` ordered `created_at DESC, id DESC`.
- Operator: `suspendSeller`/`reactivateSeller`/`closeSeller` read `reason` from the request body (422 if blank), pass the authenticated admin's uuid as actor, tenant from the resolved admin tenant (never a body tenant). Add `GET /marketplace/sellers/{uuid}/lifecycle` under the existing authenticated tenant-admin + `commerce:read` stack. Re-read the seller through the tenant-scoped repository first; unknown and cross-tenant UUIDs produce the same 404. Validate `page`/`per_page` with the house bounds and return `Response::paginated`.
- Before implementation, `rg` every direct `suspend`/`reactivate`/`close` caller (controllers, integration tests, and pgsql fixture children) and migrate all of them to the non-optional reason+actor signature. Do not add compatibility defaults that can create unaudited transitions.

- [ ] **Step 1: RED** `SellerLifecycleTest` — suspend active→suspended writes status + one audit row atomically (forced audit-uuid collision rolls back both); reactivate suspended→active same; **idempotent**: re-suspend a suspended seller → returns current, NO 2nd event, NO 409; re-activate an active seller → no-op no-event; **409**: reactivate a closed seller, `onboarding → suspended`; blank reason / blank actor → 422 before any write; `close` writes an audit row + still 409s while owning live products; the audit row records from/to/actor/reason. HTTP tests pin paginated newest-first lifecycle history and indistinguishable unknown/cross-tenant 404s.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates.
- [ ] **Step 5: COMMIT (Commerce 1)** — explicit add of migration 017, DiagnosticsReport, CommerceTestCase, SellerService, SellerLifecycleException, SellerLifecycleEventRepository, MarketplaceAdminController, routes.php, CommerceServiceProvider, the two tests → `feat(marketplace): mv5b seller lifecycle audit, reason, and idempotent transitions`.

### Task 3: Centralized buyer-availability predicate  *(NO commit — Commerce commit 2 lands after Task 4)*

**Files:** modify `src/Catalog/ProductRepository.php` (dedicated buyer reads + `activeFilteredQuery`), `src/Http/Storefront/ProductController.php`, `src/Catalog/ReviewService.php`; test `tests/Integration/Catalog/SuspendedSellerVisibilityTest.php` plus the existing storefront/review tests.

**Interfaces (§2.3):** add ONE reusable predicate to `findBuyerAvailableByUuid`, `findBuyerAvailableBySlug`, and `activeFilteredQuery`: a product is included only when **`seller_uuid IS NULL OR EXISTS(seller row WHERE uuid=product.seller_uuid AND tenant=? AND status='active')`**. Use an EXISTS/LEFT-JOIN shape that keeps sellerless products and active-seller products, excluding onboarding/suspended/closed without a state deny-list. `findLiveByUuid` and `findLiveBySlug` remain unchanged tombstone-only APIs because admin and internal mutation services depend on them.

Before editing callers, use `rg` to inventory every interactive `findLive*` use and classify it buyer vs admin/internal. Migrate only storefront direct product reads and public review submit/read paths here; Task 4 owns cart paths. Catalog mutation, importer, relationship, inventory, media, download, commission, attribution, and admin paths stay on `findLive*`. A test must prove this distinction, not merely `findIncludingDeleted*` access.

- [ ] **Step 1: RED** — a product whose seller is `active` is buyer-visible; the SAME product after the seller is `suspended` (or `closed`, or `onboarding`) is excluded from the dedicated buyer UUID/slug reads and `listActive`; a **sellerless** product stays visible; `findLiveByUuid`/`findLiveBySlug` and representative admin/catalog mutation paths still return the suspended seller's live product; public review submit/read follows buyer availability; reinstating re-includes with NO product-row change. Verify on SQLite + gated pgsql.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates. **NO commit.**

### Task 4: Cart + checkout enforcement  *(Commerce commit 2)*

**Files:** modify `src/Cart/CartService.php` (resolve add/update/pricing through the dedicated buyer read); test `tests/Integration/Orders/SuspendedSellerCheckoutTest.php` and extend the existing checkout claim/race coverage. `src/Orders/CheckoutService.php` changes only if the regression test exposes a missing stable error mapping; do not duplicate its existing seller-status guard.

**Interfaces (§2.4):**
- Cart add/update/pricing of a product whose seller is not `active` → a **stable unavailable-product error**. Replace CartService's buyer-context `findLiveByUuid` calls with `findBuyerAvailableByUuid`; do not migrate non-buyer callers.
- Checkout: `CheckoutService::claimMarketplaceOwnership()` already snapshots sellers, claims seller revisions in sorted order, re-reads them, and requires `status='active'` before any order write. Pin that behavior with regression/race tests. **Invariant:** no order is created for a seller after suspension commits.

- [ ] **Step 1: RED** — cart add of a suspended seller's product rejected (stable error); a cart built while active, then the seller suspended, then checkout → checkout FAILS (no order/seller-order created); a sellerless / active-seller cart checks out normally (byte-identical); existing paid orders (pre-suspension) remain readable/fulfillable.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates. Preserve CheckoutService unless the RED test identifies a real gap.
- [ ] **Step 5: COMMIT (Commerce 2)** — explicit add of ProductRepository, storefront/review/CartService buyer callers, any evidence-backed CheckoutService correction, and the Task-3 + Task-4 tests → `feat(marketplace): suspended-seller soft-delist and checkout enforcement`.

### Task 5: Suspended-member fulfillment authorization  *(NO commit — Commerce commit 3 lands after Task 6)*

**Files:** modify `src/Http/Middleware/SellerMemberMiddleware.php`, `routes.php`; test `tests/Integration/Marketplace/SuspendedSellerAuthorizationTest.php` and extend `tests/Integration/Marketplace/SellerMiddlewareTest.php`. `SellerOrderFulfillmentService` remains status-independent unless a RED test exposes an actual block.

**Interfaces (§2.6):** extend `commerce_seller:<capability>` with an explicit second comma-delimited middleware parameter, `allow_suspended` (`Router::resolveMiddleware()` parses `name:param1,param2`), for example `commerce_seller:commerce.seller.orders.read,allow_suspended`. Default behavior for a valid active membership on a suspended seller is a stable **409** regardless of HTTP method. Mark only these routes:

- `GET /{sellerUuid}/orders` and `GET /{sellerUuid}/orders/{sellerOrderUuid}` (`orders.read`)
- `POST /{sellerUuid}/orders/{sellerOrderUuid}/fulfill` (`orders.fulfill`, including tracking updates)
- `GET /{sellerUuid}/financials/balance` and `GET /{sellerUuid}/financials/reserves` (`reports.read`)

The role authority check remains mandatory after lifecycle eligibility: an allowed-suspended route without the capability returns **403**. Every unmarked catalog, inventory, membership, report, commission-policy, payout, and payout-account route returns **409** for a suspended seller, including GET/HEAD. `closed` never qualifies for `allow_suspended`; its deactivated-membership/terminal behavior stays non-revealing. `/mine` remains membership discovery and has no seller-resource middleware. Operator routes are unaffected. This replaces the current method-based rule that allows all GET/HEAD requests while suspended and blocks fulfillment POST.

- [ ] **Step 1: RED** — a suspended seller member with the right capability CAN list/show/fulfill an existing placed order, update tracking, and read balance/reserves; capability absence on one of those routes is 403. The same member receives 409 on representative catalog GET+write, inventory GET+write, members GET+write, financial report, commission-policy, payouts, and payout-accounts routes. A closed seller does not gain this allowance. An operator can still fulfill; active-seller behavior is byte-identical.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates. **NO commit.**

### Task 6: Payout freeze + revision serialization  *(Commerce commit 3)*

**Files:** modify `src/Marketplace/PayoutService.php` (required `SellerRepository`; `record()` + `reserve()` ordering/status/replay), `src/CommerceServiceProvider.php` (factory wiring), every direct constructor in tests and pgsql fixture children, and `src/Console/PayoutsRunBatchCommand.php` only if its counters need adaptation to the service's `null` skip; test `tests/Integration/Marketplace/SuspendedSellerPayoutTest.php` plus existing payout suites.

**Interfaces (§2.7):**
- Add `SellerRepository` as a required constructor dependency before the optional seams; update `CommerceServiceProvider::makePayoutService()` and use `rg "new PayoutService"` to update every direct test/fixture constructor. Do not hide the dependency behind container lookup or an optional fallback.
- `record()` keeps its existing fast preflight `findByIdempotencyKey`. Inside its transaction: claim seller revision → re-read seller → repeat `findByIdempotencyKey`; if present, call the existing `verifyReplay()` and return even when the seller is now non-active → otherwise require `status='active'` (stable 422) → account lock → balance/debt/capacity re-read → insert + ledger post. The second lookup closes the concurrent preflight-miss/suspension/replay race.
- `reserve()` inside its transaction: claim seller revision → re-read seller/status → if non-active, return `null` for batch (`amount === null`) or throw stable 422 for an explicit provider payout → account lock → balance/debt/capacity re-read → insert + hold. This is additive to MV4/MV5a readiness, debt, and capacity gates.
- Batch uses the service's locked `null` result as an independent candidate skip, not an exception/failure counter. Change the command only if its current result handling cannot represent that.
- In-flight committed provider payouts (`retry`/`reconcile`) are NOT gated on current status — they continue (§2.7). Only NEW payout creation is frozen.

- [ ] **Step 1: RED** — a suspended seller: new manual payout refused 422; explicit provider payout refused 422 before any hold/row; batch skips without recording a failure; an active seller is unaffected; an in-flight provider payout of a now-suspended seller still retries/reconciles. Pin the exact revision→seller/status/replay→account-lock→balance order. Add the concurrency regression where manual preflight misses, the matching payout commits, suspension follows, and the loser returns the verified replay rather than a lifecycle refusal. Run every existing MV3/MV4/MV5a payout suite after the constructor sweep.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates.
- [ ] **Step 5: COMMIT (Commerce 3)** — explicit add of SellerMemberMiddleware, routes.php, PayoutService, provider/constructor wiring, any evidence-backed batch command change, and the Task-5 + Task-6 tests → `feat(marketplace): suspended-seller payout freeze and fulfillment authorization`.

### Task 7: Gates — regression, live pgsql races, CHANGELOG  *(Commerce commit 4)*

**Files:** extend `tests/Integration/Marketplace/MarketplaceRegressionTest.php`; create `tests/Integration/Marketplace/SellerSuspensionPgsqlTest.php` (+ fixture child; mirror `ReserveChargebackPgsqlTest`/`PayoutSagaPgsqlTest`); modify `tests/Integration/Http/HttpDocumentationTest.php` (the new lifecycle-audit route); `CHANGELOG.md` `[Unreleased]`.

- [ ] **Regression:** no suspension ⇒ storefront listing/search/direct-read + cart + checkout + fulfillment + payout JSON byte-identical; the seller-active predicate is a no-op for active + sellerless products; shared `findLive*` admin/internal paths remain unfiltered; zero new queries on paths that don't read products/sellers/payouts; the lifecycle table appears in both diagnostics inventories and adopts/scopes per tenant; lifecycle history is paginated, tenant-bound, and documented.
- [ ] **Live pgsql (`COMMERCE_TEST_DB_DRIVER=pgsql`, run live, paste verbatim), BOTH orderings each:**
  - **suspension vs checkout** under the `workspace → seller → product` claim order: A holds the seller claim mid-checkout while B suspends (and vice versa) — either checkout commits before suspension, or suspension wins and checkout fails; NEVER an order created after suspension commits.
  - **suspension vs payout reservation** under the revision→account-lock order: A claims the seller revision mid-payout-reserve while B suspends (and vice versa) — payout-first commits (later suspension = in-flight); suspension-first → reserve refuses. Never a payout created after suspension commits.
  - Migration-shape live: the 017 table columns/unique/index via `pg_indexes`, re-run no-op.
- [ ] **CHANGELOG `[Unreleased]`:** MV5b — seller suspension enforcement (soft-delist via centralized active-seller predicate, checkout revalidation, payout freeze with revision serialization, restricted fulfillment surface, honored in-flight orders), audited operator-only transitions with mandatory reason, idempotent no-op/409; default-off, prospective, reversible; closed sellers buyer-unavailable but terminal. Note it ships in the same bundled Commerce 1.2.0 (MV4+MV5a+MV5b); migration 017 first publishes there. No version/pin bump.
- [ ] **COMMIT (Commerce 4)** — `feat(marketplace): mv5b suspension gates, races, and regression proof`. Then whole-branch review of the MV5b commerce range.

---

## Self-Review notes
- **Spec coverage:** §2.1→T2; §2.2→T1+T2; §2.3→T3; §2.4→T4; §2.5→T4+T5; §2.6→T5; §2.7→T6; §2.8→T2+T3+T6; §2.9→T2+T3+T5; §3→T1; §4→T2+T5; §5→T7; §6→per-task+T7.
- **Invariant-bearing subtleties for reviewers:** (a) ONE centralized buyer predicate, but ONLY dedicated buyer APIs receive it — sellerless products never drop and shared `findLive*` admin/internal paths stay tombstone-only (T3/T4); (b) transition is same-state-no-op-no-event / incompatible-409 / atomic status+audit, with every caller migrated (T2); (c) actor_uuid NOT NULL + reason mandatory, and lifecycle history is tenant-bound/paginated (T1/T2); (d) suspended route access is explicit per route, never inferred from HTTP method, and still capability-gated (T5); (e) payout creation orders revision→status/idempotency→account lock→balance, with committed replay preserved (T6); (f) suspension is prospective — existing checkout guard retained, in-flight orders fulfillable, committed payouts continue, snapshots/ledger/product rows immutable (T4/T5/T6); (g) closed shares buyer-unavailability only; (h) both pgsql race orderings for checkout and payout (T7).
- **Release:** bundled into unpublished commerce 1.2.0; migration 017; no version/pin bump; CHANGELOG under [Unreleased]. Commerce-only — no framework/contracts/payvia.
