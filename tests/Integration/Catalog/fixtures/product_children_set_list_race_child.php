<?php

declare(strict_types=1);

/**
 * Standalone subprocess for ProductChildrenConcurrencyTest's real pgsql-lane
 * races (parent-type-change-vs-set-list and child-type-change-vs-set-list).
 *
 * Runs connection B's competing children set-list (the real
 * CatalogService::setProductChildren()) in a genuinely separate OS process (and
 * therefore a genuinely separate database connection) so its claim attempt really
 * blocks on PostgreSQL row-lock contention while connection A (the parent test
 * process) still holds a row (the parent OR a proposed child, per the calling
 * test) open and uncommitted mid-mutation. PHP has no threads, so this is the
 * only way to observe true two-connection interleaving.
 *
 * argv: 1=pgConfig JSON, 2=parentUuid, 3=childUuidsJson (JSON array of uuids)
 * stdout: JSON {"children": list<array<string,mixed>>|null, "exceptionClass": string|null}
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $parentUuid, $childUuidsJson] = $argv;
/** @var array<string,mixed> $pgConfig */
$pgConfig = json_decode($pgConfigJson, true, 512, JSON_THROW_ON_ERROR);
/** @var list<string> $childUuids */
$childUuids = json_decode($childUuidsJson, true, 512, JSON_THROW_ON_ERROR);

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
    new SentinelTenantResolver(),
    new StockRepository(),
    new ProductChildrenRepository()
);

$exceptionClass = null;
$children = null;

try {
    $children = $service->setProductChildren($context, $parentUuid, $childUuids);
} catch (\Throwable $e) {
    $exceptionClass = $e::class;
}

echo json_encode([
    'children' => $children,
    'exceptionClass' => $exceptionClass,
], JSON_THROW_ON_ERROR);
