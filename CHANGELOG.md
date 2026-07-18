# Changelog

## [Unreleased]

**Theme: optional marketplace foundation (MV1)** — a two-level opt-in multi-seller
layer (install master switch + per-workspace activation) with zero behavior change
while off: two new migrations, no changes to any existing table or route.

- Added an optional Marketplace MV1 foundation behind a new install master switch, `commerce.marketplace.enabled` (env `COMMERCE_MARKETPLACE_ENABLED`, default `false`). While off, the marketplace/seller route groups are never registered and ordinary Commerce request paths execute zero marketplace-table queries — existing API behavior stays byte-identical.
- Added per-workspace activation (`commerce_marketplace_settings`) on top of the master switch, guarded by a catalog adoption gate: a workspace cannot activate while any product still lacks a seller, with an optional `default_seller_uuid` to bulk-adopt them. Deactivation is fail-closed and non-destructive — seller/membership/attribution data is untouched, and re-activation re-runs the same gate.
- Added seller identity and lifecycle (`onboarding|active|suspended|closed`) and seller memberships with a fixed, code-defined role vocabulary (`seller_owner|seller_admin|seller_staff|seller_analyst`) behind a `SellerRoleAuthority` seam, plus an anti-lockout last-owner guard on every seller.
- Added catalog ownership: a nullable `commerce_products.seller_uuid`, a dedicated guarded adoption/transfer operation (never a raw patch), and a global per-workspace lock order (workspace → sorted sellers → sorted products) that makes activation-vs-create, close-vs-create, and transfer races deterministic rather than merely test-observed — proven under real two-connection PostgreSQL races.
- Added seller-scoped catalog/inventory/membership APIs (`/commerce/seller/...`) and platform marketplace administration APIs (`/commerce/admin/marketplace/...`), both config-gated behind the master switch and requiring an active workspace for seller-member operational surfaces; operator foundation surfaces (seller/membership CRUD, adoption, transfer) remain available while a workspace is inactive.
- Added migration `010_CreateMarketplaceSellerTables`; the nullable `commerce_products.seller_uuid` column and its `(tenant_uuid, seller_uuid)` index are folded into `001_CreateCommerceCatalogTables` (installs whose catalog migration already ran must add the column/index manually). `DiagnosticsReport` and `commerce:tenancy:adopt` stay marketplace-aware regardless of the master switch, so data created before a switch-off remains coherent.
- No storefront changes: public product payloads never carry `seller_uuid` in MV1.

**Theme: shared checkout & seller orders (MV2)** — one customer order placed at
checkout now fans out into immutable per-seller order partitions with exactly
reconciled money attribution, independent parent-derived fulfillment, and a
payment-confirmation PII gate — inert (zero seller-table queries, byte-identical
behavior) whenever the marketplace workspace isn't active for that order.

