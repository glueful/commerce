<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Commerce\Orders\DraftCleanupService;
use Glueful\Extensions\Commerce\Orders\ExpiryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Three INDEPENDENT sweeps on one cron tick (admin-order-creation cycle 2, Task 8;
 * cleanup-train Task 5):
 *
 *  1. {@see ExpiryService::expireStale()} -- unpaid REAL orders: releases
 *     reserved stock and captures marketplace cancellations.
 *  2. {@see DraftCleanupService::cancelStale()} -- abandoned admin DRAFTS:
 *     audit rows only, no stock, no events, no webhooks.
 *  3. {@see DraftCleanupService::purgeStale()} -- aged draft ARTIFACTS (canceled,
 *     never-numbered rows): a guarded HARD DELETE of the row and its children.
 *
 * The third sweep lives here rather than in a command of its own precisely so
 * that hosts get purging with NO new cron obligation: an install already running
 * `commerce:orders:expire` starts disposing of artifacts on upgrade without
 * touching its scheduler. Ordering is deliberate -- cancellation runs BEFORE
 * purging, and a draft the second sweep just canceled has its `updated_at`
 * stamped to now, so it is never destroyed on the tick that canceled it and
 * always gets the full `draft_purge_days` window.
 *
 * The sweeps share a schedule and NOTHING else, so they are ISOLATED: each runs
 * in its own try/catch and all three always report. A stock/marketplace failure that
 * makes the order sweep throw must not silently suspend draft cleanup for as long
 * as that unrelated outage lasts -- drafts would then accumulate unbounded. A failing
 * sweep surfaces as an error line plus a FAILURE exit code (so cron alerting still
 * fires), never as a silently skipped sibling.
 *
 * Both draft sweeps are BOUNDED per call (see `DraftCleanupService::DEFAULT_BATCH_SIZE`),
 * so a large backlog drains across successive cron ticks rather than turning any
 * single tick into unbounded work -- an intentional trade of latency for a hard
 * per-run ceiling. The clock is passed explicitly, in UTC, so this command is the
 * only place a real "now" enters either of them.
 */
#[AsCommand(name: 'commerce:orders:expire', description: 'Expire stale pending-payment commerce orders')]
final class OrdersExpireCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();

        $expiredOk = $this->sweep(
            'Expire stale orders',
            static fn (): string => sprintf(
                'Expired %d order(s).',
                app($context, ExpiryService::class)->expireStale($context)
            )
        );

        $draftsOk = $this->sweep(
            'Cancel stale drafts',
            static fn (): string => sprintf(
                'Canceled %d stale draft order(s).',
                app($context, DraftCleanupService::class)->cancelStale(
                    $context,
                    new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
                )
            )
        );

        $purgedOk = $this->sweep(
            'Purge draft artifacts',
            static fn (): string => sprintf(
                'Purged %d draft artifact(s).',
                app($context, DraftCleanupService::class)->purgeStale(
                    $context,
                    new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
                )
            )
        );

        return $expiredOk && $draftsOk && $purgedOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Runs ONE independent sweep. `$run` returns the line to report on success;
     * anything it throws is reported and swallowed here so the NEXT sweep still
     * runs. Both calls are made unconditionally -- never short-circuited by `&&`.
     *
     * @param \Closure(): string $run
     */
    private function sweep(string $label, \Closure $run): bool
    {
        try {
            $this->info($run());

            return true;
        } catch (\Throwable $e) {
            $this->error("{$label} failed: " . $e->getMessage());

            return false;
        }
    }
}
