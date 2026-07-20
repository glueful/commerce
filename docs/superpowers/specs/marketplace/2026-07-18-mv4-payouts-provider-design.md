# Commerce Marketplace MV4 — Payouts & Provider Integration

> **Status:** held / uncommitted. Design authority for the MV4 implementation plan.
> **Scope:** THREE repos in release order — `glueful/extension-contracts` (the payout port),
> `glueful/payvia` (first provider implementation), `glueful/commerce` (the saga + consumption).
> **Predecessors:** MV1–MV3 (`2026-07-18-mv3-settlement-ledger-design.md` — commission, the append-only
> ledger, derived balances, manual payouts). Overview: `2026-07-16-multi-vendor-overview-design.md`
> (§5.2 payouts, §6.4 settlement, MV4 slice).

## 1. Scope & Non-Goals

**In scope.** A provider-neutral payout/transfer **port** in `extension-contracts`; a first **Payvia**
implementation (real minimal transfer); a commerce **reserve → execute → finalize** saga (provider I/O
strictly outside DB transactions); in-flight balance protection via `reserve_hold`; the `pending`
balance component; provider-reported reversals; automatic bounded retries + provider status
reconciliation; scheduled payout batches (CLI) + single-seller operator payout (HTTP); payout
**destination readiness** sourced from the provider; provider reconciliation; operator/seller surfaces.

**Explicitly deferred.** Time-based hold/maturation windows and risk-based reserve policy (**MV5** —
`reserve_hold`/`reserve_release` are used here ONLY as payout-saga accounting, disambiguated by
`payout_uuid`); operator-initiated clawbacks (**MV5** — MV4 only processes provider-**reported**
reversals); connected-account onboarding/KYC UI (**MV5** — the provider owns onboarding; commerce
stores only an opaque reference); an HTTP batch action (batches are CLI only).

**Continuity.** Manual payouts (MV3 `method=manual`) and all internal ledger semantics keep working
with **no** `PayoutCollector` bound — the provider path is inert until a host wires a provider.

## 2. Pinned Decisions (authoritative contract)

### 2.1 The payout port (`glueful/extension-contracts`)
- **`PayoutCollector`** interface (`src/Payments/`):
  - `transfer(ApplicationContext $c, PayoutDestination $dest, PayoutRequest $req): PayoutResult` —
    idempotent per `PayoutRequest::$idempotencyKey`. A **throw** = infra failure / unknown outcome
    (consumer must reconcile, never blindly re-attempt). A returned result carries a classified
    outcome; `UNKNOWN` remains explicitly ambiguous.
  - `status(ApplicationContext $c, PayoutDestination $dest, string $idempotencyKey): PayoutStatusResult`
    — provider-neutral **reconcile** operation: query the provider for the current state of the
    transfer identified by the attempt's idempotency key. This is what makes "reconcile before retry"
    satisfiable without commerce guessing.
- **Value objects** (`final`): `PayoutDestination` (`provider`, opaque `accountRef`, `array metadata`),
  `PayoutRequest` (`amount`, `currency`, `idempotencyKey`, `?reason`), and a `DestinationStatus`
  inspection result (see 2.7).
- **Outcome vocabularies:**
  - `PayoutResult` (from `transfer`): `PAID | PENDING | RETRYABLE_FAILURE | TERMINAL_FAILURE | UNKNOWN`
    + `providerRef`, `failureCode`, `failureReason`.
  - `PayoutStatusResult` (from `status`): the same five **plus** `REVERSED` (a transfer that settled
    then was fully reversed by the provider) + `providerRef`, `failureCode`, `failureReason`, and
    cumulative `reversedAmount` (0..request amount). A partial reversal returns `PAID` with a positive
    `reversedAmount`; `REVERSED` requires `reversedAmount === request amount`.
- **Readiness inspection** is part of `PayoutCollector` (not an optional sibling seam):
  `inspectDestination(ApplicationContext $c, PayoutDestination $dest): DestinationStatus` — returns the
  provider's readiness for that destination (see 2.7). Readiness is **provider-sourced**, never
  operator-asserted.
