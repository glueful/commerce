<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Auth\ApiKey\Exceptions\InsufficientScopeException;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewService;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReviewController;
use Glueful\Extensions\Commerce\Http\DTOs\BulkPriceData;
use Glueful\Extensions\Commerce\Http\DTOs\BulkPriceItemData;
use Glueful\Extensions\Commerce\Http\DTOs\BulkReviewData;
use Glueful\Extensions\Commerce\Http\DTOs\BulkStatusData;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingClassService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Routing\Middleware\RequireScopeMiddleware;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Layer 6 Task 2: compositional guarded product/variant patch primitives plus
 * the three bulk endpoints (design spec §2/decision 5, plan Global
 * Constraints "Shared guarded mutations..." and "Bulk"). Covers:
 *  - the whole-request `#[ArrayOf]`/`ValidatesSelf` 422 boundary (cap-100,
 *    duplicates, malformed nested items, negative price, bad vocabulary) --
 *    always BEFORE any write;
 *  - per-item mixed outcomes with input order preserved;
 *  - the closed reason vocabularies (product/variant `not_found`; review
 *    `not_found|invalid_transition`) including replay convergence;
 *  - an unexpected (uncaught) exception aborting the request rather than
 *    becoming a fabricated item failure;
 *  - `commerce:write` scope enforcement;
 *  - the compositional (not sequential) claim proof: a multi-field product or
 *    variant PATCH claims its row lock EXACTLY ONCE and commits atomically,
 *    and a late validation failure leaves every field unchanged.
 */
final class BulkEndpointsTest extends CommerceTestCase
{
    // =====================================================================
    // Products: POST /commerce/admin/products/bulk-status
    // =====================================================================

    public function testBulkStatusMixedOutcomesPreserveInputOrder(): void
    {
        $a = $this->seedActiveProduct('blkstA00001', 'blk-st-a-1');
        $b = $this->seedActiveProduct('blkstA00002', 'blk-st-a-2');

        $response = $this->adminProductController()->bulkStatus(
            new BulkStatusData(uuids: [$a['uuid'], 'no-such-prod-1', $b['uuid'], 'no-such-prod-2'], status: 'archived'),
            Request::create('/x', 'POST')
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame([$a['uuid'], $b['uuid']], $data['applied']);
        self::assertSame(
            [
                ['uuid' => 'no-such-prod-1', 'reason' => 'not_found'],
                ['uuid' => 'no-such-prod-2', 'reason' => 'not_found'],
            ],
            $data['failed']
        );

        $rowA = (new ProductRepository())->findLiveByUuid($this->context, '', $a['uuid']);
        self::assertSame('archived', $rowA['status']);
    }

    public function testBulkStatusTombstonedProductReturnsNotFound(): void
    {
        $product = $this->seedActiveProduct('blkstB00001', 'blk-st-b-1');
        $this->catalog()->deleteProduct($this->context, $product['uuid']);

        $response = $this->adminProductController()->bulkStatus(
            new BulkStatusData(uuids: [$product['uuid']], status: 'active'),
            Request::create('/x', 'POST')
        );

        $data = $this->json($response)['data'];
        self::assertSame([], $data['applied']);
        self::assertSame([['uuid' => $product['uuid'], 'reason' => 'not_found']], $data['failed']);
    }

    public function testBulkStatusCapExceededRejectsWholeRequestBeforeAnyWrite(): void
    {
        $product = $this->seedActiveProduct('blkstC00001', 'blk-st-c-1');
        $uuids = [$product['uuid']];
        for ($i = 0; $i < 100; $i++) {
            $uuids[] = 'filler-' . $i;
        }
        self::assertCount(101, $uuids);

        try {
            (new RequestDataHydrator())->hydrate(
                BulkStatusData::class,
                ['uuids' => $uuids, 'status' => 'archived'],
                [],
                []
            );
            self::fail('expected ValidationException for a 101-item batch');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('uuids', $e->errors());
        }

        $row = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame('active', $row['status'], 'no item may mutate when the whole request is rejected');
    }

    public function testBulkStatusDuplicateUuidRejectsWholeRequestBeforeAnyWrite(): void
    {
        $product = $this->seedActiveProduct('blkstD00001', 'blk-st-d-1');

        try {
            (new RequestDataHydrator())->hydrate(
                BulkStatusData::class,
                ['uuids' => [$product['uuid'], $product['uuid']], 'status' => 'archived'],
                [],
                []
            );
            self::fail('expected ValidationException for duplicate uuids');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('uuids', $e->errors());
        }

        $row = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame('active', $row['status']);
    }

    public function testBulkStatusBadVocabularyRejectsWholeRequestBeforeAnyWrite(): void
    {
        $product = $this->seedActiveProduct('blkstE00001', 'blk-st-e-1');

        try {
            (new RequestDataHydrator())->hydrate(
                BulkStatusData::class,
                ['uuids' => [$product['uuid']], 'status' => 'published'],
                [],
                []
            );
            self::fail('expected ValidationException for an unknown status');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors());
        }

        $row = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame('active', $row['status']);
    }

