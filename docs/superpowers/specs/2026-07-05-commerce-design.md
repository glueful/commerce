# Commerce — Design

**Date:** 2026-07-05
**Status:** Draft for review
**Package:** `glueful/commerce` — commerce primitives for Glueful apps: products,
carts, orders, inventory, discounts, checkout, and payment integration.

## Goal

A headless commerce primitive extension in the tenancy/subscriptions mold: it
owns the hard invariants (oversell-safety, totals correctness, order
immutability, credential hygiene) and puts every environmental concern —
payments, tax, shipping rates, tenancy — behind a small contract with a shipped
default. Consuming apps bring their own UI.

## 1. Positioning & dependencies

- Namespace `Glueful\Extensions\Commerce`, standard pack layout: flat
  `migrations/` at the pack root, `loadMigrationsFrom()` in `boot()` outside the
  enable gate, `services()` definitions with `use`-imports, CLI on
  `Glueful\Console\BaseCommand`.
- **Hard dependency:** the framework only.
- **Soft dependencies** (probed via `class_exists`, subscriptions-style
  decoupling invariant, each with documented degradation):
  - `glueful/payvia` — real payment collection (order as the polymorphic
    payable). Absent → manual-payment mode still places orders.
  - `glueful/tenancy` — row-level multi-store when `commerce.tenancy.enabled`.
    Absent/off → single store.
  - `glueful/users` — nothing structural; `user_uuid` columns are unconstrained
    external ids (no FK — the users-extraction rule).
- **Diagnosability pin:** `commerce:diagnose` reports every soft-dep bridge —
  whether payvia/tenancy bindings are active, and which contract binding is in
  use for `PaymentCollector` / `TaxCalculator` / `ShippingRateProvider` /
  `CurrentTenantResolver` (default vs bridge, by FQCN).

## 2. Data model

All tables are `commerce_`-prefixed with `uuid` string(12) public ids.

### The tenant key (P1 pin — resolved)

Composite uniques over a **nullable** `tenant_uuid` do not enforce: SQL treats
NULLs as distinct, so `UNIQUE (tenant_uuid, sku)` admits duplicate SKUs in
every single-store install. Partial unique indexes fix it on
PostgreSQL/SQLite but **MySQL has none**. The portable resolution, pinned:

> `tenant_uuid` is `string(12) NOT NULL DEFAULT ''` on every commerce table.
> `''` is the single-store sentinel; real tenant uuids are stamped only when
> tenant mode is on. Every business key is composite:
> `UNIQUE (tenant_uuid, sku)`, `(tenant_uuid, slug)`,
> `(tenant_uuid, order_number)`, `(tenant_uuid, code)`,
> `(tenant_uuid, token_hash)`. With no NULLs, these enforce identically on
> SQLite, MySQL, and PostgreSQL — and flipping tenant mode needs no migration.

### Bearer tokens are stored hashed (P1 pin)

`cart` tokens and order `guest_token`s are bearer credentials. The raw value is
generated once (CSPRNG), returned once in the create/place response, and only
its SHA-256 hex lands in the DB (`token_hash` columns). Lookups compare by
hash; a raw token is never persisted or logged — the API-key/reset-token
posture. **Presentation is header-only (P1 pin):** both tokens travel in
headers (`X-Cart-Token`, `X-Order-Token`), NEVER in query strings — query
params land in access logs, browser history, and referrers, which would undo
the hashing. Emailed "view your order" links are app/frontend URLs; the
frontend presents the token to the API in the header.

### Tables

- **`commerce_products`** — `slug` (composite unique), `name`, `description`,
  `type` (`physical|digital`), `status` (`draft|active|archived`), `options`
  JSON (`{"Size":["S","M"]}`), `metadata` JSON, timestamps, `deleted_at`.
