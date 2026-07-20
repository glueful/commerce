# Marketplace MV5c-1 — Seller API Keys (design)

**Status:** held / uncommitted draft for user hardening.
**Slice of:** the marketplace overview (§MV5 "seller-specific API keys/webhooks"). MV5c splits into two independent subsystems; **MV5c-1 is seller-scoped machine authentication** (API keys). **MV5c-2 (outbound webhooks) is a separate later slice** — no coupling between them.

**Scope:** reuses the framework's hardened key machinery, with commerce owning the seller binding + authorization. Bundles into the still-unpublished **Commerce 1.2.0** (MV4+MV5a+MV5b+MV5c-1); a new **migration 018**. NO contracts/payvia change.

**Release (decided) — framework → commerce.** MV5c-1 requires a small **framework** change (§4): `ApiKeyService::rotate()` must (a) RETURN the successor `new_uuid` so commerce can bind the successor atomically without an unsafe lookup, and (b) shorten the predecessor's expiry to **the EARLIER of its existing expiry and the grace deadline** (today it replaces expiry with `now+grace`, which can *extend* an earlier expiry — a defect). `api_key_uuid` is ALREADY exposed on the request (`ApiKeyAuthenticationProvider` line 106), so no auth-identity seam is needed. This framework change **joins the pending framework 1.71.0 release** (alongside the MV5a `dispatchOrFail` seam). Release chain: **framework 1.71.0 → commerce 1.2.0** (contracts/payvia unchanged). No version/pin bump on the branches (user's release step).

**Builds on:** the framework `Glueful\Auth\ApiKey\ApiKeyService` (generation/hashing/configured live/test prefixes/verify/rotate/revoke/IP-allowlist/expiry) + `ApiKeyAuthenticationProvider` (which sets `auth_method='api_key'`, `user_id`, `user_data`, `api_key_scopes`, and the authenticated key identity on the request), and the MV1/MV5b seller-auth stack: `SellerMemberMiddleware` (resolves the seller from the route `{sellerUuid}`, revalidates membership + seller status/lifecycle, checks the capability via `SellerRoleAuthority` → 403), `FixedSellerRoleAuthority` (the `CAPABILITY_MATRIX`).

**Default-off:** with no seller keys issued, nothing changes — session auth and all existing surfaces are byte-identical.

---

## 1. Goal

Let a seller issue **scoped machine credentials** to call its own marketplace API programmatically, reusing the framework's hardened credential machinery, with commerce owning only the seller-specific binding + authorization semantics. Effective access is the intersection of the key's declared scopes, the subject user's live seller-role capabilities, and an API-key-grantable capability catalog — revalidated every request — so a stolen key can never mint credentials, change its human's authority, move money, or reach another seller.

---

## 2. Design

### 2.1 Ownership split (strict)
- **Framework `ApiKeyService` owns:** key generation, hashing, verification, the configured live/test prefixes, rotation (grace), expiry, and revocation. Commerce NEVER re-implements crypto/verification and never parses or asserts a literal key prefix.
- **Commerce owns:** a **binding** linking the framework key `uuid` → `tenant_uuid`, `seller_uuid`, and the **subject user** (the authenticating seller-member), plus the declared scopes and the seller-specific authorization semantics below.

### 2.2 Rotation-stable lineage + direct credential bindings
- `commerce_seller_api_keys` is one logical, seller-visible key **lineage**. Its `uuid` IS the stable `lineage_uuid`; it owns tenant/seller/subject/scopes/name/status/expiry, a mutation `revision`, and a pointer to the current Commerce credential row. There is no duplicate `uuid` + `lineage_uuid` identity.
- `commerce_seller_api_key_credentials` is one row per generated framework key. It maps `framework_key_uuid` directly to the lineage and records generation, relationship (`current | predecessor | revoked`), and the predecessor grace expiry. The current key and every still-valid grace predecessor therefore resolve by one indexed lookup on the exact authenticated `api_key_uuid`; Commerce never walks the framework's forward-only `rotated_from_id` chain.
- Rotation claims the lineage revision, changes the previous credential from `current` to `predecessor`, inserts the successor credential, advances the lineage's current pointer, and writes the audit row atomically. Revocation claims the same revision and enumerates every credential row for the lineage, making whole-lineage revocation explicit and deterministic.

