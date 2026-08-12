<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase;
use Glueful\Routing\Router;

/**
 * The catalog-mounted native admin surface must equal the approved pre-catalog (1.3.x)
 * route inventory byte-for-byte on method, path, controller/action, flattened middleware,
 * and route name — the expected name is explicitly null, so accidental native naming
 * fails parity (spec §3.3 assertion 1). Any diff is a transcription bug in
 * AdminRouteCatalog::entries(): fix the catalog, never this fixture.
 *
 * Router::getAllRoutes() enumerates static-then-dynamic, so both the fixture and the
 * live collection are canonically sorted by (path, method) before comparison.
 *
 * Task A6 adds 6 per-product read endpoints on top of the immutable 98-entry 1.3.x
 * inventory. `tests/fixtures/admin_route_inventory_1_3.json` is NEVER touched again —
 * the 6 additions live in a second, additive fixture
 * (`admin_route_inventory_1_5_additions.json`) so the original 98 stay byte-parity-pinned
 * while the new total (104) is independently pinned too.
 *
 * Admin-order-creation cycle 2 follows the SAME additive discipline: the draft order
 * endpoints live in their own fixture (`admin_route_inventory_1_10_draft_additions.json`),
 * so every earlier fixture stays byte-frozen and the new total (115) is pinned on top.
 * Task 9 contributed 9 of them; Task 10 added `orders.drafts.finalize` alongside the
 * `DraftFinalizationService` it mounts, so the catalog never named an action before it
 * existed.
 *
 * Payment-links Task 8 follows the SAME additive discipline once more: the three
 * `orders.payment_link.*` endpoints live in their own fixture
 * (`admin_route_inventory_1_11_payment_link_additions.json`), every earlier fixture stays
 * byte-frozen, and the new total (118) is pinned on top. All three mount in `manage` mode
 * (`require_scope:commerce:write`) -- including the status read, per design spec §2.2.
 */
final class AdminRouteMountParityTest extends CommerceRouterTestCase
{
    private const LEGACY_COUNT = 98;
    private const ADDITIONS_COUNT = 6;
    private const ADDITIONS_1_6_COUNT = 1;
    /** Admin-order-creation cycle 2: the draft order surface (9 from Task 9, finalize from Task 10). */
    private const ADDITIONS_DRAFT_COUNT = 10;
    /** Payment-links Task 8 (design spec §2.2): mint/revoke/status, all manage mode. */
    private const ADDITIONS_PAYMENT_LINK_COUNT = 3;
    private const TOTAL_COUNT = self::LEGACY_COUNT
        + self::ADDITIONS_COUNT
        + self::ADDITIONS_1_6_COUNT
        + self::ADDITIONS_DRAFT_COUNT
        + self::ADDITIONS_PAYMENT_LINK_COUNT;

    public function testNativeMountEqualsLegacyInventoryPlusTaskA6Additions(): void
    {
        $legacy = $this->loadFixture('admin_route_inventory_1_3.json');
        self::assertCount(
            self::LEGACY_COUNT,
            $legacy,
            'the 1.3.x fixture is immutable and must stay pinned at exactly 98 entries',
        );

        $additions = $this->loadFixture('admin_route_inventory_1_5_additions.json');
        self::assertCount(
            self::ADDITIONS_COUNT,
            $additions,
            'Task A6 adds exactly 6 per-product read endpoints',
        );

        $additions16 = $this->loadFixture('admin_route_inventory_1_6_additions.json');
        self::assertCount(
            self::ADDITIONS_1_6_COUNT,
            $additions16,
            '1.6.0 adds exactly the per-product order-activity read',
        );

        $draftAdditions = $this->loadFixture('admin_route_inventory_1_10_draft_additions.json');
        self::assertCount(
            self::ADDITIONS_DRAFT_COUNT,
            $draftAdditions,
            'cycle 2 adds exactly 10 draft order endpoints (9 from Task 9 + finalize from Task 10)',
        );

        $paymentLinkAdditions = $this->loadFixture('admin_route_inventory_1_11_payment_link_additions.json');
        self::assertCount(
            self::ADDITIONS_PAYMENT_LINK_COUNT,
            $paymentLinkAdditions,
            'payment-links Task 8 adds exactly 3 payment-link endpoints',
        );

        $expected = array_merge($legacy, $additions, $additions16, $draftAdditions, $paymentLinkAdditions);
        usort($expected, self::routeSortComparator());
        self::assertCount(self::TOTAL_COUNT, $expected);

        $actual = $this->collectNonMarketplaceAdminRoutes($this->freshRouter());

        self::assertCount(
            self::TOTAL_COUNT,
            $actual,
            'mounted non-marketplace admin route count drifted '
            . '(expected 98 legacy + 6 Task A6 + 1 1.6.0 + 10 draft + 3 payment-link additions)',
        );
        foreach ($expected as $i => $record) {
            self::assertSame(
                $record,
                $actual[$i],
                "route inventory parity failure at [{$i}] {$record['method']} {$record['path']}",
            );
        }
    }

