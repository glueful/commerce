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
 * Two INDEPENDENT sweeps on one cron tick (admin-order-creation cycle 2, Task 8):
 *
 *  1. {@see ExpiryService::expireStale()} -- unpaid REAL orders: releases
 *     reserved stock and captures marketplace cancellations.
 *  2. {@see DraftCleanupService::cancelStale()} -- abandoned admin DRAFTS:
 *     audit rows only, no stock, no events, no webhooks.
 *
 * The draft sweep is BOUNDED per call (see `DraftCleanupService::DEFAULT_BATCH_SIZE`),
 * so a large backlog drains across successive cron ticks rather than turning any
 * single tick into unbounded work -- an intentional trade of latency for a hard
 * per-run ceiling. The clock is passed explicitly, in UTC, so the command is the
 * only place a real "now" enters the draft sweep.
 */
#[AsCommand(name: 'commerce:orders:expire', description: 'Expire stale pending-payment commerce orders')]
final class OrdersExpireCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();

        $expired = app($context, ExpiryService::class)->expireStale($context);
        $this->info("Expired {$expired} order(s).");

        $canceledDrafts = app($context, DraftCleanupService::class)->cancelStale(
            $context,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
        $this->info("Canceled {$canceledDrafts} stale draft order(s).");

        return self::SUCCESS;
    }
}
