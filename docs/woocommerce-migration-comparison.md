# WooCommerce Migration Comparison

Short answer: **migration is possible only for a simple store today, not a serious WooCommerce parity migration yet.** Commerce is currently a clean commerce primitive, not a WooCommerce replacement.

## What Can Migrate Today

- Products with variants/SKUs/prices.
- Basic inventory tracking and stock ledger.
- Cart + checkout.
- Fixed/percentage discounts with usage limits.
- Data-driven shipping zones, geographic matching (country/state/postcode, including
  wildcard patterns and a conjunctive country-scopes-postcode rule), position-ordered
  zone precedence with an "everywhere" catch-all zone and a shadowing warning, and
  three per-zone method kinds (flat, free-over-threshold, per-shipping-class table)
  with a full shipping-class taxonomy — via a thin provider chain (app-bound >
  DB-when-zones-exist > config fallback), so an app that never configures zones keeps
  the original config-driven behavior byte-for-byte.
- Data-driven tax rates — country/state/postcode-matched, per tax-class rate rows with
  priority ordering, a `shipping_taxable` toggle, and discount-aware per-line
  allocation (largest-remainder, scope-eligible) — via the same delegation pattern
  (app-bound > DB-when-rates-exist > flat-rate config fallback).
  Caveat: admin/API only, no settings UI (see "No bundled merchant admin UI" below);
  one rate per class applies (no compound/stacked tax rates); no live carrier rates or
  per-customer tax exemptions yet.
- Orders, order events, payment retry, fulfillment status/tracking ref.
- Payvia-style payment collection through contracts.
- Tenant-ready data model.
- Refunds — full and partial, with optional restock, via a manual path or a gateway
  saga with idempotency (same idempotency key always resolves to the same outcome).
  Caveat: gateway refunds need a bound `RefundCollector`; none is bundled, so the
  manual path is what works out of the box today.
- Order notes with internal/customer visibility and optional customer notification.
- Transactional emails for order placed/paid/fulfilled, refunds, and notes. Caveat:
  opt-in — the master switch is off by default, and each template can be toggled
  independently once enabled.
- Product categories and tags, with per-product assignment and tenant-scoped slugs.
- Product attributes — a global taxonomy with per-attribute values, plus per-product
  attribute rows (global or custom) with a variant-eligible/visible flag.
- Product media/gallery — a cover image plus an ordered gallery, backed by the
  framework's blob storage (attach/update/detach/reorder).
- Grouped and external/affiliate product types, alongside the original simple/variant
  model.
- Product add-ons/custom options, with per-cart-line pricing snapshotted at selection
  time so later add-on edits never retroactively change an existing line.
- Reviews with moderation (`pending` → `approved`/`spam`) and a live rating rollup
  (`{average, count}`) on the product payload.

  Caveat: this whole catalog-breadth batch is admin/importer-facing only. Storefront
  category-browse and attribute-filter endpoints, and storefront review submission,
  are Layer 6 and not shipped yet — the storefront product payload already echoes
  categories/tags/attributes/media/rating read-only, but there is no browse/filter/
  submit API surface yet.
- Customers, derived from orders rather than a bundled accounts table (`GET
  /commerce/admin/customers` and `/{key}`, keyed by `user_uuid` or normalized guest
  email, with soft username enrichment via `UserProviderInterface`). Caveat: no
  first-class customer/account row — a "customer" is an aggregation over orders, not
  a persisted profile; guest→user linking is an operator CLI
  (`commerce:customers:link-guests`) today, not yet an admin HTTP action (an L6
  API-parity candidate).
- A storefront authenticated address book (`GET/POST /commerce/account/addresses`,
  `PATCH/DELETE .../{uuid}`) with parent-claim-serialized default shipping/billing
  swap-in-transaction, plus optional checkout integration
  (`shipping_address_uuid`/`billing_address_uuid`) that snapshots a caller-owned
  saved address into the order exactly like an inline address.
- Full downloadable/digital product delivery: admin-managed download definitions on
  digital variants (private-blob validated), a checkout-time entitlement snapshot
  immune to later definition edits or deletes, snapshot-derived idempotent
  self-healing grant issuance (quantity-aware across add-on-distinct lines,
  overflow-guarded), two access paths (order-authenticated listing/minting and a
  public email deep link) sharing one atomic guarded-mint primitive with
  full-refund/expiry/revocation handling, a blob-access policy contributor enforcing
  grant-aware VIEW/SIGN/DELETE rules, and deep links folded into the `order_paid`
  transactional email on first issuance. Caveat: requires `glueful/framework` >= 1.70
  (the generic, multi-contributor `BlobAccessPolicy` composite this feature's policy
  backstop depends on); consumption is minted-signed-URL-based (a time-boxed URL per
  mint), not a raw fetch-count meter.
