<?php

declare(strict_types=1);

/**
 * Standalone subprocess for OrderFulfillmentServiceTest's real-pgsql CAS-conflict race
 * (admin-order-creation cycle 2, Task 5): runs the REAL
 * {@see \Glueful\Extensions\Commerce\Orders\OrderFulfillmentService::fulfill()} against
 * a genuinely separate OS process (and therefore a genuinely separate database
 * connection/session), so its `commerce_orders` transition genuinely blocks on the
 * parent test process's own connection A, which is holding the SAME row's transition
 * open/uncommitted. Mirrors `Orders/fixtures/order_number_race_child.php`'s shape.
 *
 * argv: 1=pgConfig JSON, 2=tenant, 3=orderUuid
 *
 * stdout: {"ok":true,"trackingRef":"..."} on success, or
 *   {"ok":false,"exceptionClass":"...","message":"..."} if fulfill() throws.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\OrderFulfillmentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $tenant, $orderUuid] = $argv;
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

try {
    $service = new OrderFulfillmentService(new OrderRepository());
    $fulfilled = $service->fulfill($context, $tenant, $orderUuid, 'B-TRACK');
    $out = ['ok' => true, 'trackingRef' => $fulfilled['tracking_ref']];
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
