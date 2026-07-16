<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountRedeemedException;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Http\DTOs\ProductListQuery;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Real-PostgreSQL regression lane for Layer 6 (design spec §5 "pgsql lane";
 * plan Task 7). Every case here is either genuinely driver-sensitive (the
 * storefront attribute-value filter's `JsonStringArrayContainsSql` pgsql
 * branch uses the `@>` JSON containment operator, entirely untested by the
 * SQLite suite's `json_each()` branch) or requires TRUE two-connection row
 * lock interleaving that SQLite -- a single-process, single-connection engine
 * in this test harness -- cannot exercise at all (PHP has no threads, so a
 * genuine race needs a genuinely separate OS process/connection; see
 * `Catalog\ReviewConcurrencyTest`'s class docblock for the same rationale):
 *
 * - Storefront JSON/EXISTS filters: category/tag/attribute-value membership
 *   resolves exactly on real pgsql jsonb (`red` never matches `bred`), and a
 *   product attached to several categories/tags/attribute-values still counts
 *   once through the correlated `EXISTS` semijoins (never multiplied by a
 *   naive JOIN).
 * - Discount delete-vs-checkout-redemption race, BOTH orderings (see
 *   `Discounts\DiscountService`'s class docblock): delete-first makes
 *   checkout's `consumeUsage()` affect zero rows and roll back; consume-first
 *   makes delete observe the committed redemption and refuse with 409.
 * - Product soft-delete race: two concurrent `CatalogService::deleteProduct()`
 *   calls against the same row serialize on the `catalog_revision` claim --
 *   exactly one succeeds, the loser gets the same non-revealing 404, and the
 *   row is tombstoned exactly once.
 * - Bulk-vs-single-write serialization touch test: two concurrent
 *   `CatalogService::setProductStatus()` calls (the exact primitive both
 *   `bulkStatus()`'s per-item loop and an ordinary status PATCH delegate to)
 *   against the SAME product prove the shared `catalog_revision` claim
 *   serializes two REAL connections -- the revision advances by exactly two,
 *   never a lost update.
 *
 * Gating, fixture-width discipline (every `uuid`/`tenant_uuid` literal here is
 * 12 characters or fewer -- these columns are `varchar(12)`, strictly
 * enforced by PostgreSQL but silently ignored by SQLite), self-healing
 * per-test cleanup, and the throwaway `Connection`/`ApplicationContext`
 * construction all mirror `Reports\ReportPgsqlTest` /
 * `Catalog\ReviewConcurrencyTest` exactly. Every subprocess race follows
 * `ReviewConcurrencyTest`'s pattern: connection A (this test) manually
 * replicates the losing/blocked side's PRE-commit steps directly via the
 * repository (never the full service, so the test can pause mid-transaction),
 * holds the transaction open and uncommitted, launches connection B as a
 * genuinely separate subprocess running the real service call, sleeps to let
 * B block on A's held row lock, then A completes and commits -- releasing the
 * lock so B's blocked statement can proceed and resolve the race.
 */
final class ApiParityPgsqlTest extends CommerceTestCase
{
    // === Storefront JSON/EXISTS filters: exact match on real pgsql jsonb =====

    public function testAttributeValueMembershipIsExactRedDoesNotMatchBredOnRealPostgres(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane to prove pgsql jsonb containment is exact.');
        }

        $connection = $this->migratedConnection($this->pgConfig());
        $context = $this->pgsqlContext($connection);
        $this->cleanupRedBredFixture($connection);

        try {
            $attribute = 'l6fattrshd1';
            $connection->table('commerce_attributes')->insert([
                'uuid' => $attribute,
                'tenant_uuid' => '',
                'slug' => 'shade-pg',
                'name' => 'Shade',
            ]);
            $connection->table('commerce_attribute_values')->insert([
                'uuid' => 'l6fvalred01',
                'attribute_uuid' => $attribute,
                'slug' => 'red',
                'value' => 'Red',
            ]);
            $connection->table('commerce_attribute_values')->insert([
                'uuid' => 'l6fvalbred1',
                'attribute_uuid' => $attribute,
                'slug' => 'bred',
                'value' => 'Bred',
            ]);

            $bredProduct = 'l6fprdbred1';
            $connection->table('commerce_products')->insert([
                'uuid' => $bredProduct,
                'tenant_uuid' => '',
                'slug' => 'attr-bred-pg',
                'name' => 'Bred',
                'type' => 'physical',
                'status' => 'active',
            ]);
            $connection->table('commerce_product_attributes')->insert([
                'uuid' => 'l6fpabred01',
                'product_uuid' => $bredProduct,
                'attribute_uuid' => $attribute,
                'name' => null,
                'values' => json_encode(['bred'], JSON_THROW_ON_ERROR),
                'used_for_variants' => false,
                'visible' => true,
            ]);

            $body = $this->json($this->productController($context)->index(
                new ProductListQuery(attributes: 'shade-pg:red')
            ));

            self::assertSame(0, $body['total'], 'a stored "bred" value must never match a "red" filter on pgsql');
            self::assertSame([], $body['data']);
        } finally {
            $this->cleanupRedBredFixture($connection);
        }
    }

    public function testCombinedCategoryTagAttributeFiltersWithDuplicateProducingFixtureOnRealPostgres(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane to prove EXISTS semijoins never multiply rows.');
        }

        $connection = $this->migratedConnection($this->pgConfig());
        $context = $this->pgsqlContext($connection);
        $this->cleanupComboFixture($connection);

        try {
            $connection->table('commerce_categories')->insert([
                'uuid' => 'l6fcat00001', 'tenant_uuid' => '',
                'parent_uuid' => null, 'slug' => 'shoes-pg', 'name' => 'Shoes', 'position' => 0,
            ]);
            $connection->table('commerce_categories')->insert([
                'uuid' => 'l6fcat00002', 'tenant_uuid' => '',
                'parent_uuid' => null, 'slug' => 'shoes-pg2', 'name' => 'Shoes Extra', 'position' => 0,
            ]);
            $connection->table('commerce_tags')->insert([
                'uuid' => 'l6ftag00001', 'tenant_uuid' => '', 'slug' => 'tag-pg', 'name' => 'Tag',
            ]);
            $connection->table('commerce_tags')->insert([
                'uuid' => 'l6ftag00002', 'tenant_uuid' => '',
                'slug' => 'tag-pg2', 'name' => 'Tag Extra',
            ]);
            $connection->table('commerce_attributes')->insert([
                'uuid' => 'l6fattrcmb1', 'tenant_uuid' => '', 'slug' => 'combo-pg', 'name' => 'Combo',
            ]);
            $connection->table('commerce_attribute_values')->insert([
                'uuid' => 'l6fvalcmb01', 'attribute_uuid' => 'l6fattrcmb1',
                'slug' => 'combo-val', 'value' => 'Combo Val',
            ]);
            $connection->table('commerce_attribute_values')->insert([
                'uuid' => 'l6fvalcmb02', 'attribute_uuid' => 'l6fattrcmb1',
                'slug' => 'combo-val2', 'value' => 'Combo Val Extra',
            ]);

            $product = 'l6fprddupe1';
            $connection->table('commerce_products')->insert([
                'uuid' => $product,
                'tenant_uuid' => '',
                'slug' => 'dupe-product-pg',
                'name' => 'Dupe',
                'type' => 'physical',
                'status' => 'active',
            ]);
            // Attached to TWO categories, TWO tags, and an attribute row carrying
            // TWO value slugs -- a naive JOIN-based filter would multiply this
            // single product into several result rows on pgsql exactly as it
            // would on SQLite; the correlated EXISTS semijoin must still count
            // it exactly once.
            $connection->table('commerce_product_categories')
                ->insert(['product_uuid' => $product, 'category_uuid' => 'l6fcat00001']);
            $connection->table('commerce_product_categories')
                ->insert(['product_uuid' => $product, 'category_uuid' => 'l6fcat00002']);
            $connection->table('commerce_product_tags')
                ->insert(['product_uuid' => $product, 'tag_uuid' => 'l6ftag00001']);
            $connection->table('commerce_product_tags')
                ->insert(['product_uuid' => $product, 'tag_uuid' => 'l6ftag00002']);
            $connection->table('commerce_product_attributes')->insert([
                'uuid' => 'l6fpadupe01',
                'product_uuid' => $product,
                'attribute_uuid' => 'l6fattrcmb1',
                'name' => null,
                'values' => json_encode(['combo-val', 'combo-val2'], JSON_THROW_ON_ERROR),
                'used_for_variants' => false,
                'visible' => true,
            ]);

            // A product matching category+tag but NOT the attribute must be excluded.
            $partial = 'l6fprdpart1';
            $connection->table('commerce_products')->insert([
                'uuid' => $partial,
                'tenant_uuid' => '',
                'slug' => 'partial-product-pg',
                'name' => 'Partial',
                'type' => 'physical',
                'status' => 'active',
            ]);
            $connection->table('commerce_product_categories')
                ->insert(['product_uuid' => $partial, 'category_uuid' => 'l6fcat00001']);
            $connection->table('commerce_product_tags')
                ->insert(['product_uuid' => $partial, 'tag_uuid' => 'l6ftag00001']);

            $body = $this->json($this->productController($context)->index(new ProductListQuery(
                category: 'shoes-pg',
                tag: 'tag-pg',
                attributes: 'combo-pg:combo-val'
            )));

            self::assertSame(1, $body['total']);
            self::assertCount(1, $body['data']);
            self::assertSame(1, $body['total_pages']);
            self::assertSame($product, $body['data'][0]['uuid']);
        } finally {
            $this->cleanupComboFixture($connection);
        }
    }

    // === Discount delete-vs-redemption race: BOTH orderings ===================

    /**
     * Ordering 1 ("delete commits first"): connection A claims the discount
     * and confirms it has no redemptions (mirroring `DiscountService::delete()`'s
     * pre-delete steps directly via the repository, holding the row lock open),
     * then launches connection B running a checkout-style consume attempt
     * (`DiscountService::consume()` wrapped in its own transaction, mirroring
     * `CheckoutService::placeOrder()`) as a genuinely separate subprocess. B's
     * `consumeUsage()` UPDATE blocks on A's held lock; once A finishes the
     * delete and commits, B's UPDATE unblocks, matches zero rows (the row is
     * gone), and `consume()` throws -- exactly what rolls checkout's whole
     * order back.
     */
    public function testDiscountDeleteFirstThenConcurrentConsumeAttemptFailsAndRollsBackOnRealPostgres(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $discountUuid = 'l6dscracea1';

        $this->deleteDiscountRaceDebris($connectionA, $discountUuid);
        $connectionA->table('commerce_discounts')->insert([
            'uuid' => $discountUuid,
            'tenant_uuid' => '',
            'code' => 'PGRACEA',
            'type' => 'percentage',
            'value' => 1000,
            'usage_limit' => null,
            'once_per_buyer' => 0,
            'usage_count' => 0,
            'status' => 'active',
        ]);

        $discounts = new DiscountRepository();

        $connectionA->getTransactionManager()->begin();
        self::assertTrue($discounts->claimRevision($contextA, '', $discountUuid));
        self::assertNotNull($discounts->findByUuid($contextA, '', $discountUuid));
        self::assertFalse($discounts->hasRedemptions($contextA, '', $discountUuid));

        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/discount_consume_attempt_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $discountUuid,
                'l6dordracea',
                'buyer-a@example.test',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        usleep(300_000);

        $discounts->delete($contextA, '', $discountUuid);
        $connectionA->getTransactionManager()->commit();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim($stdout), true);
        self::assertIsArray($result, "Connection B's subprocess produced no parseable result. stderr: {$stderr}");
        self::assertFalse(
            $result['consumed'],
            "B's consume attempt must fail once the discount is deleted, not land (stderr: {$stderr})."
        );
        self::assertSame(ValidationException::class, $result['exceptionClass'] ?? null);

        self::assertNull($discounts->findByUuid($contextA, '', $discountUuid));
        self::assertSame(
            0,
            $connectionA->table('commerce_discount_redemptions')
                ->where('discount_uuid', '=', $discountUuid)
                ->count(),
            'no redemption row may exist once checkout rolled back'
        );

        $this->deleteDiscountRaceDebris($connectionA, $discountUuid);
    }

    /**
     * Ordering 2 ("consume commits first"): connection A claims usage
     * (`consumeUsage()`, mirroring the first half of `DiscountService::consume()`
     * directly via the repository, holding the row lock open), then launches
     * connection B running the real `DiscountService::delete()` as a genuinely
     * separate subprocess. B's `claimRevision()` UPDATE blocks on A's held
     * lock; once A inserts the redemption and commits, B's claim unblocks,
     * succeeds (the row still exists), but its post-claim `hasRedemptions()`
     * probe now observes the committed redemption and refuses with
     * `DiscountRedeemedException` (409) -- the row is left completely intact.
     */
    public function testDiscountConsumeFirstThenConcurrentDeleteReturns409WithRowIntactOnRealPostgres(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $discountUuid = 'l6dscraceb1';
        $orderUuid = 'l6dordraceb';

        $this->deleteDiscountRaceDebris($connectionA, $discountUuid);
        $connectionA->table('commerce_discounts')->insert([
            'uuid' => $discountUuid,
            'tenant_uuid' => '',
            'code' => 'PGRACEB',
            'type' => 'percentage',
            'value' => 1000,
            'usage_limit' => null,
            'once_per_buyer' => 0,
            'usage_count' => 0,
            'status' => 'active',
        ]);

        $discounts = new DiscountRepository();

        $connectionA->getTransactionManager()->begin();
        self::assertTrue($discounts->consumeUsage($contextA, '', $discountUuid));

        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/discount_delete_attempt_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $discountUuid,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        usleep(300_000);

        $discounts->insertRedemption($contextA, [
            'uuid' => 'l6dredeem01',
            'tenant_uuid' => '',
            'discount_uuid' => $discountUuid,
            'order_uuid' => $orderUuid,
            'buyer_identity' => 'buyer-b@example.test',
            'buyer_key' => $orderUuid,
        ]);
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
            "B's delete must fail once the discount has a redemption, not land (stderr: {$stderr})."
        );
        self::assertSame(DiscountRedeemedException::class, $result['exceptionClass'] ?? null);

        $reloaded = $discounts->findByUuid($contextA, '', $discountUuid);
        self::assertNotNull($reloaded, 'the discount row must survive the race intact');
        self::assertSame(1, (int) $reloaded['usage_count']);
        self::assertSame(
            1,
            $connectionA->table('commerce_discount_redemptions')
                ->where('discount_uuid', '=', $discountUuid)
                ->count()
        );

        $this->deleteDiscountRaceDebris($connectionA, $discountUuid);
    }

    // === Product soft-delete race: exactly one winner ==========================

    /**
     * Connection A claims the product and confirms it is still live (mirroring
     * `CatalogService::deleteProduct()`'s pre-tombstone steps directly via the
     * repository, holding the row lock open), then launches connection B
     * running the real `CatalogService::deleteProduct()` as a genuinely
     * separate subprocess. B's `claimCatalogRevision()` UPDATE blocks on A's
     * held lock; once A tombstones the row and commits, B's claim unblocks,
     * succeeds, but its own live re-read now observes the committed
     * `deleted_at` and 404s -- the same non-revealing 404 an unknown product
     * gets, never a corrupted double-tombstone write.
     */
    public function testConcurrentProductDeletesYieldExactlyOneSuccessOnRealPostgres(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $productUuid = 'l6pdelrace1';

        $this->deleteProductRaceDebris($connectionA, $productUuid);
        $connectionA->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => '',
            'slug' => 'del-race-pg',
            'name' => 'Race Product',
            'type' => 'physical',
            'status' => 'active',
        ]);

        $products = new ProductRepository();

        $connectionA->getTransactionManager()->begin();
        self::assertTrue($products->claimCatalogRevision($contextA, '', $productUuid));
        self::assertNotNull($products->findLiveByUuid($contextA, '', $productUuid));

        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/product_delete_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $productUuid,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        usleep(300_000);

        self::assertTrue($products->markDeleted($contextA, '', $productUuid));
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
            "B's delete must fail once the product is already tombstoned, not land (stderr: {$stderr})."
        );
        self::assertSame(NotFoundException::class, $result['exceptionClass'] ?? null);

        $reloaded = $products->findIncludingDeletedByUuid($contextA, '', $productUuid);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded['deleted_at']);

        $this->deleteProductRaceDebris($connectionA, $productUuid);
    }

    // === Bulk-vs-single-write serialization touch test ==========================

    /**
     * Two REAL, genuinely concurrent connections each call
     * `CatalogService::setProductStatus()` -- the exact primitive both
     * `AdminProductController::bulkStatus()`'s per-item loop and an ordinary
     * status-bearing single PATCH delegate to -- against the SAME product with
     * different target statuses. No manual pause/hold is used here (unlike the
     * delete races above): the point of this touch test is that the shared
     * `catalog_revision` claim serializes two real connections under ANY
     * interleaving, not one engineered ordering. Both attempts must succeed
     * (there is no "loser" in a status write, unlike a delete), the revision
     * must advance by EXACTLY two (never a lost update), and the final status
     * must be one of the two attempted values -- never a third, corrupted
     * value.
     */
    public function testBulkStatusWriteSerializesAgainstConcurrentSingleWriteOnRealPostgres(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true two-connection write serialization.');
        }

        $pgConfig = $this->pgConfig();
        $connection = $this->migratedConnection($pgConfig);
        $productUuid = 'l6pbulkrac1';

        $this->deleteProductRaceDebris($connection, $productUuid);
        $connection->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => '',
            'slug' => 'bulk-race-pg',
            'name' => 'Bulk Race Product',
            'type' => 'physical',
            'status' => 'active',
        ]);

        $processA = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/product_status_write_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $productUuid,
                'archived',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesA
        );
        $processB = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/product_status_write_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $productUuid,
                'draft',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesB
        );
        self::assertIsResource($processA);
        self::assertIsResource($processB);

        $stdoutA = stream_get_contents($pipesA[1]);
        $stderrA = stream_get_contents($pipesA[2]);
        fclose($pipesA[1]);
        fclose($pipesA[2]);
        proc_close($processA);

        $stdoutB = stream_get_contents($pipesB[1]);
        $stderrB = stream_get_contents($pipesB[2]);
        fclose($pipesB[1]);
        fclose($pipesB[2]);
        proc_close($processB);

        $resultA = json_decode(trim($stdoutA), true);
        $resultB = json_decode(trim($stdoutB), true);
        self::assertIsArray($resultA, "Connection A's subprocess produced no parseable result. stderr: {$stderrA}");
        self::assertIsArray($resultB, "Connection B's subprocess produced no parseable result. stderr: {$stderrB}");
        self::assertTrue($resultA['applied'], "A's status write must succeed (stderr: {$stderrA}).");
        self::assertTrue($resultB['applied'], "B's status write must succeed (stderr: {$stderrB}).");

        $reloaded = $connection->table('commerce_products')->where('uuid', '=', $productUuid)->first();
        self::assertNotNull($reloaded);
        self::assertSame(2, (int) $reloaded['catalog_revision'], 'both claims must land -- never a lost update');
        self::assertContains(
            $reloaded['status'],
            ['archived', 'draft'],
            'the final status must be exactly one of the two attempted values, never a corrupted blend'
        );

        $this->deleteProductRaceDebris($connection, $productUuid);
    }

    // --- Helpers -------------------------------------------------------------

    private function productController(ApplicationContext $context): ProductController
    {
        return new ProductController(
            $context,
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

    private function cleanupRedBredFixture(Connection $connection): void
    {
        $connection->table('commerce_product_attributes')->where('uuid', '=', 'l6fpabred01')->delete();
        $connection->table('commerce_products')->where('uuid', '=', 'l6fprdbred1')->forceDelete();
        $connection->table('commerce_attribute_values')->where('uuid', '=', 'l6fvalred01')->delete();
        $connection->table('commerce_attribute_values')->where('uuid', '=', 'l6fvalbred1')->delete();
        $connection->table('commerce_attributes')->where('uuid', '=', 'l6fattrshd1')->delete();
    }

    private function cleanupComboFixture(Connection $connection): void
    {
        foreach (['l6fpadupe01'] as $uuid) {
            $connection->table('commerce_product_attributes')->where('uuid', '=', $uuid)->delete();
        }
        foreach (['l6fprddupe1', 'l6fprdpart1'] as $productUuid) {
            $connection->table('commerce_product_categories')->where('product_uuid', '=', $productUuid)->delete();
            $connection->table('commerce_product_tags')->where('product_uuid', '=', $productUuid)->delete();
            $connection->table('commerce_products')->where('uuid', '=', $productUuid)->forceDelete();
        }
        $connection->table('commerce_attribute_values')->where('uuid', '=', 'l6fvalcmb01')->delete();
        $connection->table('commerce_attribute_values')->where('uuid', '=', 'l6fvalcmb02')->delete();
        $connection->table('commerce_attributes')->where('uuid', '=', 'l6fattrcmb1')->delete();
        $connection->table('commerce_tags')->where('uuid', '=', 'l6ftag00001')->delete();
        $connection->table('commerce_tags')->where('uuid', '=', 'l6ftag00002')->delete();
        $connection->table('commerce_categories')->where('uuid', '=', 'l6fcat00001')->delete();
        $connection->table('commerce_categories')->where('uuid', '=', 'l6fcat00002')->delete();
    }

    private function deleteDiscountRaceDebris(Connection $connection, string $discountUuid): void
    {
        $connection->table('commerce_discount_redemptions')->where('discount_uuid', '=', $discountUuid)->delete();
        $connection->table('commerce_discounts')->where('uuid', '=', $discountUuid)->delete();
    }

    /**
     * `commerce_products` carries a `deleted_at` column, so a plain `delete()`
     * soft-deletes it -- leaving the row (and its unique uuid) physically in
     * place and breaking this cleanup's idempotency across repeated runs of
     * the same pgsql-gated test. `forceDelete()` is required (mirrors
     * `ReviewConcurrencyTest::deleteRaceDebris()` / `ReportPgsqlTest::cleanupStock()`'s
     * own docblocks for the identical finding).
     */
    private function deleteProductRaceDebris(Connection $connection, string $productUuid): void
    {
        $connection->table('commerce_products')->where('uuid', '=', $productUuid)->forceDelete();
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

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        self::assertSame(200, $response->getStatusCode());

        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
