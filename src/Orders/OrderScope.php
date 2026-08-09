<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Database\QueryBuilder;

/**
 * The ONE finalized-order predicate (admin-order-creation cycle 2, Task 8).
 *
 * A `draft` row lives in `commerce_orders` alongside real orders but is NOT an
 * order yet: it has no order number, no customer-visible existence, no money
 * history, and no lifecycle events. Every engine reader that answers a question
 * about REAL orders -- storefront `mine`/`show`, admin `orders.index`/`show`,
 * reports and aggregations, refunds, downloads, customer aggregation, expiry,
 * fulfillment rollups, the currency lock, marketplace surfaces -- must therefore
 * apply this predicate. Centralizing it here means the exclusion is one grep
 * away from any new reader, and the value `'draft'` is written exactly once.
 *
 * Two shapes, because this codebase reads `commerce_orders` two ways:
 *  - {@see self::excludeDrafts()} for query-builder reads;
 *  - {@see self::excludeDraftsSql()} for the hand-written SQL the reports and
 *    aggregation repositories deliberately use (see
 *    {@see \Glueful\Extensions\Commerce\Customers\CustomerAggregationRepository}'s
 *    class docblock for why those bypass the builder).
 *
 * INCLUDE-ON-REQUEST: the handful of readers that legitimately need drafts (the
 * admin draft surfaces added in Task 9, and the finalization path in Task 10)
 * opt in EXPLICITLY -- `OrderRepository::findByUuid(..., includeDrafts: true)`
 * and `paginatedFor(..., includeDrafts: true)`. There is no implicit back door:
 * filtering the ordinary orders surface by `status=draft` still returns nothing.
 */
final class OrderScope
{
    /** The one and only draft status value. */
    public const DRAFT = 'draft';

    /**
     * Query-builder form of the predicate. Returns the same builder for
     * chaining; `$column` accepts a table-qualified name for joined reads.
     */
    public static function excludeDrafts(QueryBuilder $query, string $column = 'status'): QueryBuilder
    {
        return $query->where($column, '!=', self::DRAFT);
    }

    /**
     * Raw-SQL form of the predicate, for the hand-written report/aggregation
     * SQL. `$qualifier` is an optional table alias (`'o'` -> `o.status <>
     * 'draft'`). The literal is single-quoted deliberately: a double-quoted
     * `"draft"` would be an IDENTIFIER on both PostgreSQL and SQLite, not a
     * string (see `CustomerAggregationRepository`'s class docblock, gotcha 2).
     */
    public static function excludeDraftsSql(string $qualifier = ''): string
    {
        $column = $qualifier === '' ? 'status' : $qualifier . '.status';

        return $column . " <> '" . self::DRAFT . "'";
    }
}
