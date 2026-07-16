# Glueful Commerce

Commerce for Glueful apps: catalog (products, variants, categories, tags,
attributes, media, add-ons, reviews), inventory, carts, discounts, pricing,
checkout, orders, refunds, digital delivery, shipping zones and tax tables,
customer aggregates, reports, and payment integration seams.

All money is integer minor units. Variant currency must match the store
currency. Every mutable row is guarded by affected-row-checked revision claims;
concurrency behavior is proven by two-connection PostgreSQL race tests.

## Install

```bash
composer require glueful/commerce
php glueful extensions:enable commerce
php glueful migrate:run
```

Commerce requires `glueful/extension-contracts`. Implementations such as
Tenancy and Payvia bind those shared contracts when installed; Commerce consumes
only the contracts and keeps zero hard class references to those extensions.

## Decoupling Invariants

| Capability | Without extension | With extension |
| --- | --- | --- |
| Payments | `ManualPaymentCollector` returns retryable manual payment instructions. | A provider such as Payvia binds `PaymentCollector`; Commerce stores orders and starts payment through the contract. |
| Tenancy | Every row uses the `''` sentinel tenant key. | Tenancy binds `CurrentTenantResolver` and `TenantTableRegistry`; Commerce fail-closes when no tenant is resolved. |
| Users | Guest checkout works through hashed bearer order tokens. | Authenticated order listing and customer detail enrichment use `UserProviderInterface`; guest lookups remain token-protected. |
| Media | Product media stores blob links; thumbnails absent. | `glueful/media` provides image processing for uploaded blobs. |
| Email | No transactional email is sent (master switch also defaults off). | With an email channel configured and `COMMERCE_EMAIL_ENABLED=true`, order lifecycle emails (placed/paid/fulfilled/refunded/note) are dispatched, including one-time digital-download deep links. |

Commerce never binds defaults under shared contract IDs. It resolves
`container()->has(Contract::class) ? get() : inline fallback`, matching Glueful's
soft-binding seam pattern.

Shipping and tax are contracts with delegating defaults: app-bound provider >
DB-backed (when zone/rate rows exist) > config fallback. Line-level tax is
available through the optional `LineTaxCalculator` contract; checkout dispatches
it automatically when the bound calculator implements it.

## Storefront API

Base path: `/commerce`. Public routes are rate-limited (config-driven).

- `GET /products` — paginated; filters: `category` (slug), `tag` (slug),
  `attributes` (`attribute-slug:value-slug` pairs, AND, max 5)
- `GET /products/{slug}` — enriched: variants, media, categories, tags,
  attributes, add-ons, rating, grouped children / external payload.
  Field-allowlisted; internal columns never leave this surface.
- `GET /categories` — public category tree (allowlisted fields only)
- `POST /products/{slug}/reviews` — public submit, always lands `pending`
- `GET /products/{slug}/reviews` — approved only, paginated
- `POST /cart`, `GET /cart`, `POST /cart/lines`, `PATCH|DELETE /cart/lines/{uuid}`,
  `POST|DELETE /cart/discount`
- `POST /checkout/quote`, `POST /checkout`
- `GET /orders/{number}`, `POST /orders/{number}/payment`
- `GET /orders` (authenticated), `GET /orders/{number}/downloads`,
  `POST /orders/{number}/downloads/{grantUuid}/url`
- `GET /downloads/{token}` — grant-validated, short-lived signed blob URL
- `GET|POST /account/addresses`, `PATCH|DELETE /account/addresses/{uuid}`
  (authenticated address book)

Cart endpoints use `X-Cart-Token`. Guest order endpoints use `X-Order-Token`.
Tokens are returned raw once, stored only as SHA-256 hashes, and are never
accepted from query strings.

## Admin API

Base path: `/commerce/admin`, authenticated with `commerce:read` or
`commerce:write` scopes. Lists are offset-paginated with stable ordering and
literal (escaped) substring filters.

- Products: list (`status`/`q`/`type` filters), show, create, update, soft
  delete (tombstone keeps its slug reserved), variants, grouped children
- Bulk: `POST /products/bulk-status`, `POST /variants/bulk-price`,
  `POST /reviews/bulk` — cap 100, per-item outcomes `{applied, failed}`
- Catalog breadth: categories (tree + show + CRUD), tags (list/show/create/
  rename/delete), attributes (+values), product media (attach/reorder/update/
  detach), add-ons
- Reviews: list, show, create, approve, spam, delete
- Stock: adjust a variant and write a movement ledger row
- Discounts: list, show, create, update, guarded delete (409 while redeemed)
- Orders: list, show, cancel, mark paid, fulfill, notes (add + list),
  invoice data
- Refunds: issue per order (idempotency-key required), per-order list,
  cross-order list (`status`/`order`/`from`/`to`) and show
- Digital delivery: variant downloads CRUD, grant revoke,
  refund-access override