- Added shared checkout: a partitioned checkout (marketplace active) computes each seller's exact share of subtotal/discount/shipping/tax/grand-total via a deterministic largest-remainder allocator and writes one immutable `commerce_seller_orders` row per participating seller, alongside the existing single customer order — every reconciliation invariant (`Σ` per-seller = order total, for each of subtotal/discount/shipping/tax/grand-total) is hard-asserted inside the checkout transaction. Value discounts allocate per line; free-shipping allocates the shipping waiver per seller instead. Tax is `line_detailed` when a tax-breakdown is available, else `aggregate_allocated`.
- Added a transfer-safe checkout claim protocol: checkout claims the participating sellers (never the workspace-wide lock) then the participating products, both in ascending UUID order, and re-reads before writing. A concurrent transfer/suspension/closure that changes a product's seller mid-checkout rolls back the whole attempt and retries once automatically from a fresh snapshot; a second drift, or a participating seller that isn't `active`, returns a controlled `409 checkout_conflict` — proven under real two-connection PostgreSQL races (checkout-vs-transfer in both commit orderings, checkout-vs-suspend, checkout-vs-close).
- Added an immutable `commerce_orders.marketplace_partitioned` marker, set once at placement and never mutated: a partitioned order stays child-aware and keeps its `seller_groups` projection forever, even after the workspace later deactivates; a non-partitioned order stays byte-identical regardless of any later activation.
- Added the payment-confirmation seller PII gate: a seller order's `confirmed_at` is stamped on every child, atomically with the parent's `pending_payment → paid` transition, in the SAME database transaction — proven to commit or roll back together under real two-connection contention. A pending or pre-payment-canceled seller order is a non-revealing 404 on every seller-facing surface; a paid-then-canceled order remains seller-visible as history.
- Added independent, parent-derived fulfillment: each seller order carries its own `fulfillment_status`/carrier/tracking; the parent's `fulfillment_status` (`unfulfilled|partial|fulfilled`) rolls up from its children and the parent order transitions to `fulfilled` only once every non-canceled child is. Every fulfillment mutation claims the parent's `fulfillment_revision` before any child, so concurrent child fulfillments serialize and the rollup — and the `OrderFulfilled` event — can never double-fire, proven under real two-connection PostgreSQL races. Whole-order cancellation (from `pending_payment` or `paid`) fans out to every child; partial (single-seller) cancellation is deferred to a future release.
- Added seller-facing order surfaces (`GET /commerce/seller/{sellerUuid}/orders`, `GET .../orders/{sellerOrderUuid}`, `POST .../orders/{sellerOrderUuid}/fulfill`) exposing only that seller's own confirmed lines, allocated totals, and a normalized shipping-only customer address allowlist; operator surfaces (`GET /commerce/admin/orders/{uuid}` breakdown, `POST .../seller-orders/{sellerOrderUuid}/fulfill`, existing fulfill/cancel endpoints) gain full-visibility seller-order detail and fan-out; the consolidated customer order projection (`GET /commerce/orders/{number}`) gains an allowlisted `seller_groups[]` array only when the order is partitioned.
- Added migration `011_CreateSellerOrderTables` for the new `commerce_seller_orders` table; the `commerce_order_lines` (`seller_uuid`, `discount_amount`, `tax_amount`, plus a `(order_uuid, seller_uuid)` index) and `commerce_orders` (`marketplace_partitioned`, `fulfillment_revision`) columns are folded into `004_CreateCommerceOrderTables` (installs whose order migration already ran must add these columns/index manually). `DiagnosticsReport` and `commerce:tenancy:adopt` treat `commerce_seller_orders` as marketplace-aware regardless of the master switch, exactly like the MV1 trio.
- No behavior change while the master switch is off, or for a non-partitioned order once it's on: checkout/payment/fulfillment/cancellation/projection execute zero marketplace-table queries and the customer projection never carries `seller_groups`.

**Theme: commission & settlement ledger (MV3)** — every payment-confirmed and
refund-completed partitioned order posts commission and settlement facts to an
append-only per-account ledger atomically with the lifecycle transition; balances
derive from the ledger; operators run manual payouts and audited commission-policy
changes; sellers and operators get financial reports — inert (zero settlement-table
queries, byte-identical behavior) whenever the marketplace workspace isn't
partitioned for that order.

