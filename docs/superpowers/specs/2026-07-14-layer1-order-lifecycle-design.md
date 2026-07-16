# Layer 1 — Order Lifecycle: Refunds, Notes, Transactional Email

**Status:** revised after review; ready for implementation planning
**Parent:** `2026-07-14-woocommerce-parity-overview-design.md` (Layer 1)
**Repos touched:** `glueful/extension-contracts` (one new contract + two VOs), `glueful/commerce`
**Release chain:** contracts → commerce. Payvia is *not* touched (it has no refund surface
today — verified; gateway refunds are a later Payvia feature against this contract).

## 1. Scope

1. Refunds: partial + full, optional restock, recorded in dedicated refund + refund-line
   tables; order `refunded` status becomes derived. Manual-first; `RefundCollector` contract
   for gateways, with a reserve → external call → finalize saga.
2. Order notes: internal + customer-visible, layered onto `commerce_order_events`.
3. Transactional email: event-subscribed, soft email-channel dependency, never fails a
   persisted order operation.
4. Invoice data: one read endpoint exposing a stable JSON contract; no PDF rendering.

Out of scope: concrete gateway webhook/polling adapters (the contract + settlement seam support
them, but no bundled collector returns `pending` in v1), customer-initiated refund requests,
credit notes.

## 2. Contracts (`glueful/extension-contracts`)

New, alongside the existing payments contracts. `PaymentCollector` is untouched.

```php
namespace Glueful\Extensions\Contracts\Payments;

/**
 * Returns money for a previously collected payable. Implementations must be
 * idempotent per idempotency key: repeat calls report the same logical refund
 * and never move money twice. Throwing signals infrastructure failure; business
 * failure is a RefundResult with status FAILED.
 */
interface RefundCollector
{
    public function refund(
        ApplicationContext $context,
        PayableReference $payable,
        RefundRequest $request
    ): RefundResult;
}

final class RefundRequest
{
    public function __construct(
        public readonly int $amount,          // minor units
        public readonly string $currency,
        public readonly string $idempotencyKey,
        public readonly ?string $reason = null,
    ) {}
}

final class RefundResult
{
    // status: 'completed' | 'pending' | 'failed'
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerRef = null,
        public readonly ?string $failureReason = null,
    ) {}
}
```

- Commerce soft-resolves `RefundCollector` from the container. **Unbound = manual mode**:
  the operator asserts funds were returned out-of-band (gateway dashboard, cash, bank);
  commerce records the refund as `completed` with `method = 'manual'`.
- `pending` results are stored as `pending` and excluded from `refunded_total` until
  completed. Commerce exposes an idempotent application-service settlement seam now
  (`RefundService::settle()`); a future gateway webhook/confirmation adapter calls that seam.
  No public settlement route ships in v1.
- The shared contract permits `pending`, but the commerce service never leaves one without a
  recovery path: replaying the original request with the same idempotency key safely asks the
  collector again, and `settle()` can finalize an asynchronous result.

## 3. Data Model (`glueful/commerce`)

**New tables** — migration `006_CreateCommerceRefundTables.php`:

`commerce_refunds`
| column | type | notes |
|---|---|---|
| id | bigInteger auto PK | |
| uuid | string 12, unique | |
| tenant_uuid | string 12, default '' | + index |
| order_uuid | string 12 | + index |
| idempotency_key | string 128 | unique with `tenant_uuid`; required from `Idempotency-Key` |
| request_fingerprint | string 64 | canonical request SHA-256; detects key reuse with a different request |
| amount | bigInteger | minor units, > 0 |
| currency | string 3 | must equal order currency |
| method | string 16 | `manual` \| `gateway` |
| status | string 16 | `pending` \| `completed` \| `failed` |
| reason | text, nullable | operator-entered |
| restocked | bool, default false | whether stock was returned |
| provider_ref | string 191, nullable | gateway reference |
| failure_reason | text, nullable | terminal business failure or latest unknown-outcome error |
| initiated_by | string 12, nullable | operator user uuid |
| created_at / updated_at / completed_at (nullable) | timestamps | |

`commerce_refund_lines`
| column | type | notes |
|---|---|---|
| id | bigInteger auto PK | |
| refund_uuid | string 12 | indexed; unique with `order_line_uuid` |
| order_line_uuid | string 12 | immutable order-line reference |
| quantity | integer | > 0; restock quantity |
| amount | bigInteger | minor-unit attribution, ≥ 0 |

