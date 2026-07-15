<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Customers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\EmailNormalizer;

/**
 * Order-derived customer aggregation (design spec §7) — there is NO dedicated
 * customer table. Every row groups `commerce_orders` per tenant by `user_uuid`
 * when present, else by the normalized `lower(trim(email))` guest identity.
 * The listing and the two by-key detail lookups all share the exact same
 * aggregate projection (orders_count / total_spent_minor / refunded_minor /
 * first_order_at / last_order_at) so the numbers can never drift between the
 * two surfaces.
 *
 * PORTABILITY NOTE (verified against a real `sqlite::memory:` connection AND a
 * live PostgreSQL 17 instance while writing this class — not just reasoned
 * about): the query builder's own `groupBy()`/`orderBy()` unconditionally wrap
 * every column through `DatabaseDriver::wrapIdentifier()`, which is correct for
 * a real column name but turns a GROUP BY *expression* like
 * `CASE WHEN user_uuid IS NOT NULL THEN user_uuid ELSE LOWER(TRIM(email)) END`
 * into a single nonsense quoted identifier (`"CASE WHEN ... END"`). The listing
 * query in this class therefore builds and executes its own SQL text via
 * `executeRaw()`/`executeRawFirst()` — bypassing `groupBy()`/`count()` (whose
 * `COUNT(*)` ignores any GROUP BY entirely, see `QueryBuilder::buildCountQuery()`)
 * — the same "hand the driver exact, verified SQL" posture
 * {@see \Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository::mint()}
 * already uses for its guarded UPDATE. Two further gotchas confirmed against
 * BOTH drivers before this shipped:
 *   1. PostgreSQL requires every SELECT-list expression to be either GROUP-BY'd
 *      or aggregated. The `key_type` discriminator
 *      (`CASE WHEN ... THEN 'user' ELSE 'email' END`) is a DIFFERENT expression
 *      from the GROUP BY key, so it must read `MAX(user_uuid)` — constant
 *      within a group — not the bare `user_uuid` column, or PostgreSQL rejects
 *      the query outright ("must appear in the GROUP BY clause or be used in
 *      an aggregate function").
 *   2. SQLite's double-quoted-string identifier fallback: a double-quoted
 *      `"email"` is NOT a string literal here, because a REAL column named
 *      `email` exists on this table — SQLite silently resolves it to that
 *      column (an arbitrary row's value, picked per GROUP) instead of erroring.
 *      Every string literal in this class's SQL MUST be single-quoted; a
 *      double-quoted 'email'/'user' would silently corrupt `key_type` on
 *      SQLite only, with no error anywhere.
 * ORDER BY needs no such workaround: referencing a SELECT-list alias in
 * ORDER BY (unlike GROUP BY) is unconditionally standard SQL, supported
 * identically by every driver — `ORDER BY total_spent_minor DESC` just works.
 *
 * The email substring filter (`?email=`) is applied to the underlying orders'
 * literal `email` column BEFORE aggregation (`LOWER(email) LIKE ?`), not to the
 * aggregated group — a deliberate, simple "search box" semantic: a guest whose
 * orders all share one email always matches or doesn't as a whole; an
 * authenticated customer whose account email happens to have changed between
 * orders could in principle match on some of their orders' rows but not
 * others, which is an accepted edge case for this v1 admin search.
 */
final class CustomerAggregationRepository
{
    private const TABLE = 'commerce_orders';

    /** Sort key (public API) => underlying SELECT-list alias. */
    private const SORT_COLUMNS = [
        'last_order_at' => 'last_order_at',
        'total_spent' => 'total_spent_minor',
    ];

    /** Grouping/aggregate key expression shared by the listing and both detail lookups. */
    private const KEY_EXPR = "CASE WHEN user_uuid IS NOT NULL THEN user_uuid ELSE LOWER(TRIM(email)) END";

    /** MUST read MAX(user_uuid), not the bare column — see class docblock, gotcha 1. */
    private const KEY_TYPE_EXPR = "CASE WHEN MAX(user_uuid) IS NOT NULL THEN 'user' ELSE 'email' END";

