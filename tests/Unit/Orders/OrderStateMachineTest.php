<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Orders;

use Glueful\Extensions\Commerce\Orders\OrderStateMachine;
use PHPUnit\Framework\TestCase;

final class OrderStateMachineTest extends TestCase
{
    public function testStateMachine(): void
    {
        OrderStateMachine::assertTransition('pending_payment', 'paid');
        OrderStateMachine::assertTransition('paid', 'refunded');

        $this->expectException(\DomainException::class);

        OrderStateMachine::assertTransition('canceled', 'paid');
    }
}
