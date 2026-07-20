<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Commerce\Marketplace\ReserveRepository;
use Glueful\Extensions\Commerce\Marketplace\ReserveService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scheduled reserve-release sweep (design spec §2.3, MV5a Task 8): selects every
 * `commerce_seller_reserves` row that is `status=held AND release_at IS NOT NULL AND
 * release_at <= now` via {@see ReserveRepository::dueForRelease()} -- batch-limited per
 * tenant by `commerce.marketplace.reserves.release_sweep_batch_size` -- and, independently
 * per row, calls {@see ReserveService::releaseDue()}. Mirrors the MV4 sweep-command idiom
 * ({@see PayoutsReconcileSweepCommand}, {@see PayoutsRetrySweepCommand}): one candidate's
 * exception is caught and reported WITHOUT aborting the rest of the sweep. A manual/
 * indefinite hold (`release_at IS NULL`, design spec §2.8) is never selected by the
 * repository query in the first place, so this command applies no additional filtering of
 * its own to exclude it. Host-cron-invoked -- Commerce owns no scheduler of its own, same
 * as every other MV4/MV5a sweep. Mirrors {@see SyncPayoutAccountsCommand}'s
 * `--tenant`-optional discovery idiom.
 */
#[AsCommand(
    name: 'commerce:marketplace:reserves:release-sweep',
    description: 'Release every due seller reserve hold whose release_at has passed'
)]
final class ReservesReleaseSweepCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limit to a single tenant uuid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();
        $reserves = app($context, ReserveRepository::class);
        $service = app($context, ReserveService::class);
        $batchSize = max(1, (int) config($context, 'commerce.marketplace.reserves.release_sweep_batch_size', 100));

        $tenantOption = $this->stringOption($input, 'tenant');
        $tenants = $tenantOption !== null ? [$tenantOption] : $this->discoverTenants($context);

        if ($tenants === []) {
            $this->info('No reserves due for release; nothing to do.');

            return self::SUCCESS;
        }

        $released = 0;
        $skipped = 0;
        $failures = 0;

        foreach ($tenants as $tenant) {
            $candidates = $reserves->dueForRelease($context, $tenant, $batchSize);
            $label = $tenant === '' ? '(sentinel)' : $tenant;

            foreach ($candidates as $candidate) {
                $uuid = (string) $candidate['uuid'];

                try {
                    $result = $service->releaseDue($context, $tenant, $candidate);
                    if ((string) $result['status'] !== 'released') {
                        $skipped++;
                        continue;
                    }

                    $released++;
                    $this->line(sprintf(
                        'Tenant %s reserve %s: released %d',
                        $label,
                        $uuid,
                        (int) $result['released_amount']
                    ));
                } catch (\Throwable $e) {
                    $failures++;
                    $this->error(sprintf(
                        'Tenant %s reserve %s: release failed: %s',
                        $label,
                        $uuid,
                        $e->getMessage()
                    ));
                }
            }
        }

        $this->line(sprintf(
            'Release sweep: %d released, %d skipped (already closed), %d failed.',
            $released,
            $skipped,
            $failures
        ));

        if ($failures > 0) {
            $this->error("Release sweep completed with {$failures} failure(s).");

            return self::FAILURE;
        }

        $this->success('Release sweep complete.');

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

    /** @return list<string> every distinct tenant_uuid with any commerce_seller_reserves row */
    private function discoverTenants(ApplicationContext $context): array
    {
        $tenants = [];
        $rows = db($context)->table('commerce_seller_reserves')->select(['tenant_uuid'])->distinct()->get();
        foreach ($rows as $row) {
            $tenants[(string) $row['tenant_uuid']] = true;
        }

        $list = array_keys($tenants);
        sort($list);

        return $list;
    }
}
