# Layer 2 — Catalog Breadth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Layer 2 (media, categories/tags, attributes, product types, add-ons, reviews) per `docs/superpowers/specs/2026-07-14-layer2-catalog-breadth-design.md` (revision 3).

**Architecture:** Eleven new tables in migration 007 + three folded column sets; add-on pricing rides `CartService::pricedLines()` with server-built snapshot hashing as line identity; every catalog relationship mutation uses affected-row-checked revision claims followed by mandatory post-claim re-reads; storefront changes are payload enrichment only.

**Tech Stack:** PHP 8.3, framework via synced vendored copy (includes the create-table index fix — inline `->index()` works), PHPUnit (175-test baseline, SQLite `:memory:` harness), extension-contracts path dep.

## Global Constraints

- Repo: `~/Sites/glueful/extensions/commerce` only. Branch `dev`. Commits at group boundaries, conventional style, NO attribution trailers. Never stage `docs/superpowers/**` or `.superpowers/**`.
- FOLD POSTURE (unreleased/undeployed): column additions edit create migrations 001/003/004 directly; new tables are one new migration `007_CreateCommerceCatalogBreadthTables.php` (exactly eleven tables; `down()` drops relationship/leaf tables before taxonomy parents). Dev/test DBs sync manually.
- **Cart-line unique changes:** 003's `(cart_uuid, variant_uuid)` unique becomes `(cart_uuid, variant_uuid, addons_hash)` — the old form must NOT survive; prove with a same-variant/two-hash insert test.
- **No `lockForUpdate()` exists.** Serialization primitives, by surface:
  - reviews: affected-row-checked status-claim UPDATE (one winner) + rollup in the same transaction; delete is one guarded `DELETE ... WHERE tenant AND uuid AND status IN ('pending','spam')`;
  - taxonomy: categories, tags, and attributes carry `revision` int default 0; products carry `catalog_revision` int default 0. Product category/tag/attribute set-lists claim the product first, then the union of current + proposed taxonomy rows in stable kind/UUID order. Taxonomy delete and attribute-value mutation claim the affected taxonomy row;
  - media attach/role-change/delete/reorder claims the product; children set-list claims the union of parent + current children + proposed children in sorted product-UUID order; type changes claim the product;
  - claim sets are computed from a read-only snapshot, but no mutation trusts that snapshot: after all claims succeed, re-read the current relationships and referenced rows inside the transaction, revalidate every invariant, then write. Every claim checks exactly one affected row; a changed/missing target fails or retries without mutating.
- **Concurrency tests:** deterministic claim/affected-row tests run in SQLite. Real races reuse the existing `GatewayRefundTest` PostgreSQL lane exactly: gate on `COMMERCE_TEST_DB_DRIVER=pgsql`, construct Connection A from `DB_PGSQL_HOST/PORT/DATABASE/USERNAME/PASSWORD/SCHEMA`, run `static::MIGRATIONS`, and drive Connection B in a subprocess. Use randomized identifiers and `finally` cleanup so the lane is directly runnable rather than merely documented.
- Tenant rules: the six tenant-bearing new tables (`commerce_product_media`, `commerce_categories`, `commerce_tags`, `commerce_attributes`, `commerce_product_addons`, `commerce_reviews`) join `DiagnosticsReport::tenantTables()` (feeding registry + adopter); the five child/join tables are reachable ONLY through tenant-scoped parents; every relationship write resolves all referenced parents in the current tenant first; non-revealing 404s.
- Money: signed add-on deltas, integer minor units; final unit price < 0 fails closed (422 at build time; defensive re-check in pricedLines on persisted snapshots).
- Storefront whitelists: no `author_email`, no addon internals beyond `{name, field_type?, choice_label?, value?, price_delta}` echo on lines; media/category blob URLs are PATHS `/blobs/{uuid}`. Framework `BlobRepository` rows expose `status` and `visibility`; attach accepts only `status='active'` + `visibility='public'`.
- Scopes `commerce:read`/`commerce:write`; typed RequestData DTOs (verified grammar: plain rules + domain checks); house factory pattern with `use` imports.
- Regression gate: features unused ⇒ pre-existing 175-test suite byte-identical; no-addons carts hash `''` and merge exactly as v1.
- Quality gates per group: `composer test`, `composer phpcs`, `composer run analyze` — clean.

