<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;

final class OrderNoteAdded extends BaseEvent
{
    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $note
     */
    public function __construct(
        public readonly array $order,
        public readonly array $note,
    ) {
        parent::__construct();
    }
}
