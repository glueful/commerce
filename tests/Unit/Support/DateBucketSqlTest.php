<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Support;

use Glueful\Extensions\Commerce\Support\DateBucketSql;
use PHPUnit\Framework\TestCase;

final class DateBucketSqlTest extends TestCase
{
    public function testSqliteExpressionUsesStrftime(): void
    {
        self::assertSame(
            "strftime('%Y-%m-%d', placed_at)",
            DateBucketSql::dayExpression('sqlite', 'placed_at')
        );
    }

    public function testMysqlExpressionUsesDateFormat(): void
    {
        self::assertSame(
            "DATE_FORMAT(placed_at, '%Y-%m-%d')",
            DateBucketSql::dayExpression('mysql', 'placed_at')
        );
    }

    public function testPgsqlExpressionUsesToChar(): void
    {
        self::assertSame(
            "to_char(placed_at, 'YYYY-MM-DD')",
            DateBucketSql::dayExpression('pgsql', 'placed_at')
        );
    }

    public function testExpressionEmbedsTheGivenColumnVerbatim(): void
    {
        self::assertSame(
            "strftime('%Y-%m-%d', report_at)",
            DateBucketSql::dayExpression('sqlite', 'report_at')
        );
    }

    public function testUnknownDriverThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unsupported database driver 'oracle'");

        DateBucketSql::dayExpression('oracle', 'placed_at');
    }
}
