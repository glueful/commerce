<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Dispatched after commit for each `commerce_seller_orders` child that
 * {@see \Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService}
 * transitions to `fulfilled` (design spec §2.8) -- once per transitioned
 * child, whether via the single-seller `fulfill()` path or the operator
 * `fanOutFulfill()` path.
 */
final class SellerOrderFulfilled extends BaseEvent
{
    /** @param array<string,mixed> $sellerOrder */
    public function __construct(public readonly array $sellerOrder)
    {
        parent::__construct();
    }
}
