<?php

declare(strict_types=1);

/**
 * Standalone subprocess for CategoryTreeConcurrencyTest's
 * testConcurrentDeleteVsChildAttachSerializesDeterministically().
 *
 * Runs connection B's competing child-attach (create-with-parent) in a genuinely
 * separate OS process (and therefore a genuinely separate database connection) so
 * its claim attempt really blocks on PostgreSQL row-lock contention while
 * connection A (the parent test process) still holds the parent category row open
 * and uncommitted mid-delete. PHP has no threads, so this is the only way to
 * observe true two-connection interleaving.
 *
 * argv: 1=pgConfig JSON, 2=parentUuid, 3=childSlug
 * stdout: JSON {"created": bool, "exceptionClass": string|null}
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $parentUuid, $childSlug] = $argv;
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

$service = new CategoryService(
    new CategoryRepository(),
    new ProductRepository(),
    new SentinelTenantResolver()
);

$exceptionClass = null;
$created = false;

try {
    $service->create($context, ['slug' => $childSlug, 'name' => $childSlug, 'parent_uuid' => $parentUuid]);
    $created = true;
} catch (\Throwable $e) {
    $exceptionClass = $e::class;
}

echo json_encode([
    'created' => $created,
    'exceptionClass' => $exceptionClass,
], JSON_THROW_ON_ERROR);
