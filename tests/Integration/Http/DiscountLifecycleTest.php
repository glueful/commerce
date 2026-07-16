<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\Admin\AdminDiscountController;
use Glueful\Extensions\Commerce\Http\DTOs\DiscountListQuery;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Discount admin lifecycle (Layer 6 Task 3): the folded `commerce_discounts.revision`
 * column shape, paginated/filtered list (status/q, stable uuid tie-break, literal-LIKE
 * escaping), show (found/404/cross-tenant), guarded PATCH (claims the discount with a
 * checked affected-row count so a concurrent delete can never yield a false-success
 * update), and guarded DELETE (claim -> post-claim redemption probe -> 409 row-intact,
 * or hard delete). See {@see DiscountService}'s class docblock for the full
 * delete-vs-checkout-redemption race analysis this class pins deterministically in
 * both orderings (the real two-connection interleaving is the pgsql-gated Task 7 lane).
 */
final class DiscountLifecycleTest extends CommerceTestCase
{
    // --- migration shape (folded revision column) ----------------------------

    public function testRevisionColumnDefaultsToZeroWhenOmitted(): void
    {
        $this->connection->table('commerce_discounts')->insert([
            'uuid' => 'discshape001',
            'tenant_uuid' => '',
            'code' => 'SHAPE',
            'type' => 'percentage',
            'value' => 500,
        ]);

        $row = $this->connection->table('commerce_discounts')->where('uuid', '=', 'discshape001')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['revision']);
    }

    // --- show -----------------------------------------------------------------

    public function testShowReturnsDiscount(): void
    {
        $uuid = $this->seedDiscount(['code' => 'SHOWME']);

        $response = $this->controller()->show(Request::create('/x'), $uuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('SHOWME', $this->json($response)['data']['code']);
    }

    public function testShowUnknownDiscountThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->show(Request::create('/x'), 'no-such-disc');
    }

    public function testShowCrossTenantDiscountThrowsNotFound(): void
    {
        $uuid = $this->seedDiscount(['code' => 'OTHERT'], 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->show(Request::create('/x'), $uuid);
    }

    // --- list: tenant scope / pagination / filters / tie-break -----------------

    public function testIndexOnlyListsOwnTenantDiscountsWithPaginationEnvelope(): void
    {
        $this->seedDiscount(['code' => 'A1']);
        $this->seedDiscount(['code' => 'A2']);
        $this->seedDiscount(['code' => 'OTHER'], 'tenant-b');

        $response = $this->controller()->index(new DiscountListQuery(), Request::create('/x'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(2, $body['total']);
        self::assertCount(2, $body['data']);
        self::assertSame(1, $body['current_page']);
        self::assertSame(24, $body['per_page']);
    }

    public function testIndexFiltersByStatus(): void
    {
        $this->seedDiscount(['code' => 'ACTIVE1', 'status' => 'active']);
        $this->seedDiscount(['code' => 'INACTIVE1', 'status' => 'inactive']);

        $response = $this->controller()->index(
            new DiscountListQuery(status: 'inactive'),
            Request::create('/x')
        );

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('INACTIVE1', $body['data'][0]['code']);
    }

    public function testIndexFiltersByCodeSubstringCaseInsensitive(): void
    {
        $this->seedDiscount(['code' => 'SUMMERSALE']);
        $this->seedDiscount(['code' => 'WINTERSALE']);
        $this->seedDiscount(['code' => 'OTHER']);

        $response = $this->controller()->index(
            new DiscountListQuery(q: 'sale'),
            Request::create('/x')
        );

        $body = $this->json($response);
        self::assertSame(2, $body['total']);
        $codes = array_column($body['data'], 'code');
        sort($codes);
        self::assertSame(['SUMMERSALE', 'WINTERSALE'], $codes);
    }

    public function testIndexQFilterTreatsPercentAndUnderscoreAsLiterals(): void
    {
        $this->seedDiscount(['code' => '50%OFF']);
        $this->seedDiscount(['code' => '5000OFF']);
        $this->seedDiscount(['code' => 'A_B']);
        $this->seedDiscount(['code' => 'AXB']);

        // A literal '%' must not act as a wildcard: only the exact '50%OFF' code matches.
        $percentResponse = $this->controller()->index(
            new DiscountListQuery(q: '50%'),
            Request::create('/x')
        );
        $percentBody = $this->json($percentResponse);
        self::assertSame(1, $percentBody['total']);
        self::assertSame('50%OFF', $percentBody['data'][0]['code']);

        // A literal '_' must not act as a single-character wildcard.
        $underscoreResponse = $this->controller()->index(
            new DiscountListQuery(q: 'a_b'),
            Request::create('/x')
        );
        $underscoreBody = $this->json($underscoreResponse);
        self::assertSame(1, $underscoreBody['total']);
        self::assertSame('A_B', $underscoreBody['data'][0]['code']);
    }

    public function testIndexCombinedStatusAndQFilterAppliesBothPredicatesToCountAndRows(): void
    {
        $this->seedDiscount(['code' => 'SUMMERSALE', 'status' => 'active']);
        $this->seedDiscount(['code' => 'SUMMEREND', 'status' => 'inactive']);

        $response = $this->controller()->index(
            new DiscountListQuery(status: 'active', q: 'summer'),
            Request::create('/x')
        );

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertCount(1, $body['data']);
        self::assertSame('SUMMERSALE', $body['data'][0]['code']);
    }

    public function testIndexOrdersByCreatedAtDescWithStableUuidTieBreak(): void
    {
        // Both rows share the exact same created_at -- the primary sort key ties,
        // so the secondary uuid-ascending sort must decide (and stay stable across
        // repeated reads), never depend on insertion/physical row order.
        $tiedAt = '2026-01-01 00:00:00';
        $this->seedDiscount(['uuid' => 'disctie00002', 'code' => 'TIEB', 'created_at' => $tiedAt]);
        $this->seedDiscount(['uuid' => 'disctie00001', 'code' => 'TIEA', 'created_at' => $tiedAt]);

        $response = $this->controller()->index(new DiscountListQuery(), Request::create('/x'));
        $uuids = array_column($this->json($response)['data'], 'uuid');

        self::assertSame(['disctie00001', 'disctie00002'], $uuids);
    }

    public function testIndexPaginatesWithClamp(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->seedDiscount(['code' => 'PAGE' . $i]);
        }

        $response = $this->controller()->index(
            new DiscountListQuery(page: 1, per_page: 2),
            Request::create('/x')
        );

        $body = $this->json($response);
        self::assertSame(3, $body['total']);
        self::assertCount(2, $body['data']);
        self::assertSame(2, $body['per_page']);
    }

    // --- PATCH: guarded claim ---------------------------------------------------

    public function testUpdateBumpsRevisionAndAppliesChanges(): void
    {
        $uuid = $this->seedDiscount(['code' => 'PATCHME']);

        $response = $this->controller()->update($this->patchRequest(['status' => 'inactive']), $uuid);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('inactive', $data['status']);
        self::assertSame(1, (int) $data['revision']);
    }

    public function testUpdateUnknownDiscountThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['status' => 'inactive']), 'no-such-disc');
    }

    public function testUpdateCrossTenantDiscountThrowsNotFound(): void
    {
        $uuid = $this->seedDiscount(['code' => 'OTHERT2'], 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['status' => 'inactive']), $uuid);
    }

    /**
     * Deterministic stand-in for the PATCH-vs-DELETE claim race (mirrors
     * ShippingClassEndpointTest's class-delete-vs-variant-assign test): an admin
     * reads the discount, then -- before their PATCH arrives -- someone else
     * fully deletes it (claim + redemption probe + hard delete, one committed
     * transaction). The PATCH's own claim on the now-gone row affects zero rows,
     * which must resolve as a non-revealing 404, never a false-success update.
     */
    public function testUpdateAfterDeleteBetweenReadAndUpdateNeverFalseSuccess(): void
    {
        $uuid = $this->seedDiscount(['code' => 'RACEPATCH']);

        $read = $this->controller()->show(Request::create('/x'), $uuid);
        self::assertSame(200, $read->getStatusCode());

        $deleted = $this->controller()->destroy(Request::create('/x', 'DELETE'), $uuid);
        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $deleted->getStatusCode());

        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['status' => 'inactive']), $uuid);
    }

    // --- DELETE: guarded hard delete vs. redemption -----------------------------

    public function testDeleteUnknownDiscountThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), 'no-such-disc');
    }

    public function testDeleteCrossTenantDiscountThrowsNotFound(): void
    {
        $uuid = $this->seedDiscount(['code' => 'OTHERT3'], 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), $uuid);
    }

    public function testDeleteWithZeroRedemptionsSucceeds(): void
    {
        $uuid = $this->seedDiscount(['code' => 'CLEANDEL']);

        $response = $this->controller()->destroy(Request::create('/x', 'DELETE'), $uuid);

        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull((new DiscountRepository())->findByUuid($this->context, '', $uuid));
    }

    public function testDeleteRedeemedDiscountReturns409WithTheDisableViaStatusHintAndRowIntact(): void
    {
        $uuid = $this->seedDiscount(['code' => 'REDEEMED']);
        $this->seedRedemption($uuid);

        $response = $this->controller()->destroy(Request::create('/x', 'DELETE'), $uuid);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('status', strtolower((string) $this->json($response)['message']));
        self::assertNotNull((new DiscountRepository())->findByUuid($this->context, '', $uuid));
    }

    /**
     * Ordering 1 of the shared-claim invariant ({@see DiscountService} class
     * docblock): delete's claim commits first, so checkout's later
     * `consumeUsage()` UPDATE -- unblocked only after the delete's transaction
     * commits -- matches zero rows (the row is gone). `consume()` must throw,
     * which is exactly what rolls checkout's whole order back.
     */
    public function testDeleteFirstThenConsumeAttemptAffectsZeroRowsAndThrows(): void
    {
        $uuid = $this->seedDiscount(['code' => 'RACEA', 'once_per_buyer' => 0]);
        $discount = (new DiscountRepository())->findByUuid($this->context, '', $uuid);
        self::assertNotNull($discount);

        $deleted = $this->controller()->destroy(Request::create('/x', 'DELETE'), $uuid);
        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $deleted->getStatusCode());

        $this->expectException(ValidationException::class);
        $this->discountService()->consume($this->context, $discount, 'order0000010', 'buyer@x.test');
    }

    /**
     * Ordering 2 of the shared-claim invariant: checkout's `consumeUsage()` +
     * redemption insert commit first (inside checkout's own transaction).
     * Delete's claim, once unblocked, still succeeds (the row exists) but its
     * post-claim redemption probe -- run in the SAME transaction, after the
     * claim -- now observes the committed redemption and refuses with 409; the
     * row is left completely intact.
     */
    public function testConsumeFirstThenDeleteReturns409WithRowIntact(): void
    {
        $uuid = $this->seedDiscount(['code' => 'RACEB', 'once_per_buyer' => 0]);
        $discount = (new DiscountRepository())->findByUuid($this->context, '', $uuid);
        self::assertNotNull($discount);

        $this->discountService()->consume($this->context, $discount, 'order0000011', 'buyer2@x.test');

        $response = $this->controller()->destroy(Request::create('/x', 'DELETE'), $uuid);

        self::assertSame(409, $response->getStatusCode());
        self::assertNotNull((new DiscountRepository())->findByUuid($this->context, '', $uuid));
    }

    // --- Helpers -----------------------------------------------------------

    /** @param array<string,mixed> $overrides */
    private function seedDiscount(array $overrides, string $tenant = ''): string
    {
        $uuid = (string) ($overrides['uuid'] ?? 'disc' . substr(md5((string) ($overrides['code'] ?? 'CODE') . $tenant), 0, 8));
        $this->connection->table('commerce_discounts')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'code' => 'SAVE10',
            'type' => 'percentage',
            'value' => 1000,
            'usage_limit' => null,
            'once_per_buyer' => 0,
            'usage_count' => 0,
            'status' => 'active',
        ], $overrides));

        return $uuid;
    }

    private function seedRedemption(string $discountUuid, string $tenant = ''): void
    {
        $this->connection->table('commerce_discount_redemptions')->insert([
            'uuid' => 'redm' . substr(md5($discountUuid), 0, 8),
            'tenant_uuid' => $tenant,
            'discount_uuid' => $discountUuid,
            'order_uuid' => 'order0000099',
            'buyer_identity' => 'buyer@x.test',
            'buyer_key' => 'order0000099',
        ]);
    }

    private function controller(string $tenant = ''): AdminDiscountController
    {
        return new AdminDiscountController(
            $this->context,
            new DiscountRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant),
            $this->discountService($tenant)
        );
    }

    private function discountService(string $tenant = ''): DiscountService
    {
        return new DiscountService(
            new DiscountRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant)
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

    /** @param array<string,mixed> $body */
    private function patchRequest(array $body): Request
    {
        $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