### 2.3 Auth flow (per request)
For a request whose `auth_method='api_key'` (framework already verified the key → set `user_id`, `api_key_scopes`, the key identity):
1. Resolve the exact presented `api_key_uuid` through `commerce_seller_api_key_credentials` → its active lineage. **An API-key request with no Commerce binding is explicitly denied on every seller route with the same non-revealing response**, even when the framework key's user is an active seller member. Only a non-key/session request may no-op through the seller-key authorizer.
2. **Validate, never replace, the authenticated principal:** framework authentication already populated `user`/`user_id`. Require `request.user_id === lineage.subject_user_uuid`; any mismatch is a fail-closed integrity error + `auth_denied` audit. Commerce never overwrites the authenticated `user` attribute from binding data.
3. Normalize `api_key_scopes` and require exact equality with the lineage's normalized `declared_scopes`. Any drift is denied + diagnosed/audited; runtime never chooses the broader copy.
4. **One-seller enforcement (§2.6):** the route `{sellerUuid}` MUST equal the lineage's `seller_uuid`; any mismatch ⇒ the same non-revealing refusal before any handler.
5. Compute **effective scopes** (§2.4) and gate the route's required capability against them.
6. The authorizer returns a typed key-authorization context (lineage/credential/subject/seller) only after steps 1–5. `SellerMemberMiddleware` then performs its existing seller/membership/lifecycle/role checks and calls the authorizer's single denial-audit method with that context for `membership_inactive`, `seller_inactive`, or `capability_denied`. “Audit here or there” is not an implementation choice. Non-key requests receive a null context with zero key-table queries.

### 2.4 Effective access = intersection (three-way)
**Effective access = validated declared key scopes ∩ subject user's current seller-role capabilities ∩ the API-key-grantable catalog (§2.5).** The framework-authenticated scope copy must first equal the binding copy (§2.3); mismatch denies. Enforced per request, so a role, membership, or catalog change shrinks reach immediately.

### 2.5 API-key-grantable capability catalog (explicit allow-list, not an ad-hoc deny-list)
- A dedicated code-defined `SellerApiKeyCapabilityCatalog` owns the set of exact capabilities that MAY appear on a machine key. It is separate from the rebindable `SellerRoleAuthority`: the catalog defines machine-grantability, while role authority defines what the subject's current role holds. **Grantable:** catalog read/write, inventory read/write, orders read + fulfill, reports/financial reads (and `payouts.read`). **NEVER grantable:** `commerce.seller.apikeys.manage`, `commerce.seller.members.manage`, payout execution, or any credential/ownership/policy administration.
- A key that declares a non-grantable scope is rejected at CREATE time (422); and even if a non-grantable scope somehow appears, the per-request intersection drops it.
- Declared scopes are normalized, deduplicated, **exact capability slugs only**. Wildcards (`*`, `commerce.seller.*`, or any `fnmatch` pattern) are rejected at write time and fail closed at read time, preventing a key from inheriting future capabilities implicitly.
- **At least ONE explicit scope is REQUIRED (empty is forbidden, 422).** The framework interprets an EMPTY scope list as UNRESTRICTED/full access — so a seller key MUST carry a non-empty declared-scope list; commerce never passes an empty scope set to `ApiKeyService::create`. This is load-bearing: an empty-scope key would bypass the intersection and grant everything.

### 2.6 One-seller binding (never cross-seller)
A key is bound to exactly ONE seller and can NEVER select another seller through a header or route parameter. The binding's `seller_uuid` is authoritative; the route `{sellerUuid}` must match it (§2.3 step 3). There is no "seller" header the key can supply.

