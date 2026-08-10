<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Http\Routing;

use Glueful\Extensions\Commerce\Http\Routing\AdminMountProfile;
use Glueful\Extensions\Commerce\Http\Routing\AdminRouteCatalog;
use PHPUnit\Framework\TestCase;

final class AdminRouteCatalogTest extends TestCase
{
    public function testEveryEntryHasUniqueNonEmptyKey(): void
    {
        $keys = array_map(static fn ($e) => $e->key, AdminRouteCatalog::entries());
        self::assertNotContains('', $keys);
        self::assertSame(count($keys), count(array_unique($keys)), 'duplicate catalog keys');
    }

    public function testEveryEntryHasExplicitValidMetadata(): void
    {
        $domains = [
            'products', 'taxonomy', 'inventory', 'downloads', 'customers', 'discounts',
            'orders', 'reviews', 'shipping', 'tax', 'reports',
        ];
        foreach (AdminRouteCatalog::entries() as $entry) {
            self::assertContains($entry->mode, ['view', 'manage'], "mode of {$entry->key}");
            self::assertContains($entry->kind, ['json', 'bulk', 'binary', 'unusual'], "kind of {$entry->key}");
            self::assertContains($entry->domain, $domains, "domain of {$entry->key}");
            self::assertNotSame('', $entry->method, "method of {$entry->key}");
            self::assertStringStartsWith('/', $entry->path, "path of {$entry->key}");
            self::assertTrue(class_exists($entry->controller), "controller of {$entry->key}");
            self::assertTrue(
                method_exists($entry->controller, $entry->action),
                "action {$entry->controller}::{$entry->action} of {$entry->key}",
            );
        }
    }

    public function testNoMarketplaceEntryEnters(): void
    {
        foreach (AdminRouteCatalog::entries() as $entry) {
            self::assertStringNotContainsString('marketplace', $entry->key, $entry->key);
            self::assertStringNotContainsString('marketplace', $entry->path, $entry->key);
            self::assertStringNotContainsString('seller-orders', $entry->path, $entry->key);
        }
    }

    public function testEntryCountIs115(): void
    {
        // 98 (1.3.x, spec §3) + 6 Task A6 per-product read endpoints (spec §3, 1.5.0)
        // + 1 (1.6.0 per-product order activity) + 10 draft order endpoints
        // (admin-order-creation cycle 2: 9 from Task 9, `orders.drafts.finalize` from Task 10).
        self::assertCount(115, AdminRouteCatalog::entries());
    }

    public function testRestrictedProfileRejectsAnEmptyAllowlist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AdminMountProfile::restricted(
            '/v1/admin/commerce',
            'thallo.commerce.admin.',
            ['auth'],
            ['view' => 'content_permission:x', 'manage' => 'content_permission:y'],
            [],
        );
    }
}
