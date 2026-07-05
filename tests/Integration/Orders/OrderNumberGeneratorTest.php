<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

final class OrderNumberGeneratorTest extends CommerceTestCase
{
    public function testFreshSequenceFirstOrderAndIncrements(): void
    {
        $generator = new OrderNumberGenerator();

        self::assertSame('ORD-000001', $generator->next($this->context, ''));
        self::assertSame('ORD-000002', $generator->next($this->context, ''));
    }

    public function testSequencesAreTenantIsolated(): void
    {
        $generator = new OrderNumberGenerator();

        $generator->next($this->context, '');

        self::assertSame('ORD-000001', $generator->next($this->context, 'tenantAAAA01'));
    }
}
