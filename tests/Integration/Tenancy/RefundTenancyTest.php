<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminRefundController;
use Glueful\Extensions\Commerce\Http\DTOs\CreateOrderNoteData;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundInput;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\RefundResult;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Two real tenants (not the '' sentinel) proving refunds, notes, and invoice data never
 * leak across a tenant boundary, and that idempotency uniqueness is per-tenant, not global.
 * Follows the `tenantAAAA01`/`tenantBBBB02` fixed-resolver convention established in
 * {@see TenantScopingTest}, and the direct-row-seeding style already used by
 * `GatewayRefundTest` for its own single cross-tenant `settle()` case.
 */
final class RefundTenancyTest extends CommerceTestCase
{
    private const TENANT_A = 'tenantAAAA01';
    private const TENANT_B = 'tenantBBBB02';

    public function testTenantBCannotIssueOrReplayARefundAgainstTenantAsOrder(): void
    {
        $orderUuid = Utils::generateNanoID();
        $this->seedOrder(self::TENANT_A, $orderUuid, 1000);

        $refundA = $this->refundService(self::TENANT_A)->issue(
            $this->context,
            $orderUuid,
            new RefundInput(null, 'tenant A original', [], false),
            'idem-tenant-a-issue-1'
        );
        self::assertSame('completed', $refundA['status']);

        // Tenant B attempts to issue against the same order uuid, replaying the exact
        // idempotency key tenant A already used. Tenant B's own idempotency lookup misses
        // (the unique constraint is (tenant_uuid, idempotency_key)), so it falls through to
        // the claim -- which fails because the order does not belong to tenant B.
        $this->expectException(NotFoundException::class);
        $this->refundService(self::TENANT_B)->issue(
            $this->context,
            $orderUuid,
            new RefundInput(null, 'tenant A original', [], false),
            'idem-tenant-a-issue-1'
        );
    }

    public function testTenantBCannotSettleTenantAsPendingRefund(): void
    {
        $orderUuid = Utils::generateNanoID();
        $this->seedOrder(self::TENANT_A, $orderUuid, 1000);

        $pendingUuid = Utils::generateNanoID();
        (new RefundRepository())->insert($this->context, [
            'uuid' => $pendingUuid,
            'tenant_uuid' => self::TENANT_A,
            'order_uuid' => $orderUuid,
            'idempotency_key' => 'idem-tenant-a-settle-1',
            'request_fingerprint' => md5('idem-tenant-a-settle-1'),
            'amount' => 500,
            'currency' => 'USD',
            'method' => 'gateway',
            'status' => 'pending',
            'reason' => null,
            'restocked' => false,
        ], []);

        $this->expectException(NotFoundException::class);
        $this->refundService(self::TENANT_B)->settle(
            $this->context,
            $pendingUuid,
            new RefundResult(RefundResult::COMPLETED)
        );
    }

    public function testTenantBCannotListTenantAsRefundsAtTheEndpoint(): void
    {
        $orderUuid = Utils::generateNanoID();
        $this->seedOrder(self::TENANT_A, $orderUuid, 1000);
        $this->refundService(self::TENANT_A)->issue(
            $this->context,
            $orderUuid,
            new RefundInput(300, 'tenant A partial', [], false),
            'idem-tenant-a-list-1'
        );

        $this->expectException(NotFoundException::class);
        $this->refundController(self::TENANT_B)->index(
            Request::create('/commerce/admin/orders/' . $orderUuid . '/refunds', 'GET'),
            $orderUuid
        );
    }

    public function testTenantBCannotFetchInvoiceDataForTenantAsOrder(): void
    {
        $orderUuid = Utils::generateNanoID();
        $this->seedOrder(self::TENANT_A, $orderUuid, 1000);
        $this->refundService(self::TENANT_A)->issue(
            $this->context,
            $orderUuid,
            new RefundInput(300, 'tenant A partial', [], false),
            'idem-tenant-a-invoice-1'
        );

        $this->expectException(NotFoundException::class);
        $this->orderController(self::TENANT_B)->invoiceData(
            Request::create('/commerce/admin/orders/' . $orderUuid . '/invoice-data', 'GET'),
            $orderUuid
        );
    }

    public function testTenantBCannotReadTenantAsOrderNotesViaShow(): void
    {
        $orderUuid = Utils::generateNanoID();
        $this->seedOrder(self::TENANT_A, $orderUuid, 1000);
        $this->orderController(self::TENANT_A)->addNote(
            new CreateOrderNoteData(body: 'internal note for tenant A', visibility: 'internal'),
            Request::create('/commerce/admin/orders/' . $orderUuid . '/notes', 'POST'),
            $orderUuid
        );

        $this->expectException(NotFoundException::class);
        $this->orderController(self::TENANT_B)->show(
            Request::create('/commerce/admin/orders/' . $orderUuid, 'GET'),
            $orderUuid
        );
    }

