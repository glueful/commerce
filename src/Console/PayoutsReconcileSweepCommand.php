<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provider reconcile sweep (design spec §2.6, MV4 Task 9): selects every due unresolved
 * `pending` payout (`next_reconcile_at IS NULL` is treated as an immediate repair
 * backstop) PLUS every due `paid` payout (the slower reversal-discovery cadence, §2.8)
 * via {@see PayoutRepository::dueForReconcile()}, and calls
 * {@see PayoutService::reconcile()} independently per row. Deliberately never selects
 * `failed` (a `failed+retryable` row carries its OWN `next_reconcile_at` watchdog, but
 * that row belongs to the RETRY sweep -- {@see PayoutsRetrySweepCommand} -- never this
 * one) or `reversed` (nothing left to reconcile). `reconcile()` itself never throws for
 * an ordinary provider-side failure (a `status()` throw just re-arms the watchdog
 * internally); this command's per-candidate try/catch is defense in depth against a
 * structural failure (e.g. a row disappearing mid-sweep), so one candidate never aborts
 * the sweep. Mirrors {@see SyncPayoutAccountsCommand}'s `--tenant`-optional discovery
 * idiom.
 */
#[AsCommand(
    name: 'commerce:marketplace:payouts:reconcile-sweep',
    description: 'Reconcile every due pending/paid payout against the provider'
)]
final class PayoutsReconcileSweepCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limit to a single tenant uuid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();
        $payouts = app($context, PayoutRepository::class);
        $service = app($context, PayoutService::class);

        $tenantOption = $this->stringOption($input, 'tenant');
        $tenants = $tenantOption !== null ? [$tenantOption] : $this->discoverTenants($context);

        if ($tenants === []) {
            $this->info('No payouts due for reconciliation; nothing to do.');

            return self::SUCCESS;
        }

        $reconciled = 0;
        $failures = 0;

        foreach ($tenants as $tenant) {
            $candidates = $payouts->dueForReconcile($context, $tenant);
            $label = $tenant === '' ? '(sentinel)' : $tenant;

            foreach ($candidates as $candidate) {
                $uuid = (string) $candidate['uuid'];

                try {
                    $result = $service->reconcile($context, $tenant, $candidate);
                    $reconciled++;
                    $this->line(sprintf(
                        'Tenant %s payout %s: reconciled -> %s',
                        $label,
                        $uuid,
                        (string) $result['status']
                    ));
                } catch (\Throwable $e) {
                    $failures++;
                    $this->error(sprintf(
                        'Tenant %s payout %s: reconcile failed: %s',
                        $label,
                        $uuid,
                        $e->getMessage()
                    ));
                }
            }
        }

        $this->line(sprintf('Reconcile sweep: %d reconciled, %d failed.', $reconciled, $failures));

        if ($failures > 0) {
            $this->error("Reconcile sweep completed with {$failures} failure(s).");

            return self::FAILURE;
        }

        $this->success('Reconcile sweep complete.');

        return self::SUCCESS;
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /** @return list<string> every distinct tenant_uuid with any commerce_payouts row */
    private function discoverTenants(ApplicationContext $context): array
    {
        $tenants = [];
        $rows = db($context)->table('commerce_payouts')->select(['tenant_uuid'])->distinct()->get();
        foreach ($rows as $row) {
            $tenants[(string) $row['tenant_uuid']] = true;
        }

        $list = array_keys($tenants);
        sort($list);

        return $list;
    }
}
