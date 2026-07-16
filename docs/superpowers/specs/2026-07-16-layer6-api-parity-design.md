# Layer 6 — API Parity (Detail Design)

**Status:** revision 2 — review corrections integrated
**Parent:** `2026-07-14-woocommerce-parity-overview-design.md` §Layer 6
**Repo scope:** commerce only. No new tables. One pre-launch schema fold is required:
`commerce_discounts.revision` in migration 005, used to serialize discount deletion against
checkout redemption. Any storefront-filter index gap folds into migration 007.

## 1. Scope

Recon result: OpenAPI annotation coverage on existing routes is already effectively 100% and
the framework generator consumes it, so this layer is four concrete work streams:

1. **Pagination + filter retrofit** on the unbounded admin lists (products, tags,
   attributes, shipping zones, shipping classes, tax rates, discounts, and the new
   cross-order refunds list). Categories retain their bounded tree; methods, per-order
   refunds, and per-variant downloads remain bounded child arrays.
2. **CRUD gap-fill**: missing shows, missing writes (tag update, product delete, discount
   delete), a cross-order admin refunds list, and a per-order notes read.
3. **Bulk endpoints** (all greenfield): product bulk status, variant bulk price, review
   bulk moderation. Review moderation already has guarded transitions; product status and
   variant price require new shared guarded mutation paths used by both single and bulk writes.
4. **Storefront additions**: category browse, category/tag/attribute filters on the product
   list, public review submit + list.

Plus: harden the OpenAPI enforcement test so future routes cannot ship unannotated.

## 2. Pinned decisions (review these first)

1. **Pagination retrofit is envelope-breaking and that is fine** (pre-launch posture: no
   deployed consumers). Every retrofitted list moves to the house offset pattern
   (`Response::paginated`, `per_page` clamp `max(1, min(100, ?? 24))`, default 24) with a
   `*ListQuery` DTO. Retrofitted: admin products (currently a raw unbounded
   `db()->get()`), tags, attributes, shipping zones, shipping classes, tax rates,
   discounts, cross-order refunds (new). NOT retrofitted: per-zone methods, per-variant
   downloads, per-order refunds — bounded child collections stay unpaginated arrays
   (documented); **categories keep the tree shape** (bounded by the depth-6 invariant and
   catalog scale; a flat paginated categories list serves no importer need since the
   import map handles idempotency). Every paginated query has a stable UUID tie-break after
   its primary sort, and the count and row queries use the same predicates.
2. **List filters** (each optional, combined with AND):
   - admin products: `status` (`draft|active|archived`), `q` (case-insensitive literal
     substring on product name; escape `%`, `_`, and the escape character), `type`
     (`physical|digital|external|grouped`).
   - tags/attributes/shipping classes: `q` (name/slug literal substring).
   - tax rates: keep `country`/`class`, add pagination.
   - discounts: `status`, `q` (code literal substring).
   - refunds (new cross-order list): `status`, `order` (order uuid), `from`/`to` on
     `completed_at` reusing the L5 `date:Y-m-d` convention (no ReportWindow — plain
     optional bounds, half-open, no 366 cap). Pending/failed rows have no `completed_at`
     and therefore do not match a request that supplies either date bound.
   - reviews list already filters `status`/`product` — unchanged.
   Every `q` filter is case-insensitive and escapes SQL LIKE metacharacters; `%` and `_`
   in user input are literals, not wildcard operators.
   Product status is promoted to one declared commerce vocabulary (`draft|active|archived`)
   consumed by create, ordinary update, list filtering, and bulk status. Unknown values are
   rejected at the write/query boundary rather than persisted as open strings. Product type
   likewise moves from `CatalogService`'s private constant to one shared declaration consumed
   by create/update and filtering.
3. **Show endpoints added** (GET single, admin `commerce:read`): categories, tags,
   attributes (embedding values, as the list does), shipping zones (embedding locations +
   methods), shipping methods, shipping classes, tax rates, discounts, reviews, refunds
   (`GET /refunds/{uuid}` — cross-order path). NOT added: attribute values, media, addons,
   order notes (nested resources; their parent shows carry them). All 404 via the existing
   not-found exception pattern; tenant-scoped lookups.
