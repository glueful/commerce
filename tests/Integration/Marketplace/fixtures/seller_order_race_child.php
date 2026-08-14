<?php

declare(strict_types=1);

/**
 * Standalone subprocess for FulfillmentPgsqlTest's real-pgsql race lanes
 * (design spec §2.8/§2.12, MV2 plan Task 10): runs ONE real
 * `SellerOrderFulfillmentService`/`OrderPaymentService` call in a genuinely
 * separate OS process (and therefore a genuinely separate database
 * connection), so its claim/CAS really blocks on PostgreSQL row-lock
 * contention held by the parent test process's own connection A. Mirrors
 * `fixtures/marketplace_race_child.php`'s shape exactly -- a single
 * multiplexed script because both actions share the identical bootstrap and
 * only differ in which service method they call.
 *
 * argv: 1=pgConfig JSON, 2=action, 3=args JSON
 * actions: fulfillChild | markPaid
 * stdout: JSON, shape depends on action (see each branch below)
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Extensions\Commerce\Events\SellerOrderFulfilled;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
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
    /** @var array<string,mixed> */
    public array $bindings = [];

    public function __construct(private Connection $connection)
    {
    }

    public function get(string $id): mixed
    {
        if ($id === 'database' || $id === Connection::class) {
            return $this->connection;
        }
        if (array_key_exists($id, $this->bindings)) {
            return $this->bindings[$id];
        }

        throw new \RuntimeException("Unknown service: {$id}");
    }

    public function has(string $id): bool
    {
        return $id === 'database' || $id === Connection::class || array_key_exists($id, $this->bindings);
    }
};

$context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
$context->setContainer($container);
$context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../../config/commerce.php');
$context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);

$tenant = (string) $args['tenant'];

$out = [];

try {
    switch ($action) {
        case 'fulfillChild':
            // Bound event capture (this process's own EventService) so the
            // parent test can observe -- via this subprocess's own stdout --
            // exactly how many SellerOrderFulfilled/OrderFulfilled events its
            // OWN real service call dispatched, proving no double-fire even
            // under genuine two-connection claim contention.
            $counts = ['sellerOrderFulfilled' => 0, 'orderFulfilled' => 0];
            $listeners = new ListenerProvider();
            $eventService = new EventService(new EventDispatcher($listeners), $listeners);
            $eventService->addListener(
                SellerOrderFulfilled::class,
                static function () use (&$counts): void {
                    $counts['sellerOrderFulfilled']++;
                }
            );
            $eventService->addListener(
                OrderFulfilled::class,
                static function () use (&$counts): void {
                    $counts['orderFulfilled']++;
                }
            );
            $container->bindings[EventService::class] = $eventService;

            $service = new SellerOrderFulfillmentService(new OrderRepository(), new SellerOrderRepository());
            $child = $service->fulfill(
                $context,
                $tenant,
                (string) $args['orderUuid'],
                (string) $args['sellerOrderUuid'],
                ['carrier' => 'RACE-CARRIER', 'tracking_number' => 'RACE-TRACK-1', 'tracking_url' => null],
                $args['actorSellerUuid'] ?? null
            );
            $out = [
                'ok' => true,
                'status' => $child['fulfillment_status'],
                'sellerOrderFulfilledCount' => $counts['sellerOrderFulfilled'],
                'orderFulfilledCount' => $counts['orderFulfilled'],
                'exceptionClass' => null,
            ];
            break;

        case 'markPaid':
            $service = new OrderPaymentService(new OrderRepository(), new SellerOrderPaymentConfirmation());
            // `performed` distinguishes "I ran the paid CAS" from "the order was
            // already paid by a concurrent settler, so I conceded idempotently"
            // (cleanup-train Task 4) -- the whole point of the CAS-loser lane.
            $performed = $service->markPaid($context, $tenant, (string) $args['orderUuid']);
            $out = ['ok' => true, 'performed' => $performed, 'exceptionClass' => null];
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
