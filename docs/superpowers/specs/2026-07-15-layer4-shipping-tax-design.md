# Layer 4 — Shipping Zones & Tax Rate Tables as Data

**Status:** revision 3; ready for implementation
**Parent:** `2026-07-14-woocommerce-parity-overview-design.md` (Layer 4)
**Repos touched:** `glueful/commerce` only. The existing commerce-local contracts
(`ShippingRateProvider`, `TaxCalculator` in `src/Contracts/`) do NOT change; one NEW optional
commerce-local contract is added. No framework or extension-contracts work.
**Posture:** fold rules — five new tables in migration `009`; two nullable columns fold into
001 (`variants.shipping_class_uuid`, `products.tax_class`).

## 1. Scope

In: DB-backed shipping zones/locations/methods/classes and tax rate tables with full admin
CRUD; a delegating provider chain (app-bound > DB-when-rows-exist > config fallback); the
`LineTaxCalculator` optional contract closing the aggregate-signature gap (per-line tax
classes, `shipping_taxable`, discount allocation); storefront behavior via the EXISTING
checkout quote/place flow only.

Out: settings UI (API + import only, per overview); tax-inclusive pricing; compound/stacked
tax rates (priority selects ONE rate per class in v1); shipping carriers/live rates;
per-customer tax exemptions.

## 2. Data Model — migration `009_CreateCommerceShippingTaxTables.php`

**`commerce_shipping_zones`** — uuid / tenant_uuid('') / name(255) / position int 0 /
`revision` int 0 / timestamps; unique (tenant_uuid, name).

**`commerce_shipping_zone_locations`** — child of zone: zone_uuid / kind(16) / value(64);
unique (zone_uuid, kind, value); index zone_uuid.
- `kind = country`: value `US` (ISO-3166 alpha-2, uppercased).
- `kind = state`: value `US:CA` (country:region composite — matches only when the address
  carries both).
- `kind = postcode_pattern`: value exact (`90210`) or prefix-wildcard (`90*`); matched against
  the address postcode after trimming/uppercasing. Pattern matching requires the zone to also
  match on country via a sibling location (a bare postcode pattern with no country location in
  the zone is rejected at write time — postcodes are only meaningful within a country). A
  sibling country scopes the postcode; it is NOT an independent OR-match that makes the
  postcode optional (the conjunctive matching rule is pinned in §3).

**`commerce_shipping_methods`** — child of zone: uuid / zone_uuid / kind(24) / label(255) /
config json / position int 0 / enabled bool true / timestamps; index zone_uuid.
- `kind = flat`: config `{amount}` (minor units).
- `kind = free_over`: config `{amount, free_over}` — amount, or 0 when the subtotal of ALL cart
  lines ≥ free_over. This deliberately preserves `ConfigShippingRateProvider`'s current
  mixed-cart behavior, where digital lines also count toward the threshold; changing the DB
  path to physical-only would violate the no-rows/config-fallback compatibility contract.
- `kind = per_class_table`: config `{default_amount, classes: {slug: amount}}` — the quote sums
  one contribution per DISTINCT shipping class present among the physical lines: the class's
  configured amount, else `default_amount`; lines with no class contribute one `default_amount`
  bucket (the "no class" bucket, counted once). Config validated at write time (non-negative
  integers, known class slugs warned but allowed — classes may be created later).

**`commerce_shipping_classes`** — uuid / tenant_uuid / slug(64) / name(255) / `revision` int 0 /
timestamps; unique (tenant_uuid, slug).

The shipping-class `slug` is an immutable pricing identity after creation; PATCH may change
`name` only. `per_class_table` method configuration is keyed by slug, while variants retain the
class UUID, so permitting a slug rename without rewriting every method configuration would
silently change live shipping charges. V1 rejects slug changes with 422 rather than attempting
a cross-zone configuration migration.

**`commerce_tax_rates`** — uuid / tenant_uuid / country(2) / state(64, null — `US:CA` composite
like zone locations) / postcode_pattern(32, null) / rate_bps int / label(255) / priority int 0 /
shipping_taxable bool false / class(16, default 'standard') / `revision` int 0 / timestamps;
indexes tenant_uuid, (tenant_uuid, country).

`rate_bps` is write-validated in the inclusive range `0..10000` (0%..100%). Calculators use a
checked integer multiply before half-up division and fail loudly on overflow; wrapped or
negative tax is never returned.

**Folded columns (001):**
- `commerce_variants.shipping_class_uuid` (12, nullable).
- `commerce_products.tax_class` (16, nullable — null means `standard`).

