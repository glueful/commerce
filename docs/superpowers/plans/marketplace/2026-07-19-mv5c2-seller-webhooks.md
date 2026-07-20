# Marketplace MV5c-2 — Seller Outbound Webhooks — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Reliable, secure, per-seller-isolated outbound delivery of a seller's own marketplace events — durable transactional outbox, signed payloads, strict SSRF, retries/dead-letter/replay, MV5b suspend-pause/close-disable — reusing framework signing + SSRF + queue primitives; commerce owns all seller semantics.

**Architecture:** A framework `SafeOutboundTargetResolver` (extracted from `Client`'s private SSRF validation) owns backwards-compatible safe-fetch and strict-webhook profiles over one core. Commerce state-changing services write the event snapshot + per-endpoint delivery rows **inside their own transaction** (`SellerWebhookOutboxPublisher`) — no committed-state-without-outbox window; the queue is only a wake-up hint and a recovery sweep is the durability backstop. A lease-based delivery worker signs the exact stored bytes (`WebhookSignature`), pins the SSRF-validated IP once immediately before connection, classifies retry/terminal → dead-letter, and auto-disables on consecutive failures. Suspension/endpoint-disable pause independently; endpoint-enable resumes after SSRF validation; deletion/closure cancel; management is JWT-interactive-only.

**Tech Stack:** PHP 8.3, Glueful framework (HTTP client/queue/EncryptionService/WebhookSignature), PostgreSQL + SQLite lanes.

**Authoritative spec:** `docs/superpowers/specs/marketplace/2026-07-19-mv5c2-seller-webhooks-design.md` — every task's requirements implicitly include it; §-refs point into it.

## Global Constraints

- **Release chain framework 1.71.0 → commerce 1.2.0** (both unpublished). NO contracts/payvia change. **Do NOT bump any version or composer pin, or create any tag** (release is the USER's step). The framework `SafeOutboundTargetResolver` (Task 1) joins the pending framework 1.71.0 (framework HEAD `b04e924` — dispatchOrFail + rotate). Commerce bundles into 1.2.0 with new **migration 019**; migrations `010`–`019` first publish together.
- **Per-repo commits on `dev`.** framework `/Users/michaeltawiahsowah/Sites/glueful/framework` (HEAD `b04e924`); commerce `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce` (HEAD `53d3ffa`). Explicit `git add <paths>` only. **Never stage `docs/superpowers/**` or `.superpowers/**`.** No AI/Anthropic attribution, no trailer.
- **Vendor-first dev:** after Task 1, mirror the framework changes into `commerce/vendor/glueful/framework/src/Http/` (and any new file) — LOCAL COMPILE AID, NEVER staged, never bump the framework pin.
- **Security invariants (load-bearing):**
  - **Per-seller ISOLATION:** each delivery payload carries ONLY that seller's own resource data (allow-list projection, never a raw row spread) — never another seller's data, buyer PII beyond what the seller owns, marketplace-internal fields, or any secret. Multi-seller events fan out to isolated per-seller snapshots.
  - **Encrypted secrets:** the signing secret is stored ENCRYPTED via `EncryptionService::encrypt($secret, aad: "{tenant}:{endpoint}:{secret}")` (AAD endpoint-bound); returned in plaintext EXACTLY once at create/rotate; never surfaced by any read/payload/header/log/audit. Hash-only is rejected (signing needs the raw secret).
  - **Strict SSRF:** webhook profile is https-only; resolve all A/AAAA + reject any blocked address; RE-RESOLVE + IP-pin ONCE immediately before each connection through `safeWebhookRequestAsync()` (no check-then-second-resolution gap); no redirects; no ambient cookies/proxy/creds; internal addresses NEVER in seller-facing errors; a safety failure is terminal. Existing safe-fetch callers retain their current http/https + `allow_private_hosts` behavior through a separate resolver method.
  - **Transactional outbox:** the snapshot + delivery rows commit or roll back WITH the authoritative state change; the queue is a hint; the sweep repairs lost wake-ups, never a missing outbox write.
  - **Sign the EXACT stored bytes** with the current secret; stable `delivery_uuid`+`event_id` headers for receiver dedup.
  - **JWT-interactive-only management** (`auth_provider==='jwt'` + no `api_key_uuid`, InteractiveSessionMiddleware before `commerce_seller:...webhooks.manage`); `webhooks.manage` excluded from the MV5c-1 key catalog — a key can never manage webhooks.
  - **Lock order seller revision → endpoint revision → delivery claim** across publisher/management/lifecycle/worker. Candidate discovery is unlocked; the claim transaction re-reads seller+endpoint BEFORE the delivery CAS and claim commit is the in-flight linearization point. `delivering` carries a token+lease and stale finalizers cannot overwrite a reclaimed attempt. Seller suspension and endpoint disable use distinct pause reasons; close/delete cancel (not replayable).

---

## Package 0 — framework 1.71.0 (repo `/Users/michaeltawiahsowah/Sites/glueful/framework`, branch `dev`, HEAD `b04e924`)

### Task 1: `SafeOutboundTargetResolver` + `ResolvedOutboundTarget` (extract the SSRF policy; share with `safeRequest`)

**Files:** create `src/Http/Security/SafeOutboundTargetResolver.php`, `src/Http/Security/ResolvedOutboundTarget.php` (paths per repo convention); modify `src/Http/Client.php` (`safeRequest`/`safeRequestAsync`/the private `assertSafeFetchUrl` → delegate to the resolver); tests under `tests/`.

**Interfaces:** one resolver, two explicit methods over a shared private parse/canonicalize/resolve core:
- `resolveSafeFetch(string $url, bool $allowPrivateHosts=false): ResolvedOutboundTarget` preserves today's http/https + private-host override behavior byte-for-byte. Existing `Client::safeRequest()`/`safeRequestAsync()` delegate here.
- `resolveWebhook(string $url): ResolvedOutboundTarget` requires HTTPS; owns URL-host/IDNA canonicalization; rejects credentials, fragments, non-default ports, IP literals, malformed IDNs, ambiguous host encodings; resolves ALL A/AAAA and rejects if ANY is blocked. New `Client::safeWebhookRequestAsync()` invokes it exactly once and installs the returned `{canonicalUrl,host,port,ip}` directly in the resolve map. No tenancy `HostNormalizer`, no check-then-second-resolution gap.

- [ ] **Step 1: RED** — `resolveWebhook` accepts a public https URL → returns the pinned target; rejects http, credentials (`user:pass@`), fragments, non-default port, IP-literal host, private/loopback/link-local/metadata/reserved (v4 AND v6), malformed IDN; a hostname with MULTIPLE A records where one is private → rejected. `safeWebhookRequestAsync` resolves once and pins that exact address. Existing `safeRequest`/`safeRequestAsync` still accept their current HTTP/private-host cases and remain pin+no-redirect through `resolveSafeFetch`.
- [ ] Steps 2-4: FAIL → implement → GREEN; framework phpcs/analyze clean.
- [ ] **Step 5: COMMIT (framework)** — explicit add of the 2 new files + Client + tests → `feat(http): extract SafeOutboundTargetResolver shared by safeRequest for SSRF-safe outbound`. No version bump, no tag.

---

## Package 1 — commerce 1.2.0 (repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce`, branch `dev`, HEAD `53d3ffa`)

> **STEP 0 for the first commerce task:** mirror the Task-1 framework files into `commerce/vendor/glueful/framework/src/Http/...` (never staged).

### Task 2: Schema — migration 019 (5 tables) + role capability + catalog exclusion + config + diagnostics  *(NO commit — Commerce commit 1 lands after Task 3)*

**Files:** create `migrations/019_CreateSellerWebhookTables.php`, `src/Marketplace/SellerWebhookEventCatalog.php`; modify `src/Marketplace/FixedSellerRoleAuthority.php`, `src/Marketplace/SellerApiKeyCapabilityCatalog.php` (exclude `webhooks.manage`), `config/commerce.php`, `src/Support/DiagnosticsReport.php`, `tests/Support/CommerceTestCase.php`, the exact-list pin (`CatalogBreadthTenancyTest`, currently 40), `tests/Integration/Tenancy/TenantAdopterTest.php`; create `tests/Integration/Migrations/SellerWebhookShapeTest.php`.

**Schema (§3 — 5 NEW tenant-scoped tables, all `tenant_uuid` default `''`, explicit short index names):** `commerce_seller_webhook_endpoints` (status active|disabled|deleted + deleted_at), `commerce_seller_webhook_secrets` (secret_ciphertext, relationship current|previous, overlap_expires_at — exactly-one-current invariant service-enforced), `commerce_seller_webhook_events` (uuid=event_id, canonical payload bytes), `commerce_seller_webhook_deliveries` (status `pending|paused|delivering|delivered|dead_letter|canceled`, attempts, next_attempt_at, pause fields+reason, claim_token+claim_expires_at, replay_of_uuid), `commerce_seller_webhook_endpoint_events` (append-only audit). Exact columns/uniques/indexes per §3, including expired-claim sweep index.
- `FixedSellerRoleAuthority`: `WEBHOOKS_MANAGE='commerce.seller.webhooks.manage'` (owner+admin). `SellerApiKeyCapabilityCatalog`: EXCLUDE it. `SellerWebhookEventCatalog::all()` = the 9 v1 slugs (§2.3) + `contains()`.
- config `marketplace.webhooks.*` (max_attempts, backoff base/cap, jitter, consecutive_failure_disable_threshold, secret_overlap_hours, delivery_timeout, claim_lease_seconds validated > delivery_timeout, max_response_bytes, retention_days, sweep_batch_size).
- DiagnosticsReport + CommerceTestCase MIGRATIONS + pin (40→45) + TenantAdopter cover ALL 5.

- [ ] **Step 1: RED** `SellerWebhookShapeTest` — all 5 tables' columns/types/defaults/uniques/indexes (introspection, SQLite + gated pgsql); endpoint deleted fields; delivery pause-reason + claim-token/lease fields and expired-claim index; `webhooks.manage` on owner+admin only AND excluded from `SellerApiKeyCapabilityCatalog`; `SellerWebhookEventCatalog::all()` = the 9 slugs; DiagnosticsReport/adopter cover all 5; re-run 019 no-op.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates. **NO commit.**

### Task 3: Endpoint management service (register/update/rotate-secret/disable/enable/delete) + encrypted secret + SSRF-at-registration  *(Commerce commit 1)*

**Files:** create `src/Marketplace/SellerWebhookEndpointRepository.php`, `src/Marketplace/SellerWebhookDeliveryRepository.php` (management/lifecycle primitives first; Task 4 extends it for outbox inserts), `src/Marketplace/SellerWebhookEndpointService.php`, `src/Marketplace/SellerWebhookSecretService.php`, `src/Marketplace/SellerWebhookException.php`; modify `src/CommerceServiceProvider.php`; test `tests/Integration/Marketplace/SellerWebhookEndpointTest.php`.

**Interfaces (§2.2/§2.6/§2.10):** all mutations claim the **seller revision → endpoint revision**, re-read the actor's live membership + `webhooks.manage` (403 else), and validate the URL via the framework `SafeOutboundTargetResolver::resolveWebhook()` (validate-only; discard the resolved IP). subscribed_events ⊆ `SellerWebhookEventCatalog` (422 else). One txn per mutation with the audit row.
- `register(c,tenant,sellerUuid,url,events,actor): array` — SSRF-validate; insert endpoint (revision 0, status active) + first secret (generate a strong random secret, store `EncryptionService::encrypt($secret, aad:"{tenant}:{endpoint}:{secretUuid}")`, relationship current) + `register` audit; return endpoint + raw secret ONCE.
- `updateEndpoint(...)` (url/events; url change re-validates SSRF; never returns secret), `rotateSecret(...)` (→ new secret once; move current→previous with `overlap_expires_at = now + secret_overlap_hours`, retire older previous, insert successor current — one endpoint-revision txn), `disable(...)`, `enable(...)`, `delete(...)`. Disable pauses the endpoint's pending work with `pause_reason=endpoint_disabled`; enable re-runs `resolveWebhook()` on the stored URL, resets failures, and resumes only endpoint-disabled rows. Delete is a tombstone (`status=deleted`, deleted_at), revokes secrets, cancels pending/paused, retains history, and cannot be enabled. Management refused while seller suspended.
- `SellerWebhookSecretService::currentSecretPlain(endpoint)` decrypts via AAD; there is no sender-side `secretsForVerification()` API. NEVER expose plaintext in reads.

- [ ] **Step 1: RED** `SellerWebhookEndpointTest` — register SSRF-validates (rejects http/private/credentials/IP-literal) + returns secret once + stores ciphertext (decryptable with the AAD, plaintext never in the row); a non-catalog event → 422; live-authority under seller revision (demoted/removed actor or suspended seller → refused); rotate-secret produces a new secret once + a current+previous pair with overlap, older previous retired; reads/list never return a secret; url update re-validates SSRF; disable pauses pending work; enable SSRF-revalidates + resumes only endpoint-disabled work; delete tombstones/revokes/cancels while retaining history; all mutations audited.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates.
- [ ] **Step 5: COMMIT (Commerce 1)** — explicit add of migration 019, catalogs, config, diagnostics/adopter/pin, shape test, the repos/services/exception, provider, endpoint test → `feat(marketplace): mv5c-2 seller webhook schema, endpoints, and encrypted secrets`.

### Task 4: Event attribution + per-seller isolation + transactional outbox publisher (SECURITY CORE)  *(Commerce commit 2)*

**Files:** create `src/Marketplace/SellerWebhookOutboxPublisher.php`, `src/Marketplace/SellerWebhookPayloadProjector.php`, `src/Marketplace/SellerWebhookEventRepository.php`; extend `SellerWebhookDeliveryRepository` from Task 3 with outbox/claim methods; modify the REAL v1 transaction owners: `src/Orders/CheckoutService.php` (order.placed), `src/Orders/OrderPaymentService.php` (order.paid), `src/Http/Admin/AdminOrderController.php` + `src/Orders/ExpiryService.php` (both order.canceled paths), `src/Marketplace/SellerOrderFulfillmentService.php` (seller_order.fulfilled), `src/Orders/Refunds/RefundService.php` (refund.completed), `src/Marketplace/PayoutService.php` (payout.recorded at manual-paid insert AND winning provider-paid finalizer), `src/Marketplace/SellerAttributionService.php` (product.adopted, product.transferred), and `src/Inventory/InventoryService.php::adjust()` (stock.adjusted; NOT checkout/refund/cancel/expiry movements). Test `tests/Integration/Marketplace/SellerWebhookOutboxTest.php`.

**Interfaces (§2.3/§2.4):**
- `SellerWebhookOutboxPublisher::capture(ApplicationContext $c, string $tenant, string $eventType, array $context): void` — called INSIDE the authoritative txn. Resolve participating sellers + build each **sanitized per-seller payload** via `SellerWebhookPayloadProjector`. Claim participating seller revisions in sorted order (or reuse caller-held claims through the shared claim helper), re-read lifecycle status, then probe matching ACTIVE endpoint revisions. If none, write NOTHING. If matched: insert one event snapshot per seller + one delivery per endpoint. Active seller → `pending,next_attempt_at=DB_NOW`; suspended seller → `paused,pause_reason=seller_suspended,paused_remaining_seconds=0`. Register queue hints after commit only for pending rows.
- **Event semantics:** `order.canceled` captures both operator and expiry transitions; `payout.recorded` captures the first `paid` state for manual and provider payouts (CAS winner only); `stock.adjusted` is direct `InventoryService::adjust()` only. Checkout stock decrements and refund/cancel/expiry restocks do NOT generate duplicate stock events.
- **Suspension race:** publisher seller claims serialize with MV5b. Suspension-first → capture sees suspended and writes paused. Capture-first → suspension waits, then includes the committed/new rows in its pause set. The plan must verify each caller's pre-existing lock order before inserting capture; no caller may acquire a seller lock after an incompatible later-ranked lock.
- **Off-invariance:** marketplace master-off ⇒ the publisher is a config-only no-op (zero query); active marketplace + no matching endpoint ⇒ at most one indexed probe, zero writes/queue.
- The projector is the ISOLATION boundary — a fresh named-key array per seller, never a raw row spread; no other seller's data, no buyer PII beyond owned, no secrets, no internal fields.

- [ ] **Step 1: RED** `SellerWebhookOutboxTest` — every real insertion point above is exercised (including BOTH cancellation paths and BOTH payout-paid paths); capture writes only for matching endpoints; multi-seller poison isolation + transfer-out/in; direct adjustment emits stock.adjusted while checkout/refund/cancel/expiry stock movements do not; suspended capture starts paused; both capture-vs-suspension orderings; injected outbox failure rolls back the business transition; master-off zero query / active-no-endpoint one probe; lost afterCommit enqueue leaves recoverable pending.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates.
- [ ] **Step 5: COMMIT (Commerce 2)** — explicit add of the publisher/projector/repos + every modified state-changing service + the test → `feat(marketplace): transactional per-seller webhook outbox with isolation`.

### Task 5: Signed SSRF-safe delivery worker + retry/dead-letter/auto-disable + recovery sweep  *(Commerce commit 3)*

**Files:** create `src/Marketplace/SellerWebhookDeliveryService.php`, `src/Queue/Jobs/DeliverSellerWebhookJob.php` (extends framework `Job`), `src/Console/SweepSellerWebhooksCommand.php`; extend the delivery repository; modify `src/CommerceServiceProvider.php`; test `tests/Integration/Marketplace/SellerWebhookDeliveryTest.php`.

**Interfaces (§2.5/§2.6/§2.7):**
- **Claim protocol:** select a candidate without locking. In one short transaction claim seller revision → endpoint revision → delivery CAS; re-read seller active + endpoint active BEFORE the CAS; set `delivering`, random `claim_token`, `claim_expires_at=DB_NOW+claim_lease_seconds`, attempts+1, last_attempt_at; commit. Claim commit is the in-flight linearization point. `claim_lease_seconds` must exceed HTTP timeout. A suspension/disable/close that wins first prevents claim; one that commits after claim treats it as in flight.
- Sign the EXACT stored snapshot bytes with the CURRENT secret via `WebhookSignature::generate($bytes, $currentSecretPlain, $ts)`; headers carry `delivery_uuid`, `event_id`, event-type, schema-version. Secret decrypted via AAD; never logged.
- POST via the locked webhook client's `safeWebhookRequestAsync()` — strict webhook resolver, one resolve+pin, no redirects, short timeouts, capped response consumption. A safety failure = terminal.
- **Token-checked finalize:** every success/failure update uses `WHERE status='delivering' AND claim_token=?`; stale workers no-op. 2xx → delivered. Retryable (network/timeout/408/425/429/5xx) → pending/backoff until max → dead_letter. Terminal → dead_letter immediately. Clear claim fields on accepted finalize; never let a stale finalizer reset counters or endpoint state.
- **Auto-disable:** accepted failure finalization claims seller → endpoint → delivery/token; threshold flips endpoint disabled + audit and pauses its OTHER pending deliveries with `pause_reason=endpoint_disabled`/remaining delay. Success resets failures only if endpoint remains active. One endpoint never blocks another.
- `SweepSellerWebhooksCommand`: select expired candidates unlocked, then reclaim each through seller revision → endpoint revision → delivery token/expiry CAS; return eligible rows to due pending and clear claim fields (or pause/cancel if lifecycle now requires it), then enqueue due pending rows. An expired claim counts as the already-incremented attempt because the request may have escaped before the worker died. Batch-limited, tenant-safe.

- [ ] **Step 1: RED** `SellerWebhookDeliveryTest` (fake HTTP responses) — candidate→seller→endpoint→delivery claim order; suspension-before-claim refuses while claim-commit-first is in-flight; 2xx/token finalize; retry/dead-letter/Retry-After; strict `safeWebhookRequestAsync`; expired delivering lease reclaimed; old-token finalizer cannot overwrite reclaimed/new attempt; auto-disable audits + pauses sibling pending rows (no sweep churn); exact bytes/current secret; sweep handles both lost pending wake-up and expired claim.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates.
- [ ] **Step 5: COMMIT (Commerce 3)** — explicit add of the delivery service/job/sweep command + repo + provider + test → `feat(marketplace): signed SSRF-safe webhook delivery with retry, dead-letter, and auto-disable`.

### Task 6: Replay + seller/endpoint lifecycle (reasoned pause, reinstate/enable, tombstone/close)  *(Commerce commit 4)*

**Files:** extend `SellerWebhookDeliveryService` (replay) + the delivery repository (pause/resume/cancel) + wire into MV5b `SellerService::suspend`/`reactivate`/`close`; test `tests/Integration/Marketplace/SellerWebhookLifecycleTest.php`.

**Interfaces (§2.8/§2.9):**
- **Suspend:** under seller revision → sorted endpoint revisions → delivery claims, move only `pending` to `paused,pause_reason=seller_suspended`, persisting remaining delay. Existing `endpoint_disabled` pauses stay distinct. New events captured while suspended already enter seller-paused.
- **Reactivate:** restore only `pause_reason=seller_suspended` to pending using DB-time + remaining delay; endpoint-disabled rows remain paused until endpoint enable. Preserve attempts and deterministic ordering.
- **Endpoint disable/enable/delete:** Task 3 service uses the same pause/resume primitives. Disable pauses pending with endpoint reason; enable SSRF-revalidates then resumes only endpoint reason; delete tombstones/revokes/cancels and never re-enables.
- **Close:** disable all endpoints + `canceled` all pending/paused deliveries (retained for audit, NOT replayable). An in-flight HTTP request started before the transition may finish + record; no cancellation of in-flight.
- **Replay** (`SellerWebhookDeliveryService::replay`): a JWT-interactive seller with `webhooks.manage` replays a `dead_letter` delivery → a NEW delivery row `replay_of_uuid`=original, referencing the same event snapshot, status `pending` — WITHOUT mutating the original's history. Only while seller+endpoint eligible; `canceled` never replayable.
- Lock order seller revision → endpoint revision → delivery claim throughout.

- [ ] **Step 1: RED** `SellerWebhookLifecycleTest` — seller and endpoint pause reasons never overwrite/resume each other; suspend/reactivate remaining-delay math; disable/enable SSRF recheck and resume; delete tombstone retains history/revokes secret/cancels/non-replayable; close cancels all pending/paused + disables endpoints; due paused never delivers; dead-letter replay creates new lineage; canceled refused.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates.
- [ ] **Step 5: COMMIT (Commerce 4)** — explicit add of the delivery service/repo lifecycle + SellerService wiring + test → `feat(marketplace): webhook suspend-pause, reinstate restore, close-cancel, and replay`.

### Task 7: JWT-interactive-only management surface  *(NO commit — Commerce commit 5 lands after Task 8)*

**Files:** create `src/Http/Seller/SellerWebhookController.php` (+ DTOs); modify `routes.php`, `src/CommerceServiceProvider.php`; test `tests/Integration/Marketplace/SellerWebhookSurfaceTest.php`. Reuse the existing `InteractiveSessionMiddleware` (MV5c-1).

**Interfaces (§5):** routes under the seller group, `commerce_seller:commerce.seller.webhooks.manage`, own-seller-only, **`interactive_session` middleware BEFORE `commerce_seller:...`** (JWT-only; an api-key/non-JWT request → 403 on every route): `POST /{sellerUuid}/webhooks` (secret once), `GET` (list, no secret/deleted tombstones), `PATCH /{uuid}` (url re-validates SSRF), `POST /{uuid}/rotate-secret` (secret once), `POST /{uuid}/disable`, `POST /{uuid}/enable`, `DELETE /{uuid}` (tombstone), `GET /{uuid}/deliveries` (sanitized retained history), `POST /{uuid}/deliveries/{deliveryUuid}/replay` (dead-letter only). Management + replay refused while suspended. Map SellerWebhookException/validation → 422, not-found/deleted → 404, incompatible state → 409, SSRF-reject → 422 (no internal address), JWT gate → 403.

- [ ] **Step 1: RED** `SellerWebhookSurfaceTest` (real routes) — a JWT session with webhooks.manage does register/list/rotate/disable/enable/delete/deliveries/replay; enable revalidates SSRF; deleted endpoint disappears from list but retained history remains internally coherent and mutation returns non-revealing 404; secret once only; API-key/non-JWT/capability/cross-seller/cross-tenant/suspended refusals; no secret/internal-address leakage.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates. **NO commit.**

### Task 8: Gates — isolation/SSRF/reliability/lifecycle, live pgsql races, retention, CHANGELOG  *(Commerce commit 5)*

**Files:** extend `tests/Integration/Marketplace/MarketplaceRegressionTest.php`; create `tests/Integration/Marketplace/SellerWebhookPgsqlTest.php` (+ fixture child; mirror SellerSuspensionPgsqlTest); create `src/Console/PurgeSellerWebhooksCommand.php` (retention) + register; modify `tests/Integration/Http/HttpDocumentationTest.php` (new routes); `CHANGELOG.md`.

- [ ] **Off-invariance + isolation regression:** master-off ⇒ zero webhook queries + byte-identical; active + no endpoint ⇒ one probe no writes; the isolation poison-string proof at the branch level; 5 tables adopt/scope per tenant.
- [ ] **Retention:** purge delivered + dead_letter + canceled deliveries and orphan snapshots after retention; keep pending/paused/delivering (including expired claims for sweep recovery); retain endpoint tombstones/audit per their longer audit policy; tenant-safe.
- [ ] **Live pgsql (`COMMERCE_TEST_DB_DRIVER=pgsql`, run live, paste verbatim), BOTH orderings:** (a) delivery claim vs seller suspension; (b) capture vs suspension; (c) rotate secret vs in-flight; (d) management vs suspension; (e) expired-claim reclaim vs stale finalize token; (f) auto-disable vs endpoint enable. Assert seller→endpoint→delivery ordering. Migration/index shape live, rerun 019 no-op.
- [ ] **CHANGELOG `[Unreleased]`:** MV5c-2 seller outbound webhooks — isolated transactional outbox; encrypted AAD secrets; strict webhook resolver profile + backwards-compatible safe-fetch profile; lease-recoverable exact-byte delivery; retry/dead-letter/replay/auto-disable; reasoned seller/endpoint pause + enable and tombstone/close cancel; JWT-only management. Note framework 1.71.0 → commerce 1.2.0, migration 019. No version/pin bump.
- [ ] **COMMIT (Commerce 5)** — explicit add of SellerWebhookController + DTOs, routes.php, provider, PurgeSellerWebhooksCommand, SellerWebhookSurfaceTest, MarketplaceRegressionTest, SellerWebhookPgsqlTest, HttpDocumentationTest, CHANGELOG → `feat(marketplace): mv5c-2 seller webhook surface, gates, and delivery races`. Then whole-branch review of the MV5c-2 commerce range.

---

## Self-Review notes
- **Spec coverage:** §2.1→T1+all; §2.2→T3; §2.3→T4; §2.4→T4; §2.5→T5; §2.6→T1+T3+T5; §2.7→T5; §2.8→T6; §2.9→T6; §2.10→T7; §3→T2; §4→T1; §5→T7; §6→T4+T8; §7→per-task+T8; §8→resolved.
- **Invariant-bearing subtleties for reviewers:** (a) per-seller isolation + exact real insertion points (T4); (b) encrypted AAD secret + tombstone-safe lifecycle (T3); (c) transactional outbox + capture-vs-suspension serialization (T4); (d) strict webhook profile and backwards-compatible safe-fetch profile, each one-resolve+pin (T1/T5); (e) exact stored bytes/current secret/overlap (T5); (f) leased delivering claims + token finalization + expired recovery (T5); (g) retry/dead-letter/auto-disable pauses sibling work and explicit enable resumes (T3/T5/T6); (h) seller-vs-endpoint pause reasons, close/delete cancel, seller→endpoint→delivery ordering (T6); (i) JWT-only management/key-catalog exclusion (T7); (j) master-off zero query / active one-probe (T4/T8).
- **Release:** framework 1.71.0 (Task 1, joins dispatchOrFail+rotate) → commerce 1.2.0 (migration 019); no version/pin bump; vendor-mirror the framework change locally. No contracts/payvia.
