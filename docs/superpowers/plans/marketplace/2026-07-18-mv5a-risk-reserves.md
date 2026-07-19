# Marketplace MV5a — Risk, Reserves & Negative Balances — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Ship rolling reserves, provider-reported chargebacks, and negative-balance/debt accounting for the marketplace ledger — as append-only ledger truth, default-off, bundled into the unpublished MV4 release train.

**Architecture:** Reserves and chargebacks post into the existing MV3 append-only ledger (`LedgerAccountLock` before every balance-affecting post; its 12-field replay verifier expands to 14 with `reserve_uuid`/`chargeback_uuid`). Rolling reserves hold a snapshotted % of seller proceeds at settlement and auto-release on a scheduled sweep; a shared FIFO `ReserveConsumptionService` lets both chargebacks and refunds consume unreleased reserve before driving `available` negative (debt). Chargebacks arrive as a dispatchable contracts event that Payvia constructs from a fail-closed payment-owner correlation; Commerce ingests it event-first (idempotent), attributes to immutable line snapshots, and reverses settlement.

**Tech Stack:** PHP 8.3, Glueful framework, PostgreSQL + SQLite test lanes, integer minor units.

**Authoritative spec:** `docs/superpowers/specs/marketplace/2026-07-18-mv5a-risk-reserves-design.md` — every task's requirements implicitly include it; §-refs below point into it.

## Global Constraints

- **Release train (framework seam + MV4 + MV5a):** **framework 1.71.0 → extension-contracts 1.5.0 → payvia 2.1.0 → commerce 1.2.0**. Contracts/payvia/commerce are unpublished and MV5a adds to the SAME version numbers; framework 1.71.0 is a NEW published-framework version that adds the strict-dispatch seam (Task 0). **Do NOT bump any version, edit any composer pin, or create any tag** — release is the USER's step. Migrations `010`–`016` first publish together in commerce 1.2.0, so **every schema change is a FOLD into an unreleased create-migration — never an ALTER, no migration 017, no upgrade migration.**
- **Strict dispatch seam (framework 1.71.0, Task 0):** the framework event bus fault-isolates listener exceptions (`EventDispatcher::dispatch` catch+log, never rethrow), which would silently lose a chargeback if Commerce's listener threw. Task 0 adds `EventService::dispatchOrFail()` (log then RETHROW the original, STOP after the first failing listener; at-least-once → listeners MUST be idempotent). `dispatch()` behavior is unchanged. Payvia dispatches ONLY `ProviderChargebackEvent` via `dispatchOrFail` and marks `provider_events` dispatched only after it returns successfully; ordinary payvia events keep fault isolation. Vendored framework copies in payvia/commerce get the seam mirrored locally (never staged) until the user releases 1.71.0.
- **Vendor-first dev:** the new contract file is unpublished, so before compiling payvia/commerce against it, mirror it into that repo's `vendor/glueful/extension-contracts/src/Payments/` (LOCAL COMPILE AID — vendor/ is git-ignored; **NEVER stage vendor/**; never bump the extension-contracts pin).
- **Per-repo commits on `dev`.** Explicit `git add <paths>` only (never `git add -A`/`.`). **Never stage `docs/superpowers/**` or `.superpowers/**`.** No AI/Anthropic attribution, no `Co-Authored-By`, no trailer anywhere.
- **Ledger discipline:** append-only, never mutation; claim `LedgerAccountLock` (seller/currency) before ANY balance-affecting post; integer minor units; deterministic idempotency keys with the expanded **14-field** verify treating an exact replay as a verified no-op and any correlation mismatch as an integrity failure. **`payout_uuid` stays NULL on every risk-reserve entry** so MV4's `reserved`/`pending` split holds. Every risk hold/release carries `reserve_uuid`; chargeback debit/credit + their commission entries carry `chargeback_uuid`.
- **Contract-only coupling:** commerce NEVER imports a payvia class or reads a payvia table; the chargeback arrives only via the neutral contracts event.
- **Default-off:** marketplace off / reserve policy `0` bps or `0` days / no chargeback ⇒ zero holds, zero chargeback rows, no new routes, byte-identical manual/refund/payout paths, zero new queries on unrelated paths.
- **Historical partition authority:** every refund/chargeback/settlement consequence keys on the order's immutable `marketplace_partitioned` marker, never current `MarketplaceMode::activeFor()`. A partitioned historical order remains financially maintainable after workspace deactivation; a non-partitioned order is outside MV5a and produces no marketplace chargeback/ledger rows.
- **Reserve base (§2.2):** `attributed_total` INCLUDES allocated shipping + tax, so it is NOT the base. Use `merchandise_after_discount = subtotal − allocated_discount` with the guardrail `== attributed_total − allocated_shipping − allocated_tax`; `reserve_base = max(0, merchandise_after_discount − commission_amount)`. `allocated_shipping_discount` is never subtracted.

---

## Package 0 — framework 1.71.0 (repo `/Users/michaeltawiahsowah/Sites/glueful/framework`, branch `dev`)

### Task 0: `EventService::dispatchOrFail()` strict-dispatch seam

**Files:** modify `src/Events/EventService.php` (add `dispatchOrFail`), `src/Events/EventDispatcher.php` (add a strict path); test under `tests/` (mirror existing EventDispatcher/EventService tests).