- Reports/analytics — sales (gross/net revenue, refunds, AOV, and a pending-orders
  count over a date window), products (ranked variant sales with line-attributed
  refunds), customers (new vs. returning), and stock (out-of-stock/low-stock) reports,
  with day/week/ISO-week/month rollups where applicable, computed live from migrated
  orders/refunds/stock. Caveat: API-only (admin JSON endpoints; no dashboard/chart UI
  — see "No bundled merchant admin UI" below) and no import of WooCommerce's own
  historical report/analytics data — these are freshly computed from whatever orders
  actually migrated in, not a Woo report-data carryover.

That works for: a custom headless storefront selling physical, grouped, or external/affiliate products, organized into categories/tags with attributes, media galleries, add-ons, and moderated reviews, with simple variants and basic checkout, customer accounts backed by Users, saved addresses, full digital-product delivery, and data-driven shipping zones/classes and tax rates.

## What Is Missing Versus WooCommerce

WooCommerce is much broader. It supports product types like simple, grouped, external/affiliate, variable, virtual, downloadable, and composite/bundle products, plus product editor/bulk tooling. Commerce now covers simple/variant, grouped, and external/affiliate products, plus downloadable delivery; composite/bundle products are still missing.

Major gaps:

- No migration/import tool from WooCommerce yet.
- No composite/bundle products (grouped and external/affiliate now work — see above).
- No generated invoice documents (PDF/printable).
- No abandoned cart, subscriptions, memberships, marketplaces, POS, etc.
- No bundled merchant admin UI comparable to WooCommerce. This is intentional: Commerce should provide API/domain parity, while app creators build the merchant experience that fits their product.
- No REST import parity for Woo resources like customers, refunds, product attributes, categories, tags, reviews, reports.

## What Commerce Is Doing Better Than WooCommerce

- **Cleaner architecture:** domain core + contract seams instead of WordPress/plugin global-state coupling.
- **Headless/API-first:** Commerce is built as a typed API primitive, not a PHP theme/plugin monolith.
- **Safer money model:** integer minor units, currency validation, transactional totals.
- **Better stock correctness:** checkout decrements stock transactionally instead of relying on scattered hooks.
- **Tenant-ready from day one:** every table carries a sentinel tenant key (`tenant_uuid`
  default `''`, tenant-scoped uniques) with sentinel and fail-closed resolvers plus an
  adoption path — avoids the painful retrofit later.
- **Payment decoupling:** payment collector and confirmation contracts avoid hard dependency on Payvia internals.
- **Security posture:** hashed cart/order tokens, header-only bearer tokens, anti-enumeration order lookup.
- **OpenAPI/DTO discipline:** request/query DTOs and documented controller contracts are much cleaner for integrations.
- **Order audit trail:** `commerce_order_events` gives a straightforward event/audit record.
- **Less bloat:** no WordPress runtime, no plugin soup, fewer accidental extension conflicts.

## Honest Positioning

**WooCommerce is better today as a complete merchant product.**

**Commerce is better as a modern, headless, composable commerce foundation.**

Commerce should not try to ship a full WooCommerce-style admin UI as part of the extension. The extension's job is to own the domain model, invariants, APIs, contracts, import/export surfaces, and integration seams. App creators should own the merchant UI, because checkout/admin workflows, information density, branding, and operational screens vary heavily by product.

## Migration Readiness Roadmap

For migration readiness, the required layers — in execution order, with the importer
deliberately last (an importer written before its target models exist gets rewritten for
every model added; build the destination schema first, write the importer once against the
final shape):

1. API parity for WooCommerce resources needed by importers and app-level UIs.
2. WooCommerce importer, written against the finished models above.

(Refunds, order notes, transactional emails, the whole product-media/categories/
tags/attributes/reviews/add-ons/grouped-external-product-type batch, customers via
Users integration, the storefront address book, full digital-product delivery,
data-driven shipping zones/classes and tax rates, and reports/analytics APIs —
formerly items 1 and 1a here — have shipped; see "What Can Migrate Today" above.)


