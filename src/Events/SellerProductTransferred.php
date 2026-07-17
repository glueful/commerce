<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Dispatched by {@see \Glueful\Extensions\Commerce\Marketplace\SellerAttributionService::assign()}
 * after commit when the product already had an owning seller (a transfer,
 * as opposed to {@see SellerProductAdopted}'s unowned-to-owned move).
 */
final class SellerProductTransferred extends BaseEvent
{
    /** @param array<string,mixed> $payload tenant_uuid, product_uuid, from_seller_uuid, to_seller_uuid, actor */
    public function __construct(public readonly array $payload)
    {
        parent::__construct();
    }
}
