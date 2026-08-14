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

    public function testEntryCountIs119(): void
    {
        // 98 (1.3.x, spec §3) + 6 Task A6 per-product read endpoints (spec §3, 1.5.0)
        // + 1 (1.6.0 per-product order activity) + 10 draft order endpoints
        // (admin-order-creation cycle 2: 9 from Task 9, `orders.drafts.finalize` from Task 10)
        // + 3 payment-link endpoints (payment-links Task 8, design spec §2.2)
        // + 1 draft-artifact deletion endpoint (cleanup-train Task 5).
        self::assertCount(119, AdminRouteCatalog::entries());
    }

    /**
     * Cleanup-train Task 5: the ONE endpoint in this catalog that destroys a
     * `commerce_orders` row. `manage` mode, `unusual` kind -- it is not an
     * ordinary REST `destroy` of a sub-resource called "artifact"; the path
     * segment is a DISCRIMINATOR that names which orders may be deleted at all,
     * and the operation is a guarded hard delete of the order row itself.
     *
     * Pinned as an exact set so no second deletion route can ever appear
     * unnoticed beside it.
     */
    public function testTheArtifactEntryIsTheOneDeclaredDeletionEndpoint(): void
    {
        $byKey = [];
        foreach (AdminRouteCatalog::entries() as $entry) {
            $byKey[$entry->key] = $entry;
        }

        self::assertArrayHasKey('orders.artifact.destroy', $byKey);
        $entry = $byKey['orders.artifact.destroy'];
        self::assertSame('DELETE', $entry->method);
        self::assertSame('/orders/{uuid}/artifact', $entry->path);
        self::assertSame('manage', $entry->mode);
        self::assertSame('unusual', $entry->kind);
        self::assertSame('orders', $entry->domain);
        self::assertSame('destroy', $entry->action);
        self::assertSame(
            'Glueful\\Extensions\\Commerce\\Http\\Admin\\AdminOrderArtifactController',
            $entry->controller,
        );

        $artifactKeys = array_values(array_filter(
            array_keys($byKey),
            static fn (string $key): bool => str_contains($key, 'artifact'),
        ));
        self::assertSame(['orders.artifact.destroy'], $artifactKeys, 'no unpinned artifact entry may exist');
    }

    /**
     * Payment-links Task 8 (design spec §2.2 "Catalog entries (manage mode)"):
     * the three entries, all `manage` -- INCLUDING `show`. That is a deliberate
     * departure from this catalog's read=view convention: a link's state,
     * expiry, and provider-session exposure are payment-custody facts about how
     * an order may be paid, not ordinary order reading, and the spec pins the
     * whole trio to manage mode. There is exactly ONE HTTP owner of
     * mint/revoke/status, so an embedding host mounts these keys rather than
     * redeclaring the method/path pairs.
     */
    public function testThePaymentLinkEntriesAreTheThreeDeclaredManageModeOnes(): void
    {
        $byKey = [];
        foreach (AdminRouteCatalog::entries() as $entry) {
            $byKey[$entry->key] = $entry;
        }

        $expected = [
            'orders.payment_link.store' => 'POST',
            'orders.payment_link.destroy' => 'DELETE',
            'orders.payment_link.show' => 'GET',
        ];

        foreach ($expected as $key => $method) {
            self::assertArrayHasKey($key, $byKey, $key);
            self::assertSame($method, $byKey[$key]->method, $key);
            self::assertSame('/orders/{uuid}/payment-link', $byKey[$key]->path, $key);
            self::assertSame('manage', $byKey[$key]->mode, $key);
            self::assertSame('orders', $byKey[$key]->domain, $key);
            self::assertSame(
                'Glueful\\Extensions\\Commerce\\Http\\Admin\\AdminOrderPaymentLinkController',
                $byKey[$key]->controller,
                $key,
            );
        }

        $paymentLinkKeys = array_values(array_filter(
            array_keys($byKey),
            static fn (string $key): bool => str_contains($key, 'payment_link'),
        ));
        self::assertSame(array_keys($expected), $paymentLinkKeys, 'no unpinned payment-link entry may exist');
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
