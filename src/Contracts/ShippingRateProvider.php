<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Contracts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;

interface ShippingRateProvider
{
    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $shippingAddress
     * @return list<ShippingQuote>
     */
    public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array;
}
