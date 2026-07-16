<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewService;
use Glueful\Extensions\Commerce\Http\DTOs\StorefrontReviewListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\StoreReviewData;
use Glueful\Extensions\Commerce\Http\Storefront\ReviewController;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Routing\Router;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\ValidationException;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Public review submit + approved list (design spec Layer 6 §2 decision 6;
 * plan Global Constraints storefront-reviews block): always-pending create,
 * guest-field validation + column-width bounds, the caller-supplied
 * `user_uuid` is structurally ignored, the live+active storefront guard
 * collapses unknown/draft/tombstoned products to the identical 404 on BOTH
 * endpoints, the exact `{status: "pending"}` 201 body, and the approved-only
 * paginated list with its strict field allowlist and enumeration neutrality.
 */
final class StorefrontReviewTest extends CommerceTestCase
{
    // === Hydration-boundary validation (StoreReviewData) =======================

    /** @dataProvider hydrationRejectionProvider */
    public function testHydrationRejectsInvalidPayloads(array $body, string $expectedErrorField): void
    {
        try {
            (new RequestDataHydrator())->hydrate(StoreReviewData::class, $body);
            self::fail('expected ValidationException for field: ' . $expectedErrorField);
        } catch (ValidationException $e) {
            self::assertArrayHasKey($expectedErrorField, $e->errors());
        }
    }

