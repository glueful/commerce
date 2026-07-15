<?php

declare(strict_types=1);

/**
 * Standalone subprocess for DownloadGrantConcurrencyTest's mint-vs-revoke race
 * (ordering: connection A holds an uncommitted mint open on the grant row; this
 * process is connection B's competing `DownloadGrantRepository::revoke()` guarded
 * UPDATE, which blocks on A's row lock until A commits). Mirrors
 * Catalog/fixtures/media_cover_race_child.php's structure exactly.
 *
 * argv: 1=pgConfig JSON, 2=tenant, 3=grantUuid
 * stdout: JSON {"revoked": bool, "exceptionClass": string|null}
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $tenant, $grantUuid] = $argv;
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

$revoked = false;
$exceptionClass = null;

try {
    $revoked = (new DownloadGrantRepository())->revoke($context, $tenant, $grantUuid);
} catch (\Throwable $e) {
    $exceptionClass = $e::class;
}

echo json_encode([
    'revoked' => $revoked,
    'exceptionClass' => $exceptionClass,
], JSON_THROW_ON_ERROR);
