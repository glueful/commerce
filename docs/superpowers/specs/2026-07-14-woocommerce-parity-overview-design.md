# WooCommerce Parity — Overview Spec

**Status:** draft for review
**Scope:** `glueful/commerce` (+ small, named changes to `glueful/extension-contracts`)
**Companion doc:** `docs/woocommerce-migration-comparison.md` (the gap analysis this closes)

This is an **overview** spec: it fixes the target architecture, data models, contracts, and
sequencing for every layer of the migration-readiness roadmap. Each layer gets its own
detailed design + implementation plan before it is built; this document is the map, not the
task list.

## 1. Goal and Non-Goals

**Goal:** a WooCommerce store can migrate to Commerce with no data loss for the resources
that matter — products (all mainstream types), media, categories/tags/attributes, reviews,
customers with order history, orders with refunds/notes, coupons, tax rates, and shipping
zones — and an app built on Commerce can serve that store through typed APIs.

**Non-goals (permanent, by positioning):**
- A bundled merchant admin UI. Commerce owns domain/API parity; app creators own screens.
- WordPress runtime compatibility, theme/shortcode/hook emulation.
- Subscriptions, memberships, POS. These are separate extensions if ever; the importer
  skips them with an explicit report line, never silently. Memberships in particular is an
  access-control product (plans/grants/content gating over Users + roles) whose only
  commerce touchpoint is subscribing to `OrderPaid` — it builds *on* Commerce, not *in* it.
- **Shared-checkout marketplaces** (Dokan/WCFM shape: one store, one cart, items from many
  vendors, commission splits, vendor payouts). This changes the cardinality of every
  invariant — order splitting, payout ledgers, per-vendor tax liability — and "vendor"
  there is an ownership column inside one store, not a tenant. Separate extension if ever.
  Distinguish this from the **mall model** (each vendor = their own store), which is not a
  Commerce concern at all: tenancy + commerce already provide it (tenant-isolated stores,
  domain routing, self-serve workspace signup, per-tenant roles); what remains is a thin
  cross-store directory + vendor billing layer that belongs to the platform app, not here.

**Non-goals (this roadmap, revisit later):**
- Abandoned-cart *recovery campaigns* (carts already expire via TTL; campaign email is an
  app concern once transactional email lands).
- External/affiliate products (import as `external` type with URL metadata; no checkout).
- Outbound webhooks (Woo REST exposes them; integrations expect them). Apps can build a
  webhook layer today by subscribing to Commerce events — importers pull, so migration
  readiness doesn't need them. Revisit as a Layer 6 candidate if integration demand shows.

## 2. Architecture Principles (binding for every layer)

These are the rules the existing extension already follows; every layer inherits them:

1. **Additive only.** Every layer ships as new tables/endpoints/contracts. With the feature
   unused, existing suites stay byte-identical. No destructive migrations. Unreleased legacy
   surfaces may be replaced before the first public release when the detail spec calls it out.
2. **Tenant-sentinel pattern.** Every tenant-owned root table carries `tenant_uuid` (char 12,
   default `''`), tenant-scoped unique keys, and registers with `TenantTableRegistry`.
   Dependent child tables may inherit tenancy only through a tenant-scoped root and must never
   be queried from a caller-supplied child/root UUID without that root constraint.
3. **Integer money.** All amounts are minor units (`bigInteger`), currency-validated against
   the store currency via `Support/Money`.
4. **Events spine.** State changes append to `commerce_order_events` (or emit a
   `Glueful\Events` BaseEvent) rather than inventing per-feature logs.
5. **Contract seams, soft-resolved.** Cross-extension dependencies (users, media, email)
   resolve softly from the container and degrade gracefully when absent — same posture as
   the Users extension's email dependency.
6. **DTO discipline.** Every new endpoint takes a typed `RequestData` DTO; responses are
   documented for the OpenAPI generator.
7. **Storefront vs Admin split.** Public surfaces live in `Http/Storefront` (token-scoped,
   anti-enumeration), operator surfaces in `Http/Admin` (scope-authorized).

## 3. Layers