---

## GROUP A — Schema

### Task 1: Migration 007 + folded columns + shape test

**Files:**
- Create: `migrations/007_CreateCommerceCatalogBreadthTables.php` (eleven tables per spec §2, verbatim column/unique shapes; `revision` default 0 on categories, tags, and attributes)
- Modify: `migrations/001_CreateCommerceCatalogTables.php` (products: `rating_sum` int 0, `rating_count` int 0, `catalog_revision` int 0), `migrations/003_CreateCommerceCartTables.php` (cart lines: `addons` json null, `addons_hash` string(64) `''`, unique swap), `migrations/004_CreateCommerceOrderTables.php` (order lines: `addons` json null)
- Modify: `src/Support/DiagnosticsReport.php` (+11 to commerceTables; +6 to the tenantTables source), `tests/Support/CommerceTestCase.php` (register 007), `tests/Integration/MigrationsTest.php`
- Test: `tests/Integration/Migrations/CatalogBreadthShapeTest.php`

- [ ] **Step 1 (TDD):** Shape test RED: all eleven tables exist post-migrate; category/tag/attribute revisions and product catalog_revision exist with default 0; composite uniques behave (same category slug two tenants OK / same tenant rejected; `(product_uuid, attribute_uuid)` rejects duplicate non-null while allowing multiple null-attribute custom rows — freeze the source-verified SQLite behavior; same blob twice on one product rejected); cart-line triple unique: same cart+variant with two different hashes inserts, same hash rejected, OLD pair-unique provably gone; folded product/order-line columns present with defaults.
- [ ] **Step 2:** Implement; MigrationsTest + CommerceTestCase updated. Full suite green (nothing consumes the columns yet). phpcs/analyze clean. NO commit.

---

## GROUP B — Catalog surfaces

### Task 2: Media

**Files:**
- Create: `src/Catalog/ProductMediaRepository.php`, `src/Catalog/ProductMediaService.php`, `src/Http/Admin/AdminMediaController.php`, `src/Http/DTOs/AttachMediaData.php`, `src/Http/DTOs/UpdateMediaData.php`, `src/Http/DTOs/ReorderMediaData.php`
- Modify: `routes.php`, provider, `src/Http/Storefront/ProductController.php` (payload enrichment: `media` on show, `cover_url` on index)
- Test: `tests/Integration/Http/MediaEndpointTest.php`, `tests/Integration/Catalog/MediaTenancyConcurrencyTest.php`

**Interfaces (Produces):** `ProductMediaService::attach(context, productUuid, input): array` · `update/detach/reorder`; blob validation via framework `BlobRepository` (soft-resolve; blob must exist with `status='active'` and `visibility='public'`); variant_uuid (when given) must resolve in-tenant AND belong to the product.
- Cover invariant: attach/role-change/delete/reorder claim the product (`catalog_revision` bump) FIRST in the transaction, then re-read media/variant state and enforce at-most-one-cover (demote existing cover to gallery on new cover attach — deterministic, not rejected).
- [ ] TDD: endpoint tests (attach happy/`404` product/`422` missing-or-nonpublic blob/variant-of-other-product non-revealing failure), reorder, storefront enrichment (cover first, gallery by position, path URLs), deterministic concurrent-cover test (claim + demote ⇒ exactly one cover) + runnable PostgreSQL-lane race. Then implement. Suite green; NO commit.

### Task 3: Categories + tags

