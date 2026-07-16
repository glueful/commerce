<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Support;

use Glueful\Extensions\Commerce\Support\JsonStringArrayContainsSql;
use PHPUnit\Framework\TestCase;

/**
 * {@see JsonStringArrayContainsSql} is the exact cross-driver membership test
 * the storefront attribute-value filter relies on instead of the framework's
 * text-`LIKE` `whereJsonContains()` fallback (Layer 6 Global Constraints):
 * `red` must never match a stored `bred`. This file proves the three driver
 * shapes, bound (never interpolated) values, and the two rejection paths
 * (unknown driver, untrusted column identifier). The column is always quoted
 * (double quotes on sqlite/pgsql, backticks on mysql) -- `values`, the real
 * caller's column name, is a reserved word in SQLite's grammar even when
 * table-qualified, so an unquoted reference is a genuine syntax error there.
 */
final class JsonStringArrayContainsSqlTest extends TestCase
{
    public function testSqliteUsesJsonEachWithExactEquality(): void
    {
        $condition = JsonStringArrayContainsSql::condition('sqlite', 'commerce_product_attributes.values', 'red');

        self::assertSame(
            'EXISTS (SELECT 1 FROM json_each("commerce_product_attributes"."values") WHERE json_each.value = ?)',
            $condition['sql']
        );
        self::assertSame(['red'], $condition['bindings']);
    }

    public function testPgsqlUsesJsonbContainmentAgainstAnEncodedOneElementArray(): void
    {
        $condition = JsonStringArrayContainsSql::condition('pgsql', 'commerce_product_attributes.values', 'red');

        self::assertSame('"commerce_product_attributes"."values"::jsonb @> ?::jsonb', $condition['sql']);
        self::assertSame(['["red"]'], $condition['bindings']);
    }

    public function testMysqlUsesJsonContainsAgainstAnEncodedScalar(): void
    {
        $condition = JsonStringArrayContainsSql::condition('mysql', 'commerce_product_attributes.values', 'red');

        self::assertSame('JSON_CONTAINS(`commerce_product_attributes`.`values`, ?)', $condition['sql']);
        self::assertSame(['"red"'], $condition['bindings']);
    }

    public function testValueIsAlwaysBoundNeverInterpolatedIntoTheSql(): void
    {
        foreach (['sqlite', 'pgsql', 'mysql'] as $driver) {
            $condition = JsonStringArrayContainsSql::condition($driver, 'commerce_product_attributes.values', 'red');
            self::assertStringNotContainsString('red', $condition['sql']);
        }
    }

    public function testUnknownDriverThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unsupported database driver 'oracle'");

        JsonStringArrayContainsSql::condition('oracle', 'commerce_product_attributes.values', 'red');
    }

    public function testUntrustedColumnIdentifierIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid column identifier');

        JsonStringArrayContainsSql::condition('sqlite', 'values); DROP TABLE commerce_products; --', 'red');
    }

    public function testColumnIdentifierWithoutATableQualifierIsAccepted(): void
    {
        $condition = JsonStringArrayContainsSql::condition('sqlite', 'values', 'red');

        self::assertSame(
            'EXISTS (SELECT 1 FROM json_each("values") WHERE json_each.value = ?)',
            $condition['sql']
        );
    }
}