    /** @return iterable<string, array{0: array<string,mixed>, 1: string}> */
    public static function hydrationRejectionProvider(): iterable
    {
        $valid = [
            'rating' => 5,
            'body' => 'Great product, would buy again.',
            'author_name' => 'Jane Doe',
            'author_email' => 'jane@example.com',
        ];

        yield 'missing rating' => [self::without($valid, 'rating'), 'rating'];
        yield 'missing body' => [self::without($valid, 'body'), 'body'];
        yield 'missing author_name' => [self::without($valid, 'author_name'), 'author_name'];
        yield 'missing author_email' => [self::without($valid, 'author_email'), 'author_email'];
        yield 'non-integer rating' => [['rating' => 'not-a-number'] + $valid, 'rating'];
        yield 'empty body' => [['body' => ''] + $valid, 'body'];
        yield 'body over 10000 chars' => [['body' => str_repeat('a', 10001)] + $valid, 'body'];
        yield 'author_name over 255 chars' => [['author_name' => str_repeat('a', 256)] + $valid, 'author_name'];
        yield 'author_email over 255 chars' => [
            ['author_email' => str_repeat('a', 260) . '@example.com'] + $valid,
            'author_email',
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function without(array $data, string $key): array
    {
        unset($data[$key]);

        return $data;
    }

    public function testStoreReviewDataDeclaresNoUserUuidOrProductUuidProperty(): void
    {
        // Documents the "ignored, not rejected" choice (Layer 6 Global
        // Constraints, storefront-reviews block): neither property exists on
        // the constructor, so RequestDataHydrator structurally cannot read
        // either key from the request body -- see testCallerSuppliedUserUuid...
        // below for the functional proof.
        $ctor = (new \ReflectionClass(StoreReviewData::class))->getConstructor();
        self::assertNotNull($ctor);
        $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $ctor->getParameters());

        self::assertNotContains('user_uuid', $names);
        self::assertNotContains('product_uuid', $names);
    }

    // === Service-boundary validation (rating range) =============================

    /** @dataProvider invalidRatingRangeProvider */
    public function testSubmitRatingOutOfRangeReturns422(int $rating): void
    {
        $product = $this->seedProduct('prodrevwrg' . abs($rating));

        $response = $this->controller()->store(
            $this->data(rating: $rating),
            Request::create('/x', 'POST'),
            $product['slug']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('rating', $this->json($response)['error']['details']);
    }

    /** @return iterable<string, array{0:int}> */
    public static function invalidRatingRangeProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'six' => [6];
    }

    public function testSubmitRatingOneAndFiveAreBothAccepted(): void
    {
        $low = $this->seedProduct('prodrevwlow1');
        $high = $this->seedProduct('prodrevwhig1');

        $lowResponse = $this->controller()->store($this->data(rating: 1), Request::create('/x', 'POST'), $low['slug']);
        $highResponse = $this->controller()->store(
            $this->data(rating: 5),
            Request::create('/x', 'POST'),
            $high['slug']
        );

        self::assertSame(201, $lowResponse->getStatusCode());
        self::assertSame(201, $highResponse->getStatusCode());
    }

    // === Caller-supplied identity is ignored ====================================

    public function testCallerSuppliedUserUuidIsIgnoredAndStoredNull(): void
    {
        $product = $this->seedProduct('prodrevwusr1');

        $hydrated = (new RequestDataHydrator())->hydrate(StoreReviewData::class, [
            'rating' => 5,
            'body' => 'Great product, would buy again.',
            'author_name' => 'Jane Doe',
            'author_email' => 'jane@example.com',
            'user_uuid' => 'attacker-controlled-uuid',
        ]);
        self::assertInstanceOf(StoreReviewData::class, $hydrated);

        $response = $this->controller()->store($hydrated, Request::create('/x', 'POST'), $product['slug']);
        self::assertSame(201, $response->getStatusCode());

        $row = $this->connection->table('commerce_reviews')
            ->where('product_uuid', '=', $product['uuid'])
            ->first();
        self::assertNotNull($row);
        self::assertNull($row['user_uuid']);
    }

    // === Live+active guard: 404 triple, identical on both endpoints ============

    public function testUnknownDraftAndTombstonedProductsCollapseToTheSameNotFoundOnSubmit(): void
    {
        $draft = $this->seedProduct('prodrevwdrf1', status: 'draft');
        $tombstoned = $this->seedProduct('prodrevwtmb1');
        $this->tombstone($tombstoned['uuid']);

        foreach (['no-such-review-product', $draft['slug'], $tombstoned['slug']] as $slug) {
            try {
                $this->controller()->store($this->data(), Request::create('/x', 'POST'), $slug);
                self::fail("expected NotFoundException for slug '{$slug}'");
            } catch (NotFoundException $e) {
                self::assertSame('Resource not found.', $e->getMessage());
            }
        }
    }

    public function testUnknownDraftAndTombstonedProductsCollapseToTheSameNotFoundOnList(): void
    {
        $draft = $this->seedProduct('prodrevwdrf2', status: 'draft');
        $tombstoned = $this->seedProduct('prodrevwtmb2');
        $this->tombstone($tombstoned['uuid']);

        foreach (['no-such-review-product-2', $draft['slug'], $tombstoned['slug']] as $slug) {
            try {
                $this->controller()->index(new StorefrontReviewListQuery(), Request::create('/x'), $slug);
                self::fail("expected NotFoundException for slug '{$slug}'");
            } catch (NotFoundException $e) {
                self::assertSame('Resource not found.', $e->getMessage());
            }
        }
    }

    // === Always-pending + exact 201 body ========================================

    public function testSubmitLandsPendingAlwaysAndResponseBodyIsExactlyStatusPending(): void
    {
        $product = $this->seedProduct('prodrevwok01');

        $response = $this->controller()->store($this->data(), Request::create('/x', 'POST'), $product['slug']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['status' => 'pending'], $this->json($response)['data']);

        $row = $this->connection->table('commerce_reviews')
            ->where('product_uuid', '=', $product['uuid'])
            ->first();
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
    }

    // === Approved-only list: allowlist, pagination, tie order, neutrality ======

    public function testListReturnsApprovedOnlyWithExactFieldAllowlist(): void
    {
        $product = $this->seedProduct('prodrevwlst1');
        $this->seedReview([
            'uuid' => 'revwallow001',
            'product_uuid' => $product['uuid'],
            'status' => 'approved',
            'author_email' => 'super-secret@example.com',
        ]);
        $this->seedReview(['uuid' => 'revwallow002', 'product_uuid' => $product['uuid'], 'status' => 'pending']);
        $this->seedReview(['uuid' => 'revwallow003', 'product_uuid' => $product['uuid'], 'status' => 'spam']);

        $response = $this->controller()->index(
            new StorefrontReviewListQuery(),
            Request::create('/x'),
            $product['slug']
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $body['total']);
        self::assertCount(1, $body['data']);

        $keys = array_keys($body['data'][0]);
        sort($keys);
        self::assertSame(['author_name', 'body', 'created_at', 'rating'], $keys);

        self::assertStringNotContainsString('super-secret@example.com', (string) $response->getContent());
        self::assertStringNotContainsString('author_email', (string) $response->getContent());
        self::assertStringNotContainsString('user_uuid', (string) $response->getContent());
    }

    public function testListPaginatesWithHouseClamp(): void
    {
        $product = $this->seedProduct('prodrevwpag1');
        for ($i = 1; $i <= 3; $i++) {
            $this->seedReview([
                'uuid' => 'revwpage000' . $i,
                'product_uuid' => $product['uuid'],
                'status' => 'approved',
            ]);
        }

        $response = $this->controller()->index(
            new StorefrontReviewListQuery(page: 1, per_page: 2),
            Request::create('/x'),
            $product['slug']
        );
        $body = $this->json($response);

        self::assertSame(3, $body['total']);
        self::assertCount(2, $body['data']);
        self::assertSame(2, $body['total_pages']);
    }

    public function testListOrdersCreatedAtDescendingWithUuidAscendingTieBreak(): void
    {
        $product = $this->seedProduct('prodrevwtie1');
        // B and A share a created_at -- uuid ASC breaks the tie (A before B).
        // C has a strictly newer created_at and must sort first regardless.
        $this->seedReview([
            'uuid' => 'revwtieb0002',
            'product_uuid' => $product['uuid'],
            'status' => 'approved',
            'author_name' => 'B',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->seedReview([
            'uuid' => 'revwtiea0001',
            'product_uuid' => $product['uuid'],
            'status' => 'approved',
            'author_name' => 'A',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->seedReview([
            'uuid' => 'revwtiec0003',
            'product_uuid' => $product['uuid'],
            'status' => 'approved',
            'author_name' => 'C',
            'created_at' => '2026-02-01 00:00:00',
        ]);

        $response = $this->controller()->index(
            new StorefrontReviewListQuery(),
            Request::create('/x'),
            $product['slug']
        );

        self::assertSame(['C', 'A', 'B'], array_column($this->json($response)['data'], 'author_name'));
    }

    public function testEnumerationNeutralityPendingOnlyProductIsIndistinguishableFromNoReviews(): void
    {
        $withPendingOnly = $this->seedProduct('prodrevwpo01');
        $this->seedReview([
            'uuid' => 'revwpend0001',
            'product_uuid' => $withPendingOnly['uuid'],
            'status' => 'pending',
        ]);

        $withNoReviews = $this->seedProduct('prodrevwno01');

        $pendingOnlyResponse = $this->controller()->index(
            new StorefrontReviewListQuery(),
            Request::create('/x'),
            $withPendingOnly['slug']
        );
        $noReviewsResponse = $this->controller()->index(
            new StorefrontReviewListQuery(),
            Request::create('/x'),
            $withNoReviews['slug']
        );

        self::assertSame(200, $pendingOnlyResponse->getStatusCode());
        self::assertSame(0, $this->json($pendingOnlyResponse)['total']);
        self::assertSame([], $this->json($pendingOnlyResponse)['data']);
        self::assertSame(
            $this->json($noReviewsResponse)['total'],
            $this->json($pendingOnlyResponse)['total']
        );
        self::assertSame(
            $this->json($noReviewsResponse)['data'],
            $this->json($pendingOnlyResponse)['data']
        );
    }

    // === Rate-limit route wiring =================================================

    public function testReviewRoutesAreWiredWithTheDocumentedRateLimits(): void
    {
        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../config/commerce.php');

        $container = new class ($context) implements ContainerInterface {
            public function __construct(private ApplicationContext $context)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === ApplicationContext::class) {
                    return $this->context;
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $router = new Router($container);
        require __DIR__ . '/../../../routes.php';

        self::assertContains(
            'rate_limit:5,60',
            $this->middlewareFor($router, 'POST', '/commerce/products/{slug}/reviews')
        );
        self::assertContains(
            'rate_limit:120,60',
            $this->middlewareFor($router, 'GET', '/commerce/products/{slug}/reviews')
        );
    }

    /** @return list<string> */
    private function middlewareFor(Router $router, string $method, string $path): array
    {
        foreach ($router->getAllRoutes() as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                /** @var list<string> $mw */
                $mw = $route['middleware'];

                return $mw;
            }
        }
        self::fail("Route {$method} {$path} was not registered");
    }

    // === Fixtures + helpers ======================================================

    /** @return array<string,mixed> */
    private function seedProduct(string $uuid, string $tenant = '', string $status = 'active'): array
    {
        $row = [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'type' => 'physical',
            'status' => $status,
        ];
        $this->connection->table('commerce_products')->insert($row);

        return $row;
    }

    private function tombstone(string $uuid): void
    {
        $this->connection->table('commerce_products')
            ->where('uuid', '=', $uuid)
            ->update(['deleted_at' => '2026-01-01 00:00:00']);
    }

    /** @param array<string,mixed> $overrides */
    private function seedReview(array $overrides): string
    {
        $uuid = (string) $overrides['uuid'];
        $this->connection->table('commerce_reviews')->insert(array_merge([
            'tenant_uuid' => '',
            'user_uuid' => null,
            'author_name' => 'Author',
            'author_email' => 'author@example.com',
            'rating' => 5,
            'body' => 'Body text.',
            'status' => 'approved',
        ], $overrides, ['uuid' => $uuid]));

        return $uuid;
    }

    private function data(
        int $rating = 5,
        string $body = 'Great product, would buy again.',
        string $authorName = 'Jane Doe',
        string $authorEmail = 'jane@example.com'
    ): StoreReviewData {
        return new StoreReviewData(
            rating: $rating,
            body: $body,
            author_name: $authorName,
            author_email: $authorEmail
        );
    }

    private function controller(string $tenant = ''): ReviewController
    {
        return new ReviewController($this->context, $this->reviewService($tenant));
    }

    private function reviewService(string $tenant = ''): ReviewService
    {
        return new ReviewService(
            new ReviewRepository(),
            new ProductRepository(),
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

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