**Files:**
- Create: `src/Catalog/CategoryRepository.php`, `src/Catalog/CategoryService.php`, `src/Catalog/TagRepository.php`, `src/Http/Admin/AdminCategoryController.php`, `src/Http/Admin/AdminTagController.php`, DTOs (CreateCategoryData, UpdateCategoryData, CreateTagData, SetProductCategoriesData, SetProductTagsData)
- Modify: routes, provider, storefront ProductController (categories/tags echo on show)
- Test: `tests/Integration/Http/CategoryEndpointTest.php`, `TagEndpointTest.php`, `tests/Integration/Catalog/CategoryTreeConcurrencyTest.php`

**Binding rules:** tree invariants (existing in-tenant parent, no cycles — walk ancestors, max depth 6); category mutations that touch structure (reparent, delete, child-attach) claim the involved category rows via `revision` bump in sorted-uuid order inside one transaction. Delete snapshots the target/parent/children, claims that union, then re-reads and revalidates the tree before reparenting children, detaching products, and deleting. Product category/tag set-lists claim the product first and the union of current + proposed category/tag rows in stable kind/UUID order, then re-read every row and relationship before replacement. Tag delete claims the tag before detaching and deleting. Every claim must affect exactly one row.
- [ ] TDD: CRUD + cycle/depth rejections + slug-per-tenant; delete-reparents test; deterministic delete-vs-child-attach and tag-delete-vs-assignment tests + runnable pgsql races; storefront echo; idempotent category/tag set-lists plus concurrent replacement tests proving no orphan joins. Implement. Suite green; NO commit.

### Task 4: Attributes

**Files:**
- Create: `src/Catalog/AttributeRepository.php`, `src/Catalog/AttributeService.php`, `src/Http/Admin/AdminAttributeController.php`, DTOs (CreateAttributeData, UpdateAttributeData, CreateAttributeValueData, UpdateAttributeValueData, SetProductAttributesData)
- Modify: routes, provider, storefront (visible-only attributes echo)
- Test: `tests/Integration/Http/AttributeEndpointTest.php`

**Binding rules:** global attributes + values CRUD (value slugs unique per attribute). Attribute value create/update/delete and attribute delete claim the attribute revision, then re-read before mutation. Product assignment set-list claims the product plus the union of current + proposed attributes in stable order, then re-reads definitions/values and relationships; rows with `attribute_uuid` must resolve in-tenant and `values` must be that attribute's value slugs. Custom rows (`attribute_uuid` null) require `name`, values are free strings; DB composite unique is the duplicate guard (catch duplicate-key → 422, no pre-read race). Delete attribute detaches assignments and removes values in the same transaction after the post-claim check.
- [ ] TDD: CRUD/set-list behavior plus deterministic attribute-delete/value-edit-vs-assignment conflicts and runnable pgsql races; assert no orphan joins and no writes from a stale pre-claim snapshot. Then implement. Suite green; NO commit.

### Task 5: Product types + children

**Files:**
- Modify: `src/Catalog/CatalogService.php` (type validation order — type check BEFORE `validateVariants()`; zero-variant creation allowed ONLY for external/grouped; external_url http/https validation; type-change rejection when variants OR either-direction children OR cart/order line references exist), `src/Cart/CartService.php` (addLine rejects non-physical/digital product types, 422 naming the type), `src/Http/Admin/AdminProductController.php` (children set-list endpoint), storefront (children + external echo)
- Create: `src/Catalog/ProductChildrenRepository.php`, `src/Http/DTOs/SetProductChildrenData.php`
- Test: `tests/Integration/Http/ProductTypesTest.php`

**Binding rules:** children set-list snapshots the parent/current/proposed child UUIDs, claims their union in sorted product-UUID order, then re-reads the parent, current relationships, and proposed children before validation/write. Parent must be `grouped`; children must be physical/digital; a grouped product cannot be a child anywhere (both directions checked); no self-reference. Type changes claim the product and, after re-read, reject when variants, either-direction children, or cart/order references exist; the guard counts BOTH `product_uuid` and `child_uuid` sides.
- [ ] TDD: type/children behavior plus deterministic children-replacement-vs-child-type-change/delete conflicts and runnable PostgreSQL-lane races; prove the post-claim re-read catches changed membership and leaves no stale relationship. Then implement. Suite green.
- [ ] **COMMIT (Group B):** `feat(catalog): media, categories, tags, attributes, product types`

