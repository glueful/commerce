<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewService;
use Glueful\Extensions\Commerce\Catalog\ReviewStateException;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Psr\Container\ContainerInterface;

/**
 * Review moderation claim discipline (design spec §5): the affected-row-checked
 * `claimTransition()` primitive, the guarded delete's `approved` rejection, and
 * the one-winner invariants the outer task calls for -- duplicate approve (no
 * double rollup), an approve-vs-delete race (approved survives with its rollup,
 * or the review is gone with none), and an approved->spam reversal that restores
 * the product's rating sums exactly. Follows the same
 * deterministic-claim-plus-pgsql-race split as CategoryTreeConcurrencyTest /
 * AttributeConcurrencyTest / ProductChildrenConcurrencyTest: a genuine
 * cross-connection row-lock interleave is only observable in a truly separate OS
 * process (PHP has no threads), so the default suite proves every invariant
 * sequentially and one pgsql-gated test proves the approve-vs-delete race under a
 * real two-connection lock interleave.
 */
final class ReviewConcurrencyTest extends CommerceTestCase
{
    private const TENANT_B = 'tenantBBBB03';

    // --- claimTransition() primitive ------------------------------------------

    public function testClaimTransitionSucceedsAndTransitionsStatus(): void
    {
        $this->seedProduct('', 'revwclaim001');
        $review = $this->seedReview('', 'revwclaim001', 'revwclaimr01');
        $repository = new ReviewRepository();

        self::assertTrue($repository->claimTransition($this->context, '', $review['uuid'], 'pending', 'approved'));
        $reloaded = $repository->findByUuid($this->context, '', $review['uuid']);
        self::assertSame('approved', $reloaded['status']);
    }

    public function testClaimTransitionReturnsFalseForUnknownCrossTenantOrWrongFromState(): void
    {
        $repository = new ReviewRepository();

        self::assertFalse(
            $repository->claimTransition($this->context, '', 'no-such-review', 'pending', 'approved')
        );

        $this->seedProduct(self::TENANT_B, 'revwclaim002');
        $crossTenant = $this->seedReview(self::TENANT_B, 'revwclaim002', 'revwclaimr02');
        self::assertFalse(
            $repository->claimTransition($this->context, '', $crossTenant['uuid'], 'pending', 'approved')
        );

        $this->seedProduct('', 'revwclaim003');
        $wrongState = $this->seedReview('', 'revwclaim003', 'revwclaimr03', status: 'approved');
        self::assertFalse(
            $repository->claimTransition($this->context, '', $wrongState['uuid'], 'pending', 'approved')
        );
    }

    // --- Duplicate approve: no double rollup ----------------------------------

    public function testDuplicateApproveSecondCallAffectsZeroRowsAndDoesNotDoubleRollup(): void
    {
        $product = $this->seedProduct('', 'revwdup00001');
        $review = $this->seedReview('', 'revwdup00001', 'revwdupr0001', rating: 4);
        $service = $this->service();

        $service->approve($this->context, $review['uuid']);

        try {
            $service->approve($this->context, $review['uuid']);
            self::fail('expected ReviewStateException on the second approve of an already-approved review');
        } catch (ReviewStateException $e) {
            self::assertStringContainsString('approved', $e->getMessage());
        }

        $reloadedProduct = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(4, (int) $reloadedProduct['rating_sum']);
        self::assertSame(1, (int) $reloadedProduct['rating_count']);
    }

    // --- Approve-vs-delete: one winner (sequential/deterministic) -------------

    public function testSequentialApproveThenDeleteLeavesApprovedReviewWithRollupIntact(): void
    {
        $product = $this->seedProduct('', 'revwrace0001');
        $review = $this->seedReview('', 'revwrace0001', 'revwracer001', rating: 5);
        $service = $this->service();

        $service->approve($this->context, $review['uuid']);

        try {
            $service->delete($this->context, $review['uuid']);
            self::fail('expected NotFoundException deleting an approved review');
        } catch (NotFoundException $e) {
            // expected -- approved reviews can't be deleted directly.
        }

        $reloadedReview = (new ReviewRepository())->findByUuid($this->context, '', $review['uuid']);
        self::assertNotNull($reloadedReview);
        self::assertSame('approved', $reloadedReview['status']);

        $reloadedProduct = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(5, (int) $reloadedProduct['rating_sum']);
        self::assertSame(1, (int) $reloadedProduct['rating_count']);
    }