- **`commerce_variants`** — `product_uuid`, `sku` (composite unique),
  `option_values` JSON, `price` (int minor units), `compare_at_price` nullable
  int, `currency` char(3), `position`, `status`. Every product has ≥ 1 variant
  (optionless products get one default variant); carts, orders, and stock key
  on variants only. **Single-currency invariant (P1 pin):** in v1 a variant's
  `currency` MUST equal the configured store currency — enforced at variant
  write time (422) and revalidated at checkout (a mismatch after a config
  change is a per-line 422, never a silently mixed-unit total). The column
  exists so orders snapshot from data, and multi-currency later is additive.
- **`commerce_stock`** — one row per variant: `quantity` int, `tracked` bool
  (untracked = infinite; digital default). Unique `(tenant_uuid, variant_uuid)`.
- **`commerce_stock_movements`** — append-only ledger: `variant_uuid`, `delta`,
  `reason` (`order|release|adjustment|restock`), `reference_uuid`,
  `created_at`. Quantity is always auditable from the ledger.
- **`commerce_carts`** — `token_hash` (composite unique), nullable `user_uuid`,
  `discount_code` nullable, `status` (`active|converted|abandoned`),
  `expires_at`, timestamps.
- **`commerce_cart_lines`** — `cart_uuid`, `variant_uuid`, `quantity`. Carts
  re-price live on every read; no price snapshots at cart level. Unique
  `(cart_uuid, variant_uuid)`.
- **`commerce_orders`** — `order_number` (composite unique; see generator
  below), `status`, `fulfillment_status` (`unfulfilled|fulfilled`) +
  `tracking_ref` nullable, buyer `email`, nullable `user_uuid`,
  `guest_token_hash`, `currency`, integer totals (`subtotal`, `discount_total`,
  `shipping_total`, `tax_total`, `grand_total`), `discount_code` +
  `shipping_method` snapshots, `addresses` JSON (billing/shipping),
  `placed_at`, `metadata` JSON.
- **`commerce_order_lines`** — full denormalized snapshots: `product_name`,
  `sku`, `option_values` JSON, `unit_price`, `quantity`, `line_total`. An
  order never changes meaning when the catalog does.
- **`commerce_order_events`** — append-only order audit: `order_uuid`, `type`
  (status transitions, `payment_initiated`, `payment_init_failed`,
  `payment_late_rejected`, …), `payload` JSON, `created_at`.
- **`commerce_discounts`** — `code` (composite unique), `type`
  (`percentage|fixed|free_shipping`), `value`, `min_subtotal`, `starts_at` /
  `ends_at`, `usage_limit` nullable int, `once_per_buyer` bool, `usage_count`,
  `product_scope` JSON (product-uuid allowlist; null = all), `status`.
- **`commerce_discount_redemptions`** — `discount_uuid`, `order_uuid`,
  `buyer_identity` (user_uuid if present, else lowercased email), and
  `buyer_key` — the **uniqueness discriminator (P1 pin)**: when the discount
  has `once_per_buyer = true`, `buyer_key = buyer_identity`; otherwise
  `buyer_key = order_uuid`. Unique `(tenant_uuid, discount_uuid, buyer_key)`
  therefore enforces once-per-buyer ONLY for flagged discounts while staying
  trivially satisfiable (one row per order) for the rest — a non-once discount
  is usable repeatedly by the same buyer. (Partial indexes would be the
  alternative; MySQL has none, so the discriminator column is the portable
  form.) Rows are always written for audit either way. v1 pins the per-buyer
  limit as **once per buyer** (boolean), not an arbitrary N — counting
  semantics are a follow-up.
- **`commerce_sequences`** — `(tenant_uuid, name)` unique, `value` int. The
  order-number counter (see below).

### Order-number generation (P2 pin — resolved)