Tenancy: zones, classes, tax_rates are tenant-bearing → `DiagnosticsReport::tenantTables()`
(registry + adopter). zone_locations and methods are children reachable only through a
tenant-scoped zone (the established child-table rule).

## 3. Zone Matching Semantics (pinned)

- Zones are evaluated in `position` order (ties broken by uuid for determinism). The FIRST
  matching zone supplies its methods; later zones are never consulted
  (Woo semantics — no fall-through).
- For a non-empty zone, the address must first match at least one geographic location: a
  `country` equal to the address country or a `state` equal to its country:region. Country and
  state rows are alternatives within that geographic group.
- If the zone contains ANY `postcode_pattern` rows, they narrow that geographic match: the
  address must additionally match at least one postcode pattern, and at least one sibling
  `country` row must match the address country. Therefore a zone containing `country=US` and
  `postcode_pattern=90*` does NOT match `US/10001`; the country row scopes the pattern rather
  than bypassing it. A postcode-only zone is invalid at write time.
- A zone with ZERO locations is "everywhere" — it matches any address. Positioning one first
  shadows all later zones. Everywhere zones are allowed at any position so an operator can
  deliberately use one as a catch-all/kill-switch, but zone-list projections expose a derived
  `shadows_later_zones` warning whenever one precedes another zone.
- No matching zone → the DB provider returns [] (checkout's existing "no shipping options"
  handling applies). The config fallback is NOT consulted per-quote once the tenant has any
  zone rows — the tenant has opted into data-driven shipping wholesale (mixing sources per
  request would be unpredictable).
- A matched zone returns ONLY methods with `enabled = true`, ordered by `position ASC, uuid ASC`.
  If it has no enabled methods, the result is `[]`; the provider does not fall through to a
  later zone or the config provider.

## 4. Provider Chain

`CommerceServiceProvider` rebinds the two contract defaults to thin delegators:

- `DelegatingShippingRateProvider(DbShippingRateProvider, ConfigShippingRateProvider)` —
  Db when the current tenant has ≥1 shipping zone, else config. (One existence query per
  quote; index-covered.)
- `DelegatingTaxCalculator(DbTaxCalculator, FlatRateTaxCalculator)` implements BOTH
  `TaxCalculator` and `LineTaxCalculator`. Its aggregate `quote()` delegates to Db when the
  tenant has ≥1 tax rate, else to flat-rate config. Its detailed `quoteDetailed()` delegates
  to Db when rows exist; with no rows it reconstructs the exact legacy aggregate base as
  `sum(taxableLines.taxable_amount) + effectiveShippingAmount` and calls
  `FlatRateTaxCalculator::quote()`. This is required because checkout sees the delegator, not
  its Db child; implementing the optional contract only on `DbTaxCalculator` would leave the
  data-driven path unreachable.

An application that rebinds `ShippingRateProvider::class`/`TaxCalculator::class` replaces the
whole chain (app-bound > DB > config — unchanged from the overview). Checkout applies the same
optional-contract dispatch to an app-bound calculator: `LineTaxCalculator` gets detailed input;
a legacy `TaxCalculator` gets the existing aggregate call byte-identically. Both default
delegators and both Db implementations are `shared` services; digital-only carts still return
[] before any zone work (rule preserved from the config provider).

## 5. Tax: the `LineTaxCalculator` Optional Contract

**Verified gap:** checkout calls `tax->quote(context, $preTax->grandTotal, $address)` — the
base always includes shipping and carries no line detail. Rate tables need per-line classes
and per-rate `shipping_taxable`.

**New commerce-local contract (src/Contracts/):**

```php
interface LineTaxCalculator
{
    /**
     * @param list<array{taxable_amount:int, tax_class:string, quantity:int}> $taxableLines
     *        post-discount EXTENDED line totals (already multiplied by quantity)
     */
    public function quoteDetailed(
        ApplicationContext $context,
        array $taxableLines,
        int $shippingAmount,
        array $shippingAddress
    ): TaxQuote;
}
```

- **Checkout dispatch:** if the bound `TaxCalculator` ALSO implements `LineTaxCalculator`,
  checkout builds the detailed input and calls `quoteDetailed()` with
  `$preTax->shippingTotal`, the EFFECTIVE post-discount shipping amount. It must not pass the
  selected quote's original amount because `free_shipping` reduces only the priced total.
  Otherwise the existing aggregate call runs byte-identically. `FlatRateTaxCalculator` does
  NOT implement the optional contract; the default delegator's detailed no-rows branch adapts
  back to its aggregate API as specified in §4.
