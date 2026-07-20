<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Support;

/**
 * `PDOStatement` subclass that records the SQL text of every `execute()`
 * call -- installed via `PDO::ATTR_STATEMENT_CLASS` on a test's own
 * `Connection::getPDO()`, sibling to {@see CountingPdoStatement}. Where a
 * raw count is enough, use `CountingPdoStatement`; where a test needs to
 * assert WHICH tables a call did (or did not) touch -- e.g. "zero
 * marketplace-table queries" -- this captures the actual query text so the
 * assertion checks content, not just a count that could coincidentally
 * match across two structurally different call shapes.
 */
final class QueryLoggingPdoStatement extends \PDOStatement
{
    /** @var list<string> */
    public static array $queries = [];

    public function execute(?array $params = null): bool
    {
        self::$queries[] = $this->queryString;

        return parent::execute($params);
    }
}