- Release **1.5.0** (feat commit + release commit, the `RefundCollector` cadence).

### 2.2 Payvia implementation (`glueful/payvia`)
- Bump `extension-contracts` pin `^1.1.0` → `^1.5.0` (currently stale).
- Add a `TransferCapableGateway` capability interface (parallel to `InitiationCapableGateway`):
  `transfer(...)` + `transferStatus(...)` + `inspectAccount(...)`; implement on `StripeGateway` /
  `PaystackGateway` (real minimal transfer to the opaque account ref); `GatewayManager::supports($gw,
  'payout')`.
- `PayviaPayoutCollector` implements `PayoutCollector`; a `payvia_transfers` table (globally-unique
  provider reference per the `001_CreatePaymentsTable` idiom) persists the normalized request
  **before provider I/O**. It includes tenant, gateway, internal idempotency key, provider-safe
  reference, opaque destination, amount/currency/status/message, nullable provider reference, and
  JSON request/raw payloads; register `PayoutCollector::class` in `PayviaServiceProvider::services()`.
- The provider must honor the **per-attempt idempotency key** (2.5): the same key across replays of one
  attempt de-dupes at the provider; a new key is a genuinely new transfer. Gateways may derive a
  provider-safe reference from the canonical key, but the mapping is persisted and stable.
- `status()` resolves through that durable attempt row. No row means the transfer call never reached
  Payvia and returns a confirmed retryable `attempt_not_started` result. A pre-I/O row with no known
  provider result is reconciled without minting another attempt: Paystack verifies the persisted
  provider-safe reference; Stripe may replay the identical create request under the same Stripe
  idempotency key to recover the original response. Neither path can move money twice.
- Release **2.1.0**.

### 2.3 Commerce saga — reserve → execute → finalize (`glueful/commerce`)
Mirrors `RefundService`'s gateway saga; provider I/O strictly **outside** DB transactions.
- **Reserve** (one txn, no I/O): snapshot a seller payout account that is **`ready`** for the provider
  (2.7) → claim the ledger account lock → **re-read** `available` under that lock and require
  `available ≥ amount` → insert the `commerce_payouts` row `status=pending, method=provider,
  attempt_count=1`, stamp `last_attempt_at`, and set an initial `next_reconcile_at` watchdog → post
  **`reserve_hold = −amount`** carrying the `payout_uuid`; commit. This is the exact MV3 balance-safety
  order. The pre-I/O watchdog closes the process-death window between provider execution and Commerce
  finalization. The hold drops `available` so a concurrent payout/refund cannot double-spend, and
  surfaces as **`pending`** (2.4).
- **Execute** (outside any txn): `PayoutCollector::transfer($dest, PayoutRequest{ idempotencyKey =
  attemptKey(1) })` (2.5).
- **Finalize** (one txn, no I/O) — `PayoutRepository::claimPending()` CAS (single-finalizer-wins), then
  claim the seller ledger-account lock before any balance-affecting post and apply the
  `PayoutResult`:
  - **PAID** → atomically post `reserve_release = +amount` (payout_uuid) **and** `payout_debit =
    −amount`; `status=paid`, `provider_ref`, `completed_at`, and the slower paid-status
    `next_reconcile_at`. (Net: hold released, real debit lands.)
  - **PENDING** → leave `status=pending`, hold stands; set `next_reconcile_at`. A reconcile sweep (2.6)
    resolves it later via `status()` with the SAME attempt key.
  - **RETRYABLE_FAILURE** (a *confirmed* retryable decline) → hold **stays**; `status=failed`,
    `retryable=true`, `failure_code/reason`; if attempts remain, set `next_attempt_at = now + backoff`
    without incrementing `attempt_count`. If this was the final allowed attempt, terminalize instead:
    post `reserve_release`, set `retryable=false`, and clear retry timing so no hold is stranded.
  - **TERMINAL_FAILURE** → post `reserve_release = +amount` (undo the hold); `status=failed`,
    `retryable=false`, `failure_code/reason`.
  - **UNKNOWN** (or a transport throw) → **do not** release the hold, **do not** advance the attempt;
    `status=pending` (unresolved), set `next_reconcile_at`, raise `PayoutOutcomeUnknownException`. Only
    a reconcile (2.6) may move it forward.