- **Discount allocation (pinned):** for percentage/fixed discounts, the order-level discount
  total is allocated proportionally ONLY among lines eligible under the selected discount's
  `product_scope`; ineligible lines receive allocation 0. An absent scope means every line is
  eligible. Allocation uses largest remainder over each eligible line's extended total and is
  integer-exact: allocations sum to exactly the line-applicable discount. Equal remainders are
  resolved by stable `line_uuid` ascending, which `pricedLines()` exposes additively. A
  `free_shipping` discount allocates nothing to lines. Each line's
  `taxable_amount = unit_price * quantity - allocation`, floored at 0; `taxable_amount` is
  already an extended amount and the calculator MUST NOT multiply it by quantity again.
- **Line tax_class:** the product's `tax_class` (null → `standard`); carried into
  `pricedLines()` as an additive `tax_class` key (alongside a new additive `shipping_class`
  slug key for per-class shipping and `line_uuid` for deterministic allocation). Tax classes
  are normalized lowercase slugs matching `[a-z][a-z0-9_-]{0,15}`; they are intentionally an
  open vocabulary, so a syntactically valid class with no matching rate is allowed and taxes
  at 0 rather than acquiring an implicit standard rate.
- **`DbTaxCalculator`** implements BOTH interfaces. Rate selection per line: rates matching
  the address (country, then state/postcode narrowing like zone locations — no sibling-country
  requirement here since every rate row carries its own country) AND the line's class, ordered
  by priority ASC then uuid; the FIRST match applies; no rate → 0 for that class.
  Direct aggregate `quote()` cannot distinguish merchandise from shipping; it treats the
  caller-supplied amount as one opaque `standard` taxable base. Checkout never uses that
  aggregate path for the default DB delegator because the delegator implements the detailed
  contract; the aggregate method remains for direct/backward-compatible callers only.
- **Shipping tax (pinned):** shipping is taxed at the bps of the first matching
  `standard`-class rate whose `shipping_taxable` is true (address-matched, priority-ordered);
  no such rate → shipping untaxed.
- **Rounding (pinned):** per-line half-up rounding (`intdiv(x*bps + 5000, 10000)` — the
  existing house formula), summed; shipping tax rounded the same way. The multiplication is
  range-checked before evaluation. `TaxQuote.label` is the sole applied rate's label when
  exactly one distinct rate applies, regardless of class; with zero or multiple distinct
  rates the label is `Tax`.

## 6. Admin CRUD (routes under `/commerce/admin`, $read/$write, RequestData DTOs)

| Area | Routes |
|---|---|
| Zones | `GET/POST /shipping/zones` · `PATCH/DELETE /shipping/zones/{uuid}` · `PUT /shipping/zones/{uuid}/locations` (set-list) |
| Methods | `GET/POST /shipping/zones/{uuid}/methods` · `PATCH/DELETE /shipping/methods/{uuid}` |
| Classes | `GET/POST /shipping/classes` · `PATCH/DELETE /shipping/classes/{uuid}` |
| Tax rates | `GET/POST /tax/rates` (list filterable by country/class) · `PATCH/DELETE /tax/rates/{uuid}` |

- Serialization: zone mutations (rename/reposition/locations set-list/method create-update-
  delete/zone delete) claim the ZONE `revision` (affected-row-checked) then post-claim re-read;
  zone delete cascades locations + methods in that one transaction. Class and rate mutations
  claim their own row revisions.
- Variant shipping-class assignment/clear is a structural strengthening of the existing
  variant update path: in one transaction resolve variant→product, claim the product's
  `catalog_revision`, then claim the sorted UUID union of the current and proposed shipping
  class rows using affected-row-checked revision claims, post-claim re-read, validate the
  proposed class still exists in-tenant, and write. Class DELETE claims the same class row and
  post-claim re-checks references before deletion. The shared class claim serializes
  assign-vs-delete; a product-only claim would not.
- Class DELETE is REFUSED (409) while any variant references it — explicit detach first
  (mirrors the blob-deletion posture; no silent nulling).
- Shipping-class PATCH rejects any attempt to change `slug` (422); only `name` is mutable.
- Location set-list validation: kinds/value formats as §2; the postcode-needs-country rule
  enforced against the POSTED set as a whole.
