<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Downloads;

use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadAccessService;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantService;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadSigningException;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadUrlSigner;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Repository\BlobRepository;
use Glueful\Support\SignedUrl;
use Glueful\Uploader\Contracts\BlobPublicUrlProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Order-authenticated access path (design spec §4.1): `GET .../downloads` (listing)
 * and `POST .../downloads/{grantUuid}/url` (the atomic mint). Both reuse
 * `OrderController`'s existing access check verbatim and ALWAYS run the idempotent
 * repair (`ensureGrantsForOrder`) first. Every classification branch of the atomic
 * mint chain (spec §4.1, verbatim-binding) is exercised end-to-end through the real
 * HTTP controller method, not a bypassed service call, so the wiring itself is
 * proven along with the guarded-UPDATE semantics.
 */
final class DownloadAccessTest extends CommerceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The framework's blobs table lives outside commerce's own migration set.
        require_once __DIR__ . '/../../../vendor/glueful/framework/migrations/uploads/001_CreateBlobsTable.php';
        (new \Glueful\Migrations\Uploads\CreateBlobsTable())->up($this->connection->getSchemaBuilder());
    }

    // -----------------------------------------------------------------
    // Listing: access check + always-repair
    // -----------------------------------------------------------------

    public function testListingViaGuestTokenReturnsGrantsWithWhitelistedShape(): void
    {
        $this->seedOrder('orderlist001', 'paid', guestToken: 'guest-list-1');
        $this->seedBlob('bloblist0001');
        $this->seedGrant('orderlist001', 'grantlist001', 'bloblist0001', 'dllist000001', remaining: 3, name: 'Ebook.pdf');

        $request = Request::create('/x', 'GET');
        $request->headers->set('X-Order-Token', 'guest-list-1');

        $response = $this->orderController()->downloads($request, 'ORD-orderlist001');
        $raw = (string) $response->getContent();
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $data = $body['data'];
        self::assertCount(1, $data);
        self::assertSame(
            ['grant_uuid', 'name', 'remaining', 'expires_at', 'expired', 'revoked', 'blocked_by_full_refund'],
            array_keys($data[0])
        );
        self::assertSame('grantlist001', $data[0]['grant_uuid']);
        self::assertSame('Ebook.pdf', $data[0]['name']);
        self::assertSame(3, $data[0]['remaining']);
        self::assertFalse($data[0]['expired']);
        self::assertFalse($data[0]['revoked']);
        self::assertFalse($data[0]['blocked_by_full_refund']);

        // No token_hash/blob_uuid anywhere in the serialized body.
        self::assertStringNotContainsString('bloblist0001', $raw);
        foreach (['token_hash', 'blob_uuid'] as $key) {
            self::assertArrayNotHasKey($key, $data[0]);
        }
    }

    public function testListingViaAuthenticatedOwnerReturnsGrants(): void
    {
        $this->seedOrder('orderlist002', 'paid', userUuid: 'owneruuid001');
        $this->seedBlob('bloblist0002');
        $this->seedGrant('orderlist002', 'grantlist002', 'bloblist0002', 'dllist000002');

        $request = Request::create('/x', 'GET');
        $request->attributes->set('user', ['uuid' => 'owneruuid001']);

        $response = $this->orderController()->downloads($request, 'ORD-orderlist002');

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->json($response)['data']);
    }

    public function testListingUnauthorizedAccessThrowsNotFound(): void
    {
        $this->seedOrder('orderlist003', 'paid', guestToken: 'guest-list-3');

        $this->expectException(NotFoundException::class);
        $this->orderController()->downloads(Request::create('/x', 'GET'), 'ORD-orderlist003');
    }

    public function testListingAlwaysRepairsWhenNoGrantsExistYet(): void
    {
        $this->seedOrder('orderrep0001', 'paid', guestToken: 'guest-rep-1');
        $this->seedOrderLine('orderrep0001', [
            $this->snapshotEntry('dlrep0000001', 'blobrep000001', 'Repaired.pdf', null, null),
        ]);
        self::assertSame(0, $this->connection->table('commerce_download_grants')->count());

        $request = Request::create('/x', 'GET');
        $request->headers->set('X-Order-Token', 'guest-rep-1');
        $response = $this->orderController()->downloads($request, 'ORD-orderrep0001');

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->json($response)['data']);
        self::assertSame(1, $this->connection->table('commerce_download_grants')->count());
    }

    public function testListingRepairsPartialTailAfterOneGrantDeleted(): void
    {
        $this->seedOrder('orderrep0002', 'paid', guestToken: 'guest-rep-2');
        $this->seedOrderLine('orderrep0002', [
            $this->snapshotEntry('dlrepA000001', 'blobrepA00001', 'A.pdf', null, null),
            $this->snapshotEntry('dlrepB000001', 'blobrepB00001', 'B.pdf', null, null),
        ]);

        $request = Request::create('/x', 'GET');
        $request->headers->set('X-Order-Token', 'guest-rep-2');
        $first = $this->json($this->orderController()->downloads($request, 'ORD-orderrep0002'))['data'];
        self::assertCount(2, $first);

        // Simulate a lost grant: delete exactly ONE of the two.
        $survivor = $first[0]['download_uuid'] ?? null; // grants echo no download_uuid; find via DB instead
        $rows = $this->connection->table('commerce_download_grants')
            ->where('order_uuid', '=', 'orderrep0002')->get();
        self::assertCount(2, $rows);
        $keepUuid = (string) $rows[0]['uuid'];
        $deleteUuid = (string) $rows[1]['uuid'];
        $this->connection->table('commerce_download_grants')->where('uuid', '=', $deleteUuid)->delete();
        self::assertSame(1, $this->connection->table('commerce_download_grants')->count());

        $second = $this->json($this->orderController()->downloads($request, 'ORD-orderrep0002'))['data'];
        self::assertCount(2, $second, 'the missing tail must be recreated');
        self::assertSame(2, $this->connection->table('commerce_download_grants')->count());

        // The survivor's row (grant identity) must be untouched, not re-created.
        $survivorRow = $this->connection->table('commerce_download_grants')
            ->where('uuid', '=', $keepUuid)->first();
        self::assertNotNull($survivorRow);
    }

    // -----------------------------------------------------------------
    // Atomic mint: happy paths
    // -----------------------------------------------------------------

    public function testMintHappyPathDecrementsRemainingSetsMintCountAndLastMintedAtAndUrlValidates(): void
    {
        $this->withSigningSecret();
        $this->seedOrder('ordermint001', 'paid', guestToken: 'guest-mint-1');
        $this->seedBlob('blobmint0001');
        $grantUuid = $this->seedGrant('ordermint001', 'grantmint001', 'blobmint0001', 'dlmint000001', remaining: 3);

        $response = $this->mintRequest('ordermint001', 'guest-mint-1', $grantUuid);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['url', 'expires_in'], array_keys($body['data']));
        self::assertSame(300, $body['data']['expires_in']);
        self::assertTrue(SignedUrl::make($this->context)->validate($body['data']['url']));
        // The signed URL necessarily embeds the blob_uuid in its own /blobs/{uuid}
        // path (it must, to be a fetchable blob URL) -- the whitelist guarantee is
        // that the JSON envelope carries no SEPARATE blob_uuid/token_hash field.
        self::assertSame(['url', 'expires_in'], array_keys($body['data']));

        $grant = $this->grantRow($grantUuid);
        self::assertSame(2, (int) $grant['remaining']);
        self::assertSame(1, (int) $grant['mint_count']);
        self::assertNotNull($grant['last_minted_at']);
    }

    public function testMintUnlimitedGrantIncrementsMintCountWithoutDecrementingNullRemaining(): void
    {
        $this->withSigningSecret();
        $this->seedOrder('ordermint002', 'paid', guestToken: 'guest-mint-2');
        $this->seedBlob('blobmint0002');
        $grantUuid = $this->seedGrant('ordermint002', 'grantmint002', 'blobmint0002', 'dlmint000002', remaining: null);

        $response = $this->mintRequest('ordermint002', 'guest-mint-2', $grantUuid);
        self::assertSame(200, $response->getStatusCode());

        $grant = $this->grantRow($grantUuid);
        self::assertNull($grant['remaining']);
        self::assertSame(1, (int) $grant['mint_count']);
    }

    // -----------------------------------------------------------------
    // Atomic mint: 410 classification matrix
    // -----------------------------------------------------------------

    public function testMintExhaustedGrantReturns410Exhausted(): void
    {
        $this->withSigningSecret();
        $this->seedOrder('ordermint003', 'paid', guestToken: 'guest-mint-3');
        $this->seedBlob('blobmint0003');
        $grantUuid = $this->seedGrant('ordermint003', 'grantmint003', 'blobmint0003', 'dlmint000003', remaining: 0);

        $response = $this->mintRequest('ordermint003', 'guest-mint-3', $grantUuid);

        self::assertSame(410, $response->getStatusCode());
        self::assertSame('exhausted', $this->json($response)['error']['details']['code']);
        self::assertSame(0, (int) $this->grantRow($grantUuid)['mint_count']);
    }

    public function testMintExpiredGrantUsesDbTimeReturns410Expired(): void
    {
        $this->withSigningSecret();
        $this->seedOrder('ordermint004', 'paid', guestToken: 'guest-mint-4');
        $this->seedBlob('blobmint0004');
        $grantUuid = $this->seedGrant(
            'ordermint004',
            'grantmint004',
            'blobmint0004',
            'dlmint000004',
            remaining: 5,
            expiresAt: '2000-01-01 00:00:00'
        );

        $response = $this->mintRequest('ordermint004', 'guest-mint-4', $grantUuid);

        self::assertSame(410, $response->getStatusCode());
        self::assertSame('expired', $this->json($response)['error']['details']['code']);
        self::assertSame(0, (int) $this->grantRow($grantUuid)['mint_count']);
        self::assertSame(5, (int) $this->grantRow($grantUuid)['remaining'], 'expired mint must not decrement');
    }

    public function testMintRevokedGrantReturns410Revoked(): void
    {
        $this->withSigningSecret();
        $this->seedOrder('ordermint005', 'paid', guestToken: 'guest-mint-5');
        $this->seedBlob('blobmint0005');
        $grantUuid = $this->seedGrant(
            'ordermint005',
            'grantmint005',
            'blobmint0005',
            'dlmint000005',
            remaining: 5,
            revokedAt: '2026-01-01 00:00:00'
        );

        $response = $this->mintRequest('ordermint005', 'guest-mint-5', $grantUuid);

        self::assertSame(410, $response->getStatusCode());
        self::assertSame('revoked', $this->json($response)['error']['details']['code']);
    }

    public function testMintFullyRefundedOrderBlocksAccessAndConsumesNothing(): void
    {
        $this->withSigningSecret();
        $this->seedOrder('ordermint006', 'paid', guestToken: 'guest-mint-6', grandTotal: 1000, refundedTotal: 1000);
        $this->seedBlob('blobmint0006');
        $grantUuid = $this->seedGrant('ordermint006', 'grantmint006', 'blobmint0006', 'dlmint000006', remaining: 5);

        $response = $this->mintRequest('ordermint006', 'guest-mint-6', $grantUuid);

        self::assertSame(410, $response->getStatusCode());
        self::assertSame('blocked_by_full_refund', $this->json($response)['error']['details']['code']);

        $grant = $this->grantRow($grantUuid);
        self::assertSame(0, (int) $grant['mint_count']);
        self::assertSame(5, (int) $grant['remaining']);
        self::assertNull($grant['last_minted_at']);
    }

    /**
     * ESCALATED bug (controller finding): the full-refund predicate was
     * `refunded_total >= grand_total`, which is trivially true for a FREE
     * digital-product order (grand_total = 0, refunded_total = 0) --
     * `0 >= 0`. A $0 order could therefore never mint its own downloads. The
     * fix requires grand_total > 0 as well, so "nothing to refund" is never
     * confused with "fully refunded".
     */
    public function testMintFreeOrderWithZeroGrandTotalSucceeds(): void
    {
        $this->withSigningSecret();
        $this->seedOrder('orderfree001', 'paid', guestToken: 'guest-free-1', grandTotal: 0, refundedTotal: 0);
        $this->seedBlob('blobfree0001');
        $grantUuid = $this->seedGrant('orderfree001', 'grantfree001', 'blobfree0001', 'dlfree000001', remaining: 5);

        $response = $this->mintRequest('orderfree001', 'guest-free-1', $grantUuid);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode(), (string) json_encode($body));
        self::assertSame(['url', 'expires_in'], array_keys($body['data']));

        $grant = $this->grantRow($grantUuid);
        self::assertSame(1, (int) $grant['mint_count']);
        self::assertSame(4, (int) $grant['remaining']);
        self::assertNotNull($grant['last_minted_at']);
    }

    /**
     * Listing-side counterpart of the same predicate
     * ({@see \Glueful\Extensions\Commerce\Http\Storefront\OrderController::isFullyRefunded()}):
     * a free order's grant must not be advertised as refund-blocked.
     */
    public function testListingFreeOrderWithZeroGrandTotalDoesNotReportBlockedByFullRefund(): void
    {
        $this->seedOrder('orderfree002', 'paid', guestToken: 'guest-free-2', grandTotal: 0, refundedTotal: 0);
        $this->seedBlob('blobfree0002');
        $this->seedGrant('orderfree002', 'grantfree002', 'blobfree0002', 'dlfree000002', remaining: null);

        $request = Request::create('/x', 'GET');
        $request->headers->set('X-Order-Token', 'guest-free-2');
        $response = $this->orderController()->downloads($request, 'ORD-orderfree002');
        $data = $this->json($response)['data'];

        self::assertFalse($data[0]['blocked_by_full_refund']);
    }

    /**
     * Regression: a genuinely fully-refunded PAID order (grand_total > 0) must
     * still report blocked_by_full_refund at the listing layer -- the
     * grand_total > 0 guard must not weaken the real full-refund case.
     */
    public function testListingGenuinelyFullyRefundedPaidOrderStillReportsBlockedByFullRefund(): void
    {
        $this->seedOrder('orderfull001', 'paid', guestToken: 'guest-full-1', grandTotal: 1000, refundedTotal: 1000);
        $this->seedBlob('blobfull0001');
        $this->seedGrant('orderfull001', 'grantfull001', 'blobfull0001', 'dlfull000001', remaining: 5);

        $request = Request::create('/x', 'GET');
        $request->headers->set('X-Order-Token', 'guest-full-1');
        $response = $this->orderController()->downloads($request, 'ORD-orderfull001');
        $data = $this->json($response)['data'];

        self::assertTrue($data[0]['blocked_by_full_refund']);
    }

    public function testMintAuditedOverrideRestoresAccessAfterFullRefund(): void
    {
        $this->withSigningSecret();
        $this->seedOrder('ordermint007', 'paid', guestToken: 'guest-mint-7', grandTotal: 1000, refundedTotal: 1000);
        $this->seedBlob('blobmint0007');
        $grantUuid = $this->seedGrant(
            'ordermint007',
            'grantmint007',
            'blobmint0007',
            'dlmint000007',
            remaining: 5,
            overrideAt: '2026-01-01 00:00:00'
        );

        $response = $this->mintRequest('ordermint007', 'guest-mint-7', $grantUuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, (int) $this->grantRow($grantUuid)['mint_count']);
    }

    public function testMintSigningFailureConsumesNothing(): void
    {
        // Deliberately do NOT call withSigningSecret(): SignedUrl has no configured
        // secret in a fresh CommerceTestCase context (verified: no app.key / no
        // uploads.signed_urls.secret override, and no ambient APP_KEY/SIGNED_URL_SECRET
        // env vars in this suite), so SignedUrl::make()->generate() fails closed --
        // exactly the "broken SignedUrl config" this test needs.
        $this->seedOrder('ordermint008', 'paid', guestToken: 'guest-mint-8');
        $this->seedBlob('blobmint0008');
        $grantUuid = $this->seedGrant('ordermint008', 'grantmint008', 'blobmint0008', 'dlmint000008', remaining: 5);

        $orderBefore = $this->connection->table('commerce_orders')
            ->where('uuid', '=', 'ordermint008')->first();

        $access = $this->access(); // no signing secret configured
        try {
            $access->mint($this->context, '', 'ordermint008', $grantUuid, 'http://localhost');
            self::fail('expected DownloadSigningException');
        } catch (DownloadSigningException) {
            $this->addToAssertionCount(1);
        }

        $grant = $this->grantRow($grantUuid);
        self::assertSame(0, (int) $grant['mint_count']);
        self::assertSame(5, (int) $grant['remaining']);
        self::assertNull($grant['last_minted_at']);

        // The whole transaction rolled back, including the order's financial claim
        // bump -- a signing failure consumes nothing, not even the serialization row.
        $orderAfter = $this->connection->table('commerce_orders')
            ->where('uuid', '=', 'ordermint008')->first();
        self::assertSame(
            (int) $orderBefore['refund_revision'],
            (int) $orderAfter['refund_revision'],
            'signing failure must roll back the order financial-mutation claim too'
        );
    }

    public function testMintDeterministicDoubleMintOnRemainingOneHasExactlyOneWinner(): void
    {
        $this->withSigningSecret();
        $this->seedOrder('ordermint009', 'paid', guestToken: 'guest-mint-9');
        $this->seedBlob('blobmint0009');
        $grantUuid = $this->seedGrant('ordermint009', 'grantmint009', 'blobmint0009', 'dlmint000009', remaining: 1);

        $first = $this->mintRequest('ordermint009', 'guest-mint-9', $grantUuid);
        $second = $this->mintRequest('ordermint009', 'guest-mint-9', $grantUuid);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(410, $second->getStatusCode());
        self::assertSame('exhausted', $this->json($second)['error']['details']['code']);

        $grant = $this->grantRow($grantUuid);
        self::assertSame(0, (int) $grant['remaining']);
        self::assertSame(1, (int) $grant['mint_count'], 'exactly one winner incremented mint_count');
    }

    public function testMintUnknownGrantUuidThrowsNotFound(): void
    {
        $this->withSigningSecret();
        $this->seedOrder('ordermint010', 'paid', guestToken: 'guest-mint-10');

        $this->expectException(NotFoundException::class);
        $this->mintRequest('ordermint010', 'guest-mint-10', 'no-such-grant');
    }

    public function testMintGrantBelongingToAnotherOrderThrowsNotFoundNonRevealing(): void
    {
        $this->withSigningSecret();
        $this->seedOrder('ordermint011', 'paid', guestToken: 'guest-mint-11a');
        $this->seedOrder('ordermint012', 'paid', guestToken: 'guest-mint-11b');
        $this->seedBlob('blobmint0011');
        $foreignGrant = $this->seedGrant('ordermint012', 'grantmint012', 'blobmint0011', 'dlmint000011');

        $this->expectException(NotFoundException::class);
        $this->mintRequest('ordermint011', 'guest-mint-11a', $foreignGrant);
    }

    public function testMintUnauthorizedAccessThrowsNotFoundBeforeMinting(): void
    {
        $this->seedOrder('ordermint013', 'paid', guestToken: 'guest-mint-13');
        $this->seedBlob('blobmint0013');
        $grantUuid = $this->seedGrant('ordermint013', 'grantmint013', 'blobmint0013', 'dlmint000013', remaining: 5);

        $this->expectException(NotFoundException::class);
        $this->orderController()->downloadUrl(Request::create('/x', 'POST'), 'ORD-ordermint013', $grantUuid);

        self::assertSame(5, (int) $this->grantRow($grantUuid)['remaining']);
    }

    public function testMintUsesBoundBlobPublicUrlProviderBaseOverRequestFallback(): void
    {
        $this->withSigningSecret();
        $this->seedBlob('blobprov0001');

        $provider = new class implements BlobPublicUrlProvider {
            public function publicBaseUrl(array $blob): ?string
            {
                return 'https://tenant-a.example.com';
            }
        };
        $signer = new DownloadUrlSigner(new BlobRepository($this->connection), $provider);

        $signed = $signer->sign($this->context, 'blobprov0001', 'http://request-fallback-host');

        self::assertStringStartsWith('https://tenant-a.example.com/blobs/blobprov0001', $signed['url']);
        self::assertStringNotContainsString('request-fallback-host', $signed['url']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function withSigningSecret(): void
    {
        $this->context->overrideConfig('app.key', 'test-signing-secret-0123456789ab');
    }

    private function mintRequest(string $orderUuid, string $guestToken, string $grantUuid): HttpResponse
    {
        $request = Request::create('/x', 'POST');
        $request->headers->set('X-Order-Token', $guestToken);

        return $this->orderController()->downloadUrl($request, 'ORD-' . $orderUuid, $grantUuid);
    }

    private function seedOrder(
        string $uuid,
        string $status,
        string $tenant = '',
        int $grandTotal = 1000,
        int $refundedTotal = 0,
        ?string $guestToken = 'guesttoken001',
        ?string $userUuid = null,
    ): void {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'email' => 'buyer@example.com',
            'user_uuid' => $userUuid,
            'guest_token_hash' => $guestToken !== null ? TokenHasher::hash($guestToken) : str_repeat('a', 64),
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
            'uuid' => 'line' . substr(md5($orderUuid . count($downloads) . random_int(0, PHP_INT_MAX)), 0, 8),
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

    /** @return array<string,mixed> */
    private function snapshotEntry(string $downloadUuid, string $blobUuid, string $name, ?int $limit, ?int $expiryDays): array
    {
        return [
            'download_uuid' => $downloadUuid,
            'blob_uuid' => $blobUuid,
            'name' => $name,
            'download_limit' => $limit,
            'expiry_days' => $expiryDays,
        ];
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
        ?int $remaining = null,
        ?string $expiresAt = null,
        ?string $revokedAt = null,
        ?string $overrideAt = null,
        string $tenant = '',
        string $name = 'File.pdf',
    ): string {
        $this->connection->table('commerce_download_grants')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'download_uuid' => $downloadUuid,
            'blob_uuid' => $blobUuid,
            'name' => $name,
            'token_hash' => TokenHasher::generate()['hash'],
            'remaining' => $remaining,
            'expires_at' => $expiresAt,
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

    private function access(?BlobPublicUrlProvider $provider = null): DownloadAccessService
    {
        return new DownloadAccessService(
            new OrderRepository(),
            new DownloadGrantRepository(),
            new DownloadUrlSigner(new BlobRepository($this->connection), $provider)
        );
    }

    private function orderController(): OrderController
    {
        $orders = new OrderRepository();
        $grantRepo = new DownloadGrantRepository();

        return new OrderController(
            $this->context,
            $orders,
            $this->checkout(),
            new SentinelTenantResolver(),
            new RefundRepository(),
            new DownloadGrantService($orders, $grantRepo),
            $grantRepo,
            $this->access()
        );
    }

    private function checkout(): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), new SentinelTenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->shipping(),
            $this->tax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            new SentinelTenantResolver()
        );
    }

    private function cart(): CartService
    {
        return new CartService(
            new CartRepository(),
            new VariantRepository(),
            new ProductRepository(),
            new StockRepository(),
            new DiscountRepository(),
            new PricingEngine(),
            new SentinelTenantResolver()
        );
    }

    private function shipping(): ShippingRateProvider
    {
        return new class implements ShippingRateProvider {
            public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
            {
                return [new ShippingQuote('std', 'Standard', 500)];
            }
        };
    }

    private function tax(): TaxCalculator
    {
        return new class implements TaxCalculator {
            public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
            {
                return new TaxQuote(0);
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
