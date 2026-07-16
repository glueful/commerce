# Layer 4 — Shipping & Tax as Data Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Layer 4 per `docs/superpowers/specs/2026-07-15-layer4-shipping-tax-design.md` (revision 3): DB-backed shipping zones/methods/classes + tax rate tables, delegating provider chains, and the `LineTaxCalculator` optional contract.

**Architecture:** Five new tables + two folded columns; Woo-pinned zone matching (position-ordered first-match, conjunctive postcode narrowing); thin delegators rebind the existing commerce-local contracts (app-bound > DB-when-rows > config, byte-identical with no rows); `DelegatingTaxCalculator` implements BOTH tax contracts so the detailed path is reachable through the default binding; largest-remainder scope-aware discount allocation feeds per-line class-based rates.

**Tech Stack:** commerce only (framework symlink as-is); SQLite harness + pgsql lane; house claim/DTO/controller patterns from Layers 1–3.

## Global Constraints

- Repo: commerce only, branch dev, commits at group boundaries, no attribution, never stage docs/superpowers/** or .superpowers/**.
- FOLD POSTURE: tables in `009_CreateCommerceShippingTaxTables.php`; `variants.shipping_class_uuid` + `products.tax_class` fold into 001.
- Contracts: `ShippingRateProvider`/`TaxCalculator` UNCHANGED; new `src/Contracts/LineTaxCalculator.php` exactly per spec §5 (taxable_amount = post-discount EXTENDED totals — calculators must NOT re-multiply by quantity).
- Zone matching (spec §3 verbatim): position-then-uuid order; first match wins; geographic group (country|state alternatives) must match; postcode rows CONJUNCTIVELY narrow (US + 90* must NOT match US/10001); postcode-only zone rejected at write; zero-location zone = everywhere; zone lists expose derived `shadows_later_zones`.
- Method pricing (spec §2): flat {amount}; free_over uses ALL-line subtotal (mixed-cart parity with the config provider); per_class_table sums one contribution per distinct class among PHYSICAL lines + ONE no-class default bucket.
- Method selection: after the first zone matches, quote ONLY `enabled=true` methods ordered `position ASC, uuid ASC`; no enabled methods → `[]`, with NO later-zone or config fall-through.
- Tax (spec §5 verbatim): checkout dispatches instanceof LineTaxCalculator with `$preTax->shippingTotal` (EFFECTIVE post-discount shipping); allocation = largest remainder over scope-ELIGIBLE lines only, ties by ascending `line_uuid`, integer-exact, free_shipping allocates nothing, floor 0; rate selection = address-matched + class-matched, priority ASC then uuid, first wins; open-vocabulary classes (`[a-z][a-z0-9_-]{0,15}`) with no matching rate tax at 0; shipping taxed by first matching standard-class shipping_taxable rate; house half-up rounding with range-checked multiply; label = sole applied rate's label regardless of class, else 'Tax'; rate_bps write-validated 0..10000.
- Delegators (spec §4): `DelegatingShippingRateProvider` (zone-exists → Db else config); `DelegatingTaxCalculator` implements BOTH contracts — aggregate delegates rate-exists → Db else flat; quoteDetailed delegates rate-exists → Db, else reconstructs `sum(taxable_amount) + effectiveShipping` and calls FlatRate::quote (legacy byte-parity). FlatRateTaxCalculator itself does NOT implement the optional contract.
- Serialization: zone mutations claim zone `revision` + post-claim re-read (delete cascades locations+methods in the txn); class/rate mutations claim own revisions; **variant shipping-class assignment**: one txn → resolve variant→product → claim catalog_revision → claim sorted union of current+proposed CLASS rows → re-read → validate in-tenant → write. Class DELETE claims the class, post-claim re-checks references, REFUSES 409 while referenced.
- pricedLines additive keys: `line_uuid`, `shipping_class` (slug|null), `tax_class` — nothing else changes; addons/hash untouched.
- Product/variant surfaces end-to-end: Create/Update Product/Variant DTOs + services + repositories + storefront/admin JSON projections carry the two new nullable fields; DTO omission never silently discards.
- Shipping-class slug is immutable after create (name-only PATCH; slug mutation → 422). Variant/admin projections preserve BOTH `shipping_class_uuid` (editing identity) and resolved `shipping_class` slug (pricing/display); explicit null clears the UUID.
- Tax geography validation: ISO alpha-2 country; state is `COUNTRY:REGION` with prefix equal to country; postcode is normalized exact or one trailing `*` only.
- Regression: tenants without zones/rates byte-identical (existing checkout tests untouched); FlatRate path unchanged incl. shipping-in-base.
- Quality gates per group: `composer test`, `composer phpcs`, `composer run analyze`.

---

## GROUP A — Schema

### Task 1: Migration 009 + folds + shape test
**Files:** create `migrations/009_CreateCommerceShippingTaxTables.php` (five tables per spec §2 verbatim, revision columns on zones/classes/tax_rates); modify `migrations/001_CreateCommerceCatalogTables.php` (two nullable folds); `src/Support/DiagnosticsReport.php` (+5 commerceTables; zones/classes/tax_rates → tenantTables); `tests/Support/CommerceTestCase.php`, `tests/Integration/MigrationsTest.php`, tenancy exact-list test; create `tests/Integration/Migrations/ShippingTaxShapeTest.php`.
- [ ] TDD shape test RED (tables/uniques incl. (tenant,name) zones, (zone,kind,value) locations, (tenant,slug) classes; folded nullable columns; defaults) → implement → GREEN. Full suite green; NO commit.

## GROUP B — Admin CRUD + field wiring

### Task 2: Zones, locations, methods CRUD
**Files:** create `src/Shipping/{ShippingZoneRepository,ShippingZoneService}.php`, `src/Http/Admin/AdminShippingZoneController.php`, DTOs (CreateZoneData, UpdateZoneData, SetZoneLocationsData, CreateMethodData, UpdateMethodData); routes/provider.
- Binding: zone claim + post-claim re-read on every mutation; delete cascades in txn; location set-list validates kinds/formats + postcode-needs-country against the POSTED set; method config validated per kind (non-negative ints; unknown class slugs warn-but-allow — response carries `warnings`); zone list projection computes `shadows_later_zones`.
- [ ] TDD (CRUD matrix, validation matrix, cascade, claim-404s, shadowing warning) → implement → GREEN. NO commit.

### Task 3: Classes + tax rates CRUD + product/variant field wiring — **GROUP B COMMIT**
**Files:** create `src/Shipping/ShippingClassRepository.php`, `src/Tax/TaxRateRepository.php`, `src/Http/Admin/{AdminShippingClassController,AdminTaxRateController}.php`, DTOs; modify Create/Update Product + Variant DTOs, CatalogService, ProductRepository/VariantRepository, admin+storefront projections (additive `tax_class`, `shipping_class_uuid`, and resolved `shipping_class`), routes/provider.
- Binding: class/rate revision claims; class slug immutable after create (PATCH name only, slug change 422); class delete 409-while-referenced with post-claim re-check; variant assignment = the §6 shared-claim protocol (catalog_revision + sorted class-row union); tax_class open-vocabulary normalization at write; variant read/write preserves UUID plus resolved slug and supports explicit-null clear; rate_bps 0..10000 DTO + domain validation; country/state prefix consistency + exact/trailing-wildcard-only postcode validation; rate list filterable country/class.
- [ ] TDD (incl. deterministic class-delete-vs-assign claim test; slug-mutation rejection; DTO omission preserves fields; explicit-null clears; create/update/read projections preserve `shipping_class_uuid` + resolved `shipping_class`; country/state mismatch and malformed postcode matrix) → implement → GREEN. Full suite green.
- [ ] **COMMIT (Group B):** `feat(shipping): zones, methods, classes, and tax rate tables with admin CRUD`

## GROUP C — Providers

### Task 4: Zone matcher + DbShippingRateProvider + delegator
**Files:** create `src/Shipping/{ZoneMatcher,DbShippingRateProvider,DelegatingShippingRateProvider}.php`; modify `src/Cart/CartService.php` (pricedLines +`line_uuid`, +`shipping_class` via variant join), provider binding swap.
- Binding: matcher per spec §3 (unit-test the conjunctive rule explicitly with US+90* vs US/10001); method pricing per §2 (free_over ALL-line subtotal; per_class distinct-class + one no-class bucket); matched-zone methods filter `enabled=true` and order position+uuid; zero enabled returns [] with no fall-through; quotes use method uuid ids; digital-only → [] before zone work; delegation existence query; app-rebind wins.
- [ ] TDD (matcher matrix; pricing matrix incl. disabled omission, deterministic method order, and matched-zone/no-enabled-method no-fall-through; delegation precedence incl. no-rows config byte-parity; checkout e2e quote with zones) → implement → GREEN. NO commit.

### Task 5: LineTaxCalculator + allocation + DbTaxCalculator + delegators — **GROUP C COMMIT**
**Files:** create `src/Contracts/LineTaxCalculator.php`, `src/Tax/{DiscountAllocation,DbTaxCalculator,DelegatingTaxCalculator}.php`; modify `src/Orders/CheckoutService.php` (instanceof dispatch, effective shipping, detailed-input build), CartService (pricedLines +`tax_class`), provider binding swap.
- Binding: everything in Global Constraints' tax block; DelegatingTaxCalculator's no-rows quoteDetailed reconstructs the legacy base exactly; DbTaxCalculator aggregate path = opaque standard base (documented, checkout never uses it via the delegator); checked-multiply overflow → domain exception.
- [ ] TDD: DiscountAllocation unit matrix (largest remainder incl. rounding orphans + line_uuid ties + scope eligibility + free_shipping + floor); rate-selection matrix (class/priority/state/postcode/no-rate-0/open-vocab); shipping-tax rule; label rule; overflow; checkout e2e (mixed classes, shipping_taxable on/off, discount + tax interaction, free_shipping effective-shipping proof); byte-parity regressions (flat path, no-rows detailed path). Full suite green.
- [ ] **COMMIT (Group C):** `feat(pricing): db-backed shipping quotes and line-level tax with delegating providers`

## GROUP D — Gates

### Task 6: Tenancy/races/regression/docs — **GROUP D COMMIT**
- [ ] Two-tenant sweep (zones/classes/rates reuse + isolation; quotes never cross tenants; children via in-tenant zones); tenantTables exact-list +3.
- [ ] Races: deterministic + pgsql-gated two-connection for BOTH pairs (zone-delete-vs-method-create; class-delete-vs-variant-assign) — house lane, run live where local Postgres available.
- [ ] Regression audit (pre-existing files, mechanical-only) + comparison-doc update (shipping zones/classes + tax tables → "can migrate"; prune gaps).
- [ ] Full suite + gates. **COMMIT (Group D):** `feat(commerce): layer 4 tenancy hardening + docs`

## Self-Review Notes
- Spec rev-3 → tasks: schema+folds (T1); zone/method CRUD + shadowing warning (T2); classes/rates CRUD + immutable slug + shared-claim assignment + UUID/slug field wiring + geographic validation (T3); matcher/provider/delegator + enabled deterministic method selection + shipping_class key (T4); tax contract/allocation/calculators/dispatch + tax_class + line_uuid keys (T5); gates (T6). Pinned decisions 1–3 encoded in T4 (method uuid ids), T4 (no-class bucket), T2 (shadow warning).
- Verify-at-implementation: variant→shipping-class join shape in pricedLines (single query, no N+1 — batch like L2's coversForProducts if needed); the existence-query cost per quote (add covering index if the shape test shows a gap). Admin "forms" is resolved terminology in this extension: it means DTOs plus admin JSON request/response projections; there is no HTML surface.
