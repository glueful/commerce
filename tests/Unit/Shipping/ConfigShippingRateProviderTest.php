<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Shipping;

use Glueful\Extensions\Commerce\Shipping\ConfigShippingRateProvider;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

final class ConfigShippingRateProviderTest extends CommerceTestCase
{
    public function testFreeOverThresholdZeroesAmount(): void
    {
        $this->context->mergeConfigDefaults('commerce', [
            'shipping' => [
                'methods' => [
                    ['id' => 'std', 'label' => 'Std', 'amount' => 500, 'free_over' => 5000],
                ],
            ],
        ]);

        $quotes = (new ConfigShippingRateProvider())->quote(
            $this->context,
            [['product_uuid' => 'p', 'unit_price' => 6000, 'quantity' => 1, 'type' => 'physical']],
            ['country' => 'US']
        );

        self::assertSame(0, $quotes[0]->amount);
    }

    public function testDigitalOnlyCartGetsNoQuotes(): void
    {
        $quotes = (new ConfigShippingRateProvider())->quote(
            $this->context,
            [['product_uuid' => 'p', 'unit_price' => 900, 'quantity' => 1, 'type' => 'digital']],
            ['country' => 'US']
        );

        self::assertSame([], $quotes);
    }

    public function testZoneMismatchExcludesMethod(): void
    {
        $this->context->mergeConfigDefaults('commerce', [
            'shipping' => [
                'methods' => [
                    ['id' => 'gh', 'label' => 'Ghana only', 'amount' => 300, 'zones' => ['GH']],
                ],
            ],
        ]);

        $quotes = (new ConfigShippingRateProvider())->quote(
            $this->context,
            [['product_uuid' => 'p', 'unit_price' => 1000, 'quantity' => 1, 'type' => 'physical']],
            ['country' => 'US']
        );

        self::assertSame([], $quotes);
    }
}
