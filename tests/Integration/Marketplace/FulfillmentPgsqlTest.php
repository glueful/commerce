<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Orders\FulfillmentStatus;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL race lanes for Marketplace MV2's fulfillment rollup claim
 * and the payment-confirmation PII gate (design spec §2.8/§2.12; plan
 * Task 10). Every case here requires TRUE two-connection row-lock
 * interleaving that SQLite cannot exercise (PHP has no threads, so a
 * genuine race needs a genuinely separate OS process/connection). Gating,
 * fixture-width discipline, self-healing per-test cleanup, and the
 * throwaway `Connection`/`ApplicationContext` construction all mirror
 * `MarketplacePgsqlTest`/`CheckoutClaimPgsqlTest` exactly; every subprocess
 * race follows their identical pattern: connection A (this test) manually
 * replicates the first side's PRE-commit steps directly via the
 * repositories, holds the transaction open and uncommitted, launches
 * connection B as a genuinely separate subprocess running the real service
 * call (`fixtures/seller_order_race_child.php`), sleeps to let B block on
 * A's held row lock, then A completes and commits -- releasing the lock so
 * B's blocked statement can proceed and resolve the race.
 */
final class FulfillmentPgsqlTest extends CommerceTestCase
{
    // =====================================================================
    // Fulfillment rollup claim (design spec §2.8): two children of ONE
    // parent fulfilling CONCURRENTLY serialize on the parent
    // fulfillment_revision claim, so the rollup is computed once, the
    // parent reaches fulfilled exactly once, and OrderFulfilled fires once.
    // =====================================================================

    public function testTwoChildrenOfOneParentFulfillingConcurrentlySerializeAndRollUpExactlyOnceOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'fopgroll001';
        $this->cleanupTenant($connectionA, $tenant);
        $this->activateWorkspace($connectionA, $tenant);

        $sellerA = $this->seedActiveSeller($contextA, $tenant, 'fopg-roll-sel-a', 'ownerRollA01');
        $sellerB = $this->seedActiveSeller($contextA, $tenant, 'fopg-roll-sel-b', 'ownerRollB01');

        $orderUuid = 'fopgrollord1';
        $connectionA->table('commerce_orders')->insert([
            'uuid' => $orderUuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'FOPGROLL-1',
            'status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => true,
            'fulfillment_revision' => 0,
            'email' => 'racebuyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 2000,
            'grand_total' => 2000,
            'placed_at' => $connectionA->getDriver()->formatDateTime(),
        ]);

        $confirmedAt = $connectionA->getDriver()->formatDateTime();
        $childAUuid = 'fopgrollcha1';
        $childBUuid = 'fopgrollchb1';
        foreach ([[$childAUuid, $sellerA, 1], [$childBUuid, $sellerB, 2]] as [$childUuid, $seller, $partitionNumber]) {
            $connectionA->table('commerce_seller_orders')->insert([
                'uuid' => $childUuid,
                'tenant_uuid' => $tenant,
                'order_uuid' => $orderUuid,
                'seller_uuid' => $seller['uuid'],
                'seller_name_snapshot' => $seller['name'],
                'partition_number' => $partitionNumber,
                'seller_reference' => 'FOPGROLL-1-' . $partitionNumber,
                'currency' => 'USD',
                'subtotal' => 1000,
                'attributed_total' => 1000,
                'tax_attribution_method' => 'aggregate_allocated',
                'confirmed_at' => $confirmedAt,
                'fulfillment_status' => 'unfulfilled',
                'status' => 'open',
                'revision' => 0,
            ]);
        }

        $orders = new OrderRepository();
        $sellerOrders = new SellerOrderRepository();

        // Connection A manually replicates SellerOrderFulfillmentService::fulfill()'s
        // exact steps for child A: claim the PARENT first, then the child,
        // apply the child transition, then re-read + roll up -- computing
        // 'partial' since child B is still unfulfilled at this point. Held
        // open (uncommitted).
        $connectionA->getTransactionManager()->begin();
        $orders->claimFulfillmentMutation($contextA, $tenant, $orderUuid);
        self::assertTrue($sellerOrders->claimRevision($contextA, $tenant, $childAUuid));
        $sellerOrders->markFulfilled($contextA, $tenant, $childAUuid, [
            'carrier' => 'UPS',
            'tracking_number' => 'A-TRACK-1',
        ]);
        $childrenSoFar = $sellerOrders->forOrder($contextA, $tenant, $orderUuid);
        $rollupSoFar = FulfillmentStatus::rollup($childrenSoFar);
        self::assertSame(
            FulfillmentStatus::PARENT_PARTIAL,
            $rollupSoFar,
            'sanity: only one of the two children is fulfilled at this point'
        );
        $orders->applyFulfillmentRollup($contextA, $tenant, $orderUuid, $rollupSoFar);

