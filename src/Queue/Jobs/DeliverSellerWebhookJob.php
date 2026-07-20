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
 * **No tenant in the job payload (design spec §2.4 CARRY-FORWARD).** The
 * outbox's own queue hint carries only `['delivery_uuid' => ...]` -- no
 * tenant -- so this job resolves which tenant the hinted uuid belongs to via
 * {@see SellerWebhookDeliveryRepository::findByUuidAnyTenant()} (the ONE
 * unscoped read in this whole subsystem, used ONLY for that resolution
 * step) before delegating to the fully tenant-scoped
 * {@see SellerWebhookDeliveryService::deliver()}, whose every subsequent
 * read/write is `tenant_uuid`-scoped again. A delivery_uuid that no longer
 * exists (already finalized/canceled/purged) is a silent no-op -- the
 * durable row, not the hint, is the authority (design spec §2.4).
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

        $deliveryUuid = $this->getData()['delivery_uuid'] ?? null;
        if (!is_string($deliveryUuid) || $deliveryUuid === '') {
            return;
        }

        $repository = app($this->context, SellerWebhookDeliveryRepository::class);
        $row = $repository->findByUuidAnyTenant($this->context, $deliveryUuid);
        if ($row === null) {
            return;
        }

        $service = app($this->context, SellerWebhookDeliveryService::class);
        $service->deliver($this->context, (string) $row['tenant_uuid'], $deliveryUuid);
    }
}
