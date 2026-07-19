# Commerce Multi-Vendor Marketplace - Overview

**Date:** 2026-07-16  
**Status:** discussion overview (held, uncommitted)  
**Scope:** a future optional marketplace layer for `glueful/commerce`  
**Companion:** `2026-07-14-woocommerce-parity-overview-design.md`

This document preserves the working model for future multi-vendor discussions. It is an
architecture overview, not an approved implementation specification or task plan. Each
delivery slice requires its own detailed design and review before implementation.

## 1. Terminology and Product Boundary

Commerce currently supports **multi-workspace commerce**, not a shared-checkout
multi-vendor marketplace.

| Model | Meaning | Current support |
|---|---|---|
| Single store | One workspace operates one storefront and merchant catalog. | Yes |
| Multi-workspace / mall | Each workspace is an isolated store with its own host, catalog, cart, orders, and operators. Customers shop each store separately. | Yes |
| Multi-vendor marketplace | Multiple independent sellers operate inside one workspace and one storefront. A customer can buy from several sellers in one cart and payment. | No |

The key distinction is cardinality:

> A workspace is the store and security boundary. A seller is a business participant inside
> that workspace. Sellers must not be represented as tenants if one customer order may contain
> products from several sellers.

Treating each tenant as a vendor produces the mall model. It cannot provide a shared cart,
shared checkout, parent order, commission split, or consolidated customer payment because
Commerce correctly prevents cross-tenant reads and writes.

## 2. Goal and Non-Goals

### Goal

Add an optional marketplace layer that lets one Commerce workspace:

- onboard and manage multiple sellers;
- assign products, variants, inventory, downloads, and fulfillment responsibility to a seller;
- accept a single customer checkout containing products from multiple sellers;
- split the resulting operational order by seller;
- calculate immutable commissions and seller proceeds;
- maintain an auditable seller balance ledger;
- execute, retry, reverse, and reconcile payouts;
- authorize seller users without exposing another seller's data; and
- preserve the existing single-store and multi-workspace behavior when marketplace mode is off.

### Non-goals for the first marketplace release

- Replacing workspace tenancy. Sellers always remain subordinate to a workspace.
- Building KYC, identity verification, or bank-account vaulting in Commerce. Payment providers
  own regulated onboarding; Commerce stores opaque provider references and status only.
- Multi-currency orders. Commerce currently pins one currency per workspace.
- A general accounting system. The marketplace ledger records Commerce obligations and payout
  movements; it is not a full double-entry general ledger for the seller's business.
- Cross-workspace carts or orders.
- Silent compatibility with marketplace plugins from other platforms. Import requires an
  explicit seller/commission/payout mapping report.

## 3. Existing Foundation

The marketplace layer should reuse these as-built Commerce primitives.

### 3.1 Strong reusable primitives

1. **Tenant isolation.** Commerce roots use `tenant_uuid`, tenant-scoped business keys, and a
   fail-closed tenant resolver when tenancy is enabled. The workspace remains the outer
   security and data-isolation boundary.
2. **Catalog and inventory.** Products own variants; variants own stock rows and append-only
   stock movements. Seller ownership can be added at the product root and inherited by its
   variants and stock.
3. **Transactional checkout.** Cart conversion, line validation, stock decrement, discounts,
   order creation, and order-line snapshots execute as one transactional flow.
4. **Immutable purchase snapshots.** Order lines already preserve product name, SKU, options,
   add-ons, price, quantity, totals, and digital-download definitions. Seller and commission
   identity can be added to this snapshot rather than read from mutable catalog data later.
5. **Integer money.** Prices, totals, discounts, refunds, shipping, and tax use minor units.
6. **Order lifecycle.** Checked status transitions, append-only order events, fulfillment,
   cancellation, expiry, partial/full refunds, and stock restoration already exist. The
   affected-row-checked payment transition prevents two concurrent callers from both marking an
   order paid, but event dispatch still occurs after that transition and is not a durable
   exactly-once financial hook.