4. **New writes:**
   - `PATCH /tags/{uuid}`: rename (name; slug immutable like shipping classes — slug is
     referenced by storefront filters). Tags already carry `revision` and
     `TagRepository::claimRevision()`; rename runs claim → post-claim re-read → update in one
     transaction. Unknown/cross-tenant tags remain non-revealing 404s.
   - `DELETE /products/{uuid}`: **soft delete** (claim `catalog_revision`, post-claim
     re-read, reject an existing tombstone, then set `deleted_at` using DB time in the same
     transaction). The existing repositories are not uniformly soft-delete-aware, so this
     layer introduces an explicit live-product lookup and migrates every interactive admin,
     storefront, relationship, review-create, and cart/catalog mutation to it. Only named
     history/integrity paths (order snapshots and review rollup reversal) may address a
     tombstoned row. Admin/storefront catalog lists and stock reports exclude tombstones;
     historical sales/product reports deliberately retain snapshot activity. Variants,
     stock, and media rows remain; the tombstone retains and continues reserving its slug,
     so create/rename uniqueness checks deliberately include deleted rows and return a
     normal slug-in-use 422 instead of leaking a database unique error. Re-delete → 404.
     Cart add/reprice/checkout must not silently drop a tombstoned product line (the current
     `pricedLines()` missing-row `continue` would do so): it returns a controlled unavailable-
     product 422. A checkout that already read the product as live may finish; soft delete is
     not retroactive order cancellation. Restore remains out of scope.
   - `DELETE /discounts/{uuid}`: guarded hard delete. Fold `revision` into migration 005,
     then run claim → post-claim redemption probe → delete in one transaction. Checkout's
     `consumeUsage()` updates the same discount row before inserting the redemption, so the
     shared row lock serializes both orderings: delete-first makes consume affect zero and
     roll back checkout; consume-first makes delete observe the committed redemption and
     return **409** with the “disable via status” hint. Historical orders are unaffected
     (they snapshot `discount_code`). A deterministic claim test plus a pgsql-gated
     two-connection delete-vs-redemption race locks this invariant in. Ordinary discount
     PATCH also uses the revision claim and checked affected rows, so a concurrent delete can
     never yield a false-success update.
   - `GET /orders/{uuid}/notes`: reads the order's `note.added` events (notes are already
     embedded in admin order show; this adds the symmetric read for the existing POST).
     Unpaginated (bounded per order).
5. **Bulk endpoints** — uniform semantics:
   - `POST /commerce/admin/products/bulk-status` `{uuids: [...], status: "..."}`
   - `POST /commerce/admin/variants/bulk-price` `{items: [{uuid, price}]}`
   - `POST /commerce/admin/reviews/bulk` `{action: "approve"|"spam"|"delete", uuids: [...]}`
   - Batch cap **100** items (422 above); duplicate uuids in one request → 422.
   - **Per-item atomicity, not per-batch**: items processed in input order, each through
     a guarded service entry point. `ReviewService` reuses its existing transition/delete
     primitives. Product status gets a shared claim → live re-read → set path; variant price
     resolves variant → product, claims the parent product's `catalog_revision`, re-reads
     both live parent and variant, then sets the price. Ordinary PATCH status/price writes
     route through those same paths, so a bulk write cannot race an unguarded single write.
     One expected item failure never rolls back neighbors.
   - Response **200** always (when the request itself validates):
     `{applied: [uuid...], failed: [{uuid, reason}]}`. The reason vocabulary is closed:
     product/variant = `not_found`; review = `not_found|invalid_transition`. These row-
     locking claims serialize rather than
     reporting optimistic “claim conflicts”; deadlocks/connection errors remain request-
     level failures. Controllers catch only the named domain failures; an unexpected
     exception aborts with 500 instead of being mislabeled as an item failure. No exception
     message or foreign-resource detail is reflected.
     Write scope (`commerce:write`). No idempotency keys: bulk status/price set and
     moderation transitions are naturally idempotent or claim-guarded per item; replays
     converge (an already-approved review fails `invalid_transition` on replay — reported
     in `failed`, harmless).