- Added operator-only commission policy (`percentage` or `fixed`, resolved product → seller → workspace → `config/commerce.php` `marketplace.commission`, which is concrete and never all-null) with a durable, append-only `commerce_commission_policy_events` audit row written in the SAME transaction as every policy mutation — audit-insert failure rolls back the mutation. Sellers may read effective and snapshotted commission policy but never write it; the seller catalog write path rejects any commission field with a field-specific `422` on create and update.
- Added an immutable per-line commission snapshot at checkout (`commission_source/kind/bps/fixed/basis/amount`) plus each seller order's exact summed `commission_amount` — resolved and written once, never rewritten by a later policy edit.
- Added an append-only `commerce_marketplace_ledger` with canonical per-account identity (`seller:{uuid}` | `marketplace`), signed integer-minor-unit amounts, a closed entry-type vocabulary, and deterministic idempotency (a duplicate posting key verifies every immutable field and either no-ops or fails loud — never a duplicate row). A per-`(tenant, account_key, currency)` account lock (`commerce_ledger_account_locks`, the MV1 workspace-lock idiom generalized) serializes every balance-affecting posting, claimed in sorted account-key order across multi-seller postings.
- Added atomic payment posting: `markPaid()` posts each participating seller's `sale_credit`/`commission_debit` inside its own transaction, after payment confirmation and before the `OrderPaid` event — any posting failure rolls back the paid transition itself.
- Added atomic refund posting: `applyCompletion()` derives each refund line's merchandise-capped contribution and per-line cumulative commission reversal at completion time (never at gateway-reservation time, so concurrent refunds against the same line always net out exactly), posts per-seller `refund_debit`/`commission_reversal` plus any unattributable remainder to the marketplace account, and hard-asserts the posted total equals the refund amount exactly.
- Added derived, currency-separated balances (`available`, `reserved`, `paid_out`, lifetime gross sales/commission/refunds/commission-reversed/adjustments) — always a fresh `SUM` over the ledger, never a stored mutable balance.
- Added manual operator payouts (`POST /commerce/admin/marketplace/payouts`): claims the seller account lock, rechecks available balance under it, and refuses (`422`) an amount that would overdraw — proven under real two-connection PostgreSQL races that a concurrent payout can never overdraw a concurrently-refunded account, in both commit orderings. Added operator ledger adjustments (`POST /commerce/admin/marketplace/adjustments`) for signed, reasoned corrections that may drive a balance negative — compensating-entry only, never an edit.
- Added a read-only `commerce:marketplace:reconcile` command that scans paid orders, completed refunds, and payouts directly (never by parent order status) for a missing, duplicated, or mismatched posting — it never posts.
- Added seller (`GET /commerce/seller/{sellerUuid}/financials/...`) and operator (`GET /commerce/admin/marketplace/financials/...`, `.../sellers/{uuid}/balance`, `.../sellers/{uuid}/report`) financial report/balance/payout/commission-policy surfaces, all config-gated behind the master switch.
- Added migrations `012_CreateMarketplaceLedgerTables` (ledger, account locks, commission-policy events) and `013_CreatePayoutTable`; commission columns are folded into `001_CreateCommerceCatalogTables` (products), `004_CreateCommerceOrderTables` (order lines), and `010_CreateMarketplaceSellerTables` (sellers, workspace settings), and the seller-order commission sum into `011_CreateSellerOrderTables` (installs whose migrations already ran must add these columns/tables manually). `DiagnosticsReport` and `commerce:tenancy:adopt` treat all four new tables as marketplace-aware regardless of the master switch, exactly like the MV1/MV2 tables.
- No behavior change while the master switch is off, or for a non-partitioned order once it's on: checkout/payment/refund/fulfillment execute zero settlement-table queries and post nothing.

## [1.1.0] - 2026-07-16 — Concurrency Hardening

**Theme: transactional-core hardening** — atomic checkout claims, checked order
transitions, fail-closed catalog writes, tenant route middleware, and controlled
422s where concurrent or duplicate input previously produced database errors.
No new env vars, no migrations; error semantics tighten (409/422 where blind
writes or 500s occurred before).

- Hardened the transactional core against concurrent mutation: checkout claims the cart with an atomic active→converted flip (a losing duplicate checkout gets a controlled 422, and a failed checkout restores the cart); cart line/discount/merge mutations serialize through the same claim (merge orders cart claims by UUID to avoid deadlock); every order status transition (payment, cancellation, expiry, fulfillment, full refund) is an affected-row-checked `WHERE status = ?` update, so lifecycle events like `OrderPaid` can no longer double-fire.
- Made product/variant/stock creation a single fail-closed transaction (a failed variant or stock insert no longer strands an orphan product row), and every product/variant patch now runs the guarded claim primitive — including single-field slug/sku patches — so no write can race a concurrent delete or land on a tombstoned parent.
- Added tenant middleware to the public, account, and admin commerce route groups when tenancy is enabled, and rejected duplicate refund-line attribution with a controlled 422 (probe-checked before insert) instead of a database unique-constraint 500.
- Fixed `POST /commerce/admin/orders/{uuid}/fulfill` returning a 500 for an unknown or
  cross-tenant order; it now 404s exactly like `cancel()`.
- Pinned `glueful/extension-contracts` to the released `^1.4.0` (was the `dev-dev`
  path repository).

## [1.0.0] - 2026-07-16 — Initial Release

