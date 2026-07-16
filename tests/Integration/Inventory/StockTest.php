<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Inventory;

use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Validation\ValidationException;

final class StockTest extends CommerceTestCase
{
    public function testConditionalDecrementIsOversellSafe(): void
    {
        $repository = new StockRepository();
        $repository->ensureRow($this->context, '', 'var000000001', true);
        $repository->increment($this->context, '', 'var000000001', 1);

        self::assertTrue($repository->decrement($this->context, '', 'var000000001', 1));
        self::assertFalse($repository->decrement($this->context, '', 'var000000001', 1));
        self::assertSame(0, $repository->quantity($this->context, '', 'var000000001'));
    }

    public function testAdjustWritesLedger(): void
    {
        $repository = new StockRepository();
        $repository->ensureRow($this->context, '', 'var000000002', true);
        $service = new InventoryService($repository, new SentinelTenantResolver());

        $quantity = $service->adjust($this->context, 'var000000002', 5, 'restock');

        self::assertSame(5, $quantity);
        $movements = $this->connection->table('commerce_stock_movements')
            ->where('variant_uuid', '=', 'var000000002')
            ->get();
        self::assertCount(1, $movements);
        self::assertSame('restock', $movements[0]['reason']);
    }

    public function testAdjustBelowZeroRejected(): void
    {
        $repository = new StockRepository();
        $repository->ensureRow($this->context, '', 'var000000003', true);

        $this->expectException(ValidationException::class);

        (new InventoryService($repository, new SentinelTenantResolver()))
            ->adjust($this->context, 'var000000003', -1);
    }

    public function testEnsureRowAcceptsOnlyAnIdempotentDuplicate(): void
    {
        $repository = new StockRepository();
        $repository->ensureRow($this->context, '', 'var000000004', true);

        $repository->ensureRow($this->context, '', 'var000000004', true);

        self::assertTrue($repository->isTracked($this->context, '', 'var000000004'));
        self::assertSame(1, $this->connection->table('commerce_stock')
            ->where('variant_uuid', '=', 'var000000004')
            ->count());
    }

    public function testEnsureRowRejectsAConflictingTrackingMode(): void
    {
        $repository = new StockRepository();
        $repository->ensureRow($this->context, '', 'var000000005', false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Existing stock tracking mode does not match the variant.');

        $repository->ensureRow($this->context, '', 'var000000005', true);
    }
}
