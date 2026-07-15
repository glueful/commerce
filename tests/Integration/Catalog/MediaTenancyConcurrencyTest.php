<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Repository\BlobRepository;
use Psr\Container\ContainerInterface;

/**
 * Product media: tenant isolation (two real tenants, not the '' sentinel) and the
 * claim/demote concurrency invariant behind the at-most-one-cover rule. Follows the
 * `tenantAAAA01`/`tenantBBBB02` fixed-resolver convention from RefundTenancyTest and
 * the deterministic-claim-plus-pgsql-race split from RefundOrderClaimTest /
 * GatewayRefundTest. Schema shape + diagnostics/registry coverage for
 * `commerce_product_media` already lives in
 * tests/Integration/Migrations/CatalogBreadthShapeTest.php; this file is scoped to
 * media business behavior only.
 */
final class MediaTenancyConcurrencyTest extends CommerceTestCase
{
    private const TENANT_A = 'tenantAAAA01';
    private const TENANT_B = 'tenantBBBB02';

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../vendor/glueful/framework/migrations/uploads/001_CreateBlobsTable.php';
        (new \Glueful\Migrations\Uploads\CreateBlobsTable())->up($this->connection->getSchemaBuilder());
    }

    public function testClaimCatalogRevisionIncrementsAndReturnsTrueRepeatedly(): void
    {
        $this->seedProduct('', 'prodclaim001');
        $repository = new ProductRepository();

        self::assertTrue($repository->claimCatalogRevision($this->context, '', 'prodclaim001'));
        self::assertSame(1, $this->currentRevision('prodclaim001'));

        // Unlike a single-shot status transition, the revision claim is a pure
        // serialization primitive: every subsequent mutation attempt claims again.
        self::assertTrue($repository->claimCatalogRevision($this->context, '', 'prodclaim001'));
        self::assertSame(2, $this->currentRevision('prodclaim001'));
    }

    public function testClaimCatalogRevisionReturnsFalseForUnknownOrCrossTenantProduct(): void
    {
        $repository = new ProductRepository();
        self::assertFalse($repository->claimCatalogRevision($this->context, '', 'no-such-product'));

        $this->seedProduct(self::TENANT_B, 'prodclaimtb1');
        self::assertFalse($repository->claimCatalogRevision($this->context, '', 'prodclaimtb1'));
        self::assertSame(0, $this->currentRevision('prodclaimtb1', self::TENANT_B));
    }

    /**
     * Deterministic replacement for a true two-connection interleave (SQLite
     * `:memory:` cannot run one — see GatewayRefundTest's docblock for the same
     * adjudication). Two sequential cover-attach calls each claim-then-reread inside
     * their own transaction, exactly as two racing callers would once the loser's
     * claim unblocks after the winner commits. Asserts the invariant survives:
     * exactly one cover, deterministically the most recent claim's row, with the
     * earlier cover demoted rather than rejected. The real two-connection interleave
     * is exercised by testConcurrentCoverAttachesSerializeViaProductClaimAndDemote
     * DeterministicallyOverPgsql(), gated to a pgsql test lane.
     */
    public function testSequentialCoverClaimsDemoteDeterministicallyToExactlyOneCover(): void
    {
        $this->seedProduct('', 'prodrace0001');
        $this->seedBlob('blobracea0001');
        $this->seedBlob('blobraceb0001');
        $service = $this->mediaService();

        $first = $service->attach($this->context, 'prodrace0001', ['blob_uuid' => 'blobracea0001', 'role' => 'cover']);
        self::assertSame('cover', $first['role']);
        self::assertSame(1, $this->currentRevision('prodrace0001'));

        $second = $service->attach($this->context, 'prodrace0001', ['blob_uuid' => 'blobraceb0001', 'role' => 'cover']);
        self::assertSame('cover', $second['role']);
        self::assertSame(2, $this->currentRevision('prodrace0001'));

        $rows = (new ProductMediaRepository())->forProduct($this->context, '', 'prodrace0001');
        $covers = array_values(array_filter($rows, static fn (array $r): bool => $r['role'] === 'cover'));
        self::assertCount(1, $covers, 'Exactly one cover must survive the claim protocol.');
        self::assertSame('blobraceb0001', $covers[0]['blob_uuid']);

        $demoted = array_values(array_filter($rows, static fn (array $r): bool => $r['blob_uuid'] === 'blobracea0001'));
        self::assertSame('gallery', $demoted[0]['role']);
    }

    /**
     * Real cross-connection interleaving: connection A (this test) holds the
     * product's claim open and uncommitted while connection B (a genuinely
     * independent subprocess, fixtures/media_cover_race_child.php) attempts a full
     * cover attach against the SAME product. B's claim blocks on Postgres row-lock
     * contention until A commits its own cover row; B's mandatory post-claim
     * re-read then sees A's committed cover and demotes it, so exactly one cover
     * survives the whole interleave — the same claim+demote invariant proven
     * deterministically above, now proven under a genuine race.
     */
    public function testConcurrentCoverAttachesSerializeViaProductClaimAndDemoteDeterministically(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = [
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

        $connectionA = new Connection($pgConfig);
        $schema = $connectionA->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }
        require_once __DIR__ . '/../../../vendor/glueful/framework/migrations/uploads/001_CreateBlobsTable.php';
        (new \Glueful\Migrations\Uploads\CreateBlobsTable())->up($schema);

        $contextA = $this->pgsqlContext($connectionA);
        $productUuid = 'prodpgrace01';
        // Self-healing: remove any debris a previous (crashed or green) run of this
        // gated test left behind, so the lane is idempotently re-runnable.
        $this->deleteRaceDebris($connectionA, $productUuid, ['blobracea002', 'blobraceb002']);
        $connectionA->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => '',
            'slug' => 'prodpgrace01',
            'name' => 'Race product',
            'type' => 'physical',
            'status' => 'active',
        ]);
        $connectionA->table('blobs')->insert($this->blobRow('blobracea002'));
        $connectionA->table('blobs')->insert($this->blobRow('blobraceb002'));

        // A claims the product first -- this holds the row lock, uncommitted. The
        // claim primitive (not the full service) is used directly so the test can
        // pause mid-attach while B attempts to claim the same row.
        $connectionA->getTransactionManager()->begin();
        $products = new ProductRepository();
        self::assertTrue($products->claimCatalogRevision($contextA, '', $productUuid));

        // Launch B: its own claim attempt on the same product blocks on A's row lock.
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/media_cover_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $productUuid,
                'blobraceb002',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        // Give B time to reach and block on its own claim UPDATE before A proceeds.
        usleep(300_000);

        // A completes its cover attach directly (it already holds the claim, so no
        // service-level re-claim is needed): no media exists yet, so nothing to
        // demote; insert the cover row, then commit -- releasing the row lock so
        // B's blocked claim can proceed.
        (new ProductMediaRepository())->insert($contextA, [
            'uuid' => 'mediaracea01',
            'tenant_uuid' => '',
            'product_uuid' => $productUuid,
            'variant_uuid' => null,
            'blob_uuid' => 'blobracea002',
            'role' => 'cover',
            'position' => 0,
            'alt' => null,
        ]);
        $connectionA->getTransactionManager()->commit();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim($stdout), true);
        self::assertIsArray($result, "Connection B's subprocess produced no parseable result. stderr: {$stderr}");
        self::assertNull(
            $result['exceptionClass'],
            "B's cover attach must succeed, not be rejected (stderr: {$stderr})."
        );
        self::assertSame('cover', $result['role'] ?? null);

        $rows = $connectionA->table('commerce_product_media')
            ->where('product_uuid', '=', $productUuid)
            ->get();
        $covers = array_values(array_filter($rows, static fn (array $r): bool => $r['role'] === 'cover'));
        self::assertCount(1, $covers, 'Exactly one cover must survive the interleaved claim race.');
        self::assertSame('blobraceb002', $covers[0]['blob_uuid']);

        $demoted = array_values(array_filter($rows, static fn (array $r): bool => $r['blob_uuid'] === 'blobracea002'));
        self::assertSame(
            'gallery',
            $demoted[0]['role'],
            "A's cover must be demoted once B's claim commits after it."
        );

        $this->deleteRaceDebris($connectionA, $productUuid, ['blobracea002', 'blobraceb002']);
    }

    /**
     * Idempotent pgsql-lane cleanup: media rows, blobs, and the race product
     * (forceDelete — commerce_products carries deleted_at, so a plain delete()
     * would soft-delete and strand the unique uuid for the next run).
     *
     * @param list<string> $blobUuids
     */
    private function deleteRaceDebris(Connection $connection, string $productUuid, array $blobUuids): void
    {
        $connection->table('commerce_product_media')
            ->where('product_uuid', '=', $productUuid)
            ->delete();
        foreach ($blobUuids as $blobUuid) {
            $connection->table('blobs')->where('uuid', '=', $blobUuid)->delete();
        }
        $connection->table('commerce_products')
            ->where('uuid', '=', $productUuid)
            ->forceDelete();
    }

    public function testTenantBCannotAttachMediaToTenantAsProduct(): void
    {
        $this->seedProduct(self::TENANT_A, 'prodtenaa001');
        $this->seedBlob('blobtenaa0001');

        $this->expectException(NotFoundException::class);
        $this->mediaService(self::TENANT_B)->attach(
            $this->context,
            'prodtenaa001',
            ['blob_uuid' => 'blobtenaa0001']
        );
    }

    public function testTenantBCannotUpdateTenantAsMedia(): void
    {
        $this->seedProduct(self::TENANT_A, 'prodtenaa002');
        $this->seedBlob('blobtenaa0002');
        $created = $this->mediaService(self::TENANT_A)->attach(
            $this->context,
            'prodtenaa002',
            ['blob_uuid' => 'blobtenaa0002']
        );

        $this->expectException(NotFoundException::class);
        $this->mediaService(self::TENANT_B)->update($this->context, (string) $created['uuid'], ['alt' => 'sneaky']);
    }

    public function testTenantBCannotDetachTenantAsMedia(): void
    {
        $this->seedProduct(self::TENANT_A, 'prodtenaa003');
        $this->seedBlob('blobtenaa0003');
        $created = $this->mediaService(self::TENANT_A)->attach(
            $this->context,
            'prodtenaa003',
            ['blob_uuid' => 'blobtenaa0003']
        );

        try {
            $this->mediaService(self::TENANT_B)->detach($this->context, (string) $created['uuid']);
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            self::assertNotNull(
                (new ProductMediaRepository())->findByUuid($this->context, self::TENANT_A, (string) $created['uuid']),
                'Tenant A\'s media row must survive tenant B\'s rejected detach attempt.'
            );
        }
    }

    public function testTenantBCannotReorderTenantAsMedia(): void
    {
        $this->seedProduct(self::TENANT_A, 'prodtenaa004');
        $this->seedBlob('blobtenaa0004');
        $created = $this->mediaService(self::TENANT_A)->attach(
            $this->context,
            'prodtenaa004',
            ['blob_uuid' => 'blobtenaa0004']
        );

        $this->expectException(NotFoundException::class);
        $this->mediaService(self::TENANT_B)->reorder(
            $this->context,
            'prodtenaa004',
            [['uuid' => (string) $created['uuid'], 'position' => 0]]
        );
    }

    private function currentRevision(string $uuid, string $tenant = ''): int
    {
        $row = $this->connection->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? -1 : (int) $row['catalog_revision'];
    }

    private function seedProduct(string $tenant, string $uuid): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'type' => 'physical',
            'status' => 'active',
        ]);
    }

    private function seedBlob(string $uuid): void
    {
        $this->connection->table('blobs')->insert($this->blobRow($uuid));
    }

    /** @return array<string,mixed> */
    private function blobRow(string $uuid): array
    {
        return [
            'uuid' => $uuid,
            'name' => $uuid,
            'mime_type' => 'image/png',
            'size' => 100,
            'url' => '/storage/' . $uuid,
            'storage_type' => 'local',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'uploader0003',
        ];
    }

    private function mediaService(?string $tenant = null): ProductMediaService
    {
        return new ProductMediaService(
            new ProductRepository(),
            new VariantRepository(),
            new ProductMediaRepository(),
            $tenant === null ? new SentinelTenantResolver() : $this->fixedTenant($tenant),
            new BlobRepository($this->connection)
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
