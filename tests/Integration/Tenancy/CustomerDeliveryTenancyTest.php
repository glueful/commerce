<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\DownloadService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Customers\AddressBookRepository;
use Glueful\Extensions\Commerce\Customers\AddressBookService;
use Glueful\Extensions\Commerce\Http\Admin\AdminGrantController;
use Glueful\Extensions\Commerce\Http\Storefront\DownloadLinkController;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadAccessService;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadUrlSigner;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Repository\BlobRepository;
use Glueful\Uploader\Contracts\BlobPublicUrlProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Layer-3 two-tenant sweep for the four customer-delivery tables (address books,
 * addresses, downloads, download grants). Follows the `tenantAAAA01`/`tenantBBBB02`
 * fixed-resolver convention established by `RefundTenancyTest`/`CatalogBreadthTenancyTest`.
 * The exact-list registry/adopter assertion for all twenty tenant tables already lives
 * in `CatalogBreadthTenancyTest::testDiagnosticsReportAndAdopterCoverAllSixCatalogBreadthTables()`
 * (grew by these same four in migration 008); this file adds dedicated adopter-sentinel
 * coverage for the four Layer-3 tables specifically, mirroring
 * `RefundTenancyTest::testDiagnosticsAndAdopterCoverCommerceRefunds()`'s own per-layer style.
 *
 * The flagship case here is the email deep link's token-correlation contract
 * ({@see DownloadGrantRepository::findByTokenHashGlobal()}'s class docblock):
 * `DownloadLinkController` resolves EVERY tenant purely from the matched grant row's
 * own `tenant_uuid` column -- it has no `CurrentTenantResolver` dependency at all -- so
 * a tenant-A token presented while the request itself arrives under a tenant-B host
 * must still serve ONLY tenant-A-scoped data, and the resulting signed-URL redirect
 * must use tenant A's OWN provider-derived public host, never tenant B's (or the
 * ambient request host).
 */
final class CustomerDeliveryTenancyTest extends CommerceTestCase
{
    private const TENANT_A = 'tenantAAAA01';
    private const TENANT_B = 'tenantBBBB02';

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../vendor/glueful/framework/migrations/uploads/001_CreateBlobsTable.php';
        (new \Glueful\Migrations\Uploads\CreateBlobsTable())->up($this->connection->getSchemaBuilder());
    }

    // -----------------------------------------------------------------
    // Address books / addresses
    // -----------------------------------------------------------------

    public function testTenantBCannotUpdateOrDeleteTenantAsAddress(): void
    {
        $userUuid = 'usertendlv01';
        $created = $this->addressService(self::TENANT_A)->create($this->context, $userUuid, [
            'address' => ['country' => 'US'],
        ]);

        try {
            $this->addressService(self::TENANT_B)->update(
                $this->context,
                $userUuid,
                (string) $created['uuid'],
                ['label' => 'sneaky']
            );
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->addressService(self::TENANT_B)->delete($this->context, $userUuid, (string) $created['uuid']);
            self::fail('expected NotFoundException');
        } catch (NotFoundException) {
            $survivor = (new AddressBookRepository())
                ->findByUuid($this->context, self::TENANT_A, $userUuid, (string) $created['uuid']);
            self::assertNotNull($survivor, "Tenant A's address must survive tenant B's rejected delete.");
        }
    }

    // -----------------------------------------------------------------
    // Download definitions (admin)
    // -----------------------------------------------------------------

    public function testTenantBCannotUpdateOrDetachTenantAsDownloadDefinition(): void
    {
        $this->seedProduct(self::TENANT_A, 'prodtendlv01', 'digital');
        $this->seedVariant(self::TENANT_A, 'vartendlv001', 'prodtendlv01');
        $this->seedBlob('blobtendlv01');

        $download = $this->downloadService(self::TENANT_A)->attach($this->context, 'vartendlv001', [
            'blob_uuid' => 'blobtendlv01',
            'name' => 'Manual.pdf',
        ]);

        $this->expectException(NotFoundException::class);
        $this->downloadService(self::TENANT_B)->update(
            $this->context,
            (string) $download['uuid'],
            ['name' => 'sneaky']
        );
    }

    public function testTenantBCannotDetachTenantAsDownloadDefinition(): void
    {
        $this->seedProduct(self::TENANT_A, 'prodtendlv02', 'digital');
        $this->seedVariant(self::TENANT_A, 'vartendlv002', 'prodtendlv02');
        $this->seedBlob('blobtendlv02');

        $download = $this->downloadService(self::TENANT_A)->attach($this->context, 'vartendlv002', [
            'blob_uuid' => 'blobtendlv02',
            'name' => 'Manual.pdf',
        ]);

        try {
            $this->downloadService(self::TENANT_B)->detach($this->context, (string) $download['uuid']);
            self::fail('expected NotFoundException');
        } catch (NotFoundException) {
            self::assertNotNull(
                (new DownloadRepository())
                    ->findByUuid($this->context, self::TENANT_A, (string) $download['uuid']),
                "Tenant A's download definition must survive tenant B's rejected detach."
            );
        }
    }

    // -----------------------------------------------------------------
    // Grants (admin)
    // -----------------------------------------------------------------

    public function testTenantBCannotRevokeOrOverrideTenantAsGrant(): void
    {
        $grantUuid = $this->seedGrant(self::TENANT_A, 'ordtendlv001', 'blobtendlv03', remaining: 5);

        try {
            $this->grantController(self::TENANT_B)->revoke(Request::create('/x', 'POST'), $grantUuid);
            self::fail('expected NotFoundException');
        } catch (NotFoundException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->grantController(self::TENANT_B)->setOverride(Request::create('/x', 'POST'), $grantUuid);
            self::fail('expected NotFoundException');
        } catch (NotFoundException) {
            $grant = (new DownloadGrantRepository())->findByUuid($this->context, self::TENANT_A, $grantUuid);
            self::assertNotNull($grant);
            self::assertNull($grant['revoked_at'], "Tenant A's grant must be untouched by tenant B's rejected calls.");
            self::assertNull($grant['refund_access_override_at']);
        }
    }

    // -----------------------------------------------------------------
    // Token-correlation deep link (flagship)
    // -----------------------------------------------------------------

    public function testTokenCorrelatedDeepLinkServesOnlyTenantAsGrantAndUsesItsOwnProviderHost(): void
    {
        $this->context->overrideConfig('app.key', 'test-signing-secret-0123456789ab');

        $this->seedBlob('blobtendlv0a');
        $this->seedBlob('blobtendlv0b');

        [$rawTokenA, $grantUuidA] = $this->seedGrantWithRawToken(
            self::TENANT_A,
            'ordtendlva01',
            'blobtendlv0a',
            remaining: 5
        );
        [, $grantUuidB] = $this->seedGrantWithRawToken(self::TENANT_B, 'ordtendlvb01', 'blobtendlv0b', remaining: 5);

        // A provider that answers with a DIFFERENT host per blob -- standing in for a
        // real per-tenant public-host binding. The request itself arrives at what
        // would be tenant B's own host (e.g. a stale bookmark, or because the token
        // carries the only real identity): the correlation contract requires the
        // provider's answer for tenant A's OWN blob to win regardless.
        $provider = new class implements BlobPublicUrlProvider {
            public function publicBaseUrl(array $blob): ?string
            {
                return match ($blob['uuid']) {
                    'blobtendlv0a' => 'https://tenant-a.example.com',
                    'blobtendlv0b' => 'https://tenant-b.example.com',
                    default => null,
                };
            }
        };

        $controller = new DownloadLinkController(
            $this->context,
            new DownloadGrantRepository(),
            new DownloadAccessService(
                new OrderRepository(),
                new DownloadGrantRepository(),
                new DownloadUrlSigner(new BlobRepository($this->connection), $provider)
            )
        );

        $request = Request::create('http://tenant-b.example.com/commerce/downloads/x', 'GET');
        $response = $controller->show($request, $rawTokenA);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringStartsWith(
            'https://tenant-a.example.com/blobs/blobtendlv0a',
            (string) $response->getTargetUrl(),
            "Tenant A's token must redirect through tenant A's OWN provider-derived host, "
                . 'never the ambient tenant-B request host or tenant B\'s host.'
        );

        $grantA = (new DownloadGrantRepository())->findByUuid($this->context, self::TENANT_A, $grantUuidA);
        self::assertNotNull($grantA);
        self::assertSame(1, (int) $grantA['mint_count'], "Tenant A's own grant must be the one minted.");
        self::assertSame(4, (int) $grantA['remaining']);

        $grantB = (new DownloadGrantRepository())->findByUuid($this->context, self::TENANT_B, $grantUuidB);
        self::assertNotNull($grantB);
        self::assertSame(0, (int) $grantB['mint_count'], "Tenant B's grant must be completely untouched.");
        self::assertSame(5, (int) $grantB['remaining']);
    }

    // -----------------------------------------------------------------
    // Registry + adopter coverage.
    // -----------------------------------------------------------------

    public function testDiagnosticsAndAdopterCoverTheFourCustomerDeliveryTables(): void
    {
        foreach ([
            'commerce_customer_address_books',
            'commerce_customer_addresses',
            'commerce_downloads',
            'commerce_download_grants',
        ] as $table) {
            self::assertContains($table, DiagnosticsReport::tenantTables());
        }

        $sentinels = [
            'commerce_customer_address_books' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'user_uuid' => Utils::generateNanoID(),
                'revision' => 0,
            ],
            'commerce_customer_addresses' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'user_uuid' => Utils::generateNanoID(),
                'address' => json_encode(['country' => 'US'], JSON_THROW_ON_ERROR),
            ],
            'commerce_downloads' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'variant_uuid' => Utils::generateNanoID(),
                'blob_uuid' => Utils::generateNanoID(),
                'name' => 'Sentinel.pdf',
            ],
            'commerce_download_grants' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'order_uuid' => Utils::generateNanoID(),
                'download_uuid' => Utils::generateNanoID(),
                'blob_uuid' => Utils::generateNanoID(),
                'name' => 'Sentinel.pdf',
                'token_hash' => hash('sha256', 'sentinel-' . Utils::generateNanoID()),
            ],
        ];

        foreach ($sentinels as $table => $row) {
            $this->connection->table($table)->insert($row);
        }

        $result = (new TenantAdopter())->adopt($this->context, self::TENANT_A);

        foreach ($sentinels as $table => $row) {
            self::assertArrayHasKey($table, $result['tables']);
            self::assertSame(1, $result['tables'][$table], "Adopter should have found exactly 1 sentinel row in {$table}.");

            $adopted = $this->connection->table($table)->where('uuid', '=', $row['uuid'])->first();
            self::assertNotNull($adopted);
            self::assertSame(self::TENANT_A, $adopted['tenant_uuid']);
        }
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function addressService(string $tenant): AddressBookService
    {
        return new AddressBookService(new AddressBookRepository(), $this->fixedTenant($tenant));
    }

    private function downloadService(string $tenant): DownloadService
    {
        return new DownloadService(
            new ProductRepository(),
            new VariantRepository(),
            new DownloadRepository(),
            $this->fixedTenant($tenant),
            new BlobRepository($this->connection)
        );
    }

    private function grantController(string $tenant): AdminGrantController
    {
        return new AdminGrantController(
            $this->context,
            new DownloadGrantRepository(),
            new OrderRepository(),
            $this->fixedTenant($tenant)
        );
    }

    private function seedProduct(string $tenant, string $uuid, string $type): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'type' => $type,
            'status' => 'active',
        ]);
    }

    private function seedVariant(string $tenant, string $uuid, string $productUuid): void
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

    private function seedBlob(string $uuid): void
    {
        $this->connection->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => $uuid,
            'mime_type' => 'application/pdf',
            'size' => 100,
            'url' => '/storage/' . $uuid,
            'storage_type' => 'local',
            'visibility' => 'private',
            'status' => 'active',
            'created_by' => 'uploader0001',
        ]);
    }

    private function seedGrant(string $tenant, string $orderUuid, string $blobUuid, ?int $remaining): string
    {
        [, $grantUuid] = $this->seedGrantWithRawToken($tenant, $orderUuid, $blobUuid, $remaining);

        return $grantUuid;
    }

    /** @return array{0: string, 1: string} [rawToken, grantUuid] */
    private function seedGrantWithRawToken(
        string $tenant,
        string $orderUuid,
        string $blobUuid,
        ?int $remaining
    ): array {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $orderUuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $orderUuid,
            'status' => 'paid',
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);

        $token = TokenHasher::generate();
        $grantUuid = Utils::generateNanoID();
        $this->connection->table('commerce_download_grants')->insert([
            'uuid' => $grantUuid,
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'download_uuid' => Utils::generateNanoID(),
            'blob_uuid' => $blobUuid,
            'name' => 'Sentinel.pdf',
            'token_hash' => $token['hash'],
            'remaining' => $remaining,
        ]);

        return [$token['raw'], $grantUuid];
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
}
