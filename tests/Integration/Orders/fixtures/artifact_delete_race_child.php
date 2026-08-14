<?php

declare(strict_types=1);

/**
 * Standalone subprocess for `ArtifactDeleteRacePgsqlTest`'s real-pgsql
 * delete-guard race lane (cleanup-train Task 5). PHP has no threads, so a
 * genuine "the row stopped being an artifact while the request was running"
 * interleaving needs a truly separate OS process, connection and session.
 *
 * Both actions take the ORDER ROW's lock, hold it open while the parent's own
 * guarded delete blocks on it, and only then commit -- so the parent's
 * compare-and-set re-evaluates its `order_number IS NULL AND status='canceled'`
 * predicate against freshly committed data and matches ZERO rows,
 * deterministically. Mirrors `fixtures/paid_cas_race_child.php`'s shape exactly.
 *
 * argv: 1=pgConfig JSON, 2=action, 3=args JSON
 *
 * actions:
 *  - hold_delete: deletes the artifact (and its children) through the REAL
 *    {@see \Glueful\Extensions\Commerce\Orders\DraftCleanupService::deleteArtifact()},
 *    sleeps `args.sleepMs` holding the lock, then commits.
 *    stdout: {"ok":true,"deleted":true}.
 *  - hold_renumber: stamps the artifact with an order number and a live status
 *    -- i.e. plays the part of ANY writer that makes the row stop being an
 *    artifact -- sleeps holding the lock, then commits.
 *    stdout: {"ok":true,"affected":1}.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\DraftCleanupService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
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
$resolver = new class ($tenant) implements CurrentTenantResolver {
    public function __construct(private string $tenant)
    {
    }

    public function tenantUuid(ApplicationContext $context): string
    {
        return $this->tenant;
    }
};

$out = [];

try {
    switch ($action) {
        case 'hold_delete':
            $connection->getTransactionManager()->begin();
            $deleted = (new DraftCleanupService(new OrderRepository(), $resolver))->deleteArtifact(
                $context,
                $tenant,
                (string) $args['orderUuid'],
                'raceoperatr1'
            );
            usleep((int) $args['sleepMs'] * 1000);
            $connection->getTransactionManager()->commit();
            $out = ['ok' => true, 'deleted' => $deleted];
            break;

        case 'hold_renumber':
            $connection->getTransactionManager()->begin();
            $affected = $connection->table('commerce_orders')->executeModification(
                'UPDATE commerce_orders SET order_number = ?, status = ? WHERE tenant_uuid = ? AND uuid = ?',
                [
                    (string) $args['orderNumber'],
                    'pending_payment',
                    $tenant,
                    (string) $args['orderUuid'],
                ]
            );
            usleep((int) $args['sleepMs'] * 1000);
            $connection->getTransactionManager()->commit();
            $out = ['ok' => true, 'affected' => $affected];
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
