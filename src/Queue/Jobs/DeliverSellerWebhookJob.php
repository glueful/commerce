<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Queue\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService;
use Glueful\Queue\Job;

/**
 * The MV5c-2 Task 5 queue-triggered webhook delivery worker (design spec
 * §2.4/§2.7): thin glue around {@see SellerWebhookDeliveryService::deliver()}.
 * Pushed by {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookOutboxPublisher::pushQueueHints()}
 * (a pure wake-up hint, `afterCommit()`, for freshly-captured `pending` rows)
 * and by {@see \Glueful\Extensions\Commerce\Console\SweepSellerWebhooksCommand}
 * (re-enqueuing due `pending` rows whose original wake-up was lost).
 *
 * **Tenant-scoped resolution.** Every hint pushed since 1.2.x carries
 * `['delivery_uuid' => ..., 'tenant_uuid' => ...]`, and this job resolves
 * the row by that (tenant, uuid) PAIR -- delivery uuids carry no
 * cross-tenant uniqueness constraint (migration 019), so uuid-only
 * resolution could route a hypothetical cross-tenant collision to the
 * wrong tenant's row and leave the intended one waiting for the sweep.
 * A payload WITHOUT `tenant_uuid` (a hint enqueued by a pre-fix 1.2.0
 * install that upgrades with jobs still in flight) falls back to the
 * legacy unscoped {@see SellerWebhookDeliveryRepository::findByUuidAnyTenant()}
 * resolution -- the ONE unscoped read in this whole subsystem, kept ONLY
 * for that in-flight-payload compatibility. Either way every subsequent
 * read/write in {@see SellerWebhookDeliveryService::deliver()} is
 * `tenant_uuid`-scoped. A delivery_uuid that no longer exists (already
 * finalized/canceled/purged) is a silent no-op -- the durable row, not
 * the hint, is the authority (design spec §2.4).
 */
final class DeliverSellerWebhookJob extends Job
{
    protected ?string $queue = 'webhooks';

    /** @param array<string,mixed> $data */
    public function __construct(array $data = [], ?ApplicationContext $context = null)
    {
        parent::__construct($data);
        $this->context = $context;
    }

    public function handle(): void
    {
        if ($this->context === null) {
            return;
        }

        $data = $this->getData();
        $deliveryUuid = $data['delivery_uuid'] ?? null;
        if (!is_string($deliveryUuid) || $deliveryUuid === '') {
            return;
        }

        $repository = app($this->context, SellerWebhookDeliveryRepository::class);

        // Tenant-scoped resolution by the (tenant, uuid) pair; the unscoped
        // read remains ONLY as the legacy fallback for hints enqueued by a
        // pre-fix install with jobs still in flight (see class docblock).
        // `tenant_uuid` may legitimately be '' (tenancy-off sentinel), so the
        // discriminator is key PRESENCE, not non-emptiness.
        $tenantUuid = $data['tenant_uuid'] ?? null;
        $row = is_string($tenantUuid)
            ? $repository->findByUuid($this->context, $tenantUuid, $deliveryUuid)
            : $repository->findByUuidAnyTenant($this->context, $deliveryUuid);
        if ($row === null) {
            return;
        }

        $service = app($this->context, SellerWebhookDeliveryService::class);
        $service->deliver($this->context, (string) $row['tenant_uuid'], $deliveryUuid);
    }
}
