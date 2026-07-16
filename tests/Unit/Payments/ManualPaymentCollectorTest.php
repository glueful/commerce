<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Payments;

use Glueful\Extensions\Commerce\CommerceServiceProvider;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;

final class ManualPaymentCollectorTest extends CommerceTestCase
{
    public function testManualCollectorReturnsManualInstructions(): void
    {
        $initiation = (new ManualPaymentCollector())->initiate(
            $this->context,
            new PayableReference('commerce_order', 'order0000001', 1000, 'USD')
        );

        self::assertSame('manual', $initiation->provider);
        self::assertSame('manual', $initiation->status);
        self::assertArrayHasKey('instructions', $initiation->payload);
    }

    public function testCommerceDoesNotBindSharedPaymentCollectorContract(): void
    {
        self::assertArrayNotHasKey(PaymentCollector::class, CommerceServiceProvider::services());
    }
}
