<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Shipping;

use Glueful\Extensions\Commerce\Shipping\ConfigShippingRateProvider;
use Glueful\Extensions\Commerce\Shipping\DbShippingRateProvider;
use Glueful\Extensions\Commerce\Shipping\DelegatingShippingRateProvider;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

final class DelegatingShippingRateProviderTest extends CommerceTestCase
{
    /**
     * Regression gate (spec §4/§7): with NO zone rows for the tenant, the
     * delegator must behave byte-identically to going straight through
     * {@see ConfigShippingRateProvider} -- the tenant hasn't opted into
     * data-driven shipping, so config is consulted, not an empty Db result.
     */
    public function testNoZoneRowsDelegatesToConfigByteIdentical(): void
    {
        $this->context->mergeConfigDefaults('commerce', [
            'shipping' => [
                'methods' => [
                    ['id' => 'std', 'label' => 'Standard', 'amount' => 500, 'free_over' => 5000],
                ],
            ],
        ]);
        $lines = [['unit_price' => 6000, 'quantity' => 1, 'type' => 'physical']];
        $address = ['country' => 'US'];

        $expected = (new ConfigShippingRateProvider())->quote($this->context, $lines, $address);
        $actual = $this->delegator()->quote($this->context, $lines, $address);

        self::assertEquals($expected, $actual);
        self::assertSame(0, $actual[0]->amount, 'sanity: free_over threshold applied via the config path');
    }

    public function testNoZoneRowsDigitalOnlyCartByteIdenticalToConfig(): void
    {
        $this->context->mergeConfigDefaults('commerce', [
            'shipping' => ['methods' => [['id' => 'std', 'label' => 'Standard', 'amount' => 500]]],
        ]);
        $lines = [['unit_price' => 900, 'quantity' => 1, 'type' => 'digital']];
        $address = ['country' => 'US'];

        self::assertSame(
            (new ConfigShippingRateProvider())->quote($this->context, $lines, $address),
            $this->delegator()->quote($this->context, $lines, $address)
        );
    }

    public function testZoneRowsPresentDelegatesToDbEvenWhenConfigWouldAlsoQuote(): void
    {
        $this->context->mergeConfigDefaults('commerce', [
            'shipping' => ['methods' => [['id' => 'config-std', 'label' => 'Config Standard', 'amount' => 999]]],
        ]);

        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => 'zonedbrows01',
            'tenant_uuid' => '',
            'name' => 'Everywhere',
            'position' => 0,
        ]);
        $this->connection->table('commerce_shipping_methods')->insert([
            'uuid' => 'methdbrows01',
            'zone_uuid' => 'zonedbrows01',
            'kind' => 'flat',
            'label' => 'DB Standard',
            'config' => json_encode(['amount' => 250], JSON_THROW_ON_ERROR),
            'position' => 0,
        ]);

        $quotes = $this->delegator()->quote(
            $this->context,
            [['unit_price' => 1000, 'quantity' => 1, 'type' => 'physical']],
            ['country' => 'US']
        );

        self::assertCount(1, $quotes);
        self::assertSame('methdbrows01', $quotes[0]->id);
        self::assertSame('DB Standard', $quotes[0]->label);
        self::assertSame(250, $quotes[0]->amount);
    }

    /**
     * A tenant with zone rows is wholly on the data-driven path -- even a zone
     * match miss (this address matches no zone) must NOT fall through to
     * config, unlike the no-rows-at-all case above.
     */
    public function testZoneRowsPresentButNoMatchDoesNotFallThroughToConfig(): void
    {
        $this->context->mergeConfigDefaults('commerce', [
            'shipping' => ['methods' => [['id' => 'config-std', 'label' => 'Config Standard', 'amount' => 999]]],
        ]);

        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => 'zonecanada01',
            'tenant_uuid' => '',
            'name' => 'Canada Only',
            'position' => 0,
        ]);
        $this->connection->table('commerce_shipping_zone_locations')->insert([
            'zone_uuid' => 'zonecanada01',
            'kind' => 'country',
            'value' => 'CA',
        ]);
        $this->connection->table('commerce_shipping_methods')->insert([
            'uuid' => 'methcanada01',
            'zone_uuid' => 'zonecanada01',
            'kind' => 'flat',
            'label' => 'CA Standard',
            'config' => json_encode(['amount' => 500], JSON_THROW_ON_ERROR),
            'position' => 0,
        ]);

        $quotes = $this->delegator()->quote(
            $this->context,
            [['unit_price' => 1000, 'quantity' => 1, 'type' => 'physical']],
            ['country' => 'US']
        );

        self::assertSame([], $quotes);
    }

    private function delegator(): DelegatingShippingRateProvider
    {
        return new DelegatingShippingRateProvider(
            new DbShippingRateProvider(new ShippingZoneRepository(), new SentinelTenantResolver()),
            new ConfigShippingRateProvider()
        );
    }
}
