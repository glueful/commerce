<?php

declare(strict_types=1);

/**
 * Standalone subprocess for `SettlementPgsqlTest`'s real-pgsql settlement
 * race lanes (design spec §2.5-§2.10, MV3 plan Task 12): runs ONE real
 * settlement-ledger call in a genuinely separate OS process (and therefore a
 * genuinely separate database connection), so its account-lock claims really
 * block on PostgreSQL row-lock contention held by the parent test process's
 * own connection A. Mirrors `fixtures/marketplace_race_child.php`'s shape
 * exactly; a single multiplexed script (rather than one file per action)
 * because every action here shares the identical bootstrap and only differs
 * in which settlement primitive it calls and how it reports the outcome.
 *
 * argv: 1=pgConfig JSON, 2=action, 3=args JSON
 * actions: markPaid | payout | postRefund | adjustment | ledgerLockClaim
 * stdout: JSON, shape depends on action (see each branch below)
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentException;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentService;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerException;
use Glueful\Extensions\Commerce\Marketplace\LedgerPostingService;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
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
$context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);

$tenant = (string) $args['tenant'];

$out = [];

try {
    switch ($action) {
        case 'markPaid':
            $paymentService = new OrderPaymentService(
                new OrderRepository(),
                new SellerOrderPaymentConfirmation(),
                null,
                new SellerOrderRepository(),
                new LedgerPostingService(new LedgerRepository(), new LedgerAccountLock())
            );
            // `performed` distinguishes "I ran the paid CAS" from "the order was
            // already paid by a concurrent settler, so I conceded idempotently"
            // (cleanup-train Task 4) -- the whole point of the CAS-loser lanes.
            $performed = $paymentService->markPaid($context, $tenant, (string) $args['orderUuid']);
            $out = ['ok' => true, 'performed' => $performed, 'exceptionClass' => null];
            break;

        case 'payout':
            $payoutService = new PayoutService(
                new PayoutRepository(),
                new LedgerRepository(),
                new LedgerAccountLock(),
                new SellerBalanceService(new LedgerRepository()),
                new SellerRepository()
            );
            $payout = $payoutService->record(
                $context,
                $tenant,
                (string) $args['sellerUuid'],
                (string) $args['currency'],
                (int) $args['amount'],
                (string) $args['idempotencyKey'],
                (string) $args['externalRef'],
                $args['note'] ?? null,
                (string) $args['actorUuid']
            );
            $out = [
                'ok' => true,
                'payoutUuid' => $payout['uuid'],
                'amount' => (int) $payout['amount'],
                'exceptionClass' => null,
            ];
            break;

        case 'postRefund':
            $ledgerPosting = new LedgerPostingService(new LedgerRepository(), new LedgerAccountLock());
            $ledgerPosting->postRefund(
                $context,
                $tenant,
                ['uuid' => (string) $args['orderUuid']],
                [
                    'uuid' => (string) $args['refundUuid'],
                    'amount' => (int) $args['amount'],
                    'currency' => (string) $args['currency'],
                ],
                $args['lines']
            );
            $out = ['ok' => true, 'exceptionClass' => null];
            break;

        case 'adjustment':
            $adjustmentService = new AdjustmentService(new LedgerRepository(), new LedgerAccountLock());
            $adjustmentService->post(
                $context,
                $tenant,
                (string) $args['accountKey'],
                (string) $args['currency'],
                (int) $args['signedAmount'],
                (string) $args['reason'],
                (string) $args['idempotencyKey'],
                (string) $args['actorUuid']
            );
            $out = ['ok' => true, 'exceptionClass' => null];
            break;

        case 'ledgerLockClaim':
            $lock = new LedgerAccountLock();
            $lock->claim($context, $tenant, (string) $args['accountKey'], (string) $args['currency']);
            $row = $connection->table('commerce_ledger_account_locks')
                ->where('tenant_uuid', '=', $tenant)
                ->where('account_key', '=', (string) $args['accountKey'])
                ->where('currency', '=', (string) $args['currency'])
                ->first();
            $out = ['ok' => true, 'revision' => (int) ($row['revision'] ?? -1), 'exceptionClass' => null];
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (PayoutException | AdjustmentException | LedgerException $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
