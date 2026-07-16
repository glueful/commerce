<?php

declare(strict_types=1);

/**
 * Standalone subprocess for ApiParityPgsqlTest's real pgsql-lane discount
 * delete-vs-redemption race, "delete commits first" ordering: runs
 * connection B's competing checkout-style consume attempt in a genuinely
 * separate OS process/database connection -- `DiscountService::consume()`
 * wrapped in its own transaction (mirroring how `CheckoutService::placeOrder()`
 * calls it inside the outer order-placement transaction) -- so its
 * `consumeUsage()` UPDATE really blocks on PostgreSQL row-lock contention
 * while connection A (the parent test process) still holds the SAME discount
 * row open and uncommitted mid-delete (see
 * {@see \Glueful\Extensions\Commerce\Discounts\DiscountService}'s class
 * docblock for the full race analysis).
 *
 * argv: 1=pgConfig JSON, 2=discountUuid, 3=orderUuid, 4=buyerIdentity
 * stdout: JSON {"consumed": bool, "exceptionClass": string|null}
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $discountUuid, $orderUuid, $buyerIdentity] = $argv;
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

$discounts = new DiscountRepository();
$discount = $discounts->findByUuid($context, '', $discountUuid);

$service = new DiscountService($discounts, new SentinelTenantResolver());

$exceptionClass = null;
$consumed = false;

try {
    db($context)->transaction(
        function () use ($context, $service, $discount, $orderUuid, $buyerIdentity): void {
            $service->consume($context, $discount, $orderUuid, $buyerIdentity);
        }
    );
    $consumed = true;
} catch (\Throwable $e) {
    $exceptionClass = $e::class;
}

echo json_encode([
    'consumed' => $consumed,
    'exceptionClass' => $exceptionClass,
], JSON_THROW_ON_ERROR);
