<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Refunds;

use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

final class RefundRepositoryTest extends CommerceTestCase
{
    public function testInsertRoundTripsThroughFindByIdempotencyKey(): void
    {
        $repository = new RefundRepository();
        $refund = $this->refundRow('refund000001', 'order00001', 'idem-key-1');

        $repository->insert($this->context, $refund, [
            ['order_line_uuid' => 'line0000001', 'quantity' => 2, 'amount' => 500],
        ]);

        $found = $repository->findByIdempotencyKey($this->context, '', 'idem-key-1');

        self::assertNotNull($found);
        self::assertSame('refund000001', $found['uuid']);
        self::assertSame('order00001', $found['order_uuid']);
        self::assertSame(500, (int) $found['amount']);
        self::assertNull($repository->findByIdempotencyKey($this->context, '', 'no-such-key'));

        $sameUuid = $repository->findByUuid($this->context, '', 'refund000001');
        self::assertNotNull($sameUuid);
        self::assertSame('idem-key-1', $sameUuid['idempotency_key']);

        $lines = $repository->linesFor($this->context, '', 'refund000001');
        self::assertCount(1, $lines);
        self::assertSame('line0000001', $lines[0]['order_line_uuid']);
        self::assertSame(2, (int) $lines[0]['quantity']);

        $forOrder = $repository->listForOrder($this->context, '', 'order00001');
        self::assertCount(1, $forOrder);
        self::assertSame('refund000001', $forOrder[0]['uuid']);
        self::assertCount(1, $forOrder[0]['lines']);
    }

    public function testClaimPendingIsTrueOnceThenFalse(): void
    {
        $repository = new RefundRepository();
        $repository->insert($this->context, $this->refundRow('refund000002', 'order00002', 'idem-key-2'), []);

        self::assertTrue($repository->claimPending($this->context, '', 'refund000002', 'completed'));
        self::assertFalse($repository->claimPending($this->context, '', 'refund000002', 'completed'));

        $found = $repository->findByUuid($this->context, '', 'refund000002');
        self::assertNotNull($found);
        self::assertSame('completed', $found['status']);
    }

    public function testClaimPendingAppliesExtraSetValues(): void
    {
        $repository = new RefundRepository();
        $repository->insert($this->context, $this->refundRow('refund000006', 'order00006', 'idem-key-6'), []);

        self::assertTrue($repository->claimPending(
            $this->context,
            '',
            'refund000006',
            'failed',
            ['failure_reason' => 'provider declined']
        ));

        $found = $repository->findByUuid($this->context, '', 'refund000006');
        self::assertNotNull($found);
        self::assertSame('failed', $found['status']);
        self::assertSame('provider declined', $found['failure_reason']);
    }

    public function testRestockReservedByLineSumsAcrossRefundsAndExcludesFailed(): void
    {
        $repository = new RefundRepository();

        $repository->insert(
            $this->context,
            $this->refundRow(
                'refund000003',
                'order00003',
                'idem-key-3',
                ['restocked' => true, 'status' => 'completed']
            ),
            [['order_line_uuid' => 'line0000002', 'quantity' => 2, 'amount' => 200]]
        );
        $repository->insert(
            $this->context,
            $this->refundRow(
                'refund000004',
                'order00003',
                'idem-key-4',
                ['restocked' => true, 'status' => 'pending']
            ),
            [['order_line_uuid' => 'line0000002', 'quantity' => 3, 'amount' => 300]]
        );
        $repository->insert(
            $this->context,
            $this->refundRow(
                'refund000005',
                'order00003',
                'idem-key-5',
                ['restocked' => true, 'status' => 'failed']
            ),
            [['order_line_uuid' => 'line0000002', 'quantity' => 10, 'amount' => 999]]
        );

        $sums = $repository->restockReservedByLine($this->context, '', 'order00003');

        self::assertSame(['line0000002' => 5], $sums);
    }

    public function testAmountSumsSeparatePendingFromReserved(): void
    {
        $repository = new RefundRepository();

        $repository->insert(
            $this->context,
            $this->refundRow('refund000007', 'order00007', 'idem-key-7', ['status' => 'pending', 'amount' => 100]),
            []
        );
        $repository->insert(
            $this->context,
            $this->refundRow('refund000008', 'order00007', 'idem-key-8', ['status' => 'completed', 'amount' => 250]),
            []
        );
        $repository->insert(
            $this->context,
            $this->refundRow('refund000009', 'order00007', 'idem-key-9', ['status' => 'failed', 'amount' => 999]),
            []
        );

        self::assertSame(100, $repository->pendingAmountSum($this->context, '', 'order00007'));
        self::assertSame(350, $repository->reservedAmountSum($this->context, '', 'order00007'));
    }

    public function testSetFailureReasonPersists(): void
    {
        $repository = new RefundRepository();
        $repository->insert($this->context, $this->refundRow('refund000010', 'order00010', 'idem-key-10'), []);

        $repository->setFailureReason($this->context, '', 'refund000010', 'gateway timeout');

        $found = $repository->findByUuid($this->context, '', 'refund000010');
        self::assertNotNull($found);
        self::assertSame('gateway timeout', $found['failure_reason']);
    }

    /** @param array<string,mixed> $overrides */
    private function refundRow(string $uuid, string $orderUuid, string $idempotencyKey, array $overrides = []): array
    {
        return array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'order_uuid' => $orderUuid,
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => md5($idempotencyKey),
            'amount' => 500,
            'currency' => 'USD',
            'method' => 'original',
            'status' => 'pending',
            'reason' => null,
            'restocked' => false,
        ], $overrides);
    }
}
