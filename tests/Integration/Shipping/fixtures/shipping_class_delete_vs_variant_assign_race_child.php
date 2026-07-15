<?php

declare(strict_types=1);

/**
 * Standalone subprocess for ShippingClassConcurrencyTest's
 * testConcurrentClassDeleteVsVariantAssignSerializesDeterministically().
 *
 * Runs connection B's competing variant shipping-class assignment (the real
 * CatalogService::updateVariant()) in a genuinely separate OS process (and
 * therefore a genuinely separate database connection) so its claim on the
 * shared class row really blocks on PostgreSQL row-lock contention while
 * connection A (the parent test process) still holds that same row open and
 * uncommitted mid-delete. PHP has no threads, so this is the only way to
 * observe true two-connection interleaving.
 *
 * argv: 1=pgConfig JSON, 2=variantUuid, 3=classUuid
 * stdout: JSON {"exceptionClass": string|null}
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $variantUuid, $classUuid] = $argv;
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

$service = new CatalogService(new ProductRepository(), new VariantRepository(), new SentinelTenantResolver());

$exceptionClass = null;

try {
    $service->updateVariant($context, $variantUuid, ['shipping_class_uuid' => $classUuid]);
} catch (\Throwable $e) {
    $exceptionClass = $e::class;
}

echo json_encode([
    'exceptionClass' => $exceptionClass,
], JSON_THROW_ON_ERROR);
