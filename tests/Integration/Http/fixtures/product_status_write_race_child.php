<?php

declare(strict_types=1);

/**
 * Standalone subprocess for ApiParityPgsqlTest's bulk-vs-single-write
 * serialization touch test: runs the real `CatalogService::setProductStatus()`
 * primitive -- the EXACT call both `AdminProductController::bulkStatus()`'s
 * per-item loop and an ordinary status-bearing single PATCH delegate to (see
 * that class's docblock) -- in a genuinely separate OS process/database
 * connection, so two of these launched concurrently against the SAME product
 * prove the shared `catalog_revision` claim really serializes two REAL
 * PostgreSQL connections (never a lost update: exactly one claim per attempt,
 * the final status is whichever committed last, never a corrupted blend).
 *
 * argv: 1=pgConfig JSON, 2=productUuid, 3=status
 * stdout: JSON {"applied": bool, "exceptionClass": string|null}
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $productUuid, $status] = $argv;
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

$service = new CatalogService(
    new ProductRepository(),
    new VariantRepository(),
    new SentinelTenantResolver()
);

$exceptionClass = null;
$applied = false;

try {
    $service->setProductStatus($context, $productUuid, $status);
    $applied = true;
} catch (\Throwable $e) {
    $exceptionClass = $e::class;
}

echo json_encode([
    'applied' => $applied,
    'exceptionClass' => $exceptionClass,
], JSON_THROW_ON_ERROR);
