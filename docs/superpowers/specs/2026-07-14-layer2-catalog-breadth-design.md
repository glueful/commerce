# Layer 2 — Catalog Breadth: Media, Categories/Tags, Attributes, Product Types, Reviews

**Status:** revision 3 after plan review
**Parent:** `2026-07-14-woocommerce-parity-overview-design.md` (Layer 2)
**Repos touched:** `glueful/commerce` only (framework blobs consumed as-is; `glueful/media` stays a soft dependency; contracts unchanged)
**Posture:** commerce is unreleased and undeployed — column additions FOLD into the original
create migrations (001/003/004); new tables get new create migrations (007+). The framework's
create-table plain-index fix is already in the synced vendored copy, so new migrations may use
inline `->index()`.

## 1. Scope

In: the data models and admin CRUD for media, categories, tags, attributes, product types
(external/grouped), add-ons, and reviews; storefront **payload enrichment** on the existing
product endpoints; cart/checkout **add-on pricing** (transactional, so it must land with the
model); review **moderation** with transactional rating rollups.

Out (deliberately — Layer 6 per the overview): storefront category-browse and attribute-filter
endpoints, storefront review submission, bulk endpoints. Out (overview non-goals): true
bundles/composites (single-line-item kits), fulltext search.

## 2. Data Model

New migration `007_CreateCommerceCatalogBreadthTables.php` (one migration, eleven tables — they
ship together or not at all):

**`commerce_product_media`** — joins products to core **blobs**; commerce stores no files.
| column | notes |
|---|---|
| uuid / tenant_uuid('') / product_uuid | usual identity + indexes |
| variant_uuid (12, nullable) | variant-specific image; when present it must resolve in the current tenant and belong to `product_uuid` |
| blob_uuid (12) | core blob; must exist, be `active`, and be **public** at attach time |
| role (16, default 'gallery') | `cover` \| `gallery` — at most one cover per product; attach/role-change/delete serialize on the product before checking this invariant |
| position (int, default 0) / alt (255, nullable) | |
| unique (product_uuid, blob_uuid) | same image attached once per product |

**`commerce_categories`** — tree.
| column | notes |
|---|---|
| uuid / tenant_uuid('') | |
| parent_uuid (12, nullable) | null = root; service enforces existing parent, no cycles, max depth 6 |
| slug (191) / name (255) / description (text, null) / position (int) / revision (int, default 0) | unique (tenant_uuid, slug); revision serializes structural/assignment mutations |
| blob_uuid (12, nullable) | category image, same public-blob rule |

**`commerce_product_categories`** — join: product_uuid, category_uuid, unique pair, both indexed.

**`commerce_tags`** — uuid / tenant_uuid / slug / name / revision (int default 0); unique
(tenant_uuid, slug). Revision serializes assignment against deletion.
**`commerce_product_tags`** — join: product_uuid, tag_uuid, unique pair.

**`commerce_attributes`** — the global taxonomy layer: uuid / tenant_uuid / slug / name /
position / revision (int default 0); unique (tenant_uuid, slug). Revision serializes values and
product assignments against deletion.
**`commerce_attribute_values`** — uuid / attribute_uuid / slug / value / position; unique
(attribute_uuid, slug).
**`commerce_product_attributes`** — product ↔ attribute:
| column | notes |
|---|---|
| uuid / product_uuid | |
| attribute_uuid (12, nullable) | null = custom per-product attribute (importer path) |
| name (255, nullable) | required when attribute_uuid is null (the custom label) |
| values (json) | list of value slugs (global) or raw strings (custom) |
| used_for_variants (bool) / visible (bool, default true) / position (int) | |
| unique (product_uuid, attribute_uuid) | ordinary composite database unique; PostgreSQL/MySQL/SQLite reject duplicate non-null attributes while permitting multiple custom rows whose attribute_uuid is null |

The existing `products.options` / `variants.option_values` JSON stays the variant-generation
mechanism, untouched. Attributes are the filterable taxonomy on top; a product MAY mirror its
options into attributes but nothing does so automatically. **Metadata stopgaps get no promotion
path** (overview rule restated: apps that improvised categories/media into `metadata` migrate
themselves).

**`commerce_product_children`** — grouped products: product_uuid, child_uuid, position; unique
pair; service resolves both products in the current tenant and enforces no self-reference. The
parent must be grouped, the child must be physical/digital, and grouped relationships are checked
in both directions: a grouped product cannot itself be a child. A set-list mutation claims the
parent plus every current/proposed child product in sorted UUID order, then re-reads and validates
the graph. This pins the graph to one level without a check-then-write race.