### 2.7 Immediate revalidation
Every request revalidates the subject user's ACTIVE seller membership and the seller's status. **Membership removal, role reduction, seller suspension, or closure takes effect IMMEDIATELY** — the next request with the key is refused/reduced (no key state to update, because access is derived per request via §2.4 + `SellerMemberMiddleware`). Suspension flows through the SAME MV5b `allow_suspended` surface (§2.11) — a key gets exactly the access a session would on a suspended seller.

### 2.8 Self-service management (create / rotate / revoke / list)
- A seller manages its OWN keys via a seller-scoped surface gated by the **new `commerce.seller.apikeys.manage`** capability (§3 role grant: `seller_owner` + `seller_admin`), own-seller-only (tenant-bound, `{sellerUuid}` from the route, never a body tenant/seller).
- **Management requires an INTERACTIVE JWT-backed session — NEVER merely “not an API key.”** The canonical positive predicate is `request.attributes.auth_provider === 'jwt' && !request.attributes.has('api_key_uuid')`; `AuthMiddleware` supplies `auth_provider`. Every other provider is a stable 403 unless a later design explicitly classifies it as interactive.
- CREATE accepts the authenticated actor, never a caller-supplied subject UUID or role. Inside one transaction it follows the global order **seller revision → fresh seller + actor membership/capability re-read → framework key → lineage/credential/audit**. The actor must remain an active member whose freshly-derived role holds `apikeys.manage`; seller must remain active. Subject user is that actor. This serializes against role change, membership removal, and suspension.
- CREATE declares a name + a non-empty exact subset of the grantable catalog held by that freshly-derived role. Optional `expires_at` must be a parseable UTC timestamp strictly after DB-now; default is non-expiring. Framework-key creation + lineage + first credential + audit commit or roll back together. Environment/prefix remain framework-configured and opaque; IP allowlist remains deferred.
- LIST/GET: returns lineage metadata (uuid, name, declared scopes, effective status including expiration, `expires_at`, `last_rotated_at`, created_by) — **never the secret**.
- REVOKE: disables the binding atomically and revokes the ENTIRE lineage (current + any grace-window predecessor) via `ApiKeyService::revoke`.

### 2.9 Rotation/revocation (live authority, grace, lineage-preserving, ATOMIC)
- Rotation reuses `ApiKeyService::rotate` (default grace, e.g. 24h) — which now RETURNS the successor `new_uuid` (§4) so commerce binds the successor without an unsafe lookup, and shortens the predecessor's expiry to the **earlier of its existing expiry and the grace deadline** (never extends). The successor inherits the SAME tenant, seller, **subject user**, and declared scopes atomically; both keys resolve to ONE binding lineage during grace.
- **Global lock order (pinned): `seller revision → actor membership/capability re-read → lineage revision → framework/credential writes`.** Rotate and revoke first claim the target lineage's seller revision, require the seller active and actor still an active member whose fresh role holds `apikeys.manage`, then claim the lineage revision. This closes authority/suspension TOCTOU races and composes with existing membership mutations, which already claim the seller revision.
- Rotation then re-reads lineage/current credential, requires lineage active and current framework key neither revoked nor expired, invokes framework rotation, demotes predecessor, inserts successor, advances current pointer/`last_rotated_at`, and audits. All commit or roll back together.
- **Rotation NEVER changes the subject user or expands scopes** — even when a DIFFERENT administrator performs the rotation, the binding keeps its ORIGINAL subject user (authority is not rebound to the rotating admin). The new raw secret is returned once. An optional per-key `expires_at` is preserved across rotation.
- Suspension / membership removal / role reduction affect BOTH keys immediately despite the grace window (§2.7 — access is derived per request, not frozen at rotation).
- REVOKE uses the same lock order, revokes every recorded framework credential, marks lineage + credentials revoked, and audits in one transaction. **Re-revoke is a stable no-op with no second audit event; rotate-revoked is 409; unknown/cross-tenant lineage is 404.** A zero-row active-lineage claim must re-read to distinguish these outcomes. Rotate versus revoke is serialized: rotate-first may commit and the waiting revoke then revokes the successor too; revoke-first commits and the waiting rotate returns 409. Final invariant: no active successor survives a committed revoke.

