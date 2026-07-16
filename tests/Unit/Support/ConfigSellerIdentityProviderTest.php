<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Support;

use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

final class ConfigSellerIdentityProviderTest extends CommerceTestCase
{
    public function testUnsetConfigReturnsAllNullKeysRegardlessOfTenant(): void
    {
        $result = (new ConfigSellerIdentityProvider())->forTenant($this->context, 'tenantAAAA01');

        self::assertSame(['name' => null, 'address' => null, 'tax_id' => null], $result);

        // Ignores $tenantUuid entirely: a different tenant sees the same (null) values.
        self::assertSame(
            $result,
            (new ConfigSellerIdentityProvider())->forTenant($this->context, 'tenantBBBB02')
        );
    }

    public function testConfiguredValuesArePassedThroughVerbatim(): void
    {
        $this->context->mergeConfigDefaults('commerce', [
            'seller' => [
                'name' => 'Acme Supply Co.',
                'address' => '1 Market St, Springfield',
                'tax_id' => 'TAX-99887766',
            ],
        ]);

        $result = (new ConfigSellerIdentityProvider())->forTenant($this->context, 'tenantAAAA01');

        self::assertSame([
            'name' => 'Acme Supply Co.',
            'address' => '1 Market St, Springfield',
            'tax_id' => 'TAX-99887766',
        ], $result);
    }

    public function testPartialConfigLeavesRemainingKeysNull(): void
    {
        $this->context->mergeConfigDefaults('commerce', [
            'seller' => [
                'name' => 'Only Name Set',
            ],
        ]);

        $result = (new ConfigSellerIdentityProvider())->forTenant($this->context, 'tenantAAAA01');

        self::assertSame(['name' => 'Only Name Set', 'address' => null, 'tax_id' => null], $result);
    }
}