    public function testSequentialDeleteThenApproveLeavesNoReviewAndNoRollup(): void
    {
        $product = $this->seedProduct('', 'revwrace0002');
        $review = $this->seedReview('', 'revwrace0002', 'revwracer002', rating: 5);
        $service = $this->service();

        $service->delete($this->context, $review['uuid']);

        try {
            $service->approve($this->context, $review['uuid']);
            self::fail('expected NotFoundException approving a deleted review');
        } catch (NotFoundException $e) {
            // expected -- the review is gone.
        }

        self::assertNull((new ReviewRepository())->findByUuid($this->context, '', $review['uuid']));

        $reloadedProduct = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(0, (int) $reloadedProduct['rating_sum']);
        self::assertSame(0, (int) $reloadedProduct['rating_count']);
    }

    // --- approved -> spam reversal restores sums exactly -----------------------

    public function testApprovedToSpamReversalRestoresRatingSumsAmongMultipleReviews(): void
    {
        $product = $this->seedProduct('', 'revwreverse1');
        $keep = $this->seedReview('', 'revwreverse1', 'revwreverk01', rating: 3);
        $reverse = $this->seedReview('', 'revwreverse1', 'revwreverk02', rating: 5);
        $service = $this->service();

        $service->approve($this->context, $keep['uuid']);
        $service->approve($this->context, $reverse['uuid']);

        $afterBothApproved = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(8, (int) $afterBothApproved['rating_sum']);
        self::assertSame(2, (int) $afterBothApproved['rating_count']);

        $service->spam($this->context, $reverse['uuid']);

        $afterReversal = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        // Only $reverse's contribution (+5/+1) is undone -- $keep's (+3/+1) stands.
        self::assertSame(3, (int) $afterReversal['rating_sum']);
        self::assertSame(1, (int) $afterReversal['rating_count']);

        $reloadedReverse = (new ReviewRepository())->findByUuid($this->context, '', $reverse['uuid']);
        self::assertSame('spam', $reloadedReverse['status']);
    }

    // --- Real pgsql-lane race: approve vs delete --------------------------------

    /**
     * Real cross-connection interleaving: connection A (this test) holds the
     * review's claim open and uncommitted (the first step of an approve) while
     * connection B (a genuinely independent subprocess,
     * fixtures/review_delete_vs_approve_race_child.php) runs the real
     * `ReviewService::delete()` against the SAME review. B's guarded DELETE
     * targets the same row, so it blocks on A's held PostgreSQL row lock until A
     * finishes the approve (rollup included) and commits. B's DELETE then
     * re-evaluates its `status IN ('pending','spam')` guard against the
     * now-committed `approved` row, affects zero rows, and
     * `ReviewService::delete()` throws NotFoundException -- the approved review
     * survives with its rollup intact; it never disappears out from under it.
     */
    public function testConcurrentApproveVsDeleteRealPgsqlRaceApprovedSurvivesWithRollup(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);

        $productUuid = 'revwpgracep1';
        $reviewUuid = 'revwpgracer1';

        // Self-healing: wipe any debris a previously-interrupted run of this same
        // pgsql-gated test left behind before inserting the fixture rows.
        $this->deleteRaceDebris($connectionA, $productUuid, $reviewUuid);

