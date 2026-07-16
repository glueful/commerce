<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Support;

/**
 * `PDOStatement` subclass that counts every `execute()` call -- installed via
 * `PDO::ATTR_STATEMENT_CLASS` on a test's own `Connection::getPDO()` to give a
 * Layer 6 "query-count guard" test a ground-truth statement count, without
 * needing the framework's debug-gated `QueryLogger`. Each test's `Connection`
 * (see `CommerceTestCase::setUp()`) is a fresh in-memory SQLite instance, so
 * installing this attribute never leaks a count between tests.
 */
final class CountingPdoStatement extends \PDOStatement
{
    public static int $count = 0;

    public function execute(?array $params = null): bool
    {
        self::$count++;

        return parent::execute($params);
    }
}
