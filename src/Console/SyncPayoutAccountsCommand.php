<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Payout-destination readiness sweep (design spec §2.7, MV4 Task 8): calls
 * {@see PayoutAccountService::sync()} (`PayoutCollector::inspectDestination()`,
 * strictly outside any transaction) for every
 * {@see PayoutAccountRepository::duePendingOrRestricted()} candidate --
 * accounts NOT currently `ready` (`pending`, never yet synced ready or just
 * reset by an attach; or `restricted`, which a provider may later lift).
 * Mirrors {@see \Glueful\Extensions\Commerce\Console\MarketplaceReconcileCommand}'s
 * `--tenant`-optional discovery idiom: omitted, every tenant with a `commerce_seller_payout_accounts`
 * row is discovered and swept in turn. `--seller`/`--provider` further
 * narrow candidates within the scanned tenant(s) -- an operator forcing a
 * targeted resync. Host cron invokes this on a schedule (no commerce-owned
 * scheduler); one seller/provider failure never aborts the sweep.
 */
#[AsCommand(
    name: 'commerce:marketplace:payout-accounts:sync',
    description: 'Sync provider-sourced payout-destination readiness for seller payout accounts'
)]
final class SyncPayoutAccountsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limit to a single tenant uuid');
        $this->addOption('seller', null, InputOption::VALUE_REQUIRED, 'Limit to a single seller uuid');
        $this->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Limit to a single provider');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();
        $service = app($context, PayoutAccountService::class);
        $accounts = app($context, PayoutAccountRepository::class);

        $tenantOption = $this->stringOption($input, 'tenant');
        $sellerOption = $this->stringOption($input, 'seller');
        $providerOption = $this->stringOption($input, 'provider');

        $tenants = $tenantOption !== null ? [$tenantOption] : $this->discoverTenants($context);

        if ($tenants === []) {
            $this->info('No payout accounts found; nothing to sync.');

            return self::SUCCESS;
        }

        $synced = 0;
        $failures = 0;

        foreach ($tenants as $tenant) {
            $candidates = $accounts->duePendingOrRestricted($context, $tenant, $sellerOption, $providerOption);

            foreach ($candidates as $candidate) {
                $sellerUuid = (string) $candidate['seller_uuid'];
                $provider = (string) $candidate['provider'];

                try {
                    $result = $service->sync($context, $tenant, $sellerUuid, $provider);
                    $synced++;
                    $this->line(sprintf(
                        'Synced tenant %s seller %s provider %s -> %s',
                        $tenant === '' ? '(sentinel)' : $tenant,
                        $sellerUuid,
                        $provider,
                        (string) $result['readiness_state']
                    ));
                } catch (\Throwable $e) {
                    $failures++;
                    $this->error(sprintf(
                        'Failed syncing tenant %s seller %s provider %s: %s',
                        $tenant === '' ? '(sentinel)' : $tenant,
                        $sellerUuid,
                        $provider,
                        $e->getMessage()
                    ));
                }
            }
        }

        if ($failures > 0) {
            $this->error("Payout account sync completed with {$failures} failure(s), {$synced} synced.");

            return self::FAILURE;
        }

        $this->success("Payout account sync complete: {$synced} account(s) synced.");

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

    /** @return list<string> every distinct tenant_uuid with a payout-account row */
    private function discoverTenants(ApplicationContext $context): array
    {
        $tenants = [];
        $rows = db($context)->table('commerce_seller_payout_accounts')->select(['tenant_uuid'])->distinct()->get();
        foreach ($rows as $row) {
            $tenants[(string) $row['tenant_uuid']] = true;
        }

        $list = array_keys($tenants);
        sort($list);

        return $list;
    }
}
