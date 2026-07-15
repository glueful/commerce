<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminMediaController;
use Glueful\Extensions\Commerce\Http\DTOs\AttachMediaData;
use Glueful\Extensions\Commerce\Http\DTOs\ProductListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\ReorderMediaData;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Repository\BlobRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class MediaEndpointTest extends CommerceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The framework's blobs table (core uploads/storage) is not part of the
        // commerce migration set; require it directly from the vendored framework
        // copy and create it on this test's in-memory connection.
        require_once __DIR__ . '/../../../vendor/glueful/framework/migrations/uploads/001_CreateBlobsTable.php';
        (new \Glueful\Migrations\Uploads\CreateBlobsTable())->up($this->connection->getSchemaBuilder());
    }

    public function testAttachHappyPathReturnsCreatedMediaRowAndBumpsCatalogRevision(): void
    {
        $this->seedProduct('prodmedia01');
        $this->seedBlob('blobpublic01');

        $response = $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobpublic01', role: 'gallery', alt: 'Front view'),
            Request::create('/x', 'POST'),
            'prodmedia01'
        );

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('blobpublic01', $data['blob_uuid']);
        self::assertSame('gallery', $data['role']);
        self::assertSame('Front view', $data['alt']);
        self::assertSame('prodmedia01', $data['product_uuid']);
        self::assertNull($data['variant_uuid']);

        $product = $this->connection->table('commerce_products')
            ->where('uuid', '=', 'prodmedia01')->first();
        self::assertSame(1, (int) $product['catalog_revision']);
    }

    public function testAttachWithVariantEchoesVariantUuid(): void
    {
        $this->seedProduct('prodmedia02');
        $this->seedVariant('variantmed01', 'prodmedia02');
        $this->seedBlob('blobpublic02');

        $response = $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobpublic02', variant_uuid: 'variantmed01'),
            Request::create('/x', 'POST'),
            'prodmedia02'
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('variantmed01', $this->json($response)['data']['variant_uuid']);
    }

    public function testAttachUnknownProductThrowsNotFound(): void
    {
        $this->seedBlob('blobpublic03');

        $this->expectException(NotFoundException::class);
        $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobpublic03'),
            Request::create('/x', 'POST'),
            'no-such-product'
        );
    }

    public function testAttachCrossTenantProductThrowsNotFound(): void
    {
        $this->seedProduct('prodtenantb1', 'tenant-b');
        $this->seedBlob('blobpublic04');

        $this->expectException(NotFoundException::class);
        $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobpublic04'),
            Request::create('/x', 'POST'),
            'prodtenantb1'
        );
    }

    public function testAttachMissingBlobReturns422(): void
    {
        $this->seedProduct('prodmedia03');

        $response = $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'no-such-blob'),
            Request::create('/x', 'POST'),
            'prodmedia03'
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('blob_uuid', $this->json($response)['error']['details']);
    }

    public function testAttachNonActiveBlobReturns422(): void
    {
        $this->seedProduct('prodmedia04');
        $this->seedBlob('blobinactive1', status: 'deleted');

        $response = $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobinactive1'),
            Request::create('/x', 'POST'),
            'prodmedia04'
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('blob_uuid', $this->json($response)['error']['details']);
    }

    public function testAttachNonPublicBlobReturns422(): void
    {
        $this->seedProduct('prodmedia05');
        $this->seedBlob('blobprivate01', visibility: 'private');

        $response = $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobprivate01'),
            Request::create('/x', 'POST'),
            'prodmedia05'
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('blob_uuid', $this->json($response)['error']['details']);
    }

    public function testAttachWithoutBlobsSubsystemBoundReturns422(): void
    {
        $this->seedProduct('prodmedia06');

        $controller = new AdminMediaController($this->context, $this->mediaService(withBlobs: false));
        $response = $controller->attach(
            new AttachMediaData(blob_uuid: 'anything'),
            Request::create('/x', 'POST'),
            'prodmedia06'
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'blobs subsystem',
            $this->json($response)['error']['details']['blob_uuid']
        );
    }

    public function testAttachVariantBelongingToAnotherProductIsNonRevealing422(): void
    {
        $this->seedProduct('prodmediaa1');
        $this->seedProduct('prodmediaa2');
        $this->seedVariant('variantoth1', 'prodmediaa2');
        $this->seedBlob('blobpublic06');

        $response = $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobpublic06', variant_uuid: 'variantoth1'),
            Request::create('/x', 'POST'),
            'prodmediaa1'
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('variant_uuid', $this->json($response)['error']['details']);
    }

    public function testVariantValidationFailureMessageIsIdenticalRegardlessOfCause(): void
    {
        $this->seedProduct('prodmediac1');
        $this->seedProduct('prodmediac2');
        $this->seedProduct('prodmediac3', 'tenant-b');
        $this->seedVariant('variantoth2', 'prodmediac2');
        $this->seedVariant('variantoth3', 'prodmediac3', 'tenant-b');
        $this->seedBlob('blobpublic07');
        $this->seedBlob('blobpublic08');
        $this->seedBlob('blobpublic09');

        // Cause 1: variant simply does not exist.
        $unknown = $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobpublic07', variant_uuid: 'no-such-variant'),
            Request::create('/x', 'POST'),
            'prodmediac1'
        );
        // Cause 2: variant exists but belongs to a different product (same tenant).
        $otherProduct = $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobpublic08', variant_uuid: 'variantoth2'),
            Request::create('/x', 'POST'),
            'prodmediac1'
        );
        // Cause 3: variant exists but belongs to another tenant entirely.
        $otherTenant = $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobpublic09', variant_uuid: 'variantoth3'),
            Request::create('/x', 'POST'),
            'prodmediac1'
        );

        self::assertSame(422, $unknown->getStatusCode());
        self::assertSame(422, $otherProduct->getStatusCode());
        self::assertSame(422, $otherTenant->getStatusCode());

        $message = $this->json($unknown)['error']['details']['variant_uuid'];
        self::assertSame($message, $this->json($otherProduct)['error']['details']['variant_uuid']);
        self::assertSame($message, $this->json($otherTenant)['error']['details']['variant_uuid']);
    }

    public function testAttachDuplicateBlobOnSameProductReturns422(): void
    {
        $this->seedProduct('prodmedia07');
        $this->seedBlob('blobdup000001');

        $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobdup000001'),
            Request::create('/x', 'POST'),
            'prodmedia07'
        );
        $second = $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobdup000001'),
            Request::create('/x', 'POST'),
            'prodmedia07'
        );

        self::assertSame(422, $second->getStatusCode());
        self::assertArrayHasKey('blob_uuid', $this->json($second)['error']['details']);
        self::assertSame(1, $this->connection->table('commerce_product_media')
            ->where('product_uuid', '=', 'prodmedia07')->count());
    }

    public function testAttachingSecondCoverDemotesFirstToGalleryExactlyOneCoverSurvives(): void
    {
        $this->seedProduct('prodmedia08');
        $this->seedBlob('blobcovera001');
        $this->seedBlob('blobcoverb001');

        $first = $this->json($this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobcovera001', role: 'cover'),
            Request::create('/x', 'POST'),
            'prodmedia08'
        ))['data'];
        self::assertSame('cover', $first['role']);

        $second = $this->json($this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobcoverb001', role: 'cover'),
            Request::create('/x', 'POST'),
            'prodmedia08'
        ))['data'];
        self::assertSame('cover', $second['role']);

        $rows = $this->connection->table('commerce_product_media')
            ->where('product_uuid', '=', 'prodmedia08')->get();
        $covers = array_values(array_filter($rows, static fn (array $r): bool => $r['role'] === 'cover'));
        self::assertCount(1, $covers);
        self::assertSame('blobcoverb001', $covers[0]['blob_uuid']);

        $demoted = (new ProductMediaRepository())->findByUuid($this->context, '', (string) $first['uuid']);
        self::assertSame('gallery', $demoted['role']);
    }

    public function testUpdateRoleChangeToCoverDemotesPreviousCover(): void
    {
        $this->seedProduct('prodmedia09');
        $this->seedBlob('blobg00000001');
        $this->seedBlob('blobg00000002');

        $first = $this->json($this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobg00000001', role: 'cover'),
            Request::create('/x', 'POST'),
            'prodmedia09'
        ))['data'];
        $second = $this->json($this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobg00000002', role: 'gallery'),
            Request::create('/x', 'POST'),
            'prodmedia09'
        ))['data'];

        $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode(['role' => 'cover'], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        $updated = $this->controller()->update($request, (string) $second['uuid']);

        self::assertSame(200, $updated->getStatusCode());
        self::assertSame('cover', $this->json($updated)['data']['role']);

        $firstRow = (new ProductMediaRepository())->findByUuid($this->context, '', (string) $first['uuid']);
        self::assertSame('gallery', $firstRow['role']);
    }

    public function testUpdateAltAndPosition(): void
    {
        $this->seedProduct('prodmedia13');
        $this->seedBlob('blobupdate001');
        $created = $this->json($this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobupdate001'),
            Request::create('/x', 'POST'),
            'prodmedia13'
        ))['data'];

        $request = Request::create(
            '/x',
            'PATCH',
            [],
            [],
            [],
            [],
            json_encode(['alt' => 'Updated alt', 'position' => 5], JSON_THROW_ON_ERROR)
        );
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->controller()->update($request, (string) $created['uuid']);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('Updated alt', $data['alt']);
        self::assertSame(5, (int) $data['position']);
    }

    public function testUpdateUnknownMediaThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->update(
            Request::create('/x', 'PATCH', [], [], [], [], json_encode(['alt' => 'x'], JSON_THROW_ON_ERROR)),
            'no-such-media'
        );
    }

    public function testDetachRemovesMediaRow(): void
    {
        $this->seedProduct('prodmedia10');
        $this->seedBlob('blobdetach001');
        $created = $this->json($this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobdetach001'),
            Request::create('/x', 'POST'),
            'prodmedia10'
        ))['data'];

        $response = $this->controller()->detach(Request::create('/x', 'DELETE'), (string) $created['uuid']);

        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull((new ProductMediaRepository())->findByUuid($this->context, '', (string) $created['uuid']));
    }

    public function testDetachUnknownMediaThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->detach(Request::create('/x', 'DELETE'), 'no-such-media');
    }

    public function testReorderAppliesPositions(): void
    {
        $this->seedProduct('prodmedia11');
        $this->seedBlob('blobreorder01');
        $this->seedBlob('blobreorder02');

        $a = $this->json($this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobreorder01'),
            Request::create('/x', 'POST'),
            'prodmedia11'
        ))['data'];
        $b = $this->json($this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobreorder02'),
            Request::create('/x', 'POST'),
            'prodmedia11'
        ))['data'];

        $response = $this->controller()->reorder(
            new ReorderMediaData(positions: [
                ['uuid' => $b['uuid'], 'position' => 0],
                ['uuid' => $a['uuid'], 'position' => 1],
            ]),
            Request::create('/x', 'PUT'),
            'prodmedia11'
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame($b['uuid'], $data[0]['uuid']);
        self::assertSame(0, (int) $data[0]['position']);
        self::assertSame($a['uuid'], $data[1]['uuid']);
        self::assertSame(1, (int) $data[1]['position']);
    }

    public function testReorderUnknownMediaUuidReturns422(): void
    {
        $this->seedProduct('prodmedia12');

        $response = $this->controller()->reorder(
            new ReorderMediaData(positions: [['uuid' => 'no-such-media', 'position' => 0]]),
            Request::create('/x', 'PUT'),
            'prodmedia12'
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testReorderUnknownProductThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->reorder(
            new ReorderMediaData(positions: [['uuid' => 'x', 'position' => 0]]),
            Request::create('/x', 'PUT'),
            'no-such-product'
        );
    }

    public function testStorefrontShowReturnsCoverFirstThenGalleryByPositionWithPathUrls(): void
    {
        $this->seedProduct('prodstorefr1');
        $this->seedBlob('blobcover0001');
        $this->seedBlob('blobgallery01');
        $this->seedBlob('blobgallery02');
        $this->seedVariant('variantstore1', 'prodstorefr1');

        // Attach gallery items first (positions 0, 1), then the cover (position 2) —
        // the cover must still sort first in the projection.
        $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobgallery02'),
            Request::create('/x', 'POST'),
            'prodstorefr1'
        );
        $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobgallery01', variant_uuid: 'variantstore1'),
            Request::create('/x', 'POST'),
            'prodstorefr1'
        );
        $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobcover0001', role: 'cover'),
            Request::create('/x', 'POST'),
            'prodstorefr1'
        );

        $response = $this->productController()->show(Request::create('/commerce/products/prodstorefr1'), 'prodstorefr1');

        self::assertSame(200, $response->getStatusCode());
        $media = $this->json($response)['data']['media'];
        self::assertCount(3, $media);

        self::assertSame('blobcover0001', $media[0]['blob_uuid']);
        self::assertSame('cover', $media[0]['role']);
        self::assertSame('/blobs/blobcover0001', $media[0]['url']);

        self::assertSame('blobgallery02', $media[1]['blob_uuid']);
        self::assertSame('blobgallery01', $media[2]['blob_uuid']);
        self::assertSame('variantstore1', $media[2]['variant_uuid']);
        self::assertNull($media[1]['variant_uuid']);
    }

    public function testStorefrontIndexItemsIncludeCoverUrl(): void
    {
        $this->seedProduct('prodstorefr2');
        $this->seedBlob('blobcover0002');
        $this->controller()->attach(
            new AttachMediaData(blob_uuid: 'blobcover0002', role: 'cover'),
            Request::create('/x', 'POST'),
            'prodstorefr2'
        );
        $this->seedProduct('prodstorefr3');

        $response = $this->productController()->index(new ProductListQuery());

        self::assertSame(200, $response->getStatusCode());
        $byUuid = [];
        foreach ($this->json($response)['data'] as $item) {
            $byUuid[$item['uuid']] = $item;
        }

        self::assertSame('/blobs/blobcover0002', $byUuid['prodstorefr2']['cover_url']);
        self::assertNull($byUuid['prodstorefr3']['cover_url']);
    }

    /** @return array<string,mixed> */
    private function seedProduct(string $uuid, string $tenant = '', string $type = 'physical'): array
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'type' => $type,
            'status' => 'active',
        ]);

        $product = (new ProductRepository())->findByUuid($this->context, $tenant, $uuid);
        self::assertNotNull($product);

        return $product;
    }

    private function seedBlob(string $uuid, string $status = 'active', string $visibility = 'public'): void
    {
        $this->connection->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => $uuid,
            'mime_type' => 'image/png',
            'size' => 100,
            'url' => '/storage/' . $uuid,
            'storage_type' => 'local',
            'visibility' => $visibility,
            'status' => $status,
            'created_by' => 'uploader00001',
        ]);
    }

    private function seedVariant(string $uuid, string $productUuid, string $tenant = ''): void
    {
        $this->connection->table('commerce_variants')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'sku' => $uuid,
            'option_values' => '[]',
            'price' => 1000,
            'currency' => 'USD',
            'status' => 'active',
        ]);
    }

    private function controller(): AdminMediaController
    {
        return new AdminMediaController($this->context, $this->mediaService());
    }

    private function mediaService(bool $withBlobs = true): ProductMediaService
    {
        return new ProductMediaService(
            new ProductRepository(),
            new VariantRepository(),
            new ProductMediaRepository(),
            new SentinelTenantResolver(),
            $withBlobs ? new BlobRepository($this->connection) : null
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

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
