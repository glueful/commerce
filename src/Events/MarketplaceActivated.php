<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Dispatched by {@see \Glueful\Extensions\Commerce\Marketplace\MarketplaceActivationService::activate()}
 * after commit (design spec §5 flows).
 */
final class MarketplaceActivated extends BaseEvent
{
    /** @param array<string,mixed> $payload tenant_uuid, default_seller_uuid, actor */
    public function __construct(public readonly array $payload)
    {
        parent::__construct();
    }
}