**Hold-lifetime invariant (load-bearing for §2.4 balances + reconciliation):** the `reserve_hold`
persists until a **terminal** resolution and is released exactly once. Terminal = PAID (the hold is
converted: `reserve_release` + `payout_debit`) or TERMINAL_FAILURE / provider-REVERSED (the hold is
released via `reserve_release`, or for a paid-then-reversed payout the `payout_debit` is offset by
`payout_reversal`). **Non-terminal** = PENDING, UNKNOWN, and `RETRYABLE_FAILURE` (status `failed`,
`retryable=true`) — the hold **stays** through all of these. So the hold is held ⟺ the payout is not
terminally resolved; no query may assume a `failed` row released its hold without also checking
`retryable=false`.

**Transition idempotency:** one payout has exactly one `{payout}:reserve_hold`, one
`{payout}:reserve_release`, and one `{payout}:payout_debit` identity. Reconciliation replays verify
those rows instead of duplicating them. Provider-reversal entries use the cumulative target in their
key (`{payout}:payout_reversal:{reversed_total_after}`), and each post is only the positive delta from
the persisted `reversed_total`.

### 2.4 Balances — `pending` vs `reserved` (split by `payout_uuid`)
`reserve_hold`/`reserve_release` are reused as payout accounting; the balance disambiguates by whether
the entry references a payout:
- `pending  = −( Σ reserve_hold + Σ reserve_release  WHERE payout_uuid IS NOT NULL )`
- `reserved = −( Σ reserve_hold + Σ reserve_release  WHERE payout_uuid IS NULL )` (MV5 risk reserves)
- `available = SUM(amount)` over **every** signed entry (unchanged — the hold already nets out).
- `paid_out = −( Σ payout_debit + Σ payout_reversal )` (unchanged).
So an in-flight payout shows as `pending`; MV5's future risk reserves show as `reserved`; the two never
collide. `SellerBalanceService`/`LedgerRepository::balanceComponents` gain the `payout_uuid`-aware split
and expose `pending`.

### 2.5 Provider idempotency — per attempt
- Attempt `n` uses the deterministic key **`{payoutUuid}:attempt:{n}`** for both its `transfer` call and
  every `status`/reconcile call about that attempt (the provider de-dupes replays of the *same*
  attempt — this is what makes an ambiguous attempt safely reconcilable).
- This is Commerce's canonical key, not necessarily the provider's wire reference. Payvia persists a
  stable provider-safe mapping. For Paystack, for example, it derives a lowercase 16–50 character
  reference using only alphanumerics, hyphen, and underscore; the canonical colon-delimited key is
  never sent as the Paystack reference.
- A **new** attempt key (`n+1`) is minted **only** after a **confirmed** `RETRYABLE_FAILURE`.
- **Never** create attempt `n+1` while attempt `n`'s outcome is `UNKNOWN`/`PENDING` — that risks a
  double transfer. Reconcile attempt `n` to a definite state first.

### 2.6 Retries, reconciliation, scheduling
- Persisted timing on `commerce_payouts`: `last_attempt_at`, `next_attempt_at`, `next_reconcile_at`,
  `attempt_count`, `retryable` — bounded backoff cannot be implemented from `attempt_count` alone.
- **Retry claim:** `PayoutRepository::claimRetryableForAttempt()` is a guarded CAS from
  `failed+retryable+due` to `pending`; in that transaction it increments `attempt_count`, clears
  `next_attempt_at`, stamps `last_attempt_at`, and sets a `next_reconcile_at` watchdog **before** any
  provider I/O. The operator retry uses the same claim but may ignore the due time; neither path may
  exceed `max_attempts`. A crash after the claim is recovered by reconciliation, never a blind retry.
- **Retry sweep (CLI):** selects due candidates, then claims each independently with the CAS above and
  executes the newly claimed attempt. The hold persists across retryable attempts. A retryable result
  on the final allowed attempt terminalizes and releases the hold; later operator action creates a
  new payout rather than resurrecting the terminal row.