**`commerce_product_addons`** — per-product options:
| column | notes |
|---|---|
| uuid / tenant_uuid / product_uuid | |
| name (255) / field_type (16) | `select` \| `checkbox` \| `text` |
| required (bool, default false) | |
| choices (json, nullable) | select only: `[{key, label, price_delta}]` (signed integer minor-unit deltas) |
| price_delta (bigInteger, default 0) | checkbox/text signed flat delta; a selection may never make the final unit price negative |
| position (int) / status (16, default 'active') | |

**`commerce_reviews`**
| column | notes |
|---|---|
| uuid / tenant_uuid / product_uuid | + indexes |
| user_uuid (12, nullable) / author_name (255) / author_email (255) | guest fields |
| rating (int) | 1–5, validated |
| body (text) | |
| status (16, default 'pending') | `pending` \| `approved` \| `spam` |
| created_at / updated_at | |

**Folded columns** (into existing create migrations; dev/test DBs sync manually):
- `commerce_products` (001): `rating_sum` int default 0, `rating_count` int default 0 (avg is
  derived — integer-only math, no float column), plus `catalog_revision` int default 0 as the
  serialization claim for product-scoped relationship/set-list mutations.
- `commerce_cart_lines` (003): `addons` json nullable, `addons_hash` string(64) default `''`.
  Replace unique `(cart_uuid, variant_uuid)` with
  `(cart_uuid, variant_uuid, addons_hash)`; the old unique must not survive.
- `commerce_order_lines` (004): `addons` json nullable.

Migration 007 drops relationship/leaf tables before their taxonomy parents in `down()`. All
eleven tables are added to `DiagnosticsReport::commerceTables()`. The six tables carrying their
own `tenant_uuid` (`commerce_product_media`, `commerce_categories`, `commerce_tags`,
`commerce_attributes`, `commerce_product_addons`, `commerce_reviews`) are also added to
`DiagnosticsReport::tenantTables()`, which is the shared source for `TenantTableRegistry` and
`TenantAdopter`. The five child/join tables remain outside `tenantTables()` and may only be read,
updated, or deleted through a tenant-scoped parent join. Every relationship write first resolves
all referenced parents in the same current tenant; a caller-supplied child UUID is never an
authorization boundary by itself.

**Catalog claim protocol:** because the extension deliberately carries no foreign keys, every
relationship replacement and parent deletion shares an affected-row-checked revision claim.
Product categories/tags/attributes set-list operations claim the product first, then the union of
current and proposed taxonomy rows in stable kind+UUID order. Category/tag/attribute deletion and
attribute-value mutations claim their taxonomy parent. Product media mutations/reorder claim the
product. Product children set-list claims the parent plus the union of current/proposed children
in sorted product-UUID order. Reads used to discover a claim set are only snapshots: after all
claims succeed, the operation re-reads every row and relationship/ancestry set, validates again,
and retries or fails without writing if the set changed. Every claim must affect exactly one row.

## 3. Product Types

`type` widens by value, schema unchanged: `physical` | `digital` (existing) | `external` |
`grouped`.
- **external:** `metadata.external_url` (validated http/https on save) + optional
  `metadata.button_label`. NOT purchasable: variant creation is rejected for external/grouped
  products at `CatalogService`, and `CartService::addLine` rejects any variant whose product
  type is not physical/digital (defense in depth — 422, message names the type).
- **grouped:** a display grouping; children listed via `commerce_product_children`, purchased
  individually. Grouped products are likewise unpurchasable and variant-less.
- Creation accepts zero variants only for external/grouped products; physical/digital retain the
  existing at-least-one-variant rule. The product type is validated before variant validation so
  the current unconditional `validateVariants()` requirement cannot reject the two new types.
- Type is immutable after creation when it would strand data (physical/digital → external/
  grouped with variants present is rejected; the reverse requires empty children/URL rules).
  Simple rule: **type changes are rejected whenever the product has variants, children, or
  cart/order references** — where children means either side of a grouped relationship. Operators
  recreate instead. Cheap, safe, importer-compatible.

## 4. Add-ons and Pricing (transactional core of this layer)

- `CartService::addLine(context, cart, variantUuid, quantity, addons: [])` — new optional input:
  `[{addon_uuid, choice_key?, value?}]`.
- Validation at add time against the product's ACTIVE addon definitions: required addons
  present; select ⇒ valid choice_key; checkbox ⇒ boolean presence; text ⇒ non-empty string ≤ 500
  chars; duplicate addon UUIDs, unknown addon_uuid/choice → 422.