        // Connection B (subprocess, the REAL SellerOrderFulfillmentService)
        // fulfills child B concurrently -- its own parent claim blocks
        // entirely on A's held claim.
        $handle = $this->launchRaceChild($pgConfig, 'fulfillChild', [
            'tenant' => $tenant,
            'orderUuid' => $orderUuid,
            'sellerOrderUuid' => $childBUuid,
            'actorSellerUuid' => null,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'child B fulfillment must succeed: ' . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame('fulfilled', $result['status']);
        self::assertSame(
            1,
            $result['sellerOrderFulfilledCount'],
            'exactly one SellerOrderFulfilled for the child B fulfilled here'
        );
        self::assertSame(
            1,
            $result['orderFulfilledCount'],
            'exactly one OrderFulfilled -- the parent reached fulfilled exactly once, never a double-fire'
        );

        $finalOrder = $connectionA->table('commerce_orders')->where('uuid', '=', $orderUuid)->first();
        self::assertSame('fulfilled', $finalOrder['status']);
        self::assertSame('fulfilled', $finalOrder['fulfillment_status']);
        self::assertSame(
            2,
            (int) $finalOrder['fulfillment_revision'],
            'both A and B genuinely claimed the parent revision -- no lost update, no skipped lock'
        );

        foreach ($sellerOrders->forOrder($contextA, $tenant, $orderUuid) as $child) {
            self::assertSame('fulfilled', $child['fulfillment_status']);
        }

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // Payment PII gate (design spec §2.12): the parent paid CAS and every
    // child's confirmed_at stamp commit-or-roll-back TOGETHER, proven under
    // genuine two-connection contention on the parent CAS row.
    // =====================================================================

    public function testPaidCasPlusEveryChildConfirmedAtStampCommitOrRollBackTogetherAcrossTwoConnectionsOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'fopgpaid001';
        $this->cleanupTenant($connectionA, $tenant);
        $this->activateWorkspace($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'fopg-paid-sel1', 'ownerPaid001');

        $orderUuid = 'fopgpaidord1';
        $connectionA->table('commerce_orders')->insert([
            'uuid' => $orderUuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'FOPGPAID-1',
            'status' => 'pending_payment',
            'marketplace_partitioned' => true,
            'email' => 'racebuyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'placed_at' => $connectionA->getDriver()->formatDateTime(),
        ]);
        $childUuid = 'fopgpaidchi1';
        $connectionA->table('commerce_seller_orders')->insert([
            'uuid' => $childUuid,
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'seller_uuid' => $seller['uuid'],
            'seller_name_snapshot' => $seller['name'],
            'partition_number' => 1,
            'seller_reference' => 'FOPGPAID-1-1',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
            'tax_attribution_method' => 'aggregate_allocated',
            'confirmed_at' => null,
            'fulfillment_status' => 'unfulfilled',
            'status' => 'open',
            'revision' => 0,
        ]);

        $orders = new OrderRepository();
        $confirmation = new SellerOrderPaymentConfirmation();

        // Connection A manually replicates OrderPaymentService::markPaid()'s
        // FULL transactional step (pending_payment -> paid CAS + every
        // child's confirmed_at stamp), and holds it open (uncommitted).
        $connectionA->getTransactionManager()->begin();
        $orders->transition($contextA, $tenant, $orderUuid, 'paid');
        $confirmation->confirm($contextA, $tenant, $orderUuid);

        // Connection B (subprocess, the REAL OrderPaymentService::markPaid())
        // attempts to mark the SAME order paid concurrently -- its own CAS
        // UPDATE (`WHERE status = 'pending_payment'`) blocks entirely on A's
        // held row lock.
        $handle = $this->launchRaceChild($pgConfig, 'markPaid', [
            'tenant' => $tenant,
            'orderUuid' => $orderUuid,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse(
            $result['ok'] ?? true,
            'the second concurrent markPaid() must fail once the CAS has already committed elsewhere'
        );
        self::assertSame(\DomainException::class, $result['exceptionClass'] ?? null);

        $finalOrder = $connectionA->table('commerce_orders')->where('uuid', '=', $orderUuid)->first();
        self::assertSame('paid', $finalOrder['status'], 'the WINNER (A) committed the CAS');

        $finalChild = $connectionA->table('commerce_seller_orders')->where('uuid', '=', $childUuid)->first();
        self::assertNotNull(
            $finalChild['confirmed_at'],
            'the WINNER (A) committed the confirmed_at stamp TOGETHER with the CAS -- never one without the other'
        );

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
     * @param array<string,mixed> $pgConfig
     * @param array<string,mixed> $args
     * @return array{0: resource, 1: array<int,resource>}
     */
    private function launchRaceChild(array $pgConfig, string $action, array $args): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/seller_order_race_child.php',
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
        return (new SellerService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            new SellerLifecycleEventRepository()
        ))->create(
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
