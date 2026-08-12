<?php

declare(strict_types=1);

/**
 * Standalone subprocess for PaymentLinkRepositoryPgsqlTest's real-pgsql race lane
 * (payment-links Task 5, design spec §2.2): runs one action against a genuinely
 * separate OS process/database connection/session, so its `FOR UPDATE` on the
 * payment-link row really blocks the parent test process's own connection.
 * Mirrors `Orders/fixtures/draft_attempt_race_child.php`'s shape.
 *
 * argv: 1=pgConfig JSON, 2=action, 3=args JSON
 *
 * actions:
 *  - hold_claim: begins a transaction, calls
 *    `PaymentLinkRepository::claimInitiationWindow()` (which takes the link row
 *    `FOR UPDATE`), sleeps for `args.sleepMs` milliseconds holding that lock and
 *    its uncommitted counter increment, then commits. A concurrent claim on the
 *    same link from another connection therefore blocks until this commit and
 *    then observes the COMMITTED counter -- never the stale pre-claim value.
 *    stdout: {"ok":true,"claimed":bool}.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
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
        case 'hold_claim':
            $connection->getTransactionManager()->begin();
            $claimed = (new PaymentLinkRepository())->claimInitiationWindow(
                $context,
                (string) $args['tenant'],
                (string) $args['linkUuid'],
                new \DateTimeImmutable((string) $args['now'], new \DateTimeZone('UTC')),
                (int) $args['limit']
            );
            usleep((int) $args['sleepMs'] * 1000);
            $connection->getTransactionManager()->commit();
            $out = ['ok' => true, 'claimed' => $claimed];
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
