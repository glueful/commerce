<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seller-webhook delivery/event RETENTION cleanup (design spec §2.7/§3,
 * MV5c-2 Task 8): deletes `commerce_seller_webhook_deliveries` rows whose
 * `status` is TERMINAL (`delivered|dead_letter|canceled`) and whose
 * `updated_at` is older than `commerce.marketplace.webhooks.retention_days`
 * (default 90, `config/commerce.php`), PLUS `commerce_seller_webhook_events`
 * snapshot rows that no longer have ANY referencing delivery row (orphaned)
 * and are themselves past the same window. Host-cron-invoked -- Commerce owns
 * no scheduler of its own, mirroring every other MV4/MV5a/MV5c sweep/purge
 * command ({@see PurgeApiKeyDenialsCommand}, {@see SweepSellerWebhooksCommand}).
 *
 * **KEEP, never purge (design spec §2.7/§2.9):** `pending`, `paused`, and
 * `delivering` deliveries -- INCLUDING a `delivering` row whose crash-safe
 * claim lease has already expired. An expired-claim `delivering` row is NOT
 * a terminal status; {@see SweepSellerWebhooksCommand}'s own recovery sweep
 * is what reclaims it back to a due `pending`/`paused`/`canceled`/
 * `dead_letter` row. This command purges NONE of that recovery itself -- it
 * only ever deletes rows ALREADY sitting in a terminal status, so a
 * `delivering` row whose lease is stuck expired (the recovery sweep never
 * ran) is retained forever here too: this command is retention-only, never a
 * substitute for the recovery sweep.
 *
 * **Endpoint tombstones + audit are NEVER touched here (design spec §2.2/
 * §2.9/§2.10):** `commerce_seller_webhook_endpoints` (including a
 * `status=deleted` tombstone row) and the append-only
 * `commerce_seller_webhook_endpoint_events` audit trail follow their OWN,
 * LONGER audit-retention policy -- this command does not reference either
 * table at all. A tombstoned endpoint's own retained deliveries (already
 * `canceled` by {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointService::delete()}'s
 * tombstone sweep) age out and purge through the SAME `canceled`-status path
 * as any other terminal delivery -- the endpoint row/tombstone itself lives
 * on regardless.
 *
 * **Orphan event snapshots (design spec §2.3/§2.4):** an event snapshot is
 * "orphaned" once EVERY delivery row that ever referenced it is gone -- most
 * commonly because THIS SAME run just purged the last one, but also covering
 * a prior run that purged deliveries without also sweeping events. A replay
 * ({@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService::replay()})
 * always inserts a NEW delivery row referencing the SAME event snapshot
 * BEFORE the original (terminal) delivery it replays can ever be purged
 * (replay requires that original row to still exist) -- so an event with a
 * live replay lineage is never mistaken for orphaned, regardless of the
 * original delivery's age.
 *
 * **Tenant-safe by explicit per-tenant scoping:** unlike
 * {@see PurgeApiKeyDenialsCommand}'s single cross-tenant statement (safe
 * there because the `action='auth_denied'` predicate alone can never touch a
 * different action regardless of tenant), this command discovers every
 * distinct tenant carrying a webhook delivery or event row FIRST
 * ({@see self::discoverTenants()}), then issues every DELETE scoped by
 * `tenant_uuid = ?` -- mirroring {@see SweepSellerWebhooksCommand}/
 * {@see ReservesReleaseSweepCommand}'s identical `--tenant`-optional
 * discovery idiom -- so a cross-tenant uuid collision could never cause a
 * cross-tenant purge.
 */
#[AsCommand(
    name: 'commerce:marketplace:webhooks:purge',
    description: 'Purge terminal seller-webhook deliveries and orphaned event snapshots past the retention window'
)]
final class PurgeSellerWebhooksCommand extends BaseCommand
{
    /** @var list<string> */
    private const TERMINAL_DELIVERY_STATUSES = ['delivered', 'dead_letter', 'canceled'];

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limit to a single tenant uuid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();