- **Reconcile sweep (CLI):** selects both due unresolved `pending` payouts (`next_reconcile_at IS NULL`
  is immediately due as a repair backstop) and due `paid` payouts.
  Pending rows reconcile PENDING/UNKNOWN attempts; paid rows run at a slower configurable cadence so
  provider-reported reversals are discoverable. Calls `PayoutCollector::status(attemptKey(current))`
  and applies a closed state-valid mapping. A `paid` payout may remain `PAID`, become partially/full
  reversed, or remain unresolved because the status call itself failed; a provider regression to
  `PENDING`/`RETRYABLE_FAILURE`/`TERMINAL_FAILURE` after Commerce recorded `paid` is an integrity
  finding and never releases or reposts money. For a cumulative `reversedAmount` greater than the
  persisted `reversed_total`, claim the seller account lock and post only the delta in the same
  transaction as updating the payout. Keep `status=paid` for a partial reversal; set
  `status=reversed` only when `reversed_total === amount`. A regressing or over-amount observation is
  an integrity finding, never a negative compensating post.
- **Batch (CLI):** `commerce:marketplace:payouts:run-batch` enumerates eligible `(seller, currency)`
  using an unlocked availability read only as a candidate hint. For each candidate, the shared
  reserve path claims the seller/currency ledger-account lock, re-reads available, and chooses the
  batch amount from that locked value (the full available amount, optionally capped by config); it
  skips when the locked value is below the per-currency minimum. The hold is posted in that same
  transaction before execution. This makes concurrent batch workers serialize without a separate
  candidate lease. Candidates are processed independently so one seller/provider failure never
  aborts the batch. Host cron invokes it (no commerce-owned scheduler).
- **No HTTP batch.** Operator HTTP is single-seller only (2.8).

### 2.7 Payout destination & readiness (provider-sourced)
New **`commerce_seller_payout_accounts`** table (dedicated, not columns on `commerce_sellers`):
`(tenant_uuid, seller_uuid, provider)` unique, `account_ref` (opaque), `readiness_state`
(`pending | ready | restricted`), `last_synced_at`, `failure_code`, timestamps. **No row** means
`unconfigured`; a row always has a non-empty opaque `account_ref`. Commerce stores no raw bank/KYC/PII.
**Reserve refuses (422) unless a
`ready` account exists for the payout provider, before any ledger hold.** Readiness is set by syncing
from the provider (`inspectDestination` → update `readiness_state`/`last_synced_at`/`failure_code`),
via an operator action or a sync sweep — never a manual operator "mark ready". Per-provider records so
switching providers doesn't overwrite. `commerce_sellers.status='onboarding'` (reserved since MV1)
remains orthogonal.

Inspection I/O never runs under a DB lock: snapshot `(uuid, provider, account_ref)` → call
`inspectDestination()` outside a transaction → guarded update only if provider/account_ref still
match. A concurrent account replacement therefore makes the old inspection stale/no-op instead of
marking the new destination ready. Reserve copies `destination_ref` onto the payout, so later account
changes never retarget an in-flight payout.

### 2.8 Reversal scope (provider-reported only)
MV4 processes a **provider-reported** reversal from the paid-payout reconciliation cadence. A status
result's cumulative `reversedAmount` posts only the unrecorded delta as `payout_reversal`; partial
reversals leave the payout `paid`, while a full `REVERSED` result sets `status=reversed`. There is **no**
operator "reverse"/clawback HTTP command in MV4 (a clawback requires the provider port to actually
perform one — deferred to MV5). The saga's failure path uses `reserve_release`, never `payout_reversal`
(they are distinct: `reserve_release` undoes an unpaid hold; `payout_reversal` reverses a settled debit).

### 2.9 Coupling & continuity
Commerce depends on the **contract only**; the `PayoutCollector` binding is provider-injected by the
host (soft `container->has()`, exactly like `RefundCollector`). With no provider bound, provider payouts
are unavailable and manual payouts + all ledger semantics keep working. No commerce → payvia composer
dependency. Commerce's provider reconciliation calls only `PayoutCollector::status()`; Payvia owns
comparison of its durable transfer-attempt row with Stripe/Paystack. Commerce never reads or names
`payvia_transfers`.

