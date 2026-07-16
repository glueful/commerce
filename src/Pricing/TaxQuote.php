<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Pricing;

final readonly class TaxQuote
{
    public function __construct(
        public int $amount,
        public string $label = 'Tax',
    ) {
    }
}