7. **Payment and refund seams.** `PaymentCollector` collects the customer-facing order payment;
   `RefundCollector` handles outward refunds. These remain useful, but neither is a seller
   settlement or payout contract.
8. **Event spine.** `OrderPlaced`, `OrderPaid`, `OrderFulfilled`, `RefundCompleted`, and related
   events provide integration hooks. Financial correctness must still be persisted in the
   initiating transaction rather than delegated solely to best-effort listeners.
9. **Reports and customer aggregation.** Existing tenant-scoped query objects provide a base
   for marketplace-wide reporting; seller dimensions can be introduced explicitly.
10. **Digital delivery and media.** Entitlements are snapshotted per order line and blobs are
    referenced through framework policies. Seller authorization can be added without changing
    how files are stored.
11. **Seller presentation seam.** `SellerIdentityProvider` can provide tenant-aware invoice
    identity. It is an invoice presentation seam, not seller ownership or settlement.

### 3.2 Existing primitives that are insufficient by themselves

- `tenant_uuid` says which workspace owns a row, not which seller inside that workspace does.
- The current seller config describes one invoice issuer for the workspace.
- `PaymentCollector` receives one payable for the full order and has no recipient, commission,
  transfer, connected-account, or payout semantics.
- Admin routes use workspace authorization and broad `commerce:read` / `commerce:write` API-key
  scopes. They do not enforce seller membership or row ownership.
- Shipping and tax currently quote the order as one store transaction. Marketplace liability
  and seller-specific origins may require seller-grouped quoting.
- One order status and one fulfillment state cannot represent independent seller fulfillment.
- A guarded order transition and a subsequently dispatched `OrderPaid` event are not atomic. A
  process failure between them can leave a paid order without downstream effects, so seller-ledger
  posting needs a transactionally coupled claim, an outbox, or an idempotent reconciliation path.

## 4. Missing Marketplace Domains

True multi-vendor support requires all of the following. Adding only `seller_uuid` to products
would create attribution, not a safe marketplace.

1. **Seller identity and lifecycle** - seller profiles, status, onboarding state, suspension,
   payout readiness, and historical retention.
2. **Seller membership and authorization** - which users may administer a seller and which
   capabilities they hold.
3. **Catalog ownership** - exactly one authoritative seller per purchasable product, inherited
   by variants, inventory, downloads, and related catalog resources.
4. **Order partitioning** - one customer order with immutable seller-attributed lines and one
   operational seller order per participating seller.
5. **Commission policy** - deterministic calculation, precedence, rounding, and immutable
   snapshots at checkout.
6. **Settlement ledger** - append-only seller credits, commissions, refunds, adjustments,
   reserves, payout debits, reversals, and reconciliation entries.
7. **Payout execution** - provider-neutral payout contract, idempotent saga, retries, failure
   states, external references, and reconciliation.
8. **Seller fulfillment and refunds** - seller-scoped operational actions without allowing one
   seller to mutate another seller's lines or the parent payment arbitrarily.
9. **Marketplace reporting** - platform-wide and seller-scoped sales, refunds, fees, balances,
   payouts, stock, and tax/shipping views.
10. **Administrative surfaces** - platform marketplace administration plus seller dashboards.

## 5. Proposed Aggregate Model

### 5.1 Ownership hierarchy

```text
Workspace / tenant
  Marketplace configuration
  Sellers
    Seller memberships
    Products
      Variants
        Stock

Customer order
  Order lines (seller identity + commission snapshot)
  Seller orders (one per participating seller)
    Seller fulfillment/refund responsibility
  Customer payment
  Settlement ledger entries
  Payouts
```

The parent customer order remains the source of truth for what the customer bought and paid.
Seller orders are operational and financial partitions, not duplicate customer orders.

### 5.2 Candidate data model

Names are provisional and must be finalized in a detailed spec.

#### `commerce_sellers`

