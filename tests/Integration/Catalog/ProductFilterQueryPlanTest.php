<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ResolvedProductFilters;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Layer 6 Task 5's query-plan gate: proves, by ACTUAL `EXPLAIN QUERY PLAN`
 * output against the real migrated schema, that migration 007's existing
 * indexes -- the `(product_uuid, category_uuid)`/`(product_uuid, tag_uuid)`
 * composite uniques and the `(product_uuid, attribute_uuid)` composite unique
 * on `commerce_product_attributes` -- already support the storefront filter's
 * correlated `EXISTS` semijoins with an index seek, not a full table scan.
 * This is a query-plan ASSERTION, not shape-guessing: if migration 007 ever
 * needed an additive index for this filter, this test would fail and name
 * exactly which subquery regressed to a scan.
 */
final class ProductFilterQueryPlanTest extends CommerceTestCase
{
    public function testCategoryFilterSemijoinUsesAnIndexSeekNotATableScan(): void
    {
        $plan = $this->explainFor(new ResolvedProductFilters(categoryUuid: 'cat0000001'));

        $this->assertTableUsesIndexNotScan($plan, 'commerce_product_categories');
    }

    public function testTagFilterSemijoinUsesAnIndexSeekNotATableScan(): void
    {
        $plan = $this->explainFor(new ResolvedProductFilters(tagUuid: 'tag0000001'));

        $this->assertTableUsesIndexNotScan($plan, 'commerce_product_tags');
    }

    public function testAttributePairFilterSemijoinUsesAnIndexSeekNotATableScan(): void
    {
        $plan = $this->explainFor(new ResolvedProductFilters(attributePairs: [
            ['attribute_uuid' => 'attr0000001', 'value_slug' => 'red'],
        ]));

        $this->assertTableUsesIndexNotScan($plan, 'commerce_product_attributes');
    }

    public function testCombinedFiltersEachUseAnIndexSeekNotATableScan(): void
    {
        $plan = $this->explainFor(new ResolvedProductFilters(
            categoryUuid: 'cat0000001',
            tagUuid: 'tag0000001',
            attributePairs: [['attribute_uuid' => 'attr0000001', 'value_slug' => 'red']]
        ));

        $this->assertTableUsesIndexNotScan($plan, 'commerce_product_categories');
        $this->assertTableUsesIndexNotScan($plan, 'commerce_product_tags');
        $this->assertTableUsesIndexNotScan($plan, 'commerce_product_attributes');
    }

    /** @return list<array<string,mixed>> */
    private function explainFor(ResolvedProductFilters $filters): array
    {
        return (new ProductRepository())->activeFilteredQuery($this->context, '', $filters)->explain();
    }

    /** @param list<array<string,mixed>> $plan */
    private function assertTableUsesIndexNotScan(array $plan, string $table): void
    {
        $details = array_map(static fn (array $row): string => (string) ($row['detail'] ?? ''), $plan);
        $matching = array_values(array_filter(
            $details,
            static fn (string $detail): bool => str_contains($detail, $table)
        ));

        self::assertNotEmpty($matching, "expected the query plan to reference {$table}");

        foreach ($matching as $detail) {
            self::assertStringContainsString(
                'USING',
                $detail,
                "expected an index seek (not a full scan) for {$table}, got: {$detail}"
            );
            self::assertStringNotContainsString(
                "SCAN {$table}",
                $detail,
                "expected {$table} to be searched via an index, not scanned: {$detail}"
            );
        }
    }
}
