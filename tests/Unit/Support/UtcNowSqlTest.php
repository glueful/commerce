<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Support;

use Glueful\Extensions\Commerce\Support\UtcNowSql;
use PHPUnit\Framework\TestCase;

final class UtcNowSqlTest extends TestCase
{
    public function testSqliteExpressionIsCurrentTimestamp(): void
    {
        self::assertSame('CURRENT_TIMESTAMP', UtcNowSql::expression('sqlite'));
    }

    public function testMysqlExpressionIsUtcTimestampFunction(): void
    {
        self::assertSame('UTC_TIMESTAMP()', UtcNowSql::expression('mysql'));
    }

    public function testPgsqlExpressionConvertsNowToUtc(): void
    {
        self::assertSame("(NOW() AT TIME ZONE 'UTC')", UtcNowSql::expression('pgsql'));
    }

    public function testUnknownDriverThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unsupported database driver 'oracle'");

        UtcNowSql::expression('oracle');
    }
}
