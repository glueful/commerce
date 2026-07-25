<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tax;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Support\CommerceSettings;

final class FlatRateTaxCalculator implements TaxCalculator
{
    /** @param array<string,mixed> $shippingAddress */
    public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
    {
        $basisPoints = CommerceSettings::taxFlatRateBps($context);
        if ($basisPoints <= 0 || $taxableAmount <= 0) {
            return new TaxQuote(0);
        }

        return new TaxQuote(intdiv($taxableAmount * $basisPoints + 5000, 10000));
    }
}