- **Snapshot, don't reference:** the line stores `addons` json
  `[{addon_uuid, name, field_type, choice_key?, choice_label?, value?, price_delta}]` — later
  definition edits never mutate existing carts/orders. The snapshot builder receives the variant
  price and rejects a negative final price before returning. `pricedLines()` computes
  `unit_price = variant price + Σ(price_delta from the SNAPSHOT)` and fails closed if the result
  is negative. Signed deltas are allowed; a persisted or newly-built snapshot may not create a
  negative-priced line.
- **Line identity:** the server first validates and builds the complete normalized snapshot, then
  `addons_hash` = sha256 of that canonical snapshot (all stored display/selection/price fields,
  sorted by addon_uuid; normalized text; `''` for none). Hashing the snapshot rather than only the
  submitted selection means a definition name/label/price edit produces a new line instead of
  merging newly-added quantity into an older-priced snapshot. Find-existing-line and the database
  unique both match cart_uuid + variant_uuid + addons_hash. The legacy no-addons path hashes to
  `''` and merges exactly as today.
- Every cart identity path follows the same rule: `addLine()` and guest→user cart merge combine
  only equal variant+hash lines and copy the snapshot when inserting; different hashes remain
  separate. Stock checks aggregate quantity for the variant across **all** of its add-on hashes,
  so splitting a variant into several configured lines cannot over-reserve stock.
- Checkout passes the snapshot with each priced line and `OrderRepository::orderLineRow()` encodes
  it into `commerce_order_lines.addons`; tenant-scoped line reads decode it before projection.
  Order totals already flow from line `unit_price`, so no totals-path change. Invoice-data lines
  and storefront/admin order projections gain a sanitized `addons` echo
  (name/label/value/price_delta — nothing else).

## 5. Reviews and Rollups

- Reviews enter via admin create (importer path) in this layer; storefront submission is L6.
- Moderation transitions: `pending → approved | spam`, `approved → spam` (retraction).
  **Rollups are transactional:** approve = `rating_sum += rating, rating_count += 1` guarded by
  affected-row-checked status transition (the same claim pattern as refunds — one winner);
  approved→spam reverses the increments. Derived `rating: {average, count}` (average rounded to
  1 decimal at the projection layer only) appears on product payloads when count > 0.
- Delete is itself a guarded mutation, not a read-then-delete: one affected-row-checked
  `DELETE ... WHERE tenant_uuid = ? AND uuid = ? AND status IN ('pending','spam')`. Therefore an
  approve/delete race has one winner; an approved review can never disappear while its rollup
  remains. Status claim + product rollup update execute in the same transaction and a missing
  product rolls the claim back.
- Author email never appears in any storefront payload (same whitelist discipline as refunds).

## 6. HTTP Surface

All admin routes under the existing `/commerce/admin` group, `require_scope:commerce:read`/`write`
as today; typed `RequestData` DTOs; tenant-scoped non-revealing 404s everywhere.

| Area | Endpoints (admin) |
|---|---|
| Media | `POST /products/{uuid}/media` (attach: blob_uuid, role, alt, variant_uuid?) · `PATCH /media/{uuid}` (role/alt/position) · `DELETE /media/{uuid}` · `PUT /products/{uuid}/media/order` (positions) |
| Categories | `GET/POST /categories` · `PATCH/DELETE /categories/{uuid}` · `PUT /products/{uuid}/categories` (set list) |
| Tags | `GET/POST /tags` · `DELETE /tags/{uuid}` · `PUT /products/{uuid}/tags` (set list) |
| Attributes | `GET/POST /attributes` · `PATCH/DELETE /attributes/{uuid}` · `POST /attributes/{uuid}/values` · `PATCH/DELETE /attribute-values/{uuid}` · `PUT /products/{uuid}/attributes` (set assignments) |
| Children | `PUT /products/{uuid}/children` (set ordered list; grouped only) |
| Add-ons | `GET/POST /products/{uuid}/addons` · `PATCH/DELETE /addons/{uuid}` |
| Reviews | `GET /reviews` (filter: status, product) · `POST /reviews` (admin/importer create) · `POST /reviews/{uuid}/approve` · `POST /reviews/{uuid}/spam` · `DELETE /reviews/{uuid}` (pending/spam only — approved must be spammed first so rollups stay truthful) |

DELETE semantics: category delete detaches products and re-parents children to the deleted
node's parent; attribute delete cascades its values + product assignments; media detach never
touches the blob (blob lifecycle stays the app's/core's concern); tag delete detaches. Category
parent changes, child attachment, and deletion share sorted per-tenant/category mutation locks.
Delete claims the target and performs reparent + detach + delete in one transaction, so a
concurrent writer cannot attach a child to a disappearing category. Tag/attribute assignment and
delete use the same claim-before-re-read discipline; attribute-value mutations claim the owning
attribute, so no relationship or value row can be inserted after its parent disappears.