- `uuid`, `tenant_uuid`
- `slug`, `name`, legal/display metadata
- `status`: `onboarding | active | suspended | closed`
- commission-policy reference or default commission basis points
- payout-provider and opaque external account reference
- payout readiness/status and timestamps
- tenant-scoped unique `(tenant_uuid, slug)`

No bank details, identity documents, or provider secrets belong in this table.

#### `commerce_seller_memberships`

- `seller_uuid`, `user_uuid`
- role/capability assignment and `status`
- unique `(seller_uuid, user_uuid)`

Membership is seller-local. The initial authorization model should keep seller users in the
ordinary authenticated user population: an active, least-privilege workspace membership admits
the user through the tenant boundary, while the seller membership grants seller-specific powers.
Whether this is the required v1 identity model remains an explicit MV0 decision. Workspace
operators retain an explicit audited bypass path.

#### Catalog ownership

- Add `seller_uuid` to `commerce_products`.
- Variants, stock, downloads, media, add-ons, and catalog taxonomy assignments inherit the
  seller through the product and must be reached through that root.
- A seller transfer is a guarded business operation, not an unrestricted product patch.
- Existing products require an explicit adoption/default-seller migration strategy.

#### Order snapshots

Add immutable seller attribution to `commerce_order_lines`:

- `seller_uuid`
- seller display/legal snapshot needed for the order record
- commission policy/version and calculated commission amount
- seller gross, allocated discount, shipping, tax, platform fee, and seller net components

Historical order meaning must not change when a seller is renamed, suspended, transferred, or
deleted.

#### `commerce_seller_orders`

One row per `(order_uuid, seller_uuid)` containing:

- seller-specific operational status and fulfillment state;
- seller-attributed subtotal, discounts, shipping, tax, refunds, commission, and net;
- shipping method/origin snapshot when seller-specific shipping applies;
- seller-visible order number/reference; and
- independent tracking and fulfillment metadata.

A seller-visible reference does not require a separate per-seller sequence. A deterministic
composite of the parent order number and seller-partition reference is sufficient unless product
requirements later demand seller-local numbering.

The parent order controls customer payment state. Seller orders control seller fulfillment and
settlement eligibility.

#### Commission policies

V1 should begin with a small, explicit precedence model, for example:

1. product override;
2. seller override;
3. workspace marketplace default.

The selected rule and calculated values are snapshotted onto order lines. Later edits never
rewrite existing orders or balances.

#### `commerce_marketplace_ledger`

An append-only financial source of truth:

- `uuid`, `tenant_uuid`, `seller_uuid`, `currency`
- entry type from a closed vocabulary: `sale_credit`, `commission_debit`, `refund_debit`,
  `commission_reversal`, `adjustment`, `reserve_hold`, `reserve_release`, `payout_debit`, or
  `payout_reversal`
- signed amount in minor units
- order, seller-order, refund, payout, and source references where applicable
- stable idempotency key and timestamps

Balances are derived from ledger entries or maintained as checked rollups with the ledger as the
audit source. Never store only a mutable `seller.balance` number.

#### Payouts

`commerce_payouts` and normalized payout items should record:

- seller, amount, currency, status, provider, external reference;
- idempotency key, retry metadata, failure code, and timestamps;
- the exact eligible ledger entries included; and
- reversal/reconciliation state.

Payout provider I/O must remain outside retryable database transactions. Use a persisted
reserve/claim -> external call -> idempotent finalize saga, matching Commerce refund patterns.

## 6. Core Flows

### 6.1 Seller onboarding

1. A workspace operator creates or invites a seller.
2. Seller users receive explicit seller memberships.
3. Regulated payout onboarding occurs through an external provider.
4. Commerce stores only the provider reference and readiness result.
5. Product publication and payout eligibility are separately gated; a seller may be allowed to
   prepare a catalog before payouts are ready, but checkout policy must be explicit.

### 6.2 Catalog creation

1. Resolve the workspace and authenticated seller authority.
2. Determine the target seller from trusted server authorization, never from an unchecked body
   field.
