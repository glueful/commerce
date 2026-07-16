<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Support;

use Glueful\Extensions\Commerce\Support\ReportBoundarySql;
use PHPUnit\Framework\TestCase;

final class ReportBoundarySqlTest extends TestCase
{
    public function testSqliteRowExpressionBindsPlainPlaceholders(): void
    {
        self::assertSame(
            'SELECT ? AS bucket, ? AS from_at, ? AS to_at',
            ReportBoundarySql::rowExpression('sqlite')
        );
    }

    public function testMysqlRowExpressionCastsBoundsToDatetime(): void
    {
        self::assertSame(
            'SELECT ? AS bucket, CAST(? AS DATETIME) AS from_at, CAST(? AS DATETIME) AS to_at',
            ReportBoundarySql::rowExpression('mysql')
        );
    }

    public function testPgsqlRowExpressionCastsBoundsToTimestamp(): void
    {
        self::assertSame(
            'SELECT ? AS bucket, CAST(? AS timestamp) AS from_at, CAST(? AS timestamp) AS to_at',
            ReportBoundarySql::rowExpression('pgsql')
        );
    }

    public function testUnknownDriverThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unsupported database driver 'oracle'");

        ReportBoundarySql::rowExpression('oracle');
    }
}
