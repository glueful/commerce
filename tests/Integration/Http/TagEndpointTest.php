<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\TagService;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminTagController;
use Glueful\Extensions\Commerce\Http\DTOs\CreateTagData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductTagsData;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class TagEndpointTest extends CommerceTestCase
{
    public function testCreateTagHappyPath(): void
    {
        $response = $this->controller()->store(
            new CreateTagData(slug: 'summer', name: 'Summer'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('summer', $data['slug']);
        self::assertSame('Summer', $data['name']);
        self::assertSame(0, (int) $data['revision']);
    }

    public function testCreateDuplicateSlugSameTenantReturns422(): void
    {
        $this->createTag('summer', 'Summer');

        $response = $this->controller()->store(
            new CreateTagData(slug: 'summer', name: 'Summer Duplicate'),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('slug', $this->json($response)['error']['details']);
    }

    public function testCreateSameSlugDifferentTenantSucceeds(): void
    {
        $this->createTag('summer', 'Summer', 'tenant-b');

        $response = $this->controller()->store(
            new CreateTagData(slug: 'summer', name: 'Summer'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
    }

    public function testIndexListsAllTagsForTenant(): void
    {
        $this->createTag('summer', 'Summer');
        $this->createTag('sale', 'Sale');
        $this->createTag('other-tenant-tag', 'Other', 'tenant-b');

        $response = $this->controller()->index(Request::create('/x'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $this->json($response)['data']);
    }

    public function testDeleteDetachesProductsThenDeletesTag(): void
    {
        $tag = $this->createTag('summer', 'Summer');
        $product = $this->seedProduct('prodtag000001');
        $this->assignTag($product['uuid'], $tag['uuid']);

        $response = $this->controller()->destroy(Request::create('/x', 'DELETE'), $tag['uuid']);

        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull((new TagRepository())->findByUuid($this->context, '', $tag['uuid']));
        self::assertSame([], (new TagRepository())->tagUuidsForProduct($this->context, $product['uuid']));
    }

    public function testDeleteUnknownTagThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), 'no-such-tag');
    }

    public function testDeleteCrossTenantTagThrowsNotFound(): void
    {
        $tag = $this->createTag('summer', 'Summer', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), $tag['uuid']);
    }

    public function testSetProductTagsAttachesAndIsIdempotent(): void
    {
        $product = $this->seedProduct('prodtag000002');
        $a = $this->createTag('summer', 'Summer');
        $b = $this->createTag('sale', 'Sale');

        $first = $this->controller()->setForProduct(
            new SetProductTagsData(tag_uuids: [$a['uuid'], $b['uuid']]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );
        self::assertSame(200, $first->getStatusCode());
        self::assertCount(2, $this->json($first)['data']);

        $before = $this->connection->table('commerce_product_tags')
            ->where('product_uuid', '=', $product['uuid'])
            ->get();

        $second = $this->controller()->setForProduct(
            new SetProductTagsData(tag_uuids: [$a['uuid'], $b['uuid']]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );
        self::assertSame(200, $second->getStatusCode());

        $after = $this->connection->table('commerce_product_tags')
            ->where('product_uuid', '=', $product['uuid'])
            ->get();
        self::assertSame($before, $after);
    }

    public function testSetProductTagsRemovesUnlistedTags(): void
    {
        $product = $this->seedProduct('prodtag000003');
        $a = $this->createTag('summer', 'Summer');
        $b = $this->createTag('sale', 'Sale');

        $this->controller()->setForProduct(
            new SetProductTagsData(tag_uuids: [$a['uuid'], $b['uuid']]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        $response = $this->controller()->setForProduct(
            new SetProductTagsData(tag_uuids: [$a['uuid']]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $uuids = array_column($this->json($response)['data'], 'uuid');
        self::assertSame([$a['uuid']], $uuids);
    }

    public function testSetProductTagsUnknownTagReturns422(): void
    {
        $product = $this->seedProduct('prodtag000004');

        $response = $this->controller()->setForProduct(
            new SetProductTagsData(tag_uuids: ['no-such-tag']),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('tag_uuids', $this->json($response)['error']['details']);
    }

    public function testSetProductTagsUnknownProductThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->setForProduct(
            new SetProductTagsData(tag_uuids: []),
            Request::create('/x', 'PUT'),
            'no-such-product'
        );
    }

    public function testSetProductTagsWithTagDeletedConcurrentlyReturns422(): void
    {
        // Deterministic stand-in for a tag-delete-vs-assignment race: the tag is
        // fully deleted (claim+detach+delete, one committed transaction) BEFORE the
        // assignment call runs, exactly as it would appear to a racing assignment
        // that loses the interleave. Assignment must resolve it as unknown, not
        // create a dangling join row.
        $product = $this->seedProduct('prodtag000005');
        $tag = $this->createTag('summer', 'Summer');
        $this->controller()->destroy(Request::create('/x', 'DELETE'), $tag['uuid']);

        $response = $this->controller()->setForProduct(
            new SetProductTagsData(tag_uuids: [$tag['uuid']]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], (new TagRepository())->tagUuidsForProduct($this->context, $product['uuid']));
    }

    public function testStorefrontShowEchoesTagsAsSlugAndName(): void
    {
        $product = $this->seedProduct('prodtagstore1');
        $a = $this->createTag('summer', 'Summer');
        $b = $this->createTag('sale', 'Sale');
        $this->assignTag($product['uuid'], $a['uuid']);
        $this->assignTag($product['uuid'], $b['uuid']);

        $response = $this->productController()->show(Request::create('/x'), $product['uuid']);

        self::assertSame(200, $response->getStatusCode());
        $tags = $this->json($response)['data']['tags'];
        self::assertCount(2, $tags);
        $slugs = array_column($tags, 'slug');
        sort($slugs);
        self::assertSame(['sale', 'summer'], $slugs);
        self::assertArrayHasKey('name', $tags[0]);
        self::assertArrayNotHasKey('uuid', $tags[0]);
    }

    /** @return array<string,mixed> */
    private function createTag(string $slug, string $name, string $tenant = ''): array
    {
        $response = $this->controller($tenant)->store(
            new CreateTagData(slug: $slug, name: $name),
            Request::create('/x', 'POST')
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response)['data'];
    }

    private function assignTag(string $productUuid, string $tagUuid): void
    {
        $this->connection->table('commerce_product_tags')->insert([
            'product_uuid' => $productUuid,
            'tag_uuid' => $tagUuid,
        ]);
    }

    /** @return array<string,mixed> */
    private function seedProduct(string $uuid, string $tenant = ''): array
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'type' => 'physical',
            'status' => 'active',
        ]);

        $product = (new ProductRepository())->findByUuid($this->context, $tenant, $uuid);
        self::assertNotNull($product);

        return $product;
    }

    private function controller(string $tenant = ''): AdminTagController
    {
        return new AdminTagController($this->context, $this->tagService($tenant));
    }

    private function tagService(string $tenant = ''): TagService
    {
        return new TagService(
            new TagRepository(),
            new ProductRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant)
        );
    }

    private function productController(): ProductController
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

    private function fixedTenant(string $tenant): CurrentTenantResolver
    {
        return new class ($tenant) implements CurrentTenantResolver {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
