# Commerce Marketplace MV4 — Payouts & Provider Integration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to
> implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> **THREE repos, release order:** `glueful/extension-contracts` → `glueful/payvia` → `glueful/commerce`.
> Each task names its repo. Commit **per repo, on its own dev branch**.

**Goal:** Provider-backed seller payouts via a provider-neutral port (extension-contracts), a first
Payvia implementation, and a commerce reserve → execute → finalize saga — with in-flight `reserve_hold`
balance protection, per-attempt-keyed retries, provider status reconciliation, provider-reported
reversals, scheduled batches (CLI) + single-seller operator payout (HTTP), and provider-sourced
destination readiness. Manual payouts and ledger semantics keep working with no provider bound.

**Architecture:** `PayoutCollector` (transfer + status + inspectDestination) in contracts; Payvia
`TransferCapableGateway` + `PayviaPayoutCollector` with a durable pre-I/O `payvia_transfers` attempt
row; commerce `PayoutService` reserve/execute/finalize mirroring `RefundService`'s gateway saga, with
`reserve_hold`/`reserve_release` (disambiguated by `payout_uuid`) and `payout_debit`/`payout_reversal`.

**Tech Stack:** PHP 8.3, Glueful framework, PHPUnit 10, SQLite (suite) + PostgreSQL (saga races).

**Spec:** `docs/superpowers/specs/marketplace/2026-07-18-mv4-payouts-provider-design.md` (authoritative;
every §-reference points into it). §10 seams are already source-verified.

## Global Constraints

