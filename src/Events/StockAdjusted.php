<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;

final class StockAdjusted extends BaseEvent
{
    /** @param array<string,mixed> $payload */
    public function __construct(public readonly array $payload)
    {
        parent::__construct();
    }
}
