<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The MV5c-2 Task 5 recovery sweep (design spec §2.4/§2.7, host-cron): the
 * durable outbox row -- never the queue hint -- is the delivery authority,
 * so this command repairs the two ways a hint can be lost. (a) Every
 * `delivering` row whose crash-safe claim lease has expired
 * ({@see SellerWebhookDeliveryRepository::dueDelivering()}) is reclaimed,
 * independently, via {@see SellerWebhookDeliveryService::reclaimExpired()} --
 * which itself re-claims seller -> endpoint -> the delivery's own
 * token+expiry CAS, returning it to due `pending` (or `paused`/`canceled`,
 * or `dead_letter` if its attempt budget was already exhausted when the
 * crash was discovered) and NEVER touching `attempts` a second time. (b)
 * Every due `pending` row ({@see SellerWebhookDeliveryRepository::duePending()})
 * whose original `afterCommit()` queue wake-up was lost is simply
 * re-enqueued via {@see SellerWebhookDeliveryService::enqueueHint()} -- a
 * pure hint, no state transition; the actual claim happens when the queue
 * job runs. One candidate's exception is caught and reported without
 * aborting the rest of the sweep (mirrors {@see PayoutsRetrySweepCommand}/
 * {@see ReservesReleaseSweepCommand}). Batch-limited per tenant by
 * `commerce.marketplace.webhooks.sweep_batch_size`, `--tenant`-optional
 * discovery mirroring {@see SyncPayoutAccountsCommand}'s idiom.
 */
#[AsCommand(
    name: 'commerce:marketplace:webhooks:sweep',
    description: 'Reclaim expired seller-webhook delivery leases and re-enqueue due pending deliveries'
)]
final class SweepSellerWebhooksCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limit to a single tenant uuid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();
        $deliveries = app($context, SellerWebhookDeliveryRepository::class);
        $service = app($context, SellerWebhookDeliveryService::class);
        $batchSize = max(1, (int) config($context, 'commerce.marketplace.webhooks.sweep_batch_size', 100));

        $tenantOption = $this->stringOption($input, 'tenant');
        $tenants = $tenantOption !== null ? [$tenantOption] : $this->discoverTenants($context);

        if ($tenants === []) {
            $this->info('No due seller-webhook deliveries; nothing to sweep.');

            return self::SUCCESS;
        }

        $reclaimed = 0;
        $enqueued = 0;
        $failures = 0;

        foreach ($tenants as $tenant) {
            $label = $tenant === '' ? '(sentinel)' : $tenant;

            foreach ($deliveries->dueDelivering($context, $tenant, $batchSize) as $candidate) {
                $uuid = (string) $candidate['uuid'];

                try {
                    $outcome = $service->reclaimExpired($context, $tenant, $uuid);
                    if ($outcome === 'stale' || $outcome === 'not_claimed') {
                        continue;
                    }

                    $reclaimed++;
                    $this->line("Tenant {$label} delivery {$uuid}: reclaimed -> {$outcome}");
                } catch (\Throwable $e) {
                    $failures++;
                    $this->error("Tenant {$label} delivery {$uuid}: reclaim failed: " . $e->getMessage());
                }
            }

            foreach ($deliveries->duePending($context, $tenant, $batchSize) as $candidate) {
                $uuid = (string) $candidate['uuid'];

                try {
                    $service->enqueueHint($context, $tenant, $uuid);
                    $enqueued++;
                } catch (\Throwable $e) {
                    $failures++;
                    $this->error("Tenant {$label} delivery {$uuid}: enqueue failed: " . $e->getMessage());
                }
            }
        }

        $this->line(sprintf(
            'Webhook sweep: %d lease(s) reclaimed, %d due delivery(ies) re-enqueued, %d failure(s).',
            $reclaimed,
            $enqueued,
            $failures
        ));

        if ($failures > 0) {
            $this->error("Webhook sweep completed with {$failures} failure(s).");

            return self::FAILURE;
        }

        $this->success('Webhook sweep complete.');

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

    /** @return list<string> every distinct tenant_uuid with any commerce_seller_webhook_deliveries row */
    private function discoverTenants(ApplicationContext $context): array
    {
        $tenants = [];
        $rows = db($context)->table('commerce_seller_webhook_deliveries')->select(['tenant_uuid'])->distinct()->get();
        foreach ($rows as $row) {
            $tenants[(string) $row['tenant_uuid']] = true;
        }

        $list = array_keys($tenants);
        sort($list);

        return $list;
    }
}
