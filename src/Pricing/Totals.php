<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Pricing;

final readonly class Totals
{
    public function __construct(
        public int $subtotal,
        public int $discountTotal,
        public int $shippingTotal,
        public int $taxTotal,
        public int $grandTotal,
    ) {
    }
}
