<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Commerce\Marketplace\ReconciliationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only ledger reconciliation (design spec §2.11, MV3 Task 10): runs
 * {@see ReconciliationService::scan()} and prints its missing/duplicate/
 * mismatched findings. NEVER posts or mutates anything -- purely a
 * read-and-report diagnostic. Exits {@see self::FAILURE} whenever any
 * finding exists across the scanned tenant(s), so CI/ops can gate on a
 * clean ledger.
 *
 * `--tenant` scopes to a single tenant uuid; omitted, every tenant with
 * ANY marketplace ledger-adjacent activity (a confirmed seller order, a
 * completed refund on a partitioned order, or a payout) is discovered and
 * scanned in turn -- mirroring {@see CustomersLinkGuestsCommand}'s own
 * optional `--tenant` idiom (present -> scoped, absent -> unscoped).
 */
#[AsCommand(
    name: 'commerce:marketplace:reconcile',
    description: 'Scan the settlement ledger for missing/duplicate/mismatched postings (read-only)'
)]
final class MarketplaceReconcileCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limit to a single tenant uuid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();
        $service = app($context, ReconciliationService::class);

        $tenantOption = $input->getOption('tenant');
        $tenants = is_string($tenantOption) && trim($tenantOption) !== ''
            ? [trim($tenantOption)]
            : $this->discoverTenants($context);

        if ($tenants === []) {
            $this->info('No marketplace ledger-adjacent activity found; nothing to reconcile.');

            return self::SUCCESS;
        }

        $totalFindings = 0;
        foreach ($tenants as $tenant) {
            $report = $service->scan($context, $tenant);
            $totalFindings += $this->printTenantReport($tenant, $report);
        }

        $tenantWord = count($tenants) === 1 ? 'tenant' : 'tenants';
        if ($totalFindings > 0) {
            $this->error(sprintf(
                'Reconciliation found %d issue(s) across %d %s.',
                $totalFindings,
                count($tenants),
                $tenantWord
            ));

            return self::FAILURE;
        }

        $this->success('Reconciliation clean across ' . count($tenants) . " {$tenantWord}. No findings.");

        return self::SUCCESS;
    }

    /**
     * @param array{
     *     missing: list<array<string,mixed>>,
     *     duplicate: list<array<string,mixed>>,
     *     mismatched: list<array<string,mixed>>
     * } $report
     * @return int the number of findings for this tenant
     */
    private function printTenantReport(string $tenant, array $report): int
    {
        $count = count($report['missing']) + count($report['duplicate']) + count($report['mismatched']);
        $label = $tenant === '' ? '(sentinel)' : $tenant;

        if ($count === 0) {
            $this->line("Tenant {$label}: clean.");

            return 0;
        }

        $this->line(sprintf(
            'Tenant %s: %d missing, %d duplicate, %d mismatched.',
            $label,
            count($report['missing']),
            count($report['duplicate']),
            count($report['mismatched'])
        ));

        $rows = [];
        foreach (['missing', 'duplicate', 'mismatched'] as $kind) {
            foreach ($report[$kind] as $finding) {
                $rows[] = [
                    strtoupper($kind),
                    (string) ($finding['source'] ?? '-'),
                    $this->findingUuid($finding),
                    (string) ($finding['seller_uuid'] ?? '-'),
                    (string) ($finding['entry_type'] ?? '-'),
                    (string) ($finding['detail'] ?? '-'),
                ];
            }
        }
        $this->table(['Kind', 'Source', 'UUID', 'Seller', 'Entry Type', 'Detail'], $rows);

        return $count;
    }

    /** @param array<string,mixed> $finding */
    private function findingUuid(array $finding): string
    {
        foreach (['order_uuid', 'refund_uuid', 'payout_uuid', 'seller_order_uuid'] as $key) {
            if (isset($finding[$key]) && $finding[$key] !== '') {
                return (string) $finding[$key];
            }
        }

        return '-';
    }

    /** @return list<string> every distinct tenant_uuid with marketplace ledger-adjacent activity */
    private function discoverTenants(ApplicationContext $context): array
    {
        $tenants = [];
        foreach (['commerce_seller_orders', 'commerce_refunds', 'commerce_payouts'] as $table) {
            $rows = db($context)->table($table)->select(['tenant_uuid'])->distinct()->get();
            foreach ($rows as $row) {
                $tenants[(string) $row['tenant_uuid']] = true;
            }
        }

        $list = array_keys($tenants);
        sort($list);

        return $list;
    }
}
