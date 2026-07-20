<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Pricing;

final readonly class TaxQuote
{
    public function __construct(
        public int $amount,
        public string $label = 'Tax',
        public ?TaxBreakdown $breakdown = null,
    ) {
        if ($this->breakdown !== null && $this->breakdown->total() !== $this->amount) {
            throw new \InvalidArgumentException(sprintf(
                'TaxQuote: breakdown total (%d) does not match quote amount (%d).',
                $this->breakdown->total(),
                $this->amount
            ));
        }
    }
}