Refund lines are normalized rather than stored as JSON because cumulative restock and
line-attribution limits must be locked, summed, and tested portably. `commerce_refunds` is
registered by `DiagnosticsReport::tenantTables()`; `commerce_refund_lines` is reachable only
through a tenant-scoped refund and is never queried by caller-supplied refund UUID alone.

**Column additions — folded into the original create-table migrations** (pre-release
posture; already-migrated dev/test DBs sync manually):

- `commerce_orders` (004): `refunded_total` bigInteger default 0;
  `refund_revision` bigInteger default 0. Incrementing `refund_revision` is the portable
  affected-row claim that serializes every refund mutation for one order.
- `commerce_order_events` (004): `actor_uuid` string 12 nullable; `visibility` string 16
  default `'internal'` (`internal` | `customer`). Existing writers are untouched — defaults
  keep every current event internal/system.

## 4. Refund Semantics

`RefundService::issue(context, orderUuid, RefundInput, idempotencyKey)` is the public write
path. The controller requires a non-empty `Idempotency-Key` header (maximum 128 characters).
The unique `(tenant_uuid, idempotency_key)` constraint is the backstop. Reuse with the same
canonical request fingerprint returns the existing logical refund; reuse with a different
fingerprint returns `409 Conflict`.

An optimistic idempotency lookup happens before opening a transaction. Every new issue then
claims the order by incrementing `refund_revision`, re-probes the idempotency key under that
claim, and only then validates order state and capacity. This ordering lets a concurrent replay
return a completed full refund after the order has become `refunded`, while different keys are
serialized before they can reserve capacity. The unique constraint remains the final race
backstop: a loser reloads and compares the persisted fingerprint rather than generating a
second logical refund.

Every repository lookup resolves the current tenant first and constrains both tenant and
resource identity. Cross-tenant UUIDs return the same non-revealing 404 as unknown resources.

### 4.1 Manual path (no external side effect)

One database transaction:

1. Claim the tenant-scoped order with an affected-row-checked
   `UPDATE commerce_orders SET refund_revision = refund_revision + 1`; this obtains the row
   lock without a nonexistent query-builder `lockForUpdate()`. Re-read the order and
   idempotency key under the claim. For a new refund, accept only `paid` or `fulfilled`; the
   current payment model marks those states only after the full `grand_total` is paid.
2. Validate `amount > 0` and `amount <= grand_total - refunded_total - SUM(pending refund
   amounts)`. Omitted amount is computed under this lock as the full remaining refundable
   amount. Currency must match the order.
3. Validate optional line attribution against immutable order lines. Attributed amounts may
   sum to at most the refund amount. For restock, lines are required and the cumulative
   quantity reserved by all non-failed restock refunds plus the proposed quantity must not
   exceed the original order-line quantity.
4. Insert the completed manual refund + normalized lines, atomically increment
   `orders.refunded_total`, and optionally restock. Restocking uses an affected-row-checked
   stock operation and records one `refund_restock` movement per variant using the refund UUID
   as its reference. Any database/restock failure rolls back refund, totals, stock, and ledger
   together.
5. If the new total equals `grand_total`, transition through the existing state machine
   (`paid → refunded` / `fulfilled → refunded`). Partial refunds leave order status untouched.
6. Append an internal `refund.completed` audit event containing amount, method, and operator
   reason. Customer-visible projections contain amount/date/method only; the operator reason
   is never exposed or emailed. Dispatch `RefundCompleted` only after the outermost commit.

### 4.2 Gateway path (reserve → call → finalize)

Network I/O never runs inside `Connection::transaction()` because the framework retries
deadlocked callbacks and because an order lock must not be held during a gateway call.

1. **Reserve transaction:** claim the order by incrementing `refund_revision`, re-read
   idempotency + capacity under the claim, insert a `pending` gateway refund + normalized lines
   using the stable HTTP idempotency key, then commit. Pending amount and restock quantities
   reserve capacity against concurrent refunds. Two different keys can never both validate
   against the same pre-reservation snapshot.
2. **External call:** outside every database transaction, call `RefundCollector::refund()`
   with the persisted refund UUID as the collector idempotency key. A replay uses the same UUID.
3. **Finalize transaction:** read the tenant-scoped refund to discover its order, claim the
   order first via `refund_revision`, then affected-row-claim the still-pending refund and
   re-read both. This order-first discipline is used by every refund mutation. `completed`
   increments totals, performs restock, records the events, and dispatches after commit;
   `failed` stores the reason and releases the pending reservation; `pending` remains reserved.
   A result already applied is a no-op.
