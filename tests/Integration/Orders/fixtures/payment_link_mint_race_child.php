<?php

declare(strict_types=1);

/**
 * Standalone subprocess for PaymentLinkServicePgsqlTest's real-pgsql mint race
 * (payment-links Task 6, design spec §2.2 Ruling 7): runs one mint against a
 * genuinely separate OS process/database connection/session, so the ORDER row
 * lock `PaymentLinkService::mint()` takes really blocks the parent test
 * process's own connection. Mirrors `Orders/fixtures/payment_link_race_child.php`'s
 * shape exactly.
 *
 * argv: 1=pgConfig JSON, 2=action, 3=args JSON
 *
 * actions:
 *  - hold_order_then_mint: begins a transaction, takes the ORDER row `FOR
 *    UPDATE` itself, sleeps for `args.sleepMs` holding it, then mints (the
 *    service's own transaction nests as a savepoint inside this one) and
 *    commits. A concurrent mint on the same order from another connection
 *    therefore blocks on that order lock until this commit, and then observes
 *    the COMMITTED link -- which is exactly what makes "revoke prior, insert
 *    new" leave exactly one active link instead of two.
 *    stdout: {"ok":true,"linkUuid":string}.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkService;
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

$tenants = new class ((string) $args['tenant']) implements CurrentTenantResolver {
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
        case 'hold_order_then_mint':
            $service = new PaymentLinkService(new OrderRepository(), new PaymentLinkRepository(), $tenants);
            $connection->getTransactionManager()->begin();
            $connection->table('commerce_orders')->executeRawFirst(
                'SELECT * FROM commerce_orders WHERE tenant_uuid = ? AND uuid = ? FOR UPDATE',
                [(string) $args['tenant'], (string) $args['orderUuid']]
            );
            usleep((int) $args['sleepMs'] * 1000);
            $minted = $service->mint(
                $context,
                (string) $args['tenant'],
                (string) $args['orderUuid'],
                (int) $args['ttlDays'],
                (string) $args['actor'],
                new \DateTimeImmutable((string) $args['now'], new \DateTimeZone('UTC'))
            );
            $connection->getTransactionManager()->commit();
            // NOTE: the raw token is deliberately NOT echoed -- this subprocess
            // reports the link identity only, never the bearer secret.
            $out = ['ok' => true, 'linkUuid' => $minted['link']->linkUuid];
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