Never `max(order_number) + 1`. Inside the place-order transaction, the
generator does a conditional atomic increment on the `commerce_sequences` row
(`UPDATE ... SET value = value + 1 WHERE tenant_uuid = ? AND name = 'order'`,
then read back in the same transaction — the row lock serializes concurrent
checkouts). **First-use path (P2 pin):** the row is created lazily and
race-safely — attempt the `UPDATE`; zero affected rows → `INSERT (tenant_uuid,
name, value = 1)`; a duplicate-key failure there means a concurrent checkout
won the insert, so retry the `UPDATE` once. Portable (no upsert dialect
needed), and works identically for the sentinel tenant and any newly created
tenant. The formatted number (`commerce.orders.number_format`, e.g.
`ORD-{seq:06}`) is written under the composite unique as a backstop; a unique
violation is a bug, not a retry path. A concurrency test pins two simultaneous
placements receiving distinct numbers, including the fresh-sequence first
order.

## 3. Contracts & shipped defaults

`Contracts/` interfaces, each with a default bound in the provider; bridges
rebind when their package exists:

- **`PaymentCollector`** — `initiate(Order): PaymentInitiation` (the payload
  the storefront returns to the client). **Idempotency pin:** `initiate()` is
  retry-safe — calling it again for the same order returns/refreshes the same
  logical payment intent (keyed by order uuid), never double-charges, and is
  the engine behind the payment-retry endpoint (§5). Confirmation always lands
  through `OrderPaymentService::markPaid(order)` regardless of collector.
  - Default `ManualPaymentCollector`: orders sit `pending_payment`; mark-paid
    via admin endpoint or CLI.
  - `PayviaPaymentCollector` (bound only when payvia is installed): order is
    the payable (`payable_type = commerce_order`), confirm/webhook flow calls
    `confirmAndRecord()` then `markPaid()`.
- **`TaxCalculator`** — `quote(TotalsContext): TaxQuote`. Default: config
  flat-rate in basis points (default 0).
- **`ShippingRateProvider`** — `quote(cart, address): list<ShippingQuote>`.
  Default reads `commerce.shipping.methods`: flat rate, free-over-threshold,
  per-zone country lists. Digital-only carts skip shipping entirely.
- **`CurrentTenantResolver`** — current tenant uuid or `''`;
  tenancy-backed when tenant mode is on, sentinel resolver otherwise.

**PricingEngine** (not a contract — the pure core):
`(lines, discount?, shippingQuote?, taxQuote?) → Totals`. Integer minor-unit
arithmetic only; fixed order: subtotal → discount (clamped ≥ 0; percentages
round half-up on minor units; `product_scope` restricts the discountable base)
→ shipping (`free_shipping` zeroes it) → tax → grand total. Exhaustively
unit-tested in isolation.

## 4. Checkout flow & order lifecycle

`CheckoutService::placeOrder(cart, buyerInfo, shippingMethodId)`:

1. **Revalidate** lines (variant + product active) and the discount (window,
   status, scope, `min_subtotal`) — advisory pass; failures are per-line 422s.
2. **Quote totals** via PricingEngine — the same path as
   `POST /checkout/quote`, so quoted always equals charged.
3. **One transaction:**
   - Conditional stock decrement per **tracked** line — untracked lines skip
     the decrement entirely (`UPDATE commerce_stock SET quantity = quantity
     - :n WHERE variant_uuid = :v AND tenant_uuid = :t AND tracked = 1 AND
     quantity >= :n` — zero affected rows → roll back, 409 listing the short
     lines) + ledger rows (`reason: order`).
   - **Discount enforcement is transactional, not advisory (P1 pin):** the
     global limit is a conditional increment (`UPDATE commerce_discounts SET
     usage_count = usage_count + 1 WHERE uuid = ? AND (usage_limit IS NULL OR
     usage_count < usage_limit)`; zero rows → roll back, discount-exhausted
     422). Once-per-buyer is the redemption row's composite unique — a
     duplicate-key failure rolls back with the same 422 shape. Two concurrent
     checkouts can never both consume the last redemption.
   - Insert order (+ generated order number, §2), snapshot lines, order event
     (`placed`), redemption row; mark cart `converted`.
