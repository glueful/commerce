<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Console;

use Glueful\Extensions\Commerce\Cart\CartPruner;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;

final class MaintenanceTest extends CommerceTestCase
{
    public function testCartPrunerAbandonsOnlyExpiredActiveCarts(): void
    {
        $this->connection->table('commerce_carts')->insert([
            'uuid' => 'expired0001',
            'tenant_uuid' => '',
            'token_hash' => str_repeat('a', 64),
            'status' => 'active',
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);
        $this->connection->table('commerce_carts')->insert([
            'uuid' => 'future00001',
            'tenant_uuid' => '',
            'token_hash' => str_repeat('b', 64),
            'status' => 'active',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->connection->table('commerce_carts')->insert([
            'uuid' => 'oldgone001',
            'tenant_uuid' => '',
            'token_hash' => str_repeat('c', 64),
            'status' => 'converted',
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);

        self::assertSame(1, (new CartPruner())->prune($this->context));

        self::assertSame('abandoned', $this->cartStatus('expired0001'));
        self::assertSame('active', $this->cartStatus('future00001'));
        self::assertSame('converted', $this->cartStatus('oldgone001'));
    }

    public function testDiagnosticsReportListsBindingsAndProbes(): void
    {
        $report = DiagnosticsReport::build($this->context);

        self::assertSame(ManualPaymentCollector::class, $report['contracts']['payment_collector']['class']);
        self::assertSame('fallback', $report['contracts']['payment_collector']['source']);
        self::assertFalse($report['tenancy']['enabled']);

        $bound = new ManualPaymentCollector();
        $this->bind(PaymentCollector::class, $bound);
        $report = DiagnosticsReport::build($this->context);

        self::assertSame($bound::class, $report['contracts']['payment_collector']['class']);
        self::assertSame('bound', $report['contracts']['payment_collector']['source']);
    }

    public function testDiagnosticsReportVariantsMissingStockEmptyWhenHealthy(): void
    {
        $report = DiagnosticsReport::build($this->context);

        self::assertSame([], $report['database']['variants_missing_stock']);
    }

    /**
     * Task A4 (single-page product editor plan): a variant seeded directly,
     * bypassing `StockRepository::ensureRow()`, is missing its
     * `commerce_stock` row -- the ordered `{tenant_uuid, product_uuid,
     * variant_uuid}` identity of that drift must appear in the diagnostics
     * report even though the variant was never touched through the normal
     * product-creation path.
     */
    public function testDiagnosticsReportVariantsMissingStockListsOrphanedVariantIdentity(): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => 'diagprod0001',
            'tenant_uuid' => 'tenantdiag01',
            'slug' => 'diagprod0001',
            'name' => 'Diag Product',
            'type' => 'physical',
            'status' => 'active',
        ]);
        $this->connection->table('commerce_variants')->insert([
            'uuid' => 'diagvar00001',
            'tenant_uuid' => 'tenantdiag01',
            'product_uuid' => 'diagprod0001',
            'sku' => 'diagvar00001',
            'option_values' => '[]',
            'price' => 500,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        // Deliberately no matching commerce_stock row.

        $report = DiagnosticsReport::build($this->context);

        self::assertSame(
            [['tenant_uuid' => 'tenantdiag01', 'product_uuid' => 'diagprod0001', 'variant_uuid' => 'diagvar00001']],
            $report['database']['variants_missing_stock']
        );
    }

    private function cartStatus(string $uuid): string
    {
        $row = $this->connection->table('commerce_carts')->where('uuid', '=', $uuid)->first();
        self::assertIsArray($row);

        return (string) $row['status'];
    }
}