    /**
     * @param array{email?: string} $filters
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function paginate(
        ApplicationContext $context,
        string $tenant,
        array $filters,
        string $sort,
        string $direction,
        int $page,
        int $perPage
    ): array {
        if (!isset(self::SORT_COLUMNS[$sort])) {
            throw new \InvalidArgumentException("Unsupported customer sort field: {$sort}");
        }
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException("Unsupported customer sort direction: {$direction}");
        }
        $sortColumn = self::SORT_COLUMNS[$sort];

        [$whereSql, $bindings] = $this->buildWhere($tenant, $filters);

        $totalRow = db($context)->table(self::TABLE)->executeRawFirst(
            'SELECT COUNT(DISTINCT ' . self::KEY_EXPR . ') as total FROM ' . self::TABLE . " {$whereSql}",
            $bindings
        );
        $total = (int) ($totalRow['total'] ?? 0);

        $sql = 'SELECT '
            . self::KEY_TYPE_EXPR . ' as key_type, '
            . self::KEY_EXPR . ' as customer_key, '
            . 'MAX(user_uuid) as user_uuid, '
            . 'MAX(email) as email, '
            . 'COUNT(*) as orders_count, '
            . 'SUM(grand_total) as total_spent_minor, '
            . 'SUM(refunded_total) as refunded_minor, '
            . 'MIN(created_at) as first_order_at, '
            . 'MAX(created_at) as last_order_at '
            . 'FROM ' . self::TABLE . " {$whereSql} "
            . 'GROUP BY ' . self::KEY_EXPR . ' '
            . "ORDER BY {$sortColumn} {$direction} "
            . 'LIMIT ? OFFSET ?';

        $rows = db($context)->table(self::TABLE)->executeRaw(
            $sql,
            [...$bindings, $perPage, max(0, $page - 1) * $perPage]
        );

        return [
            'items' => array_map(fn (array $row): array => $this->projectListRow($row), $rows),
            'total' => $total,
        ];
    }

    /** @return array<string,mixed>|null */
    public function findByUser(ApplicationContext $context, string $tenant, string $userUuid): ?array
    {
        $row = db($context)->table(self::TABLE)->executeRawFirst(
            $this->detailSql() . ' WHERE tenant_uuid = ? AND user_uuid = ?',
            [$tenant, $userUuid]
        );

        return $this->projectDetailRow($row, 'user', $userUuid, $userUuid);
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(ApplicationContext $context, string $tenant, string $email): ?array
    {
        $normalized = EmailNormalizer::normalize($email);
        $row = db($context)->table(self::TABLE)->executeRawFirst(
            $this->detailSql() . ' WHERE tenant_uuid = ? AND user_uuid IS NULL AND LOWER(TRIM(email)) = ?',
            [$tenant, $normalized]
        );

        return $this->projectDetailRow($row, 'email', $normalized, null);
    }

    private function detailSql(): string
    {
        return 'SELECT '
            . 'COUNT(*) as orders_count, '
            . 'SUM(grand_total) as total_spent_minor, '
            . 'SUM(refunded_total) as refunded_minor, '
            . 'MIN(created_at) as first_order_at, '
            . 'MAX(created_at) as last_order_at, '
            . 'MAX(email) as email '
            . 'FROM ' . self::TABLE;
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    private function projectDetailRow(?array $row, string $keyType, string $key, ?string $userUuid): ?array
    {
        if ($row === null || (int) $row['orders_count'] === 0) {
            return null;
        }

        return [
            'key_type' => $keyType,
            'key' => $key,
            'user_uuid' => $userUuid,
            'email' => (string) $row['email'],
            'orders_count' => (int) $row['orders_count'],
            'total_spent_minor' => (int) $row['total_spent_minor'],
            'refunded_minor' => (int) $row['refunded_minor'],
            'first_order_at' => $row['first_order_at'],
            'last_order_at' => $row['last_order_at'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function projectListRow(array $row): array
    {
        return [
            'key_type' => (string) $row['key_type'],
            'key' => (string) $row['customer_key'],
            'user_uuid' => $row['user_uuid'] !== null ? (string) $row['user_uuid'] : null,
            'email' => (string) $row['email'],
            'orders_count' => (int) $row['orders_count'],
            'total_spent_minor' => (int) $row['total_spent_minor'],
            'refunded_minor' => (int) $row['refunded_minor'],
            'first_order_at' => $row['first_order_at'],
            'last_order_at' => $row['last_order_at'],
        ];
    }

    /**
     * @param array{email?: string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(string $tenant, array $filters): array
    {
        $sql = 'WHERE tenant_uuid = ?';
        $bindings = [$tenant];

        $emailFilter = isset($filters['email']) ? trim($filters['email']) : '';
        if ($emailFilter !== '') {
            $sql .= ' AND LOWER(email) LIKE ?';
            $bindings[] = '%' . strtolower($emailFilter) . '%';
        }

        return [$sql, $bindings];
    }
}
