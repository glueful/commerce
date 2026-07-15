<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Downloads;

use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantOverflowException;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Helpers\Utils;

/**
 * Grant issuance (design spec §3): snapshot-derived, quantity-aggregated,
 * idempotent across all three recovery surfaces. Every fixture here seeds
 * `commerce_orders`/`commerce_order_lines` directly (no cart/checkout pipeline) so
 * each test controls the exact snapshot and order status under test -- Task 3's
 * `CheckoutDownloadsTest` already covers snapshot construction at checkout time.
 */
final class GrantIssuanceTest extends CommerceTestCase
{
    public function testIssuesFromLineSnapshotEvenAfterTheDefinitionRowIsGone(): void
    {
        // No row is ever inserted into commerce_downloads for 'dldeleted001' -- grant
        // issuance must never read that table, only the order-line snapshot.
        $this->seedOrder('orderdel0001', 'paid');
        $this->seedOrderLine('orderdel0001', 'var000000001', [
            $this->snapshotEntry('dldeleted001', 'blobdeleted01', 'Ghost.pdf', 3, 10),
        ], quantity: 2);

        $grants = $this->service()->ensureGrantsForOrder($this->context, $this->order('orderdel0001'));

        self::assertCount(1, $grants);
        self::assertSame('dldeleted001', $grants[0]['download_uuid']);
        self::assertSame('blobdeleted01', $grants[0]['blob_uuid']);
        self::assertSame('Ghost.pdf', $grants[0]['name']);
        self::assertSame(6, (int) $grants[0]['remaining']); // limit(3) x quantity(2)
        self::assertNotNull($grants[0]['expires_at']);
        self::assertSame('', $grants[0]['tenant_uuid']);
        self::assertSame(64, strlen((string) $grants[0]['token_hash']));
    }

    public function testQuantityAggregatesAcrossAddonDistinctLinesOfTheSameVariant(): void
    {
        $this->seedOrder('orderagg0001', 'paid');
        // Two order lines for the same variant/download -- as add-on-distinct lines
        // produce (Task 3: withDownloadSnapshots() keys off the variant, so both lines
        // carry an identical downloads snapshot). Quantities must sum: 2 + 3 = 5.
        $entry = $this->snapshotEntry('dlagg0000001', 'blobagg000001', 'Course.zip', 2, null);
        $this->seedOrderLine('orderagg0001', 'var000000002', [$entry], quantity: 2);
        $this->seedOrderLine('orderagg0001', 'var000000002', [$entry], quantity: 3);

        $grants = $this->service()->ensureGrantsForOrder($this->context, $this->order('orderagg0001'));

        self::assertCount(1, $grants, 'one grant per download_uuid, not one per line');
        self::assertSame(10, (int) $grants[0]['remaining']); // limit(2) x quantity(5)
    }

    public function testNullDownloadLimitProducesNullRemainingAndNullExpiry(): void
    {
        $this->seedOrder('orderunl0001', 'paid');
        $this->seedOrderLine('orderunl0001', 'var000000003', [
            $this->snapshotEntry('dlunl0000001', 'blobunl000001', 'Unlimited.pdf', null, null),
        ], quantity: 4);

        $grants = $this->service()->ensureGrantsForOrder($this->context, $this->order('orderunl0001'));

        self::assertCount(1, $grants);
        self::assertNull($grants[0]['remaining']);
        self::assertNull($grants[0]['expires_at']);
    }