4. A thrown infrastructure error is an unknown outcome: keep the refund `pending`, record the
   last failure reason, and return a retryable `503`. Replaying the same HTTP idempotency key
   safely asks the collector for the same logical result.
5. `RefundService::settle(context, refundUuid, RefundResult)` reuses the finalize transaction
   for future webhook/polling adapters. It is tenant-scoped and idempotent: transitions apply
   only from `pending`, while an already-terminal refund returns its existing result unchanged.

If gateway money moved but database finalization/restocking fails, the transaction rolls back
and the refund remains pending. The same idempotency key or `settle()` retries finalization;
there is never a partially restocked completed refund.

Commerce validates the collector result status at its boundary. An unknown status is treated
as an infrastructure error: no terminal transition occurs and the refund remains recoverable
as `pending`.

**Derived status rule:** nothing sets `refunded` except the locked completion transaction. The
existing admin `mark-refunded` route is **removed** (extension is unreleased) — its replacement
is a full-amount manual refund via the new endpoint.

## 5. Order Notes

A note is an order event of type `note`:

- `POST /commerce/admin/orders/{uuid}/notes` — DTO: `{body: string (1..4000),
  visibility: 'internal'|'customer', notify: bool}` → appends event with `actor_uuid` =
  authenticated operator, given visibility. `notify: true` (only valid with `customer`)
  dispatches `OrderNoteAdded` after commit; its listener triggers the note email.
- Admin order detail returns all events (now incl. actor/visibility); storefront order
  lookup returns only `visibility='customer'` events of type `note`. Completed refunds are a
  separate sanitized projection from `commerce_refunds`; existing internal event types never
  leak.
