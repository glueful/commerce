<?php

declare(strict_types=1);

/**
 * Standalone subprocess for OrderNumberGeneratorPgsqlTest's real-pgsql race
 * lanes (admin-order-creation cycle 2, Task 4): runs one action against a
 * genuinely separate OS process (and therefore a genuinely separate database
 * connection/session), so its `commerce_sequences` insert really blocks on
 * PostgreSQL unique-index contention held by the parent test process's own
 * connection A. Mirrors `Marketplace/fixtures/marketplace_race_child.php`'s
 * shape.
 *
 * argv: 1=pgConfig JSON, 2=action, 3=args JSON
 *
 * actions:
 *  - hold_insert: begins a transaction, inserts a fresh
 *    `commerce_sequences(tenant_uuid, 'order', 1)` row, sleeps for
 *    `args.sleepMs` milliseconds (holding the insert open/uncommitted so a
 *    concurrent connection's competing insert of the SAME key blocks on it),
 *    then commits. stdout: {"ok":true}.
 *  - allocate_in_transaction: begins its OWN transaction (mirrors a real
 *    order-creation flow already holding an open transaction when it asks
 *    for the next order number), calls the REAL
 *    `OrderNumberGenerator::next()`, commits its own transaction on success,
 *    and reports the outcome. stdout:
 *    {"ok":true,"orderNumber":"ORD-000002"} or
 *    {"ok":false,"exceptionClass":"...","message":"..."}.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $action, $argsJson] = $argv;
/** @var array<string,mixed> $pgConfig */
$pgConfig = json_decode($pgConfigJson, true, 512, JSON_THROW_ON_ERROR);
/** @var array<string,mixed> $args */
$args = json_decode($argsJson, true, 512, JSON_THROW_ON_ERROR);

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

$tenant = (string) $args['tenant'];

$out = [];

try {
    switch ($action) {
        case 'hold_insert':
            $connection->getTransactionManager()->begin();
            $connection->table('commerce_sequences')->insert([
                'tenant_uuid' => $tenant,
                'name' => 'order',
                'value' => 1,
            ]);
            usleep((int) $args['sleepMs'] * 1000);
            $connection->getTransactionManager()->commit();
            $out = ['ok' => true];
            break;

        case 'allocate_in_transaction':
            $connection->getTransactionManager()->begin();
            try {
                $orderNumber = (new OrderNumberGenerator())->next($context, $tenant);
                $connection->getTransactionManager()->commit();
                $out = ['ok' => true, 'orderNumber' => $orderNumber, 'exceptionClass' => null];
            } catch (\Throwable $e) {
                // The transaction may already be unusable at this point (the
                // exact bug this fixture exists to prove); attempt a rollback
                // for hygiene but never let a failure here mask the primary
                // exception being reported.
                try {
                    $connection->getTransactionManager()->rollback();
                } catch (\Throwable) {
                    // ignore secondary failure
                }
                throw $e;
            }
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
