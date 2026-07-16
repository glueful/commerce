<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Pricing;

final readonly class ShippingQuote
{
    public function __construct(
        public string $id,
        public string $label,
        public int $amount,
    ) {
    }
}
