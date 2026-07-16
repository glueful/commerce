# Layer 3 — Customers via Users Integration + Digital Delivery

**Status:** revision 3 after plan review
**Parent:** `2026-07-14-woocommerce-parity-overview-design.md` (Layer 3)
**Repos touched:** `glueful/framework` + `glueful/commerce`. Framework adds a generic,
backward-compatible `BlobAccessPolicy` contributor chain; commerce contributes its download
policy without replacing the host's primary policy. `UserProviderInterface` is soft-resolved;
`glueful/users` is NOT depended on.
**Posture:** fold rules as before — four new tables in migration 008; purchase-time download
snapshots fold into `commerce_order_lines` in migration 004. Orders already carry
`user_uuid`/`email`; checkout already stamps `user_uuid` for authenticated sessions.

## 1. Scope

In: customer aggregation (admin), address book (storefront, authenticated) with optional
checkout integration, guest-order linking (operator command), digital delivery end to end
(download definitions, transactional-enough grants, two access paths, signed-URL serving,
blob-access backstop, email links).

Out: customer accounts/registration (Users owns identity), storefront review submission and
browse/filter endpoints (L6), subscriptions-style recurring entitlements, per-customer
pricing.

## 2. Data Model — migration `008_CreateCommerceCustomerDeliveryTables.php`

**`commerce_customer_address_books`** — the stable serialization parent for an account's
address defaults: uuid / tenant_uuid / user_uuid / revision (int default 0) / timestamps;
unique (tenant_uuid, user_uuid). First use creates the row idempotently (duplicate-key → reload).
The ensure happens before the address-mutation transaction (or in an explicit savepoint), so a
PostgreSQL unique violation cannot abort the transaction that must perform the claim. After the
parent exists, every create/update/delete/default swap starts a transaction, claims the shared
row by incrementing `revision`, checks exactly one affected row, then re-reads and mutates
addresses. Two concurrent first-address/default requests therefore serialize on the same parent
rather than claiming two different new address rows.

**`commerce_customer_addresses`**
| column | notes |
|---|---|
| uuid / tenant_uuid('') / user_uuid (12) | + index (tenant_uuid, user_uuid) |
| label (64, nullable) | free text ("Home") |
| address (json) | same shape checkout already accepts in `orders.addresses` |
| is_default_shipping / is_default_billing (bool, default false) | at most one of each per (tenant, user) — clear-then-set in one transaction after claiming the shared address-book revision row |
| created_at / updated_at | |

**`commerce_downloads`** — what a digital variant delivers.
| column | notes |
|---|---|
| uuid / tenant_uuid('') / variant_uuid (12) | variant must resolve in-tenant and belong to a `digital`-type product (attach-time rule) |
| blob_uuid (12) | must exist, `status='active'`, and be **PRIVATE** (inverse of media: merchandise must not be publicly fetchable) |
| name (255) | display/file label |
| download_limit (int, nullable) | null = unlimited |
| expiry_days (int, nullable) | null = never expires |
| position (int) / status (16, default 'active') | |
| unique (variant_uuid, blob_uuid) | |

**`commerce_download_grants`** — issued per paid order.
| column | notes |
|---|---|
| uuid / tenant_uuid('') / order_uuid / download_uuid | unique (order_uuid, download_uuid) — the idempotency key |
| blob_uuid / name | purchase-time snapshots; grant access never depends on a mutable or deleted download definition |
| token_hash (64) | sha256 via existing `TokenHasher`; **globally unique** (email deep links arrive tenantless — correlation-style lookup, payvia precedent) |
| remaining (int, nullable) | null = unlimited; otherwise snapshotted download_limit × purchased quantity summed across matching order lines |
| expires_at (timestamp, nullable) | issuance time + expiry_days |
| mint_count (bigInteger, default 0) / last_minted_at (nullable) | updated by every URL mint, including unlimited grants; serializes mint/revoke decisions and protects a still-live signed URL from blob deletion |
| revoked_at (timestamp, nullable) | operator kill-switch |
| refund_access_override_at / refund_access_override_by (nullable) | audited operator exception allowing access after a full refund |
| created_at | |

