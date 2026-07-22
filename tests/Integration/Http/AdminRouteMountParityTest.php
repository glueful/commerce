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
 */
final class AdminRouteMountParityTest extends CommerceRouterTestCase
{
    public function testNativeMountEqualsLegacyInventory(): void
    {
        $fixtureFile = dirname(__DIR__, 2) . '/fixtures/admin_route_inventory_1_3.json';
        self::assertFileExists($fixtureFile);
        /** @var list<array<string,mixed>> $expected */
        $expected = json_decode((string) file_get_contents($fixtureFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(98, $expected);

        $actual = $this->collectNonMarketplaceAdminRoutes($this->freshRouter());

        self::assertCount(98, $actual, 'mounted non-marketplace admin route count drifted');
        foreach ($expected as $i => $record) {
            self::assertSame(
                $record,
                $actual[$i],
                "route inventory parity failure at [{$i}] {$record['method']} {$record['path']}",
            );
        }
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
        usort(
            $records,
            static fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']],
        );

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
