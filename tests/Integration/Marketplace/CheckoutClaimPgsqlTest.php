<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Marketplace\CheckoutConflictException;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleException;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL race lanes for Marketplace MV2's checkout transfer-safe
 * claim protocol (design spec §2.7; plan Task 10). Every case here requires
 * TRUE two-connection row-lock interleaving that SQLite cannot exercise
 * (PHP has no threads, so a genuine race needs a genuinely separate OS
 * process/connection). Gating, fixture-width discipline (every `uuid`/
 * `tenant_uuid` literal here is 12 characters or fewer -- `varchar(12)`,
 * strictly enforced by PostgreSQL but silently ignored by SQLite),
 * self-healing per-test cleanup, and the throwaway `Connection`/
 * `ApplicationContext` construction all mirror `MarketplacePgsqlTest`
 * exactly -- this file extends the SAME `fixtures/marketplace_race_child.php`
 * multiplexed subprocess with a new `checkout` action rather than
 * duplicating the harness.
 *
 * Two of the four lanes below manually replicate the CLAIM+WRITE phase of a
 * partitioned checkout directly via the repositories (`manuallyPlaceMinimalPartitionedOrder()`)
 * rather than calling the real `CheckoutService`, so connection A can pause
 * mid-transaction and hold its claim open on demand -- exactly the same
 * "manually replicate one side, launch the real service as a subprocess for
 * the other" pattern `MarketplacePgsqlTest::runTransferVsTransfer()` already
 * established for MV1.
 */
final class CheckoutClaimPgsqlTest extends CommerceTestCase
{
    // =====================================================================
    // checkout-vs-transfer, BOTH orderings (design spec §2.7/§8): no torn
    // mapping -- the order snapshots the committed seller, and the product
    // never lands on a stale seller.
    // =====================================================================

    /**
     * Ordering (a): the TRANSFER commits first. The concurrent checkout's
     * own seller claim blocks entirely on the transfer's held claim; once
     * unblocked, its re-read observes the drift (its original snapshot saw
     * the source seller) and the transaction rolls back -- `placeOrder()`'s
     * automatic ONE retry then runs from a fresh snapshot, which correctly
     * sees the committed target seller, and the checkout succeeds attributed
     * to it. Never a stale attribution to the pre-transfer source.
     */
    public function testTransferCommitsFirstThenConcurrentCheckoutObservesTheCommittedSellerOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'ccpgxfr001';
        $this->cleanupTenant($connectionA, $tenant);
        $this->activateWorkspace($connectionA, $tenant);

        $source = $this->seedActiveSeller($contextA, $tenant, 'ccpg-xfr-src1', 'ownerXfrSrc1');
        $target = $this->seedActiveSeller($contextA, $tenant, 'ccpg-xfr-tgt1', 'ownerXfrTgt1');