### 2.10 Durable audit
- An append-only audit records **key creation, rotation, revocation, and bound-key authorization denials** — actor/subject, lineage, predecessor/successor framework-key uuids, grace expiry, seller, closed reason code, timestamp — and NEVER records a secret. Mutation events are permanent and atomic with their mutation.
- `auth_denied` is written only after framework authentication succeeds and an exact Commerce credential binding is found; random invalid/non-Commerce keys cannot grow the table. Reasons use a closed vocabulary (`principal_mismatch | scope_drift | scope_missing | seller_mismatch | membership_inactive | seller_inactive | capability_denied`). Persist at most one denial per `(tenant,lineage,reason,UTC-minute)` using an explicit `bucket_start` unique backstop; duplicates in the bucket are an idempotent no-op. Rows have configurable retention (`commerce.marketplace.api_keys.auth_denied_retention_days`, default 90) and a host-scheduled cleanup command; expiry is honored in reads before cleanup. If persistence fails, access remains denied and the write failure is error-logged.

### 2.11 Suspension interaction (MV5b)
- A key-authenticated request flows through the SAME `SellerMemberMiddleware` + `allow_suspended` route markers as a session: on a `suspended` seller a key reaches ONLY the 5 `allow_suspended` routes (orders read/fulfill, financials balance/reserves), everything else 409; a `closed` seller's keys reach nothing (deactivated memberships). No key-specific suspension logic — consistency is the point.

### 2.12 Coupling & continuity
- **framework → commerce** (framework key machinery reused + the two `rotate()` changes in §4; commerce owns binding/authz). No contracts/payvia. Default-off. No new external coupling. Operator-only key issuance is NOT in scope (sellers self-serve); operators can suspend the seller (which freezes its keys) but do not mint seller keys here.

---

## 3. Schema
- **New migration `018_CreateSellerApiKeysTables.php`** creates three tenant-scoped tables (all `tenant_uuid` default `''` for adopter compatibility):
  - **`commerce_seller_api_keys`** (one logical lineage): `id` bigint PK; `uuid` varchar(12) (the stable lineage identity); `tenant_uuid` varchar(12); `seller_uuid` varchar(12); `subject_user_uuid` varchar(12); `declared_scopes` text containing canonical JSON; `name` varchar(120); `status` varchar(16) default `active` (`active|revoked`); `current_credential_uuid` varchar(12); `expires_at` nullable timestamp; `revision` integer default `0`; `created_by` varchar(12); `created_at`/`updated_at`; `last_rotated_at`/`revoked_at` nullable. Unique `(tenant_uuid,uuid)` and `(tenant_uuid,current_credential_uuid)`; index `(tenant_uuid,seller_uuid,status)`.
  - **`commerce_seller_api_key_credentials`** (one generated framework key): `id` bigint PK; `uuid` varchar(12); `tenant_uuid` varchar(12); `lineage_uuid` varchar(12); `framework_key_uuid` varchar(12), matching canonical `api_keys.uuid`; `generation` integer; `relationship` varchar(16) (`current|predecessor|revoked`); `grace_expires_at` nullable timestamp; `created_at`; `revoked_at` nullable. Unique `(tenant_uuid,uuid)`, `(tenant_uuid,framework_key_uuid)`, and `(tenant_uuid,lineage_uuid,generation)`; indexes `(tenant_uuid,lineage_uuid)` and `(tenant_uuid,lineage_uuid,relationship)`.
  - Append-only **`commerce_seller_api_key_events`**: `uuid`, `tenant_uuid`, lineage_uuid, seller_uuid, subject_user_uuid, `action` (`created|rotated|revoked|auth_denied`), actor_uuid nullable, `reason_code` nullable, `bucket_start` nullable, `predecessor_key_uuid` nullable, `successor_key_uuid` nullable, `grace_expires_at` nullable, `detail` nullable, created_at; unique `(tenant_uuid,uuid)` and `(tenant_uuid,lineage_uuid,action,reason_code,bucket_start)` (the latter is populated only for `auth_denied`); indexes `(tenant_uuid,seller_uuid,created_at)` and `(action,created_at)` for retention cleanup. No secrets ever.