- The existing product/variant write and projection surfaces are extended end-to-end:
  `CreateProductData`, `UpdateProductData`, product service/repository/projections and admin
  forms carry nullable `tax_class`; `CreateVariantData`, `UpdateVariantData`,
  `ProductVariantData`, variant service/repository/projections and admin JSON payloads carry
  nullable `shipping_class_uuid`. Read projections preserve that UUID for editing AND expose
  the resolved nullable `shipping_class` slug for pricing/display; one must not replace the
  other. DTO omission must not silently discard either field, and an explicit null clears the
  assignment. Product tax classes use the normalized open-vocabulary rule from §5; variant
  shipping classes must resolve in the current tenant or be null.
- Tax-rate DTOs enforce `rate_bps` `0..10000`; calculator overflow is a domain failure, never
  wrapped arithmetic. `country` is normalized ISO alpha-2; a non-null `state` must use the
  `COUNTRY:REGION` form and its country prefix must equal the row's `country` (`country=US`,
  `state=CA:ON` is rejected). `postcode_pattern` uses the same normalized exact-or-single-
  trailing-wildcard grammar as zone postcodes; embedded/leading/multiple wildcards are rejected.
- Non-revealing 404s; cross-tenant isolation; no audit events beyond standard timestamps
  (operator config, not customer-financial state).

## 7. Testing

- Unit: zone matcher (country/state/postcode incl. wildcard + conjunctive sibling-country
  rule, including `US + 90*` rejecting `US/10001`,
  position precedence + uuid tiebreak, zero-location everywhere-zone, no-match → []); method
  pricing (flat, free_over threshold edges with mixed physical+digital carts preserving the
  config provider's all-line subtotal, per_class_table incl. one no-class bucket and
  missing-slug default, disabled methods omitted, enabled methods ordered position+uuid, and a
  matched zone with no enabled methods returning [] without fall-through); rate selection
  (class match, priority order, state/postcode
  narrowing); discount allocation (largest-remainder exactness incl. rounding orphans and
  deterministic `line_uuid` ties, product-scoped discounts leave ineligible lines untouched,
  free_shipping allocates nothing, extended-amount/no-double-quantity contract, floor at 0);
  shipping-tax rule; sole reduced-rate label; rate bounds and checked-overflow failure.
- Integration: admin CRUD matrix + claims + cascades + class-delete refusal + immutable-class-
  slug rejection + location/tax-geography validation; checkout quote/place end-to-end with DB zones+rates (per-class shipping, mixed
  tax classes, shipping_taxable on/off); delegation precedence (no rows → config behavior
  byte-identical including free_shipping effective shipping; rows → DB detailed path through
  the delegator; app-rebound legacy/detailed contracts → app wins); product/variant create,
  update, explicit-null clear, read and admin JSON payloads preserve `shipping_class_uuid` while
  also exposing the resolved `shipping_class` slug; pricedLines additive keys don't disturb
  existing consumers (addons_hash etc. untouched).
- Tenancy: two-tenant (zone/class/rate name+slug reuse, cross-tenant 404s, quotes never read
  the other tenant's rows); children only via in-tenant zones.
- Concurrency: deterministic zone-delete-vs-method-create and class-delete-vs-variant-assign
  claim tests + pgsql-gated two-connection races for BOTH pairs in the house lane pattern.
- Regression gate: tenants without zones/rates are byte-identical (existing checkout tests
  untouched and green); FlatRateTaxCalculator path unchanged including its
  shipping-in-the-base behavior.

## 8. Sequencing (implementation order)

1. Migration 009 + folded columns + shape test + inventories.
2. Zone/class/rate repositories + admin CRUD (claims, cascades, validation) + complete
   product/variant DTO/service/repository/projection field wiring.
3. Zone matcher + `DbShippingRateProvider` + delegator + pricedLines `shipping_class` key.
4. `LineTaxCalculator` contract + discount allocation + `DbTaxCalculator` + delegator +
   checkout dispatch + pricedLines `tax_class` key.
5. Tenancy/concurrency suites + regression gate + comparison-doc update.

## 9. Pinned Decisions

1. **Method identity on quotes** — DB quotes use the method row's uuid as `ShippingQuote.id`
   (stable for reorders/telemetry); config fallback keeps its config ids. Mixed ids across the
   two sources are valid because a tenant is always wholly on one source.
2. **`per_class_table` no-class bucket** — counted once regardless of how many class-less
   lines exist; Woo's "no class cost" behaves per-order, not per-line.
3. **Everywhere-zone position** — allowed at any position with derived shadowing warnings;
   operators may deliberately place an everywhere-zone first as a catch-all/kill-switch.