**Interfaces:** `EventService::dispatchOrFail(object $event): object` → delegates to a new `EventDispatcher::dispatchOrFail(object $event): object` that runs listeners in order, and on the FIRST listener `\Throwable`: report it (same `error_log` as `dispatch()`) then **RETHROW the original exception**, STOPPING further listeners. Preserve `StoppableEventInterface` short-circuit + the tracer hooks. **`dispatch()` is UNCHANGED** (still fault-isolated). Document strict dispatch as **at-least-once** (a caller may re-dispatch on failure, so listeners MUST be idempotent).

- [ ] **Step 1: RED** — a listener that throws under `dispatchOrFail` propagates the ORIGINAL exception (not wrapped), and listeners registered AFTER it do NOT run; the same event under `dispatch()` still swallows + continues (unchanged); a clean chain returns the event normally under both. — [ ] Steps 2-4: FAIL→implement→GREEN; framework phpcs/analyze clean.
- [ ] **Step 5: COMMIT (framework)** — explicit add of the two src files + the test → `feat(events): strict dispatchOrFail that rethrows listener failures`. No version bump, no tag (framework 1.71.0 release is the USER's step).

---

## Package 1 — extension-contracts 1.5.0 (repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/contracts`, branch `dev`, HEAD `844fb89`)

### Task 1: `ProviderChargebackEvent` dispatchable contract event

**Files:**
- Create: `src/Payments/ProviderChargebackEvent.php`
- Test: `tests/Unit/Payments/ProviderChargebackEventTest.php`

**Interfaces:**
- Consumes: `Glueful\Events\Contracts\BaseEvent` (framework), `Glueful\Extensions\Contracts\Payments\PayableReference` (existing — `(string $type, string $id, int $amount, string $currency, ?string $description, array $metadata)`).
- Produces: `ProviderChargebackEvent extends BaseEvent` with readonly promoted ctor `(string $tenantUuid, string $provider, string $providerEventId, string $paymentReference, PayableReference $payable, int $amount, string $currency, ?string $reasonCode, string $occurredAt, string $kind = 'chargeback', ?string $relatedEventId = null)`. Constants `KIND_CHARGEBACK='chargeback'`, `KIND_REVERSAL='reversal'`.

**Invariants (§4):** `provider`/`providerEventId`/`paymentReference`/`currency` non-empty; `amount > 0`; `currency === payable->currency`; `occurredAt` is a parseable timestamp; `kind ∈ {chargeback, reversal}`; a `reversal` REQUIRES a non-empty `relatedEventId`. `tenantUuid` MAY be `''` (single-store Payvia mode) — allowed, not rejected. Call `parent::__construct()` (BaseEvent) so event id/timestamp/metadata work.

- [ ] **Step 1: failing test** — construct valid chargeback + reversal; assert the promoted readonly properties and inherited `getEventId()`; assert `\InvalidArgumentException` on: empty provider/event/reference/currency, amount ≤ 0, currency≠payable currency, malformed occurredAt, bad kind, reversal without relatedEventId. Assert `''` tenant is accepted. Assert it is a `BaseEvent` instance with a non-empty event id.
- [ ] **Step 2:** run → FAIL (class absent).
- [ ] **Step 3:** implement the final class + invariants + `parent::__construct()`.
- [ ] **Step 4:** run → PASS. Run the contracts suite (`vendor/bin/phpunit`) green.
- [ ] **Step 5: COMMIT (contracts)** — `git add src/Payments/ProviderChargebackEvent.php tests/Unit/Payments/ProviderChargebackEventTest.php` → `feat(payments): provider chargeback event for marketplace dispute ingestion`. No version bump, no tag.

---

## Package 2 — payvia 2.1.0 (repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/payvia`, branch `dev`, HEAD `2190ac4`)

> **STEP 0 for every payvia task:** mirror the Task-1 file into `payvia/vendor/glueful/extension-contracts/src/Payments/ProviderChargebackEvent.php` (never staged).

### Task 2: Narrow fail-closed payment-owner correlation

**Files:**
- Modify: `src/Repositories/ProviderCorrelationRepository.php` (add a payment-owner lookup beside the existing subscription methods)
- Test: extend `tests/Integration/Tenancy/ProviderCorrelationRepositoryTest.php`

**Interfaces:**
- Consumes: the existing `payments` table (cols `uuid, tenant_uuid, gateway, gateway_transaction_id, payable_type, payable_id, reference, amount, currency, status`).
- Produces: `findPaymentOwnerByGatewayTxn(string $gateway, string $gatewayTransactionId): ?array` returning exactly `['tenant_uuid','reference','payable_type','payable_id','amount','currency']` for **exactly one** matching persisted payment, else `null` (zero OR multiple matches → `null`, fail closed).

- [ ] **Step 1: failing test** — one match returns the tuple; zero matches → null; two matches (same gateway_transaction_id, including rows owned by different tenants) → null (fail closed, never guess). Prove the method works with no request tenant bound by entering the repository's existing `system()`/`TenantContextRunner` path, and that it returns the persisted owner rather than filtering to a caller tenant.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** implement — run the bounded SELECT by `(gateway, gateway_transaction_id)` through the repository's existing explicit system-correlation scope; if `count !== 1` return null; else map columns. This is intentionally the narrow tenantless correlation surface, not an interactive tenant-scoped read.
- [ ] **Step 4:** PASS.

### Task 3: Dispute-webhook → `ProviderChargebackEvent` mapping + dispatch (redelivery via existing `provider_events`)

**Files:**
- Modify: `src/Gateways/StripeGateway.php`, `src/Gateways/PaystackGateway.php` (recognize dispute/chargeback + dispute-reversal webhook types, normalize amount/currency/reason/occurred-at/provider-event-id/gateway-txn-id)
- Modify: `src/Events/EventType.php` (closed chargeback/reversal types and their immutable logical-event treatment)
- Create: `src/Events/ProviderChargebackDispatcher.php`, `src/Exceptions/UnresolvedPaymentOwnershipException.php`
- Modify: `src/Services/WebhookService.php`, `src/PayviaServiceProvider.php` (compose the named chargeback dispatcher into the existing durable provider-event dispatch callback; `ConfirmationDispatcher` is payment-confirmation infrastructure and is not part of this path)
- Test: `tests/Integration/Webhooks/DisputeWebhookDispatchTest.php`; extend `tests/Unit/Events/ProviderEventTest.php` as needed for the new event types/logical keys

**Interfaces:**
- Consumes: Task 2 `findPaymentOwnerByGatewayTxn`, the Task-1 event, `WebhookService`'s existing durable `provider_events` + `dispatch_status` redelivery.
- Produces: on a verified dispute webhook, exactly one `ProviderChargebackEvent` (`kind=chargeback`) or reversal (`kind=reversal`, `relatedEventId` = the original dispute's provider event id) dispatched, built from the correlated payment row (`tenant_uuid`, `reference`, `PayableReference(payable_type, payable_id, amount, currency)`) — NEVER from webhook metadata alone.

**Constraints:** signature-verify first (existing path). `WebhookService` remains the sole durable `provider_events` owner. Its existing dispatcher callback first preserves normal local `PaymentProviderEvent` delivery, then delegates recognized dispute types to `ProviderChargebackDispatcher`, which correlates and emits the contracts event through `EventService`. Zero/multiple ownership matches throw `UnresolvedPaymentOwnershipException` after persistence, so no fabricated event is dispatched and the provider row remains retryable. A contracts-listener failure must propagate through the callback, leaving the logical dispatch unmarked; a retry (test with `staleSeconds=0`) redelivers. Commerce is not referenced.

- [ ] **Step 1: failing tests** — Stripe `charge.dispute.created` + Paystack equivalent map to a `chargeback` event with fields from the correlated payment; a dispute-won/reversed webhook maps to `kind=reversal` with `relatedEventId`; unknown/unverified webhook → no event; ownership zero/multiple → no contracts dispatch + durable event stays redispatchable; simulate contracts-listener throw → logical dispatch not marked, then `relayPending(..., staleSeconds: 0)` redelivers and marks it exactly once after success. Assert the ordinary local provider event still dispatches and no commerce class is referenced.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** implement gateway dispute recognition + dispatch wiring.
- [ ] **Step 4:** PASS. Full payvia suite green; phpcs + analyze clean.
- [ ] **Step 5: COMMIT (payvia)** — explicit add of the gateways, `EventType`, named dispatcher/exception, webhook service, provider, and tests (NOT vendor/) → `feat(payvia): dispute-webhook chargeback events with fail-closed payment correlation`. No version bump, no tag.

---

## Package 3 — commerce 1.2.0 (repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce`, branch `dev`, HEAD `7f9a52f`)

> **STEP 0 for the first commerce task that compiles against the event:** mirror the Task-1 file into `commerce/vendor/glueful/extension-contracts/src/Payments/ProviderChargebackEvent.php` (never staged).

### Task 4: Schema — folds + new 015/016 + config + diagnostics + shape tests  *(NO commit — Commerce commit 1 lands after Task 5)*

**Files:**
- Modify: `migrations/010_CreateMarketplaceSellerTables.php` (fold reserve-policy columns), `migrations/012_CreateMarketplaceLedgerTables.php` (fold `reserve_uuid`/`chargeback_uuid` + indexes)
- Create: `migrations/015_CreateSellerReservesTable.php` (`commerce_seller_reserves` + `commerce_reserve_policy_events`), `migrations/016_CreateChargebacksTable.php` (`commerce_chargebacks` + `commerce_chargeback_lines`)
- Modify: `config/commerce.php` (`marketplace.reserves` operational settings only), `src/Support/DiagnosticsReport.php` (+4 tables), `tests/Support/CommerceTestCase.php` (`MIGRATIONS`)
- Create: `tests/Integration/Migrations/ReserveChargebackShapeTest.php`

**Schema (§3, verbatim):**
- `010` fold — `commerce_marketplace_settings`: `reserve_bps` INT NOT NULL DEFAULT 0, `reserve_days` INT NOT NULL DEFAULT 0. `commerce_sellers`: `reserve_bps` INT NULL, `reserve_days` INT NULL.
- `012` fold — nullable `reserve_uuid` varchar(12), `chargeback_uuid` varchar(12) + supporting indexes; extend `LedgerRepository`'s insert/phpdoc and `VERIFIED_FIELDS` allowlists to include both, expanding replay verification from 12 to **14 immutable fields** (do the allowlist edit HERE so later tasks can post them safely).
- `015` — `commerce_seller_reserves` per §3.2 (id, uuid, tenant_uuid `''`, seller_uuid, currency, source_kind `rolling|manual`, seller_order_uuid NULL, idempotency_key NULL, amount>0, reserve_bps_snapshot, reserve_days_snapshot, status `held|released|consumed` default held, held_at, release_at NULL, closed_at NULL, created_by NULL, reason NULL, timestamps; uniques `(tenant,seller_order_uuid,seller_uuid)`, `(tenant,idempotency_key)` for manual replay, `(tenant,uuid)`; indexes `(tenant,status,release_at)`,`(tenant,seller_uuid,currency,status,release_at)`). Plus `commerce_reserve_policy_events` per §3.2 (id, uuid, tenant `''`, subject_kind `workspace|seller`, subject_uuid, actor_uuid, before_policy JSON, after_policy JSON, created_at; unique `(tenant,uuid)` + subject/time index).
- `016` — create the complete `commerce_chargebacks` and `commerce_chargeback_lines` schemas listed in spec §3.3. Pin the closed status vocabulary `received|awaiting_attribution|posted|integrity_hold`, kind vocabulary `chargeback|reversal`, nullable `related_chargeback_uuid`, event unique `(tenant,provider,provider_event_id)`, line uniques `(tenant,chargeback_uuid,order_line_uuid)` and `(tenant,uuid)`, and the order/status/seller indexes from the spec.
- `config/commerce.php` `marketplace.reserves`: operational release-sweep batch size only, following the existing `marketplace.payouts` env idiom. **Do not add config policy defaults**: `commerce_marketplace_settings.reserve_bps/reserve_days` is the authoritative workspace policy source and defaults to `0`.

- [ ] **Step 1: RED** `ReserveChargebackShapeTest` — all folded columns + defaults; `015`/`016` tables/uniques/indexes via driver introspection; nullable manual `idempotency_key` uniqueness; `reserve_uuid`/`chargeback_uuid` present + nullable on the ledger; DiagnosticsReport and tenant adoption list all **four** new tables; re-run migrations no-op.
- [ ] **Step 2:** FAIL. — [ ] **Step 3:** implement folds + migrations + config + diagnostics + MIGRATIONS. — [ ] **Step 4:** GREEN; full `composer test`; phpcs + analyze. **NO commit.**

### Task 5: Balance `debt` component  *(Commerce commit 1)*

**Files:** modify `src/Marketplace/LedgerRepository.php` (`balanceComponents` +`debt`), `src/Marketplace/SellerBalanceService.php` (+`debt` in both `balance()` docblocks/returns); extend `tests/Integration/Marketplace/BalanceTest.php`.

**Interfaces:** `balanceComponents`/`balance()` return gains `debt: int` = `max(0, −available)`. `available`/`pending`/`reserved`/`paid_out` unchanged; derived in the SAME grouped scan (no extra round-trip).

- [ ] **Step 1: RED** — negative available ⇒ `debt = −available`, `available` still negative (not clamped); non-negative available ⇒ `debt = 0`; existing keys/values unchanged (additive only). — [ ] **Step 2:** FAIL. — [ ] **Step 3:** implement. — [ ] **Step 4:** GREEN; gates.
- [ ] **Step 5: COMMIT (Commerce 1)** — explicit add of migrations 010/012/015/016, config, DiagnosticsReport, CommerceTestCase, LedgerRepository, SellerBalanceService, the two tests → `feat(marketplace): mv5a reserve/chargeback schema and debt balance`.

### Task 6: Reserve policy service + durable audit  *(NO commit — Commerce commit 2 lands after Task 8)*

**Files:** create `src/Marketplace/ReservePolicyService.php`, `src/Marketplace/ReservePolicyEventRepository.php`; modify `src/CommerceServiceProvider.php` (register); test `tests/Integration/Marketplace/ReservePolicyTest.php`.

**Interfaces (§2.1):**
- `resolve(c, tenant, sellerUuid): array{reserve_bps:int, reserve_days:int}` — per-seller override (null inherits workspace; explicit `0` disables, does NOT inherit) falling back to workspace default.
- `setWorkspace(c, tenant, int $bps, int $days, ?string $actor)` / `setSeller(c, tenant, sellerUuid, ?int $bps, ?int $days, ?string $actor)` — validate (bps 0..10000, days ≥ 0), write the column(s) AND append a `commerce_reserve_policy_events` row (before/after JSON) in the SAME transaction (mirror MV3 `CommissionPolicyService`); actor required (422 if blank).

- [ ] **Step 1: RED** — resolution precedence (seller override vs inherit vs explicit-0-disable); workspace + seller set each write an audit row atomically (forced-uuid-collision proves atomicity, MV3 idiom); invalid bps/days → 422; blank actor → 422. — [ ] **Step 2:** FAIL. — [ ] **Step 3:** implement. — [ ] **Step 4:** GREEN; gates. **NO commit.**

### Task 7: Reserve hold at settlement  *(NO commit)*

**Files:** create `src/Marketplace/ReserveRepository.php`, `src/Marketplace/ReserveService.php`; modify `src/Marketplace/LedgerPostingService.php` (`postSale` — post the reserve hold alongside sale_credit/commission_debit); test `tests/Integration/Marketplace/ReserveHoldTest.php`.

**Interfaces (§2.2):**
- `ReserveService::holdForSettlement(c, tenant, array $sellerOrder)` called from `postSale` under the SAME seller/currency lock/transaction: resolve policy (Task 6); if `0` bps or `0` days → no-op; compute `merchandise_after_discount = subtotal − allocated_discount` with the guardrail assert `=== attributed_total − allocated_shipping − allocated_tax` (integrity throw on mismatch); `reserve_base = max(0, merchandise_after_discount − commission_amount)`; `reserve_amount = floor(reserve_base × reserve_bps / 10000)`; if `0` → no-op. Use the seller-order's persisted non-null `confirmed_at` (stamped immediately before `postSale()` in `markPaid()`) as both `held_at` and the base for `release_at = confirmed_at + reserve_days`; never use a fresh wall-clock value. Insert a `commerce_seller_reserves` row (`source_kind=rolling`, `seller_order_uuid`, snapshot bps/days) and post `reserve_hold = −reserve_amount` (`payout_uuid=NULL`, `reserve_uuid`=the row uuid), idempotency key `{order_uuid}:{seller_uuid}:reserve_hold`.
- Produces: `reserve_uuid` correlation used by Tasks 8/9/14.

- [ ] **Step 1: RED** — base math on a shipping+tax+discount order equals both formulas; reserve_hold + row created with snapshot + release_at derived from persisted `confirmed_at`; `reserved` reflects the hold; 0-bps/0-days settle with NO hold; guardrail mismatch throws. Settlement replay must find one hold and verify its seller/order/currency/amount/policy snapshots/held_at/release_at plus the ledger's 14-field identity exactly; changing policy or advancing the clock before replay must not recalculate the historical hold, and a conflicting row must throw. — [ ] Steps 2-4: FAIL→implement→GREEN; gates. **NO commit.**

### Task 8: Reserve release sweep  *(Commerce commit 2)*

**Files:** create `src/Console/ReservesReleaseSweepCommand.php`; add release logic to `ReserveService`/`ReserveRepository`; register command in `src/CommerceServiceProvider.php`; test `tests/Integration/Marketplace/ReserveReleaseSweepTest.php`.

**Interfaces (§2.3):** `commerce:marketplace:reserves:release-sweep` selects `status=held AND release_at IS NOT NULL AND release_at ≤ now`; per row under the seller/currency lock, derive `remaining = max(0, −Σ(reserve_hold+reserve_release) WHERE reserve_uuid=this)`, post `reserve_release = +remaining` (`payout_uuid=NULL`, `reserve_uuid`) if remaining>0, mark row `status=released, closed_at=now`. Idempotency key `{reserve_uuid}:scheduled_release`. Independent per row.

- [ ] **Step 1: RED** — due hold releases full remaining → back to available, out of reserved; a partially-consumed reserve releases only its remainder; not-yet-due skipped; NULL release_at (manual) never swept; replay no-op; one failure doesn't abort. — [ ] Steps 2-4. 
- [ ] **Step 5: COMMIT (Commerce 2)** — explicit add of Tasks 6-8 files → `feat(marketplace): rolling reserve policy, settlement holds, and release sweep`.

### Task 9: Shared FIFO `ReserveConsumptionService`  *(NO commit — Commerce commit 3 lands after Task 13)*

**Files:** create `src/Marketplace/ReserveConsumptionService.php`; test `tests/Integration/Marketplace/ReserveConsumptionTest.php`.

**Interfaces (§2.5):** `consume(c, tenant, sellerUuid, currency, int $liability, string $liabilityKind, string $liabilityUuid): int` — MUST be called under an already-held seller/currency lock. Consumes `min($liability, current_reserved)` from `status=held` reserves **earliest-`release_at`-first (NULL release_at / manual sorts LAST)**. Per affected reserve, re-read its locked remaining, post `reserve_release = +consumed_slice` (`payout_uuid=NULL`, `reserve_uuid`, plus `chargeback_uuid`/`refund_uuid` via the correlation column matching `$liabilityKind`), key `{liabilityKind}:{liabilityUuid}:{sellerUuid}:{reserveUuid}:reserve_release`. A reserve stays `held` while it has positive remainder; becomes `status=consumed` only when exhausted. Returns total consumed. `reserved ≥ 0` invariant — never release beyond a reserve's locked remaining.

- [ ] **Step 1: RED** — liability < first reserve: partial-consumes one, it stays held with correct remainder; liability spanning 3 reserves consumes FIFO by release_at, exhausted ones → consumed; manual (NULL release_at) consumed last; liability > total reserved consumes all, returns total reserved (caller handles the shortfall); replay idempotent (per-reserve keys); never over-releases. — [ ] Steps 2-4. **NO commit.**

### Task 10: Chargeback ingestion (event-first, idempotent, validated)  *(NO commit)*

**Files:** create `src/Marketplace/ChargebackRepository.php`, `src/Marketplace/ChargebackService.php`, `src/Marketplace/ChargebackIntegrityException.php`; test `tests/Integration/Marketplace/ChargebackIngestTest.php`.

**Interfaces (§2.4):** `ChargebackService::ingest(c, ProviderChargebackEvent $event): array` — resolve tenant from the event; validate Commerce supports the payable type (order), the payable id resolves to an order in that tenant, currency/amount bounds coherent, and provider/payment correlation is sane (else `integrity_hold`). Require the order's immutable `marketplace_partitioned` marker; current workspace activation is irrelevant, and a non-partitioned order returns an explicit ignored/no-op result without inserting a marketplace chargeback row. Insert `commerce_chargebacks` FIRST inside the processing txn for an eligible partitioned order; the unique `(tenant,provider,provider_event_id)` is the idempotency claim. On conflict, re-read and verify provider/payment reference, resolved order/payable identity, amount, currency, reason, occurred-at, kind, and resolved related-chargeback identity exactly; mismatch → `ChargebackIntegrityException`, never silent skip. The payable tuple is revalidated against the immutable historical order when it is not duplicated as columns. Attribution+posting is Task 11; ingest sets the initial status: fully-attributable full chargeback → hand to Task 11 for `posted` in the same txn; partial without lines → commit `awaiting_attribution`; unresolvable → `integrity_hold`.

- [ ] **Step 1: RED** — new event persists received/awaiting/integrity per resolvability; duplicate provider_event_id with identical payload = one row, no-op; duplicate with CONFLICTING payload → integrity exception; unsupported payable type / unknown order / currency mismatch → integrity_hold; non-partitioned order → explicit ignored/no row; a partitioned order still processes after workspace deactivation; `''` tenant single-store accepted when ownership validates. — [ ] Steps 2-4. **NO commit.**

### Task 11: Chargeback attribution + ledger reversal + reserve-first + negative balance (MONEY CORE)  *(NO commit)*

**Files:** extend `src/Marketplace/ChargebackService.php` (+ attribution/posting), `ChargebackRepository` (lines); test `tests/Integration/Marketplace/ChargebackReversalTest.php`.

**Interfaces (§2.5/§2.6):**
- Attribution: partial REQUIRES persisted `commerce_chargeback_lines` `(order_line_uuid, amount)` summing to the chargeback amount (operator supplies via a surface in Task 16; the service validates + posts). Full chargeback auto-expands each immutable seller order's `attributed_total` to its lines with `LargestRemainder::distribute`: weight `max(0, line_total − discount_amount + tax_amount)`, line-UUID tie-break, equal unit weights if every weight is zero. Persist the generated lines and hard-assert per-seller sums equal `attributed_total` and the whole sum equals the chargeback amount. No attribution may exceed the derived original line/seller remaining after earlier chargebacks/refunds.
- Per attributed seller, under the seller/currency lock: post `chargeback_debit = −(seller-attributed amount incl. attributable shipping/tax)` (key `{chargeback_uuid}:{seller_uuid}:chargeback_debit`) and `commission_reversal = +(seller commission share, capped to the existing refund-style cumulative merchandise basis)` (key `{chargeback_uuid}:{seller_uuid}:commission_reversal`). Net liability = debit − commission reversal.
- Reserve-first: call `ReserveConsumptionService::consume(..., 'chargeback', chargeback_uuid)` for the net liability BEFORE the debit lands; then post the debit in FULL, allowing `available` to go negative (§2.6 — never truncate).
- Unattributable remainder (no seller line, or exceeds allocations) → marketplace-funded, explicit posting, never silently a seller's.
- The whole insertion→attribution→posting→`posted` transition is ONE transaction when attribution is available.

- [ ] **Step 1: RED** — full chargeback auto-expands + persists lines + reverses each seller proportionally (debit incl shipping/tax, commission reversal merchandise-capped); allocation is integer-exact/stable by line UUID and the all-zero-weight seller case remains exact; partial with persisted lines posts; partial without lines stays awaiting (no posting); over-attribution rejected; reserve consumed first then available goes negative (debt appears); unattributable → marketplace; atomic (a forced failure mid-posting commits no chargeback row, lines, or ledger entries, so provider redelivery retries the event from its durable Payvia source). — [ ] Steps 2-4. **NO commit.**

### Task 12: Refund reserve-first + debt semantics  *(NO commit)*

**Files:** modify `src/Orders/Refunds/RefundService.php` / `src/Marketplace/LedgerPostingService.php::postRefund` to call `ReserveConsumptionService::consume(..., 'refund', refund_uuid)` before the refund debit and allow `available` negative; test `tests/Integration/Marketplace/RefundReserveFirstTest.php`.

**Interfaces (§2.5):** the existing marketplace refund posting claims its complete sorted seller/marketplace account-lock set exactly once, then calls the shared reserve consumer under each already-held seller/currency lock for `max(0, seller refund_debit − seller commission_reversal)` **before** that seller's postings; do not make `ReserveConsumptionService` claim locks recursively or call it before `postRefund()` owns the full sorted set. Reserve releases carry `refund_uuid`; the full refund debit and commission reversal still post unchanged, and the net result may drive `available` negative (debt). Manual + gateway refund paths both flow through `applyCompletion()` → `postRefund()` in the same transaction. No change to non-marketplace refunds.

- [ ] **Step 1: RED** — a marketplace refund with an existing reserve consumes exactly the net seller liability (debit minus commission reversal) first; reserved drops and available is cushioned without over-release; a refund exceeding reserve+available drives debt; existing refund JSON/behavior otherwise unchanged; non-marketplace refund untouched (byte-identical). — [ ] Steps 2-4. **NO commit.**

### Task 13: Payout debt gate  *(Commerce commit 3)*

**Files:** modify `src/Marketplace/PayoutService.php` (reserve gate) + the batch candidate logic; test `tests/Integration/Marketplace/PayoutDebtGateTest.php`.

**Interfaces (§2.7):** provider + manual payout creation requires `debt == 0` **AND the existing locked `available >= requested amount` check** (the positive amount already implies `available > 0`); this adds the debt gate, it does not replace the MV4 capacity guard. A payout may not be created in debt and never draws available below 0 — refuse 422. `payouts:run-batch` skips sellers with `debt > 0` or locked `available ≤ 0`. `reserved` stays non-payout-eligible.

- [ ] **Step 1: RED** — indebted seller: manual + provider payout refused 422; batch skips them; a solvent seller unaffected; MV4 payout tests still green (the gate is additive). — [ ] Steps 2-4.
- [ ] **Step 5: COMMIT (Commerce 3)** — explicit add of Tasks 9-13 files → `feat(marketplace): chargeback ingestion, reserve-first consumption, and negative-balance debt`.

### Task 14: Compensating chargeback reversal + reserve reinstatement  *(NO commit — Commerce commit 4 lands after Task 16)*

**Files:** extend `ChargebackService` (reversal branch), `ReserveService`/`ReserveConsumptionService` (reinstate); test `tests/Integration/Marketplace/ChargebackReversalCompensationTest.php`.

**Interfaces (§2.10):** a `kind=reversal` event has its own provider event ID and carries `relatedEventId` (the original **provider** event ID). Resolve that relation under `(tenant, provider, provider_event_id)` and persist the resulting internal `related_chargeback_uuid`; an unknown/cross-provider relation is an integrity hold, never a guessed UUID. The reversal posts a dedicated `chargeback_credit` + matching `commission_debit` re-application — NEVER mutates the original rows. Cumulative compensation may not exceed the original chargeback's postings (over-amount = integrity finding, no fabricated post). If the original consumed a rolling reserve whose `release_at` is still future, post an idempotent `reserve_hold` against that SAME `reserve_uuid` for the previously-consumed amount (capped so derived remaining never exceeds the reserve's original `amount`) and reopen the row to `held`; if the window elapsed, re-hold nothing.

- [ ] **Step 1: RED** — reversal credits the seller + re-applies commission, original rows untouched; over-amount reversal = integrity finding (no post); reserve re-held when window unexpired (row reopens, reserved restored, capped at original), NOT re-held when elapsed; duplicate reversal event idempotent. — [ ] Steps 2-4. **NO commit.**

### Task 15: Manual reserve hold/release + debt forgiveness  *(NO commit)*

**Files:** extend `src/Marketplace/ReserveService.php` (manual hold/release methods) + reuse `src/Marketplace/AdjustmentService.php` (forgiveness credit); test `tests/Integration/Marketplace/ManualReserveTest.php`. *(HTTP surface for all of this is Task 16 — this task is service-layer + tests only, driven through the services directly.)*

**Interfaces (§2.8):** `ReserveService::manualHold(c, tenant, sellerUuid, currency, int $amount, string $idempotencyKey, string $actor, string $reason): array` requires non-empty key (max 128), actor, and reason; creates a `commerce_seller_reserves` row `source_kind=manual`, `seller_order_uuid=NULL`, policy snapshots `0/0`, no `release_at`, `idempotency_key`, `created_by`+`reason`, ledger `reserve_hold` with `reserve_uuid` — never an untracked raw post. Unique `(tenant,idempotency_key)` is the reserve-row claim: exact replay returns the existing row after full identity verification; conflicting reuse throws 409. `ReserveService::manualRelease(c, tenant, string $reserveUuid, string $actor): int` requires the actor, names a reserve, and uses the same locked remaining-amount logic (key `manual:{reserve_uuid}:release`, `created_by` set). Debt forgiveness = an explicit audited positive `adjustment` credit via `AdjustmentService` with its required caller idempotency key, actor, and reason (never mutation/deletion of chargeback rows). All operator-initiated (gated at the Task-16 routes with `commerce:write`).

- [ ] **Step 1: RED** — manual hold creates tracked row + ledger reserve, shows in reserved, never auto-releases (sweep skips it); blank actor/reason/key rejected; identical idempotency replay returns the same reserve, conflicting reuse is 409, and a simulated duplicate request cannot double-hold; manual release derives remaining and records actor; forgiveness requires/replays its adjustment idempotency key and lifts debt (chargeback rows untouched, immutable). — [ ] Steps 2-4. **NO commit.**

### Task 16: Chargeback listener + operator/seller surfaces  *(Commerce commit 4)*

**Files:** create `src/Events/Listeners/ProviderChargebackListener.php` (invokes `ChargebackService::ingest`); modify `src/CommerceServiceProvider.php` to register the listener service and add it directly to `EventService` during boot (the extension has no `config/events.php`; mirror the existing `OrderMailListener` registration block); create `src/Http/Admin/AdminReserveController.php` (+ DTOs) owning the FULL operator surface — set workspace/seller reserve policy (Task 6), ingest chargeback (Task 10 service), supply attribution lines for a partial (Task 11), manual hold/release (Task 15), debt-forgiveness adjustment (Task 15), read a seller's reserves+debt; extend `src/Http/Seller/SellerFinancialController.php` (own reserved + upcoming releases + debt, SANITIZED allow-list); modify `routes.php`; test `tests/Integration/Marketplace/ReserveChargebackSurfaceTest.php`.

**Interfaces (§6):** operator (`commerce:write`, marketplace-enabled): set workspace/seller reserve policy; ingest chargeback (system/operator, same service the listener uses); supply attribution lines for a partial; manual hold/release; debt-forgiveness adjustment; read a seller's reserves+debt. Manual hold and forgiveness require the HTTP `Idempotency-Key` header. Every route derives the tenant from the authenticated admin profile and binds seller/order targets to it; body fields may not select another tenant. Seller (`commerce_seller:commerce.seller.reports.read`, own only): read own `reserved` + upcoming releases (`amount`+`release_at` only) + `debt`, **allow-list projection** (NO provider event internals, NO `payment_reference`/`provider_event_id`/`reason` leak). NO operator "reverse a chargeback" route. The listener maps a dispatched contract event to `ingest` and must not import payvia.

- [ ] **Step 1: RED** — listener ingests a dispatched event end-to-end (fake dispatch); operator routes over real `commerce:write`; cross-tenant body/target attempts cannot escape the resolved tenant; manual-hold/forgiveness endpoints reject a missing idempotency key and replay exact requests safely; attribution endpoint posts a partial; seller reads own reserved/debt SANITIZED; poison-string test proves provider_event_id/payment_reference/reason never leak to the seller; cross-seller 404; no reverse route exists. — [ ] Steps 2-4.
- [ ] **Step 5: COMMIT (Commerce 4)** — explicit add of Tasks 14-16 files → `feat(marketplace): chargeback reversals, manual reserves, and reserve/debt surfaces`.

### Task 17: Gates — regression, live pgsql races, CHANGELOG  *(Commerce commit 5)*

**Files:** extend `tests/Integration/Marketplace/MarketplaceRegressionTest.php`; create `tests/Integration/Marketplace/ReserveChargebackPgsqlTest.php` (+ fixture child, mirror `SettlementPgsqlTest`/`PayoutSagaPgsqlTest`); modify `tests/Integration/Http/HttpDocumentationTest.php` (new routes); `CHANGELOG.md` `[Unreleased]`.

- [ ] **Regression:** reserve policy `0`/marketplace-off ⇒ zero holds/chargebacks, route manifest identical, manual+refund+payout JSON byte-identical, zero new queries on unrelated paths, folded defaults keep existing sellers reserve-free; all four new tenant tables adopt/scope correctly.
- [ ] **Live pgsql (`COMMERCE_TEST_DB_DRIVER=pgsql`, run live, paste verbatim):** chargeback vs payout reservation under the shared seller/currency lock (no double-spend, no over-release, debt/available consistent); concurrent reserve-release sweep vs chargeback consumption of the SAME hold (one wins, no double-release); migration shape (folds + 015/016 + ledger correlations) live via `pg_indexes`, re-run no-op.
- [ ] **CHANGELOG `[Unreleased]`:** MV5a — rolling reserves (policy/holds/release sweep), provider-reported chargebacks (event-first, attribution, reversal, reserve-first consumption), negative-balance debt + payout freeze, compensating reversals + reserve reinstatement, manual reserves + debt forgiveness; contract-only coupling; default-off unchanged. Note it ships in **the same bundled train as MV4** (contracts 1.5.0 → payvia 2.1.0 → commerce 1.2.0); migrations `010`–`016` first publish together in Commerce 1.2.0. No version-header/pin bump.
- [ ] **COMMIT (Commerce 5)** — `feat(marketplace): mv5a risk gates, races, and regression proof`. Then whole-branch review of the MV5a commerce range.

---

## Self-Review notes
- **Spec coverage:** §2.1→T6; §2.2→T7; §2.3→T8; §2.4→T10; §2.5→T9+T11+T12; §2.6→T11+T5; §2.7→T13; §2.8→T15; §2.9→T5; §2.10→T14; §2.11→T16+all; §3→T4; §4→T1; §5→T2+T3+release; §6→T16; §7→T17; §8→per-task+T17.
- **Invariant-bearing subtleties for reviewers:** (a) reserve base uses the merchandise formula with the equivalence guard, NOT `attributed_total` (T7); (b) `payout_uuid` NULL + `reserve_uuid`/`chargeback_uuid` correlation on every risk/chargeback entry (T4 allowlist, all posters); (c) FIFO partial consumption keeps a reserve `held` with a derived remainder — never a mutable scalar (T9); (d) chargeback is event-first + exact-payload conflict verify + atomic-when-attributable (T10/T11); (e) seller debit includes attributable shipping/tax while commission reversal is merchandise-capped (T11); (f) debt is derived `max(0,−available)`, no separate balance; payout freeze on debt (T5/T13); (g) reversal is a separate compensating event with reserve reinstatement capped to the original, never mutation (T14); (h) contract-only — commerce never imports payvia; the listener bridges the dispatched event (T16); (i) Payvia correlation fails closed on 0/multiple matches and the durable provider event redelivers on listener failure (T2/T3).
- **Release-order gate:** contracts (T1) → payvia (T2-T3) → commerce (T4-T17); vendor-mirror the event locally in payvia and commerce (never staged); pin bumps + tags are the USER's release step. All three stay version-unbumped on dev.
