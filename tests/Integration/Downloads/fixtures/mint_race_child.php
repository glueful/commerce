<?php

declare(strict_types=1);

/**
 * Standalone subprocess for DownloadGrantConcurrencyTest's real-PostgreSQL mint races.
 *
 * Runs connection B's competing `DownloadAccessService::mint()` call in a genuinely
 * separate OS process (and therefore a genuinely separate database connection) so its
 * `claimOrderFinancialMutation()`/grant-row UPDATE really blocks on PostgreSQL row-lock
 * contention while connection A (the parent test process) still holds a row open and
 * uncommitted. PHP has no threads, so this is the only way to observe true
 * two-connection interleaving. Mirrors
 * Catalog/fixtures/media_cover_race_child.php's structure exactly.
 *
 * argv: 1=pgConfig JSON, 2=tenant, 3=orderUuid, 4=grantUuid, 5=requestBase, 6=signingSecret
 * stdout: JSON {"ok": bool|null, "code": string|null, "urlPresent": bool, "exceptionClass": string|null}
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadAccessService;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadUrlSigner;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Repository\BlobRepository;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $tenant, $orderUuid, $grantUuid, $requestBase, $signingSecret] = $argv;
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
$context->overrideConfig('app.key', $signingSecret);

$service = new DownloadAccessService(
    new OrderRepository(),
    new DownloadGrantRepository(),
    new DownloadUrlSigner(new BlobRepository($connection))
);

$ok = null;
$code = null;
$urlPresent = false;
$exceptionClass = null;

try {
    $result = $service->mint($context, $tenant, $orderUuid, $grantUuid, $requestBase);
    $ok = $result['ok'];
    $code = $result['code'] ?? null;
    $urlPresent = isset($result['url']);
} catch (\Throwable $e) {
    $exceptionClass = $e::class;
}

echo json_encode([
    'ok' => $ok,
    'code' => $code,
    'urlPresent' => $urlPresent,
    'exceptionClass' => $exceptionClass,
], JSON_THROW_ON_ERROR);