    /**
     * Dedicated assertion on exactly the 6 Task A6 additions (spec §3 table): each is
     * present, mounted with `view` mode (`require_scope:commerce:read`), and no
     * additional per-product read endpoint snuck in unpinned.
     */
    public function testTaskA6AdditionsAreExactlyTheSixDeclaredReadEndpoints(): void
    {
        $additions = $this->loadFixture('admin_route_inventory_1_5_additions.json');
        self::assertCount(self::ADDITIONS_COUNT, $additions);

        $actual = $this->collectNonMarketplaceAdminRoutes($this->freshRouter());
        $actualByRoute = [];
        foreach ($actual as $record) {
            $actualByRoute[$record['method'] . ' ' . $record['path']] = $record;
        }

        foreach ($additions as $record) {
            $routeKey = $record['method'] . ' ' . $record['path'];
            self::assertArrayHasKey($routeKey, $actualByRoute, "expected Task A6 read endpoint missing: {$routeKey}");
            self::assertSame($record, $actualByRoute[$routeKey]);
        }

        foreach ($this->loadFixture('admin_route_inventory_1_6_additions.json') as $record) {
            $routeKey = $record['method'] . ' ' . $record['path'];
            self::assertArrayHasKey($routeKey, $actualByRoute, "expected 1.6.0 read endpoint missing: {$routeKey}");
            self::assertSame($record, $actualByRoute[$routeKey]);
        }

        foreach ($this->loadFixture('admin_route_inventory_1_10_draft_additions.json') as $record) {
            $routeKey = $record['method'] . ' ' . $record['path'];
            self::assertArrayHasKey($routeKey, $actualByRoute, "expected draft endpoint missing: {$routeKey}");
            self::assertSame($record, $actualByRoute[$routeKey]);
        }

        foreach ($this->loadFixture('admin_route_inventory_1_11_payment_link_additions.json') as $record) {
            $routeKey = $record['method'] . ' ' . $record['path'];
            self::assertArrayHasKey($routeKey, $actualByRoute, "expected payment-link endpoint missing: {$routeKey}");
            self::assertSame($record, $actualByRoute[$routeKey]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function loadFixture(string $filename): array
    {
        $fixtureFile = dirname(__DIR__, 2) . '/fixtures/' . $filename;
        self::assertFileExists($fixtureFile);
        /** @var list<array<string,mixed>> $decoded */
        $decoded = json_decode((string) file_get_contents($fixtureFile), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @return callable(array<string,mixed>, array<string,mixed>): int */
    private static function routeSortComparator(): callable
    {
        return static fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']];
    }

    public function testMarketplaceGroupStillRegistersOnlyWhenFlagEnabled(): void
    {
        $disabled = $this->collectPaths($this->freshRouter(), '/commerce/admin/marketplace');
        self::assertSame([], $disabled, 'marketplace admin routes must be absent by default');

        $this->enableMarketplace();
        $enabled = $this->collectPaths($this->freshRouter(), '/commerce/admin/marketplace');
        self::assertNotSame([], $enabled, 'marketplace admin routes must register when the flag is on');
    }

    /** @return list<array<string,mixed>> */
    private function collectNonMarketplaceAdminRoutes(Router $router): array
    {
        $records = [];
        foreach ($router->getAllRoutes() as $route) {
            $path = (string) $route['path'];
            if (
                !str_starts_with($path, '/commerce/admin')
                || str_starts_with($path, '/commerce/admin/marketplace')
                || str_contains($path, 'seller-orders')
            ) {
                continue;
            }
            $handler = $route['handler'];
            self::assertIsArray($handler, "non-array handler at {$path}");
            $records[] = [
                'method' => (string) $route['method'],
                'path' => $path,
                'controller' => $handler[0],
                'action' => $handler[1],
                'middleware' => array_values(array_map('strval', (array) $route['middleware'])),
                'name' => $route['name'],
            ];
        }
        usort($records, self::routeSortComparator());

        return $records;
    }

    /** @return list<string> */
    private function collectPaths(Router $router, string $prefix): array
    {
        $paths = [];
        foreach ($router->getAllRoutes() as $route) {
            if (str_starts_with((string) $route['path'], $prefix)) {
                $paths[] = (string) $route['path'];
            }
        }

        return $paths;
    }
}
