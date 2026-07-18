<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutOutcomeUnknownException;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scheduled batch payout (design spec §2.6, MV4 Task 9): enumerates `(seller, currency)`
 * candidates via {@see LedgerRepository::positiveAvailableCandidates()} -- an UNLOCKED
 * scan that is a candidate HINT ONLY, never itself the posted amount -- then, independently
 * per candidate, calls {@see PayoutService::executeBatch()}. That call re-derives the real
 * amount from the account's `available` balance UNDER the per-account ledger lock (the
 * SAME shared reserve step {@see \Glueful\Extensions\Commerce\Marketplace\PayoutService::execute()}
 * uses), so two overlapping runs of this command serialize on that lock rather than
 * double-spending a stale hint. A `null` return is a legitimate skip (the locked amount
 * was non-positive or below the configured per-currency minimum);
 * {@see PayoutOutcomeUnknownException} is an expected, non-fatal outcome (the reconcile
 * sweep resolves it); any other candidate failure (e.g. no ready payout destination, a
 * provider throw) is caught and reported WITHOUT aborting the rest of the batch. This is a
 * CLI-only surface (design spec §2.6/§6.1: no HTTP batch action) -- host cron invokes it;
 * Commerce owns no scheduler of its own.
 */
#[AsCommand(
    name: 'commerce:marketplace:payouts:run-batch',
    description: 'Run a scheduled payout batch across every eligible seller/currency'
)]
final class PayoutsRunBatchCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limit to a single tenant uuid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();
        $ledger = app($context, LedgerRepository::class);
        $service = app($context, PayoutService::class);

        $tenantOption = $this->stringOption($input, 'tenant');
        $tenants = $tenantOption !== null ? [$tenantOption] : $this->discoverTenants($context);

        if ($tenants === []) {
            $this->info('No seller accounts with a positive available balance found; nothing to pay out.');

            return self::SUCCESS;
        }

        $processed = 0;
        $skipped = 0;
        $unresolved = 0;
        $failures = 0;

        foreach ($tenants as $tenant) {
            $candidates = $ledger->positiveAvailableCandidates($context, $tenant);
            $label = $tenant === '' ? '(sentinel)' : $tenant;

            foreach ($candidates as $candidate) {
                $sellerUuid = $candidate['seller_uuid'];
                $currency = $candidate['currency'];

                try {
                    $payout = $service->executeBatch($context, $tenant, $sellerUuid, $currency, null);
                    if ($payout === null) {
                        $skipped++;
                        continue;
                    }

                    $processed++;
                    $this->line(sprintf(
                        'Tenant %s seller %s (%s): paid out %d -> %s',
                        $label,
                        $sellerUuid,
                        $currency,
                        (int) $payout['amount'],
                        (string) $payout['status']
                    ));
                } catch (PayoutOutcomeUnknownException $e) {
                    $unresolved++;
                    $this->line(sprintf(
                        'Tenant %s seller %s (%s): outcome unknown, awaiting reconcile (%s)',
                        $label,
                        $sellerUuid,
                        $currency,
                        $e->getMessage()
                    ));
                } catch (\Throwable $e) {
                    $failures++;
                    $this->error(sprintf(
                        'Tenant %s seller %s (%s): batch payout failed: %s',
                        $label,
                        $sellerUuid,
                        $currency,
                        $e->getMessage()
                    ));
                }
            }
        }

        $this->line(sprintf(
            'Batch: %d paid out, %d skipped (below minimum), %d unresolved (awaiting reconcile), %d failed.',
            $processed,
            $skipped,
            $unresolved,
            $failures
        ));

        if ($failures > 0) {
            $this->error("Payout batch completed with {$failures} failure(s).");

            return self::FAILURE;
        }

        $this->success('Payout batch complete.');

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

    /** @return list<string> every distinct tenant_uuid with any seller ledger activity */
    private function discoverTenants(ApplicationContext $context): array
    {
        $tenants = [];
        $rows = db($context)->table('commerce_marketplace_ledger')
            ->select(['tenant_uuid'])
            ->where('account_kind', '=', 'seller')
            ->distinct()
            ->get();
        foreach ($rows as $row) {
            $tenants[(string) $row['tenant_uuid']] = true;
        }

        $list = array_keys($tenants);
        sort($list);

        return $list;
    }
}
