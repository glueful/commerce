# Layer 3 — Customers + Digital Delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Layer 3 per `docs/superpowers/specs/2026-07-15-layer3-customers-digital-delivery-design.md` (revision 3): framework blob-policy composition seam, digital delivery (snapshot-derived grants, atomic mints, two access paths), customers aggregation, address book.

**Architecture:** The framework gains a `BlobAccessPolicyRegistry` + `CompositeBlobAccessPolicy` (AND/veto, byte-identical with no contributors) at the verified single injection point `StorageProvider:153`; commerce contributes its download policy without binding the shared contract. Grants derive exclusively from purchase-time order-line snapshots; the mint is one atomic claim chain (order financial claim → prebuilt URL → guarded grant UPDATE on DB time). Customers/addresses ride existing order identity (`user_uuid` capture verified present).

**Tech Stack:** PHP 8.3; framework changes land in `~/Sites/glueful/framework` (HELD tree, synced into commerce's vendored copy like the index fix); commerce on the established harness (SQLite + pgsql lane).

## Global Constraints

- Repos: framework (Task 1 ONLY — held, uncommitted, synced to commerce vendor after green) and commerce (Tasks 2–10; commits at group boundaries, no attribution, never stage docs/superpowers/** or .superpowers/**).
- FOLD POSTURE: four new tables in `008_CreateCommerceCustomerDeliveryTables.php`; `commerce_order_lines.downloads` json folds into 004. Release chain note: commerce Layer 3 depends on the UNRELEASED framework seam — pin/release framework (1.70.0 minor: registry is a feature) before any commerce release.
- Serialization primitives (house patterns, reuse exactly): grant mutations = guarded single-UPDATE claims with DB time; order-financial serialization = the existing `refund_revision` claim **renamed to `claimOrderFinancialMutation()`** and shared by refunds + mints (pure rename + reuse, all refund tests stay green); address-book default swaps = the new `commerce_customer_address_books` parent revision claim. Ensure that parent before opening the address-mutation transaction (or under an explicit savepoint), handle a competing unique insert there, then begin/claim/re-read/mutate — never catch a PostgreSQL unique violation and continue in an aborted transaction. Download definition mutations = `catalog_revision` product claim (media family).
- Atomic mint (spec §4.1 verbatim): one transaction — claim order financial row → re-read refund totals → build signed URL (pure; failure consumes nothing) → ONE guarded grant UPDATE (`mint_count+1`, `last_minted_at = DB now`, `remaining-1` when non-null; WHERE tenant/order/grant + not revoked + not expired (DB time) + remaining null-or-positive + (not fully refunded OR override set)). 1 row → return URL; 0 rows → classify by read → 410 {exhausted|expired|revoked|blocked_by_full_refund} or 404.
- Grants: unique (order_uuid, download_uuid) idempotency; snapshot columns (blob_uuid, name) from the ORDER LINE snapshot only — never live definitions; `remaining = download_limit × Σ quantity` across matching lines incl. add-on-distinct (overflow-checked); tokens 160-bit (20 bytes hex), hash-only storage, raw returned only by the creating call; token lookup is a named correlation read (global by hash, then tenant-constrained by the located row).
- Issuance surfaces: `OrderMailListener::onOrderPaid()` (primary, `issueAndCollectForOrder`), order-authenticated lazy heal, backfill CLI. The token path NEVER heals. Issuance failure inside the mail listener: log, send plain email, never block.
- Policy (spec §5 verbatim): neutral for unreferenced blobs; VIEW = signature OR creator; INFO = creator only; SIGN = false for referenced blobs; DELETE = false while a definition references it, any grant retains access, or `last_minted_at + url_ttl` is in the future.
- Whitelists: grant listings expose no token/hash/blob_uuid; audit events carry no token/hash/blob uuid; email deep links only in the mail payload.
- Customers: aggregation keyed user_uuid else `lower(trim(email))` with explicit `key_type`; link-guests stamps only on exact normalized-email match of the resolved identity (username-only match rejected + reported).
- Config: `commerce.downloads.url_ttl` default 300.
- Concurrency tests: deterministic in SQLite plus runnable PostgreSQL-lane races for finite double-mint, unlimited mint-vs-revoke, mint-vs-full-refund, and concurrent first-default creation. Reuse the established env/two-connection pattern with randomized identifiers and `finally` cleanup.
- Regression gate: stores without digital products/addresses byte-identical; checkout without address uuids unchanged; refund suite green through the claim rename.
- Quality gates per group: `composer test` + `composer phpcs`(project script)/`composer run analyze` (commerce) · `composer test` + `composer run phpcs` + `composer run analyse` (framework).

---

## GROUP F — Framework seam (repo: framework; HELD, no commit)

### Task 1: BlobAccessPolicyRegistry + CompositeBlobAccessPolicy

**Files:** Create `src/Uploader/Contracts/BlobAccessPolicyRegistry.php`, `src/Uploader/Contracts/CompositeBlobAccessPolicy.php`; Modify `src/Container/Providers/StorageProvider.php` (:153 injection becomes the composite); Test `tests/Unit/Uploader/BlobAccessPolicyCompositionTest.php`; CHANGELOG [Unreleased] entry (Added).

**Interfaces (Produces):**
```php
final class BlobAccessPolicyRegistry
{
    /** @throws \LogicException on duplicate id */
    public function register(string $id, BlobAccessPolicy $policy): void;
    /** @return array<string, BlobAccessPolicy> insertion-ordered */
    public function all(): array;
    public function has(string $id): bool;
}
final class CompositeBlobAccessPolicy implements BlobAccessPolicy
{
    public function __construct(
        private BlobAccessPolicy $primary,
        private BlobAccessPolicyRegistry $registry,
    ) {}
    // authorizeAccess: primary AND every CURRENT registry contributor
    // (registry->all() read per call; short-circuit on first false)
}
```
- Registry is a normal shared framework service from StorageProvider, available before extension boot. No static accessor or process-global fallback. The composite keeps the registry reference, so contributor registration remains live even if the composite or UploadController was constructed earlier.
- StorageProvider wraps: `new CompositeBlobAccessPolicy(primary-or-Null, registry)`. Zero contributors + no primary = Null primary + empty live registry = byte-identical.
- [ ] TDD: primary-only allow/deny; contributor-only deny; combined (host primary denial AND contributor denial both effective); deterministic order; duplicate-id throws; no-contributor byte-identical; **late registration** (construct composite first, then register a contributor, then observe its denial). Add a StorageProvider integration assertion that the controller receives the live composite. Framework full suite + gates green. NO commit (held tree). Sync the touched files into commerce's `vendor/glueful/framework` (and payvia's if its suite consumes blobs — it doesn't; skip).

---

## GROUP A — Schema (repo: commerce)

### Task 2: Migration 008 + folded order-line snapshot + shape test

- 008 creates the four tables per spec §2 verbatim (address_books parent w/ revision + unique (tenant,user); addresses; downloads w/ unique (variant_uuid, blob_uuid); grants w/ unique (order_uuid, download_uuid), globally-unique token_hash, mint_count/last_minted_at/refund-override columns). 004 folds `commerce_order_lines.downloads` json nullable. DiagnosticsReport +4 tables (all four tenant-bearing → tenantTables). CommerceTestCase + MigrationsTest.
- [ ] TDD shape test: tables/uniques/defaults; grants dupe (order,download) rejected; token_hash global-unique across tenants; address_books (tenant,user) unique; folded column default null. Full suite green; NO commit.

## GROUP B — Delivery core (repo: commerce)

### Task 3: Downloads admin CRUD + checkout entitlement snapshot
- `DownloadRepository`/`DownloadService`/`AdminDownloadController` + DTOs + routes ($read/$write): attach validates in-tenant digital-variant + PRIVATE active blob (public → 422); mutations claim product catalog_revision; detach never touches the blob.
- `CheckoutService`: order-line building snapshots active definitions for the line's variant into `downloads` json `[{download_uuid, blob_uuid, name, download_limit, expiry_days}]` (empty array for digital variants with none; column null for non-digital). OrderRepository decode boundary extends to `downloads` (same single-decode rule as addons).
- [ ] TDD: CRUD matrix; checkout snapshot present/empty/null cases; definition edit/delete after checkout leaves the order-line snapshot byte-identical. NO commit.

### Task 4: Grant service + backfill CLI
- `DownloadGrantRepository` (insert, findForOrder, correlation `findByTokenHashGlobal` documented correlation-style, guarded mint UPDATE, revoke/override guarded updates) + `DownloadGrantService`:
  - `ensureGrantsForOrder(context, order): list<array>` — snapshot-derived, quantity-aggregated (overflow-checked), idempotent. Name both unique constraints: losing `(order_uuid,download_uuid)` reloads that existing grant; losing global `token_hash` regenerates the raw token and retries. Never treat one conflict as the other. Qualifying statuses paid/fulfilled/refunded.
  - `issueAndCollectForOrder(context, order): array{grants: list, raw_tokens: array<grantUuid,string>}` — raw only for rows THIS call created. Generate the 160-bit credential with the verified `TokenHasher::generate()` house primitive.
- `commerce:downloads:backfill` CLI (BaseCommand house pattern, tenant-aware, --dry-run).
- [ ] TDD: idempotency across all three surfaces (no dupes), including a partially-issued order whose missing tail is repaired; named-constraint branches (order/download race reload vs token collision regenerate); quantity math incl. add-on-distinct lines; overflow; snapshot-only derivation (definition deleted → grants still issue from line snapshot). NO commit.

### Task 5: Access paths + atomic mint + claim rename
- Rename `refund_revision` claim → `OrderRepository::claimOrderFinancialMutation()` (mechanical; refund suite untouched otherwise and green).
- `OrderController` additions: `GET /orders/{number}/downloads` (existing access check reused; listing shape per spec — expired/revoked/blocked_by_full_refund booleans, no token/hash/blob) and `POST /orders/{number}/downloads/{grantUuid}/url` (atomic mint per Global Constraints; 410 code classification; 404 non-revealing).
- `DownloadLinkController` (storefront): `GET /commerce/downloads/{token}` — rate-limited, correlation lookup, SAME mint primitive, 302 to signed URL; JSON 410/404 otherwise.
- `AdminGrantController`: `POST /commerce/admin/grants/{uuid}/revoke` and `PUT/DELETE /commerce/admin/grants/{uuid}/refund-access-override`; tenant-constrained target lookup, guarded mutations, authenticated actor capture, and actor-bearing order events with no token/hash/blob UUID payload.
- Signed URLs mirror the verified core sequence exactly: inject optional `BlobPublicUrlProvider`, load the snapshotted blob row, use `publicBaseUrl($blob)` or the request scheme/host fallback, append `/blobs/{uuid}`, then `SignedUrl::make($context)->generate($baseUrl, $ttl)`.
- Both order-authenticated endpoints call `ensureGrantsForOrder()` unconditionally for every qualifying order before listing/target lookup. The operation is idempotent, so this heals partially-issued tails as well as an empty grant set. The token path never heals.
- [ ] TDD: full matrix (mint decrement one-winner deterministic; unlimited increments mint_count; expiry via DB time; revoked/full-refund/override; signing failure consumes nothing — bind a broken SignedUrl config in test; 302 Location validates with SignedUrl::validate; token path can't heal; empty and partial lazy-heal work; 410 code classification exact); admin revoke/override authorization, state, and audit payload tests. Add a BlobPublicUrlProvider test proving a tenant-A grant requested under tenant B redirects to tenant A's public host. NO commit.

### Task 6: Policy contributor + diagnostics
- `CommerceDownloadBlobPolicy` per spec §5 decision table (definition-referenced OR grant-snapshot-referenced; grant "retains access" = not revoked AND not expired AND remaining null-or-positive AND not blocked-by-full-refund-without-override; DELETE also blocked while `last_minted_at + url_ttl` future).
- Provider boot: `registry->register('commerce.downloads', policy)` (registry via container — Task 1's decision); NEVER binds BlobAccessPolicy. Diagnostics line: contributor present/missing.
- [ ] TDD: policy unit decision table; integration through the real composite (framework vendored copy synced in Task 1): direct blob GET unsigned → 404, signed → 200, creator VIEW → 200, generic SIGN → denied, DELETE blocked/allowed matrix; host-primary + contributor combined denial. NO commit.

### Task 7: Mail integration — **GROUP B COMMIT**
- `OrderMailListener::onOrderPaid()`: `issueAndCollectForOrder` first (try/catch-log → plain email on failure), pass raw-token deep links into the `order_paid` payload; template renders downloads section only when links present (byte-identical otherwise). No separate grant listener exists.
- [ ] TDD: email carries links on first issuance; already-granted order → plain email; issuance throw → plain email still sent + grants absent then healable. FULL suite green.
- [ ] **COMMIT (Group B):** `feat(downloads): digital delivery — snapshot grants, atomic mints, policy contributor, email links`

## GROUP C — Customers (repo: commerce)

### Task 8: Customers admin + link-guests CLI
- `CustomerAggregationRepository` (grouped query per spec §7 — key_type/key, totals incl. refunded_minor, first/last order, pagination, sort, email filter) + `AdminCustomerController` (list + detail w/ explicit `?by=user|email`; detail = aggregate + recent orders) + soft UserProviderInterface enrichment. Extend the order-detail query explicitly for normalized email-keyed customers; the current `paginatedFor()` user_uuid filter is insufficient. Address enrichment deliberately lands in Task 9 after its repository exists.
- `commerce:customers:link-guests` CLI: normalized-email exact-match guard (username-only resolution rejected+reported), --dry-run/--email, tenant-aware.
- [ ] TDD: aggregation math (guest email normalization, mixed user/guest, refunded totals); enrichment soft-degrade; keying explicit; link-guests dry-run/match/reject cases. NO commit.

### Task 9: Address book + checkout integration — **GROUP C COMMIT**
- `AddressBookRepository`/`AddressBookService`: ensure the `(tenant,user)` parent outside the address-mutation transaction (or within a savepoint), resolve a competing named-unique insert by reload, then start the transaction and claim/re-read/mutate. Default swap is clear-then-set under that shared claim. Add address-book enrichment to the user-keyed `AdminCustomerController` detail here, after the repository exists. `AccountAddressController` uses auth routes and the `mine()` actor extraction pattern.
- `CheckoutController`/`CheckoutService`: optional `shipping_address_uuid`/`billing_address_uuid` — authenticated-only, owner-only resolution, snapshot into `orders.addresses`. Cross-kind mixing is allowed (shipping UUID + inline billing or vice versa); UUID plus inline data for the SAME kind is ambiguous and returns 422. Inline-only remains unchanged.
- [ ] TDD: CRUD + non-revealing 404s; default-swap determinism; competing first-parent insert recovery without an aborted transaction; customer-detail address enrichment; checkout via uuids (owner-only, cross-user 404, snapshot equality), cross-kind mixing, same-kind ambiguity 422, unauthenticated uuid → 422. FULL suite green.
- [ ] **COMMIT (Group C):** `feat(customers): order-derived customer aggregation, address book, guest linking`

## GROUP D — Gates (repo: commerce)

### Task 10: Tenancy/concurrency suites + regression + docs — **GROUP D COMMIT**
- Two-tenant: address books/addresses/downloads/grants; cross-tenant token assertion (tenant-A token under tenant-B resolver serves only row-A data scoping).
- pgsql lane: remaining=1 double-mint; unlimited mint-vs-revoke; mint-vs-full-refund (shared financial claim); and concurrent first-default creation (shared address-book claim). Use the exact existing house env/two-connection pattern, randomized IDs, and `finally` cleanup. The cross-tenant token test also asserts its redirect Location uses tenant A's provider-derived public host.
- Regression audit (pre-existing files changed, why); comparison doc: digital delivery + customers/addresses move to "can migrate"; note framework 1.70 dependency.
- [ ] FULL suite + gates. **COMMIT (Group D):** `feat(commerce): layer 3 tenancy hardening + docs`

## Self-Review Notes
- Spec rev-3 → tasks: live registry/composite (T1); 4 tables + folded snapshot (T2); private-blob CRUD + checkout snapshot (T3); snapshot-derived quantity-aware idempotent grants, partial-tail repair, named conflict handling, and backfill (T4); atomic mint chain + claim rename + admin grant mutations + provider-derived URLs + both paths (T5); decision-table policy + contributor registration + diagnostics (T6); issue-and-collect mail flow (T7); keyed aggregation + guarded linking without a forward address dependency (T8); PostgreSQL-safe parent ensure/claim + customer-detail enrichment + unambiguous checkout uuids (T9); four real races/tenancy/docs (T10).
- Source-verification closures: framework core providers are compiled before extension boot and the shared live registry removes controller-construction timing from correctness; commerce `TokenHasher::generate()` is the 20-byte/160-bit house generator; `UploadController::signedUrl()` composes `BlobPublicUrlProvider` base → `/blobs/{uuid}` → `SignedUrl::make(context)->generate()`; `UploadController::info()` passes `signatureValid=false`. No static fallback or implementation-time contract guess remains.