## 3. Schema (exact)

### 3.1 `commerce_payouts` — provider columns folded into unreleased `013`
Add: `status` varchar(16) default `paid` (`pending|paid|failed|reversed`); `method` varchar(16)
default `manual` (`manual|provider`); `provider` varchar(32) nullable; `provider_ref` varchar(191)
nullable; `destination_ref` varchar(191) nullable; `failure_code` varchar(64) nullable; `failure_reason`
text nullable; `retryable` boolean default false; `attempt_count` int default 0; `last_attempt_at`
timestamp nullable; `next_attempt_at` timestamp nullable; `next_reconcile_at` timestamp nullable;
`reversed_total` bigInt default 0; `updated_at` timestamp nullable; `completed_at` timestamp nullable.
Index `(tenant_uuid, status,
next_attempt_at)` and `(tenant_uuid, status, next_reconcile_at)` for the sweeps.
`external_ref` and `created_by` become nullable at the schema level: provider rows exist before a
provider reference is known, and scheduled batches have no human actor. The manual `record()` service
continues to require both and now writes `method=manual`, `status=paid`, and `completed_at` explicitly;
the provider reserve path always writes `method=provider`, `status=pending`. **Confirmed:** Commerce
`v1.1.0` contains migrations `001–009` only, so marketplace migration `013` is unreleased and folding
is the correct no-external-installs posture.

### 3.2 `commerce_seller_payout_accounts` — new (`014`)
`id` bigInt PK; `uuid` varchar(12); `tenant_uuid` varchar(12) default `''`; `seller_uuid` varchar(12);
`provider` varchar(32); `account_ref` varchar(191); `readiness_state` varchar(16) default `pending`;
`last_synced_at` timestamp nullable; `failure_code` varchar(64) nullable; `created_at`/`updated_at`.
Unique `(tenant_uuid, seller_uuid, provider)`, `(tenant_uuid, uuid)`; index `(tenant_uuid, seller_uuid)`.
`tenant_uuid default('')`. Registered in `DiagnosticsReport` (commerceTables + tenantTables).

### 3.3 Ledger
`commerce_marketplace_ledger.payout_uuid` already exists (MV3). MV4 posts `reserve_hold`/`reserve_release`
with `payout_uuid` set, and `payout_reversal` (activated). No schema change to the ledger — only new
entry_type usage + the balance split query (2.4).

### 3.4 `payvia_transfers` (payvia repo, new `008` migration)
Durable attempt record written before provider I/O: `id`, `uuid`, `tenant_uuid` default `''`,
`gateway`, `idempotency_key` (Commerce's canonical attempt key), `provider_reference` (provider-safe
request reference), `provider_ref` nullable (the returned transfer id/code), `destination_ref`,
`amount`, `currency`, `status`, `message`, `request_payload` JSON, `raw_payload` JSON, timestamps.
Unique `(tenant_uuid, gateway, idempotency_key)`, global unique `(gateway, provider_reference)`, and
null-exempt unique `(gateway, provider_ref)`; index `tenant_uuid` in the follow-up `alterTable` block
used by Payvia's existing migration convention. The table contains no Commerce class/table reference.

## 4. Package-owned deliverables & acceptance gates (release order)

### 4.1 `glueful/extension-contracts` → 1.5.0 (FIRST)
**Deliver:** `PayoutCollector` (transfer + status + inspectDestination), `PayoutDestination`,
`PayoutRequest`, `PayoutResult` (5 states), `PayoutStatusResult` (6 states incl. REVERSED),
`DestinationStatus`; status results carry cumulative `reversedAmount`; unit tests for state-specific
VO invariants + interface docblocks (idempotency + throw semantics). The release commit also updates
the package's stale `extra.glueful.version` metadata from `1.3.0` to `1.5.0` (tag `v1.4.0` currently
still reports `1.3.0`).
**Gate:** VO/enum tests green; `composer` valid; feat + release commit; the interface compiles against
`glueful/framework`. Nothing here depends on payvia/commerce.

