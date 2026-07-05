<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

final class InsufficientStockException extends \RuntimeException
{
    public function __construct(
        public readonly string $variantUuid,
        public readonly string $sku,
    ) {
        parent::__construct("Insufficient stock for {$sku}.");
    }
}
