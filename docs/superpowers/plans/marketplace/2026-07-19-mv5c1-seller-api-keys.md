# Marketplace MV5c-1 — Seller API Keys — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Seller-scoped machine credentials — a seller self-issues scoped API keys to call its own marketplace API, reusing the framework key machinery, with commerce owning the binding + a three-way effective-scope intersection revalidated every request.

**Architecture:** Framework `ApiKeyService` owns all crypto (gen/hash/verify/rotate/revoke/prefix/expiry). Commerce owns two tables — a claimable logical **lineage** (`commerce_seller_api_keys`) + one **credential** row per generated framework key (`commerce_seller_api_key_credentials`) for O(1) `api_key_uuid`→lineage resolution (no framework chain walk) — plus an append-only audit. A **seller-key authorizer** at the existing `SellerMemberMiddleware` choke point enforces principal-integrity, exact-scope-match, one-seller binding, and the effective-scope gate; rotation/revocation serialize on the lineage revision.

**Tech Stack:** PHP 8.3, Glueful framework, PostgreSQL + SQLite test lanes.

**Authoritative spec:** `docs/superpowers/specs/marketplace/2026-07-19-mv5c1-seller-api-keys-design.md` — every task's requirements implicitly include it; §-refs point into it.

## Global Constraints

- **Release chain framework 1.71.0 → commerce 1.2.0** (both unpublished). NO contracts/payvia change. **Do NOT bump any version or composer pin, or create any tag** (release is the USER's step). The framework `rotate()` change (Task 1) joins the already-committed framework 1.71.0 `dispatchOrFail` seam (framework HEAD `9991069`). Commerce bundles into 1.2.0 with a NEW **migration 018**; migrations `010`–`018` first publish together.
- **Per-repo commits on `dev`.** framework repo `/Users/michaeltawiahsowah/Sites/glueful/framework`; commerce repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce` (HEAD `f413484`). Explicit `git add <paths>` only (never `-A`/`.`). **Never stage `docs/superpowers/**` or `.superpowers/**`.** No AI/Anthropic attribution, no `Co-Authored-By`, no trailer.
- **Vendor-first dev:** commerce compiles against the framework `rotate()` change — after Task 1, mirror the updated `src/Auth/ApiKey/ApiKeyService.php` into `commerce/vendor/glueful/framework/src/Auth/ApiKey/ApiKeyService.php` (LOCAL COMPILE AID; vendor/ git-ignored; NEVER staged; never bump the framework pin).
- **Security invariants (load-bearing — a stolen key must never escalate):**
  - **Effective access = validated declared key scopes ∩ subject user's live seller-role capabilities ∩ the api_key_grantable catalog**, per request.
  - **≥1 explicit exact-capability-slug scope required; EMPTY forbidden** (framework reads empty as unrestricted); **WILDCARDS forbidden** (`*`, `commerce.seller.*`, any fnmatch) — rejected at write, fail-closed at read.
  - **Never grantable to a key:** `apikeys.manage`, `members.manage`, any payout-**execution** capability, other credential/ownership/policy-admin.
  - **Principal never replaced:** commerce validates `request user_id === lineage subject_user_uuid`; a mismatch fail-closes (auth_denied), never overwrites the authenticated `user`.
  - **Framework scope copy MUST exactly equal the binding scope copy** (after canonical normalization); drift fail-closes.
  - **One-seller:** route `{sellerUuid}` must equal `lineage.seller_uuid`; no seller header.
  - **Unbound API keys deny:** an API-key request without a Commerce credential binding is refused on seller routes even if its framework user has seller membership. Only non-key requests no-op.
  - **Management is JWT-interactive-ONLY:** exact predicate `auth_provider === 'jwt'` and no `api_key_uuid`; route order puts this before `commerce_seller:*`.
  - **Live authority + lock order:** create/rotate/revoke never accept caller subject/role. They claim seller revision, re-read actor membership/capability and active seller, then (rotate/revoke) claim lineage revision.
  - **Mutations atomic:** framework key + Commerce lineage/credential + audit commit or roll back together.
  - **No secrets persisted or logged** by commerce (beyond what the framework stores); raw secret returned exactly once.

---

## Package 0 — framework 1.71.0 (repo `/Users/michaeltawiahsowah/Sites/glueful/framework`, branch `dev`, HEAD `9991069`)

### Task 1: `ApiKeyService::rotate()` — return `new_uuid` + shorten-not-extend predecessor expiry

**Files:** modify `src/Auth/ApiKey/ApiKeyService.php` (`rotate()`); test under `tests/` (mirror existing ApiKey tests).

**Interfaces:** `rotate(context, existing, graceHours=24)` return array gains **`'new_uuid' => $newKey->uuid`** (keep the existing `old_uuid`/`new_plain`/`old_expires_at` keys — additive). The predecessor expiry becomes **`min(existing_expires_at, now+grace)`** instead of `now+grace`: if `existing->expires_at` is null → use `now+grace`; else use the earlier of the two. Update `old_expires_at` in the return to the actually-applied value. The `EntityUpdatedEvent` audit reflects the applied expiry.

- [ ] **Step 1: RED** — (a) `rotate()` returns a `new_uuid` equal to the successor key's uuid; (b) an EARLIER existing expiry is never extended and still limits predecessor + successor; (c) a LATER/null predecessor expiry is shortened to the grace deadline while the successor preserves the original expiry; (d) with no earlier original expiry, both keys verify during grace.
- [ ] Steps 2-4: FAIL → implement → GREEN; framework phpcs/analyze clean.
- [ ] **Step 5: COMMIT (framework)** — `git add src/Auth/ApiKey/ApiKeyService.php <test>` → `feat(auth): api-key rotate returns successor uuid and never extends predecessor expiry`. No version bump, no tag.

---

## Package 1 — commerce 1.2.0 (repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce`, branch `dev`, HEAD `f413484`)

> **STEP 0 for the first commerce task:** mirror the Task-1 updated `ApiKeyService.php` into `commerce/vendor/glueful/framework/src/Auth/ApiKey/ApiKeyService.php` (never staged).

### Task 2: Schema — migration 018 (3 tables) + role authority + config + diagnostics + shape test  *(NO commit — Commerce commit 1 lands after Task 3)*

**Files:** create `migrations/018_CreateSellerApiKeysTables.php`, `src/Marketplace/SellerApiKeyCapabilityCatalog.php`; modify `src/Marketplace/FixedSellerRoleAuthority.php`, `config/commerce.php`, `src/Support/DiagnosticsReport.php`, `tests/Support/CommerceTestCase.php`, the exact-list tenant-table pin (`CatalogBreadthTenancyTest`), `tests/Integration/Tenancy/TenantAdopterTest.php`; create `tests/Integration/Migrations/SellerApiKeyShapeTest.php`.

**Schema (§3, verbatim) — 3 tenant-scoped tables (all `tenant_uuid` default `''`):**
- `commerce_seller_api_keys` (lineage): id PK; uuid varchar(12) (= lineage identity); tenant_uuid v12; seller_uuid v12; subject_user_uuid v12; declared_scopes text (canonical JSON); name varchar(120); status varchar(16) default `active` (`active|revoked`); current_credential_uuid varchar(12); expires_at timestamp NULL; revision int default 0; created_by v12; created_at/updated_at; last_rotated_at/revoked_at NULL. Unique `(tenant,uuid)`, `(tenant,current_credential_uuid)`; index `(tenant,seller_uuid,status)`.
- `commerce_seller_api_key_credentials`: id PK; uuid v12; tenant_uuid v12; lineage_uuid v12; framework_key_uuid **varchar(12)** (matches `api_keys.uuid`); generation int; relationship varchar(16) (`current|predecessor|revoked`); grace_expires_at NULL; created_at; revoked_at NULL. Unique `(tenant,uuid)`, `(tenant,framework_key_uuid)`, `(tenant,lineage_uuid,generation)`; index `(tenant,lineage_uuid)`, `(tenant,lineage_uuid,relationship)`.
- `commerce_seller_api_key_events` (append-only audit): uuid; tenant_uuid; lineage_uuid; seller_uuid; subject_user_uuid; action varchar(16) (`created|rotated|revoked|auth_denied`); actor_uuid NULL; reason_code NULL; bucket_start NULL; predecessor_key_uuid NULL; successor_key_uuid NULL; grace_expires_at NULL; detail NULL; created_at. Unique `(tenant,uuid)` AND `(tenant,lineage_uuid,action,reason_code,bucket_start)` (populated only for `auth_denied`); index `(tenant,seller_uuid,created_at)`, `(action,created_at)`. NO secrets.
- `FixedSellerRoleAuthority`: add `APIKEYS_MANAGE = 'commerce.seller.apikeys.manage'`, granted to owner + admin. `SellerApiKeyCapabilityCatalog` independently owns `{catalog.read, catalog.write, inventory.read, inventory.write, orders.read, orders.fulfill, reports.read, payouts.read}` through `all()`/`contains()`; it excludes credential/member/payout-execution/policy capabilities. Do NOT add `apiKeyGrantable()` to `SellerRoleAuthority` or type callers to `FixedSellerRoleAuthority`.
- `config/commerce.php`: `marketplace.api_keys.auth_denied_retention_days` (env, default 90).
- `DiagnosticsReport` (commerceTables → tenantTables) + `CommerceTestCase::MIGRATIONS` + the exact-list pin + TenantAdopterTest gain ALL THREE tables.

- [ ] **Step 1: RED** `SellerApiKeyShapeTest` — all columns/types/defaults for 3 tables; credentials uniques; audit bucket unique; two permanent mutation events with null reason/bucket both insert (nullable-unique portability), while duplicate same-minute auth_denied collides; owner+admin alone hold apikeys.manage; dedicated catalog excludes apikeys.manage/members.manage; diagnostics/migration/adopter cover all 3; re-run 018 no-op.
- [ ] Steps 2-4: FAIL → implement → GREEN; full `composer test`; phpcs + analyze. **NO commit.**

### Task 3: Lineage/credential repositories + scope validation + CREATE service  *(Commerce commit 1)*

**Files:** create `src/Marketplace/SellerApiKeyRepository.php` (lineage + credentials + events), `src/Marketplace/SellerApiKeyService.php` (create), `src/Marketplace/SellerApiKeyScopeValidator.php`, `src/Marketplace/SellerApiKeyException.php`; modify `src/CommerceServiceProvider.php` (wire seller/membership repositories, role authority, capability catalog); test `tests/Integration/Marketplace/SellerApiKeyCreateTest.php`.

**Interfaces:**
- `SellerApiKeyScopeValidator::validate(array $declared, string $role): array` — injected with `SellerRoleAuthority` + `SellerApiKeyCapabilityCatalog`; canonicalize; reject empty, wildcard/fnmatch, unknown/non-grantable, or not-held-by-live-role scopes. No call to a method absent from `SellerRoleAuthority`.
- `SellerApiKeyService::create(c, tenant, sellerUuid, name, declaredScopes, ?expiresAt, actor): array` — no caller-supplied subject or role. Validate basic name/expiry syntax first; expiry must be UTC and strictly after DB-now. In ONE transaction: claim seller revision → re-read seller active → re-read actor's active seller membership → derive its role and require `apikeys.manage` → validate scopes against that live role → framework create → lineage (subject=actor) → first credential → created audit. Lock order is seller revision before framework/key writes. Any failure rolls everything back.
- `SellerApiKeyRepository`: lineage insert/find; credential insert/find-by-framework-key-uuid/demote/insert; event insert (uuid-collision seam for atomicity proof).

- [ ] **Step 1: RED** `SellerApiKeyCreateTest` — valid live actor succeeds with subject=actor; caller cannot nominate another subject/role; empty/wildcard/non-grantable/not-held/unknown scopes reject; invalid/past/DB-now expiry rejects; forced audit collision rolls back all rows; deterministic claim test proves role/membership/seller are re-read after seller revision and demoted/removed/suspended actor cannot create.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates.
- [ ] **Step 5: COMMIT (Commerce 1)** — explicit add of migration 018, FixedSellerRoleAuthority, SellerApiKeyCapabilityCatalog, config/diagnostics/migration/adopter files, repositories/service/validator/exception/provider, and tests → `feat(marketplace): mv5c-1 seller api-key schema, role catalog, and create`.

### Task 4: Seller-key authorizer at the SellerMemberMiddleware choke point (SECURITY CORE)  *(Commerce commit 2)*

**Files:** modify `src/Http/Middleware/SellerMemberMiddleware.php`; create `src/Marketplace/SellerApiKeyAuthorizer.php`, `src/Marketplace/SellerApiKeyAuthorizationContext.php`; extend `SellerApiKeyRepository` (bounded denial write); test `tests/Integration/Marketplace/SellerApiKeyAuthTest.php`.

**Interfaces (§2.3/§2.4/§2.6/§2.10):**
- `SellerApiKeyAuthorizer::authorize(...)` is called inside `SellerMemberMiddleware` after tenant/route seller extraction but BEFORE seller/membership lookup. It returns null with zero key-table queries only for `auth_method !== 'api_key'`. For every API-key request:
  1. Resolve exact `api_key_uuid` → credential → lineage. Missing Commerce binding is an immediate non-revealing DENIAL, not a no-op, even if the framework principal is an active seller member.
  2. **Principal integrity:** require `request user_id === lineage.subject_user_uuid` (do NOT replace the authenticated `user`). Mismatch ⇒ fail-closed + `auth_denied(principal_mismatch)`.
  3. **Exact scope match:** canonical-normalize `api_key_scopes` (request attr) and require EXACT equality with the lineage `declared_scopes`; drift ⇒ fail-closed + `auth_denied(scope_drift)`.
  4. **One-seller:** route `{sellerUuid}` must equal `lineage.seller_uuid`; mismatch ⇒ non-revealing refusal + `auth_denied(seller_mismatch)`.
  5. **Effective-scope gate:** required capability must be in `declared_scopes ∩ SellerApiKeyCapabilityCatalog`; otherwise 403 + `scope_missing`. Return `SellerApiKeyAuthorizationContext` carrying lineage/credential/subject/seller.
- `SellerMemberMiddleware` then performs existing seller/membership/lifecycle/live-role checks. When context is non-null, it calls exactly `SellerApiKeyAuthorizer::recordDenied(context, reason)` before returning `membership_inactive`, `seller_inactive`, or `capability_denied`; there is no “there or here” branch. A context reaching the handler has passed key scope + live role.
- **Bounded auth_denied audit (§2.10):** written ONLY when framework auth succeeded AND an exact commerce credential binding was found (random/non-commerce keys can't grow the table). Closed reason vocabulary. At most ONE row per `(tenant, lineage, reason, UTC-minute)` via the `bucket_start` unique — a duplicate insert is an idempotent no-op (catch the unique violation). If the audit write fails, access STILL denies (fail-closed) and the failure is error-logged.

- [ ] **Step 1: RED** `SellerApiKeyAuthTest` — valid key succeeds; seller/principal/scope mismatch deny; missing declared capability denies; role reduction denies next request; a non-Commerce framework key whose user IS an active seller member is still denied; membership/seller/role denials use the single context audit path; repeated same-minute denial writes one row; audit failure still denies; session request does zero key-table queries.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates.
- [ ] **Step 5: COMMIT (Commerce 2)** — explicit add of SellerMemberMiddleware, SellerApiKeyAuthorizer, SellerApiKeyRepository, SellerApiKeyAuthTest → `feat(marketplace): seller api-key authorizer with principal, scope, and one-seller enforcement`.

### Task 5: Rotation + revocation (revision-serialized, atomic)  *(Commerce commit 3)*

**Files:** extend `src/Marketplace/SellerApiKeyService.php` (rotate/revoke) + `SellerApiKeyRepository` (revision claim, credential demote/insert/revoke-all); test `tests/Integration/Marketplace/SellerApiKeyRotationTest.php`.

**Interfaces (§2.9), global order `seller revision → fresh manager authority → lineage revision`:**
- `rotate(c, tenant, sellerUuid, lineageUuid, actor): array` — claim seller revision → require seller active + actor active membership with freshly-derived role allowing `apikeys.manage` → claim active lineage revision and verify it belongs to seller → re-read current credential/framework key active+unexpired → framework rotate → demote/insert/advance → audit. Preserve original subject/scopes/expiry even for different manager.
- `revoke(c, tenant, sellerUuid, lineageUuid, actor): array` — same seller/authority/lineage order → revoke every credential → mark rows/lineage revoked → audit. **Unknown/cross-tenant/cross-seller lineage = 404; already revoked = stable no-op with no new event; rotate-revoked = 409.** If the active revision claim affects zero rows, re-read to classify rather than collapsing outcomes.
- Rotate vs revoke serialize on the lineage claim: rotate-first may commit, then the waiting revoke must enumerate and revoke its successor; revoke-first makes the waiting rotate return 409. No active successor survives a committed revoke.

- [ ] **Step 1: RED** `SellerApiKeyRotationTest` — seller revision precedes live manager authority and lineage revision; demoted/removed manager or suspended seller refuses rotate/revoke; successor inheritance/grace/direct resolution; different manager preserves subject; expired/revoked rotate 409; unknown 404; re-revoke no-op/no second event; whole-lineage revoke; forced failure rolls back successor.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates.
- [ ] **Step 5: COMMIT (Commerce 3)** — explicit add of SellerApiKeyService, SellerApiKeyRepository, SellerApiKeyRotationTest → `feat(marketplace): seller api-key rotation and whole-lineage revocation`.

### Task 6: Self-service management surface (JWT-interactive-only)  *(NO commit — Commerce commit 4 lands after Task 7)*

**Files:** create `src/Http/Seller/SellerApiKeyController.php` (+ DTOs), `src/Http/Middleware/InteractiveSessionMiddleware.php`; modify `routes.php`, `src/CommerceServiceProvider.php`; test `tests/Integration/Marketplace/SellerApiKeySurfaceTest.php`.

**Interfaces (§2.8/§5):**
- Routes under the seller group, gated `commerce_seller:commerce.seller.apikeys.manage`, own-seller-only (tenant from resolved admin/session, `{sellerUuid}` from route, never a body tenant/seller):
  - `POST /{sellerUuid}/api-keys` → create (name, declared_scopes ≥1, optional expires_at) → raw secret once.
  - `GET /{sellerUuid}/api-keys` → list lineage metadata (uuid, name, declared_scopes, effective status incl. expiration, expires_at, last_rotated_at, created_by) — NEVER the secret.
  - `POST /{sellerUuid}/api-keys/{lineageUuid}/rotate` → new raw secret once.
  - `POST /{sellerUuid}/api-keys/{lineageUuid}/revoke`.
- **JWT-interactive-only gate (source-resolved):** `InteractiveSessionMiddleware` requires `$request->attributes->get('auth_provider') === 'jwt'` AND absence of `api_key_uuid`. `AuthMiddleware` is the canonical producer; `JwtAuthenticationProvider` does not set `auth_method='jwt'`. Route order is `auth → tenant (when enabled) → interactive_session → commerce_seller:...apikeys.manage`, so every API key/non-JWT provider receives 403 before seller lifecycle/scope handling.
- Map `SellerApiKeyException`/validation → 422, the lineage-not-found/revoked → 404/409, the JWT gate → 403.

- [ ] **Step 1: RED** `SellerApiKeySurfaceTest` — over real routes: a JWT session with `apikeys.manage` can create/list/rotate/revoke (secret returned once on create+rotate, never on list); **an API-key request is 403 on EVERY management route** (create/list/rotate/revoke) even with apikeys.manage in scope; a non-JWT provider is 403; `apikeys.manage` absent → 403; cross-seller/cross-tenant target refused; list never leaks a secret.
- [ ] Steps 2-4: FAIL → implement → GREEN; gates. **NO commit.**

### Task 7: Gates — immediate revalidation, suspension, live pgsql race, retention, CHANGELOG  *(Commerce commit 4)*

**Files:** extend `tests/Integration/Marketplace/MarketplaceRegressionTest.php`; create `tests/Integration/Marketplace/SellerApiKeyPgsqlTest.php` (+ fixture child; mirror `SellerSuspensionPgsqlTest`); create `src/Console/PurgeApiKeyDenialsCommand.php` (retention cleanup) + register; modify `tests/Integration/Http/HttpDocumentationTest.php` (new routes); `CHANGELOG.md`.

- [ ] **Immediate revalidation + suspension (§2.7/§2.11):** membership removal / role reduction / seller suspension / closure each take effect on the very NEXT key request (suspension → a key reaches ONLY the 5 `allow_suspended` routes, MV5b; closed → nothing). A key's effective scope shrinks the instant its subject's role is reduced.
- [ ] **Off-invariance (§6):** no seller keys ⇒ session auth + all surfaces byte-identical; the authorizer does ZERO key-table queries for `auth_method != 'api_key'`; non-commerce framework keys get no seller authority; all 3 tables adopt/scope per tenant.
- [ ] **Retention (§2.10):** `PurgeApiKeyDenialsCommand` deletes `auth_denied` rows older than `auth_denied_retention_days` (default 90); reads honor expiry before cleanup; host-cron-invoked, tenant-safe.
- [ ] **Live pgsql (`COMMERCE_TEST_DB_DRIVER=pgsql`, run live, paste verbatim), BOTH orderings:** (a) rotate-first commits successor, waiting revoke revokes every generation; revoke-first commits and waiting rotate returns 409; final state has no active successor; (b) create vs manager demotion/removal and create vs seller suspension; (c) rotate/revoke vs manager demotion or suspension under seller→lineage order. Mutation-first may finish; authority/lifecycle-first makes key mutation refuse. Migration shape via `pg_indexes`; re-run 018 no-op.
- [ ] **CHANGELOG `[Unreleased]`:** MV5c-1 seller API keys — seller-scoped machine credentials reusing the framework key machinery; commerce lineage+credential binding; three-way effective-scope intersection (key ∩ live role ∩ grantable catalog) revalidated per request; exact-scope-match + principal-integrity + one-seller + no-wildcard/no-empty; grace rotation (revision-serialized, whole-lineage revoke); JWT-interactive-only self-service management; bounded fail-closed auth_denied audit. Note framework 1.71.0 (`rotate()` returns successor uuid + shortens predecessor expiry) → commerce 1.2.0; migration 018. No version/pin bump.
- [ ] **COMMIT (Commerce 4)** — explicit add of SellerApiKeyController + DTOs, InteractiveSessionMiddleware, routes.php, CommerceServiceProvider, PurgeApiKeyDenialsCommand, SellerApiKeySurfaceTest, MarketplaceRegressionTest, SellerApiKeyPgsqlTest, HttpDocumentationTest, CHANGELOG → `feat(marketplace): mv5c-1 seller api-key surface, gates, and rotate-revoke race`. Then whole-branch review of the MV5c-1 commerce range.

---

## Self-Review notes
- **Spec coverage:** §2.1→T1+T3; §2.2→T2+T3+T5; §2.3→T4; §2.4→T4; §2.5→T2+T3; §2.6→T4; §2.7→T4+T7; §2.8→T3+T6; §2.9→T5; §2.10→T4+T7; §2.11→T4+T7; §3→T2; §4→T1; §5→T6; §6→T7; §7→per-task+T7.
- **Invariant-bearing subtleties for reviewers:** (a) API-key request without Commerce binding DENIES; only sessions no-op (T4); (b) effective = exact key scopes ∩ live role ∩ dedicated grantable catalog; empty/wildcards/drift fail closed (T2–T4); (c) principal never replaced (T4); (d) create derives subject+role under seller revision; rotate/revoke lock seller revision → fresh manager authority → lineage revision (T3/T5/T7); (e) management exact predicate is `auth_provider==='jwt'` + no `api_key_uuid`, enforced before commerce_seller middleware (T6); (f) mutations atomic; re-revoke no-op/no-event, rotate-revoked 409, unknown 404 (T3/T5); (g) framework rotation new_uuid + no expiry extension (T1); (h) bounded denial audit + nullable-unique portability (T2/T4/T7); (i) non-key requests perform zero key-table queries (T4/T7).
- **Release:** framework 1.71.0 (Task 1, joins the committed dispatchOrFail seam) → commerce 1.2.0 (migration 018); no version/pin bump; vendor-mirror the framework rotate change locally (never staged). No contracts/payvia.