3. Create product, variants, and stock transactionally with seller ownership at the product root.
4. All subsequent reads/writes constrain both workspace and authorized seller unless the caller
   holds an audited marketplace-wide permission.

### 6.3 Shared checkout

1. Cart lines continue to reference variants.
2. Checkout resolves each live product's seller after claiming the cart.
3. Validate seller status, payout/checkout eligibility, product availability, shipping, tax,
   commission policy, and stock.
4. Group lines by seller for seller-specific calculations.
5. Build one customer total and assert exact integer reconciliation with every seller group.
6. In one transaction, decrement stock, create the parent order, snapshot seller-attributed
   lines, create seller orders, persist commission snapshots, and convert the cart.
7. Initiate one customer payment for the parent order.

Required reconciliation invariant:

```text
customer grand total
= sum(seller-attributed merchandise, shipping, and tax components)
   minus order-level allocations

for each seller:
seller gross - commission - seller-borne adjustments = seller net
```

Largest-remainder allocation with stable line UUID tie-breaking should be reused wherever an
order-level discount, shipping amount, tax amount, or refund must be distributed exactly.

### 6.4 Payment and settlement

Customer collection and seller settlement are separate concerns:

- `PaymentCollector` continues to collect the full parent-order amount.
- On authoritative payment confirmation, Commerce transactionally creates idempotent seller
  sale/commission ledger entries.
- A new payout/transfer port handles movement to sellers. Do not widen `PaymentCollector` into a
  marketplace-specific contract.
- Provider integrations may implement destination charges, separate transfers, or delayed
  payouts, but Commerce's ledger semantics remain provider-neutral.
- A payment event listener may trigger work, but `OrderPaid` is not the financial guarantee: the
  current event dispatch follows the guarded status transition and a crash can occur between the
  two. The payment transition and durable ledger claim must either commit atomically, use a
  transactional outbox, or be backed by an idempotent reconciliation process that finds paid
  orders missing their required ledger entries. Repeated delivery must remain harmless.

### 6.5 Fulfillment

- Sellers see only their seller order and attributed lines.
- A seller may fulfill or add tracking only for its seller order.
- Parent fulfillment is derived from seller-order states, for example fulfilled only when all
  required seller orders are fulfilled.
- Cancellation before payment may cancel all seller orders atomically.
- Partial seller cancellation after payment requires a refund/adjustment path, not a status-only
  mutation.

### 6.6 Refunds and disputes

- Customer refunds remain parent-order operations backed by normalized refund lines. Current
  single-store Commerce permits line-less refunds and permits attributed line amounts to total
  less than the refund; marketplace mode cannot leave that remainder financially unattributed.
- Refund lines determine seller attribution from immutable order-line snapshots. A full-remaining
  refund may expand deterministically across refundable seller lines. A partial refund requires
  explicit line allocations, and any amount not charged to seller lines must be explicitly
  classified as marketplace-funded. Seller allocations plus marketplace-funded amount must equal
  the refund exactly in minor units.
- Completion writes seller refund debits and the configured commission reversal atomically with
  Commerce refund completion.
- Seller users may request or initiate refunds only for their lines and within workspace policy;
  the platform retains final authority over the customer-facing payment refund.
- Provider fees, non-refundable commissions, reserves, chargebacks, and negative seller balances
  require explicit policies. They must never be inferred after the fact from mutable config.

## 7. Authorization Model

Marketplace authorization adds a seller axis inside the existing workspace axis:

```text
allowed = workspace resolved
       AND workspace membership/bypass accepted
       AND seller target resolved from the resource
       AND (seller membership capability OR audited marketplace-wide capability)
```

Candidate seller capabilities:

- `commerce.seller.catalog.read|write`
- `commerce.seller.inventory.read|write`
- `commerce.seller.orders.read|fulfill`
- `commerce.seller.refunds.request`
- `commerce.seller.reports.read`
- `commerce.seller.payouts.read`
- `commerce.seller.members.manage`