### 4.2 `glueful/payvia` → 2.1.0 (SECOND)
**Deliver:** contracts pin → `^1.5.0`; `TransferCapableGateway` + Stripe/Paystack `transfer`/
`transferStatus`/`inspectAccount`; `PayviaPayoutCollector`; `payvia_transfers` migration;
`GatewayManager` `payout` capability + typed accessor; `PayoutCollector::class` binding;
pre-I/O attempt persistence and per-attempt-key/provider-reference mapping.
**Gate:** payvia unit/integration tests green (transfer success/pending/retryable/terminal/unknown +
status incl. reversed, against a gateway fake); the collector maps raw provider responses to the 5/6
  states correctly; internal-key replay and provider-safe-reference mapping proven. Stripe retrieval and
  partial reversal mapping, plus Paystack's `pending`/`otp` and reference-character constraints, have
  provider-specific tests. Paystack `otp` is **not terminal**: it maps to `PENDING` with a stable
  `action_required` code, retains the hold, and must reconcile to a provider-confirmed success/failure
  after the operator resolves or cancels it outside Commerce. MV4 ships no OTP-entry workflow and must
  never release money merely because interaction is required. Paystack's initiate response can say
  `status=success` while also saying the transfer is queued and `transferred_at` is null; that response
  maps to `PENDING`, not `PAID`, until verify confirms settlement. Depends only on contracts 1.5.0.

### 4.3 `glueful/commerce` → 1.2.0 (THIRD)
**Deliver:** contracts pin → `^1.5.0`; the reserve/execute/finalize `PayoutService` split;
`PayoutRepository` `claimPending` + `claimRetryableForAttempt` + status/timing setters; the schema
(3.1/3.2); the balance
`pending`/`reserved` split + `payout_reversal`; the destination-account model + readiness gate + sync;
retry/reconcile/batch CLI commands; contract-only provider reconciliation; operator single-seller HTTP + seller/
operator payout-account surfaces + capabilities; soft `PayoutCollector` binding.
**Gate:** the whole §8 test plan green (SQLite + live-pgsql saga races); manual payout + provider-unbound
paths byte-identical; regression pins. Depends on contracts 1.5.0 (and, at runtime in the host, payvia
2.1.0).

## 5. Commerce services & seams
- **`PayoutService`** — `execute(...)` (reserve→call→finalize for one seller), `retry(...)`,
  `reconcile(...)`, plus the MV3 `record(...)` becomes the `method=manual` path. Appends optional
  `?PayoutCollector` (soft-bound). Provider I/O outside transactions.
- **`PayoutRepository`** — add `claimPending(c, tenant, uuid, to, set)` (CAS, `RefundRepository`
  template), `claimRetryableForAttempt(...)` (failed→pending CAS + attempt increment + watchdog),
  `setFailure/setProviderRef/scheduleAttempt/scheduleReconcile` setters.
- **`PayoutAccountService` + `PayoutAccountRepository`** — attach an opaque account ref, sync readiness
  from `inspectDestination`, the reserve-time readiness gate.
- **`LedgerRepository`/`SellerBalanceService`** — the `payout_uuid`-aware `pending`/`reserved` split;
  `payout_reversal` posting via `LedgerPostingService` (or `PayoutService` directly).
- **`ReconciliationService`** — ledger coherence by payout state (manual/paid debit; pending/retryable
  hold; terminal-failed hold+release; reversed debit+reversal). Provider state is obtained only through
  `PayoutCollector::status()`; Payvia owns its internal attempt/provider comparison.
- **Console:** `commerce:marketplace:payouts:run-batch`, `:retry-sweep`, `:reconcile-sweep`,
  `:sync-payout-accounts` (all `BaseCommand`, host-cron invoked; claim candidates individually).
- **Exceptions:** `PayoutOutcomeUnknownException extends \RuntimeException`, reuse `PayoutException`
  (\DomainException, 422) for validation/not-ready/insufficient.

