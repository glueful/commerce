<?php

declare(strict_types=1);

/**
 * Standalone subprocess for `ReserveChargebackPgsqlTest`'s real-pgsql
 * risk-reserve/chargeback race lanes (design spec §2.3/§2.5/§2.6/§2.7, MV5a
 * Task 17 GATES): runs ONE real Marketplace entry point in a genuinely
 * separate OS process (and therefore a genuinely separate database
 * connection), so its account-lock claims really block on PostgreSQL
 * row-lock contention held by the parent test process's own connection A.
 * Mirrors `fixtures/payout_saga_race_child.php`/`fixtures/settlement_race_child.php`'s
 * shape exactly -- a single multiplexed script, since both actions share the
 * identical bootstrap and only differ in which entry point they call and how
 * they report the outcome.
 *
 * argv: 1=pgConfig JSON, 2=action, 3=args JSON
 * actions: payoutRecord | releaseDue
 * stdout: JSON, shape depends on action (see each branch below)
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyEventRepository;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyService;
use Glueful\Extensions\Commerce\Marketplace\ReserveRepository;
use Glueful\Extensions\Commerce\Marketplace\ReserveService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
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
$context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);

$tenant = (string) $args['tenant'];

$out = [];

try {
    switch ($action) {
        // A REAL PayoutService::record() attempt -- the manual operator payout
        // path, which shares the EXACT same claim-lock -> recheck-balance-under-
        // the-lock -> debt-gate -> capacity-check sequence as the provider saga's
        // reserve() step (design spec §2.6/§2.7), without needing a bound
        // PayoutCollector/PayoutAccountService for this race.
        case 'payoutRecord':
            $ledger = new LedgerRepository();
            $payoutService = new PayoutService(
                new PayoutRepository(),
                $ledger,
                new LedgerAccountLock(),
                new SellerBalanceService($ledger)
            );
            $payout = $payoutService->record(
                $context,
                $tenant,
                (string) $args['sellerUuid'],
                (string) $args['currency'],
                (int) $args['amount'],
                (string) $args['idempotencyKey'],
                (string) $args['externalRef'],
                null,
                (string) $args['actorUuid']
            );
            $out = [
                'ok' => true,
                'exceptionClass' => null,
                'payoutUuid' => $payout['uuid'],
                'amount' => (int) $payout['amount'],
            ];
            break;

        // A REAL ReserveService::releaseDue() call for one specific reserve row
        // (the `commerce:marketplace:reserves:release-sweep` CLI's own per-row
        // entry point, design spec §2.3) -- `$reserve` here is deliberately the
        // SAME minimal unlocked hint shape `ReserveRepository::dueForRelease()`
        // returns (uuid/seller_uuid/currency only); releaseDue() re-reads
        // everything else fresh under its own claimed account lock.
        case 'releaseDue':
            $reserveService = new ReserveService(
                new ReservePolicyService(
                    new SellerRepository(),
                    new MarketplaceWorkspaceLock(),
                    new ReservePolicyEventRepository()
                ),
                new ReserveRepository(),
                new LedgerRepository()
            );
            $result = $reserveService->releaseDue($context, $tenant, [
                'uuid' => (string) $args['reserveUuid'],
                'seller_uuid' => (string) $args['sellerUuid'],
                'currency' => (string) $args['currency'],
            ]);
            $out = [
                'ok' => true,
                'exceptionClass' => null,
                'status' => $result['status'],
                'releasedAmount' => (int) $result['released_amount'],
            ];
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (PayoutException $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