- `FixedSellerRoleAuthority`: add `COMMERCE.SELLER.APIKEYS.MANAGE` and grant it to owner + admin. New `SellerApiKeyCapabilityCatalog` owns the exact machine-grantable allow-list; do not add `apiKeyGrantable()` to `SellerRoleAuthority` or couple validators to `FixedSellerRoleAuthority`.
- `DiagnosticsReport::commerceTables()` + `tenantTables()`, `CommerceTestCase::MIGRATIONS`, tenant adopter/scoping coverage, and every exact-list table pin gain all three tables.

## 4. Framework seam (REQUIRED — joins framework 1.71.0)
- **Auth identity — already present, no change:** `ApiKeyAuthenticationProvider` sets `auth_method='api_key'`, `user_id`, `user_data`, `api_key_scopes`, and the authenticated key uuid as **`api_key_uuid`** (line 106); `AuthMiddleware` already populates `user`. Commerce reads and validates these attributes against the credential/lineage but never replaces the authenticated principal. No auth-identity seam needed.
- **`ApiKeyService::rotate()` — TWO required changes (framework 1.71.0):**
  1. **Return the successor `new_uuid`** (add it to the returned array) so commerce binds the successor atomically. Today `rotate()` does not return the new key's uuid/model; commerce would otherwise need an unsafe post-hoc lookup by `rotated_from_id`.
  2. **Predecessor expiry = earlier of (existing expiry, grace deadline).** Today `rotate()` REPLACES the predecessor expiry with `now + grace`, which can EXTEND an already-earlier expiry (a defect — a rotation must only ever SHORTEN the predecessor). Fix to `min(existing_expiry, now+grace)`, treating a null existing expiry as "grace deadline".
- These are the ONLY framework changes; they bundle into the pending **framework 1.71.0** (with the MV5a `dispatchOrFail` seam). Release chain: framework 1.71.0 → commerce 1.2.0.

## 5. Surfaces
- **Seller self-service (`commerce_seller:commerce.seller.apikeys.manage`, own seller only, JWT INTERACTIVE SESSION ONLY):** `POST /{sellerUuid}/api-keys` (create → raw secret once), `GET /{sellerUuid}/api-keys` (list, no secrets), `POST /{sellerUuid}/api-keys/{lineageUuid}/rotate` (→ new raw secret once), `POST /{sellerUuid}/api-keys/{lineageUuid}/revoke`. Every non-JWT provider and every request carrying `api_key_uuid` is refused (403).
- **No operator key-mint surface** in MV5c-1 (operators manage seller lifecycle, not seller credentials).
- No buyer-facing change.

## 6. Off-invariance
- No seller keys issued ⇒ session auth + all seller/admin/storefront surfaces byte-identical; the seller-key authorizer returns before any key-table query for `auth_method != 'api_key'`, and non-Commerce framework keys receive no seller authority; zero new queries on non-key requests; all three new tables adopt/scope per tenant.