6. **Storefront additions** (public, rate-limited via the existing `$rate()` config
   closure pattern; NO auth):
   - Add `commerce.rate_limits.catalog` and apply it to product list/show, category tree,
     and approved-review list; add the stricter `commerce.rate_limits.review_submit` for
     review POST. Products currently have no rate middleware, so these are explicit config
     additions rather than reuse of a nonexistent key.
   - `GET /commerce/categories`: all tenant categories, independent of product activity
     (categories have no active/status column), in the admin tree shape minus internal
     fields. The recursive public allowlist is exactly
     `slug,name,description,position,image_url,children`; `image_url` is null or
     `/blobs/{blob_uuid}`. UUID, tenant UUID, raw blob UUID, and revision never leave this
     surface.
   - Product list filters on `GET /commerce/products`: `category` (slug — includes
     descendant categories' products? **No: exact category only**, Woo's default is
     hierarchical but our tree endpoint gives clients descendant slugs; exactness keeps
     the contract explicit), `tag` (slug), `attributes` (`attribute-slug:value-slug` pairs,
     comma-separated,
     AND semantics, e.g. `?attributes=color:red,size:m`; max 5 pairs, 422 above; unknown
     names/values simply match nothing — enumeration-neutral). All filters compose with
     existing pagination. Resolve category/tag/attribute slugs in a bounded tenant-scoped
     batch, then apply correlated `EXISTS` semijoins (one per attribute pair), never result-
     multiplying joins; count and row queries share the exact predicates. Attribute value
     membership uses a commerce-local, exact `JsonStringArrayContainsSql` driver helper
     (SQLite `json_each`, PostgreSQL JSON containment, MySQL `JSON_CONTAINS`) over the stored
     JSON slug list. The framework's generic `whereJsonContains()` fallback is text-LIKE on
     SQLite/PostgreSQL and is not exact enough (`red` must not match `bred`). Custom free-text
     product attributes are deliberately not addressable by this stable slug API. Order is
     `created_at DESC, uuid ASC`, preventing page drift on ties.
   - `POST /commerce/products/{slug}/reviews`: public submit → lands `pending` (never
     auto-approved). Guest: `author_name` + `author_email` required (bounded to column
     widths); `rating` int 1..5, `body` required with a 10,000-character HTTP cap. The route is
     genuinely public and the framework has no optional-auth middleware, so v1 stores
     `user_uuid = null` even when a client happens to send credentials — it never trusts a
     caller-supplied user UUID. Authenticated attribution is deferred until a reusable
     optional-auth seam exists. The product must resolve as live+active before
     `ReviewService::create`; a draft or tombstoned product is the same 404 as unknown.
     Response 201 contains only `{status: "pending"}`. No verified-purchase flag (the schema
     has no order link; out of scope).
   - `GET /commerce/products/{slug}/reviews`: first resolve the same live+active storefront
     product guard, then return **approved only**, paginated, `created_at DESC, uuid ASC`;
     expose rating/body/author_name/created_at (never email/user_uuid/status or hidden-row
     counts).
7. **OpenAPI enforcement hardened**: `HttpDocumentationTest` moves from a 9-controller
   hand-list to `Router::getAllRoutes()`, filtered to handlers in the commerce HTTP
   namespace (so the extension does not claim framework/application routes). For every
   registered `[class, method]` commerce action, assert `#[ApiOperation]` plus at least one
   `#[ApiResponse]`; fail explicitly on an unsupported commerce handler shape. This checks
   route actions rather than every public method on selected controllers and includes all
   storefront/admin/account controllers registered by `routes.php`.
   Every new Layer 6 route/DTO annotated to the house standard (`#[FromQuery]` +
   `#[Rule]`; `@queryParam` style not needed — DTOs carry the schema).
8. **No cursor pagination** (settled in L5); no new migration file. Fold discount
   `revision` into 005. Migration 007 already indexes both directions of category/tag
   links and product UUID on product attributes; a shape/query-plan test confirms these
   support the `EXISTS` predicates, and any demonstrated gap folds into 007.
9. **Bulk validation boundary**: use nested `RequestData` items (`#[ArrayOf]`) rather than
   manually trusting arrays. Malformed UUIDs, non-integer/negative prices, duplicate UUIDs,
   and batches over 100 reject the whole request with 422 before any mutation. Per-item
   failures are only runtime resource/state/claim outcomes from an otherwise valid request.
   Bulk status validates the status
   against the product status vocabulary up front (422 — one bad status fails the whole
   request since it applies to all items).

## 3. Endpoint delta table

| # | Method + path (under `/commerce/admin` unless noted) | Change |
|---|---|---|
| 1 | GET `/products` | + pagination + `status`/`q`/`type` filters |
| 2 | GET `/tags`, `/attributes`, `/shipping/classes` | + pagination + `q` filter |
| 3 | GET `/shipping/zones`, `/discounts`, `/tax/rates` | + pagination (+ `status`/`q` on discounts) |
| 4 | GET `/categories/{uuid}`, `/tags/{uuid}`, `/attributes/{uuid}`, `/shipping/zones/{uuid}`, `/shipping/methods/{uuid}`, `/shipping/classes/{uuid}`, `/tax/rates/{uuid}`, `/discounts/{uuid}`, `/reviews/{uuid}` | new shows |
| 5 | GET `/refunds` + GET `/refunds/{uuid}` | new cross-order refund list/show |
| 6 | GET `/orders/{uuid}/notes` | new (symmetric with POST) |
| 7 | PATCH `/tags/{uuid}` | new (rename) |
| 8 | DELETE `/products/{uuid}` | new (soft) |
| 9 | DELETE `/discounts/{uuid}` | new (guarded 409) |
| 10 | POST `/products/bulk-status`, `/variants/bulk-price`, `/reviews/bulk` | new bulk |
| 11 | GET `/commerce/categories` (storefront) | new |
| 12 | GET `/commerce/products` (storefront) | + `category`/`tag`/`attributes` filters |
| 13 | POST + GET `/commerce/products/{slug}/reviews` (storefront) | new |

