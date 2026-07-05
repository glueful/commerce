<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;

final class OrderFulfilled extends BaseEvent
{
    /** @param array<string,mixed> $order */
    public function __construct(public readonly array $order)
    {
        parent::__construct();
    }
}