4. **Payment initiation — outside the transaction, explicit failure semantics
   (P1 pin):** `PaymentCollector::initiate(order)` runs after commit.
   - Success → `payment_initiated` order event; payload returns to the client.
   - Failure → `payment_init_failed` order event; checkout returns **HTTP 201
     with the order reference and a `payment: {status: "init_failed",
     retryable: true}` block** — the order exists, stays `pending_payment`,
     and its stock hold persists until expiry. The client resumes via the
     payment-retry endpoint (§5). A provider outage therefore never creates
     hidden orders: the buyer always receives the order number + guest token
     and a defined resume path.

**Order state machine** (`OrderStateMachine`, the single transition
authority): `pending_payment → paid → fulfilled`; `canceled` reachable from
`pending_payment`/`paid`; `refunded` from `paid`/`fulfilled` (v1 refund =
status + event; money movement stays in the payment provider). Every
transition writes an order event.

**Expiry & the pay window:** `commerce:orders:expire` (CLI, cron-able) cancels
`pending_payment` orders older than `commerce.orders.expiry_minutes` and
restocks via ledger (`reason: release`). Pending-payment orders ARE the stock
hold — there is no separate reservation system (§9). **Late-payment pin (P2):**
a payment confirmation arriving for an expired/canceled order is NOT silently
left to the app — commerce records a `payment_late_rejected` order event with
the provider payload reference and emits a `LatePaymentRejected` event so apps
can alert/refund; the order status does not change.

**Events** (all extend `BaseEvent`): `OrderPlaced`, `OrderPaid`,
`OrderCanceled`, `OrderFulfilled`, `StockAdjusted`, `DiscountRedeemed`,
`LatePaymentRejected`. Commerce sends no mail itself.

## 5. API surface

**Storefront** (public, `/commerce` prefix):

- `GET /products`, `GET /products/{slug}` — active only, paginated,
  field-selection friendly.
- Cart ops — `POST /cart` (issues the raw token once), `GET /cart`,
  `POST /cart/lines`, `PATCH|DELETE /cart/lines/{uuid}`,
  `POST|DELETE /cart/discount` — authenticated by `X-Cart-Token` (hash
  lookup).
- `POST /checkout/quote` — totals + shipping options for an address.
- `POST /checkout` — place order (returns order number, guest token once,
  payment payload or `init_failed` block).
- `POST /orders/{number}/payment` — **payment retry**: re-invokes
  `PaymentCollector::initiate()` for a `pending_payment` order; authorized by
  `X-Order-Token` header (hashed compare) or authenticated ownership.
- `GET /orders/{number}` — `X-Order-Token` header (hashed compare) or
  authenticated ownership; the guest token NEVER travels in the query string
  (§2). **Enumeration pin:** wrong number and wrong token return the SAME
  generic 404 shape — order numbers are sequential, so the response must
  never distinguish "order exists" from "bad token".
- `GET /orders` — authenticated "my orders".
- Cart merge: an authenticated request presenting a guest cart token
  attaches/merges into the user's active cart (quantities add, capped by
  stock).
- **Rate-limit pin:** cart-token endpoints, `POST /checkout`, the payment
  retry, and guest order lookup all carry `rate_limit` middleware (config-
  tunable) — they are public bearer-token surfaces and brute-force targets.

**Admin** (auth + `RequireScope`; scopes `commerce:read` / `commerce:write`):
products + variants CRUD, stock adjust (ledgered), discounts CRUD, orders
list/detail, transitions (`cancel`, `mark-paid`, `fulfill` + tracking ref,
`mark-refunded`).

**CLI:** `commerce:orders:expire`, `commerce:carts:prune` (marks carts idle
past `cart.ttl_days` as `abandoned`; converted carts are never touched),
`commerce:stock:adjust`, `commerce:tenancy:adopt` (§6), `commerce:diagnose`
(§1).

## 6. Configurable tenancy

`commerce.tenancy.enabled` (default `false`).

- **Enabled + tenancy installed:** the provider merges commerce's table list
  into `tenancy.tables` (the raw-query backstop), binds the tenancy-backed
  `CurrentTenantResolver`, and repositories stamp `tenant_uuid` on insert;
  reads are scoped by tenancy's connection hook AND by the repositories
  themselves passing the resolved tenant into every query (belt and braces —
  the hook alone must not be the only defense).
