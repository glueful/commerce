<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Dispatched by {@see \Glueful\Extensions\Commerce\Marketplace\SellerAttributionService::assign()}
 * after commit when the product had no prior seller (adoption, as opposed
 * to {@see SellerProductTransferred}'s owner-to-owner move).
 */
final class SellerProductAdopted extends BaseEvent
{
    /** @param array<string,mixed> $payload tenant_uuid, product_uuid, seller_uuid, actor */
    public function __construct(public readonly array $payload)
    {
        parent::__construct();
    }
}
