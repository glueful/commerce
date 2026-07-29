<?php

/**
 * Standalone subprocess for WishlistConcurrencyTest's real-PostgreSQL races. Runs
 * connection B's competing growth path (a save or an import) in a genuinely separate
 * OS process -- and therefore a genuinely separate database connection -- so its
 * `WishlistRepository::claimList()` really blocks on PostgreSQL row-lock contention
 * while connection A (the parent test process) still holds the wishlist's parent row
 * open and uncommitted. Mirrors Customers/fixtures/address_default_race_child.php.
 *
 * argv: 1=pgConfig JSON, 2=tenant, 3=userUuid, 4=operation(add|import), 5=payload
 *       (a single product uuid for `add`, a comma-separated list for `import`)
 * stdout: JSON {"imported": list<string>, "added": bool|null, "count": int,
 *               "exceptionClass": string|null, "message": string|null}
 */

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Wishlist\WishlistRepository;
use Glueful\Extensions\Commerce\Wishlist\WishlistService;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $tenant, $userUuid, $operation, $payload] = $argv;
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

$tenantResolver = new class ($tenant) implements CurrentTenantResolver {
    public function __construct(private string $tenant)
    {
    }

    public function tenantUuid(ApplicationContext $context): string
    {
        return $this->tenant;
    }
};

$repository = new WishlistRepository();
$service = new WishlistService($repository, new ProductRepository(), $tenantResolver);

$imported = [];
$added = null;
$exceptionClass = null;
$message = null;

try {
    if ($operation === 'add') {
        $added = $service->add($context, $userUuid, $payload);
    } else {
        $imported = $service->import($context, $userUuid, explode(',', $payload))->imported;
    }
} catch (\Throwable $e) {
    $exceptionClass = $e::class;
    $message = $e->getMessage();
}

echo json_encode([
    'imported' => $imported,
    'added' => $added,
    'count' => $repository->countForUser($context, $tenant, $userUuid),
    'exceptionClass' => $exceptionClass,
    'message' => $message,
], JSON_THROW_ON_ERROR);
