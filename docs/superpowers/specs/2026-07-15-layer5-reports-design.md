# Layer 5 — Reports & Analytics APIs (Detail Design)

**Status:** revision 3 — reviewed; ready for implementation
**Parent:** `2026-07-14-woocommerce-parity-overview-design.md` §Layer 5
**Repo scope:** commerce only. Read-only layer: **no new write paths**, no changes to checkout/refund/stock behavior.

## 1. Scope

Four admin report endpoints computed live from existing tables (`commerce_orders`,
`commerce_order_lines`, `commerce_refunds`, `commerce_refund_lines`, `commerce_stock`),
behind a small raw-SQL query-object layer (`src/Reports/`). Plus: three range indexes
folded into the original migrations, a `reports` config block, and the comparison-doc
update. A materialized daily-rollup table is explicitly **out of scope** (overview: add
only if measured cost demands it).

## 2. Pinned decisions (deviations & semantics — review these first)

1. **Pagination: house offset style, NOT cursor.** The overview said "cursor pagination";
   the codebase has zero cursor infrastructure — every list uses
   `paginatedFor`-style offset + `Response::paginated` (flat envelope keys
   `current_page/per_page/total/...`). Reports adopt the house pattern:
   - `sales` and `customers` return **bounded bucket series** (window cap makes them ≤ ~400
     rows) — no pagination at all, plain `Response::success`.
   - `products` and `stock` are ranked/filterable lists — house offset pagination,
     `per_page` clamped to 100, default 24.
2. **Sales bucket in SQL by day; roll up week/month in PHP.** Cross-driver date functions
   diverge (SQLite `strftime('%W')` is not ISO week; MySQL/PG differ again). The query
   layer groups sales by the **UTC day** (`strftime/DATE_FORMAT/to_char` per driver via a
   `DateBucketSql` helper mirroring `UtcNowSql`), and `ReportRollup` (pure PHP, unit-tested)
   folds those additive money/order totals into ISO-8601 weeks (Monday-start, `oWW` key) or
   calendar months. Customer counts are NOT additive across days: §4.3 uses bounded DB-side
   distinct aggregates for the FINAL requested bucket so each customer is counted once. Day
   uses the same UTC-day expression; week/month use PHP-generated, bound bucket boundaries.
   No database week/month function is used anywhere.
3. **Revenue timestamp semantics = `COALESCE(placed_at, created_at)`.** No `paid_at` exists.
   `placed_at` is written at checkout placement and is the column Layer 7's importer will
   backdate for historical orders (`created_at` would be import time). Every order-based
   report uses that semantic timestamp, implemented as the indexable two-branch `report_at`
   derived table in decision 11 rather than a direct `WHERE COALESCE(...)` predicate.
4. **Revenue statuses:** gross sales count orders with
   `status IN ('paid','fulfilled','refunded')` — i.e. money was actually taken.
   `pending_payment` and `canceled` orders are excluded from revenue, AOV, revenue-order
   count, product ranking and customer metrics. The sales response deliberately carries a
   separate `pending_orders` visibility count (window-scoped `pending_payment` only); that
   field is not a revenue metric.
5. **Refunds bucket by `commerce_refunds.completed_at`, status `completed` only.**
   A June refund against a May order is June's refund (Woo parity). Source verification
   corrected the initial premise: manual refunds set `completed_at` in `buildRow()` and the
   gateway finalize path passes `completed_at` into `claimPending()`. No writer change is
   needed. `pending` refunds are NOT money out yet and are excluded; `failed` always excluded.
6. **Net figures are derived, not summed twice:** `net_minor = gross_minor − refunds_minor`
   where `refunds_minor` comes from the refunds table (decision 5), not from
   `orders.refunded_total` (which would double-shift refund timing back to the order's
   bucket). `orders.refunded_total` is used nowhere in Layer 5.
7. **AOV** = `intdiv(gross_minor + orders_count/2 ... )` — precisely: house half-up integer
   division `intdiv(2*gross + n, 2*n)` per bucket and for the window summary; `0` when
   `orders_count = 0`. Integer minor units everywhere; no floats in money fields.