    public function testTenantBCannotAddANoteToTenantAsOrder(): void
    {
        $orderUuid = Utils::generateNanoID();
        $this->seedOrder(self::TENANT_A, $orderUuid, 1000);

        try {
            $this->orderController(self::TENANT_B)->addNote(
                new CreateOrderNoteData(body: 'sneaky cross-tenant note', visibility: 'internal'),
                Request::create('/commerce/admin/orders/' . $orderUuid . '/notes', 'POST'),
                $orderUuid
            );
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            self::assertNull(
                $this->connection->table('commerce_order_events')
                    ->where('order_uuid', '=', $orderUuid)
                    ->where('type', '=', 'note.added')
                    ->first(),
                'No note event should be recorded when the order belongs to a different tenant.'
            );
        }
    }

    public function testSameIdempotencyKeyCreatesIndependentRefundsPerTenant(): void
    {
        $orderAUuid = Utils::generateNanoID();
        $orderBUuid = Utils::generateNanoID();
        $this->seedOrder(self::TENANT_A, $orderAUuid, 1000);
        $this->seedOrder(self::TENANT_B, $orderBUuid, 1000);
        $sharedKey = 'idem-shared-across-tenants-1';

        $refundA = $this->refundService(self::TENANT_A)->issue(
            $this->context,
            $orderAUuid,
            new RefundInput(null, 'tenant A refund', [], false),
            $sharedKey
        );
        $refundB = $this->refundService(self::TENANT_B)->issue(
            $this->context,
            $orderBUuid,
            new RefundInput(null, 'tenant B refund', [], false),
            $sharedKey
        );

        self::assertSame('completed', $refundA['status']);
        self::assertSame('completed', $refundB['status']);
        self::assertNotSame($refundA['uuid'], $refundB['uuid'], 'Each tenant must get its own, independent refund.');

        $repo = new RefundRepository();
        $lookupA = $repo->findByIdempotencyKey($this->context, self::TENANT_A, $sharedKey);
        $lookupB = $repo->findByIdempotencyKey($this->context, self::TENANT_B, $sharedKey);
        self::assertNotNull($lookupA);
        self::assertNotNull($lookupB);
        self::assertSame($refundA['uuid'], $lookupA['uuid']);
        self::assertSame($refundB['uuid'], $lookupB['uuid']);
        self::assertSame(self::TENANT_A, $lookupA['tenant_uuid']);
        self::assertSame(self::TENANT_B, $lookupB['tenant_uuid']);

        // Sanity: both rows really exist side by side under the shared key text.
        self::assertSame(
            2,
            $this->connection->table('commerce_refunds')
                ->where('idempotency_key', '=', $sharedKey)
                ->count()
        );
    }

    public function testDiagnosticsAndAdopterCoverCommerceRefunds(): void
    {
        self::assertContains('commerce_refunds', DiagnosticsReport::tenantTables());
        // commerce_refund_lines carries no tenant column by design (see class docs on
        // RefundRepository::linesFor()) -- it must never be treated as a tenant table.
        self::assertNotContains('commerce_refund_lines', DiagnosticsReport::tenantTables());

        $sentinelUuid = Utils::generateNanoID();
        (new RefundRepository())->insert($this->context, [
            'uuid' => $sentinelUuid,
            'tenant_uuid' => '',
            'order_uuid' => Utils::generateNanoID(),
            'idempotency_key' => 'idem-sentinel-adopt-1',
            'request_fingerprint' => md5('idem-sentinel-adopt-1'),
            'amount' => 500,
            'currency' => 'USD',
            'method' => 'manual',
            'status' => 'completed',
            'reason' => null,
            'restocked' => false,
        ], []);

        $result = (new TenantAdopter())->adopt($this->context, self::TENANT_A);

        self::assertArrayHasKey('commerce_refunds', $result['tables']);
        self::assertSame(1, $result['tables']['commerce_refunds']);

        $adopted = $this->connection->table('commerce_refunds')->where('uuid', '=', $sentinelUuid)->first();
        self::assertNotNull($adopted);
        self::assertSame(self::TENANT_A, $adopted['tenant_uuid']);
    }

    private function seedOrder(string $tenant, string $uuid, int $grandTotal, string $status = 'paid'): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => $grandTotal,
            'grand_total' => $grandTotal,
        ]);
    }

    private function refundService(string $tenant): RefundService
    {
        return new RefundService(
            new OrderRepository(),
            new RefundRepository(),
            new StockRepository(),
            $this->fixedTenant($tenant)
        );
    }

    private function refundController(string $tenant): AdminRefundController
    {
        return new AdminRefundController(
            $this->context,
            new OrderRepository(),
            new RefundRepository(),
            $this->refundService($tenant),
            $this->fixedTenant($tenant)
        );
    }

    private function orderController(string $tenant): AdminOrderController
    {
        return new AdminOrderController(
            $this->context,
            new OrderRepository(),
            new StockRepository(),
            new OrderPaymentService(new OrderRepository()),
            $this->fixedTenant($tenant),
            new RefundRepository(),
            new ConfigSellerIdentityProvider()
        );
    }

    private function fixedTenant(string $tenant): CurrentTenantResolver
    {
        return new class ($tenant) implements CurrentTenantResolver {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
    }
}