- No edit/delete: notes are audit records. (Woo parity: Woo allows delete; we deliberately
  don't — importer imports Woo notes as-is.)

## 6. Transactional Email

**Port:**

```php
namespace Glueful\Extensions\Commerce\Mail;

interface CommerceMailer
{
    /** Never throws out of listeners; failures are logged + diagnostics-visible. */
    public function send(ApplicationContext $context, string $template, array $order, array $payload = []): void;
}
```

**Default binding — `NotificationCommerceMailer`:** copies the Users extension's proven
soft posture: resolve `NotificationDispatcher` from the container when present, address a
lightweight `Notifiable` wrapping `orders.email`, send through the `email` channel. No
dispatcher/channel → log once per boot + `DiagnosticsReport` line `email: inactive`; sends
become no-ops. Apps may rebind `CommerceMailer` to bypass notifications entirely.

**Triggers — event listeners registered by the provider** (never inline in services):

| event | template | default |
|---|---|---|
| `OrderPlaced` | `order_placed` | on |
| `OrderPaid` | `order_paid` | on |
| `OrderFulfilled` | `order_fulfilled` (includes tracking_ref) | on |
| `RefundCompleted` | `order_refunded` (amount, partial/full) | on |
| `OrderNoteAdded` with `notify=true` | `order_note` | n/a (explicit) |

- Config: the master `commerce.email.enabled` switch defaults **off**; per-template switches
  default on. Operators opt in after configuring an email channel and reviewing seller/template
  data. This preserves existing-install behavior even when an email channel is already present.
- Every listener wraps `send()` in try/catch-log. An email failure can never fail the persisted
  order operation. Delivery may add request latency when the selected notification channel is
  synchronous; a queued channel is the supported non-blocking deployment posture.
- `OrderPlaced` and `OrderPaid` keep their existing dispatch points. The currently-declared but
  un-emitted `OrderFulfilled` is dispatched after fulfillment persists. All newly transactional
  events (`RefundCompleted`, `RefundFailed`, `OrderNoteAdded`) dispatch after the outermost
  commit.
- Templates ship as simple text/HTML message builders in the extension (subject + body from
  order data), overridable by rebinding `CommerceMailer` or per-template config. No
  templating engine dependency.

## 7. Invoice Data

`GET /commerce/admin/orders/{uuid}/invoice-data` → stable JSON contract, versioned from v1:

```
{ schema_version: 1,
  seller: {name, address, tax_id},
  buyer:  {email, addresses},              // from the order
  order:  {number, dates, currency, status},
  lines:  [{name, sku, quantity, unit_minor, subtotal_minor}],
  totals: {subtotal_minor, discount_minor, shipping_minor, tax_minor,
           grand_minor, refunded_minor},
  refunds: [{date, amount_minor, method}] }
```

All money fields are integer minor units in `order.currency`. PDF rendering stays an app
concern.

Seller identity is read through a Commerce-local `SellerIdentityProvider` port:

```php
interface SellerIdentityProvider
{
    /** @return array{name:?string,address:?string,tax_id:?string} */
    public function forTenant(ApplicationContext $context, string $tenantUuid): array;
}
```

The default `ConfigSellerIdentityProvider` reads new null-tolerant `commerce.seller.*` config.
This keeps Layer 1 free of settings infrastructure while allowing tenant-aware applications to
rebind seller identity without changing the invoice contract.

## 8. HTTP Surface Summary

| method + path | authorization | DTO |
|---|---|---|
| `POST /commerce/admin/orders/{uuid}/refunds` | `require_scope:commerce:write` | `Idempotency-Key` + `CreateRefundData {amount?, reason?, lines?, restock}` — omitted amount = remaining refundable |
| `GET /commerce/admin/orders/{uuid}/refunds` | `require_scope:commerce:read` | — |
| `POST /commerce/admin/orders/{uuid}/notes` | `require_scope:commerce:write` | `CreateOrderNoteData {body, visibility, notify}` |
| `GET /commerce/admin/orders/{uuid}/invoice-data` | `require_scope:commerce:read` | — |
| ~~`POST .../mark-refunded`~~ | — | **removed**, replaced by refunds endpoint |

Commerce keeps its existing standalone authorization model (`commerce:read` / `commerce:write`
API scopes). A host application may layer finer Aegis permissions such as
`commerce.refunds.manage`, but that is not a Commerce or Contracts dependency in Layer 1.

Storefront: order lookup response gains `refunds: [{date, amount_minor, method}]` (completed
only) and `notes: [...]` (customer-visible only). Additive fields; existing consumers
unaffected.

## 9. Events

- New: `RefundCompleted {order, refund}`, `RefundFailed {order, refund}`, and
  `OrderNoteAdded {order, note}` (BaseEvent, dispatched via the existing container-checked
  `EventService` pattern after commit).
- `RefundFailed` is internal/operator-facing and never emails the customer. A gateway failure
  does not prove that a customer-visible refund occurred.

## 10. Testing

- **Unit:** refund validation math (caps, currency, partial accumulation incl. pending
  holds), HTTP idempotency replay vs conflicting payload, derived-status rule, cumulative
  per-line restock limits, `RefundResult` handling (completed/pending/failed), mailer
  no-channel degrade, seller-provider config fallback.
- **Integration:** refund endpoint happy/partial/over-amount/wrong-state paths; restock
  ledger movement and rollback; gateway collector runs outside a transaction; deadlock/replay
  does not call a collector with a new key; pending replay + `settle()` recovery; notes and
  operator refund reasons do not leak through storefront responses; invoice-data shape;
  `OrderFulfilled` and note events actually reach the mail listener; events do not dispatch on
  rollback; migrations cover both new tables + folded columns.
- **Concurrency:** two different 60-unit gateway reservations racing against a 100-unit order
  result in exactly one reservation; same-key races return one logical refund; concurrent
  settle/replay calls apply totals and stock once.
- **Tenancy:** two tenants may use the same order/refund-shaped identifiers without visibility;
  cross-tenant refund/list/invoice/note access returns 404; the tenant registry includes
  `commerce_refunds` and adoption/diagnostics cover it.
- **Regression gate:** with no refunds/notes issued and no email channel installed, the
  pre-existing suite passes byte-identical. With an email channel installed but
  `commerce.email.enabled=false`, no new mail is sent.

## 11. Sequencing (implementation order)

1. Contracts repo: `RefundCollector` + `RefundRequest`/`RefundResult` (+ unit docs).
2. Migrations: fold 004 columns, add 006 refund + refund-lines tables, update tenant-table
   registration; sync dev/test DBs manually.
3. `RefundService` reserve/call/finalize saga + repository + idempotency + state-machine and
   stock integration (+ unit tests).
4. Refund + notes + invoice-data endpoints, DTOs, scope wiring, route removal.
5. Storefront response additions + visibility filtering.
6. `CommerceMailer` port, notification binding, listeners, templates, config, diagnostics.
7. Integration suite + regression gate + docs (update comparison doc's gap list).

## 12. Review Decisions

1. Seller identity is a tenant-aware provider seam with a config-backed default; no settings
   table in Layer 1.
2. `RefundFailed` never emails the customer; it remains an internal event until a refund is
   confirmed completed.
3. `mark-refunded` is removed with no compatibility shim because the extension is unreleased.
4. Notes remain append-only audit records.