    public function testBulkStatusMalformedUuidElementRejectsWholeRequest(): void
    {
        try {
            (new RequestDataHydrator())->hydrate(
                BulkStatusData::class,
                ['uuids' => [123, 'ok-uuid-001'], 'status' => 'active'],
                [],
                []
            );
            self::fail('expected ValidationException for a non-string uuid element');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('uuids.0', $e->errors());
        }
    }

    public function testBulkStatusHydratesScalarUuidsArray(): void
    {
        $dto = (new RequestDataHydrator())->hydrate(
            BulkStatusData::class,
            ['uuids' => ['abcdef123456', 'ghijkl123456'], 'status' => 'draft'],
            [],
            []
        );

        self::assertSame(['abcdef123456', 'ghijkl123456'], $dto->uuids);
        self::assertSame('draft', $dto->status);
    }

    public function testBulkStatusRequiresWriteScope(): void
    {
        $product = $this->seedActiveProduct('blkstF00001', 'blk-st-f-1');
        $call = fn (): HttpResponse => $this->adminProductController()->bulkStatus(
            new BulkStatusData(uuids: [$product['uuid']], status: 'archived'),
            Request::create('/x', 'POST')
        );

        $allowed = $this->dispatchScoped(['commerce:write'], $call);
        self::assertSame(200, $allowed->getStatusCode());

        $this->expectException(InsufficientScopeException::class);
        $this->dispatchScoped(['commerce:read'], $call);
    }

    /**
     * A non-`NotFoundException` failure (here: an invalid status value that
     * bypassed the DTO's `ValidatesSelf` whole-request boundary by
     * constructing the DTO directly, mirroring the house pattern for
     * exercising a service-layer guard in isolation) must propagate uncaught
     * out of the bulk loop -- never get caught and reported as a `failed`
     * item. In real HTTP traffic this becomes a request-level 500.
     */
    public function testBulkStatusUnexpectedExceptionPropagatesUncaughtNotFabricatedFailure(): void
    {
        $product = $this->seedActiveProduct('blkstG00001', 'blk-st-g-1');

        try {
            $this->adminProductController()->bulkStatus(
                new BulkStatusData(uuids: [$product['uuid']], status: 'bogus-status'),
                Request::create('/x', 'POST')
            );
            self::fail('expected the ValidationException to propagate uncaught');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors());
        }