The exact role matrix is a later decision. A likely initial role family is seller owner, seller
admin, fulfillment staff, and analyst. Commerce should expose a seller-role authority seam and
remain decoupled from Aegis and Thallo's workspace-role implementation. V1 may use a fixed
code-defined family; per-seller capability deltas or custom roles can layer on later if justified.

Security requirements:

- Never authorize a caller-supplied `seller_uuid` independently of the target resource.
- Seller-scoped list queries must include both `tenant_uuid` and authorized `seller_uuid`.
- Platform/workspace bypass must be explicit and audited.
- API keys need optional seller binding or seller-specific scopes before seller automation is
  exposed.
- Unknown, suspended, or closed sellers fail closed for new mutations.

## 8. Shipping, Tax, Discounts, and Inventory

### Shipping

The current workspace quote may remain valid for centrally fulfilled marketplaces. If sellers
ship independently, the quote must group lines by seller/origin and aggregate selected methods
without losing each seller's allocation. One customer shipment selection may therefore become
one selection per seller group.

### Tax

The merchant-of-record decision determines whether tax is calculated and reported by the
marketplace or individual sellers. The existing line-tax seam is reusable, but seller liability,
origin, registration, and invoice presentation require explicit inputs and snapshots.

### Discounts

Every discount must state who funds it: marketplace, seller, or shared. Order-level discounts
must be allocated exactly across seller lines. Seller-funded discounts must not reduce another
seller's proceeds.

### Inventory

Stock remains variant-based. Seller ownership is inherited from the product. Cross-seller stock
pooling is out of scope unless modeled later as a separate fulfillment/inventory-owner concept.

## 9. Reporting and Administrative Surfaces

Two distinct views are required:

### Marketplace operator

- sellers and onboarding state;
- marketplace sales and refunds;
- commissions, liabilities, reserves, negative balances, and payout failures;
- seller-order fulfillment exceptions;
- reconciliation and audit tools; and
- seller suspension and emergency access.

### Seller

- own products, inventory, media, and downloads;
- own seller orders, line-level refunds, notes, fulfillment, and tracking;
- own sales, fees, net earnings, balance, and payouts;
- own team memberships and roles; and
- no visibility into another seller's customers, lines, revenue, or payout details.

Customer order views remain consolidated while clearly showing seller attribution, shipment
groups, refund status, and contact/support boundaries.

Every seller field added to a storefront order projection must be included through that
projection's explicit allowlist. Seller attribution must never appear accidentally through a raw
row spread, and private legal, onboarding, payout, or membership fields remain excluded.

## 10. Invariants

Detailed specs must preserve at least these invariants:

1. Every seller belongs to exactly one workspace.
2. Every purchasable product belongs to exactly one active seller when marketplace mode is on.
3. Seller identity and commission values on an order are immutable snapshots.
4. One seller can never read or mutate another seller's catalog, order partition, ledger, or
   payout through an ordinary seller path.
5. The sum of seller/order allocations equals the parent-order amount exactly in integer minor
   units.
6. Ledger idempotency prevents duplicate credits, debits, refunds, or payout reservations.
7. A payout cannot include an ineligible, already-paid, reserved, disputed, or wrong-currency
   ledger entry.
8. External payment/payout I/O never runs inside a retryable database transaction.
9. Refund completion and its seller ledger consequences commit atomically.
10. Seller suspension stops new sales immediately without destroying historical orders,
    balances, or payout evidence.
11. Marketplace mode off preserves current Commerce behavior and API responses.
12. Tenant isolation remains authoritative outside every seller-level rule.
13. Every refunded minor unit is attributed to seller lines or explicitly funded by the
    marketplace; no settlement remainder is implicit.
14. A paid order cannot remain permanently without its required seller-ledger postings; atomic
    posting, an outbox, or reconciliation must close the payment/event crash window.
15. Seller authorization composes with, and never bypasses, the workspace tenant boundary.

## 11. Suggested Delivery Slices

### MV0 - Decision and contract ledger

