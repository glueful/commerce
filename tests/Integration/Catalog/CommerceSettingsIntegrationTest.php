<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Support\CommerceSettingsOverride;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Store-settings spec §6: the seam reaches REAL flows — a bound override's currency is what
 * product creation stamps onto variants (CatalogService::storeCurrency), and
 * VariantRepository::anyExistsForTenant is the currency lock's exact predicate.
 */
final class CommerceSettingsIntegrationTest extends CommerceTestCase
{
    public function testBoundOverrideCurrencyReachesVariantCreation(): void
    {
        $this->bindings[CommerceSettingsOverride::class] = new class implements CommerceSettingsOverride {
            public function value(ApplicationContext $context, string $key): ?string
            {
                return $key === 'commerce.currency' ? 'GHS' : null;
            }
        };

        $product = $this->catalogService()->createProduct($this->context, [
            'slug' => 'override-currency-prod',
            'name' => 'Override currency prod',
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => 'sku-override-currency',
                'price' => 1999,
                // No explicit currency: the store default — i.e. the OVERRIDE — must apply.
                'option_values' => [],
            ]],
        ]);

        $variant = (new VariantRepository())
            ->forProduct($this->context, '', (string) $product['uuid'])[0] ?? null;
        self::assertNotNull($variant);
        self::assertSame('GHS', $variant['currency']);
    }

    public function testAnyExistsForTenantProbesExactlyThisTenant(): void
    {
        $repo = new VariantRepository();
        self::assertFalse($repo->anyExistsForTenant($this->context, ''));

        $this->connection->table('commerce_products')->insert([
            'uuid' => 'setexistp001',
            'tenant_uuid' => 'other-tenant',
            'slug' => 'setexistp001',
            'name' => 'Other tenant product',
            'type' => 'physical',
            'status' => 'active',
        ]);
        $this->connection->table('commerce_variants')->insert([
            'uuid' => 'setexistv001',
            'tenant_uuid' => 'other-tenant',
            'product_uuid' => 'setexistp001',
            'sku' => 'setexistv001',
            'option_values' => '[]',
            'price' => 500,
            'currency' => 'USD',
            'position' => 0,
            'status' => 'active',
        ]);

        // Another tenant's variant must not trip THIS tenant's lock.
        self::assertFalse($repo->anyExistsForTenant($this->context, ''));
        self::assertTrue($repo->anyExistsForTenant($this->context, 'other-tenant'));

        $this->connection->table('commerce_variants')->insert([
            'uuid' => 'setexistv002',
            'tenant_uuid' => '',
            'product_uuid' => 'setexistp001',
            'sku' => 'setexistv002',
            'option_values' => '[]',
            'price' => 500,
            'currency' => 'USD',
            'position' => 0,
            'status' => 'active',
        ]);
        self::assertTrue($repo->anyExistsForTenant($this->context, ''));
    }

    public function testReassignCurrencyRewritesOnlyThisTenantsVariantCodes(): void
    {
        $repo = new VariantRepository();
        $this->seedVariantRow('setcurp00001', 'setcurv00001', '', 'USD');
        $this->seedVariantRow('setcurp00002', 'setcurv00002', 'other-tenant', 'USD');

        $repo->reassignCurrencyForTenant($this->context, '', 'GHS');

        $mine = $this->connection->table('commerce_variants')->where(['uuid' => 'setcurv00001'])->first();
        $theirs = $this->connection->table('commerce_variants')->where(['uuid' => 'setcurv00002'])->first();
        self::assertSame('GHS', $mine['currency']);
        // Amounts untouched — reinterpretation, never conversion.
        self::assertSame(500, (int) $mine['price']);
        // Another tenant's rows are never rewritten.
        self::assertSame('USD', $theirs['currency']);
    }

    public function testOrderProbeAnswersPerTenant(): void
    {
        $orders = new \Glueful\Extensions\Commerce\Orders\OrderRepository();
        self::assertFalse($orders->anyExistsForTenant($this->context, ''));

        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'setord000001',
            'tenant_uuid' => 'other-tenant',
            'order_number' => 'ORD-setord000001',
            'status' => 'paid',
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 500,
            'grand_total' => 500,
        ]);
        self::assertFalse($orders->anyExistsForTenant($this->context, ''));
        self::assertTrue($orders->anyExistsForTenant($this->context, 'other-tenant'));
    }

    /** Seeds a product + one variant row directly (bypasses services — shape only). */
    private function seedVariantRow(string $productUuid, string $variantUuid, string $tenant, string $currency): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($productUuid),
            'name' => $productUuid,
            'type' => 'physical',
            'status' => 'active',
        ]);
        $this->connection->table('commerce_variants')->insert([
            'uuid' => $variantUuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'sku' => $variantUuid,
            'option_values' => '[]',
            'price' => 500,
            'currency' => $currency,
            'position' => 0,
            'status' => 'active',
        ]);
    }

    private function catalogService(): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository(),
            new ProductChildrenRepository(),
        );
    }
}
