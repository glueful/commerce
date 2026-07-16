<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\CommerceServiceProvider;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tenancy\TenantContextMissingException;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;

final class TenantScopingTest extends CommerceTestCase
{
    public function testEveryRepositoryIsTenantScopedWhenResolved(): void
    {
        $catalogT1 = $this->catalog('tenantAAAA01');
        $catalogT2 = $this->catalog('tenantBBBB02');
        $p1 = $catalogT1->createProduct($this->context, $this->productInput('T1 Tee'));
        $p2 = $catalogT2->createProduct($this->context, $this->productInput('T2 Tee'));

        self::assertSame(
            'T1 Tee',
            (new ProductRepository())->findLiveBySlug($this->context, 'tenantAAAA01', 'tee')['name']
        );
        self::assertSame(
            'T2 Tee',
            (new ProductRepository())->findLiveBySlug($this->context, 'tenantBBBB02', 'tee')['name']
        );

        $variantT1 = (string) $p1['variants'][0]['uuid'];
        $variantT2 = (string) $p2['variants'][0]['uuid'];
        $stock = new StockRepository();
        $stock->increment($this->context, 'tenantAAAA01', $variantT1, 3);
        $stock->increment($this->context, 'tenantBBBB02', $variantT2, 7);
        self::assertSame(3, $stock->quantity($this->context, 'tenantAAAA01', $variantT1));
        self::assertSame(0, $stock->quantity($this->context, 'tenantBBBB02', $variantT1));

        $cartT1 = $this->cart('tenantAAAA01')->create($this->context);
        self::assertNotNull($this->cart('tenantAAAA01')->byToken($this->context, $cartT1['token']));
        self::assertNull($this->cart('tenantBBBB02')->byToken($this->context, $cartT1['token']));

        $discounts = new DiscountRepository();
        $discounts->insert($this->context, $this->discountRow('tenantAAAA01', 'SAVE'));
        $discounts->insert($this->context, $this->discountRow('tenantBBBB02', 'SAVE'));
        self::assertSame('tenantAAAA01', $discounts->findByCode($this->context, 'tenantAAAA01', 'SAVE')['tenant_uuid']);
        self::assertSame('tenantBBBB02', $discounts->findByCode($this->context, 'tenantBBBB02', 'SAVE')['tenant_uuid']);

        $orders = new OrderRepository();
        $orders->insert($this->context, $this->orderRow('tenantAAAA01', 'ORD-1'));
        $orders->insert($this->context, $this->orderRow('tenantBBBB02', 'ORD-1'));
        self::assertSame('tenantAAAA01', $orders->findByNumber($this->context, 'tenantAAAA01', 'ORD-1')['tenant_uuid']);
        self::assertSame('tenantBBBB02', $orders->findByNumber($this->context, 'tenantBBBB02', 'ORD-1')['tenant_uuid']);
    }

    public function testEnabledWithoutABoundResolverIsALoudBootError(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tenancy' => ['enabled' => true]]);

        $this->expectException(\RuntimeException::class);
        CommerceServiceProvider::makeTenantResolver($this->contextContainer(), $this->context);
    }

    public function testBoundResolverReturningEmptyFailsClosedInTenantMode(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tenancy' => ['enabled' => true]]);
        $this->bind(CurrentTenantResolver::class, new SentinelTenantResolver());

        $resolver = CommerceServiceProvider::makeTenantResolver($this->contextContainer(), $this->context);

        $this->expectException(TenantContextMissingException::class);
        $resolver->tenantUuid($this->context);
    }

    private function catalog(string $tenant): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant($tenant),
            new StockRepository()
        );
    }

    private function cart(string $tenant): CartService
    {
        return new CartService(
            new CartRepository(),
            new VariantRepository(),
            new ProductRepository(),
            new StockRepository(),
            new DiscountRepository(),
            new PricingEngine(),
            $this->fixedTenant($tenant)
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

    /** @return array<string,mixed> */
    private function productInput(string $name): array
    {
        return [
            'slug' => 'tee',
            'name' => $name,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => 'TEE',
                'option_values' => [],
                'price' => 100,
                'currency' => 'USD',
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function discountRow(string $tenant, string $code): array
    {
        return [
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $tenant,
            'code' => $code,
            'type' => 'percentage',
            'value' => 100,
            'once_per_buyer' => 0,
            'usage_count' => 0,
            'status' => 'active',
        ];
    }

    /** @return array<string,mixed> */
    private function orderRow(string $tenant, string $number): array
    {
        return [
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $tenant,
            'order_number' => $number,
            'status' => 'pending_payment',
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('f', 64),
            'currency' => 'USD',
            'subtotal' => 100,
            'grand_total' => 100,
        ];
    }
}
