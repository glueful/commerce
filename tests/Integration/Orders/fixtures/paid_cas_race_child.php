<?php

declare(strict_types=1);

/**
 * Standalone subprocess for `PaidCasRacePgsqlTest`'s real-pgsql
 * `pending_payment -> paid` race lane (cleanup-train Task 4): runs the REAL
 * {@see \Glueful\Extensions\Commerce\Orders\OrderPaymentService::markPaid()}
 * against a genuinely separate OS process / database connection / session and
 * holds the resulting row lock open, so the parent test's own concurrent
 * settlement really blocks on it and then resolves into a deterministic
 * 0-affected-rows compare-and-set LOSS once this side commits. Mirrors
 * `Orders/fixtures/draft_finalize_race_child.php`'s shape exactly.
 *
 * argv: 1=pgConfig JSON, 2=action, 3=args JSON
 *
 * actions:
 *  - hold_mark_paid: begins a transaction, marks args.orderUuid paid for
 *    args.tenant, sleeps for `args.sleepMs` milliseconds (holding the
 *    uncommitted row lock), then commits.
 *    stdout: {"ok":true,"performed":true}.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
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

$out = [];

try {
    switch ($action) {
        case 'hold_mark_paid':
            $connection->getTransactionManager()->begin();
            $performed = (new OrderPaymentService(new OrderRepository()))->markPaid(
                $context,
                (string) $args['tenant'],
                (string) $args['orderUuid']
            );
            usleep((int) $args['sleepMs'] * 1000);
            $connection->getTransactionManager()->commit();
            $out = ['ok' => true, 'performed' => $performed];
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
