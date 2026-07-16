<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Refunds;

use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

final class RefundOrderClaimTest extends CommerceTestCase
{
    public function testClaimIncrementsRevisionAndReturnsTrueRepeatedly(): void
    {
        $this->seedOrder('order00claim', '');
        $repository = new OrderRepository();

        self::assertTrue($repository->claimOrderFinancialMutation($this->context, '', 'order00claim'));
        self::assertSame(1, (int) $this->currentRevision('order00claim'));

        // Unlike claimPending's single-shot status transition, the revision claim is a
        // pure serialization primitive: every subsequent refund mutation attempt claims
        // again, bumping the counter further.
        self::assertTrue($repository->claimOrderFinancialMutation($this->context, '', 'order00claim'));
        self::assertSame(2, (int) $this->currentRevision('order00claim'));
    }

    public function testClaimReturnsFalseForUnknownOrder(): void
    {
        $repository = new OrderRepository();

        self::assertFalse($repository->claimOrderFinancialMutation($this->context, '', 'no-such-order'));
    }

    public function testClaimReturnsFalseForCrossTenantOrder(): void
    {
        $this->seedOrder('order00tenb', 'tenant-b');
        $repository = new OrderRepository();

        self::assertFalse($repository->claimOrderFinancialMutation($this->context, '', 'order00tenb'));
        self::assertSame(0, (int) $this->currentRevision('order00tenb', 'tenant-b'));
    }

    private function currentRevision(string $uuid, string $tenant = ''): int
    {
        $row = $this->connection->table('commerce_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? -1 : (int) $row['refund_revision'];
    }

    private function seedOrder(string $uuid, string $tenant): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
    }
}