*(Every task's requirements implicitly include this. Exact values from the spec.)*

- **RELEASE ORDER + VENDOR-FIRST DEV.** Implement contracts first, then payvia, then commerce. Contracts
  1.5.0 is NOT published during execution, so payvia/commerce dev must compile against the new port by
  **mirroring the new contract files into each repo's vendored `vendor/glueful/extension-contracts/
  src/Payments/`** (vendor edit), so tests run. **Do NOT bump the `composer.json` `extension-contracts`
  pin to `^1.5.0` during dev** — the pin bump + publish is a RELEASE-time step the USER performs (per
  "release before pinning dependents"). The mirrored vendor files are a local compile aid only: never
  stage them in Payvia/Commerce commits, and audit each package diff before its commit. Commit source
  changes on each repo's dev branch; leave version pins and `extra.glueful.version` bumps as
  HELD/release-time edits.
- **NO GIT TAGS / NO PUBLISH.** The user creates all tags and publishes. No AI/Anthropic attribution in
  any commit.
- **CONTRACT-ONLY COUPLING (§2.9).** Commerce depends on the contract only; `PayoutCollector` is
  soft-bound (`container->has()`, like `RefundCollector`). No commerce→payvia composer dep; commerce
  never reads/names `payvia_transfers`. With no provider bound, provider payouts are unavailable and
  manual payouts + ledger semantics keep working.
- **PROVIDER I/O OUTSIDE TRANSACTIONS (§2.3).** reserve commits before execute; execute (transfer/
  status/inspect) runs outside any DB transaction; finalize does no I/O. Mirrors `RefundService`.
- **PER-ATTEMPT IDEMPOTENCY (§2.5).** Attempt `n` uses `{payoutUuid}:attempt:{n}` for its transfer AND
  every status call about it. A NEW key (`n+1`) only after a CONFIRMED `RETRYABLE_FAILURE`. NEVER a new
  attempt while attempt `n` is `UNKNOWN`/`PENDING`. Payvia persists a stable provider-safe reference
  mapping (Paystack: lowercase 16–50 chars, `[a-z0-9_-]`; never send the colon key).
- **HOLD LIFETIME (§2.3).** `reserve_hold` (with `payout_uuid`) persists until TERMINAL (PAID ⇒
  `reserve_release`+`payout_debit`; TERMINAL_FAILURE / final-attempt exhaustion ⇒ `reserve_release`).
  Non-terminal (PENDING, UNKNOWN, RETRYABLE_FAILURE with `retryable=true`) keeps the hold. No query may
  assume a `failed` row released its hold without `retryable=false`.
- **TRANSITION IDEMPOTENCY (§2.3).** One payout has exactly one `{payout}:reserve_hold`, one
  `{payout}:reserve_release`, one `{payout}:payout_debit` identity (reconciliation replays verify, not
  duplicate). Reversal entries key on the cumulative target `{payout}:payout_reversal:{reversed_total_after}`
  and post only the positive delta from the persisted `reversed_total`.
- **BALANCES (§2.4).** `pending = −(Σ reserve_hold+reserve_release WHERE payout_uuid IS NOT NULL)`;
  `reserved = −(… WHERE payout_uuid IS NULL)`; `available = SUM(all)`; `paid_out = −(payout_debit +
  payout_reversal)`. `balanceComponents` extends its single grouped scan with conditional aggregation
  (no new per-component queries).
- **RESERVE ORDER (§2.3).** account lock → re-read available under lock → require `available ≥ amount` →
  insert payout row with attempt 1 timing + an initial reconciliation watchdog → post `reserve_hold` —
  all one transaction. Reserve copies `destination_ref` onto the payout (later account changes never
  retarget an in-flight payout). The pre-I/O watchdog recovers a process death after transfer but before
  finalize.
- **RETRY CAS (§2.6).** `PayoutRepository::claimRetryableForAttempt()` = guarded `failed+retryable+due`
  → `pending` CAS that increments `attempt_count`, clears `next_attempt_at`, stamps `last_attempt_at`,
  and sets a `next_reconcile_at` watchdog BEFORE any I/O; never exceeds `max_attempts`; a crash after
  the claim recovers via reconciliation, never a blind retry. Operator retry uses the same CAS
  (may ignore due time).
- **RECONCILE (§2.6/§2.8).** Reconcile sweep covers unresolved `pending` AND due `paid` (slower cadence,
  for provider-reported reversals). `PayoutCollector::status(attemptKey(current))` → closed state-valid
  map. Partial cumulative `reversedAmount` > persisted `reversed_total` ⇒ post only the delta as
  `payout_reversal` in the same txn as the payout update, `status` stays `paid`; `reversed_total ===
  amount` ⇒ `status=reversed`. A paid payout regressing to PENDING/RETRYABLE/TERMINAL, or an over-amount
  observation, is an **integrity finding** — never releases or reposts money.
- **BATCH (§2.6).** `run-batch` uses an UNLOCKED availability read as a candidate HINT only; the shared
  reserve path then locks the (seller,currency) account, re-reads available, and derives the amount from
  that LOCKED value (full available, optionally config-capped), skipping if below the per-currency
  minimum. Concurrent workers serialize via the lock; candidates processed independently (one failure
  never aborts the batch). Host cron invokes it — no commerce-owned scheduler.
- **PAYSTACK OTP.** An `otp` transfer is unresolved, not a terminal decline: map it to `PENDING` with
  stable `action_required`, retain the hold, and reconcile after the operator resolves/cancels it at
  Paystack. MV4 has no OTP-entry UI. Never release a hold merely because provider interaction is needed.
- **READINESS (§2.7).** Reserve refuses (422) unless a `ready` account exists for the provider, before
  any hold. Readiness is provider-sourced via `inspectDestination` with DNS-style semantics: snapshot
  `(uuid, provider, account_ref)` → call outside any txn → guarded apply only if provider/account_ref
  still match (a concurrent replacement makes the stale inspection a no-op). Never operator-asserted.
- **REVERSAL SCOPE (§2.8).** Provider-reported only; no operator reverse/clawback command in MV4.
- **SELLER PROJECTION (§6).** Seller payout view exposes status/provider_ref/sanitized failure
  code+message while preserving the existing MV3 manual fields (`amount`, `currency`, `external_ref`,
  `note`, `created_by`, timestamps). Provider rows may have null `external_ref`/`created_by`; raw
  `failure_reason`, idempotency keys, and destination refs remain excluded. Payout-account readiness
  read returns provider/state/last-synced only, never `account_ref`.
- **MIGRATIONS (§3).** Commerce: FOLD provider columns into unreleased `013`; new `014` payout accounts.
  Payvia: new `008` `payvia_transfers`. All new tables `tenant_uuid default('')` (adopt sentinel);
  index assertions via driver introspection (no `hasIndex`).
- **HOUSE STYLE.** `use` imports; `UtcNowSql`; append optional nullable collaborators (never subclass
  final services); DTO/Response idioms; phpcs + PHPStan clean per each repo's config.

---

## PACKAGE 1 — `glueful/extension-contracts` → 1.5.0 (release FIRST)

Repo: `/Users/michaeltawiahsowah/Sites/glueful/extensions/contracts`

### Task 1: Payout port + value objects — **CONTRACTS COMMIT**
**Files (contracts repo):** create `src/Payments/PayoutCollector.php`, `PayoutDestination.php`,
`PayoutRequest.php`, `PayoutResult.php`, `PayoutStatusResult.php`, `DestinationStatus.php`; create
`tests/Unit/Payments/{PayoutResultTest,PayoutStatusResultTest,PayoutDestinationTest,PayoutRequestTest,
DestinationStatusTest}.php`. The `composer.json`/manifest `extra.glueful.version` repair from `1.3.0`
to `1.5.0` is prepared HELD but belongs to the USER's separate release commit, not this feature commit.

**Interfaces (payvia + commerce consume EXACTLY):**
- `interface PayoutCollector`:
  - `transfer(ApplicationContext $c, PayoutDestination $dest, PayoutRequest $req): PayoutResult`
  - `status(ApplicationContext $c, PayoutDestination $dest, string $idempotencyKey): PayoutStatusResult`
  - `inspectDestination(ApplicationContext $c, PayoutDestination $dest): DestinationStatus`
- `final PayoutDestination(string $provider, string $accountRef, array $metadata = [])`.
- `final PayoutRequest(int $amount, string $currency, string $idempotencyKey, ?string $reason = null)`.
- `final PayoutResult`: consts `PAID|PENDING|RETRYABLE_FAILURE|TERMINAL_FAILURE|UNKNOWN`;
  `(string $status, ?string $providerRef = null, ?string $failureCode = null, ?string $failureReason = null)`.
- `final PayoutStatusResult`: consts `PAID|PENDING|RETRYABLE_FAILURE|TERMINAL_FAILURE|UNKNOWN|REVERSED`;
  `(string $status, int $reversedAmount = 0, ?string $providerRef = null, ?string $failureCode = null,
  ?string $failureReason = null)`. Constructor invariants: `reversedAmount ≥ 0`; `status=REVERSED` ⇒
  `reversedAmount` must equal the caller-provided request amount context (validate `reversedAmount > 0`
  for REVERSED at construction; the "= amount" equality is asserted by the consumer against the payout).
  A positive `reversedAmount` with `status=PAID` is a valid partial reversal.
- `final DestinationStatus`: readiness `(string $state /* pending|ready|restricted */, ?string
  $failureCode = null)`.
- Docblocks pin: `transfer`/`status` idempotent per `idempotencyKey`; a THROW = infra/unknown (consumer
  reconciles, never blind-retries); a returned result is a classified outcome; `UNKNOWN` is explicitly
  ambiguous.

- [ ] TDD: VO construction + invariant matrix (reversedAmount bounds; REVERSED requires positive
  reversedAmount; PAID+partial-reversal valid; unknown status string rejected; enum consts present);
  interface exists and method signatures compile against `glueful/framework`. → implement → GREEN
  (`composer test` in the contracts repo). phpcs/analyze per contracts config.
- [ ] **COMMIT (contracts):** `feat(payments): payout collector port with transfer, status, and inspect`
  (a separate release commit `Release 1.5.0 — payout collector` is the USER's step — do NOT create it).

---

## PACKAGE 2 — `glueful/payvia` → 2.1.0 (release SECOND; depends on contracts 1.5.0)

Repo: `/Users/michaeltawiahsowah/Sites/glueful/extensions/payvia`
**Before starting:** mirror the Task-1 contract files into payvia's `vendor/glueful/extension-contracts/
src/Payments/` so payvia compiles against the new port (vendor edit; the pin bump is release-time).

### Task 2: Transfer capability seam + `payvia_transfers` migration
**Files (payvia):** create `src/Gateways/TransferCapableGateway.php` (interface); modify
`src/GatewayManager.php` (add `'payout'` match arm to `supports()` + a typed `payoutGateway(string
$name): TransferCapableGateway` accessor); create `migrations/008_CreatePayviaTransfersTable.php`;
modify `tests/Support` + create `tests/Integration/PayviaTransfersShapeTest.php`. **NO commit until Task 4.**

**Interfaces:**
- `interface TransferCapableGateway`: `transfer(PayoutDestination $dest, PayoutRequest $req, string
  $providerSafeRef): array` (raw provider response); `transferStatus(string $providerSafeRef, ?string
  $providerRef): array`; `inspectAccount(string $accountRef): array`. (Shapes: raw arrays the collector
  maps to the contract VOs.)
- `GatewayManager::supports($gw, 'payout')` ⇒ `$gw instanceof TransferCapableGateway`;
  `payoutGateway($name)` resolves + asserts the capability.
- `payvia_transfers` EXACTLY per spec §3.4: `id, uuid, tenant_uuid default '', gateway,
  idempotency_key, provider_reference, provider_ref nullable, destination_ref, amount, currency,
  status, message, request_payload json, raw_payload json, timestamps`; unique `(tenant_uuid, gateway,
  idempotency_key)`, global unique `(gateway, provider_reference)`, null-exempt unique `(gateway,
  provider_ref)`; tenant_uuid index in a follow-up `alterTable` (payvia convention). NO commerce reference.

- [ ] TDD: shape test (columns/uniques/indexes via driver introspection); `supports('payout')` true only
  for a `TransferCapableGateway`; `payoutGateway()` returns it / throws for a non-capable gateway. →
  implement → GREEN. NO commit.

### Task 3: Stripe + Paystack transfer/status/inspect + state mapping
**Files (payvia):** modify `src/Gateways/StripeGateway.php` + `src/Gateways/PaystackGateway.php`
(implement `TransferCapableGateway`); create a provider-safe-reference deriver; create
`tests/Integration/{StripeTransferTest,PaystackTransferTest}.php`. **NO commit until Task 4.**

**Interfaces / mapping (spec §2.2/§2.5, §10 provider constraints):**
- `transfer()` maps raw responses → `{status ∈ PAID|PENDING|RETRYABLE_FAILURE|TERMINAL_FAILURE|UNKNOWN,
  providerRef, failureCode, failureReason}`. Network/5xx/timeout ⇒ throw (UNKNOWN upstream). Definite
  decline ⇒ TERMINAL_FAILURE; rate-limit/transient documented-retryable ⇒ RETRYABLE_FAILURE; accepted-
  but-unsettled ⇒ PENDING; settled ⇒ PAID.
- `transferStatus()` adds `REVERSED` + cumulative `reversedAmount` (Stripe: transfer retrieval reports
  cumulative `amount_reversed`; Paystack: verify by reference). Paystack `otp` ⇒ `PENDING` with stable
  `action_required` (hold retained until provider-confirmed success/failure; no Commerce OTP workflow);
  Paystack `pending` ⇒ PENDING. A Paystack initiate response with `status=success` but
  `transferred_at=null`/queued semantics is also PENDING; only a verified settled result is PAID.
- Provider-safe reference derivation from the canonical `{payoutUuid}:attempt:{n}` key: Paystack ⇒
  lowercase 16–50 chars `[a-z0-9_-]` (never the colon key); Stripe ⇒ the canonical key may be the
  Stripe idempotency key directly. Mapping is deterministic + persisted (Task 4).

- [ ] TDD: each provider maps a representative raw response for every state incl. partial + full
  reversal; provider-safe reference obeys Paystack char/length constraints and is stable for a given
  canonical key; Paystack `otp` remains pending/action-required and never becomes a terminal release;
  Paystack queued-success remains pending until a verify response confirms settlement;
  a thrown transport error surfaces as an exception (not a fabricated status). Use a gateway HTTP
  fake (no live API). → implement → GREEN. NO commit.

### Task 4: `PayviaPayoutCollector` + binding — **PAYVIA COMMIT**
**Files (payvia):** create `src/Services/PayviaPayoutCollector.php`, `src/PayoutTransferRepository.php`;
modify `src/PayviaServiceProvider.php` (bind `PayoutCollector::class`); create
`tests/Integration/PayviaPayoutCollectorTest.php`.

**Interfaces:**
- `PayviaPayoutCollector implements PayoutCollector`. `transfer()`: persist a `payvia_transfers` row
  **before** any provider I/O (idempotency_key = the canonical attempt key; provider_reference = the
  derived safe ref; status=pending) → resolve the gateway (`GatewayManager::payoutGateway`) → call
  `transfer()` → update the row with `provider_ref`/status/raw_payload → return the mapped `PayoutResult`.
  A duplicate `(tenant, gateway, idempotency_key)` on the pre-I/O insert ⇒ **recover** the existing
  attempt (do NOT mint another transfer): if a provider result is known, return it; else reconcile via
  `transferStatus`/reference verify. `status()`: resolve through the durable row and the gateway
  `transferStatus`, mapping to `PayoutStatusResult` (incl. REVERSED + cumulative `reversedAmount`). No
  row ⇒ confirmed retryable `attempt_not_started`. `inspectDestination()` → gateway `inspectAccount` →
  `DestinationStatus`.
- Bind `PayoutCollector::class => ['class' => PayviaPayoutCollector::class, 'shared' => true,
  'autowire' => true]` (the existing `services()` cross-extension-collector idiom).

- [ ] TDD: pre-I/O row persisted before the (faked) provider call; a lost provider response (row exists,
  no provider_ref) recovers via status/reference-verify WITHOUT a second transfer; per-attempt-key
  replay de-dupes; full/partial reversal surfaces through `status()`; `attempt_not_started` when no row;
  Paystack action-required remains pending through the collector; `inspectDestination` maps readiness;
  the binding resolves `PayoutCollector`. → implement → GREEN.
  Full payvia suite + phpcs + analyze.
- [ ] **COMMIT (payvia):** `feat(payvia): provider payout transfers, status, and destination inspection`
  (the `Release 2.1.0` commit + the `extension-contracts ^1.5.0` pin bump are the USER's release step).

---

## PACKAGE 3 — `glueful/commerce` → 1.2.0 (release THIRD)

Repo: `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce`
**Before starting:** mirror the Task-1 contract files into commerce's `vendor/glueful/extension-contracts/
src/Payments/` so commerce compiles against the new port (vendor edit; the pin bump is release-time).

### Task 5: Schema (fold 013 + new 014) + config + shape tests
**Files (commerce):** modify `migrations/013_CreatePayoutTable.php` (fold the §3.1 provider columns +
sweep indexes; `external_ref`/`created_by` → nullable), create `migrations/014_CreateSellerPayoutAccountsTable.php`;
modify `config/commerce.php` (`marketplace.payouts`), `src/Support/DiagnosticsReport.php` (+`commerce_seller_payout_accounts`),
`tests/Support/CommerceTestCase.php`, and the existing `tests/Integration/Migrations/SettlementShapeTest.php`
(replace MV3's now-obsolete NOT NULL assertions with the folded nullable/default continuity assertions);
create `tests/Integration/Migrations/PayoutProviderShapeTest.php`.
**NO commit until Task 6.**

**Interfaces:** `commerce_payouts` gains all §3.1 columns (status default `paid`, method default
`manual`, provider/provider_ref/destination_ref/failure_code/failure_reason/retryable/attempt_count/
last_attempt_at/next_attempt_at/next_reconcile_at/reversed_total/updated_at/completed_at) + the two
sweep indexes; `commerce_seller_payout_accounts` per §3.2 (`tenant_uuid default('')`). `config
marketplace.payouts` = per-currency minimums, optional per-currency maximums (absent = full locked
available balance), backoff, max_attempts, pending_reconcile_interval, paid_reconcile_interval,
default_provider.

- [ ] TDD: shape — folded payout columns + defaults (status `paid`, method `manual`, retryable false,
  attempt_count 0, reversed_total 0); nullable `external_ref`/`created_by`; sweep indexes via
  introspection; `commerce_seller_payout_accounts` columns/uniques/index + `tenant_uuid default('')`;
  DiagnosticsReport lists it; re-run no-op. → implement → GREEN. Full suite green; NO commit.

### Task 6: Balance `pending`/`reserved` split — **COMMERCE COMMIT 1**
**Files (commerce):** modify `src/Marketplace/LedgerRepository.php` (`balanceComponents` conditional
aggregation by `payout_uuid`), `src/Marketplace/SellerBalanceService.php` (expose `pending`); extend
`tests/Integration/Marketplace/BalanceTest.php`.

**Interfaces:** `balanceComponents` returns `pending` (net reserve entries WHERE payout_uuid NOT NULL)
+ `reserved` (WHERE payout_uuid NULL), one grouped scan; `available` unchanged (sums all).
`SellerBalanceService::balance()` includes `pending`.

- [ ] TDD: seed reserve_hold/release with and without payout_uuid → `pending` reflects only the
  payout-referenced holds, `reserved` only the non-payout ones, `available` sums everything; a payout
  hold then release nets `pending` to 0. → implement → GREEN. Full suite + phpcs + analyze.
- [ ] **COMMIT (Commerce 1):** `feat(marketplace): mv4 payout schema and pending-balance split`

### Task 7: Payout saga (reserve/execute/finalize) + repository CAS
**Files (commerce):** modify `src/Marketplace/PayoutService.php` (add `execute`/`retry`/`reconcile`;
keep `record` as `method=manual`, now writing method/status/completed explicitly), `src/Marketplace/
PayoutRepository.php` (`claimPending`, `claimRetryableForAttempt`, setters); create `src/Marketplace/
PayoutOutcomeUnknownException.php`; modify `src/CommerceServiceProvider.php` (soft `?PayoutCollector`);
create `tests/Integration/Marketplace/PayoutSagaTest.php`. **NO commit until Task 8.**

**Interfaces:**
- `PayoutService::execute(c, tenant, sellerUuid, currency, int $amount, ?string $actorUuid): array` — reserve
  (account lock → available re-read → require ≥ amount → insert row `method=provider,status=pending,
  attempt_count=1`, stamp `last_attempt_at`, set initial `next_reconcile_at` watchdog → `reserve_hold`
  w/ payout_uuid, copy `destination_ref`; readiness gate from Task 8
  — for this task stub readiness as a passed-in ready account or a nullable dependency) → execute
  (`PayoutCollector::transfer`, attemptKey(1), OUTSIDE txn) → finalize (`claimPending` CAS; PAID ⇒
  reserve_release+payout_debit+paid+completed_at+slow reconcile; PENDING ⇒ next_reconcile_at; RETRYABLE
  ⇒ hold stays + schedule or terminalize on last attempt; TERMINAL ⇒ reserve_release+failed; UNKNOWN ⇒
  hold stays + pending + PayoutOutcomeUnknownException). Provider unbound ⇒ 422 (no provider).
- `PayoutRepository::claimPending(c, tenant, uuid, to, set): bool`; `claimRetryableForAttempt(c, tenant,
  uuid, maxAttempts): ?array` (CAS failed+retryable+due→pending, increment attempt_count, stamp
  last_attempt_at, clear next_attempt_at, set watchdog next_reconcile_at); setters.
- Every balance-affecting post claims the account lock; transition idempotency keys per §2.3.

- [ ] TDD: PAID posts reserve_release+payout_debit (balances exact: available unchanged hold→paid,
  pending→0, paid_out up); PENDING keeps hold + sets reconcile; RETRYABLE keeps hold + schedules next
  attempt (attempt not incremented until re-claim); TERMINAL releases hold; UNKNOWN keeps hold + stays
  pending + raises + never advances attempt; single-finalizer CAS (double-finalize = one posting set);
  a simulated process death after provider execution but before finalize is recovered by the initial
  reserve watchdog;
  reserve refuses when available<amount; provider-unbound 422; manual `record()` still writes
  method=manual/paid/completed. → implement → GREEN. NO commit.

### Task 8: Payout accounts + readiness gate + sync — **COMMERCE COMMIT 2**
**Files (commerce):** create `src/Marketplace/PayoutAccountRepository.php`, `PayoutAccountService.php`,
`src/Console/SyncPayoutAccountsCommand.php`; wire the readiness gate into `PayoutService::execute`'s
reserve; modify provider registrations; create `tests/Integration/Marketplace/PayoutAccountReadinessTest.php`.

**Interfaces:**
- `PayoutAccountService::attach(c, tenant, sellerUuid, provider, accountRef, actor): array` (upsert an
  opaque ref, `readiness_state=pending`); `sync(c, tenant, sellerUuid, provider): array` — DNS-style:
  snapshot `(uuid, provider, account_ref)` → `PayoutCollector::inspectDestination` OUTSIDE txn →
  guarded apply (update readiness/last_synced_at/failure_code only if provider+account_ref still match).
  `requireReady(c, tenant, sellerUuid, provider): array` — returns the ready account or throws 422.
- Reserve calls `requireReady` BEFORE any hold; copies `destination_ref` onto the payout.

- [ ] TDD: reserve refuses 422 when no `ready` account for the provider, before any hold/row; `sync`
  updates readiness from `inspectDestination` (not operator-asserted); a concurrent account_ref change
  during inspection makes the stale result a no-op (guarded apply); per-provider records don't
  overwrite; the in-flight payout keeps its snapshotted destination_ref across a later account change.
  → implement → GREEN. Full suite + phpcs + analyze.
- [ ] **COMMIT (Commerce 2):** `feat(marketplace): provider payout saga and destination readiness`

### Task 9: Retry/reconcile/batch CLI + reversal + reconciliation
**Files (commerce):** create `src/Console/{PayoutsRunBatchCommand,PayoutsRetrySweepCommand,
PayoutsReconcileSweepCommand}.php`; add `PayoutService::reconcile` reversal handling (delta-only
`payout_reversal`, paid-state-regression integrity) — extend from Task 7; modify `src/Marketplace/
ReconciliationService.php` (payout-state ledger coherence + contract-only provider check); create
`tests/Integration/Marketplace/{PayoutRetryReconcileTest,PayoutReversalTest,PayoutBatchTest,
PayoutProviderReconciliationTest}.php`. **NO commit until Task 10.**

**Interfaces:**
- Retry sweep: select `failed+retryable+next_attempt_at≤now+attempt_count<max`, claim each via
  `claimRetryableForAttempt`, execute the new attempt (key `attempt:{n+1}`); final-attempt retryable ⇒
  terminalize + release hold.
- Reconcile sweep: select due unresolved `pending` (`next_reconcile_at IS NULL OR ≤now`, where null is
  an immediate repair candidate) + due `paid` (slow cadence); `status()` → closed map; partial
  `reversedAmount>reversed_total` ⇒ post delta `payout_reversal`
  (`{payout}:payout_reversal:{reversed_total_after}`) + update `reversed_total`, stay `paid`; `===amount`
  ⇒ `status=reversed`; a paid regression / over-amount ⇒ integrity finding (no money change).
- Batch: unlocked candidate hint → per candidate the shared reserve path (lock → re-read available →
  derive amount from locked value, cap by config, skip if < min → hold) → execute; independent per
  candidate.
- `ReconciliationService`: expect `payout_debit` for both `status=paid` and `status=reversed`; a
  reversed row additionally requires cumulative `payout_reversal == reversed_total` (and a full
  reversal requires `reversed_total == amount`). Account for in-flight holds; provider state via
  `PayoutCollector::status()` only (never `payvia_transfers`).

- [ ] TDD: retry CAS increments exactly once immediately before I/O; crash-after-claim recovers via
  watchdog (reconcile), not blind retry; max-attempt exhaustion terminalizes + releases hold;
  reconcile-before-retry for UNKNOWN; reconcile skips pending/paid rows before `next_reconcile_at`;
  a null reconciliation timestamp is treated as immediately due for repair;
  partial then full reversal posts only unseen deltas, paid→reversed at completion, paid_out restored,
  and reconciliation requires the original debit plus the cumulative reversal; paid regression
  integrity-fails (no ledger change); batch derives amount from locked balance, honors the optional
  per-currency cap, and two concurrent workers can't duplicate the amount; one failing seller doesn't
  abort the batch. → implement → GREEN. NO commit.

### Task 10: Operator + seller surfaces + capabilities + binding — **COMMERCE COMMIT 3**
**Files (commerce):** modify `src/Http/Admin/AdminPayoutController.php` (single-seller execute; retry a
specific retryable payout; attach/sync account), `src/Http/Seller/SellerFinancialController.php`
(sanitized payout projection + payout-account readiness read), DTOs, `routes.php`, `src/CommerceServiceProvider.php`;
create `tests/Integration/Marketplace/PayoutSurfaceTest.php`.

**Interfaces:**
- Operator (`commerce:write`): `POST` execute one seller; `POST` retry a specific payout (only while
  retryable+below max, same CAS); `POST`/`PUT` attach/sync a payout account. NO batch endpoint, NO
  reverse endpoint.
- Seller (`commerce_seller`): `GET` own payouts (sanitized projection — status/provider_ref/sanitized
  failure code+message, while retaining existing manual `external_ref`/`note`/`created_by` fields;
  excludes raw failure_reason/idempotency/destination refs); `GET` own payout-account readiness
  (provider/state/last-synced only, never account_ref). No mutation.
- Cross-seller/unknown ⇒ 404 non-revealing.

- [ ] TDD: operator execute/retry/attach/sync over real routes (`commerce:write`); retry rejects a
  terminal/exhausted payout (requires new payout); seller sees own sanitized payouts + readiness;
  existing manual payout JSON remains byte-identical and provider rows render nullable
  `external_ref`/`created_by` coherently;
  poison-string test proves raw failure_reason / idempotency key / destination ref / account_ref never
  leak to the seller; cross-seller 404; no batch/reverse route exists. → implement → GREEN. Full suite +
  phpcs + analyze.
- [ ] **COMMIT (Commerce 3):** `feat(marketplace): payout retries, reconciliation, reversals, and surfaces`

### Task 11: Gates — regression, races, docs — **COMMERCE COMMIT 4**
**Files (commerce):** extend `tests/Integration/Marketplace/MarketplaceRegressionTest.php`; create
`tests/Integration/Marketplace/PayoutSagaPgsqlTest.php`; modify `tests/Integration/Http/HttpDocumentationTest.php`
(flag-ON walk covers new routes), `CHANGELOG.md` (`[Unreleased]`).

- [ ] Regression: marketplace off / provider unbound ⇒ manual payout byte-identical, provider path
  inert; route manifest identical when off; a non-payout / non-marketplace path executes ZERO new
  queries (payout accounts, sweep indexes); the folded default (`status=paid, method=manual`) keeps
  existing/manual payouts paid; `DiagnosticsReport`/tenant-adopt cover the new table.
- [ ] Live pgsql lanes (fixture-child harness): payout-vs-refund under the hold (no overdraw); double-
  finalize idempotency (one posting set); concurrent batch workers derive from the locked balance and
  never double-transfer the same amount; concurrent retry sweeps + `claimRetryableForAttempt` don't
  double-attempt; a paid-state provider regression is flagged, never reposts. Verbatim output in report.
- [ ] Migration shape live on pgsql: folded payout columns + `commerce_seller_payout_accounts`; indexes
  via `pg_indexes`; re-run no-op.
- [ ] CHANGELOG `[Unreleased]`: MV4 provider payouts — the reserve/execute/finalize saga, `pending`
  balance, per-attempt retries, provider reconciliation + reversals, readiness, batch/retry/reconcile
  CLI; contract-only coupling; default-off / provider-unbound unchanged. Note the release chain
  (contracts 1.5.0 → payvia 2.1.0 → commerce 1.2.0).
- [ ] Full suite (SQLite + live pgsql) + phpcs + analyze. **COMMIT (Commerce 4):**
  `feat(marketplace): mv4 payout gates, races, and regression proof`

---

## Self-Review Notes
- **Spec coverage:** §2.1 → T1; §2.2/§3.4 → T2–T4; §2.3 saga → T7; §2.4 balances → T6; §2.5 idempotency
  → T2/T4/T7; §2.6 retry/reconcile/batch → T9; §2.7 readiness → T8; §2.8 reversal → T9; §2.9 coupling →
  T7/T9/T10; §3.1/3.2 schema → T5; §4 gates → per-package; §6 surfaces → T10; §7 off-invariance → T11;
  §8 tests → distributed; §10 seams already source-verified.
- **Release-order gates:** contracts (T1) releases before payvia (T2–T4) before commerce (T5–T11). Each
  package's tests are self-contained; the composer pin bumps + tags + publish are the USER's release
  steps (vendor-edit the contract locally during dev; vendored mirrors are never staged). The
  contracts `extra.glueful.version` repair is likewise part of the USER's release commit, not T1's
  feature commit.
- **Invariant-bearing subtleties for reviewers:** (a) per-attempt idempotency key — a new key ONLY
  after a confirmed retryable failure, never under UNKNOWN (T2/T4/T7/T9); (b) hold lifetime — held ⟺
  non-terminal, so `failed+retryable` still holds (T6/T7/T9); (c) reserve order — lock → available
  re-read → insert → hold, one txn (T7); (d) reversal is delta-only + paid-regression is integrity, not
  a negative post (T9); (e) readiness DNS-style guarded apply — a replaced account can't be marked ready
  by a stale inspection (T8); (f) batch derives amount from the LOCKED balance so concurrent workers
  can't duplicate (T9/T11); (g) commerce reconciles only via `PayoutCollector::status()`, never
  `payvia_transfers` (T9).
