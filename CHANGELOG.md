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
- Added maintenance CLI commands and `commerce:diagnose`.
- Added tenant-mode resolver wiring, fail-closed tenant context handling, tenant table registration, and `commerce:tenancy:adopt`.