All four tables join `DiagnosticsReport::tenantTables()` (registry + adopter). Grants are
tenant-scoped rows but the token lookup is a named correlation read (global by token_hash,
then everything else constrained by the located row's tenant) — the payvia
correlation-surface pattern, documented on the repository method.

**Folded order-line column:** `commerce_order_lines.downloads` (JSON nullable) snapshots the
active definitions for that digital variant at checkout:
`[{download_uuid, blob_uuid, name, download_limit, expiry_days}]`. This is the purchase-time
entitlement source. Definition edits, disablement, or deletion affect future checkouts only;
existing paid orders and grants retain their purchased files. Checkout with no active downloads
stays valid and stores an empty snapshot, preserving existing digital-product behavior.

## 3. Grant Issuance (transactional-enough, self-healing)

Verified constraint: `OrderPaymentService::markPaid()` transitions and dispatches `OrderPaid`
with **no wrapping transaction**, and the mailer precedent treats listeners as best-effort.
Download access is NOT best-effort — a paid customer must get their files. Design:

- `DownloadGrantService::ensureGrantsForOrder(context, order): list<grant>` — IDEMPOTENT:
  read only the order lines' purchase-time `downloads` snapshots; group by download_uuid and
  sum purchased quantity across all matching lines (including add-on-distinct lines); insert
  missing grants with snapshotted blob/name and `remaining = limit × quantity` (checked for
  integer overflow). Unique `(order_uuid, download_uuid)` is the arbiter; duplicate-key →
  reload only when that named constraint lost the race. A collision on the separately named
  global token_hash unique regenerates a token and retries; the two conflicts are never
  conflated. Only `paid`/`fulfilled`/`refunded` orders qualify.
- Called from THREE recovery surfaces:
  1. `OrderMailListener::onOrderPaid()` calls `issueAndCollectForOrder()` as the primary path,
     persists grants, and immediately passes only the raw tokens it just created to the mail;
  2. **lazily** from both order-authenticated endpoints (§4.1): every qualifying request calls
     the idempotent ensure operation, even when some grants already exist, so a partially-issued
     tail heals as well as an entirely missing set;
  3. `commerce:downloads:backfill` CLI (operator bulk repair, tenant-aware).
- The token deep-link path cannot heal an absent grant: no token can exist before its grant.
  It only consumes an already-issued grant. Backfill/lazy-created grants remain fully usable
  through order authentication but have no recoverable raw email token.
- `expires_at` = issuance time + expiry_days (lazy issuance therefore starts the clock at
  first access, never earlier — documented, customer-favorable).
- Raw tokens exist ONLY at issuance: the creating call returns them; the row stores the hash.
  The same `OrderPaid` mail listener passes them into the email (§6). Re-reads never re-derive
  them; order-authenticated access does not need them. Definition changes cannot alter grants,
  because issuance derives exclusively from the order-line snapshot.
- **Refund policy:** partial refunds leave access unchanged. A full refund (`refunded_total >=
  grand_total`) blocks every future mint directly in the atomic access predicate, independent
  of listener delivery. An operator may set/clear the grant's audited refund-access override;
  this is distinct from ordinary revocation. The UI reports `blocked_by_full_refund` separately
  from revoked/expired/exhausted.

## 4. Access Paths (two, deliberately)

### 4.1 Order-authenticated (no second credential)

`GET /commerce/orders/{number}/downloads` — same auth as the existing order lookup (guest
token header OR authenticated owner; reuse `OrderController`'s exact access check). Response:
grants for the order — `{grant_uuid, name, remaining, expires_at, expired: bool,
revoked: bool, blocked_by_full_refund: bool}` (no token, no hash, no blob_uuid).

`POST /commerce/orders/{number}/downloads/{grantUuid}/url` — same auth and the shared atomic
mint primitive. In one transaction, claim the order first using the existing
`refund_revision` serialization row (refactored to the neutral `claimOrderFinancialMutation()`
name and shared with refunds), then re-read its refund totals. This makes a full-refund
completion and a mint serialize on the same row. Build the signed URL next (pure work; a
signing/configuration failure consumes nothing), then execute one affected-row-checked grant
UPDATE using database time:

`mint_count = mint_count + 1`, `last_minted_at = DB now`, and `remaining = remaining - 1` only
when non-null, WHERE the
tenant/order/grant match, `revoked_at IS NULL`, expiry is null or in the future, remaining is
null or positive, and the post-claim order is not fully refunded unless
`refund_access_override_at IS NOT NULL`. Exactly one affected row authorizes returning the
prebuilt URL; zero rows are classified by a read into exhausted/expired/revoked/refunded 410 or
unknown 404. Unlimited grants still increment `mint_count`, so they cannot race revocation via
an unclaimed read. The mint and revoke updates serialize on the grant row; whichever commits
first wins. Revocation cannot invalidate an already-issued signed URL, whose residual exposure
is bounded by the configured 300-second TTL.

URL composition mirrors the core controller exactly: load the snapshotted blob row, ask the
optional `BlobPublicUrlProvider` for its public base URL, fall back to the current request's
scheme/host only when no provider answers, append `/blobs/{uuid}`, then call
`SignedUrl::make(context)->generate(baseUrl, ttl)`. In a tenant-aware host this ensures a token
correlated to tenant A redirects to tenant A's public host even when the token route was reached
under another host.

### 4.2 Email deep link (token)

`GET /commerce/downloads/{token}` — public route (rate-limited like other storefront
routes). Hash the token, correlation-lookup the grant, run the SAME validation+consumption,
then **302 redirect** to the freshly minted signed blob URL (a browser-clickable email link).
Invalid/expired/exhausted → 410/404 JSON as above. Raw tokens are single-credential
bearer secrets: 160-bit random (20 bytes, hex-encoded) via the existing token generation used
for carts/orders.

Consumption counts **URL mints**, not blob fetches. A minted URL may be fetched repeatedly until
its short TTL expires; fetch-counting would require a framework blob-served callback and is out
of scope.

## 5. Blob Backstop — `BlobAccessPolicy`

Commerce ships `CommerceDownloadBlobPolicy implements BlobAccessPolicy`:
- For a blob referenced by neither a current download definition nor a grant snapshot, return
  true (neutral/no veto).
- `VIEW`: referenced blobs require `signatureValid` OR
  `authenticatedUserUuid === blob.created_by`. `INFO` permits only the creator because the core
  info action does not validate request signatures. Everything else returns false (core 404).
- `DELETE`: false while a definition references the blob, any grant snapshot still has access
  (not revoked and not expired/exhausted), or `last_minted_at + url_ttl` is still in the future.
  Operators detach definitions and revoke or exhaust/expire grants, then wait out the maximum
  signed-URL TTL before deleting the underlying merchandise file.
- `SIGN`: false for referenced blobs. Core's generic authenticated signed-URL endpoint must not
  bypass grant consumption; commerce mints URLs internally only after the §4 claim. Creator
  access remains available through direct authenticated VIEW.

**Framework composition seam (part of this layer, not backlog):** framework adds a
`BlobAccessPolicyRegistry` and a `CompositeBlobAccessPolicy`. The composite holds the shared
registry object, not a snapshot array, and reads `registry->all()` inside every authorization;
contributors registered after composite/controller construction are therefore immediately
effective. `StorageProvider` always gives the controller this composite: the existing primary
`BlobAccessPolicy` binding (or the null policy) plus every named registry contributor.
Authorization uses AND/veto semantics — every policy must return true. With no contributors,
behavior is byte-identical. Commerce registers its concrete policy as a named contributor during
boot and never binds/replaces the shared contract id. No static/global registry fallback exists.
Therefore Thallo's `TenantBlobPolicy` enforces tenant ownership AND commerce enforces grants;
neither can erase the other through provider order. Diagnostics requires the commerce
contributor to be present whenever commerce is enabled. Framework tests cover primary
only, contributor only, combined denial, deterministic registration, and duplicate-id rejection.

## 6. Email Integration (extends L1 mailer)

- `order_paid` template: when issuance for this order returned newly-created grants, the mail
  payload carries `downloads: [{name, url: <deep link with raw token>}]` and the template
  renders a downloads section. No grants → template unchanged (byte-identical for physical
  orders).
- Wiring: there is NO separate grant listener. `OrderMailListener::onOrderPaid()` first calls
  `issueAndCollectForOrder(order)`, then passes that call's raw tokens directly to `safeSend()`.
  Grants commit before mail is attempted; the event stays immutable and listener ordering is
  irrelevant. The framework already provides priority ordering, but this flow does not depend
  on it. If issuance throws, log it and still send the ordinary paid email without links;
  §4.1/backfill repairs access. If mail throws, grants remain and §4.1 remains available.
- Existing grants are never assigned a re-derived raw token. A future explicit resend flow may
  rotate token hashes, but that credential lifecycle is out of scope for v1.
- Failure isolation unchanged: mail failures never affect grants or payment.

## 7. Customers (admin) + Addresses (storefront)

**Admin customers** — aggregation over orders, NO new customer table:
- `GET /commerce/admin/customers` ($read): grouped by user_uuid when present, otherwise by
  normalized `lower(trim(email))`, per tenant. Each result carries an explicit `key_type`
  (`user` or `email`) and `key` for the detail route — `{user_uuid?, email, orders_count,
  total_spent_minor (Σ grand_total),
  refunded_minor (Σ refunded_total), first_order_at, last_order_at}`, paginated, sortable by
  last_order_at/total_spent, filter by email substring. Identity enrichment (username) via
  soft `UserProviderInterface::findByUuid` — provider absent = raw aggregation only.
- `GET /commerce/admin/customers/{key}` ($read): key = user uuid or email (explicit
  `?by=user|email`); returns the aggregate + recent orders (existing paginated projection)
  + the address book (when keyed by user).

**Storefront address book** — authenticated (`auth` middleware, same actor extraction as
`mine()`):
- `GET/POST /commerce/account/addresses`, `PATCH/DELETE /commerce/account/addresses/{uuid}`
  — all rows keyed to the authenticated user + tenant; non-revealing 404s. Setting a default
  first ensures and claims the shared `(tenant,user)` address-book row, then re-reads the
  address set and clears/sets defaults in the same transaction. Every address mutation uses
  this parent claim, including two concurrent first-address creations.
- **Checkout integration (opt-in):** `POST /commerce/checkout` accepts optional
  `shipping_address_uuid`/`billing_address_uuid` — resolved ONLY for authenticated requests,
  must belong to the caller; resolved addresses are SNAPSHOTTED into `orders.addresses`
  exactly as inline addresses are today (orders never reference the book). Inline addresses
  keep working unchanged. Mixing is allowed across kinds (for example shipping UUID + inline
  billing); supplying both a UUID and inline data for the same kind is ambiguous and returns 422.

**Guest linking** — `commerce:customers:link-guests` CLI (operator-invoked, tenant-aware):
for orders with `user_uuid IS NULL`, resolve `email` via soft
`UserProviderInterface::findByLogin`; because that contract is identifier-agnostic, stamp
`user_uuid` only when the returned identity has a non-null email whose normalized
`lower(trim(email))` exactly matches the normalized order email. A username-only match is
rejected and reported. `--dry-run` prints the plan; `--email=` narrows. **Documented risk:** linking grants order visibility
(`mine`) and download access to the account — the provider cannot attest email verification
(`UserIdentity` has no verified flag), so this stays an explicit operator action, never
automatic. Checkout's existing authenticated capture stays as-is.

## 8. HTTP Surface Summary

| Surface | Route | Auth |
|---|---|---|
| Admin downloads CRUD | `GET/POST /commerce/admin/variants/{uuid}/downloads` · `PATCH/DELETE /commerce/admin/downloads/{uuid}` · `POST /commerce/admin/grants/{uuid}/revoke` · `PUT/DELETE /commerce/admin/grants/{uuid}/refund-access-override` | $read/$write |
| Admin customers | `GET /commerce/admin/customers` · `GET /commerce/admin/customers/{key}` | $read |
| Storefront downloads | `GET /commerce/orders/{number}/downloads` · `POST /commerce/orders/{number}/downloads/{grantUuid}/url` | order access (guest token / owner) |
| Deep link | `GET /commerce/downloads/{token}` | token itself (rate-limited) |
| Address book | `GET/POST /commerce/account/addresses` · `PATCH/DELETE /commerce/account/addresses/{uuid}` | auth |
| CLI | `commerce:downloads:backfill` · `commerce:customers:link-guests` | operator |

Config: `commerce.downloads.url_ttl` (default 300). Download mutations (attach/update/
detach) claim the product via `catalog_revision` like media (same serialization family);
grant mint/revoke/override mutations use the guarded grant-row claim. Override and revoke
actions record actor-bearing order events; no raw token/hash/blob UUID appears in audit output.

## 9. Testing

- Unit: grant validation matrix (expired/revoked/exhausted/unlimited/full-refund/override);
  quantity aggregation and overflow; token hash round-trip + 160-bit shape; policy decision
  table (definition/grant referenced vs unreferenced × signature × creator × action).
- Integration: admin downloads CRUD (private-blob rule — PUBLIC blob → 422 — and
  digital-variant rule); checkout order-line entitlement snapshot and definition-edit/delete
  stability; issuance idempotency (mail-listener + order-auth lazy + backfill produce no
  duplicates), including quantity summed across add-on-distinct lines; both access paths
  end-to-end incl. atomic mint count/decrement, 410 codes, 302
  deep link with valid signed URL (`SignedUrl::validate` on the result); missed-listener
  self-heal (delete grants, hit §4.1, grants reappear); token path alone cannot heal; email
  payload carries links on first listener issuance with no preceding token-discarding listener;
  policy backstop (direct blob GET without signature → 404; generic core SIGN denied; operator
  creator VIEW passes; DELETE blocked while referenced); framework composition test proves a
  host primary denial AND commerce denial both survive; partial refund keeps access, full refund
  blocks it, audited override restores it. Customers aggregation math (incl. normalized guest
  email and refunded_minor) + enrichment soft-degrade; guest-link username collision rejected;
  address book CRUD + default swaps + checkout snapshot via address_uuid (owner-only,
  cross-user 404).
- Concurrency: deterministic — two mints on remaining=1 → one 200, one 410; unlimited
  mint-vs-revoke serializes through `mint_count`; mint-vs-full-refund serializes through the
  shared order financial claim; expiry uses DB time; two concurrent first default-address
  inserts leave exactly one default. Real PostgreSQL races cover all four distinct lock paths:
  double finite mint, unlimited mint-vs-revoke, mint-vs-full-refund, and concurrent first-default
  address creation. They reuse the existing commerce
  PostgreSQL lane (`COMMERCE_TEST_DB_DRIVER=pgsql`, `DB_PGSQL_*`, Connection A + subprocess B)
  with randomized identifiers and `finally` cleanup.
- Tenancy: two-tenant coverage (address books, addresses, downloads, grants); token correlation lookup
  cannot leak across tenants (token from tenant A used while resolver is tenant B — token
  path is correlation-style and must still serve ONLY its own row's data; assert).
- Regression: nothing changes for stores without digital products/addresses — pre-existing
  suite byte-identical; checkout without address uuids unchanged.

## 10. Sequencing (implementation order)

1. Framework policy registry/composite + StorageProvider integration + compatibility tests.
2. Migration 008, folded order-line snapshot, shape test, and tenantTables additions.
3. Downloads admin CRUD + checkout entitlement snapshot.
4. Grants: snapshot-derived idempotent service, quantity aggregation, and backfill CLI.
5. Access paths: order-authenticated pair + token deep link + atomic mint + signed URLs.
6. Commerce policy contributor + combined-policy diagnostics/tests.
7. Mail integration (`onOrderPaid` issue-and-collect, no separate grant listener).
8. Customers admin endpoints + guarded link-guests CLI.
9. Address-book parent claim + CRUD + checkout integration.
10. Tenancy/concurrency suites + regression gate + comparison-doc update.

## 11. Resolved Decisions

1. **Consumption unit:** URL mint. Fetch counting needs a blob-served callback; mint counting
   is enforceable now, and the 300-second residual URL lifetime is explicit.
2. **Customer detail keying:** explicit `?by=user|email`; never infer identity kind from a
   UUID-shaped or email-shaped route value.
3. **Refunds:** partial refunds preserve access; full refunds block future mints by default;
   an explicit audited per-grant override supports goodwill cases.
4. **Definition lifecycle:** order lines and grants snapshot the purchased download. Catalog
   edits apply prospectively and cannot silently rewrite an existing entitlement.
