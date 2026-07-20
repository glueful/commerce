<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Commerce\Marketplace\PayoutOutcomeUnknownException;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Bounded-retry sweep (design spec §2.6, MV4 Task 9): selects every
 * `status=failed AND retryable=true AND next_attempt_at <= now AND attempt_count <
 * max_attempts` payout ({@see PayoutRepository::dueForRetry()}) and, independently per
 * candidate, calls {@see PayoutService::retry()} -- which itself CASes the row out of
 * `failed` via {@see PayoutRepository::claimRetryableForAttempt()} (incrementing
 * `attempt_count`, stamping the watchdog, BEFORE any provider I/O) and executes the newly
 * claimed attempt. A crash between the claim and finalize is recovered by the reconcile
 * sweep via that SAME watchdog, never a second blind retry here. `retry()` returning null
 * (lost the CAS race to a concurrent sweep, or no longer due) is a legitimate no-op;
 * {@see \Glueful\Extensions\Commerce\Marketplace\PayoutOutcomeUnknownException} (the new
 * attempt itself came back ambiguous) is likewise an EXPECTED outcome -- the reconcile
 * sweep resolves it later -- and is reported separately from genuine failures. One
 * candidate's exception never aborts the sweep. Mirrors
 * {@see SyncPayoutAccountsCommand}'s `--tenant`-optional discovery idiom.
 */
#[AsCommand(
    name: 'commerce:marketplace:payouts:retry-sweep',
    description: 'Claim and execute the next attempt for every due, retryable failed payout'
)]
final class PayoutsRetrySweepCommand extends BaseCommand
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
        $maxAttempts = (int) config($context, 'commerce.marketplace.payouts.max_attempts', 5);

        $tenantOption = $this->stringOption($input, 'tenant');
        $tenants = $tenantOption !== null ? [$tenantOption] : $this->discoverTenants($context);

        if ($tenants === []) {
            $this->info('No retryable payouts found; nothing to retry.');

            return self::SUCCESS;
        }

        $retried = 0;
        $skipped = 0;
        $unresolved = 0;
        $failures = 0;

        foreach ($tenants as $tenant) {
            $candidates = $payouts->dueForRetry($context, $tenant, $maxAttempts);

            foreach ($candidates as $candidate) {
                $uuid = (string) $candidate['uuid'];
                $label = $tenant === '' ? '(sentinel)' : $tenant;

                try {
                    $result = $service->retry($context, $tenant, $uuid);
                    if ($result === null) {
                        $skipped++;
                        continue;
                    }

                    $retried++;
                    $this->line(sprintf(
                        'Tenant %s payout %s: retried -> %s',
                        $label,
                        $uuid,
                        (string) $result['status']
                    ));
                } catch (PayoutOutcomeUnknownException $e) {
                    // An expected, non-fatal outcome (design spec §2.3): the new attempt's
                    // result is ambiguous. The hold stays and the reconcile sweep resolves
                    // it -- never counted as a sweep failure.
                    $unresolved++;
                    $this->line(sprintf(
                        'Tenant %s payout %s: outcome unknown, awaiting reconcile (%s)',
                        $label,
                        $uuid,
                        $e->getMessage()
                    ));
                } catch (\Throwable $e) {
                    $failures++;
                    $this->error(sprintf('Tenant %s payout %s: retry failed: %s', $label, $uuid, $e->getMessage()));
                }
            }
        }

        $this->line(sprintf(
            'Retry sweep: %d retried, %d skipped, %d unresolved (awaiting reconcile), %d failed.',
            $retried,
            $skipped,
            $unresolved,
            $failures
        ));

        if ($failures > 0) {
            $this->error("Retry sweep completed with {$failures} failure(s).");

            return self::FAILURE;
        }

        $this->success('Retry sweep complete.');

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
