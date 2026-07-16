<?php

declare(strict_types=1);

/**
 * Standalone subprocess for ZoneMethodConcurrencyTest's
 * testConcurrentZoneDeleteVsMethodCreateSerializesDeterministically().
 *
 * Runs connection B's competing method-create (the real
 * ShippingZoneService::createMethod()) in a genuinely separate OS process (and
 * therefore a genuinely separate database connection) so its claim attempt really
 * blocks on PostgreSQL row-lock contention while connection A (the parent test
 * process) still holds the zone row open and uncommitted mid-delete. PHP has no
 * threads, so this is the only way to observe true two-connection interleaving.
 *
 * argv: 1=pgConfig JSON, 2=zoneUuid
 * stdout: JSON {"method": array<string,mixed>|null, "exceptionClass": string|null}
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $zoneUuid] = $argv;
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

$service = new ShippingZoneService(
    new ShippingZoneRepository(),
    new ShippingClassRepository(),
    new SentinelTenantResolver()
);

$exceptionClass = null;
$method = null;

try {
    $method = $service->createMethod($context, $zoneUuid, [
        'kind' => 'flat',
        'label' => 'Standard',
        'config' => ['amount' => 500],
    ]);
} catch (\Throwable $e) {
    $exceptionClass = $e::class;
}

echo json_encode([
    'method' => $method,
    'exceptionClass' => $exceptionClass,
], JSON_THROW_ON_ERROR);
