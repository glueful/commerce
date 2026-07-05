# Glueful Commerce

Commerce primitives for Glueful apps: catalog, inventory, carts, discounts,
pricing, checkout, orders, and payment integration seams.

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
| Users | Guest checkout works through hashed bearer order tokens. | Authenticated order listing can use Glueful's normal identity stack; guest lookups remain token-protected. |

Commerce never binds defaults under shared contract IDs. It resolves
`container()->has(Contract::class) ? get() : inline fallback`, matching Glueful's
soft-binding seam pattern.

## Storefront API

Base path: `/commerce`.

- `GET /products`
- `GET /products/{slug}`
- `POST /cart`
- `GET /cart`
- `POST /cart/lines`
- `PATCH /cart/lines/{uuid}`
- `DELETE /cart/lines/{uuid}`
- `POST /cart/discount`
- `DELETE /cart/discount`
- `POST /checkout/quote`
- `POST /checkout`
- `GET /orders/{number}`
- `POST /orders/{number}/payment`
- `GET /orders` authenticated

Cart endpoints use `X-Cart-Token`. Guest order endpoints use `X-Order-Token`.
Tokens are returned raw once, stored only as SHA-256 hashes, and are never
accepted from query strings.

## Admin API

Base path: `/commerce/admin`, authenticated with `commerce:read` or
`commerce:write` scopes.

- Products: list, show, create, update, create variant, update variant
- Stock: adjust a variant and write a movement ledger row
- Discounts: list, create, update
- Orders: list, show, cancel, mark paid, fulfill, mark refunded

## Configuration

```php
return [
    'currency' => env('COMMERCE_CURRENCY', 'USD'),
    'tax' => ['flat_rate_bps' => (int) env('COMMERCE_TAX_BPS', 0)],
    'shipping' => [
        'methods' => [
            ['id' => 'standard', 'label' => 'Standard shipping', 'amount' => 500, 'free_over' => 5000],
        ],
    ],
    'cart' => ['ttl_days' => (int) env('COMMERCE_CART_TTL_DAYS', 30)],
    'orders' => [
        'expiry_minutes' => (int) env('COMMERCE_ORDER_EXPIRY_MINUTES', 60),
        'number_format' => env('COMMERCE_ORDER_NUMBER_FORMAT', 'ORD-{seq}'),
    ],
    'tenancy' => ['enabled' => (bool) env('COMMERCE_TENANCY_ENABLED', false)],
];
```

All money is stored in integer minor units. Variant currency must match the
store currency.

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
- `commerce:diagnose`
