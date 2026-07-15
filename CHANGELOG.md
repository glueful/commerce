# Changelog

## [Unreleased]

- Added Commerce extension scaffold and Glueful extension metadata.
- Added integer-minor-unit money utilities and hashed cart/order token handling.
- Added catalog tables and services for products, variants, and single-store currency enforcement.
- Added inventory stock ledger with atomic oversell-safe decrements.
- Added pure pricing totals with discount, shipping, and tax composition.
- Added transactional discounts with usage limits and once-per-buyer redemption keys.
- Added cart tokens, line management, discount application, and guest cart merge support.
- Added shipping and tax contracts with config-backed defaults.
- Added order numbering, immutable line snapshots, order events, state transitions, expiry, and restock behavior.
- Added checkout orchestration with transactional stock decrement, totals validation, order placement, and retryable payment initiation.
- Added payment confirmation handling through `glueful/extension-contracts`, including amount/currency checks and late-payment rejection events.
- Added storefront product, cart, checkout, and guest order APIs with header-only bearer tokens.
- Added admin catalog, stock, discount, and order APIs.
- Added runtime request DTOs plus OpenAPI operation/response metadata for Commerce HTTP APIs.
- Added pagination for admin and customer order listing APIs.
- Added maintenance CLI commands and `commerce:diagnose`.
- Added tenant-mode resolver wiring, fail-closed tenant context handling, tenant table registration, and `commerce:tenancy:adopt`.
- Added partial/full order refunds with automatic stock restock, a gateway-backed settlement saga, and idempotent refund requests.
- Added order notes attached to admin/system order actions, with actor resolution.
- Added opt-in transactional email for order lifecycle events (placed, fulfilled, refund settled, etc.), with mailer, listeners, and templates.
- Added invoice data v1 (seller identity, line/tax/totals snapshot) and a dedicated invoice endpoint.
- Fixed `fulfill()` to dispatch the `OrderFulfilled` event, which was previously silently skipped.
- Removed the standalone admin `mark-refunded` endpoint; refunds are now the single source of truth for an order's refunded state.
- Added product media (cover + gallery) attach/reorder/detach endpoints with blob validation.
- Added categories and tags taxonomy with per-product assignment endpoints.
- Added product attributes (global and per-product custom) with visible/hidden storefront echo control.
- Added external and grouped product types, including grouped-child ordering and external URL/button metadata.
- Added product add-ons (select/checkbox/text) with snapshot pricing, cart-line hash identity, and admin CRUD.
- Added customer reviews with a moderation workflow and transactional rating rollups.
- Added storefront `GET /commerce/products` and `GET /commerce/products/{slug}` enrichments: media, categories, tags, attributes, add-ons, children, rating, and external metadata.
- Added multi-tenant coverage and claim-based concurrency protection for all Layer 2 catalog tables.
