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
 *
 * The returned `TaxQuote` MAY carry a
 * {@see \Glueful\Extensions\Commerce\Pricing\TaxBreakdown} (design spec
 * §2.4): a calculator attaches one only when it genuinely computed tax per
 * line, keyed by each input row's `line_uuid`; an aggregate/allocated result
 * (e.g. a flat-rate fallback that only ever reconstructs one opaque base)
 * leaves the breakdown null. Downstream attribution-method detection is by
 * breakdown PRESENCE, never `instanceof` against this interface.
 */
interface LineTaxCalculator
{
    /**
     * @param list<array{taxable_amount:int, tax_class:string, quantity:int, line_uuid:string}> $taxableLines
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
