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
     * The canceled status value, written once for the same reason `DRAFT` is:
     * cleanup-train Task 5's draft-ARTIFACT predicate is half `status =
     * 'canceled'`, and a destructive predicate must not carry a hand-typed
     * literal.
     */
    public const CANCELED = 'canceled';

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
        return self::column($qualifier) . " <> '" . self::DRAFT . "'";
    }

    /**
     * The POSITIVE form, for the two DEDICATED draft compare-and-set writes:
     * {@see OrderRepository::finalizeDraftTransition()} and
     * {@see DraftCleanupService::cancelDraft()}. Both are `WHERE ... AND
     * status = 'draft'`; routing them through here keeps the literal `'draft'`
     * written exactly once in production code, exactly like every reader.
     */
    public static function isDraftSql(string $qualifier = ''): string
    {
        return self::column($qualifier) . " = '" . self::DRAFT . "'";
    }

    /**
     * The ONE draft-ARTIFACT predicate (cleanup-train Task 5), in its SQL form.
     *
     * A draft artifact is an order row that was canceled while it was still a
     * draft: `order_number IS NULL` AND `status = 'canceled'`. That conjunction
     * is not a convention, it is a STRUCTURAL PROOF that the row never touched
     * money -- only finalization and storefront checkout ever allocate an order
     * number, and everything that can reference an order financially (payments,
     * invoices, stock claims, payment links, refunds, marketplace children) is
     * created on or after that allocation. A numberless canceled row therefore
     * cannot have any of them, which is the ONLY reason hard-deleting it is
     * legal at all.
     *
     * It lives here, beside the draft predicate, for the same reason that one
     * does: it is the authorization for a DESTRUCTIVE operation and it is used
     * from three places -- the endpoint's classification, the guarded delete's
     * compare-and-set, and the purge sweep's candidate scan. Three hand-written
     * copies could drift; one cannot. {@see self::isDeletableArtifact()} is the
     * PHP form over an already-read row, and the two are pinned equivalent by
     * `Orders\DraftArtifactPurgeTest`.
     *
     * `$qualifier` is an optional table alias, exactly as on the siblings above.
     */
    public static function deletableArtifactSql(string $qualifier = ''): string
    {
        $prefix = $qualifier === '' ? '' : $qualifier . '.';

        return $prefix . "order_number IS NULL AND " . $prefix . "status = '" . self::CANCELED . "'";
    }

    /**
     * The PHP form of {@see self::deletableArtifactSql()}, for a row that has
     * already been read (the endpoint's classification and re-classification).
     *
     * `array_key_exists` rather than `isset` on `order_number`: a NULL number is
     * the whole point of the predicate, and `isset()` cannot tell "the column is
     * NULL" from "the caller handed us a row that does not have the column at
     * all". A row missing the column is not an artifact -- it is a row this
     * method has no business answering for, and it fails closed.
     *
     * @param array<string,mixed> $order a `commerce_orders` row
     */
    public static function isDeletableArtifact(array $order): bool
    {
        return array_key_exists('order_number', $order)
            && $order['order_number'] === null
            && ($order['status'] ?? null) === self::CANCELED;
    }

    private static function column(string $qualifier): string
    {
        return $qualifier === '' ? 'status' : $qualifier . '.status';
    }
}