**Theme: the complete commerce platform** — catalog breadth (categories, tags, attributes,
media, add-ons, reviews), orders with refunds and transactional email, digital delivery,
customer aggregates, DB-backed shipping zones and tax tables behind delegating provider
contracts, reports, and full API parity (pagination, filters, shows, bulk endpoints, public
storefront browse/reviews). Integer minor units throughout; claim-based concurrency proven
by two-connection PostgreSQL race tests; requires framework ≥ 1.70.0.

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
- Added digital-download definitions (admin CRUD on variants, private-blob and digital-variant validation) and a checkout-time purchase-line entitlement snapshot that survives later definition edits or deletes.
- Added snapshot-derived, idempotent, self-healing digital-download grant issuance (quantity aggregation across add-on-distinct lines, overflow guard, bounded token-collision retry) plus a `commerce:downloads:backfill` operator CLI.
- Added order-authenticated digital-download listing and atomic signed-URL minting, plus a public email deep-link redemption route, sharing one guarded mint primitive with full-refund and expiry/revocation handling.
- Added `CommerceDownloadBlobPolicy`, a blob-access policy contributor enforcing grant/definition-aware VIEW/INFO/DELETE/SIGN rules through the framework's composite blob-policy registry.
- Added digital-download deep links to the `order_paid` transactional email on first grant issuance, with issuance failures isolated from mail delivery so the plain email always still sends.
- Added order-derived admin customer aggregation (`GET /commerce/admin/customers` and `/{key}`, grouped by user uuid or normalized email) with soft username enrichment via `UserProviderInterface`.
- Added `commerce:customers:link-guests`, an operator CLI that links guest orders to a resolvable user account by exact-match normalized email.
- Added a storefront authenticated address book (`GET/POST /commerce/account/addresses`, `PATCH/DELETE /commerce/account/addresses/{uuid}`) with parent-claim serialization and default shipping/billing swap-in-transaction.
- Added optional `shipping_address_uuid`/`billing_address_uuid` checkout integration that snapshots a caller-owned saved address into the order exactly like an inline address.
- Added the address book to the admin customer detail endpoint when keyed by user uuid.
- Added shipping zones with geographic locations (country/state/postcode, including wildcard patterns) and per-zone methods (flat, free-over-threshold, per-shipping-class table), a shipping-class taxonomy, and tax-rate tables (country/state/postcode-matched, per tax-class, priority-ordered, `shipping_taxable`), with full admin CRUD, claim-serialized zone/class/rate mutations, and cascading zone deletes.
- Added nullable `tax_class` (products) and `shipping_class_uuid` (variants) fields end-to-end through create/update DTOs, services, repositories, and admin/storefront projections, preserving the resolved shipping-class slug alongside the raw uuid.
- Added DB-backed shipping-zone rate quoting and line-level tax calculation (per-class tax rates, `shipping_taxable`, largest-remainder discount allocation into per-line taxable amounts) via delegating providers that fall back to config-based shipping/flat-rate tax byte-identically when a tenant has no zone or rate rows.
- Hardened variant creation to claim (not just read) a referenced shipping class's revision, closing a TOCTOU gap against a concurrent class delete so create/update/delete all serialize against the same class row.
- Added multi-tenant coverage (isolation, name/slug reuse, adopter/registry coverage) and pgsql-verified claim-based concurrency protection for shipping zones, shipping classes, and tax rates.
- Added four read-only admin reports over a UTC date window (`GET /commerce/admin/reports/{sales,products,customers,stock}`, `commerce:read`-scoped): sales (gross/net revenue, refunds, AOV, and a window-scoped pending-orders count, bucketed by day/ISO-week/month with zero-filled series and an independently-computed summary), products (ranked variant sales by revenue or quantity with deterministic tie-breaks and line-attributed refunds, paginated), customers (new-vs-returning counts keyed by the same user/guest-email identity as the customer aggregation endpoint, bounded DB-side distinct aggregates instead of a `(days x customers)` blowup), and stock (point-in-time out-of-stock/low-stock variant list against a configurable threshold, paginated).
- Added a shared `ReportWindow` (UTC `from`/`to` defaults, 366-day span cap, day/week/month bucket boundaries) and `ReportRollup` (pure-PHP day-to-week/month folding with zero-fill) used by the sales and customers reports, plus driver-aware `DateBucketSql`/`ReportBoundarySql` SQL helpers (SQLite/MySQL/PostgreSQL) so bucketed and boundary-table queries stay indexable instead of predicating on a database week/month function.
- Added two folded range indexes on `commerce_orders` (`tenant_uuid, placed_at` and `tenant_uuid, created_at`) and one on `commerce_refunds` (`tenant_uuid, completed_at`) so the reports' windowed order/refund scans stay indexable; promoted `CustomerAggregationRepository::KEY_EXPR` to `public` so the customers report reuses the exact same new/returning identity expression as the customers admin endpoint.
- Added `commerce.reports.low_stock_threshold` config (env `COMMERCE_REPORTS_LOW_STOCK_THRESHOLD`, default 2) as the stock report's default threshold, validated 0-100000 alongside any `?threshold=` override.
- Added tenant isolation (including the `''` sentinel tenant), `commerce:read` scope enforcement, and endpoint-specific empty-result contract coverage across all four report endpoints.
- Added shared `ProductStatus`/`ProductType` vocabularies (`draft|active|archived` / `physical|digital|external|grouped`) consumed by product create, update, list filtering, and bulk status, rejecting unknown values at the boundary instead of persisting them.
- Added a live-vs-history product read split (`ProductRepository::findLiveByUuid/findLiveBySlug` vs. `findIncludingDeletedByUuid/findIncludingDeletedBySlug`), retiring the ambiguous `findByUuid`/`findBySlug`, and `DELETE /commerce/admin/products/{uuid}` (soft delete): a tombstoned product 404s on every interactive admin/storefront/relationship/review-create path, keeps reserving its slug (a normal 422 on reuse, never a raw unique-constraint error), leaves variants/stock/media untouched, and refuses a silent cart-line drop with a controlled unavailable-product 422 instead.
- Added compositional guarded product/variant patch primitives (claim → live re-read → validate-full-patch → single write) shared by ordinary status/price PATCH and the new bulk endpoints, so a bulk write and a single-resource PATCH always serialize against the same row lock.
- Added three bulk admin endpoints — `POST /commerce/admin/products/bulk-status`, `/variants/bulk-price`, `/reviews/bulk` — capped at 100 items, rejecting duplicate/malformed input as one whole-request 422 before any write, processing the rest per-item with a closed `{applied, failed: [{uuid, reason}]}` outcome vocabulary.
- Added a folded `commerce_discounts.revision` column, a guarded `DELETE /commerce/admin/discounts/{uuid}` (409 with a "disable via status" hint once redeemed, row left intact) serialized against checkout redemption through the same claimed row, and a paginated/filtered (`status`/`q`) admin discount list.
- Added pagination and shared literal-substring `q` filters (escaping `%`/`_`/the escape character via a new `Support\LiteralLike` helper) to the admin products, tags, attributes, shipping zones, shipping classes, and tax rates lists, plus `status`/`type` filters on products; every retrofitted list carries a stable uuid tie-break and identical count/row predicates.
- Added new admin show endpoints for categories, tags, attributes (embedding values), shipping zones (embedding locations + methods), shipping methods, shipping classes, tax rates, discounts, reviews, and a new cross-order refunds list/show (`GET /commerce/admin/refunds`, `/{uuid}`, filtered by `status`/`order`/`from`/`to`).
- Added `PATCH /commerce/admin/tags/{uuid}` (name-only rename; slug stays immutable) and `GET /commerce/admin/orders/{uuid}/notes`, symmetric with the existing notes write.
- Added public storefront category browse (`GET /commerce/categories`, a strict `slug,name,description,position,image_url,children` allowlist tree) and `category`/`tag`/`attributes` filters on `GET /commerce/products` (exact-slug category/tag, `attribute-slug:value-slug` pairs with AND semantics, max 5), resolved via one batched tenant-scoped lookup per filter kind and correlated `EXISTS` semijoins — never a result-multiplying join — with a new `Support\JsonStringArrayContainsSql` helper giving exact (never substring) cross-driver attribute-value membership.
- Added public storefront reviews: `POST /commerce/products/{slug}/reviews` (always lands `pending`; caller-supplied identity is never trusted) and `GET .../reviews` (approved-only, paginated, field-allowlisted), both gated behind new `commerce.rate_limits.catalog`/`commerce.rate_limits.review_submit` config keys.
- Hardened `HttpDocumentationTest` to build a fresh `Router` from `routes.php` and walk every registered commerce route action instead of a hand-maintained controller list, failing explicitly (named `class::method`) on any unannotated action or unsupported handler shape.
- Added a pgsql-gated API-parity lane (`ApiParityPgsqlTest`) proving the storefront attribute-value filter's exact JSON containment on real PostgreSQL (`red` never matches `bred`), the discount delete-vs-checkout-redemption race in both orderings under true two-connection row-lock interleaving, the product soft-delete race (exactly one winner), and bulk-vs-single-write serialization against a real concurrent connection.
- Applied an explicit field allowlist to the storefront product projection (`GET /commerce/products` and `GET /commerce/products/{slug}`): products now expose only `uuid,slug,name,description,type,options,created_at` plus derived enrichments, and variants only `uuid,sku,option_values,price,compare_at_price,currency,position,status,shipping_class_uuid,shipping_class` — the previously spread internal columns (`tenant_uuid`, `status`, raw `metadata`, `rating_sum`/`rating_count`, `catalog_revision`, `tax_class`, numeric ids, `updated_at`/`deleted_at`) no longer leave the public surface.

