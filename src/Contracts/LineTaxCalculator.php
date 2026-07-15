<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Contracts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;

/**
 * Optional commerce-local contract closing the aggregate `TaxCalculator` gap
 * (design spec §5): the aggregate `quote()` call carries no per-line detail,
 * so a rate table keyed by per-line tax class and per-rate `shipping_taxable`
 * has nothing to match against. A `TaxCalculator` MAY additionally implement
 * this interface; checkout dispatches to `quoteDetailed()` via `instanceof`
 * when available, otherwise falls back to the existing aggregate call
 * byte-identically (see {@see \Glueful\Extensions\Commerce\Orders\CheckoutService}).
 */
interface LineTaxCalculator
{
    /**
     * @param list<array{taxable_amount:int, tax_class:string, quantity:int}> $taxableLines
     *        post-discount EXTENDED line totals (already multiplied by quantity --
     *        implementations MUST NOT multiply `taxable_amount` by `quantity` again)
     * @param array<string,mixed> $shippingAddress
     */
    public function quoteDetailed(
        ApplicationContext $context,
        array $taxableLines,
        int $shippingAmount,
        array $shippingAddress
    ): TaxQuote;
}
