# WooCommerce Migration Comparison

Short answer: **migration is possible only for a simple store today, not a serious WooCommerce parity migration yet.** Commerce is currently a clean commerce primitive, not a WooCommerce replacement.

## What Can Migrate Today

- Products with variants/SKUs/prices.
- Basic inventory tracking and stock ledger.
- Cart + checkout.
- Fixed/percentage discounts with usage limits.
- Basic shipping quotes through the provider contract.
- Basic tax through the tax contract.
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

That works for: a custom headless storefront selling physical, grouped, or external/affiliate products, organized into categories/tags with attributes, media galleries, add-ons, and moderated reviews, with simple variants and basic checkout.

## What Is Missing Versus WooCommerce

WooCommerce is much broader. It supports product types like simple, grouped, external/affiliate, variable, virtual, downloadable, and composite/bundle products, plus product editor/bulk tooling. Commerce now covers simple/variant, grouped, and external/affiliate products; composite/bundle products and true downloadable delivery are still missing.

Major gaps:

- No migration/import tool from WooCommerce yet.
- No customers/accounts model inside Commerce.
- No downloadable/digital product *delivery*. The seams already exist — products carry a
  `type` (shipping already skips `digital` lines; stock tracking already skips non-physical),
  so only the delivery layer is missing. The framework's private blobs + signed URLs +
  `BlobAccessPolicy` (1.67/1.68) are exactly that primitive: a downloads link table plus a
  policy binding gated on "order paid" covers most of the feature.
- No composite/bundle products (grouped and external/affiliate now work — see above).
- No generated invoice documents (PDF/printable).
- No tax-rate table/VAT rules UI.
- No mature shipping zones/classes/rules UI.
- No reports/analytics.
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

1. Customers via Users integration (`user_uuid` + soft-resolved `UserProviderInterface`,
   the same pattern the tenancy pack uses — not a Commerce-owned accounts table).
2. Shipping/tax APIs and configuration surfaces.
3. Reports/analytics APIs.
4. API parity for WooCommerce resources needed by importers and app-level UIs.
5. WooCommerce importer, written against the finished models above.

(Refunds, order notes, transactional emails, and the whole product-media/categories/
tags/attributes/reviews/add-ons/grouped-external-product-type batch — formerly items 1
and 1a here — have shipped; see "What Can Migrate Today" above.)