---

## GROUP C — Add-ons (the money task)

### Task 6: Add-on definitions + cart/checkout snapshot pricing

**Files:**
- Create: `src/Catalog/AddonRepository.php`, `src/Cart/AddonSnapshot.php` (pure: validate + build + hash), `src/Http/Admin/AdminAddonController.php`, DTOs (CreateAddonData, UpdateAddonData)
- Modify: `src/Cart/CartService.php` (addLine signature + find-existing by variant+hash + snapshot storage; `mergeIntoUser()`/private `mergeLine()` merge only equal variant+hash and copy snapshots on insert), `src/Cart/CartRepository.php`, `src/Orders/CheckoutService.php` (`placeOrder()` stock loops aggregate quantity per variant ACROSS hashes and pass snapshots into persistence), `src/Orders/OrderRepository.php` (`orderLineRow()` encodes addons; `linesForOrder()` decodes them), `src/Pricing/PricingEngine.php` consumers via `pricedLines()` (unit_price = variant + Σ snapshot deltas; negative ⇒ fail closed), `src/Http/Storefront/OrderController.php`, `src/Http/Admin/AdminOrderController.php`, and `src/Orders/InvoiceData.php` (explicit sanitized line projections)
- Test: `tests/Unit/Cart/AddonSnapshotTest.php`, `tests/Integration/Cart/CartAddonsTest.php`, `tests/Integration/Orders/CheckoutAddonsTest.php`

**AddonSnapshot contract (Produces — complete):**

```php
final class AddonSnapshot
{
    /**
     * Validate selections against ACTIVE definitions and build the canonical snapshot.
     * @param list<array<string,mixed>> $definitions active commerce_product_addons rows
     * @param list<array{addon_uuid:string,choice_key?:string,value?:mixed}> $selections
     * @param int $variantPrice variant price in minor units
     * @return array{snapshot: list<array<string,mixed>>, hash: string}  hash = '' when snapshot is []
     * @throws AddonValidationException (extends \DomainException) on: unknown addon_uuid,
     *         duplicate addon_uuid, missing required addon, invalid choice_key, checkbox
     *         non-boolean, text empty/over 500 chars, or variantPrice + delta < 0.
     */
    public static function build(array $definitions, array $selections, int $variantPrice): array;

    /** sha256 over the canonical snapshot: sorted by addon_uuid, fixed key order
     *  (addon_uuid, name, field_type, choice_key, choice_label, value, price_delta),
     *  text values trimmed, JSON_THROW_ON_ERROR. Empty snapshot => ''. */
    public static function hash(array $snapshot): string;

    /** @return int total signed delta */
    public static function delta(array $snapshot): int;
}
```

**Binding rules:** hash covers the FULL snapshot (display + price fields) so a definition edit yields a new hash — new line, never a stale-price merge; `pricedLines()` reads ONLY persisted snapshots (never re-resolves definitions) and throws/fails closed on negative computed unit price; checkout copies snapshot json verbatim to `commerce_order_lines.addons`; stock checks (addLine availability + checkout decrement pre-validation) aggregate per variant across all hashes.
- [ ] **Step 1 (TDD):** AddonSnapshot unit matrix RED (each validation rule; hash stability incl. selection order irrelevance; definition-edit changes hash; delta math; `build(..., $variantPrice)` rejects a negative final unit price internally; '' for none).
- [ ] **Step 2 (TDD):** Cart integration RED: same variant two selections = two lines; same selection merges quantity; no-addons merges with v1 semantics (hash ''); guest→user merge by variant+hash; definition price edit ⇒ new line, old line price unchanged; negative-final-price 422; stock: variant qty aggregated across two hashed lines cannot exceed availability (addLine AND checkout paths).
- [ ] **Step 3:** Implement checkout copy through `OrderRepository::orderLineRow()`, decode in `linesForOrder()`, and add explicit sanitized projections in storefront order, admin order, and invoice data (assert no `choices` array or status leaks).
- [ ] **Step 4:** Full suite green; grep-style regression: v1 cart tests untouched and green.
- [ ] **COMMIT (Group C):** `feat(cart): product add-ons with snapshot pricing and hash line identity`

