<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders\Refunds;

final class RefundInput
{
    /** @param list<array{order_line_uuid:string,quantity:int,amount:int}> $lines */
    public function __construct(
        public readonly ?int $amount,
        public readonly ?string $reason,
        public readonly array $lines,
        public readonly bool $restock,
    ) {
    }
}
