# Layer 5 — Reports & Analytics APIs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Layer 5 per `docs/superpowers/specs/2026-07-15-layer5-reports-design.md` (revision 3): four read-only admin report endpoints (sales, products, customers, stock) over existing tables, with three folded range indexes and one config key.

**Architecture:** Raw-SQL query objects in `src/Reports/` (the `CustomerAggregationRepository` posture); SQL groups only by UTC day via `DateBucketSql`; pure-PHP `ReportRollup` folds ISO weeks/months for additive sales figures; customer counts use bounded DB-side distinct aggregates against PHP-generated bucket boundaries; windowed order scans go through two indexable `report_at` UNION ALL branches, never `WHERE COALESCE(...)`.

**Tech Stack:** commerce only (no framework/extension-contracts changes); SQLite harness + standing pgsql lane; house DTO/controller/route patterns.

## Global Constraints

- Repo: commerce only, branch dev, commits at group boundaries, no attribution/trailers, never stage docs/superpowers/** or .superpowers/**.
- READ-ONLY LAYER: no new write paths; no behavior change to checkout/refund/stock; existing suite stays byte-identical (only permitted existing-file edits: two migration index folds, `KEY_EXPR` `private`→`public`, provider/routes/config registration, CHANGELOG, comparison doc).
- FOLD POSTURE: `commerce_orders` +INDEX `(tenant_uuid, placed_at)` and +INDEX `(tenant_uuid, created_at)` fold into 004; `commerce_refunds` +INDEX `(tenant_uuid, completed_at)` folds into 006. No new migration file. Dev DBs sync manually (controller's problem, not the task's).
- Revenue statuses EXACTLY `('paid','fulfilled','refunded')`; `pending_payment`/`canceled` are excluded from revenue/AOV/revenue-order-count/product/customer metrics. Sales alone carries a separate window-scoped `pending_orders` visibility count, which is not revenue.
- Report time = the two-branch derived table (spec §2.11), each branch projecting `report_at`:
  branch 1 `placed_at IS NOT NULL AND placed_at >= ? AND placed_at < ?` projects `placed_at AS report_at`; branch 2 `placed_at IS NULL AND created_at >= ? AND created_at < ?` projects `created_at AS report_at`. NEVER `WHERE COALESCE(...)`.
- Refunds: `status = 'completed'`, bucketed/windowed on `completed_at` (verified written by both manual `buildRow()` and gateway `claimPending()` paths). `orders.refunded_total` used NOWHERE.
- Half-open windows: `from` 00:00:00 inclusive → `toExclusiveSql()` (to+1 day 00:00:00) exclusive; all bounds UTC strings bound as params; no interpolated dates; single-quoted string literals in raw SQL (SQLite); every query starts `tenant_uuid = ?`.
- `net_minor = gross_minor − refunds_minor`; AOV = `intdiv(2*gross + n, 2*n)`, `0` when `n = 0`; integer minor units, no floats in money fields.
- Bucket keys: `Y-m-d` day, `2026-W25` ISO week (Monday start, `o` year), `Y-m` month; series zero-filled for every bucket in the window; NO database week/month function anywhere.
- Customer counts are NOT additive: repository returns ≤ requested_bucket_count + 1 rows (day: one `COUNT(DISTINCT CASE...)` query grouped by day expression; week/month: bound boundary rows from driver-aware `ReportBoundarySql` joined with `UNION ALL`, LEFT JOIN + DB-side distinct classification); timestamp placeholders are plain on SQLite, `CAST(? AS DATETIME)` on MySQL, `CAST(? AS timestamp)` on PostgreSQL; independent window summary query; `ReportRollup` never touches customer counts.
- Customer identity: `CustomerAggregationRepository::KEY_EXPR` verbatim (promote constant to `public`, mechanical); "new" = key's all-time first revenue-status order in bucket/window.
- Products: independent sales/refund `UNION ALL` activity branches outer-grouped by `variant_uuid` (count query counts the same outer result); refund branch joins `commerce_refund_lines` → `commerce_refunds` (tenant, completed, `completed_at` window) → `commerce_order_lines` through BOTH `order_line_uuid` AND the refund's `order_uuid`; refund fields named `attributed_refunded_minor`/`attributed_refunded_quantity` (line-attributed only; line-less refunds excluded from product attribution); sort `revenue`|`quantity` DESC, ties `variant_uuid ASC`; snapshots via `MAX(product_name)`/`MAX(sku)`.
- Stock: `tracked = 1 AND quantity <= threshold`; `out_of_stock` = `quantity <= 0`, `low_stock` = `0 < quantity <= threshold`; JOIN variants `status = 'active'` (no `deleted_at` on variants) JOIN products `deleted_at IS NULL` (draft/inactive products remain visible); order `quantity ASC, variant_uuid ASC`; threshold default `config('commerce.reports.low_stock_threshold')` (config default 2, env `COMMERCE_REPORTS_LOW_STOCK_THRESHOLD`), `?threshold=` override validated 0..100000; configured default validated to the SAME range and invalid config fails clearly (never clamp); response echoes effective threshold.
- Window DTO: `from`/`to` `date:Y-m-d`, defaults today−29d/today; `group in:day,week,month` default `day`; `from <= to`, span ≤ 366 days else 422 via `ValidationException::forField`; future `to` allowed. `ReportWindow::fromDates(...)` is canonical, `fromQuery(...)` delegates, products call `fromDates(..., 'day')`; null/supplied `$today` is created/normalized in UTC before deriving defaults.
- Routes: `GET` under the existing admin group (`/commerce/admin`), path prefix `reports/`, per-route `->middleware($read)` (`require_scope:commerce:read`). Controller: plain `final class`, `ApplicationContext` first ctor param, tenant via `CurrentTenantResolver` with `SentinelTenantResolver` fallback, `#[ApiOperation]`/`#[ApiResponse]` annotations.
- Pagination (products/stock only): house offset, `per_page` clamp `max(1, min(100, ?? 24))`, `Response::paginated` flat envelope; sales/customers unpaginated `Response::success`.
- ServiceProvider registration with `use` imports (never inline FQCNs in services()/factories).
- Quality gates per group: `composer test`, `composer phpcs`, `composer run analyze`.

---

## GROUP A — Foundations

### Task 1: Index folds + shared date/window/rollup units + DTOs
**Files:** modify `migrations/004_CreateCommerceOrderTables.php` (+2 indexes), `migrations/006_CreateCommerceRefundTables.php` (+1 index); create `src/Support/{DateBucketSql,ReportBoundarySql}.php`, `src/Reports/{ReportWindow,ReportRollup,StockThreshold,ReportConfigurationException}.php`, `src/Http/DTOs/{ReportWindowQuery,ProductsReportQuery,StockReportQuery}.php`; create `tests/Unit/Reports/{ReportWindowTest,ReportRollupTest,StockThresholdTest}.php`, `tests/Unit/Support/{DateBucketSqlTest,ReportBoundarySqlTest}.php`, `tests/Integration/Migrations/ReportIndexShapeTest.php`.

**Interfaces (later tasks consume these exactly):**
- `DateBucketSql::dayExpression(string $driver, string $column): string` — sqlite `strftime('%Y-%m-%d', col)` / mysql `DATE_FORMAT(col, '%Y-%m-%d')` / pgsql `to_char(col, 'YYYY-MM-DD')`; `InvalidArgumentException` on unknown driver; final class, private ctor (mirror `UtcNowSql`).
- `ReportWindow::fromDates(?string $from, ?string $to, string $group = 'day', ?\DateTimeImmutable $today = null): self` is canonical; `fromQuery(ReportWindowQuery $q, ?\DateTimeImmutable $today = null): self` delegates. Null `$today` is constructed in UTC; supplied `$today` is converted to UTC first. Methods `fromSql(): string` (`Y-m-d 00:00:00`), `toExclusiveSql(): string` (to+1d `00:00:00`), `days(): list<string>`, `group(): string`, `bucketBounds(): list<array{bucket: string, from: string, to: string}>` (end-exclusive UTC strings; ≤ 54 week / ≤ 13 month entries), `fromDate(): string`, `toDate(): string`.
- `ReportBoundarySql::rowExpression(string $driver): string` — sqlite `SELECT ? AS bucket, ? AS from_at, ? AS to_at`; mysql uses `CAST(? AS DATETIME)` for both bounds; pgsql uses `CAST(? AS timestamp)`; unknown driver throws.
- `ReportRollup::fold(array $dayRows, ReportWindow $window): list<array{bucket: string, ...}>` — input `Y-m-d` ⇒ additive int map; zero-fills every bucket; ISO-week (`o-\WW`) and month keys; used ONLY for additive sales figures.
- `StockThreshold::resolve(?int $override, mixed $configured): int` — override/config both constrained `0..100000`; invalid config throws `ReportConfigurationException`, invalid override remains DTO 422.
- DTO fields per spec §3 (all `#[FromQuery]` + `#[Rule]`, existing `*Query.php` style).

- [ ] TDD: RED unit matrix (ISO W53/W01 year boundaries, month folding, zero-fill; window defaults/366-cap/from>to/future-to, `fromQuery`/`fromDates` equivalence, non-UTC process/supplied timezone → UTC; both SQL helpers' exact three-driver expressions + throws; StockThreshold boundaries + invalid config; AOV helper if placed here) + RED shape test (three indexes exist, by exact name/columns) → implement → GREEN. Full suite green. NO commit.

## GROUP B — Sales

### Task 2: SalesReportRepository + controller + routes — **GROUP B COMMIT**
**Files:** create `src/Reports/SalesReportRepository.php`, `src/Http/Admin/AdminReportController.php` (sales action only); modify `src/CommerceServiceProvider.php` (register controller+repo, use imports), `routes.php` (`GET reports/sales`), `config/commerce.php` (+`reports` block); create `tests/Integration/Http/SalesReportEndpointTest.php`.

**Interfaces:**
- `SalesReportRepository::salesByDay(ApplicationContext $c, string $tenant, ReportWindow $w): array{orders: array<string, array{gross_minor: int, orders_count: int}>, refunds: array<string, int>, sums: array{discount_minor: int, shipping_minor: int, tax_minor: int}, pending_orders: int}` — orders/refunds keyed by `Y-m-d`; orders aggregate day-groups the two-branch derived table; refunds aggregate day-groups completed refunds on `completed_at`; `sums` + `pending_orders` window-scoped scalars (same queries or one extra scalar query each — no `(days × anything)` blowup).
- Controller merges via `ReportRollup`, computes per-bucket + summary `net`/`aov` in PHP, responds per spec §4.1 (`currency` from `config('commerce.currency')`).

- [ ] TDD: RED endpoint tests (hand-computed integers across multiple days/statuses; exclusions; May-order/June-refund in June's bucket; zero-fill; week+month rollups; summary: additive fields sum, AOV recomputed not summed, `net = gross − refunds`; legacy `placed_at IS NULL` order counted via created_at branch) → implement → GREEN. Full suite + gates green.
- [ ] **COMMIT (Group B):** `feat(reports): sales report with date-window foundations and folded range indexes`

## GROUP C — Products, customers, stock

### Task 3: ProductSalesReportRepository + products action
**Files:** create `src/Reports/ProductSalesReportRepository.php`; modify `AdminReportController` (+products), `routes.php` (`GET reports/products`); create `tests/Integration/Http/ProductsReportEndpointTest.php`.

**Interfaces:** controller constructs the window via `ReportWindow::fromDates($query->from, $query->to, 'day')`; repository `paginate(ApplicationContext $c, string $tenant, ReportWindow $w, string $sort, int $page, int $perPage): array{items: list<array{variant_uuid: string, sku: string, product_name: string, quantity: int, revenue_minor: int, attributed_refunded_minor: int, attributed_refunded_quantity: int}>, total: int}` — UNION ALL branches per Global Constraints; count query wraps the same outer grouping.

- [ ] TDD: RED (both sorts + tie-break across pages; refund-only variant appears in refund month; line-less refund absent from attribution; snapshot name after product rename; window edges) → implement → GREEN. NO commit.

### Task 4: CustomerReportRepository + customers action
**Files:** create `src/Reports/CustomerReportRepository.php`; modify `AdminReportController` (+customers), `routes.php` (`GET reports/customers`), `src/Customers/CustomerAggregationRepository.php` (`KEY_EXPR` → `public`, mechanical); create `tests/Integration/Http/CustomersReportEndpointTest.php`.

**Interfaces:** `bucketCounts(ApplicationContext $c, string $tenant, ReportWindow $w): array{series: array<string, array{new_customers: int, returning_customers: int}>, summary: array{new_customers: int, returning_customers: int, total_customers: int}}` — series keyed by final bucket key; day mode = one day-grouped `COUNT(DISTINCT CASE ...)` query (PHP zero-fills); week/month = `ReportBoundarySql` rows joined by `UNION ALL` + LEFT JOIN with DB-side distinct classification; summary = independent window-wide aggregate; every aggregate uses the two-branch report-time derivation + all-time first-order join; repository result rows ≤ buckets + 1.

- [ ] TDD: RED (new-in-exactly-one-bucket; guest email vs user_uuid keys; case/trim normalization; pre-window first order ⇒ returning; two orders same week counted once, never new+returning; summary independent, ≠ series sum in a crafted fixture; row-count bound under high-customer fixture; zero-filled empty buckets in all three groupings) → implement → GREEN. NO commit.

### Task 5: StockReportRepository + stock action — **GROUP C COMMIT**
**Files:** create `src/Reports/StockReportRepository.php`; modify `AdminReportController` (+stock), `routes.php` (`GET reports/stock`); create `tests/Integration/Http/StockReportEndpointTest.php`.

**Interfaces:** `paginate(ApplicationContext $c, string $tenant, int $threshold, ?string $status, int $page, int $perPage): array{items: list<array{variant_uuid: string, sku: string, product_name: string, quantity: int, status: string}>, total: int}`; controller resolves the effective threshold through `StockThreshold` (validated override or validated config default), echoes it per item, and responds per spec §4.4.

- [ ] TDD: RED (threshold boundaries 0/exact/+1; untracked invisible; inactive variant excluded; trashed product excluded, draft product visible; `?status=` filter; `?threshold=` override incl. 422 out-of-range; invalid configured threshold fails with named config error; ordering) → implement → GREEN. Full suite + gates green.
- [ ] **COMMIT (Group C):** `feat(reports): product, customer, and stock reports`

## GROUP D — Gates

### Task 6: Cross-cutting gates + docs — **GROUP D COMMIT**
**Files:** create `tests/Integration/Http/ReportTenancyTest.php`; modify `docs/woocommerce-migration-comparison.md`, `CHANGELOG.md` ([Unreleased]).

- [ ] Two-tenant sweep: disjoint results on all four endpoints (seeded both tenants + sentinel `''`); scope enforcement (`commerce:read` required, write-token rejected) per endpoint. Empty-result contracts: sales/customers zero-filled all-zero series; products empty paginated `data`; stock point-in-time with no qualifying rows (no window assertion).
- [ ] pgsql lane: full suite live against local Postgres (`COMMERCE_TEST_DB_DRIVER=pgsql`) — report actual output; date-expression and boundary-table SQL must pass identically.
- [ ] Regression audit: `git diff` over the layer — existing-file changes limited to the permitted list (Global Constraints); pre-existing tests untouched.
- [ ] Comparison doc: reports/analytics gap → "can migrate" (API-only, no Woo report-data import — reports compute from migrated orders); prune the gap line. CHANGELOG covers Layer 5.
- [ ] Full suite + gates. **COMMIT (Group D):** `feat(commerce): layer 5 report gates + docs`

## Self-Review Notes
- Spec rev-3 → tasks: folds+UTC window factories+typed boundary SQL+threshold validation+DTOs (T1); sales+config+controller scaffold (T2); products UNION ALL + explicit `fromDates` construction (T3); customers bounded typed-boundary aggregates + KEY_EXPR promotion (T4); stock with shared threshold validation (T5); tenancy/pgsql/docs + endpoint-specific empty contracts (T6). All pinned decisions encoded in Global Constraints.
- Verify-at-implementation: exact refund `completed_at` writer claims (spec says verified — re-confirm `buildRow()`/`claimPending()` before relying); whether `executeRaw`/`executeRawFirst` support the UNION ALL + derived-table shapes on all drivers (CustomerAggregationRepository proves the mechanism); `Response::paginated` message strings follow neighbors.
- Bind-parameter budget: week boundaries ≤ 54 × 3 params + scoping — far below SQLite's 999 floor; noted so no task "optimizes" it away.
