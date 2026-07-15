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
use Glueful\Extensions\Commerce\Catalog\ReviewRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewService;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminReviewController;
use Glueful\Extensions\Commerce\Http\DTOs\CreateReviewData;
use Glueful\Extensions\Commerce\Http\DTOs\ProductListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\ReviewListQuery;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Review CRUD + moderation transitions + transactional rollups (design spec §5/§6):
 * admin create (always `pending`, never touches rollups), `pending -> approved |
 * spam` / `approved -> spam` transitions (each an affected-row-checked status
 * claim + rollup mutation in the SAME transaction), the single guarded delete
 * (`pending`/`spam` only), and the storefront `rating {average, count}` projection.
 */
final class ReviewEndpointTest extends CommerceTestCase
{
    // --- Create / validation matrix -----------------------------------------

    public function testCreateHappyPathLandsPendingAndDoesNotTouchRollup(): void
    {
        $product = $this->seedProduct('prodrevw0001');

        $response = $this->controller()->store($this->reviewData($product['uuid']), Request::create('/x', 'POST'));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->json($response)['data'];
        self::assertSame('pending', $data['status']);
        self::assertSame(5, (int) $data['rating']);
        self::assertSame('Jane Doe', $data['author_name']);
        self::assertSame('jane@example.com', $data['author_email']);

        $reloadedProduct = (new ProductRepository())->findByUuid($this->context, '', $product['uuid']);
        self::assertNotNull($reloadedProduct);
        self::assertSame(0, (int) $reloadedProduct['rating_sum']);
        self::assertSame(0, (int) $reloadedProduct['rating_count']);
    }