        $row = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame('active', $row['status'], 'the rolled-back claim must leave status unchanged');
    }

    // =====================================================================
    // Variants: POST /commerce/admin/variants/bulk-price
    // =====================================================================

    public function testBulkPriceMixedOutcomesPreserveInputOrder(): void
    {
        $a = $this->seedActiveProduct('blkprA00001', 'blk-pr-a-1');
        $b = $this->seedActiveProduct('blkprA00002', 'blk-pr-a-2');
        $variantA = (string) $a['variants'][0]['uuid'];
        $variantB = (string) $b['variants'][0]['uuid'];

        $response = $this->adminProductController()->bulkPrice(
            new BulkPriceData(items: [
                new BulkPriceItemData(uuid: $variantA, price: 1500),
                new BulkPriceItemData(uuid: 'no-such-var-1', price: 2000),
                new BulkPriceItemData(uuid: $variantB, price: 2500),
            ]),
            Request::create('/x', 'POST')
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame([$variantA, $variantB], $data['applied']);
        self::assertSame([['uuid' => 'no-such-var-1', 'reason' => 'not_found']], $data['failed']);

        $rowA = (new VariantRepository())->findByUuid($this->context, '', $variantA);
        $rowB = (new VariantRepository())->findByUuid($this->context, '', $variantB);
        self::assertSame(1500, (int) $rowA['price']);
        self::assertSame(2500, (int) $rowB['price']);
    }

    public function testBulkPriceCapExceededRejectsWholeRequestBeforeAnyWrite(): void
    {
        $product = $this->seedActiveProduct('blkprB00001', 'blk-pr-b-1');
        $variant = (string) $product['variants'][0]['uuid'];

        $items = [['uuid' => $variant, 'price' => 999]];
        for ($i = 0; $i < 100; $i++) {
            $items[] = ['uuid' => 'filler-' . $i, 'price' => 100];
        }
        self::assertCount(101, $items);

        try {
            (new RequestDataHydrator())->hydrate(BulkPriceData::class, ['items' => $items], [], []);
            self::fail('expected ValidationException for a 101-item batch');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('items', $e->errors());
        }

        $row = (new VariantRepository())->findByUuid($this->context, '', $variant);
        self::assertSame(1000, (int) $row['price'], 'no item may mutate when the whole request is rejected');
    }

    public function testBulkPriceDuplicateUuidRejectsWholeRequestBeforeAnyWrite(): void
    {
        $product = $this->seedActiveProduct('blkprC00001', 'blk-pr-c-1');
        $variant = (string) $product['variants'][0]['uuid'];

        try {
            (new RequestDataHydrator())->hydrate(
                BulkPriceData::class,
                ['items' => [['uuid' => $variant, 'price' => 100], ['uuid' => $variant, 'price' => 200]]],
                [],
                []
            );
            self::fail('expected ValidationException for duplicate uuids across items');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('items', $e->errors());
        }

        $row = (new VariantRepository())->findByUuid($this->context, '', $variant);
        self::assertSame(1000, (int) $row['price']);
    }

    public function testBulkPriceMalformedNestedItemRejectsWholeRequestBeforeAnyWrite(): void
    {
        $product = $this->seedActiveProduct('blkprD00001', 'blk-pr-d-1');
        $variant = (string) $product['variants'][0]['uuid'];

        try {
            (new RequestDataHydrator())->hydrate(
                BulkPriceData::class,
                ['items' => [['uuid' => $variant, 'price' => 500], ['uuid' => 12345, 'price' => 100]]],
                [],
                []
            );
            self::fail('expected ValidationException for a non-string nested uuid');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('items.1.uuid', $e->errors());
        }

        $row = (new VariantRepository())->findByUuid($this->context, '', $variant);
        self::assertSame(1000, (int) $row['price'], 'the whole request must reject before the valid item writes');
    }

    public function testBulkPriceMissingNestedFieldRejectsWholeRequest(): void
    {
        try {
            (new RequestDataHydrator())->hydrate(
                BulkPriceData::class,
                ['items' => [['uuid' => 'okvariant001']]],
                [],
                []
            );
            self::fail('expected ValidationException for a missing nested price field');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('items.0.price', $e->errors());
        }
    }

    public function testBulkPriceNegativePriceRejectsWholeRequestBeforeAnyWrite(): void
    {
        $product = $this->seedActiveProduct('blkprE00001', 'blk-pr-e-1');
        $variant = (string) $product['variants'][0]['uuid'];

        try {
            (new RequestDataHydrator())->hydrate(
                BulkPriceData::class,
                ['items' => [['uuid' => $variant, 'price' => -100]]],
                [],
                []
            );
            self::fail('expected ValidationException for a negative price');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('items.0.price', $e->errors());
        }

        $row = (new VariantRepository())->findByUuid($this->context, '', $variant);
        self::assertSame(1000, (int) $row['price']);
    }

    public function testBulkPriceHydratesNestedItemDtos(): void
    {
        $dto = (new RequestDataHydrator())->hydrate(
            BulkPriceData::class,
            ['items' => [['uuid' => 'var00000001', 'price' => 1500]]],
            [],
            []
        );

        self::assertCount(1, $dto->items);
        self::assertInstanceOf(BulkPriceItemData::class, $dto->items[0]);
        self::assertSame('var00000001', $dto->items[0]->uuid);
        self::assertSame(1500, $dto->items[0]->price);
    }

    public function testBulkPriceRequiresWriteScope(): void
    {
        $product = $this->seedActiveProduct('blkprF00001', 'blk-pr-f-1');
        $variant = (string) $product['variants'][0]['uuid'];
        $call = fn (): HttpResponse => $this->adminProductController()->bulkPrice(
            new BulkPriceData(items: [new BulkPriceItemData(uuid: $variant, price: 1200)]),
            Request::create('/x', 'POST')
        );

        $allowed = $this->dispatchScoped(['commerce:write'], $call);
        self::assertSame(200, $allowed->getStatusCode());

        $this->expectException(InsufficientScopeException::class);
        $this->dispatchScoped(['commerce:read'], $call);
    }

    public function testBulkPriceUnexpectedExceptionPropagatesUncaughtNotFabricatedFailure(): void
    {
        $product = $this->seedActiveProduct('blkprG00001', 'blk-pr-g-1');
        $variant = (string) $product['variants'][0]['uuid'];

        try {
            $this->adminProductController()->bulkPrice(
                new BulkPriceData(items: [new BulkPriceItemData(uuid: $variant, price: -500)]),
                Request::create('/x', 'POST')
            );
            self::fail('expected the ValidationException to propagate uncaught');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('price', $e->errors());
        }

        $row = (new VariantRepository())->findByUuid($this->context, '', $variant);
        self::assertSame(1000, (int) $row['price'], 'the rejected price must never be written');
    }

    // =====================================================================
    // Reviews: POST /commerce/admin/reviews/bulk
    // =====================================================================

    public function testBulkReviewApproveMixedOutcomesPreserveInputOrder(): void
    {
        $product = $this->seedActiveProduct('blkrvA00001', 'blk-rv-a-1');
        $r1 = $this->seedReview($product['uuid'], 'blkrvrev001');
        $r3 = $this->seedReview($product['uuid'], 'blkrvrev003');

        $response = $this->adminReviewController()->bulk(
            new BulkReviewData(action: 'approve', uuids: [$r1['uuid'], 'no-such-review', $r3['uuid']]),
            Request::create('/x', 'POST')
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame([$r1['uuid'], $r3['uuid']], $data['applied']);
        self::assertSame([['uuid' => 'no-such-review', 'reason' => 'not_found']], $data['failed']);

        $row1 = (new ReviewRepository())->findByUuid($this->context, '', $r1['uuid']);
        self::assertSame('approved', $row1['status']);
    }

    public function testBulkReviewSpamReversesRollupWhenCurrentlyApproved(): void
    {
        $product = $this->seedActiveProduct('blkrvB00001', 'blk-rv-b-1');
        $review = $this->seedReview($product['uuid'], 'blkrvrev004', rating: 5);
        $this->reviews()->approve($this->context, $review['uuid']);

        $result = $this->reviews()->bulk($this->context, 'spam', [$review['uuid']]);

        self::assertSame([$review['uuid']], $result['applied']);
        self::assertSame([], $result['failed']);

        $productRow = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(0, (int) $productRow['rating_count'], 'spam must reverse the approve rollup');
    }

    public function testBulkReviewDeleteApprovedReviewReturnsInvalidTransitionRowIntact(): void
    {
        $product = $this->seedActiveProduct('blkrvC00001', 'blk-rv-c-1');
        $review = $this->seedReview($product['uuid'], 'blkrvrev005');
        $this->reviews()->approve($this->context, $review['uuid']);

        $result = $this->reviews()->bulk($this->context, 'delete', [$review['uuid']]);

        self::assertSame([], $result['applied']);
        self::assertSame([['uuid' => $review['uuid'], 'reason' => 'invalid_transition']], $result['failed']);
        self::assertNotNull((new ReviewRepository())->findByUuid($this->context, '', $review['uuid']));
    }

    public function testBulkReviewDeleteUnknownReviewReturnsNotFound(): void
    {
        $result = $this->reviews()->bulk($this->context, 'delete', ['no-such-review']);

        self::assertSame([], $result['applied']);
        self::assertSame([['uuid' => 'no-such-review', 'reason' => 'not_found']], $result['failed']);
    }

    public function testBulkReviewDeletePendingReviewApplies(): void
    {
        $product = $this->seedActiveProduct('blkrvD00001', 'blk-rv-d-1');
        $review = $this->seedReview($product['uuid'], 'blkrvrev006');

        $result = $this->reviews()->bulk($this->context, 'delete', [$review['uuid']]);

        self::assertSame([$review['uuid']], $result['applied']);
        self::assertNull((new ReviewRepository())->findByUuid($this->context, '', $review['uuid']));
    }

    /**
     * Replay convergence (plan Global Constraints "Bulk"): re-running the
     * SAME bulk approve against an already-approved review reports
     * `invalid_transition` in `failed` rather than corrupting state or
     * double-applying the rating rollup.
     */
    public function testBulkReviewApproveReplayConvergesToInvalidTransitionNoDoubleRollup(): void
    {
        $product = $this->seedActiveProduct('blkrvE00001', 'blk-rv-e-1');
        $review = $this->seedReview($product['uuid'], 'blkrvrev007', rating: 4);

        $first = $this->reviews()->bulk($this->context, 'approve', [$review['uuid']]);
        self::assertSame([$review['uuid']], $first['applied']);
        self::assertSame([], $first['failed']);

        $second = $this->reviews()->bulk($this->context, 'approve', [$review['uuid']]);
        self::assertSame([], $second['applied']);
        self::assertSame([['uuid' => $review['uuid'], 'reason' => 'invalid_transition']], $second['failed']);

        $productRow = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(1, (int) $productRow['rating_count']);
        self::assertSame(4, (int) $productRow['rating_sum']);
    }

    public function testBulkReviewBadActionVocabularyRejectsWholeRequestBeforeAnyWrite(): void
    {
        $product = $this->seedActiveProduct('blkrvF00001', 'blk-rv-f-1');
        $review = $this->seedReview($product['uuid'], 'blkrvrev008');

        try {
            (new RequestDataHydrator())->hydrate(
                BulkReviewData::class,
                ['action' => 'reject', 'uuids' => [$review['uuid']]],
                [],
                []
            );
            self::fail('expected ValidationException for an unknown action');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('action', $e->errors());
        }

        $row = (new ReviewRepository())->findByUuid($this->context, '', $review['uuid']);
        self::assertSame('pending', $row['status']);
    }

    public function testBulkReviewDuplicateUuidRejectsWholeRequestBeforeAnyWrite(): void
    {
        $product = $this->seedActiveProduct('blkrvG00001', 'blk-rv-g-1');
        $review = $this->seedReview($product['uuid'], 'blkrvrev009');

        try {
            (new RequestDataHydrator())->hydrate(
                BulkReviewData::class,
                ['action' => 'approve', 'uuids' => [$review['uuid'], $review['uuid']]],
                [],
                []
            );
            self::fail('expected ValidationException for duplicate uuids');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('uuids', $e->errors());
        }

        $row = (new ReviewRepository())->findByUuid($this->context, '', $review['uuid']);
        self::assertSame('pending', $row['status']);
    }

    public function testBulkReviewRequiresWriteScope(): void
    {
        $product = $this->seedActiveProduct('blkrvH00001', 'blk-rv-h-1');
        $review = $this->seedReview($product['uuid'], 'blkrvrev010');
        $call = fn (): HttpResponse => $this->adminReviewController()->bulk(
            new BulkReviewData(action: 'approve', uuids: [$review['uuid']]),
            Request::create('/x', 'POST')
        );

        $allowed = $this->dispatchScoped(['commerce:write'], $call);
        self::assertSame(200, $allowed->getStatusCode());

        $this->expectException(InsufficientScopeException::class);
        $this->dispatchScoped(['commerce:read'], $call);
    }

    /**
     * An integrity violation ({@see \Glueful\Extensions\Commerce\Catalog\ReviewService::applyRollup()}'s
     * defensive throw when a review's own `product_uuid` no longer resolves)
     * is a genuinely unexpected failure, not a client-triggerable one: it must
     * propagate uncaught out of `ReviewService::bulk()` rather than becoming a
     * `failed` entry. A prior item that already committed its OWN transaction
     * stays applied; the failing item's own transaction rolls back.
     */
    public function testBulkReviewUnexpectedRollupIntegrityFailurePropagatesUncaughtNotFabricatedFailure(): void
    {
        $product = $this->seedActiveProduct('blkrvI00001', 'blk-rv-i-1');
        $good = $this->seedReview($product['uuid'], 'blkrvrev011');

        $orphanUuid = 'blkrvorph01';
        $this->connection->table('commerce_reviews')->insert([
            'uuid' => $orphanUuid,
            'tenant_uuid' => '',
            'product_uuid' => 'no-such-product',
            'user_uuid' => null,
            'author_name' => 'Ghost',
            'author_email' => 'ghost@example.com',
            'rating' => 3,
            'body' => 'This review references a product that no longer resolves at all.',
            'status' => 'pending',
        ]);

        try {
            $this->reviews()->bulk($this->context, 'approve', [$good['uuid'], $orphanUuid]);
            self::fail('expected the rollup integrity RuntimeException to propagate uncaught');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('rollup failed', $e->getMessage());
        }

        $goodRow = (new ReviewRepository())->findByUuid($this->context, '', $good['uuid']);
        self::assertSame('approved', $goodRow['status'], 'a prior item that already committed stays applied');

        $orphanRow = (new ReviewRepository())->findByUuid($this->context, '', $orphanUuid);
        self::assertSame('pending', $orphanRow['status'], "the failing item's own transaction must roll back");
    }

    // =====================================================================
    // Compositional (not sequential) shared-claim proofs
    // =====================================================================

    public function testMultiFieldProductPatchStatusAndMetadataClaimsRevisionExactlyOnceAndCommitsAtomically(): void
    {
        $product = $this->seedActiveProduct('blkcpA00001', 'blk-cp-a-1');

        $response = $this->adminProductController()->update(
            $this->patchRequest(['status' => 'archived', 'metadata' => ['note' => 'bulk-composed']]),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $row = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame('archived', $row['status']);
        self::assertSame(['note' => 'bulk-composed'], $row['metadata']);
        self::assertSame(1, (int) $row['catalog_revision'], 'a multi-field guarded patch claims exactly once');
    }

    public function testSetProductStatusDelegatesToSameSingleClaimPrimitive(): void
    {
        $product = $this->seedActiveProduct('blkcpB00001', 'blk-cp-b-1');

        $this->catalog()->setProductStatus($this->context, $product['uuid'], 'archived');

        $row = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame('archived', $row['status']);
        self::assertSame(1, (int) $row['catalog_revision']);
    }

    public function testLateValidationFailureOnMultiFieldProductPatchLeavesEveryFieldUnchanged(): void
    {
        $product = $this->catalog()->createProduct($this->context, [
            'slug' => 'blk-cp-late',
            'name' => 'Bulk Composed Late Failure',
            'type' => 'external',
            'status' => 'draft',
            'metadata' => ['external_url' => 'https://example.com/original'],
            'variants' => [],
        ]);

        $response = $this->adminProductController()->update(
            $this->patchRequest(['status' => 'active', 'metadata' => ['button_label' => 'Shop Now']]),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('metadata.external_url', $this->json($response)['error']['details']);

        $row = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame('draft', $row['status'], 'status must NOT have been written ahead of the later failure');
        self::assertSame(['external_url' => 'https://example.com/original'], $row['metadata']);
        self::assertSame(0, (int) $row['catalog_revision'], 'the claim itself must roll back on validation failure');
    }

    public function testMultiFieldVariantPatchPriceAndShippingClassClaimsParentRevisionExactlyOnce(): void
    {
        $class = $this->createClass('fragile', 'Fragile');
        $product = $this->seedActiveProduct('blkcpC00001', 'blk-cp-c-1');
        $variant = (string) $product['variants'][0]['uuid'];

        $response = $this->adminProductController()->updateVariant(
            $this->patchRequest(['price' => 2500, 'shipping_class_uuid' => $class['uuid']]),
            $variant
        );

        self::assertSame(200, $response->getStatusCode());
        $variantRow = (new VariantRepository())->findByUuid($this->context, '', $variant);
        self::assertSame(2500, (int) $variantRow['price']);
        self::assertSame($class['uuid'], $variantRow['shipping_class_uuid']);

        $productRow = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(
            1,
            (int) $productRow['catalog_revision'],
            'the parent product row must be claimed exactly once for a combined price+shipping_class patch'
        );

        $classRow = $this->connection->table('commerce_shipping_classes')->where('uuid', '=', $class['uuid'])->first();
        self::assertSame(1, (int) $classRow['revision']);
    }

    /**
     * Preserves the L4 §6 sorted current/proposed shipping-class claim
     * protocol when BOTH a real reassignment (class A -> class B) and a price
     * change land in the SAME patch: both classes get claimed exactly once
     * each, the parent product row gets claimed exactly once overall.
     */
    public function testMultiFieldVariantPatchReassignsShippingClassAndPreservesSortedClaimProtocol(): void
    {
        $classA = $this->createClass('fragile', 'Fragile');
        $classB = $this->createClass('oversized', 'Oversized');
        $product = $this->seedProductWithVariantClass('blkcpD00001', 'blk-cp-d-1', $classA['uuid']);
        $variant = (string) $product['variants'][0]['uuid'];

        $response = $this->adminProductController()->updateVariant(
            $this->patchRequest(['price' => 3000, 'shipping_class_uuid' => $classB['uuid']]),
            $variant
        );

        self::assertSame(200, $response->getStatusCode());
        $variantRow = (new VariantRepository())->findByUuid($this->context, '', $variant);
        self::assertSame(3000, (int) $variantRow['price']);
        self::assertSame($classB['uuid'], $variantRow['shipping_class_uuid']);

        $productRow = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(1, (int) $productRow['catalog_revision']);

        $classARow = $this->connection->table('commerce_shipping_classes')
            ->where('uuid', '=', $classA['uuid'])->first();
        $classBRow = $this->connection->table('commerce_shipping_classes')
            ->where('uuid', '=', $classB['uuid'])->first();
        // classA starts at revision 1 (claimed once already at product-create
        // time via claimShippingClassesForCreate()); the guarded patch below
        // claims it a SECOND time as the vacated "current" class -> 2. classB
        // was never referenced before this patch, so its ONE claim lands at 1.
        self::assertSame(2, (int) $classARow['revision'], 'the vacated current class must still be claimed');
        self::assertSame(1, (int) $classBRow['revision'], 'the newly proposed class must be claimed');
    }

    public function testSetVariantPriceDelegatesToSameSingleClaimPrimitive(): void
    {
        $product = $this->seedActiveProduct('blkcpE00001', 'blk-cp-e-1');
        $variant = (string) $product['variants'][0]['uuid'];

        $this->catalog()->setVariantPrice($this->context, $variant, 1750);

        $variantRow = (new VariantRepository())->findByUuid($this->context, '', $variant);
        self::assertSame(1750, (int) $variantRow['price']);

        $productRow = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(1, (int) $productRow['catalog_revision']);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return array<string,mixed> */
    private function seedActiveProduct(string $uuid, string $slug, string $tenant = ''): array
    {
        return $this->catalog($tenant)->createProduct($this->context, [
            'slug' => $slug,
            'name' => $uuid,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper($uuid),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
    }

    /** @return array<string,mixed> */
    private function seedProductWithVariantClass(
        string $uuid,
        string $slug,
        string $classUuid,
        string $tenant = ''
    ): array {
        return $this->catalog($tenant)->createProduct($this->context, [
            'slug' => $slug,
            'name' => $uuid,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper($uuid),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
                'shipping_class_uuid' => $classUuid,
            ]],
        ]);
    }

    /** @return array<string,mixed> */
    private function seedReview(string $productUuid, string $uuid, int $rating = 4, string $tenant = ''): array
    {
        return $this->reviews($tenant)->create($this->context, [
            'product_uuid' => $productUuid,
            'rating' => $rating,
            'body' => 'A sufficiently long review body to satisfy validation requirements.',
            'author_name' => 'Reviewer',
            'author_email' => 'reviewer@example.com',
        ]);
    }

    /** @return array<string,mixed> */
    private function createClass(string $slug, string $name, string $tenant = ''): array
    {
        $service = new ShippingClassService(
            new ShippingClassRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant)
        );

        return $service->create($this->context, ['slug' => $slug, 'name' => $name]);
    }

    private function catalog(string $tenant = ''): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant),
            new StockRepository(),
            new ProductChildrenRepository(),
            new ShippingClassRepository()
        );
    }

    private function reviews(string $tenant = ''): ReviewService
    {
        return new ReviewService(
            new ReviewRepository(),
            new ProductRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant)
        );
    }

    private function adminProductController(string $tenant = ''): AdminProductController
    {
        return new AdminProductController(
            $this->context,
            $this->catalog($tenant),
            new ProductRepository(),
            new VariantRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant),
            new ShippingClassRepository()
        );
    }

    private function adminReviewController(string $tenant = ''): AdminReviewController
    {
        return new AdminReviewController($this->context, $this->reviews($tenant));
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

    /** @param list<string> $grantedScopes */
    private function dispatchScoped(array $grantedScopes, callable $next): HttpResponse
    {
        $request = Request::create('/x', 'POST');
        $request->attributes->set('api_key_scopes', $grantedScopes);

        return (new RequireScopeMiddleware())->handle(
            $request,
            static fn (Request $r): HttpResponse => $next(),
            'commerce:write'
        );
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
