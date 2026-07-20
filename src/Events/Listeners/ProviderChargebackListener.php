<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events\Listeners;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Marketplace\ChargebackService;
use Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent;

/**
 * Maps a dispatched {@see ProviderChargebackEvent} to {@see ChargebackService::ingest()}
 * (design spec §2.4/§2.11/§5, MV5a Task 16) -- the SAME ingestion entry point the
 * operator/system HTTP surface
 * ({@see \Glueful\Extensions\Commerce\Http\Admin\AdminReserveController::ingestChargeback()})
 * calls. Contract-only coupling (design spec §2.11): this class imports ONLY the neutral
 * `Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent` VO -- never a payvia
 * class, never a payvia table -- so Commerce stays independently testable against a
 * hand-built event with no payvia dependency at all.
 *
 * **Deliberately no try/catch.** `CommerceServiceProvider::boot()` registers this
 * listener's {@see self::onProviderChargeback()} against
 * `Glueful\Events\EventService::dispatchOrFail()` (framework 1.71.0, design spec §5) --
 * the strict-dispatch variant that logs THEN RETHROWS the original exception from the
 * first failing listener, stopping dispatch. Payvia dispatches `ProviderChargebackEvent`
 * ONLY through `dispatchOrFail()` and marks its own durable `provider_events` row
 * dispatched ONLY after that call returns successfully -- so a thrown exception here
 * leaves Payvia's row retryable and `relayPending()` redelivers it. Swallowing a failure
 * here would silently mark a real chargeback "delivered" while it was never actually
 * ingested; delivery is therefore at-least-once, and {@see ChargebackService::ingest()}
 * is already idempotent (its `(tenant, provider, provider_event_id)` unique claim) --
 * exactly what makes a safe redelivery-on-throw contract possible.
 *
 * A BUSINESS classification such as `awaiting_attribution` or `integrity_hold` is a
 * NORMAL, successful return from `ingest()` (design spec §2.4) -- never a throw -- so it
 * never reaches this class's error path at all; only a genuine runtime failure
 * (a database error, an unexpected integrity exception on a corrupted replay, ...)
 * propagates.
 */
final class ProviderChargebackListener
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ChargebackService $chargebacks,
    ) {
    }

    public function onProviderChargeback(ProviderChargebackEvent $event): void
    {
        $this->chargebacks->ingest($this->context, $event);
    }
}