8. **Currency:** single-currency posture. Responses carry one top-level
   `currency` = `config('commerce.currency')`. Mixed-currency stores are out of scope
   (matches checkout, which prices in the single configured currency).
9. **Customer identity** reuses `CustomerAggregationRepository::KEY_EXPR` verbatim
   (`CASE WHEN user_uuid IS NOT NULL THEN user_uuid ELSE LOWER(TRIM(email)) END`).
   "New in bucket" = the key's **first-ever revenue-status order** (all-time, not
   window-relative) falls in that bucket; "returning" = key ordered in bucket but first
   order predates it. Distinct keys per FINAL requested bucket; a key is classified once per
   bucket (new XOR returning), even if it orders on multiple days in that week/month. The
   window summary independently classifies each distinct in-window key once: new when its
   first-ever order is inside the whole window, returning when it predates the window, so
   `summary.new_customers + summary.returning_customers = summary.total_customers`.
10. **Stock thresholds from config:** `commerce.reports.low_stock_threshold` (int,
    default **2** — Woo's default), env `COMMERCE_REPORTS_LOW_STOCK_THRESHOLD`.
    `out_of_stock` = `tracked = 1 AND quantity <= 0`;
    `low_stock` = `tracked = 1 AND 0 < quantity <= threshold`. Untracked variants never
    appear. Threshold is also overridable per-request (`?threshold=`, validated 0..100000)
    for ad-hoc queries; config is the default. The configured value is validated against the
    same range when resolved; an invalid deployment value fails with a named configuration
    error rather than being silently clamped or producing a misleading report.
11. **Indexes fold into original migrations** (pre-launch fold posture):
    `commerce_orders` + INDEX `(tenant_uuid, placed_at)` in 004;
    `commerce_orders` + INDEX `(tenant_uuid, created_at)` in 004 for the legacy-null fallback;
    `commerce_refunds` + INDEX `(tenant_uuid, completed_at)` in 006. Dev DBs sync manually.
    Windowed order queries do NOT predicate directly on `COALESCE` (which would make the normal
    indexes unusable): they use two `UNION ALL` branches — placed_at non-null/ranged, then
    placed_at null + created_at ranged — each projecting its selected timestamp as `report_at`.
    No new index for stock (existing UNIQUE `(tenant_uuid, variant_uuid)` suffices; the
    stock report filter is on unindexed `quantity`, acceptable at catalog scale).
12. **Raw-SQL query objects** (the `CustomerAggregationRepository` posture): expression
    GROUP BYs break the query builder, so each report repository builds raw SQL with bound
    params via `executeRaw`, single-quoted string literals (SQLite), every query starting
    `tenant_uuid = ?`. No ORM models.

## 3. Shared date-window DTO

`src/Http/DTOs/ReportWindowQuery.php` — `RequestData`, all `#[FromQuery]`:

| Field | Rule | Default | Semantics |
|---|---|---|---|
| `from` | `date:Y-m-d` | today−29d | inclusive, UTC day start `00:00:00` |
| `to` | `date:Y-m-d` | today | inclusive, UTC day end `23:59:59` |
| `group` | `in:day,week,month` | `day` | rollup granularity (sales/customers only) |

Validation (in a `ReportWindow` value object built from the DTO, unit-tested):
`from <= to`; window span ≤ **366 days** → else 422 (`ValidationException::forField`);
`to` may be in the future (clamped nowhere — empty buckets return zeros). Its canonical
factory is `fromDates(?string $from, ?string $to, string $group = 'day',
?DateTimeImmutable $today = null)`; `fromQuery(ReportWindowQuery, $today)` delegates to it,
and `ProductsReportQuery` calls `fromDates(..., 'day')`. A null `$today` is created explicitly
in UTC; a supplied value is converted to UTC before its date is used, so process timezone
never changes defaults. The VO exposes
`fromSql()` / `toExclusiveSql()` (UTC datetime strings for half-open bound ranges), `days()`
(list of `Y-m-d` keys for zero-filling), and `bucketBounds()` (the final day/week/month bucket
key + UTC start/end-exclusive boundaries). All bucket series are **zero-filled**: every bucket
in the window appears, even with no data.

`ProductsReportQuery` embeds the same `from`/`to` fields (no `group`) plus its own
filters (`sort in:quantity,revenue` default `revenue`; `page`/`per_page`);
`StockReportQuery` (`status in:out_of_stock,low_stock` default both via omission;
`threshold numeric`; `page`/`per_page`; no `from`/`to` — stock is point-in-time).

## 4. Endpoints (all `GET`, group `/commerce/admin/reports`, middleware `auth` + `require_scope:commerce:read`)

### 4.1 `GET /commerce/admin/reports/sales`
Response `data`:
```json
{
  "currency": "USD",
  "window": {"from": "2026-06-16", "to": "2026-07-15", "group": "day"},
  "summary": {"gross_minor": 0, "refunds_minor": 0, "net_minor": 0,
               "orders_count": 0, "aov_minor": 0, "pending_orders": 0,
               "discount_minor": 0, "shipping_minor": 0, "tax_minor": 0},
  "series": [{"bucket": "2026-06-16", "gross_minor": 0, "refunds_minor": 0,
               "net_minor": 0, "orders_count": 0, "aov_minor": 0}]
}
```
Two SQL aggregates (orders by day; completed refunds by day), rolled up + zero-filled + merged in
PHP. `summary` is computed from the same day rows (not a third query). Bucket keys:
`Y-m-d` (day), `oYYY-Www` ISO form `2026-W25` (week), `Y-m` (month). `discount_minor`/
`shipping_minor`/`tax_minor` are window sums over revenue-status orders (summary only,
not per bucket).

### 4.2 `GET /commerce/admin/reports/products`
Ranked variants over the window, house pagination (`Response::paginated` flat envelope):
```json
{"success": true, "message": "Product report retrieved",
 "data": [{"variant_uuid": "...", "sku": "...", "product_name": "...",
             "quantity": 0, "revenue_minor": 0, "attributed_refunded_minor": 0,
             "attributed_refunded_quantity": 0}],
 "current_page": 1, "per_page": 24, "total": 1, "total_pages": 1,
 "has_next_page": false, "has_previous_page": false}
```
SQL uses independent activity branches combined by `UNION ALL`, then outer-groups by
`variant_uuid` (the count query counts that same outer result):
- sales branch: `commerce_order_lines` JOIN the two-branch windowed revenue-order derived table,
  grouped by variant with snapshot `MAX(product_name/sku)`, `SUM(quantity/line_total)` and zero
  refund columns;
- refund branch: `commerce_refund_lines` JOIN `commerce_refunds` (`tenant`, completed status,
  `completed_at` window) JOIN `commerce_order_lines` through both `order_line_uuid` AND the
  refund's `order_uuid`, grouped by variant with zero sales columns and summed refund-line
  amount/quantity.

This preserves a June refund for a May order and admits refund-only variants in the June
result. Sort `revenue` = outer `SUM(revenue_minor) DESC`, `quantity` = outer `SUM(quantity)
DESC`; ties break `variant_uuid ASC`. `revenue_minor` is gross line revenue before discounts
and refunds. Refund lines are optional in the as-built refund contract, so the product fields
are deliberately named `attributed_refunded_*`: they include only explicit line allocations;
an order-level refund with no lines cannot honestly be assigned to a product and remains visible
only in the complete sales refund total. `attributed_net_minor = revenue_minor -
attributed_refunded_minor` is derivable client-side; no claim is made that product-attributed
refunds equal the sales report's complete refund total.

### 4.3 `GET /commerce/admin/reports/customers`
Bucket series (same window/group DTO), `Response::success`:
```json
{"window": {...},
 "summary": {"new_customers": 0, "returning_customers": 0, "total_customers": 0},
 "series": [{"bucket": "2026-06-16", "new_customers": 0, "returning_customers": 0}]}
```
The repository returns at most `requested_bucket_count + 1` rows across its bounded aggregates,
never `(days × customers)`:
- for `group=day`, one query groups by `DateBucketSql::dayExpression(report_at)` and performs
  `COUNT(DISTINCT CASE ...)` classification in SQL; PHP zero-fills missing days;
- for week/month, PHP builds the final non-overlapping boundaries from
  `ReportWindow::bucketBounds()` and binds them into a portable derived table
  (`ReportBoundarySql::rowExpression($driver)` joined with `UNION ALL`; no interpolated dates
  and no DB week functions). The helper emits plain bound strings for SQLite,
  `CAST(? AS DATETIME)` for MySQL and `CAST(? AS timestamp)` for PostgreSQL so all-placeholder
  UNION branches cannot resolve timestamp bounds as text. At most 54 week boundaries or 13
  month boundaries keep this below the cross-driver bind-parameter limits;
- a second window-wide aggregate produces the one summary row for every grouping mode.

Each aggregate derives revenue-status window orders through the two indexed report-time
branches from §2.11 and joins each key's all-time first revenue-order timestamp. Day grouping or
the week/month boundary LEFT JOIN then performs DB-side distinct classification into new,
returning and total. Each key is deduplicated before the FINAL bucket count. A customer who is
new in one week and returns in another appears once as new in the independent window summary;
series counts are intentionally non-additive and MUST NOT be summed to derive summary customer
counts. Empty week/month boundaries return zeros through the LEFT JOIN shape; empty days are
zero-filled in PHP.

### 4.4 `GET /commerce/admin/reports/stock`
Point-in-time, house pagination (`Response::paginated` flat envelope):
```json
{"success": true, "message": "Stock report retrieved",
 "data": [{"variant_uuid": "...", "sku": "...", "product_name": "...",
             "quantity": 0, "status": "out_of_stock", "threshold": 2}],
 "current_page": 1, "per_page": 24, "total": 1, "total_pages": 1,
 "has_next_page": false, "has_previous_page": false}
```
SQL: `commerce_stock` (tenant, `tracked = 1`, `quantity <= :threshold`) JOIN
`commerce_variants` (sku, product link, `status = 'active'`; variants have no `deleted_at`)
JOIN `commerce_products` (name, `deleted_at IS NULL`; draft/inactive products remain visible
to stock administrators until trashed). `status` derived per row
(`out_of_stock` when `quantity <= 0`, else `low_stock`); `?status=` filters to one class.
Order `quantity ASC, variant_uuid ASC`. Response echoes the effective `threshold`.

## 5. Components

| Unit | Responsibility |
|---|---|
| `src/Support/DateBucketSql.php` | static `dayExpression(string $driver, string $column): string` — UTC day (`Y-m-d`) per driver (sqlite `strftime('%Y-%m-%d', col)` / mysql `DATE_FORMAT(col, '%Y-%m-%d')` / pgsql `to_char(col, 'YYYY-MM-DD')`). Mirrors `UtcNowSql` (final, private ctor, InvalidArgumentException on unknown driver). |
| `src/Support/ReportBoundarySql.php` | static `rowExpression(string $driver): string` — a three-placeholder derived-table SELECT with driver-correct timestamp casts (SQLite plain, MySQL DATETIME, PostgreSQL timestamp); throws on unknown driver. |
| `src/Reports/ReportWindow.php` | VO from `ReportWindowQuery`: validation (≤366d, from≤to), `fromSql/toExclusiveSql/days/bucketBounds/group`. |
| `src/Reports/ReportRollup.php` | pure PHP additive sales day→week/month folding + zero-fill + ISO-week keys. Never used to sum customer counts. |
| `src/Reports/StockThreshold.php` | resolves request override or configured default; enforces `0..100000` for both and throws `ReportConfigurationException` for invalid config. |
| `src/Reports/SalesReportRepository.php` | the two day-grouped raw-SQL aggregates + window sums. |
| `src/Reports/ProductSalesReportRepository.php` | independent sales/refund activity branches + outer variant aggregate, offset pagination (rows + count query). |
| `src/Reports/CustomerReportRepository.php` | bounded bucket-boundary aggregate (≤ requested buckets + summary) with DB-side distinct-key classification. Reuses `CustomerAggregationRepository::KEY_EXPR` (promote the constant to `public` — mechanical, no behavior change). |
| `src/Reports/StockReportRepository.php` | threshold query + count. |
| `src/Http/Admin/AdminReportController.php` | four actions; resolves tenant via `CurrentTenantResolver` (house fallback pattern); builds VOs; shapes responses. |
| `src/Http/DTOs/{ReportWindowQuery,ProductsReportQuery,StockReportQuery}.php` | query DTOs. |

Registration: controller + repositories in `CommerceServiceProvider::services()` (use
imports, not inline FQCNs); routes appended to the existing admin group in `routes.php`
with `$read`.

## 6. Config

```php
'reports' => [
    'low_stock_threshold' => (int) env('COMMERCE_REPORTS_LOW_STOCK_THRESHOLD', 2),
],
```
Added to `config/commerce.php`. No other keys (window cap and defaults are code
constants — they are contract, not deployment tuning).

## 7. Tests

- **Unit:** `ReportRollup` (ISO-week year boundaries incl. W53/W01, month folding,
  zero-fill), `ReportWindow` (defaults, 366-cap 422, from>to 422, future `to`, non-UTC
  process/supplied timezone normalizes to UTC, `fromQuery` and `fromDates` equivalence),
  `DateBucketSql` and `ReportBoundarySql` (three drivers + unknown-driver throw, exact timestamp
  casts), `StockThreshold` (request/config boundaries + invalid-config failure), AOV half-up
  rounding matrix.
- **Integration (per endpoint, SQLite + the standing pgsql lane):** seeded fixture set
  spanning multiple days/statuses/tenants —
  - sales: bucket math vs hand-computed integers; status exclusions (`pending_payment`,
    `canceled` invisible in gross); refund in later bucket than its order (decision 5
    proof); zero-fill; week + month rollups; summary consistency
    (additive series fields sum to their summary counterparts; window AOV is recomputed from
    summary gross/count rather than summed; `net = gross − refunds`).
  - products: ranking both sorts + deterministic tie-break across pages; May-order/June-refund
    remains in June as a refund-only variant; explicitly line-attributed refund columns;
    line-less refund excluded from product attribution but present in sales refunds; snapshot
    names (rename product after order → report shows snapshot).
  - customers: new-vs-returning across buckets (key new in exactly one bucket; guest email
    vs user_uuid keys; case/trim email normalization); pre-window first order makes
    in-window order returning; same customer ordering on two days in one week/month counted
    once and never both new+returning in that bucket; summary independently deduplicated and
    not derived by summing series; repository row count ≤ buckets+1 under a high-customer fixture.
  - stock: threshold boundaries (0, exact threshold, threshold+1), untracked invisibility,
    inactive variant exclusion + product `deleted_at` exclusion (draft products remain),
    `?status=` filter, `?threshold=` override.
  - all: tenant isolation (two tenants, disjoint results), scope enforcement
    (`commerce:read` required). Empty-result contracts are endpoint-specific: sales/customers
    return zero-filled all-zero series, products returns an empty paginated `data` list, and
    stock is point-in-time (tested with no qualifying stock, not an artificial date window).
- **Migrations shape test:** all three folded indexes exist; refund index targets
  `completed_at`; order query SQL uses indexed placed/non-placed branches rather than a
  `WHERE COALESCE(...)` predicate.
- **Regression gate:** pre-existing suite byte-identical (read-only layer — only the
  `KEY_EXPR` visibility promotion and folded indexes touch existing files).

## 8. Out of scope

Materialized rollup tables; cursor pagination; report exports (CSV); coupon-usage report
(no coupon breadth yet); stock-movement time series; per-gateway revenue split.
