<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Pricing;

use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use PHPUnit\Framework\TestCase;

final class PricingEngineTest extends TestCase
{
    /** @return list<array{product_uuid: string, unit_price: int, quantity: int}> */
    private function lines(): array
    {
        return [
            ['product_uuid' => 'p1', 'unit_price' => 1500, 'quantity' => 2],
            ['product_uuid' => 'p2', 'unit_price' => 999, 'quantity' => 1],
        ];
    }

    public function testSubtotalOnly(): void
    {
        $totals = (new PricingEngine())->price($this->lines(), null, null, null);

        self::assertSame(3999, $totals->subtotal);
        self::assertSame(3999, $totals->grandTotal);
    }

    public function testPercentageDiscountRoundsHalfUp(): void
    {
        $discount = ['type' => 'percentage', 'value' => 1000, 'product_scope' => null];
        $totals = (new PricingEngine())->price($this->lines(), $discount, null, null);

        self::assertSame(400, $totals->discountTotal);
        self::assertSame(3599, $totals->grandTotal);
    }

    public function testFixedDiscountClampsAtZero(): void
    {
        $discount = ['type' => 'fixed', 'value' => 999999, 'product_scope' => null];
        $totals = (new PricingEngine())->price($this->lines(), $discount, null, null);

        self::assertSame(3999, $totals->discountTotal);
        self::assertSame(0, $totals->grandTotal);
    }

    public function testScopedDiscountOnlyHitsScopedLines(): void
    {
        $discount = ['type' => 'percentage', 'value' => 1000, 'product_scope' => ['p2']];
        $totals = (new PricingEngine())->price($this->lines(), $discount, null, null);

        self::assertSame(100, $totals->discountTotal);
    }

    public function testFreeShippingZeroesShipping(): void
    {
        $discount = ['type' => 'free_shipping', 'value' => 0, 'product_scope' => null];
        $shipping = new ShippingQuote('standard', 'Standard', 500);
        $totals = (new PricingEngine())->price($this->lines(), $discount, $shipping, null);

        self::assertSame(0, $totals->shippingTotal);
        self::assertSame(500, $totals->discountTotal);
    }

    public function testShippingAndTaxJoinGrandTotal(): void
    {
        $shipping = new ShippingQuote('standard', 'Standard', 500);
        $tax = new TaxQuote(360);
        $totals = (new PricingEngine())->price($this->lines(), null, $shipping, $tax);

        self::assertSame(4859, $totals->grandTotal);
    }
}