## 4. Components

| Unit | Responsibility |
|---|---|
| `src/Http/DTOs/` new/extended `*ListQuery` DTOs | products (admin+storefront extensions), tags/attributes/classes `q`, discounts, refunds; bulk DTOs plus nested `BulkPriceItemData`; `StoreReviewData` (storefront submit) |
| Existing admin controllers | gain show/list/filter/delete/bulk actions in place (no new controller unless a file is unwieldy; `AdminRefundController` gains the cross-order list/show) |
| `src/Http/Storefront/ReviewController.php` (new) | public submit + approved list |
| `src/Http/Storefront/CategoryController.php` (new) | public tree |
| `src/Catalog/ProductStatus.php`, `ProductType.php` | shared validation vocabularies for create/update/filter/bulk |
| `src/Catalog/*` services/repositories | explicit live-vs-history product reads; product soft delete; guarded status/price writes shared by single+bulk; tag rename; storefront `EXISTS` filters |
| `src/Support/JsonStringArrayContainsSql.php` | exact cross-driver JSON string-array membership used inside attribute-filter semijoins |
| `src/Catalog/ReviewService` | active/live storefront create guard; bulk moderation loop reusing `claimTransition`/`guardedDelete` |
| `src/Discounts/*` | revision claim + guarded delete; list pagination/filters |
| `tests/Integration/Http/*` | per-surface endpoint tests + the hardened `HttpDocumentationTest` |

## 5. Tests

- Retrofit: every paginated list — envelope keys, clamp, stable tie ordering, filter
  matrix, literal-LIKE escaping, count/row agreement, tenant isolation.
- Shows: found/404/cross-tenant-404 per resource.
- Product delete: DB-time soft stamp; all interactive reads/mutations and stock report hide
  it; sales/product historical reports retain its order snapshots; 404 on re-delete;
  variants/stock/media and order-line snapshots untouched; slug remains reserved with a
  controlled 422 on reuse; cart add/reprice/checkout refuses rather than silently dropping
  the line; concurrent deletes serialize with exactly one success.
- Discount delete: zero-redemptions deletes; redeemed → 409 with intact row; cross-tenant
  404; deterministic shared-claim invariant + pgsql-gated redemption/delete race in both
  orderings.
- Bulk: per-item outcomes (mixed applied/failed in one request), input-order processing,
  cap-101 → 422, duplicate uuid → 422, malformed/negative nested price → 422 before any
  write, bad status vocabulary → 422 whole-request, single-write-vs-bulk serialization,
  replay convergence
  (re-run same bulk → idempotent/failed-invalid-transition, never corruption), scope
  enforcement (write required); unexpected exception proves request-level 500 rather than
  a fabricated item failure.
- Storefront: category tree includes all tenant categories and excludes internal fields;
  product filters (each alone, combined AND, unknown slug → empty page not error,
  attributes cap, `red` does not match `bred`, duplicate-producing fixtures keep exact
  totals/pages, stable tie order, query-count guard); review submit (required-fields/length
  422 matrix, caller-supplied user UUID ignored, live+active product required,
  always-pending, rate-limit config wired) + public list (approved only, field allowlist,
  pagination); enumeration neutrality (pending/spam reviews invisible with no count leak).
- OpenAPI: hardened enforcement test walks every registered commerce route action and
  fails on an unannotated or unsupported handler — including all routes this layer adds.
- pgsql lane: exact JSON/`EXISTS` storefront filters, discount delete-vs-redemption race,
  and bulk serialization exercised in the committed gated lane (extend
  `ReportPgsqlTest`'s environment-gated pattern with a focused L6 file).
- Regression gate: untouched surfaces byte-identical; existing suite green.

## 6. Out of scope

Woo-style batch create/delete for every resource (only the three pinned bulk endpoints);
category-descendant product filtering; verified-purchase review flags; review update/reply;
media standalone list; restore/untrash endpoints; cursor pagination; per-resource
idempotency keys on bulk; authenticated review attribution until the framework exposes a
reusable optional-auth middleware/profile.