Ordered by execution. Each layer = one minor release of the extension.

---

### Layer 1 — Order lifecycle: refunds, notes, transactional email

*Unblocks the "simple store" claim, not just Woo parity.*

**Refunds** (`commerce_refunds` + normalized `commerce_refund_lines`):

| column | notes |
|---|---|
| uuid / tenant_uuid / order_uuid | usual identity |
| amount (bigInteger) | minor units; partial refunds are rows, not states |
| currency | must match order |
| reason (text, nullable) | operator-entered |
| refund lines | normalized line UUID, quantity, and amount attribution; enforces cumulative restock limits |
| restock (bool) | whether stock was returned |
| status | `pending` → `completed` / `failed` |
| initiated_by (12, nullable) | operator user uuid |

- Order `status` becomes **derived** for refunds: `refunded` only when
  `SUM(completed refunds) == grand_total`; partial refunds leave the order in
  `paid`/`fulfilled` with a `refunded_total` rollup column on `commerce_orders`. A private
  `refund_revision` claim serializes reserve/finalize mutations per order.
- The state machine keeps its existing transitions; `refunded` is now reached via the
  refund service, never set directly.
- Restock: optional per refund; goes through `StockRepository` with a movement-ledger entry
  (`refund_restock`).
- **Contract change (extension-contracts):** a refund capability. Design as a
  separate `Glueful\Extensions\Contracts\Payments\RefundCollector` interface (do NOT widen
  `PaymentCollector` — existing collectors must not break). An unbound collector is Commerce's
  manual mode; future gateway adapters bind the new interface. *Requires the contracts-repo
  release chain.*
- Gateway execution is a persisted reserve → external call → idempotent finalize saga; no
  gateway I/O runs inside the framework's retryable transaction callback.
- Events: `RefundCompleted` / `RefundFailed` BaseEvents + internal order events.

**Order notes** — extend `commerce_order_events` (additive columns):

| new column | notes |
|---|---|
| actor_uuid (12, nullable) | null = system |
| visibility (16, default `internal`) | `internal` \| `customer` |

- A note is an event of type `note` with a text payload. Customer-visible notes surface on
  the storefront order endpoint; internal notes only on admin.
- Existing rows are untouched (defaults apply); existing readers see no shape change.

