<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Contracts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;

interface TaxCalculator
{
    /** @param array<string,mixed> $shippingAddress */
    public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote;
}