## 7. Testing (highlights)
- Auth: exact credential lookup; authenticated `user_id` must equal binding subject; framework/binding scopes must match; wildcards rejected; seller A cannot reach seller B; effective access = key ∩ role ∩ grantable; a non-Commerce framework key owned by an otherwise-valid seller member is still denied.
- Management: canonical `auth_provider='jwt'` positive signal + no `api_key_uuid`; create derives subject/role from a fresh membership under the seller revision; concurrent demotion/removal/suspension either follows a committed create or wins and refuses it; expiry invalid/past/now rejected.
- Rotation/revocation: seller revision precedes fresh manager authority and lineage revision; active/unexpired lineage only; successor preserves subject/scopes/expiry; re-revoke stable no-op/no event; rotate-revoked 409; unknown 404; revoke kills every generation.
- Immediate revalidation: membership removal / role reduction / seller suspension / closure each take effect on the very next key request (suspension → only the `allow_suspended` routes, MV5b).
- Audit: create/rotate/revoke are permanent and atomic; bound-key `auth_denied` uses closed reasons, never records secrets, remains fail-closed on audit-write failure, and expires under the configured retention policy.
- Schema portability: multiple permanent audit events with null `reason_code`/`bucket_start` do not collide; duplicate same-minute `auth_denied` does. Off-invariance + tenant adoption for all three tables. **Mandatory live pgsql races, both orderings:** rotate vs revoke on the lineage revision, plus create/rotate/revoke vs manager demotion or seller suspension on the seller revision.

## 8. Resolved decisions
- **Framework seam — RESOLVED (§4):** `api_key_uuid` already exists (line 106), no auth seam. `ApiKeyService::rotate()` MUST return `new_uuid` AND shorten (never extend) the predecessor expiry to `min(existing, grace)`. These join **framework 1.71.0**; release chain framework 1.71.0 → commerce 1.2.0.
- **Environment/prefix — RESOLVED:** entirely framework-configured and OPAQUE to commerce; the seller does NOT select it per key.
- **Expiry — RESOLVED:** optional create expiry must be parseable UTC and strictly after DB-now; default non-expiring. Framework remains enforcement source; rotation preserves successor expiry and may only shorten the predecessor to the grace deadline.
- **Binding/lineage — RESOLVED (§2.2):** one claimable logical-lineage row plus one direct credential row per framework generation; no framework-lineage walk. `uuid` is the lineage identity; rotate/revoke serialize on its revision.
- **Principal integrity — RESOLVED (§2.3):** framework-authenticated `user_id` must equal the lineage subject; Commerce validates and never replaces `user`. Framework and binding scope copies must match after canonical normalization.
- **Unbound framework keys — RESOLVED (§2.3):** non-key requests no-op; every API-key request without a Commerce binding is denied on seller routes, even if its framework principal has seller membership.
- **Scopes/catalog — RESOLVED (§2.5):** a dedicated `SellerApiKeyCapabilityCatalog`; at least one exact slug; empty/wildcards forbidden. Role authority remains independently rebindable.
- **Interactive management — RESOLVED (§2.8):** canonical predicate `auth_provider='jwt'` and no `api_key_uuid`; “not an API key” alone is insufficient.
- **Live management authority — RESOLVED (§2.8/§2.9):** service-level lock order seller revision → fresh actor membership/capability → lineage revision. Subject/role are never caller supplied.
- **IP allowlist — DEFERRED:** not in MV5c-1's seller surface (easy follow-on).
- **Mutation atomicity/concurrency — RESOLVED (§2.8/§2.9):** create/rotate/revoke share transactions with binding/credential/audit writes; seller and lineage races are mandatory; re-revoke no-op/no-event, rotate-revoked 409, unknown 404.
- **Denied-audit bound — RESOLVED (§2.10):** only authenticated Commerce-bound keys create denial rows; closed reason vocabulary, one row per lineage/reason/minute, 90-day default retention.
- **Release — RESOLVED:** MV5c-1 in unpublished commerce 1.2.0 (migration 018); the small framework change joins the pending framework release. No contracts/payvia.
