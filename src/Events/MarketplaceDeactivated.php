<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Dispatched by {@see \Glueful\Extensions\Commerce\Marketplace\MarketplaceActivationService::deactivate()}
 * after commit (design spec §2.3/§5 flows): deactivation is non-destructive
 * -- this event records the transition only, nothing else changed.
 */
final class MarketplaceDeactivated extends BaseEvent
{
    /** @param array<string,mixed> $payload tenant_uuid, actor */
    public function __construct(public readonly array $payload)
    {
        parent::__construct();
    }
}
