<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;

final class RefundFailed extends BaseEvent
{
    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $refund
     */
    public function __construct(
        public readonly array $order,
        public readonly array $refund,
    ) {
        parent::__construct();
    }
}