Pin merchant of record, commission precedence, tax/shipping liability, payout timing, reserves,
refund attribution/funding, seller-user identity, seller lifecycle, and gateway capabilities. No
implementation.

### MV1 - Seller identity, membership, and catalog ownership

Seller tables, lifecycle, seller authorization, product adoption, seller-scoped catalog and
inventory APIs, diagnostics, and admin surfaces. No shared checkout or money movement yet.

### MV2 - Shared checkout and seller orders

Seller snapshots, seller groups, seller-order aggregate, exact allocation, grouped shipping/tax,
customer projections, and fulfillment derivation. Payment remains one parent-order collection.

### MV3 - Commission and settlement ledger

Commission policies, immutable checkout snapshots, paid/refund ledger posting, payment-to-ledger
crash recovery, balances, adjustments, reconciliation diagnostics, and seller financial reports.
Payouts may remain manual and Commerce-local.

### MV4 - Payouts and provider integration

Provider-neutral payout port, external-account readiness, reserve/execute/finalize saga, retries,
reversals, scheduled payout batches, provider reconciliation, and operator tooling. A reusable
provider port belongs in `glueful/extension-contracts`; the first Payvia implementation creates
the release chain `extension-contracts -> payvia -> commerce`. Internal ledger semantics and
manual payouts do not depend on that release chain.

### MV5 - Marketplace hardening

Disputes/chargebacks, negative balances, reserve policies, seller suspension edge cases,
seller-specific API keys/webhooks, import tooling, PostgreSQL concurrency lanes, and full
cross-tenant/cross-seller security tests.

Each slice should be independently gated and disabled by default until its invariants are fully
enforced. Do not expose seller assignment before seller-scoped authorization exists.

## 12. Decisions Required Before a Detailed Spec

1. **Merchant of record:** marketplace/workspace or each seller?
2. **Payment topology:** one marketplace charge with later transfers, destination charges, or a
   provider-selectable model?
3. **Commission basis:** merchandise only, or also shipping/tax; percentage, fixed, tiered, or
   category/product overrides?
4. **Discount funding:** marketplace-funded, seller-funded, or explicit per discount?
5. **Refund policy:** who may approve, which fees/commissions reverse, how negative balances are
   handled, and whether full refunds auto-expand across lines.
6. **Refund attribution and funding:** require explicit seller-line attribution for partial
   refunds, permit marketplace-funded remainders, or define another exact allocation policy?
7. **Seller-user identity:** must every seller user hold a least-privilege workspace membership in
   addition to seller membership, or does the host provide a separate seller-principal path?
8. **Payout timing:** immediate, scheduled, fulfillment-based, delivery-based, or hold-window?
9. **Reserves:** whether new/high-risk sellers retain a configurable reserve.
10. **Shipping:** centrally fulfilled versus seller-origin shipping and one method per seller.
11. **Tax liability:** marketplace-level versus seller-level registrations and reporting.
12. **Seller onboarding:** invite-only versus self-service, moderation, and payout-provider KYC.
13. **Catalog moderation:** seller publishes directly or workspace approval required?
14. **Seller roles:** fixed initial roles behind a role-authority seam versus per-seller custom
    roles or capability deltas?
15. **Existing catalog adoption:** create one default seller, require manual assignment, or keep
    marketplace mode blocked until every active product is assigned?
16. **Seller closure:** transfer products, archive them, or block closure while obligations remain?
17. **Gateway scope:** which payment provider must prove connected accounts, transfers, refunds,
    and payout reconciliation for the first release?

## 13. Acceptance Story for the End State

In one workspace, seller A and seller B each manage only their own catalog. A customer adds one
item from each seller to one cart and completes one payment. Commerce creates one customer order,
two immutable seller order partitions, exact commission/net ledger entries, and independent
fulfillment responsibilities. Each seller sees only its order and balance. A partial refund of
seller A's line reverses only seller A's proceeds and the configured commission while seller B's
order, fulfillment, and payout remain unchanged. The workspace operator can reconcile the parent
payment, both seller balances, and any payouts from an append-only audit trail.
