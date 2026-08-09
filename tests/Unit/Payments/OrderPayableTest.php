<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Payments;

use Glueful\Extensions\Commerce\Payments\OrderPayable;
use PHPUnit\Framework\TestCase;

final class OrderPayableTest extends TestCase
{
    public function testTypeConstantIsCommerceOrder(): void
    {
        self::assertSame('commerce_order', OrderPayable::TYPE);
    }

    /**
     * Source inventory: pins every production call site that must identify a
     * Commerce order payable through the shared constant rather than a private
     * re-declaration of the 'commerce_order' string literal. Test fixtures are
     * explicitly NOT required to migrate off the literal -- only these five.
     */
    public function testProductionCallSitesConsumeTheSharedConstant(): void
    {
        $root = dirname(__DIR__, 3);
        $sites = [
            $root . '/src/Orders/CheckoutService.php',
            $root . '/src/Payments/OrderPaymentConfirmationHandler.php',
            $root . '/src/Mail/OrderNotifiable.php',
            $root . '/src/Orders/Refunds/RefundService.php',
            $root . '/src/Marketplace/ChargebackService.php',
        ];

        foreach ($sites as $file) {
            self::assertFileExists($file);
            $source = (string) file_get_contents($file);
            self::assertStringContainsString(
                'OrderPayable::TYPE',
                $source,
                "{$file} must consume OrderPayable::TYPE instead of a private 'commerce_order' literal."
            );
        }
    }
}
