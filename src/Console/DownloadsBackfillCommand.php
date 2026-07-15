<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Operator bulk repair (design spec §3, recovery surface 3 of 3): scans every
 * paid/fulfilled/refunded commerce order — optionally narrowed to one tenant — and
 * runs the SAME idempotent {@see DownloadGrantService::ensureGrantsForOrder()} the
 * `OrderPaid` mail listener and the lazy order-authenticated download endpoints use.
 * Safe to run repeatedly: already-issued grants are always reported `skipped`, never
 * recreated. `--dry-run` shares the exact same counting path
 * ({@see DownloadGrantService::previewForOrder()}) as a real run, so the numbers can
 * never drift between a preview and the write it describes.
 */
#[AsCommand(
    name: 'commerce:downloads:backfill',
    description: 'Repair missing digital-download grants for paid/fulfilled/refunded commerce orders'
)]
final class DownloadsBackfillCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limit to a single tenant uuid');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report counts without writing any grants');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();
        $tenantOption = $input->getOption('tenant');
        $dryRun = (bool) $input->getOption('dry-run');

        $query = db($context)->table('commerce_orders')
            ->whereIn('status', ['paid', 'fulfilled', 'refunded'])
            ->orderBy('id', 'ASC');
        if (is_string($tenantOption) && trim($tenantOption) !== '') {
            $query->where('tenant_uuid', '=', trim($tenantOption));
        }
        $orders = $query->get();

        if ($orders === []) {
            $this->info('No qualifying orders found.');

            return self::SUCCESS;
        }

        $service = app($context, DownloadGrantService::class);
        $rows = [];
        $totalCreated = 0;
        $totalSkipped = 0;

        foreach ($orders as $order) {
            $preview = $service->previewForOrder($context, $order);
            $needed = $preview['needed'];
            $existing = $preview['existing'];

            if (!$dryRun && $needed > 0) {
                $service->ensureGrantsForOrder($context, $order);
            }

            $totalCreated += $needed;
            $totalSkipped += $existing;

            if ($needed > 0 || $existing > 0) {
                $rows[] = [(string) $order['order_number'], (string) $needed, (string) $existing];
            }
        }

        if ($rows !== []) {
            $this->table(['Order', $dryRun ? 'Would create' : 'Created', 'Skipped'], $rows);
        }

        $mode = $dryRun ? 'Dry run' : 'Backfill';
        $this->info(
            "{$mode} complete: {$totalCreated} created, {$totalSkipped} skipped, "
            . count($orders) . ' order(s) scanned.'
        );

        return self::SUCCESS;
    }
}
