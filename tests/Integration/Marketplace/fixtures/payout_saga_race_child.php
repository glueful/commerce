<?php

declare(strict_types=1);

/**
 * Standalone subprocess for `PayoutSagaPgsqlTest`'s real-pgsql payout-SAGA race
 * lanes (design spec §2.3-§2.8, MV4 Task 11): runs ONE real `PayoutService` call
 * in a genuinely separate OS process (and therefore a genuinely separate database
 * connection), so its account-lock/row claims really block on PostgreSQL
 * row-lock contention held by the parent test process's own connection A.
 * Mirrors `fixtures/settlement_race_child.php`'s shape exactly -- a single
 * multiplexed script, since every action here shares the identical bootstrap
 * and only differs in which `PayoutService` entry point it calls and how it
 * reports the outcome. `RaceFakePayoutCollector` below is a minimal
 * single-call-per-method fake scripted entirely from argv -- it never talks to
 * a real provider, mirroring every other fake `PayoutCollector` in this suite.
 *
 * argv: 1=pgConfig JSON, 2=action, 3=args JSON
 * actions: payoutExecute | executeBatch | retry | reconcile
 * stdout: JSON, shape depends on action (see each branch below)
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountService;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
use Glueful\Extensions\Commerce\Marketplace\PayoutOutcomeUnknownException;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Contracts\Payments\DestinationStatus;
use Glueful\Extensions\Contracts\Payments\PayoutCollector;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;
use Glueful\Extensions\Contracts\Payments\PayoutResult;
use Glueful\Extensions\Contracts\Payments\PayoutStatusResult;
use Psr\Container\ContainerInterface;

/**
 * Single-call-per-method fake collector, scripted entirely from the `transfer`/`status`
 * argv entries (each a plain associative array shaped like the matching contract VO's
 * constructor args, or omitted entirely to make an unscripted call fail loudly instead of
 * silently succeeding). Never talks to a real provider.
 */
final class RaceFakePayoutCollector implements PayoutCollector
{
    public int $transferCalls = 0;
    public int $statusCalls = 0;

    /**
     * @param array<string,mixed>|null $transfer
     * @param array<string,mixed>|null $status
     */
    public function __construct(private readonly ?array $transfer = null, private readonly ?array $status = null)
    {
    }

    public function transfer(
        ApplicationContext $context,
        PayoutDestination $destination,
        PayoutRequest $request
    ): PayoutResult {
        $this->transferCalls++;
        if ($this->transfer === null) {
            throw new \RuntimeException('RaceFakePayoutCollector: transfer() was not scripted for this call.');
        }

        return new PayoutResult(
            (string) $this->transfer['status'],
            $this->transfer['providerRef'] ?? null,
            $this->transfer['failureCode'] ?? null,
            $this->transfer['failureReason'] ?? null
        );
    }

    public function status(
        ApplicationContext $context,
        PayoutDestination $destination,
        string $idempotencyKey
    ): PayoutStatusResult {
        $this->statusCalls++;
        if ($this->status === null) {
            throw new \RuntimeException('RaceFakePayoutCollector: status() was not scripted for this call.');
        }

        return new PayoutStatusResult(
            (string) $this->status['status'],
            (int) ($this->status['reversedAmount'] ?? 0),
            $this->status['providerRef'] ?? null,
            $this->status['failureCode'] ?? null,
            $this->status['failureReason'] ?? null
        );
    }

    public function inspectDestination(
        ApplicationContext $context,
        PayoutDestination $destination
    ): DestinationStatus {
        throw new \LogicException('RaceFakePayoutCollector::inspectDestination() is not exercised by this fixture.');
    }
}

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
if (isset($args['defaultProvider'])) {
    $context->overrideConfig('commerce.marketplace.payouts.default_provider', (string) $args['defaultProvider']);
}

$tenant = (string) $args['tenant'];

$payoutsRepo = new PayoutRepository();
$ledger = new LedgerRepository();
$balances = new SellerBalanceService($ledger);
$payoutAccounts = new PayoutAccountService(new PayoutAccountRepository());

$out = [];

try {
    switch ($action) {
        case 'payoutExecute':
            $collector = new RaceFakePayoutCollector($args['transfer'] ?? null);
            $service = new PayoutService(
                $payoutsRepo,
                $ledger,
                new LedgerAccountLock(),
                $balances,
                null,
                $collector,
                $payoutAccounts
            );
            $payout = $service->execute(
                $context,
                $tenant,
                (string) $args['sellerUuid'],
                (string) $args['currency'],
                (int) $args['amount'],
                $args['actorUuid'] ?? null
            );
            $out = [
                'ok' => true,
                'exceptionClass' => null,
                'payoutUuid' => $payout['uuid'],
                'status' => $payout['status'],
                'amount' => (int) $payout['amount'],
                'transferCalls' => $collector->transferCalls,
            ];
            break;

        case 'executeBatch':
            $collector = new RaceFakePayoutCollector($args['transfer'] ?? null);
            $service = new PayoutService(
                $payoutsRepo,
                $ledger,
                new LedgerAccountLock(),
                $balances,
                null,
                $collector,
                $payoutAccounts
            );
            $payout = $service->executeBatch(
                $context,
                $tenant,
                (string) $args['sellerUuid'],
                (string) $args['currency'],
                $args['actorUuid'] ?? null
            );
            $out = [
                'ok' => true,
                'exceptionClass' => null,
                'payout' => $payout === null ? null : [
                    'uuid' => $payout['uuid'],
                    'amount' => (int) $payout['amount'],
                    'status' => $payout['status'],
                ],
                'transferCalls' => $collector->transferCalls,
            ];
            break;

        case 'retry':
            $collector = new RaceFakePayoutCollector($args['transfer'] ?? null);
            $service = new PayoutService(
                $payoutsRepo,
                $ledger,
                new LedgerAccountLock(),
                $balances,
                null,
                $collector,
                $payoutAccounts
            );
            $result = $service->retry(
                $context,
                $tenant,
                (string) $args['payoutUuid'],
                (bool) ($args['ignoreDueTime'] ?? false)
            );
            $out = [
                'ok' => true,
                'exceptionClass' => null,
                'result' => $result === null ? null : [
                    'status' => $result['status'],
                    'attempt_count' => (int) $result['attempt_count'],
                    'provider_ref' => $result['provider_ref'] ?? null,
                ],
                'transferCalls' => $collector->transferCalls,
            ];
            break;

        case 'reconcile':
            $collector = new RaceFakePayoutCollector(null, $args['status'] ?? null);
            $service = new PayoutService(
                $payoutsRepo,
                $ledger,
                new LedgerAccountLock(),
                $balances,
                null,
                $collector,
                $payoutAccounts
            );
            $row = $payoutsRepo->findByUuid($context, $tenant, (string) $args['payoutUuid']);
            if ($row === null) {
                throw new \RuntimeException('payout row not found: ' . (string) $args['payoutUuid']);
            }
            $reconciled = $service->reconcile($context, $tenant, $row);
            $out = [
                'ok' => true,
                'exceptionClass' => null,
                'status' => $reconciled['status'],
                'reversed_total' => (int) $reconciled['reversed_total'],
                'statusCalls' => $collector->statusCalls,
            ];
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (PayoutException | PayoutOutcomeUnknownException $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