    public function testExpiresAtIsIssuanceTimePlusExpiryDays(): void
    {
        $this->seedOrder('orderexp0001', 'paid');
        $this->seedOrderLine('orderexp0001', 'var000000004', [
            $this->snapshotEntry('dlexp0000001', 'blobexp000001', 'Expiring.pdf', null, 14),
        ], quantity: 1);

        $before = time();
        $grants = $this->service()->ensureGrantsForOrder($this->context, $this->order('orderexp0001'));
        $after = time();

        self::assertNotNull($grants[0]['expires_at']);
        $expiresAt = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            (string) $grants[0]['expires_at'],
            new \DateTimeZone('UTC')
        )->getTimestamp();
        self::assertGreaterThanOrEqual($before + (14 * 86400), $expiresAt);
        self::assertLessThanOrEqual($after + (14 * 86400), $expiresAt);
    }

    public function testOverflowGuardThrowsInsteadOfWrapping(): void
    {
        $this->seedOrder('orderovf0001', 'paid');
        $this->seedOrderLine('orderovf0001', 'var000000005', [
            $this->snapshotEntry('dlovf0000001', 'blobovf000001', 'Huge.zip', PHP_INT_MAX, null),
        ], quantity: 2);

        $this->expectException(DownloadGrantOverflowException::class);
        $this->service()->ensureGrantsForOrder($this->context, $this->order('orderovf0001'));
    }

    public function testUnqualifyingStatusProducesNoGrants(): void
    {
        $this->seedOrder('orderpen0001', 'pending_payment');
        $this->seedOrderLine('orderpen0001', 'var000000006', [
            $this->snapshotEntry('dlpen0000001', 'blobpen000001', 'NotYet.pdf', null, null),
        ], quantity: 1);

        $grants = $this->service()->ensureGrantsForOrder($this->context, $this->order('orderpen0001'));

        self::assertSame([], $grants);
        self::assertSame(0, $this->connection->table('commerce_download_grants')->count());
    }

    public function testDoubleEnsureProducesNoDuplicatesAndOnlyTheFirstCallReturnsARawToken(): void
    {
        $this->seedOrder('orderdbl0001', 'paid');
        $this->seedOrderLine('orderdbl0001', 'var000000007', [
            $this->snapshotEntry('dldbl0000001', 'blobdbl000001', 'Once.pdf', null, null),
        ], quantity: 1);

        $service = $this->service();
        $order = $this->order('orderdbl0001');

        $first = $service->issueAndCollectForOrder($this->context, $order);
        self::assertCount(1, $first['grants']);
        self::assertCount(1, $first['raw_tokens']);

        $second = $service->issueAndCollectForOrder($this->context, $order);
        self::assertCount(1, $second['grants']);
        self::assertSame([], $second['raw_tokens']);

        self::assertSame(1, $this->connection->table('commerce_download_grants')->count());
    }

    public function testPreInsertedGrantRowIsReloadedIdempotentlyNotDuplicated(): void
    {
        $this->seedOrder('orderrace0001', 'paid');
        $this->seedOrderLine('orderrace0001', 'var000000008', [
            $this->snapshotEntry('dlrace0000001', 'blobrace000001', 'Raced.pdf', 5, null),
        ], quantity: 1);

        // Simulate a concurrent writer that already committed this exact grant before
        // this call runs -- pre-insert the row directly, bypassing the service.
        $preexistingHash = str_repeat('f', 64);
        $this->connection->table('commerce_download_grants')->insert([
            'uuid' => 'grantrace001',
            'tenant_uuid' => '',
            'order_uuid' => 'orderrace0001',
            'download_uuid' => 'dlrace0000001',
            'blob_uuid' => 'blobrace000001',
            'name' => 'Raced.pdf',
            'token_hash' => $preexistingHash,
            'remaining' => 5,
        ]);

        $result = $this->service()->issueAndCollectForOrder($this->context, $this->order('orderrace0001'));

        self::assertCount(1, $result['grants']);
        self::assertSame($preexistingHash, $result['grants'][0]['token_hash'], 'pre-existing row left untouched');
        self::assertSame([], $result['raw_tokens'], 'this call never created the row, so no raw token');
        self::assertSame(1, $this->connection->table('commerce_download_grants')->count());
    }

    public function testTokenHashCollisionRegeneratesAndSucceeds(): void
    {
        // A grant for a DIFFERENT (order, download) already holds this token_hash.
        $collidingHash = str_repeat('c', 64);
        $this->connection->table('commerce_download_grants')->insert([
            'uuid' => 'grantother01',
            'tenant_uuid' => '',
            'order_uuid' => 'orderother01',
            'download_uuid' => 'dlother00001',
            'blob_uuid' => 'blobother001',
            'name' => 'Other.pdf',
            'token_hash' => $collidingHash,
        ]);

        $this->seedOrder('ordercol0001', 'paid');
        $this->seedOrderLine('ordercol0001', 'var000000009', [
            $this->snapshotEntry('dlcol0000001', 'blobcol000001', 'Collide.pdf', null, null),
        ], quantity: 1);

        $calls = 0;
        $generator = function () use (&$calls, $collidingHash): array {
            $calls++;
            if ($calls === 1) {
                return ['raw' => 'colliding-raw-token', 'hash' => $collidingHash];
            }

            return TokenHasher::generate();
        };

        $result = $this->service($generator)->issueAndCollectForOrder($this->context, $this->order('ordercol0001'));

        self::assertSame(2, $calls, 'the generator is retried exactly once after the collision');
        self::assertCount(1, $result['grants']);
        self::assertNotSame($collidingHash, $result['grants'][0]['token_hash']);
        self::assertCount(1, $result['raw_tokens']);
        self::assertSame(
            2,
            $this->connection->table('commerce_download_grants')->count(),
            'the pre-seeded other grant plus the one newly created -- no phantom rows from the failed attempt'
        );
    }

    public function testTokenGeneratorThatAlwaysCollidesThrowsAfterBoundedAttempts(): void
    {
        $collidingHash = str_repeat('d', 64);
        $this->connection->table('commerce_download_grants')->insert([
            'uuid' => 'grantstuck01',
            'tenant_uuid' => '',
            'order_uuid' => 'orderstuck01',
            'download_uuid' => 'dlstuck00001',
            'blob_uuid' => 'blobstuck001',
            'name' => 'Stuck.pdf',
            'token_hash' => $collidingHash,
        ]);

        $this->seedOrder('orderbnd0001', 'paid');
        $this->seedOrderLine('orderbnd0001', 'var000000010', [
            $this->snapshotEntry('dlbnd0000001', 'blobbnd000001', 'Bound.pdf', null, null),
        ], quantity: 1);

        $calls = 0;
        $generator = function () use (&$calls, $collidingHash): array {
            $calls++;

            return ['raw' => 'always-colliding', 'hash' => $collidingHash];
        };

        try {
            $this->service($generator)->ensureGrantsForOrder($this->context, $this->order('orderbnd0001'));
            self::fail('expected a PDOException after exhausting bounded token-collision retries');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(3, $calls, 'bounded at 3 attempts');
        self::assertSame(
            1,
            $this->connection->table('commerce_download_grants')->count(),
            'only the pre-seeded row exists -- no row was created for the exhausted download'
        );
    }

    public function testPartiallyIssuedOrderHealsOnlyTheMissingTail(): void
    {
        $this->seedOrder('orderpart0001', 'paid');
        $this->seedOrderLine('orderpart0001', 'var000000011', [
            $this->snapshotEntry('dlpartA00001', 'blobpartA0001', 'A.pdf', null, null),
            $this->snapshotEntry('dlpartB00001', 'blobpartB0001', 'B.pdf', null, null),
        ], quantity: 1);

        // Simulate an earlier partial run: only A was ever issued.
        $existingHash = str_repeat('a', 64);
        $this->connection->table('commerce_download_grants')->insert([
            'uuid' => 'grantpartA01',
            'tenant_uuid' => '',
            'order_uuid' => 'orderpart0001',
            'download_uuid' => 'dlpartA00001',
            'blob_uuid' => 'blobpartA0001',
            'name' => 'A.pdf',
            'token_hash' => $existingHash,
        ]);

        $result = $this->service()->issueAndCollectForOrder($this->context, $this->order('orderpart0001'));

        self::assertCount(2, $result['grants']);
        self::assertCount(1, $result['raw_tokens'], 'only the newly created B grant gets a raw token');

        $byDownload = [];
        foreach ($result['grants'] as $grant) {
            $byDownload[$grant['download_uuid']] = $grant;
        }
        self::assertSame($existingHash, $byDownload['dlpartA00001']['token_hash'], 'A left untouched');
        self::assertArrayHasKey($byDownload['dlpartB00001']['uuid'], $result['raw_tokens']);
    }

    public function testIssueAndCollectReturnsRawTokensOnlyForGrantsCreatedByThisCall(): void
    {
        $this->seedOrder('orderraw0001', 'paid');
        $this->seedOrderLine('orderraw0001', 'var000000012', [
            $this->snapshotEntry('dlrawA000001', 'blobrawA00001', 'RawA.pdf', null, null),
            $this->snapshotEntry('dlrawB000001', 'blobrawB00001', 'RawB.pdf', null, null),
        ], quantity: 1);

        $service = $this->service();
        $order = $this->order('orderraw0001');

        $first = $service->issueAndCollectForOrder($this->context, $order);
        self::assertCount(2, $first['grants']);
        self::assertCount(2, $first['raw_tokens']);
        foreach ($first['grants'] as $grant) {
            self::assertArrayHasKey($grant['uuid'], $first['raw_tokens']);
            self::assertSame(40, strlen($first['raw_tokens'][$grant['uuid']]), '160-bit / 20-byte hex token');
        }

        $second = $service->issueAndCollectForOrder($this->context, $order);
        self::assertCount(2, $second['grants']);
        self::assertSame([], $second['raw_tokens']);
    }

    public function testGrantsAreStampedWithTheOrdersTenantUuid(): void
    {
        $this->seedOrder('ordertn00001', 'paid', tenant: 'tenantAAAA01');
        $this->seedOrderLine('ordertn00001', 'var000000013', [
            $this->snapshotEntry('dltn0000001', 'blobtn000001', 'Tenant.pdf', null, null),
        ], quantity: 1);

        $grants = $this->service()->ensureGrantsForOrder($this->context, $this->order('ordertn00001', 'tenantAAAA01'));

        self::assertCount(1, $grants);
        self::assertSame('tenantAAAA01', $grants[0]['tenant_uuid']);
    }

    public function testFulfilledAndRefundedStatusesAlsoQualify(): void
    {
        $this->seedOrder('orderful0001', 'fulfilled');
        $this->seedOrderLine('orderful0001', 'var000000014', [
            $this->snapshotEntry('dlful0000001', 'blobful000001', 'Fulfilled.pdf', null, null),
        ], quantity: 1);

        $this->seedOrder('orderref0001', 'refunded');
        $this->seedOrderLine('orderref0001', 'var000000015', [
            $this->snapshotEntry('dlref0000001', 'blobref000001', 'Refunded.pdf', null, null),
        ], quantity: 1);

        self::assertCount(
            1,
            $this->service()->ensureGrantsForOrder($this->context, $this->order('orderful0001'))
        );
        self::assertCount(
            1,
            $this->service()->ensureGrantsForOrder($this->context, $this->order('orderref0001'))
        );
    }

    public function testPhysicalOnlyOrderProducesNoGrants(): void
    {
        $this->seedOrder('orderphy0001', 'paid');
        $this->seedOrderLine('orderphy0001', 'var000000016', null, quantity: 1);

        $grants = $this->service()->ensureGrantsForOrder($this->context, $this->order('orderphy0001'));

        self::assertSame([], $grants);
        self::assertSame(0, $this->connection->table('commerce_download_grants')->count());
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function service(?callable $tokenGenerator = null): DownloadGrantService
    {
        return new DownloadGrantService(new OrderRepository(), new DownloadGrantRepository(), $tokenGenerator);
    }

    private function seedOrder(string $uuid, string $status, string $tenant = ''): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
    }

    /** @return array<string,mixed> */
    private function order(string $uuid, string $tenant = ''): array
    {
        $row = $this->connection->table('commerce_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
        self::assertNotNull($row);

        return $row;
    }

    /** @param list<array<string,mixed>>|null $downloads */
    private function seedOrderLine(string $orderUuid, string $variantUuid, ?array $downloads, int $quantity): void
    {
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => Utils::generateNanoID(),
            'order_uuid' => $orderUuid,
            'variant_uuid' => $variantUuid,
            'product_name' => 'Digital Item',
            'sku' => 'SKU-' . $variantUuid,
            'option_values' => '[]',
            'unit_price' => 500,
            'quantity' => $quantity,
            'line_total' => 500 * $quantity,
            'downloads' => $downloads === null ? null : json_encode($downloads, JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return array<string,mixed> */
    private function snapshotEntry(
        string $downloadUuid,
        string $blobUuid,
        string $name,
        ?int $limit,
        ?int $expiryDays
    ): array {
        return [
            'download_uuid' => $downloadUuid,
            'blob_uuid' => $blobUuid,
            'name' => $name,
            'download_limit' => $limit,
            'expiry_days' => $expiryDays,
        ];
    }
}
