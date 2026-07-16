<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Orders;

use Glueful\Extensions\Commerce\Orders\Refunds\RefundInput;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use PHPUnit\Framework\TestCase;

final class RefundFingerprintTest extends TestCase
{
    public function testSameInputProducesSameHash(): void
    {
        $input = new RefundInput(500, 'damaged', [
            ['order_line_uuid' => 'line0000001', 'quantity' => 1, 'amount' => 500],
        ], true);

        $first = RefundService::fingerprint('order00001', $input);
        $second = RefundService::fingerprint('order00001', $input);

        self::assertSame($first, $second);
    }

    public function testLineOrderIsIrrelevant(): void
    {
        $ordered = new RefundInput(500, 'damaged', [
            ['order_line_uuid' => 'line0000001', 'quantity' => 1, 'amount' => 200],
            ['order_line_uuid' => 'line0000002', 'quantity' => 1, 'amount' => 300],
        ], true);
        $reversed = new RefundInput(500, 'damaged', [
            ['order_line_uuid' => 'line0000002', 'quantity' => 1, 'amount' => 300],
            ['order_line_uuid' => 'line0000001', 'quantity' => 1, 'amount' => 200],
        ], true);

        self::assertSame(
            RefundService::fingerprint('order00001', $ordered),
            RefundService::fingerprint('order00001', $reversed)
        );
    }

    public function testDifferentAmountProducesDifferentHash(): void
    {
        $five = new RefundInput(500, 'damaged', [], false);
        $six = new RefundInput(600, 'damaged', [], false);

        self::assertNotSame(
            RefundService::fingerprint('order00001', $five),
            RefundService::fingerprint('order00001', $six)
        );
    }

    public function testDifferentOrderProducesDifferentHash(): void
    {
        $input = new RefundInput(null, null, [], false);

        self::assertNotSame(
            RefundService::fingerprint('order00001', $input),
            RefundService::fingerprint('order00002', $input)
        );
    }
}