**Transactional email** — a `CommerceMailer` port inside the extension:
- Interface: `send(context, template, order, payload)`; the default binding resolves the
  framework notification system's email channel **softly** — no email extension installed
  means events still fire and a diagnostics line reports email as inactive (same soft
  posture as Users' forgot-password).
- Ships templates (order placed / paid / fulfilled / refunded / note-to-customer) as
  overridable message catalogs; apps restyle or replace.
- Trigger points subscribe to the existing events (`OrderPlaced`, `OrderPaid`,
  `OrderFulfilled`) — no checkout-path coupling; email failures never fail the order.

**Invoices** are a document-generation concern: expose a stable
`GET /orders/{uuid}/invoice-data` JSON contract (seller/buyer/lines/tax breakdown) and leave
PDF rendering to apps. Seller identity comes from a tenant-aware provider seam with a
config-backed default. Revisit bundling a renderer only if demand shows.

---

### Layer 2 — Catalog breadth: media, categories/tags, attributes, product types, reviews

**Media** (`commerce_product_media`): joins products to core **blobs** — Commerce stores no
files.

| column | notes |
|---|---|
| uuid / tenant_uuid / product_uuid | |
| variant_uuid (12, nullable) | variant-specific image |
| blob_uuid (12) | framework blob; public visibility for gallery images |
| position (int) / alt (255, nullable) / role (16, default `gallery`) | `cover` \| `gallery` |

- Thumbnails/dimensions come free when `glueful/media` is installed (soft; absent = original
  URLs only, no failures).
- Import path: importer downloads Woo image URLs → `FileUploader` → link rows.

**Categories & tags:**
- `commerce_categories`: uuid, tenant_uuid, parent_uuid (nullable — tree), slug
  (tenant-unique), name, description, position, `blob_uuid` (nullable, category image).
- `commerce_product_categories` + `commerce_product_tags` join tables; tags are flat
  (uuid/slug/name).

**Attributes** — Woo has taxonomy attributes (global, e.g. "Color") and custom (per-product).
- `commerce_attributes` (uuid, tenant_uuid, slug, name, position) +
  `commerce_attribute_values` (uuid, attribute_uuid, slug, value, position) — the global set.
- `commerce_product_attributes` join: product ↔ attribute with `values` (json),
  `used_for_variants` (bool), `visible` (bool).
- **Relationship to the existing `options`/`option_values` JSON:** those stay the
  variant-generation mechanism (unchanged, no migration). Global attributes are the
  *filterable taxonomy layer*; a product may map its options to attributes for storefront
  filtering. Custom per-product attributes import into the join rows without a global row.

**Product types** — the `type` column (16 chars) widens by value, not schema:
- `physical` (today), `digital` (today, delivery in Layer 3), `external` (URL + button label
  in `metadata`; not purchasable), `grouped` (child list via new
  `commerce_product_children` join; a display grouping — children are purchased
  individually, matching Woo semantics).
- True bundles/composites (single-line-item kits with component stock) are **out** of this
  roadmap; importer maps Woo bundles (a plugin, not core Woo) to grouped with a report note.
- Add-ons/custom options: a `commerce_product_addons` table (per-product option definitions:
  field type, choices, price deltas in minor units) + an `addons` json snapshot on cart/order
  lines. Priced add-ons adjust the line unit price at add-to-cart time through
  `PricingEngine` — one seam, no scattered hooks.

**Reviews** (`commerce_reviews`): uuid, tenant_uuid, product_uuid, user_uuid (nullable),
author_name/email (guest), rating (1–5), body, status (`pending`/`approved`/`spam`),
created_at. Product rating rollups (`rating_avg`, `rating_count` on products) updated
transactionally on approval. Moderation is admin-API-only.

**Metadata stopgaps get no promotion path.** Apps that improvised images/categories/
attributes into product `metadata` before this layer migrate that data themselves — there
is no convention to promote automatically, and Layer 2 will not guess at one. This is the
documented cost of the stopgap, stated now so nobody builds on it expecting otherwise.

---

### Layer 3 — Customers via Users integration + digital delivery

**Customers.** No Commerce-owned accounts table. The design doubles down on what
`commerce_orders` already has (`user_uuid` nullable, `email`, `guest_token_hash`):

- Reads resolve identity through `UserProviderInterface` (framework core) — soft, same as
  the tenancy pack.
- `commerce_customer_addresses`: uuid, tenant_uuid, user_uuid, label, address (json),
  is_default_shipping/is_default_billing — the address book Woo migrates.
- Admin endpoint `GET /admin/commerce/customers` = aggregation over orders (first/last
  order, totals, count) joined to provider identities; **no new customer rows to keep in
  sync**.
- Guest→account linking: a maintenance command + an opt-in checkout behavior that attaches
  `user_uuid` when an authenticated session places an order (already possible; formalized).
- Importer maps Woo customers → Users-extension accounts (created unverified, no passwords —
  password reset is the activation path) + address rows + order backfill by email match.

**Digital delivery** — the blob primitives (framework 1.67/1.68) are the mechanism:
- `commerce_downloads`: uuid, tenant_uuid, variant_uuid, blob_uuid (private blob), name,
  download_limit (nullable), expiry_days (nullable).
- `commerce_download_grants`: uuid, order_uuid, download_uuid, remaining (nullable),
  expires_at (nullable), token_hash — granted transactionally on `OrderPaid`.
- Access: storefront `GET /downloads/{token}` validates the grant and issues a short-lived
  signed blob URL; a `BlobAccessPolicy` binding backstops direct blob access ("no valid
  grant → 404"). Raw tokens shown once; hashes stored (existing `TokenHasher`).

---

### Layer 4 — Shipping & tax as data: zones, classes, rate tables

Replaces "config-file only" with DB-backed providers; the config provider remains the
fallback so existing installs are untouched.

- `commerce_shipping_zones` (uuid, tenant_uuid, name, position) +
  `commerce_shipping_zone_locations` (zone_uuid, kind `country`/`state`/`postcode_pattern`,
  value) + `commerce_shipping_methods` (zone_uuid, kind `flat`/`free_over`/`per_class_table`,
  label, config json, position, enabled).
- `commerce_shipping_classes` (uuid, slug, name) + nullable `shipping_class_uuid` on
  variants — Woo's per-class rates land in method `config`.
- `commerce_tax_rates`: uuid, tenant_uuid, country, state (nullable), postcode_pattern
  (nullable), rate_bps, label, priority, shipping_taxable (bool), class (16, default
  `standard`) + nullable `tax_class` on products.
- New `DbShippingRateProvider` / `DbTaxCalculator` implement the **existing contracts**
  (`ShippingRateProvider::quote(context, lines, shippingAddress)`,
  `TaxCalculator::quote(context, taxableAmount, shippingAddress)`); binding precedence:
  app-bound > DB-backed (when rows exist) > config fallback. Contracts do not change.
  - *Known limit to verify at detail-design:* per-line tax classes need line-level input;
    if `TaxCalculator`'s aggregate signature can't express it, add a **new optional**
    `LineTaxCalculator` contract rather than breaking the existing one.
- Full admin CRUD APIs for zones/methods/classes/rates. No settings UI — API + import only.

---

### Layer 5 — Reports & analytics APIs

API-only aggregates, computed from orders/refunds/stock (no new write paths):

- `GET /admin/commerce/reports/sales` (revenue, orders, AOV, refunds; grouped by
  day/week/month, date-ranged)
- `.../products` (top sellers, revenue by product/variant)
- `.../customers` (new vs returning by email/user_uuid)
- `.../stock` (low stock, out of stock — thresholds from config)
- Implementation: direct SQL aggregates behind a small query-object layer; add covering
  indexes as needed (`orders(tenant_uuid, status, created_at)` etc.). A materialized
  daily-rollup table is an optimization to add **only if** measured query cost demands it.
- Cursor pagination + the same date-window DTO across all report endpoints.

---

### Layer 6 — API parity for importer resources

Rounding-out pass so every migrated resource is fully manageable via API — the checklist is
mechanical:

- CRUD/list (cursor-paginated, filterable) for: categories, tags, attributes(+values),
  reviews (moderation), media links, refunds (create = issue refund), customers
  (read/aggregate), downloads, shipping zones/methods/classes, tax rates, order notes.
- Bulk endpoints where Woo tooling expects them: product bulk status/price updates,
  review bulk moderation.
- OpenAPI documentation for every route; storefront additions (category browse, attribute
  filters on product list, review submit/list) included.

---

### Layer 7 — WooCommerce importer

Written last, against the finished models. CLI-first (`php glueful commerce:import:woo`),
long-running-safe.

- **Sources:** (a) Woo REST API (URL + consumer key/secret) — richest, preferred;
  (b) Woo product/customer CSV exports — offline fallback. Both normalize into one internal
  intermediate representation; mappers consume only the IR.
- **Order + idempotency:** categories/tags/attributes → media → products/variants →
  customers → coupons → tax/shipping → orders (+ refunds/notes) → reviews. Every entity
  records its Woo ID in `commerce_import_map` (source, source_id, entity, uuid) — re-runs
  update instead of duplicate, and the map powers a resumable cursor per entity type.
- **Report, not silence:** every skipped/unmappable thing (subscriptions, bundles→grouped
  downgrades, unsupported gateways, malformed rows) lands in a written import report.
  Historical Woo orders import as **records** (status-mapped, no payment collection, stock
  untouched, events annotated `imported`).
- **Dry-run mode** prints the full report without writing.

> **Home & timing (decided 2026-07-16):** the importer will NOT live in this extension.
> It is deferred until Thallo adopts commerce, and will be implemented in
> `thallo/packages/thallo-importers` as a Thallo-side package consuming commerce's public
> APIs/services. Layers 1–6 are the complete commerce-extension scope of this overview;
> nothing in the extension may grow importer-only seams speculatively — any seam the
> importer turns out to need is requested when it is built, against the shipped surface.

## 4. Cross-Cutting Requirements

- **Woo is a compatibility target, not a design authority.** Parity means a migrated
  store keeps working and its merchant-observable behavior (pricing, zone matching, rate
  priority, defaults) is preserved — it never means adopting WooCommerce's architecture,
  API shapes, data model, or feature sprawl. Commerce keeps its own posture: integer
  minor units and house rounding, claim-based concurrency, contract seams with delegating
  providers, honest attribution over convenient pretense (e.g. `attributed_refunded_*`),
  scoped/tenant-aware APIs, and deliberate non-goals (subscriptions, memberships,
  bundles, batch-everything endpoints, bundled admin UI). Any place a layer intentionally
  matches a Woo behavior, its detail spec must pin that as an explicit "Woo parity"
  decision for review — matching by default or by habit is a spec defect.
- **Interim app guidance (until a layer ships):** the supported stopgaps are **events**
  (app-side order email, webhooks, fulfillment automation via `OrderPlaced`/`OrderPaid`/
  `OrderFulfilled`/…) and **`metadata` JSON** (app-level data on products/orders). Events
  are a durable seam — anything built on them keeps working after the layers land. Metadata
  stopgaps are throwaway (see Layer 2's no-promotion rule).
- **Authorization:** Commerce's standalone admin routes continue to use its existing
  `commerce:read` / `commerce:write` API scopes. Host applications may layer finer Aegis
  permissions (`commerce.refunds.manage`, `commerce.catalog.manage`, …), but those are not
  dependencies of this extension roadmap; storefront additions stay token/public per the
  current split.
- **Diagnostics:** each soft dependency (email channel, media extension, users provider)
  reports active/inactive in `DiagnosticsReport`.
- **Testing:** per layer — unit tests for money/state logic, integration tests per
  endpoint, a migrations test, and the standing regression gate: with the layer's features
  unused, the pre-existing suite passes byte-identical.
- **Docs:** each layer updates the comparison doc's gap list on ship — the doc is the
  scoreboard.

## 5. Dependencies & Release Chain

| Dependency | Kind | Notes |
|---|---|---|
| `glueful/extension-contracts` | hard | `RefundCollector` (Layer 1). Release chain: contracts → commerce; Payvia integration is later because it has no refund surface today. Also: pin off `dev-dev` before any commerce release. |
| Framework blobs + signed URLs + `BlobAccessPolicy` | hard (≥1.68) | media (L2), downloads (L3) |
| `UserProviderInterface` | soft | customers (L3) |
| `glueful/media` | soft | thumbnails for product media |
| Email notification channel | soft | transactional email (L1) |
| `glueful/users` | soft (importer: required for customer import) | account creation path |

## 6. Sequencing Summary

| Release | Layer | Headline |
|---|---|---|
| +1 | 1 | Refunds (partial, restock, collector seam), order notes, transactional email |
| +2 | 2 | Media, categories/tags, attributes, product types (external/grouped/add-ons), reviews |
| +3 | 3 | Customers via Users, address book, digital delivery via blobs |
| +4 | 4 | Shipping zones/classes + tax rate tables, DB-backed providers |
| +5 | 5 | Reports APIs |
| +6 | 6 | API parity rounding-out |
| +7 | 7 | WooCommerce importer + import map + report |

## 7. Open Questions (resolve at each layer's detail design)

1. **L2:** storefront attribute filtering — query-param DSL vs the framework's
   FieldSelection expansion; pick one and match the rest of the storefront API.
2. **L3:** importer password posture is "reset to activate" — confirm the Users extension's
   reset flow can be triggered administratively (or add that seam to glueful/users first).
3. **L4:** verify whether aggregate `TaxCalculator` suffices for class-based rates or the
   optional `LineTaxCalculator` contract is needed (decides a contracts-repo release).
4. **L7:** minimum supported Woo version for the REST source (v3 API assumed).