    public function testCreateAcceptsOptionalUserUuid(): void
    {
        $product = $this->seedProduct('prodrevw0002');

        $response = $this->controller()->store(
            $this->reviewData($product['uuid'], userUuid: 'userreview01'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('userreview01', $this->json($response)['data']['user_uuid']);
    }

    public function testCreateBlankProductUuidReturns422(): void
    {
        $response = $this->controller()->store(
            $this->reviewData('   '),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('product_uuid', $this->json($response)['error']['details']);
    }

    public function testCreateUnknownProductUuidReturns422(): void
    {
        $response = $this->controller()->store(
            $this->reviewData('no-such-product'),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('product_uuid', $this->json($response)['error']['details']);
    }

    public function testCreateCrossTenantProductUuidReturns422(): void
    {
        $product = $this->seedProduct('prodrevw0003', 'tenant-b');

        $response = $this->controller()->store(
            $this->reviewData($product['uuid']),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('product_uuid', $this->json($response)['error']['details']);
    }

    /** @dataProvider invalidRatingProvider */
    public function testCreateRatingOutOfBoundsReturns422(int $rating): void
    {
        $product = $this->seedProduct('prodrevwrtg' . $rating);

        $response = $this->controller()->store(
            $this->reviewData($product['uuid'], rating: $rating),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('rating', $this->json($response)['error']['details']);
    }

    /** @return iterable<string, array{0:int}> */
    public static function invalidRatingProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'six' => [6];
        yield 'far above range' => [100];
    }

    public function testCreateRatingOneAndFiveAreBothAccepted(): void
    {
        $productLow = $this->seedProduct('prodrevwlow1');
        $productHigh = $this->seedProduct('prodrevwhi1');

        $low = $this->controller()->store(
            $this->reviewData($productLow['uuid'], rating: 1),
            Request::create('/x', 'POST')
        );
        $high = $this->controller()->store(
            $this->reviewData($productHigh['uuid'], rating: 5),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $low->getStatusCode());
        self::assertSame(201, $high->getStatusCode());
        self::assertSame(1, (int) $this->json($low)['data']['rating']);
        self::assertSame(5, (int) $this->json($high)['data']['rating']);
    }

    public function testCreateInvalidAuthorEmailReturns422(): void
    {
        $product = $this->seedProduct('prodrevw0004');

        $response = $this->controller()->store(
            $this->reviewData($product['uuid'], authorEmail: 'not-an-email'),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('author_email', $this->json($response)['error']['details']);
    }

    public function testCreateBlankBodyReturns422(): void
    {
        $product = $this->seedProduct('prodrevw0005');

        $response = $this->controller()->store(
            $this->reviewData($product['uuid'], body: '   '),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('body', $this->json($response)['error']['details']);
    }

    public function testCreateBlankAuthorNameReturns422(): void
    {
        $product = $this->seedProduct('prodrevw0006');

        $response = $this->controller()->store(
            $this->reviewData($product['uuid'], authorName: '  '),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('author_name', $this->json($response)['error']['details']);
    }

    // --- List / filters / pagination ----------------------------------------

    public function testIndexFiltersByStatusAndProductAndPaginates(): void
    {
        $productA = $this->seedProduct('prodrevwidx1');
        $productB = $this->seedProduct('prodrevwidx2');

        $r1 = $this->createReview($productA['uuid']);
        $r2 = $this->createReview($productA['uuid']);
        $this->approve($r2['uuid']);
        $this->createReview($productB['uuid']);

        // Filter by product.
        $byProduct = $this->controller()->index(
            new ReviewListQuery(product: $productA['uuid']),
            Request::create('/x')
        );
        self::assertSame(200, $byProduct->getStatusCode());
        $byProductBody = $this->json($byProduct);
        self::assertSame(2, $byProductBody['total']);

        // Filter by status.
        $byStatus = $this->controller()->index(
            new ReviewListQuery(status: 'approved'),
            Request::create('/x')
        );
        $byStatusBody = $this->json($byStatus);
        self::assertSame(1, $byStatusBody['total']);
        self::assertSame($r2['uuid'], $byStatusBody['data'][0]['uuid']);

        // Combined filter.
        $combined = $this->controller()->index(
            new ReviewListQuery(status: 'pending', product: $productA['uuid']),
            Request::create('/x')
        );
        $combinedBody = $this->json($combined);
        self::assertSame(1, $combinedBody['total']);
        self::assertSame($r1['uuid'], $combinedBody['data'][0]['uuid']);

        // Pagination.
        $paged = $this->controller()->index(
            new ReviewListQuery(page: 1, per_page: 1),
            Request::create('/x')
        );
        $pagedBody = $this->json($paged);
        self::assertCount(1, $pagedBody['data']);
        self::assertSame(3, $pagedBody['total']);
    }

    // --- Approve -------------------------------------------------------------

    public function testApproveHappyPathAppliesRollup(): void
    {
        $product = $this->seedProduct('prodrevwapp1');
        $review = $this->createReview($product['uuid'], rating: 4);

        $response = $this->controller()->approve(Request::create('/x', 'POST'), $review['uuid']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('approved', $this->json($response)['data']['status']);

        $reloadedProduct = (new ProductRepository())->findByUuid($this->context, '', $product['uuid']);
        self::assertNotNull($reloadedProduct);
        self::assertSame(4, (int) $reloadedProduct['rating_sum']);
        self::assertSame(1, (int) $reloadedProduct['rating_count']);
    }

    public function testApproveAlreadyApprovedReturns409AndDoesNotDoubleRollup(): void
    {
        $product = $this->seedProduct('prodrevwapp2');
        $review = $this->createReview($product['uuid'], rating: 3);
        $this->approve($review['uuid']);

        $response = $this->controller()->approve(Request::create('/x', 'POST'), $review['uuid']);

        self::assertSame(409, $response->getStatusCode());

        $reloadedProduct = (new ProductRepository())->findByUuid($this->context, '', $product['uuid']);
        self::assertNotNull($reloadedProduct);
        self::assertSame(3, (int) $reloadedProduct['rating_sum']);
        self::assertSame(1, (int) $reloadedProduct['rating_count']);
    }

    public function testApproveSpamReviewReturns409(): void
    {
        $product = $this->seedProduct('prodrevwapp3');
        $review = $this->createReview($product['uuid']);
        $this->spam($review['uuid']);

        $response = $this->controller()->approve(Request::create('/x', 'POST'), $review['uuid']);

        self::assertSame(409, $response->getStatusCode());
    }

    public function testApproveUnknownReviewThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->approve(Request::create('/x', 'POST'), 'no-such-review');
    }

    public function testApproveCrossTenantReviewThrowsNotFound(): void
    {
        $product = $this->seedProduct('prodrevwapp4', 'tenant-b');
        $review = $this->createReview($product['uuid'], tenant: 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->approve(Request::create('/x', 'POST'), $review['uuid']);
    }

    // --- Spam ------------------------------------------------------------

    public function testSpamFromPendingHasNoRollupEffect(): void
    {
        $product = $this->seedProduct('prodrevwspm1');
        $review = $this->createReview($product['uuid'], rating: 5);

        $response = $this->controller()->spam(Request::create('/x', 'POST'), $review['uuid']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('spam', $this->json($response)['data']['status']);

        $reloadedProduct = (new ProductRepository())->findByUuid($this->context, '', $product['uuid']);
        self::assertNotNull($reloadedProduct);
        self::assertSame(0, (int) $reloadedProduct['rating_sum']);
        self::assertSame(0, (int) $reloadedProduct['rating_count']);
    }

    public function testSpamFromApprovedReversesRollup(): void
    {
        $product = $this->seedProduct('prodrevwspm2');
        $review = $this->createReview($product['uuid'], rating: 4);
        $this->approve($review['uuid']);

        $response = $this->controller()->spam(Request::create('/x', 'POST'), $review['uuid']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('spam', $this->json($response)['data']['status']);

        $reloadedProduct = (new ProductRepository())->findByUuid($this->context, '', $product['uuid']);
        self::assertNotNull($reloadedProduct);
        self::assertSame(0, (int) $reloadedProduct['rating_sum']);
        self::assertSame(0, (int) $reloadedProduct['rating_count']);
    }

    public function testSpamAlreadySpamReturns409(): void
    {
        $product = $this->seedProduct('prodrevwspm3');
        $review = $this->createReview($product['uuid']);
        $this->spam($review['uuid']);

        $response = $this->controller()->spam(Request::create('/x', 'POST'), $review['uuid']);

        self::assertSame(409, $response->getStatusCode());
    }

    public function testSpamUnknownReviewThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->spam(Request::create('/x', 'POST'), 'no-such-review');
    }

    public function testSpamCrossTenantReviewThrowsNotFound(): void
    {
        $product = $this->seedProduct('prodrevwspm4', 'tenant-b');
        $review = $this->createReview($product['uuid'], tenant: 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->spam(Request::create('/x', 'POST'), $review['uuid']);
    }

    // --- Delete --------------------------------------------------------------

    public function testDeletePendingReviewSucceeds(): void
    {
        $product = $this->seedProduct('prodrevwdel1');
        $review = $this->createReview($product['uuid']);

        $response = $this->controller()->destroy(Request::create('/x', 'DELETE'), $review['uuid']);

        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull((new ReviewRepository())->findByUuid($this->context, '', $review['uuid']));
    }

    public function testDeleteSpamReviewSucceeds(): void
    {
        $product = $this->seedProduct('prodrevwdel2');
        $review = $this->createReview($product['uuid']);
        $this->spam($review['uuid']);

        $response = $this->controller()->destroy(Request::create('/x', 'DELETE'), $review['uuid']);

        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull((new ReviewRepository())->findByUuid($this->context, '', $review['uuid']));
    }

    public function testDeleteApprovedReviewThrowsNotFoundAndLeavesRollupIntact(): void
    {
        $product = $this->seedProduct('prodrevwdel3');
        $review = $this->createReview($product['uuid'], rating: 5);
        $this->approve($review['uuid']);

        try {
            $this->controller()->destroy(Request::create('/x', 'DELETE'), $review['uuid']);
            self::fail('expected NotFoundException for an approved review delete attempt');
        } catch (NotFoundException $e) {
            // expected
        }

        self::assertNotNull((new ReviewRepository())->findByUuid($this->context, '', $review['uuid']));
        $reloadedProduct = (new ProductRepository())->findByUuid($this->context, '', $product['uuid']);
        self::assertNotNull($reloadedProduct);
        self::assertSame(5, (int) $reloadedProduct['rating_sum']);
        self::assertSame(1, (int) $reloadedProduct['rating_count']);
    }

    public function testDeleteUnknownReviewThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), 'no-such-review');
    }

    public function testDeleteCrossTenantReviewThrowsNotFound(): void
    {
        $product = $this->seedProduct('prodrevwdel4', 'tenant-b');
        $review = $this->createReview($product['uuid'], tenant: 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), $review['uuid']);
    }

    // --- Rollup math across full sequences ------------------------------------

    public function testRollupMathAcrossMultipleReviewsApproveSpamDeleteSequence(): void
    {
        $product = $this->seedProduct('prodrevwseq1');

        $a = $this->createReview($product['uuid'], rating: 5);
        $b = $this->createReview($product['uuid'], rating: 3);
        $c = $this->createReview($product['uuid'], rating: 1);

        $this->approve($a['uuid']);
        $this->approve($b['uuid']);
        $this->approve($c['uuid']);

        $afterThreeApprovals = (new ProductRepository())->findByUuid($this->context, '', $product['uuid']);
        self::assertNotNull($afterThreeApprovals);
        self::assertSame(9, (int) $afterThreeApprovals['rating_sum']);
        self::assertSame(3, (int) $afterThreeApprovals['rating_count']);

        // Retract b (approved -> spam): reverses its +3/+1 contribution.
        $this->spam($b['uuid']);
        $afterRetraction = (new ProductRepository())->findByUuid($this->context, '', $product['uuid']);
        self::assertNotNull($afterRetraction);
        self::assertSame(6, (int) $afterRetraction['rating_sum']);
        self::assertSame(2, (int) $afterRetraction['rating_count']);

        // Deleting the now-spam review does not touch the rollup further (it was
        // already reversed by the spam transition).
        $this->controller()->destroy(Request::create('/x', 'DELETE'), $b['uuid']);
        $afterDelete = (new ProductRepository())->findByUuid($this->context, '', $product['uuid']);
        self::assertNotNull($afterDelete);
        self::assertSame(6, (int) $afterDelete['rating_sum']);
        self::assertSame(2, (int) $afterDelete['rating_count']);
    }

    // --- Storefront rating projection -----------------------------------------

    public function testStorefrontShowOmitsRatingWhenNoApprovedReviews(): void
    {
        $product = $this->seedProduct('prodrevwsfp1');

        $response = $this->productController()->show(Request::create('/x'), (string) $product['slug']);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey('rating', $this->json($response)['data']);
    }

    public function testStorefrontShowIncludesRoundedAverageAndCountAfterApprovals(): void
    {
        $product = $this->seedProduct('prodrevwsfp2');
        $a = $this->createReview($product['uuid'], rating: 5);
        $b = $this->createReview($product['uuid'], rating: 4);
        $c = $this->createReview($product['uuid'], rating: 4);
        $this->approve($a['uuid']);
        $this->approve($b['uuid']);
        $this->approve($c['uuid']);

        $response = $this->productController()->show(Request::create('/x'), (string) $product['slug']);

        self::assertSame(200, $response->getStatusCode());
        $rating = $this->json($response)['data']['rating'];
        self::assertSame(3, $rating['count']);
        // (5 + 4 + 4) / 3 = 4.333... rounded to 1 decimal = 4.3.
        self::assertEqualsWithDelta(4.3, $rating['average'], 0.0001);
    }

    public function testStorefrontIndexEachItemGainsRating(): void
    {
        $product = $this->seedProduct('prodrevwsfp3');
        $review = $this->createReview($product['uuid'], rating: 5);
        $this->approve($review['uuid']);

        $response = $this->productController()->index(new ProductListQuery());

        self::assertSame(200, $response->getStatusCode());
        $item = $this->json($response)['data'][0];
        self::assertSame(['average', 'count'], array_keys($item['rating']));
        self::assertEqualsWithDelta(5.0, $item['rating']['average'], 0.0001);
        self::assertSame(1, $item['rating']['count']);
    }

    /**
     * There is no storefront review-list endpoint yet (L6), so the only surface
     * that could leak `commerce_reviews` internals today is the product payload
     * itself. Asserts the derived `rating` summary carries ONLY average/count and
     * that `author_email` (or any other review-table column) never appears
     * anywhere in the serialized storefront product response body.
     */
    public function testStorefrontProductPayloadCarriesNoReviewInternals(): void
    {
        $product = $this->seedProduct('prodrevwsfp4');
        $review = $this->createReview(
            $product['uuid'],
            rating: 5,
            authorEmail: 'super-secret-reviewer@example.com'
        );
        $this->approve($review['uuid']);

        $response = $this->productController()->show(Request::create('/x'), (string) $product['slug']);
        $body = (string) $response->getContent();

        self::assertStringNotContainsString('super-secret-reviewer@example.com', $body);
        self::assertStringNotContainsString('author_email', $body);
        self::assertStringNotContainsString('author_name', $body);

        $data = $this->json($response)['data'];
        self::assertSame(['average', 'count'], array_keys($data['rating']));
    }

    // --- Helpers ---------------------------------------------------------------

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

    private function reviewData(
        string $productUuid,
        int $rating = 5,
        string $body = 'Great product, would buy again.',
        string $authorName = 'Jane Doe',
        string $authorEmail = 'jane@example.com',
        ?string $userUuid = null
    ): CreateReviewData {
        return new CreateReviewData(
            product_uuid: $productUuid,
            rating: $rating,
            body: $body,
            author_name: $authorName,
            author_email: $authorEmail,
            user_uuid: $userUuid,
        );
    }

    /** @return array<string,mixed> */
    private function createReview(
        string $productUuid,
        int $rating = 5,
        string $authorEmail = 'jane@example.com',
        string $tenant = ''
    ): array {
        $response = $this->controller($tenant)->store(
            $this->reviewData($productUuid, rating: $rating, authorEmail: $authorEmail),
            Request::create('/x', 'POST')
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response)['data'];
    }

    /** @return array<string,mixed> */
    private function approve(string $uuid, string $tenant = ''): array
    {
        $response = $this->controller($tenant)->approve(Request::create('/x', 'POST'), $uuid);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response)['data'];
    }

    /** @return array<string,mixed> */
    private function spam(string $uuid, string $tenant = ''): array
    {
        $response = $this->controller($tenant)->spam(Request::create('/x', 'POST'), $uuid);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response)['data'];
    }

    private function controller(string $tenant = ''): AdminReviewController
    {
        return new AdminReviewController($this->context, $this->reviewService($tenant));
    }

    private function reviewService(string $tenant = ''): ReviewService
    {
        return new ReviewService(
            new ReviewRepository(),
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