        $productUuid = 'ccpgxfrprd01';
        $variantUuid = 'ccpgxfrvar01';
        $connectionA->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => $tenant,
            'slug' => 'ccpg-xfr-prod1',
            'name' => 'Xfr Race Prod',
            'type' => 'physical',
            'status' => 'active',
            'seller_uuid' => $source['uuid'],
        ]);
        $connectionA->table('commerce_variants')->insert([
            'uuid' => $variantUuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'sku' => 'CCPG-XFR-1',
            'option_values' => '{}',
            'price' => 1000,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $sellers = new SellerRepository();
        $products = new ProductRepository();

        // Connection A mirrors SellerAttributionService::assign()'s full
        // protocol (design spec §2, claim source+target sellers then the
        // product), transferring the product from $source to $target, and
        // commits FIRST -- mirrors MarketplacePgsqlTest::runTransferVsTransfer().
        $connectionA->getTransactionManager()->begin();
        $snapshot = $products->findLiveByUuid($contextA, $tenant, $productUuid);
        self::assertSame($source['uuid'], $snapshot['seller_uuid']);
        $claimSet = array_unique([$source['uuid'], $target['uuid']]);
        sort($claimSet);
        foreach ($claimSet as $sellerUuid) {
            self::assertTrue($sellers->claimRevision($contextA, $tenant, (string) $sellerUuid));
        }
        self::assertTrue($products->claimCatalogRevision($contextA, $tenant, $productUuid));
        $products->update($contextA, $tenant, $productUuid, ['seller_uuid' => $target['uuid']]);

        // Connection B (subprocess, the REAL CheckoutService) attempts to
        // check out the SAME product -- its own snapshot still sees $source
        // (A hasn't committed), so its seller claim (on $source, a
        // participant of A's OWN claim set) blocks entirely on A's held lock.
        $handle = $this->launchRaceChild($pgConfig, 'checkout', [
            'tenant' => $tenant,
            'variantUuid' => $variantUuid,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'checkout must succeed against the fresh, committed seller after one automatic retry: '
                . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertTrue($result['partitioned']);
        self::assertSame(
            $target['uuid'],
            $result['sellerUuid'],
            'the order must snapshot the COMMITTED (target) seller -- never the stale source'
        );

        $finalProduct = $connectionA->table('commerce_products')->where('uuid', '=', $productUuid)->first();
        self::assertSame($target['uuid'], $finalProduct['seller_uuid']);

        $sellerOrder = $connectionA->table('commerce_seller_orders')
            ->where('order_uuid', '=', (string) $result['orderUuid'])
            ->first();
        self::assertSame($target['uuid'], $sellerOrder['seller_uuid']);

        $this->cleanupTenant($connectionA, $tenant);
    }

    /**
     * Ordering (b): the CHECKOUT commits first. The concurrent transfer's
     * own seller claim (on the product's CURRENT owner) blocks entirely on
     * the checkout's held claim; once unblocked, its own snapshot (read
     * fresh, unaffected by the checkout -- which never mutates
     * `commerce_products.seller_uuid`) still matches, so the transfer
     * proceeds normally. No torn mapping: the already-committed order keeps
     * its correct (pre-transfer) seller snapshot forever, and the transfer
     * lands cleanly afterward.
     */
    public function testCheckoutCommitsFirstThenConcurrentTransferNeverTearsTheMappingOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'ccpgxfr002';
        $this->cleanupTenant($connectionA, $tenant);
        $this->activateWorkspace($connectionA, $tenant);

        $source = $this->seedActiveSeller($contextA, $tenant, 'ccpg-xfr-src2', 'ownerXfrSrc2');
        $target = $this->seedActiveSeller($contextA, $tenant, 'ccpg-xfr-tgt2', 'ownerXfrTgt2');

        $productUuid = 'ccpgxfrprd02';
        $connectionA->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => $tenant,
            'slug' => 'ccpg-xfr-prod2',
            'name' => 'Xfr Race Prod 2',
            'type' => 'physical',
            'status' => 'active',
            'seller_uuid' => $source['uuid'],
        ]);

        $orderUuid = 'ccpgxfrord02';

        // Connection A manually replicates the CLAIM+WRITE phase of a
        // partitioned checkout for $productUuid (still owned by $source),
        // holding the seller claim open (uncommitted).
        $connectionA->getTransactionManager()->begin();
        $this->manuallyPlaceMinimalPartitionedOrder(
            $connectionA,
            $contextA,
            $tenant,
            $source['uuid'],
            (string) $source['name'],
            $productUuid,
            $orderUuid,
            'CCPGXFR002-1'
        );

        // Connection B (subprocess, the REAL SellerAttributionService::assign())
        // attempts to transfer the SAME product to $target -- its own seller
        // claim (on $source, the product's current owner) blocks entirely on
        // A's held claim.
        $handle = $this->launchRaceChild($pgConfig, 'assign', [
            'tenant' => $tenant,
            'productUuid' => $productUuid,
            'targetSellerUuid' => $target['uuid'],
            'actor' => null,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'the transfer must succeed against fresh, committed state once the checkout has landed: '
                . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame($target['uuid'], $result['sellerUuid']);

        $finalProduct = $connectionA->table('commerce_products')->where('uuid', '=', $productUuid)->first();
        self::assertSame($target['uuid'], $finalProduct['seller_uuid'], 'the transfer must land after the checkout');

        $sellerOrder = $connectionA->table('commerce_seller_orders')->where('order_uuid', '=', $orderUuid)->first();
        self::assertSame(
            $source['uuid'],
            $sellerOrder['seller_uuid'],
            'the order must keep snapshotting the seller that was ACTUALLY current when it committed -- never '
                . 'torn by the later transfer'
        );

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // checkout-vs-suspend: a checkout claiming a just-suspended seller must
    // never silently complete.
    // =====================================================================

    public function testSuspendCommitsFirstThenConcurrentCheckoutIs409OnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'ccpgsus001';
        $this->cleanupTenant($connectionA, $tenant);
        $this->activateWorkspace($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'ccpg-sus-sel1', 'ownerSus001');

        $productUuid = 'ccpgsusprd01';
        $variantUuid = 'ccpgsusvar01';
        $connectionA->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => $tenant,
            'slug' => 'ccpg-sus-prod1',
            'name' => 'Suspend Race Prod',
            'type' => 'physical',
            'status' => 'active',
            'seller_uuid' => $seller['uuid'],
        ]);
        $connectionA->table('commerce_variants')->insert([
            'uuid' => $variantUuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'sku' => 'CCPG-SUS-1',
            'option_values' => '{}',
            'price' => 1000,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $sellers = new SellerRepository();

        // Connection A mirrors SellerService::suspend()'s claim-then-update
        // (active -> suspended, no live-products guard), and commits FIRST.
        $connectionA->getTransactionManager()->begin();
        self::assertTrue($sellers->claimRevision($contextA, $tenant, $seller['uuid']));
        $sellers->update($contextA, $tenant, $seller['uuid'], ['status' => 'suspended']);

        // Connection B (subprocess, the REAL CheckoutService) attempts to
        // check out a product owned by the just-suspended seller -- its own
        // seller claim blocks entirely on A's held claim.
        $handle = $this->launchRaceChild($pgConfig, 'checkout', [
            'tenant' => $tenant,
            'variantUuid' => $variantUuid,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'checkout against a just-suspended seller must fail');
        self::assertSame(CheckoutConflictException::class, $result['exceptionClass'] ?? null);

        self::assertSame(
            0,
            $connectionA->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->count(),
            'no order may exist once the checkout was rejected'
        );
        self::assertSame(
            0,
            $connectionA->table('commerce_seller_orders')->where('seller_uuid', '=', $seller['uuid'])->count()
        );

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // checkout-vs-close: a concurrent close attempt against a seller with a
    // live, just-checked-out product must be safely serialized and refused
    // -- never racing ahead of the checkout, never leaving a torn state.
    // =====================================================================

    public function testCloseAttemptDuringInFlightCheckoutIsSafelySerializedAndRefusedOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'ccpgcls001';
        $this->cleanupTenant($connectionA, $tenant);
        $this->activateWorkspace($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'ccpg-cls-sel1', 'ownerCls101');

        $productUuid = 'ccpgclsprd01';
        $connectionA->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => $tenant,
            'slug' => 'ccpg-cls-prod1',
            'name' => 'Close Race Prod',
            'type' => 'physical',
            'status' => 'active',
            'seller_uuid' => $seller['uuid'],
        ]);

        $orderUuid = 'ccpgclsord01';

        // Connection A manually replicates a checkout's CLAIM+WRITE phase for
        // $productUuid, holding the seller claim open (uncommitted).
        $connectionA->getTransactionManager()->begin();
        $this->manuallyPlaceMinimalPartitionedOrder(
            $connectionA,
            $contextA,
            $tenant,
            $seller['uuid'],
            (string) $seller['name'],
            $productUuid,
            $orderUuid,
            'CCPGCLS001-1'
        );

        // Connection B (subprocess, the REAL SellerService::close()) attempts
        // to close the seller -- its own claim blocks entirely on A's held
        // claim.
        $handle = $this->launchRaceChild($pgConfig, 'close', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'actor' => null,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'a seller with a live, just-checked-out product must never close');
        self::assertSame(SellerLifecycleException::class, $result['exceptionClass'] ?? null);

        $finalSeller = $connectionA->table('commerce_sellers')->where('uuid', '=', $seller['uuid'])->first();
        self::assertSame('active', $finalSeller['status'], 'the seller must remain active -- the close attempt failed');

        $sellerOrder = $connectionA->table('commerce_seller_orders')->where('order_uuid', '=', $orderUuid)->first();
        self::assertSame($seller['uuid'], $sellerOrder['seller_uuid'], 'the checkout completed correctly, unaffected');

        $this->cleanupTenant($connectionA, $tenant);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane for true two-connection row-lock interleaving.');
        }
    }

    /**
     * Manually replicates the ownership-claim + write phase of a partitioned
     * checkout for exactly ONE product/seller/line, matching
     * `CheckoutService::claimMarketplaceOwnership()` (claim the seller, then
     * the product) followed by the order/order-line/seller-order writes it
     * makes afterward -- run directly against $connectionA so the caller can
     * hold the transaction open (uncommitted) to force a genuine concurrent
     * block, then commit at a moment of the caller's own choosing. Every
     * column not exercised by the race itself (subtotal/currency/etc.) is a
     * fixed, deterministic placeholder.
     */
    private function manuallyPlaceMinimalPartitionedOrder(
        Connection $connectionA,
        ApplicationContext $contextA,
        string $tenant,
        string $sellerUuid,
        string $sellerName,
        string $productUuid,
        string $orderUuid,
        string $orderNumber
    ): void {
        $sellers = new SellerRepository();
        $products = new ProductRepository();

        self::assertTrue($sellers->claimRevision($contextA, $tenant, $sellerUuid));
        self::assertTrue($products->claimCatalogRevision($contextA, $tenant, $productUuid));

        (new OrderRepository())->insert($contextA, [
            'uuid' => $orderUuid,
            'tenant_uuid' => $tenant,
            'order_number' => $orderNumber,
            'status' => 'pending_payment',
            'email' => 'racebuyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'placed_at' => $connectionA->getDriver()->formatDateTime(),
            'marketplace_partitioned' => true,
        ], [[
            'line_uuid' => 'l' . substr(md5($orderUuid), 0, 11),
            'variant_uuid' => 'v' . substr(md5($orderUuid), 0, 11),
            'product_name' => 'Race Prod',
            'sku' => 'RACE-SKU-1',
            'option_values' => [],
            'unit_price' => 1000,
            'quantity' => 1,
            'seller_uuid' => $sellerUuid,
            'discount_amount' => 0,
            'tax_amount' => 0,
        ]]);

        (new SellerOrderRepository())->insertForOrder($contextA, $tenant, [[
            'order_uuid' => $orderUuid,
            'order_number' => $orderNumber,
            'seller_uuid' => $sellerUuid,
            'seller_name_snapshot' => $sellerName,
            'currency' => 'USD',
            'subtotal' => 1000,
            'allocated_discount' => 0,
            'allocated_shipping_discount' => 0,
            'allocated_shipping' => 0,
            'allocated_tax' => 0,
            'attributed_total' => 1000,
            'tax_attribution_method' => 'aggregate_allocated',
        ]]);
    }

    /**
     * @param array<string,mixed> $pgConfig
     * @param array<string,mixed> $args
     * @return array{0: resource, 1: array<int,resource>}
     */
    private function launchRaceChild(array $pgConfig, string $action, array $args): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/marketplace_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $action,
                json_encode($args, JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int,resource>} $handle
     * @return array<string,mixed>
     */
    private function collectRaceChild(array $handle): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "subprocess produced no parseable result. stderr: {$stderr}");

        return $result;
    }

    /** @return array<string,mixed> */
    private function seedActiveSeller(
        ApplicationContext $context,
        string $tenant,
        string $slug,
        string $ownerUserUuid
    ): array {
        return (new SellerService(new SellerRepository(), new SellerMembershipRepository()))->create(
            $context,
            $tenant,
            $slug,
            ucfirst(str_replace('-', ' ', $slug)),
            null,
            $ownerUserUuid
        );
    }

    private function activateWorkspace(Connection $connection, string $tenant): void
    {
        $connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'a' . substr(md5($tenant), 0, 11),
            'tenant_uuid' => $tenant,
            'status' => 'active',
        ]);
    }

    private function cleanupTenant(Connection $connection, string $tenant): void
    {
        $orderUuids = array_column(
            $connection->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->get(),
            'uuid'
        );
        foreach ($orderUuids as $orderUuid) {
            $connection->table('commerce_order_lines')->where('order_uuid', '=', (string) $orderUuid)->delete();
        }
        $connection->table('commerce_seller_orders')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->delete();

        $productUuids = array_column(
            $connection->table('commerce_products')->where('tenant_uuid', '=', $tenant)->get(),
            'uuid'
        );
        foreach ($productUuids as $uuid) {
            $connection->table('commerce_variants')->where('product_uuid', '=', (string) $uuid)->delete();
        }
        $connection->table('commerce_products')->where('tenant_uuid', '=', $tenant)->forceDelete();
        $connection->table('commerce_seller_memberships')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_sellers')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_marketplace_settings')->where('tenant_uuid', '=', $tenant)->delete();
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
        $context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);

        return $context;
    }
}