## 6. APIs, capabilities & authority
- **Operator (admin, `commerce:write`):** `POST` single-seller payout execute; `POST` retry a specific
  failed payout only while it is retryable and below `max_attempts` (single, short — one provider
  call, using the same retry CAS); terminal/exhausted rows require a new payout; `POST`/`PUT` attach or sync a seller's payout
  account. **No** batch endpoint (CLI only). **No** reverse endpoint (provider-reported only).
- **Operator (`commerce:read`):** payout list/detail incl. status/provider/failure; per-seller balance
  now includes `pending`.
- **Seller (`commerce_seller` middleware):** `GET` own payouts preserves the existing MV3 manual
  projection (`amount`, `currency`, `external_ref`, `note`, `created_by`, timestamps) and extends it
  with status/provider/provider_ref/sanitized failure code+message. Provider rows may return null for
  the now-nullable `external_ref`/`created_by`; raw `failure_reason`, idempotency keys, and destination
  refs stay excluded. `GET` own payout-account readiness (`commerce.seller.payouts.read`)
  returns provider/state/last-synced only, never `account_ref`. No mutation.
- Cross-seller/unknown ⇒ 404 non-revealing.

## 7. Marketplace-off / no-provider invariance
- Marketplace off, or `PayoutCollector` unbound: provider payouts unavailable; manual payout + ledger
  semantics unchanged; zero new queries on non-marketplace and non-payout paths; route manifest
  unchanged when off. Historical payouts branch on their own `method`/`status`, not current binding.

## 8. Test plan
- **Contracts (own repo):** VO construction/validation; enum completeness; interface docblock semantics.
- **Payvia (own repo):** pre-I/O attempt row; `transfer` maps provider responses to
  PAID/PENDING/RETRYABLE/TERMINAL/UNKNOWN; `status` maps full and partial reversal; canonical-key to
  provider-safe-reference mapping; same-attempt recovery when the provider response is lost; the
  `payout` capability probe/accessor; no Commerce dependency.
- **Commerce saga:** reserve posts `reserve_hold` (+`pending` reflects it), execute outside txn,
  finalize PAID posts `reserve_release`+`payout_debit` (balances exact: available unchanged across
  hold→paid, pending→0, paid_out up), RETRYABLE keeps the hold + schedules next attempt with a NEW key,
  TERMINAL releases the hold, UNKNOWN keeps the hold + stays pending + raises + never advances the
  attempt; account lock precedes the available re-read; single-finalizer CAS.
- **Idempotency:** attempt `n` key stable across a reconcile of that attempt; a new key only after a
  confirmed retryable failure; no attempt `n+1` under UNKNOWN.
- **Readiness gate:** reserve refuses (422) when not `ready`, before any hold; readiness synced from
  `inspectDestination` (not operator-asserted); provider I/O outside transaction; an account-ref
  change during inspection makes the result stale/no-op; per-provider records and in-flight
  destination snapshot.
- **Reversal:** paid rows remain in the slower reconcile cadence; partial cumulative reversal posts
  only the unseen delta and remains paid; full REVERSED reaches `status=reversed` and restores
  `paid_out`; regressing/over-amount status integrity-fails; no operator reverse endpoint exists.
- **Retry/reconcile/batch:** backoff timing (`next_attempt_at`/`next_reconcile_at`) honored; retry CAS
  increments exactly once immediately before I/O; process death after claim recovers via watchdog;
  max-attempt exhaustion terminalizes + releases the hold; reconcile-before-retry for UNKNOWN; batch
  derives its amount from the locked available balance and concurrent workers cannot duplicate that
  amount; one failing seller doesn't abort the rest. A paid payout's provider-state regression is an
  integrity finding and never changes ledger money.
- **Provider reconciliation:** Commerce uses only the collector status port; Payvia independently
  detects local-attempt/provider mismatches.
- **Off/continuity:** folded default keeps existing/manual payouts paid; manual `record()` writes its
  method/status/completed time explicitly; provider-unbound inert; regression + zero-query pins.
  Existing manual-payout seller response fields remain byte-identical.