- Customers: list and show (aggregates over orders by user/email identity)
- Shipping: zones (+locations, +methods), classes; Tax: rate tables — full
  CRUD, consumed by the delegating shipping/tax providers
- Reports: `GET /reports/sales`, `/reports/products`, `/reports/customers`
  (day/week/month windows), `/reports/stock` (low/out-of-stock thresholds)

Every route action carries OpenAPI annotations; a CI test walks the full route
manifest and fails on any unannotated action.

## Configuration

```php
return [
    'currency' => env('COMMERCE_CURRENCY', 'USD'),

    // Config fallbacks — used only until DB zone/rate rows exist or an app
    // binds its own providers.
    'tax' => ['flat_rate_bps' => (int) env('COMMERCE_TAX_BPS', 0)],
    'shipping' => [
        'methods' => [
            ['id' => 'standard', 'label' => 'Standard shipping', 'amount' => 500, 'free_over' => 5000],
        ],
    ],

    'cart' => ['ttl_days' => (int) env('COMMERCE_CART_TTL_DAYS', 30)],

    'rate_limits' => [
        'cart' => [(int) env('COMMERCE_CART_RATE_LIMIT', 60), 60],
        'checkout' => [(int) env('COMMERCE_CHECKOUT_RATE_LIMIT', 30), 60],
        'orders' => [(int) env('COMMERCE_ORDER_RATE_LIMIT', 60), 60],
        'downloads' => [(int) env('COMMERCE_DOWNLOADS_RATE_LIMIT', 60), 60],
        'catalog' => [(int) env('COMMERCE_CATALOG_RATE_LIMIT', 120), 60],
        'review_submit' => [(int) env('COMMERCE_REVIEW_SUBMIT_RATE_LIMIT', 5), 60],
    ],

    // Digital-delivery signed URLs.
    'downloads' => ['url_ttl' => (int) env('COMMERCE_DOWNLOADS_URL_TTL', 300)],

    'orders' => [
        'expiry_minutes' => (int) env('COMMERCE_ORDER_EXPIRY_MINUTES', 60),
        'number_format' => env('COMMERCE_ORDER_NUMBER_FORMAT', 'ORD-{seq}'),
    ],

    'tenancy' => ['enabled' => (bool) env('COMMERCE_TENANCY_ENABLED', false)],

    // Null-tolerant: invoice-data serializes each key as null, never omitted.
    'seller' => [
        'name' => env('COMMERCE_SELLER_NAME'),
        'address' => env('COMMERCE_SELLER_ADDRESS'),
        'tax_id' => env('COMMERCE_SELLER_TAX_ID'),
    ],

    'email' => [
        // Master switch: OFF by default, even when an email channel is active.
        'enabled' => (bool) env('COMMERCE_EMAIL_ENABLED', false),
        'templates' => [
            'order_placed' => true,
            'order_paid' => true,
            'order_fulfilled' => true,
            'order_refunded' => true,
            'order_note' => true,
        ],
    ],

    'reports' => [
        // Low-stock report threshold (0..100000); per-request ?threshold= override.
        'low_stock_threshold' => (int) env('COMMERCE_REPORTS_LOW_STOCK_THRESHOLD', 2),
    ],
];
```

## Digital Delivery

Variants carry downloadable files (private blobs). Grants are issued
transactionally when an order is paid: snapshot-derived, idempotent per
`(order, download)`, token-hashed. Access flows through
`GET /commerce/downloads/{token}` (grant checks + short-lived signed blob URL);
a `BlobAccessPolicy` contributor backstops direct blob access. Refunded orders
lose access unless an admin sets a per-grant override. `commerce:downloads:backfill`
heals grants for orders paid before a download was attached.

## Tenancy Adoption

Single-store installs use `tenant_uuid = ''`. If enabling tenancy after data
exists, adopt sentinel rows into one tenant before serving tenant-scoped traffic:

```bash
php glueful commerce:tenancy:adopt --tenant=<tenant-uuid>
```

The command runs in one transaction, refuses mixed tenant data, and rekeys all
commerce tenant-owned tables including sequences. `commerce:diagnose` reports
contract bindings and sentinel row counts so orphaned rows are visible.

## Maintenance Commands

- `commerce:orders:expire`
- `commerce:carts:prune`
- `commerce:stock:adjust <variant-uuid> <delta> [--reason=]`
- `commerce:downloads:backfill`
- `commerce:customers:link-guests`
- `commerce:tenancy:adopt`
- `commerce:diagnose`

## Migrating from WooCommerce

`docs/woocommerce-migration-comparison.md` tracks feature-by-feature coverage.
Commerce matches Woo's merchant-observable semantics where migrated stores
depend on them (zone first-match ordering, tax rate priority, low-stock
defaults) without adopting Woo's architecture; subscriptions, memberships, and
bundles are deliberate non-goals.
