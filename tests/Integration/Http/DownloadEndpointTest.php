<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\DownloadService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminDownloadController;
use Glueful\Extensions\Commerce\Http\DTOs\CreateDownloadData;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Repository\BlobRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Admin downloads CRUD (design spec §2/§8): variant-scoped attach requires an
 * in-tenant variant belonging to a digital-type product and an existing,
 * active, PRIVATE blob (the INVERSE of {@see MediaEndpointTest}, which
 * requires public). Mutations claim the product's `catalog_revision` by
 * resolving THROUGH the variant; detach never touches the underlying blob.
 */
final class DownloadEndpointTest extends CommerceTestCase
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

    public function testAttachHappyPathReturnsCreatedDownloadRowAndBumpsCatalogRevision(): void
    {
        $this->seedProduct('proddl000001', type: 'digital');
        $this->seedVariant('vardl0000001', 'proddl000001');
        $this->seedBlob('blobprivate01');

        $response = $this->controller()->attach(
            new CreateDownloadData(blob_uuid: 'blobprivate01', name: 'Manual.pdf'),
            Request::create('/x', 'POST'),
            'vardl0000001'
        );

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('blobprivate01', $data['blob_uuid']);
        self::assertSame('Manual.pdf', $data['name']);
        self::assertSame('vardl0000001', $data['variant_uuid']);
        self::assertNull($data['download_limit']);
        self::assertNull($data['expiry_days']);
        self::assertSame(0, (int) $data['position']);
        self::assertSame('active', $data['status']);

        $product = $this->connection->table('commerce_products')
            ->where('uuid', '=', 'proddl000001')->first();
        self::assertSame(1, (int) $product['catalog_revision']);
    }

    public function testAttachWithLimitExpiryAndPositionEchoesThemExactly(): void
    {
        $this->seedProduct('proddl000002', type: 'digital');
        $this->seedVariant('vardl0000002', 'proddl000002');
        $this->seedBlob('blobprivate02');

        $response = $this->controller()->attach(
            new CreateDownloadData(
                blob_uuid: 'blobprivate02',
                name: 'Ebook.epub',
                download_limit: 5,
                expiry_days: 30,
                position: 2
            ),
            Request::create('/x', 'POST'),
            'vardl0000002'
        );

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame(5, (int) $data['download_limit']);
        self::assertSame(30, (int) $data['expiry_days']);
        self::assertSame(2, (int) $data['position']);
    }

    public function testAttachUnknownVariantThrowsNotFound(): void
    {
        $this->seedBlob('blobprivate03');

        $this->expectException(NotFoundException::class);
        $this->controller()->attach(
            new CreateDownloadData(blob_uuid: 'blobprivate03', name: 'File.zip'),
            Request::create('/x', 'POST'),
            'no-such-variant'
        );
    }

    public function testAttachCrossTenantVariantThrowsNotFound(): void
    {
        $this->seedProduct('proddltenb01', 'tenant-b', type: 'digital');
        $this->seedVariant('vardltenb001', 'proddltenb01', 'tenant-b');
        $this->seedBlob('blobprivate04');

        $this->expectException(NotFoundException::class);
        $this->controller()->attach(
            new CreateDownloadData(blob_uuid: 'blobprivate04', name: 'File.zip'),
            Request::create('/x', 'POST'),
            'vardltenb001'
        );
    }

    public function testAttachNonDigitalVariantReturns422(): void
    {
        $this->seedProduct('prodphys0001', type: 'physical');
        $this->seedVariant('varphys00001', 'prodphys0001');
        $this->seedBlob('blobprivate05');

        $response = $this->controller()->attach(
            new CreateDownloadData(blob_uuid: 'blobprivate05', name: 'File.zip'),
            Request::create('/x', 'POST'),
            'varphys00001'
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('variant_uuid', $this->json($response)['error']['details']);

        // Rejected attach must never bump the product's catalog_revision.
        $product = $this->connection->table('commerce_products')
            ->where('uuid', '=', 'prodphys0001')->first();
        self::assertSame(0, (int) $product['catalog_revision']);
    }

    public function testAttachMissingBlobReturns422(): void
    {
        $this->seedProduct('proddl000003', type: 'digital');
        $this->seedVariant('vardl0000003', 'proddl000003');

        $response = $this->controller()->attach(
            new CreateDownloadData(blob_uuid: 'no-such-blob', name: 'File.zip'),
            Request::create('/x', 'POST'),
            'vardl0000003'
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('blob_uuid', $this->json($response)['error']['details']);
    }

    public function testAttachNonActiveBlobReturns422(): void
    {
        $this->seedProduct('proddl000004', type: 'digital');
        $this->seedVariant('vardl0000004', 'proddl000004');
        $this->seedBlob('blobinactive2', status: 'deleted');

        $response = $this->controller()->attach(
            new CreateDownloadData(blob_uuid: 'blobinactive2', name: 'File.zip'),
            Request::create('/x', 'POST'),
            'vardl0000004'
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('blob_uuid', $this->json($response)['error']['details']);
    }

    /**
     * The key inverse-of-media rule (design spec §2): a PUBLIC blob is rejected
     * for downloads, exactly the opposite of {@see MediaEndpointTest}'s
     * `testAttachNonPublicBlobReturns422()` -- merchandise must never be
     * publicly fetchable.
     */
    public function testAttachPublicBlobReturns422(): void
    {
        $this->seedProduct('proddl000005', type: 'digital');
        $this->seedVariant('vardl0000005', 'proddl000005');
        $this->seedBlob('blobpublicdl1', visibility: 'public');

        $response = $this->controller()->attach(
            new CreateDownloadData(blob_uuid: 'blobpublicdl1', name: 'File.zip'),
            Request::create('/x', 'POST'),
            'vardl0000005'
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('blob_uuid', $this->json($response)['error']['details']);
    }

    public function testAttachWithoutBlobsSubsystemBoundReturns422(): void
    {
        $this->seedProduct('proddl000006', type: 'digital');
        $this->seedVariant('vardl0000006', 'proddl000006');

        $controller = new AdminDownloadController($this->context, $this->downloadService(withBlobs: false));
        $response = $controller->attach(
            new CreateDownloadData(blob_uuid: 'anything', name: 'File.zip'),
            Request::create('/x', 'POST'),
            'vardl0000006'
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'blobs subsystem',
            $this->json($response)['error']['details']['blob_uuid']
        );
    }

    public function testAttachDuplicateBlobOnSameVariantReturns422(): void
    {
        $this->seedProduct('proddl000007', type: 'digital');
        $this->seedVariant('vardl0000007', 'proddl000007');
        $this->seedBlob('blobdupdl0001');

        $this->controller()->attach(
            new CreateDownloadData(blob_uuid: 'blobdupdl0001', name: 'File.zip'),
            Request::create('/x', 'POST'),
            'vardl0000007'
        );
        $second = $this->controller()->attach(
            new CreateDownloadData(blob_uuid: 'blobdupdl0001', name: 'File (dup).zip'),
            Request::create('/x', 'POST'),
            'vardl0000007'
        );

        self::assertSame(422, $second->getStatusCode());
        self::assertArrayHasKey('blob_uuid', $this->json($second)['error']['details']);
        self::assertSame(1, $this->connection->table('commerce_downloads')
            ->where('variant_uuid', '=', 'vardl0000007')->count());
    }

    public function testUpdateNameLimitExpiryPositionAndStatus(): void
    {
        $this->seedProduct('proddl000008', type: 'digital');
        $this->seedVariant('vardl0000008', 'proddl000008');
        $this->seedBlob('blobprivate08');
        $created = $this->json($this->controller()->attach(
            new CreateDownloadData(blob_uuid: 'blobprivate08', name: 'Original.pdf'),
            Request::create('/x', 'POST'),
            'vardl0000008'
        ))['data'];

        $response = $this->controller()->update(
            $this->patchRequest([
                'name' => 'Renamed.pdf',
                'download_limit' => 3,
                'expiry_days' => 7,
                'position' => 4,
                'status' => 'inactive',
            ]),
            (string) $created['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('Renamed.pdf', $data['name']);
        self::assertSame(3, (int) $data['download_limit']);
        self::assertSame(7, (int) $data['expiry_days']);
        self::assertSame(4, (int) $data['position']);
        self::assertSame('inactive', $data['status']);
    }

    public function testUpdateDownloadLimitExplicitNullSetsUnlimited(): void
    {
        $this->seedProduct('proddl000009', type: 'digital');
        $this->seedVariant('vardl0000009', 'proddl000009');
        $this->seedBlob('blobprivate09');
        $created = $this->json($this->controller()->attach(
            new CreateDownloadData(blob_uuid: 'blobprivate09', name: 'File.zip', download_limit: 5),
            Request::create('/x', 'POST'),
            'vardl0000009'
        ))['data'];
        self::assertSame(5, (int) $created['download_limit']);

        $response = $this->controller()->update(
            $this->patchRequest(['download_limit' => null]),
            (string) $created['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->json($response)['data']['download_limit']);
    }

    public function testUpdateUnknownDownloadThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['name' => 'x']), 'no-such-download');
    }

    public function testUpdateCrossTenantDownloadThrowsNotFound(): void
    {
        $this->seedProduct('proddltenb02', 'tenant-b', type: 'digital');
        $this->seedVariant('vardltenb002', 'proddltenb02', 'tenant-b');
        $this->connection->table('commerce_downloads')->insert([
            'uuid' => 'dltenantb0001',
            'tenant_uuid' => 'tenant-b',
            'variant_uuid' => 'vardltenb002',
            'blob_uuid' => 'blobwhatever1',
            'name' => 'File.zip',
        ]);

        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['name' => 'x']), 'dltenantb0001');
    }

    public function testDetachRemovesDownloadRowAndNeverTouchesTheBlob(): void
    {
        $this->seedProduct('proddl000010', type: 'digital');
        $this->seedVariant('vardl0000010', 'proddl000010');
        $this->seedBlob('blobprivate10');
        $created = $this->json($this->controller()->attach(
            new CreateDownloadData(blob_uuid: 'blobprivate10', name: 'File.zip'),
            Request::create('/x', 'POST'),
            'vardl0000010'
        ))['data'];

        $response = $this->controller()->detach(Request::create('/x', 'DELETE'), (string) $created['uuid']);

        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull((new DownloadRepository())->findByUuid($this->context, '', (string) $created['uuid']));

        // Detach never touches the blob: the blob row survives, untouched.
        $blob = $this->connection->table('blobs')->where('uuid', '=', 'blobprivate10')->first();
        self::assertNotNull($blob);
        self::assertSame('active', $blob['status']);
        self::assertSame('private', $blob['visibility']);
    }

    public function testDetachUnknownDownloadThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->detach(Request::create('/x', 'DELETE'), 'no-such-download');
    }

    public function testIndexListsDownloadsOrderedByPosition(): void
    {
        $this->seedProduct('proddl000011', type: 'digital');
        $this->seedVariant('vardl0000011', 'proddl000011');
        $this->seedBlob('blobprivate11');
        $this->seedBlob('blobprivate12');

        $first = $this->json($this->controller()->attach(
            new CreateDownloadData(blob_uuid: 'blobprivate11', name: 'First.zip', position: 5),
            Request::create('/x', 'POST'),
            'vardl0000011'
        ))['data'];
        $second = $this->json($this->controller()->attach(
            new CreateDownloadData(blob_uuid: 'blobprivate12', name: 'Second.zip', position: 1),
            Request::create('/x', 'POST'),
            'vardl0000011'
        ))['data'];

        $response = $this->controller()->index(Request::create('/x', 'GET'), 'vardl0000011');

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertCount(2, $data);
        self::assertSame($second['uuid'], $data[0]['uuid']);
        self::assertSame($first['uuid'], $data[1]['uuid']);
    }

    public function testIndexUnknownVariantReturnsEmptyListNonRevealing(): void
    {
        $response = $this->controller()->index(Request::create('/x', 'GET'), 'no-such-variant');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->json($response)['data']);
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

        $product = (new ProductRepository())->findLiveByUuid($this->context, $tenant, $uuid);
        self::assertNotNull($product);

        return $product;
    }

    private function seedVariant(string $uuid, string $productUuid, string $tenant = ''): void
    {
        $this->connection->table('commerce_variants')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'sku' => $uuid,
            'option_values' => '[]',
            'price' => 500,
            'currency' => 'USD',
            'status' => 'active',
        ]);
    }

    private function seedBlob(string $uuid, string $status = 'active', string $visibility = 'private'): void
    {
        $this->connection->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => $uuid,
            'mime_type' => 'application/pdf',
            'size' => 100,
            'url' => '/storage/' . $uuid,
            'storage_type' => 'local',
            'visibility' => $visibility,
            'status' => $status,
            'created_by' => 'uploader00001',
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function patchRequest(array $payload): Request
    {
        $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode($payload, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    private function controller(): AdminDownloadController
    {
        return new AdminDownloadController($this->context, $this->downloadService());
    }

    private function downloadService(bool $withBlobs = true): DownloadService
    {
        return new DownloadService(
            new ProductRepository(),
            new VariantRepository(),
            new DownloadRepository(),
            new SentinelTenantResolver(),
            $withBlobs ? new BlobRepository($this->connection) : null
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
