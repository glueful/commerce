<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tax;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\LineTaxCalculator;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;

/**
 * The `TaxCalculator::class` default (design spec §4): Db when the current
 * tenant has at least one tax-rate row, else flat-rate config -- one
 * existence query per quote ({@see DbTaxCalculator::hasRatesForCurrentTenant()},
 * index-covered), mirroring
 * {@see \Glueful\Extensions\Commerce\Shipping\DelegatingShippingRateProvider}'s
 * precedence. A tenant is always wholly on one source or the other; rows are
 * never mixed per-request.
 *
 * Implements BOTH `TaxCalculator` and `LineTaxCalculator` so checkout's
 * `instanceof` dispatch reaches the data-driven detailed path through the
 * DEFAULT binding -- {@see FlatRateTaxCalculator} itself deliberately does
 * NOT implement the optional contract (spec §4). With no rate rows,
 * `quoteDetailed()` reconstructs the EXACT legacy aggregate base --
 * `sum(taxableLines.taxable_amount) + shippingAmount` -- and calls
 * `FlatRateTaxCalculator::quote()`, so a tenant without rates gets a
 * byte-identical result to the pre-Layer-4 checkout path.
 *
 * An application that rebinds `TaxCalculator::class` replaces this whole
 * chain outright (its DI definition wins over
 * {@see \Glueful\Extensions\Commerce\CommerceServiceProvider}'s).
 */
final class DelegatingTaxCalculator implements TaxCalculator, LineTaxCalculator
{
    public function __construct(
        private DbTaxCalculator $db,
        private FlatRateTaxCalculator $flat,
    ) {
    }

    /** @param array<string,mixed> $shippingAddress */
    public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
    {
        $calculator = $this->db->hasRatesForCurrentTenant($context) ? $this->db : $this->flat;

        return $calculator->quote($context, $taxableAmount, $shippingAddress);
    }

    /**
     * @param list<array{taxable_amount:int, tax_class:string, quantity:int, line_uuid:string}> $taxableLines
     * @param array<string,mixed> $shippingAddress
     */
    public function quoteDetailed(
        ApplicationContext $context,
        array $taxableLines,
        int $shippingAmount,
        array $shippingAddress
    ): TaxQuote {
        if ($this->db->hasRatesForCurrentTenant($context)) {
            return $this->db->quoteDetailed($context, $taxableLines, $shippingAmount, $shippingAddress);
        }

        $legacyBase = $shippingAmount;
        foreach ($taxableLines as $line) {
            $legacyBase += (int) $line['taxable_amount'];
        }

        return $this->flat->quote($context, $legacyBase, $shippingAddress);
    }
}
