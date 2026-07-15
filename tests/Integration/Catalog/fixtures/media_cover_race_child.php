<?php

declare(strict_types=1);

/**
 * Standalone subprocess for MediaTenancyConcurrencyTest's
 * testConcurrentCoverAttachesSerializeViaProductClaimAndDemoteDeterministically().
 *
 * Runs connection B's competing cover-attach in a genuinely separate OS process (and
 * therefore a genuinely separate database connection) so its claim attempt really
 * blocks on PostgreSQL row-lock contention while connection A (the parent test
 * process) still holds the product row open and uncommitted. PHP has no threads, so
 * this is the only way to observe true two-connection interleaving.
 *
 * argv: 1=pgConfig JSON, 2=productUuid, 3=blobUuid
 * stdout: JSON {"role": string|null, "exceptionClass": string|null}
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Repository\BlobRepository;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $productUuid, $blobUuid] = $argv;
/** @var array<string,mixed> $pgConfig */
$pgConfig = json_decode($pgConfigJson, true, 512, JSON_THROW_ON_ERROR);

$connection = new Connection($pgConfig);

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
$context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../../config/commerce.php');

$service = new ProductMediaService(
    new ProductRepository(),
    new VariantRepository(),
    new ProductMediaRepository(),
    new SentinelTenantResolver(),
    new BlobRepository($connection)
);

$exceptionClass = null;
$role = null;

try {
    $row = $service->attach($context, $productUuid, ['blob_uuid' => $blobUuid, 'role' => 'cover']);
    $role = $row['role'];
} catch (\Throwable $e) {
    $exceptionClass = $e::class;
}

echo json_encode([
    'role' => $role,
    'exceptionClass' => $exceptionClass,
], JSON_THROW_ON_ERROR);