        $retentionDays = max(
            0,
            (int) config($context, 'commerce.marketplace.webhooks.retention_days', 90)
        );
        $threshold = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));

        $tenantOption = $this->stringOption($input, 'tenant');
        $tenants = $tenantOption !== null ? [$tenantOption] : $this->discoverTenants($context);

        if ($tenants === []) {
            $this->info('No seller-webhook retention candidates; nothing to purge.');

            return self::SUCCESS;
        }

        $deliveriesPurged = 0;
        $eventsPurged = 0;

        foreach ($tenants as $tenant) {
            $label = $tenant === '' ? '(sentinel)' : $tenant;

            $deliveries = db($context)->table('commerce_seller_webhook_deliveries')
                ->where('tenant_uuid', '=', $tenant)
                ->whereIn('status', self::TERMINAL_DELIVERY_STATUSES)
                ->where('updated_at', '<', $threshold)
                ->delete();
            $deliveriesPurged += $deliveries;

            // Deliveries are purged FIRST, above -- so an event whose LAST
            // referencing row this same run just deleted is correctly
            // caught as orphaned by the read this method issues next.
            $events = $this->purgeOrphanEvents($context, $tenant, $threshold);
            $eventsPurged += $events;

            if ($deliveries > 0 || $events > 0) {
                $this->line(
                    "Tenant {$label}: purged {$deliveries} terminal delivery row(s), "
                        . "{$events} orphaned event snapshot(s)."
                );
            }
        }

        $this->info(sprintf(
            'Purged %d terminal delivery row(s) and %d orphaned event snapshot(s) older than %d day(s) '
                . '(before %s UTC).',
            $deliveriesPurged,
            $eventsPurged,
            $retentionDays,
            $threshold
        ));

        $this->success('Seller webhook retention sweep complete.');

        return self::SUCCESS;
    }

    /**
     * Two-step, portable (no correlated-subquery DELETE, identical on
     * SQLite/PostgreSQL): (1) every event snapshot past the retention
     * threshold for this tenant, (2) which of those uuids ANY delivery row
     * (of ANY status -- a live `pending` replay lineage counts too) still
     * references, (3) delete the set-difference.
     */
    private function purgeOrphanEvents(ApplicationContext $context, string $tenant, string $threshold): int
    {
        $candidateUuids = array_map(
            static fn (array $row): string => (string) $row['uuid'],
            db($context)->table('commerce_seller_webhook_events')
                ->select(['uuid'])
                ->where('tenant_uuid', '=', $tenant)
                ->where('occurred_at', '<', $threshold)
                ->get()
        );
        if ($candidateUuids === []) {
            return 0;
        }

        $referencedUuids = array_map(
            static fn (array $row): string => (string) $row['webhook_event_uuid'],
            db($context)->table('commerce_seller_webhook_deliveries')
                ->select(['webhook_event_uuid'])
                ->distinct()
                ->where('tenant_uuid', '=', $tenant)
                ->whereIn('webhook_event_uuid', $candidateUuids)
                ->get()
        );

        $orphanUuids = array_values(array_diff($candidateUuids, $referencedUuids));
        if ($orphanUuids === []) {
            return 0;
        }

        return db($context)->table('commerce_seller_webhook_events')
            ->where('tenant_uuid', '=', $tenant)
            ->whereIn('uuid', $orphanUuids)
            ->delete();
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @return list<string> every distinct tenant_uuid carrying at least one
     *     commerce_seller_webhook_deliveries OR commerce_seller_webhook_events
     *     row -- a superset that may include a tenant with nothing actually
     *     due; that tenant's own scoped deletes above are then a safe no-op.
     */
    private function discoverTenants(ApplicationContext $context): array
    {
        $tenants = [];
        $deliveryTenantRows = db($context)->table('commerce_seller_webhook_deliveries')
            ->select(['tenant_uuid'])->distinct()->get();
        foreach ($deliveryTenantRows as $row) {
            $tenants[(string) $row['tenant_uuid']] = true;
        }
        $eventTenantRows = db($context)->table('commerce_seller_webhook_events')
            ->select(['tenant_uuid'])->distinct()->get();
        foreach ($eventTenantRows as $row) {
            $tenants[(string) $row['tenant_uuid']] = true;
        }

        $list = array_keys($tenants);
        sort($list);

        return $list;
    }
}
