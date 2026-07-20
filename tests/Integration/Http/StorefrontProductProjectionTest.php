<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\DTOs\ProductListQuery;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Public storefront product projection allowlist: the raw commerce_products /
 * commerce_variants rows carry internal columns (tenant_uuid, catalog_revision,
 * rating rollup internals, tax_class, raw metadata, numeric ids, timestamps)
 * that must never leave the public surface. Products expose exactly
 * uuid/slug/name/description/type/options/created_at plus the derived
 * enrichments; variants expose exactly uuid/sku/option_values/price/
 * compare_at_price/currency/position/status/shipping_class_uuid/shipping_class.
 */
final class StorefrontProductProjectionTest extends CommerceTestCase
{
    private const PRODUCT_INTERNAL_FIELDS = [
        'id',
        'tenant_uuid',
        'status',
        'metadata',
        'rating_sum',
        'rating_count',
        'catalog_revision',
        'tax_class',
        'updated_at',
        'deleted_at',
        // Marketplace MV1 (design spec §2.9): no storefront exposure in MV1
        // -- pinned here regardless of a product's actual attribution state.
        'seller_uuid',
    ];

    private const VARIANT_INTERNAL_FIELDS = [
        'id',
        'tenant_uuid',
        'product_uuid',
        'created_at',
        'updated_at',
    ];

    private const PRODUCT_PUBLIC_BASE_FIELDS = [
        'uuid',
        'slug',
        'name',
        'description',
        'type',
        'options',
        'created_at',
    ];

    public function testIndexProjectsOnlyAllowlistedProductAndVariantFields(): void
    {
        $this->seedProduct('projprod0001');
        $this->seedVariant('projvar00001', 'projprod0001');

        $body = $this->json($this->controller()->index(new ProductListQuery()));

        self::assertCount(1, $body['data']);
        $product = $body['data'][0];

        foreach (self::PRODUCT_INTERNAL_FIELDS as $field) {
            self::assertArrayNotHasKey($field, $product, "index leaks product.{$field}");
        }
        foreach (self::PRODUCT_PUBLIC_BASE_FIELDS as $field) {
            self::assertArrayHasKey($field, $product, "index missing product.{$field}");
        }
        self::assertArrayHasKey('variants', $product);
        self::assertArrayHasKey('cover_url', $product);

        $variant = $product['variants'][0];
        foreach (self::VARIANT_INTERNAL_FIELDS as $field) {
            self::assertArrayNotHasKey($field, $variant, "index leaks variant.{$field}");
        }
        foreach (['uuid', 'sku', 'option_values', 'price', 'currency', 'position', 'status'] as $field) {
            self::assertArrayHasKey($field, $variant, "index missing variant.{$field}");
        }
        self::assertArrayHasKey('shipping_class', $variant);
        self::assertArrayHasKey('shipping_class_uuid', $variant);
    }

    public function testShowProjectsOnlyAllowlistedProductAndVariantFields(): void
    {
        $this->seedProduct('projprod0002', [
            'slug' => 'proj-show',
            'metadata' => '{"internal_note":"secret"}',
            'rating_sum' => 9,
            'rating_count' => 2,
        ]);
        $this->seedVariant('projvar00002', 'projprod0002');

        $body = $this->json($this->controller()->show(Request::create('/x'), 'proj-show'));
        $product = $body['data'];

        foreach (self::PRODUCT_INTERNAL_FIELDS as $field) {
            self::assertArrayNotHasKey($field, $product, "show leaks product.{$field}");
        }
        foreach (self::PRODUCT_PUBLIC_BASE_FIELDS as $field) {
            self::assertArrayHasKey($field, $product, "show missing product.{$field}");
        }

        // Derived enrichments survive the allowlist.
        foreach (['variants', 'media', 'categories', 'tags', 'attributes', 'addons'] as $field) {
            self::assertArrayHasKey($field, $product, "show missing derived {$field}");
        }
        self::assertSame(['average' => 4.5, 'count' => 2], $product['rating']);

        $variant = $product['variants'][0];
        foreach (self::VARIANT_INTERNAL_FIELDS as $field) {
            self::assertArrayNotHasKey($field, $variant, "show leaks variant.{$field}");
        }

        // The raw metadata blob (app-internal channel) never leaves; the body
        // string check catches leaks through any nested/derived path too.
        self::assertStringNotContainsString('internal_note', json_encode($product, JSON_THROW_ON_ERROR));
    }

    public function testShowExternalProductStillDerivesExternalPayloadFromMetadata(): void
    {
        $this->seedProduct('projprod0003', [
            'slug' => 'proj-ext',
            'type' => 'external',
            'metadata' => '{"external_url":"https://example.com/buy","button_label":"Buy"}',
        ]);

        $body = $this->json($this->controller()->show(Request::create('/x'), 'proj-ext'));
        $product = $body['data'];

        self::assertSame('https://example.com/buy', $product['external']['url']);
        self::assertSame('Buy', $product['external']['button_label']);
        self::assertArrayNotHasKey('metadata', $product);
    }

    /** @param array<string,mixed> $overrides */
    private function seedProduct(string $uuid, array $overrides = []): void
    {
        $this->connection->table('commerce_products')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'slug' => 'slug-' . $uuid,
            'name' => 'Projected',
            'type' => 'physical',
            'status' => 'active',
        ], $overrides));
    }

    private function seedVariant(string $uuid, string $productUuid): void
    {
        $this->connection->table('commerce_variants')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'product_uuid' => $productUuid,
            'sku' => $uuid,
            'option_values' => '[]',
            'price' => 500,
            'currency' => 'USD',
            'status' => 'active',
        ]);
    }

    private function controller(): ProductController
    {
        return new ProductController(
            $this->context,
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new ProductMediaRepository(),
            new CategoryRepository(),
            new TagRepository(),
            new AttributeRepository(),
            new ProductChildrenRepository(),
            new AddonRepository()
        );
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
