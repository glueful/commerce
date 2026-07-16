<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Downloads;

use Glueful\Extensions\Commerce\Http\Storefront\DownloadLinkController;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadAccessService;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadUrlSigner;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Repository\BlobRepository;
use Glueful\Support\SignedUrl;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Email deep-link access path (design spec §4.2): `GET /commerce/downloads/{token}`.
 * Public, single-credential, correlation-style token lookup (payvia precedent);
 * runs the SAME atomic mint primitive the order-authenticated path uses, but with
 * NO repair step -- an absent grant has no token to reach this controller with in
 * the first place, so this path can never heal a partially- or entirely-missing
 * grant set (unlike §4.1's two endpoints).
 */
final class DownloadDeepLinkTest extends CommerceTestCase
{
    private const RAW_TOKEN = 'deep-link-raw-token-0001';

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../vendor/glueful/framework/migrations/uploads/001_CreateBlobsTable.php';
        (new \Glueful\Migrations\Uploads\CreateBlobsTable())->up($this->connection->getSchemaBuilder());

        $this->context->overrideConfig('app.key', 'test-signing-secret-0123456789ab');
    }

    public function testValidTokenRedirectsWithValidatingSignedLocation(): void
    {
        $this->seedOrder('orderdeep001', 'paid');
        $this->seedBlob('blobdeep0001');
        $this->seedGrant('orderdeep001', 'grantdeep001', 'blobdeep0001', 'dldeep000001', self::RAW_TOKEN, remaining: 3);

        $response = $this->controller()->show(Request::create('/x', 'GET'), self::RAW_TOKEN);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(302, $response->getStatusCode());
        $location = $response->headers->get('Location');
        self::assertIsString($location);
        self::assertTrue(SignedUrl::make($this->context)->validate($location));

        $grant = $this->grantRow('grantdeep001');
        self::assertSame(2, (int) $grant['remaining']);
        self::assertSame(1, (int) $grant['mint_count']);
    }

    public function testConsumptionCountsMintsGrantRemainsUsableUntilExhausted(): void
    {
        $this->seedOrder('orderdeep002', 'paid');
        $this->seedBlob('blobdeep0002');
        $this->seedGrant('orderdeep002', 'grantdeep002', 'blobdeep0002', 'dldeep000002', self::RAW_TOKEN . '-2', remaining: 1);

        $first = $this->controller()->show(Request::create('/x', 'GET'), self::RAW_TOKEN . '-2');
        self::assertSame(302, $first->getStatusCode());

        $second = $this->controller()->show(Request::create('/x', 'GET'), self::RAW_TOKEN . '-2');
        self::assertSame(410, $second->getStatusCode());
        self::assertSame('exhausted', $this->json($second)['error']['details']['code']);
    }

    public function testBadTokenReturns404NonRevealing(): void
    {
        $response = $this->controller()->show(Request::create('/x', 'GET'), 'no-such-token-at-all');

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayNotHasKey('code', $this->json($response)['error']['details'] ?? []);
    }

    public function testRevokedGrantReturns410RevokedCodedJson(): void
    {
        $this->seedOrder('orderdeep003', 'paid');
        $this->seedBlob('blobdeep0003');
        $this->seedGrant(
            'orderdeep003',
            'grantdeep003',
            'blobdeep0003',
            'dldeep000003',
            self::RAW_TOKEN . '-3',
            remaining: 5,
            revokedAt: '2026-01-01 00:00:00'
        );

        $response = $this->controller()->show(Request::create('/x', 'GET'), self::RAW_TOKEN . '-3');

        self::assertSame(410, $response->getStatusCode());
        self::assertSame('revoked', $this->json($response)['error']['details']['code']);
    }

    /**
     * The defining asymmetry vs the order-authenticated path (design spec §4.2): the
     * token path NEVER heals. An order with a missing/partial grant set has no token
     * to reach here with for the missing download in the first place -- but even for
     * an EXISTING grant's token, the deep-link controller must never invoke
     * `ensureGrantsForOrder()`. Prove it by seeding an order whose line snapshot
     * describes a SECOND digital download that has no grant row at all, then
     * confirming a successful redemption of the first grant's token leaves that
     * second download's grant still entirely absent.
     */
    public function testTokenPathNeverHealsMissingGrants(): void
    {
        $this->seedOrder('orderdeep004', 'paid');
        $this->seedBlob('blobdeep0004a');
        $this->seedOrderLine('orderdeep004', [
            ['download_uuid' => 'dldeep0004a', 'blob_uuid' => 'blobdeep0004a', 'name' => 'A.pdf', 'download_limit' => null, 'expiry_days' => null],
            ['download_uuid' => 'dldeep0004b', 'blob_uuid' => 'blobdeep0004b', 'name' => 'B.pdf', 'download_limit' => null, 'expiry_days' => null],
        ]);
        // Only A ever got issued a grant (simulating a partially-issued order); B has none.
        $this->seedGrant('orderdeep004', 'grantdeep004', 'blobdeep0004a', 'dldeep0004a', self::RAW_TOKEN . '-4', remaining: 5);

        self::assertSame(1, $this->connection->table('commerce_download_grants')->count());

        $response = $this->controller()->show(Request::create('/x', 'GET'), self::RAW_TOKEN . '-4');
        self::assertSame(302, $response->getStatusCode());

        // B's grant must still not exist -- the token path never repairs the tail.
        self::assertSame(1, $this->connection->table('commerce_download_grants')->count());
        self::assertNull(
            $this->connection->table('commerce_download_grants')
                ->where('download_uuid', '=', 'dldeep0004b')->first()
        );
    }

    public function testDeepLinkFailureResponsesNeverExposeTokenHashOrBlobUuidAsFields(): void
    {
        $this->seedOrder('orderdeep005', 'paid');
        $this->seedBlob('blobdeep0005');
        $this->seedGrant(
            'orderdeep005',
            'grantdeep005',
            'blobdeep0005',
            'dldeep000005',
            self::RAW_TOKEN . '-5',
            remaining: 0
        );

        $response = $this->controller()->show(Request::create('/x', 'GET'), self::RAW_TOKEN . '-5');
        $body = $this->json($response);

        foreach (['token_hash', 'blob_uuid'] as $key) {
            self::assertArrayNotHasKey($key, $body);
            self::assertArrayNotHasKey($key, $body['error'] ?? []);
            self::assertArrayNotHasKey($key, $body['error']['details'] ?? []);
        }
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function seedOrder(
        string $uuid,
        string $status,
        string $tenant = '',
        int $grandTotal = 1000,
        int $refundedTotal = 0,
    ): void {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => $grandTotal,
            'grand_total' => $grandTotal,
            'refunded_total' => $refundedTotal,
        ]);
    }

    /** @param list<array<string,mixed>> $downloads */
    private function seedOrderLine(string $orderUuid, array $downloads, int $quantity = 1): void
    {
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => 'line' . substr(md5($orderUuid), 0, 8),
            'order_uuid' => $orderUuid,
            'variant_uuid' => 'var' . substr(md5($orderUuid), 0, 9),
            'product_name' => 'Digital Item',
            'sku' => 'SKU-' . $orderUuid,
            'option_values' => '[]',
            'unit_price' => 500,
            'quantity' => $quantity,
            'line_total' => 500 * $quantity,
            'downloads' => json_encode($downloads, JSON_THROW_ON_ERROR),
        ]);
    }

    private function seedBlob(string $uuid, string $visibility = 'private', string $status = 'active'): void
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

    private function seedGrant(
        string $orderUuid,
        string $uuid,
        string $blobUuid,
        string $downloadUuid,
        string $rawToken,
        ?int $remaining = null,
        ?string $revokedAt = null,
        ?string $overrideAt = null,
        string $tenant = '',
    ): string {
        $this->connection->table('commerce_download_grants')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'download_uuid' => $downloadUuid,
            'blob_uuid' => $blobUuid,
            'name' => 'File.pdf',
            'token_hash' => TokenHasher::hash($rawToken),
            'remaining' => $remaining,
            'revoked_at' => $revokedAt,
            'refund_access_override_at' => $overrideAt,
        ]);

        return $uuid;
    }

    /** @return array<string,mixed> */
    private function grantRow(string $uuid, string $tenant = ''): array
    {
        $row = $this->connection->table('commerce_download_grants')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
        self::assertNotNull($row);

        return $row;
    }

    private function controller(): DownloadLinkController
    {
        $orders = new OrderRepository();
        $grants = new DownloadGrantRepository();
        $signer = new DownloadUrlSigner(new BlobRepository($this->connection));
        $access = new DownloadAccessService($orders, $grants, $signer);

        return new DownloadLinkController($this->context, $grants, $access);
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