**Storefront additions (existing endpoints only, additive):**
- `GET /commerce/products/{slug}`: gains `media` (cover first, then gallery by position:
  `{blob_uuid, url: '/blobs/{uuid}', role, alt, variant_uuid}`), `categories` `[{slug, name}]`,
  `tags` `[{slug, name}]`, `attributes` (visible only: `[{slug|name, values}]`), `addons`
  (active only, full definitions incl. deltas — the storefront needs them to price the picker),
  `children` (grouped: `[{slug, name, cover_url}]`), `rating {average, count}` (when count > 0),
  and for external: `external {url, button_label}`.
- `GET /commerce/products`: each item gains `cover_url` and `rating`.
- Media URLs are PATHS (`/blobs/{uuid}`) — origin resolution stays with the host
  (`BlobPublicUrlProvider` exists framework-side for apps that need absolute URLs).

## 7. Soft Dependencies & Blob Considerations

- **Blob validation at attach:** blob exists, `status = 'active'`, visibility public. Blobs are
  NOT tenant-scoped in core — commerce records the reference it was given. A hostile operator
  could attach another tenant's public blob uuid; it is already public by definition, so this
  leaks nothing, but the attach event records the actor (existing scope auth covers write
  access). Documented, not blocked.
- **`glueful/media` absent:** everything works; gallery URLs serve originals. Present: thumbs
  exist blob-side; commerce still returns the original `/blobs/{uuid}` path and leaves
  variant/thumb negotiation to the blob route's own params. No commerce code depends on the
  media extension.

## 8. Testing

- Unit: addon validation matrix + snapshot pricing math + addons_hash canonicalization; category
  cycle/depth rules; review rollup math incl. the affected-row-checked transitions; type-change
  rejection rules.
- Integration: every admin endpoint (happy + 404 + validation); cart addLine with addons
  (folded composite unique, separate-line identity, definition price edit creates a new hash/line,
  guest-cart merge by variant+hash, stock aggregation across hashes, negative-final-price refusal,
  snapshot immutability, checkout snapshot copy);
  external/grouped purchase rejection; storefront payload enrichment (whitelists: no author
  email, no addon internals beyond the sanitized echo); media attach validation (missing/
  non-public blob → 422; variant from another product/tenant → non-revealing failure), including
  concurrent cover mutations proving at most one cover survives.
- Tenancy: two-tenant coverage for every new tenant-scoped table (slugs reusable across tenants;
  cross-tenant 404s); join tables reached only through tenant-scoped parents; diagnostics,
  registry, and sentinel adoption include exactly the six tenant-bearing additions.
- Concurrency/invariant coverage: approve-vs-delete leaves either no review/no rollup or an
  approved review/one rollup; duplicate approve/spam cannot double-apply; category child-write,
  tag assignment, attribute assignment/value mutation, and product-child set-list races cannot
  leave an orphan or invalid graph. Run real two-connection races in the PostgreSQL lane using
  the existing commerce contract (`COMMERCE_TEST_DB_DRIVER=pgsql`, `DB_PGSQL_*`, independent
  `Connection` A plus subprocess `Connection` B); retain deterministic affected-row/lock
  invariant tests in the default harness.
- Regression gate: with none of the new features used, the pre-existing suite (175) passes
  byte-identical; a no-addons cart hashes to `''` and matches v1 line-merging exactly.

## 9. Sequencing (implementation order)

1. Migration 007 + folded columns (001/003/004) + SchemaShape-style test.
2. Media: repository/service/admin endpoints + storefront enrichment.
3. Categories + tags: models, tree rules, endpoints, product assignment, storefront echo.
4. Attributes (+ values + product assignments) + storefront echo.
5. Product types: external/grouped rules (catalog + cart gates) + children endpoint + storefront.
6. Add-ons: definitions CRUD + cart/checkout snapshot pricing + projections (the money task).
7. Reviews: moderation endpoints + transactional rollups + product rating projection.
8. Tenancy suite + regression gate + comparison-doc update (media/categories/attributes/reviews
   move to "can migrate"; product types annotated).

## 10. Resolved Decisions

1. `commerce_product_attributes (product_uuid, attribute_uuid)` uses an ordinary composite
   database unique. Nullable `attribute_uuid` gives the required custom-row exemption on all
   three supported drivers; there is no service-only uniqueness race.
2. `PUT /products/{uuid}/categories` uses idempotent set-list semantics, matching importer and
   admin-UI usage.
3. Add-ons are allowed on digital products. Add-on field types have no physical fulfillment
   dependency; the same snapshot, identity, and non-negative-final-price rules apply.