        $connectionA->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => '',
            'slug' => $productUuid,
            'name' => 'Race Product',
            'type' => 'physical',
            'status' => 'active',
        ]);
        $connectionA->table('commerce_reviews')->insert([
            'uuid' => $reviewUuid,
            'tenant_uuid' => '',
            'product_uuid' => $productUuid,
            'author_name' => 'Race Reviewer',
            'author_email' => 'race@example.com',
            'rating' => 4,
            'body' => 'Race review body.',
            'status' => 'pending',
        ]);

        // A claims the review first -- this holds the row lock, uncommitted. The
        // claim primitive (not the full service) is used directly so the test can
        // pause mid-approve while B's delete attempts to claim the same row.
        $connectionA->getTransactionManager()->begin();
        $reviews = new ReviewRepository();
        self::assertTrue($reviews->claimTransition($contextA, '', $reviewUuid, 'pending', 'approved'));

        // Launch B: the real ReviewService::delete() against the same review.
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/review_delete_vs_approve_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $reviewUuid,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        // Give B time to reach and block on its own DELETE before A proceeds.
        usleep(300_000);

        // A completes the approve directly (it already holds the claim, so no
        // service-level re-claim is needed): applies the rollup, then commits --
        // releasing the row lock so B's blocked delete can proceed.
        $products = new ProductRepository();
        self::assertTrue($products->adjustRating($contextA, '', $productUuid, 4, 1));
        $connectionA->getTransactionManager()->commit();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim($stdout), true);
        self::assertIsArray($result, "Connection B's subprocess produced no parseable result. stderr: {$stderr}");
        self::assertFalse(
            $result['deleted'],
            "B's delete must fail once the review is approved, not land (stderr: {$stderr})."
        );
        self::assertSame(NotFoundException::class, $result['exceptionClass'] ?? null);

        $reloadedReview = $connectionA->table('commerce_reviews')->where('uuid', '=', $reviewUuid)->first();
        self::assertNotNull($reloadedReview, 'The approved review must survive the race.');
        self::assertSame('approved', $reloadedReview['status']);

        $reloadedProduct = $connectionA->table('commerce_products')->where('uuid', '=', $productUuid)->first();
        self::assertSame(4, (int) $reloadedProduct['rating_sum']);
        self::assertSame(1, (int) $reloadedProduct['rating_count']);

        // Leave the pgsql fixture database as we found it.
        $this->deleteRaceDebris($connectionA, $productUuid, $reviewUuid);
    }

    // --- Helpers -----------------------------------------------------------

    /** @return array<string,mixed> */
    private function seedProduct(string $tenant, string $uuid): array
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'type' => 'physical',
            'status' => 'active',
        ]);

        $product = (new ProductRepository())->findLiveByUuid($this->context, $tenant, $uuid);
        self::assertNotNull($product);

        return $product;
    }

    /** @return array<string,mixed> */
    private function seedReview(
        string $tenant,
        string $productUuid,
        string $uuid,
        int $rating = 5,
        string $status = 'pending'
    ): array {
        $this->connection->table('commerce_reviews')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'author_name' => 'Reviewer',
            'author_email' => 'reviewer@example.com',
            'rating' => $rating,
            'body' => 'Review body.',
            'status' => $status,
        ]);

        $review = (new ReviewRepository())->findByUuid($this->context, $tenant, $uuid);
        self::assertNotNull($review);

        return $review;
    }

    private function service(): ReviewService
    {
        return new ReviewService(new ReviewRepository(), new ProductRepository(), new SentinelTenantResolver());
    }

    /** @return array<string,mixed> */
    private function pgConfig(): array
    {
        return [
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'glueful_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
            ],
            'pooling' => ['enabled' => false],
        ];
    }

    /** @param array<string,mixed> $pgConfig */
    private function migratedConnection(array $pgConfig): Connection
    {
        $connection = new Connection($pgConfig);
        $schema = $connection->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        return $connection;
    }

    private function deleteRaceDebris(Connection $connection, string $productUuid, string $reviewUuid): void
    {
        $connection->table('commerce_reviews')->where('uuid', '=', $reviewUuid)->delete();
        // forceDelete(), not delete(): commerce_products carries a deleted_at
        // column, so a plain delete() would soft-delete it -- leaving the row
        // (and its unique uuid) physically in place and breaking this cleanup's
        // idempotency across repeated runs of this same pgsql-gated test.
        $connection->table('commerce_products')->where('uuid', '=', $productUuid)->forceDelete();
    }

    private function pgsqlContext(Connection $connection): ApplicationContext
    {
        $container = new class ($connection) implements ContainerInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database' || $id === Connection::class;
            }
        };

        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->setContainer($container);
        $context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../config/commerce.php');

        return $context;
    }
}