---

## GROUP D — Reviews + gates

### Task 7: Reviews + rollups

**Files:**
- Create: `src/Catalog/ReviewRepository.php`, `src/Catalog/ReviewService.php`, `src/Http/Admin/AdminReviewController.php`, DTOs (CreateReviewData, ReviewListQuery)
- Modify: routes, provider, storefront ProductController (`rating {average, count}` when count > 0; average = round(sum/count, 1) at projection ONLY), 001 already carries rating columns
- Test: `tests/Integration/Http/ReviewEndpointTest.php`, `tests/Integration/Catalog/ReviewRollupConcurrencyTest.php`

**Binding rules:** transitions pending→approved|spam, approved→spam; each = affected-row-checked status claim + rollup increment/reverse in ONE transaction (missing product rolls back the claim); delete = single guarded `DELETE ... WHERE tenant AND uuid AND status IN ('pending','spam')` (0 rows on approved/unknown/cross-tenant → identical non-revealing 404/409 per house 404 rule — pick 404, matching other resources); author_email whitelisted OUT of every storefront payload.
- [ ] TDD (incl. deterministic approve-vs-delete one-winner test, duplicate-approve no-double-rollup, approved→spam reversal; runnable PostgreSQL-lane race) then implement. Suite green; NO commit.

### Task 8: Tenancy suite, regression gate, docs

- [ ] Two-tenant coverage for all six tenant-bearing tables (slug/name reuse across tenants; cross-tenant 404s on every admin surface; join tables unreachable except through in-tenant parents) — extend `tests/Integration/Tenancy/`; verify adopter/diagnostics include exactly the six.
- [ ] Regression audit: list every pre-existing test file changed in Tasks 1–7 with why (expected: MigrationsTest, CommerceTestCase, possibly cart tests touched ONLY for constructor/DTO wiring — anything asserting different behavior is a red flag to surface).
- [ ] Docs: comparison doc — media/categories/tags/attributes/reviews/add-ons/grouped/external move to "What Can Migrate Today" (with L6 caveat: storefront browse/filter/review-submit endpoints pending); update the gaps list.
- [ ] Full suite + phpcs + analyze clean.
- [ ] **COMMIT (Group D):** `feat(catalog): reviews with transactional rollups + tenancy hardening`

## Self-Review Notes

- Spec rev-3 → tasks: 11 tables + folded revisions + unique swap (T1); serialized media mutations + verified blob fields (T2); category/tag revision claims, post-claim tree reads, and transactional reparent-delete (T3); attribute/value claims and post-claim set-list validation (T4); union-claimed grouped children + both-direction rules (T5); variant-price-aware snapshot construction, hash-aware cart merge, explicit OrderRepository persistence/decoding, stock aggregation, and sanitized projections (T6); guarded review delete + claim rollups (T7); inventories + regression + docs (T8).
- Source-verification closures: framework blobs expose `status`/`visibility`; cart merging is `CartService::mergeIntoUser()` → private `mergeLine()`; checkout stock validation is in `CheckoutService::placeOrder()`; `OrderRepository::orderLineRow()`/`linesForOrder()` own order-line encoding/decoding; SQLite permits repeated nullable composite keys while enforcing non-null duplicates; the runnable PostgreSQL race pattern and env contract are already implemented by `GatewayRefundTest`.
