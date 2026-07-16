<?php

declare(strict_types=1);

/**
 * Standalone subprocess for DownloadGrantConcurrencyTest's mint-vs-full-refund race
 * (ordering: connection A holds an uncommitted mint's order claim open; this process
 * is connection B's competing full manual refund via `RefundService::issue()`, whose
 * own `claimOrderFinancialMutation()` blocks on A's held order-row lock until A
 * commits). Mirrors Refunds/fixtures/gateway_refund_race_child.php's structure.
 *
 * argv: 1=pgConfig JSON, 2=tenant, 3=orderUuid, 4=idempotencyKey, 5=amount
 * stdout: JSON {"status": string|null, "exceptionClass": string|null}
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundInput;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $tenant, $orderUuid, $idempotencyKey, $amount] = $argv;
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

$service = new RefundService(
    new OrderRepository(),
    new RefundRepository(),
    new StockRepository(),
    $tenantResolver
);

$status = null;
$exceptionClass = null;

try {
    $refund = $service->issue(
        $context,
        $orderUuid,
        new RefundInput((int) $amount, 'pgsql race refund', [], false),
        $idempotencyKey
    );
    $status = $refund['status'];
} catch (\Throwable $e) {
    $exceptionClass = $e::class;
}

echo json_encode([
    'status' => $status,
    'exceptionClass' => $exceptionClass,
], JSON_THROW_ON_ERROR);