- **Disabled:** sentinel resolver (`''`), no stamping beyond the column
  default, hooks never see commerce tables.
- **Enabled without tenancy installed:** loud boot error — misconfiguration,
  not degradation.
- **Data posture on enable (P2 pin):** flipping the flag on a POPULATED store
  needs no schema migration, but existing rows keep `tenant_uuid = ''` and
  belong to NO tenant — tenant-scoped requests start from empty data. That is
  the default and it is deliberate. To carry existing data into a tenant, run
  the one-shot `commerce:tenancy:adopt --tenant=<uuid>` command: it re-keys
  every sentinel row (all commerce tables, including sequences and redemption
  `buyer_key` recomputation) to the given tenant inside one transaction, and
  refuses to run if any non-sentinel rows already exist. `commerce:diagnose`
  reports sentinel-row counts when tenant mode is on, so orphaned data is
  visible, never silent.
- **Test pin:** every repository gets a "tenant scope required when enabled"
  test — with two tenants seeded, each read/write helper provably cannot see
  or touch the other tenant's rows even when called outside an ORM path.

## 7. Config & money

`config/commerce.php`: `currency` (single store currency), `tax.flat_rate_bps`,
`shipping.methods` (label, amount, `free_over`, `zones` country lists),
`cart.ttl_days`, `orders.expiry_minutes`, `orders.number_format`,
`tenancy.enabled`, `rate_limits.*`.

**Money pins:** every amount everywhere is an integer in minor units; orders
snapshot their currency. The configured currency is validated against ISO 4217
including its **minor-unit exponent** (a bundled code → exponent map): JPY
(0-decimal) and KWD (3-decimal) style currencies must format correctly —
arithmetic stays integer; only display/serialization uses the exponent
(`amount`, `currency`, and a derived `formatted` field in API resources).

## 8. Testing

Pack-local PHPUnit + SQLite harness (payvia/subscriptions style):

- PricingEngine unit matrix — rounding (half-up), clamping at zero, free
  shipping, scoped discounts, 0/3-decimal currency formatting.
- Oversell proof — two concurrent placements against quantity 1: exactly one
  succeeds, the other 409s; ledger reconciles.
- **Null-tenant uniqueness** — duplicate SKU / order number / discount code /
  cart token-hash rejected in single-store (sentinel) mode.
- **Hashed-token lookups** — raw token never stored; header-presented lookup
  by hash works; wrong token on order lookup returns the generic 404; tokens
  in query strings are rejected/ignored.
- **Concurrent discount limit** — two checkouts racing the last redemption:
  one wins, one 422s (global limit AND once-per-buyer `buyer_key` unique);
  a non-once discount redeems twice for the same buyer without conflict.
- **Variant-currency invariant** — variant write in a non-store currency 422s;
  a config currency change after creation surfaces as a checkout line 422.
- **Payment-init failure after commit** — collector throws: order exists as
  `pending_payment` with `payment_init_failed` event, checkout response
  carries the retryable block, retry endpoint completes the flow.
- **Order-number concurrency** — parallel placements get distinct sequential
  numbers.
- Checkout integration end-to-end incl. quote-equals-charge; guest-cart merge;
  discount constraint matrix (window, min subtotal, scope); order expiry
  restocks (ledger `release`); late payment records `payment_late_rejected`.
- **Decoupling invariant** — no payvia: manual mode end-to-end; tenancy flag
  off: no scoping, sentinel rows.
- **Tenant scoping per repository** (§6) including raw-ish query helpers.

## 9. Out of scope (v1)

Multi-currency price lists; automatic promotions / discount stacking (one code
per cart); **stock reservations** — explicitly out: pending-payment orders act
as the stock hold until expiry (§4); partial/multi-parcel fulfillment;
returns/RMA workflow; real tax engines (contract only); carrier rate
integrations (contract only); customer account UI; search beyond listing
filters; per-tenant currency; arbitrary-N per-buyer discount limits.