- **Live pgsql races:** payout-vs-refund under the hold (no overdraw); double-finalize idempotency;
  concurrent batch/retry sweeps don't double-transfer (`claimRetryableForAttempt` + per-attempt key).

## 9. File map (commerce; contracts/payvia summarized in §4)
- **Migrations:** `commerce_payouts` columns (fold into `013` per §3.1) + `commerce_seller_payout_accounts`
  (new); `CommerceTestCase::MIGRATIONS`; `DiagnosticsReport`.
- **Marketplace/payout:** `src/Marketplace/PayoutService.php` (saga), `PayoutRepository.php` (CAS+setters),
  `PayoutAccountService.php`, `PayoutAccountRepository.php`, `PayoutOutcomeUnknownException.php`;
  `LedgerRepository.php`/`SellerBalanceService.php` (pending split), `LedgerPostingService.php`
  (reversal), `ReconciliationService.php` (provider scan).
- **Console:** `src/Console/{PayoutsRunBatch,PayoutsRetrySweep,PayoutsReconcileSweep,SyncPayoutAccounts}Command.php`.
- **HTTP:** `src/Http/Admin/AdminPayoutController.php` (execute/retry/account), `src/Http/Seller/
  SellerFinancialController.php` (payout-account readiness read), DTOs, `routes.php`, provider registrations.
- **Config:** `config/commerce.php` (`marketplace.payouts` — per-currency minimums, optional
  per-currency maximums/caps, backoff, max attempts, pending reconcile interval, paid-status reconcile
  interval, default provider). An absent maximum means the batch uses the full locked available
  balance.
- **Tests:** `tests/Integration/Marketplace/{PayoutSagaTest, PayoutRetryReconcileTest, PayoutAccountReadinessTest,
  PayoutReversalTest, PayoutBatchTest, PayoutProviderReconciliationTest, PayoutSagaPgsqlTest}.php`,
  off-regression extensions.

## 10. Source-verified seams
- **Refund saga — RESOLVED:** `RefundService::issueGateway()` commits reserve state before
  `callAndFinalize()` performs I/O; `finalize()` claims the order then `RefundRepository::claimPending()`.
  Throws preserve pending/unknown while returned failures finalize — the exact structural template.
- **Manual payout — RESOLVED:** current `PayoutService::record()` claims `LedgerAccountLock`, re-reads
  available under it, and inserts the row + debit atomically. MV4 preserves that branch and explicitly
  writes manual/paid/completed fields after the folded schema changes.
- **Balance split — RESOLVED:** `LedgerRepository::balanceComponents()` currently performs one
  `entry_type` grouped scan. MV4 extends that same scan with conditional aggregation by
  `payout_uuid IS NULL/NOT NULL`; it does not add per-component queries.
- **Payvia capability wiring — RESOLVED:** `GatewayManager::supports()` is an interface-based match and
  needs a `payout` branch plus a typed `payoutGateway()` accessor. `PayviaServiceProvider::services()`
  already binds cross-extension collectors by contract id. `TransferCapableGateway` is additive.
- **Provider API constraints — RESOLVED:** Stripe transfer retrieval is provider-id-based and reports
  cumulative partial reversal; Paystack verifies by transfer reference, can return `pending`/`otp`,
  and constrains reference characters. These facts drive §2.1/§2.2/§2.5 and the pre-I/O attempt table.
- **Contracts template/version — RESOLVED:** `RefundCollector`/`RefundResult` are the interface/VO
  template. Repository tag `v1.4.0` exists, but `extra.glueful.version` still says `1.3.0`; the 1.5.0
  release repairs it.
- **Commands/scheduling — RESOLVED:** Commerce's provider calls `discoverCommands()` for `src/Console`;
  host `config/schedule.php` owns cron registration. MV4 adds commands only, no extension scheduler.
- **Migration placement — RESOLVED:** Commerce `v1.1.0` includes migrations `001–009`; marketplace
  `010–013` are unreleased. Provider columns fold into `013`; payout accounts use new `014`.
- **Release chain — RESOLVED:** contracts 1.5.0 → Payvia 2.1.0 → Commerce 1.2.0, vendor-first local
  wiring during implementation.
