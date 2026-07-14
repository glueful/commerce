<?php

declare(strict_types=1);

/**
 * Standalone subprocess for GatewayRefundTest::testConcurrentGatewayReservationsSerializeViaOrderClaim().
 *
 * Runs connection B's competing gateway refund attempt in a genuinely separate OS
 * process (and therefore a genuinely separate database connection) so its claim
 * attempt really blocks on PostgreSQL row-lock contention while connection A (the
 * parent test process) still holds the order row open and uncommitted. PHP has no
 * threads, so this is the only way to observe true two-connection interleaving.
 *
 * argv: 1=pgConfig JSON, 2=orderUuid, 3=idempotencyKey
 * stdout: JSON {"exceptionClass": string|null, "collectorCalledInsideTransaction": bool}
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundInput;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\RefundCollector;
use Glueful\Extensions\Contracts\Payments\RefundRequest;
use Glueful\Extensions\Contracts\Payments\RefundResult;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $orderUuid, $idempotencyKey] = $argv;
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

$collector = new class implements RefundCollector {
    public bool $calledInsideTransaction = false;
    public bool $called = false;

    public function refund(
        ApplicationContext $context,
        PayableReference $payable,
        RefundRequest $request
    ): RefundResult {
        $this->called = true;
        $this->calledInsideTransaction = db($context)->withinTransaction();

        return new RefundResult(RefundResult::PENDING);
    }
};

$service = new RefundService(
    new OrderRepository(),
    new RefundRepository(),
    new StockRepository(),
    new SentinelTenantResolver(),
    $collector
);

$exceptionClass = null;
try {
    $service->issue(
        $context,
        $orderUuid,
        new RefundInput(60, 'connection B', [], false),
        $idempotencyKey
    );
} catch (\Throwable $e) {
    $exceptionClass = $e::class;
}

echo json_encode([
    'exceptionClass' => $exceptionClass,
    // Only meaningful if the collector was actually invoked (the losing side should never
    // reach it — validate() rejects it before the reservation transaction commits).
    'collectorCalledInsideTransaction' => $collector->called && $collector->calledInsideTransaction,
], JSON_THROW_ON_ERROR);
