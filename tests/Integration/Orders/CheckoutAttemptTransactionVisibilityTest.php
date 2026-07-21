<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptContext;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Transaction-visibility proof (design spec §7, Slice-2 Task 3, RED case 5):
 * a fake {@see \Glueful\Extensions\Commerce\Orders\CheckoutAttemptAuthority}
 * reading on a SECOND, independent database connection during placement
 * cannot see the order before `placeOrder()`'s transaction commits. This is
 * a genuinely separate PHP TEST CLASS (not just a method) because it is the
 * one case in this slice that needs two independent connections open on the
 * SAME underlying data -- `CommerceTestCase`'s default `:memory:` SQLite
 * database is private per-connection (two `:memory:` connections never share
 * data at all), so this class overrides {@see CommerceTestCase::sqlitePath()}
 * to point the whole test at a real temp file instead. No genuine OS-level
 * concurrency is needed (unlike the pgsql-gated race harnesses elsewhere in
 * this suite): PHP has no threads, so the fake authority's `complete()` --
 * called synchronously, INSIDE the still-open placement transaction -- can
 * simply run the second connection's read itself and the ordering is exact
 * and deterministic by construction.
 */
final class CheckoutAttemptTransactionVisibilityTest extends CommerceTestCase
{
    private string $dbFile;

    protected function sqlitePath(): string
    {
        if (!isset($this->dbFile)) {
            $this->dbFile = sys_get_temp_dir() . '/commerce_checkout_attempt_visibility_'
                . bin2hex(random_bytes(6)) . '.sqlite';
            @unlink($this->dbFile);
        }

        return $this->dbFile;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->dbFile) && file_exists($this->dbFile)) {
            @unlink($this->dbFile);
        }
    }

    public function testASecondConnectionCannotObserveTheOrderBeforeCommitButCanAfter(): void
    {
        $reader = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->sqlitePath()],
            'pooling' => ['enabled' => false],
        ]);

        [$token] = $this->seedCartWithLine('SKU-ATT-VIS', 3, 1, 1000);
        $authority = new VisibilityProbeCheckoutAttemptAuthority($reader);
        $attempt = new CheckoutAttemptContext('idem-visibility', 'fingerprint-a');

        $placed = $this->checkout($authority)
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std', $attempt);

        self::assertTrue($authority->observed, 'the authority never ran its during-transaction probe read');
        self::assertNull(
            $authority->duringTransactionOrderRow,
            'a second connection must not observe the order row before the placement transaction commits'
        );

        $afterCommit = $reader->table('commerce_orders')
            ->where('uuid', '=', $placed['order']['uuid'])
            ->first();
        self::assertNotNull($afterCommit, 'the order must be visible on the second connection once committed');
        self::assertSame($placed['order']['uuid'], $afterCommit['uuid']);
    }

    // --- Helpers -------------------------------------------------------

    private function checkout(VisibilityProbeCheckoutAttemptAuthority $authority): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), new SentinelTenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->shipping(),
            $this->tax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            new SentinelTenantResolver(),
            attemptAuthority: $authority
        );
    }

    private function cart(): CartService
    {
        return new CartService(
            new CartRepository(),
            new VariantRepository(),
            new ProductRepository(),
            new StockRepository(),
            new DiscountRepository(),
            new PricingEngine(),
            new SentinelTenantResolver()
        );
    }

    /** @return array{0: string, 1: string} */
    private function seedCartWithLine(string $sku, int $stock, int $quantity, int $price): array
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => strtolower($sku),
            'name' => $sku,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => $sku,
                'option_values' => [],
                'price' => $price,
                'currency' => 'USD',
            ]],
        ]);
        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, '', $variantUuid, $stock);
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, $variantUuid, $quantity);

        return [$token, $variantUuid];
    }

    /** @return array{email: string, user_uuid: null} */
    private function buyer(): array
    {
        return ['email' => 'buyer@example.com', 'user_uuid' => null];
    }

    /** @return array{shipping: array{country: string}, billing: array{country: string}} */
    private function addresses(): array
    {
        return ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']];
    }

    private function shipping(): ShippingRateProvider
    {
        return new class implements ShippingRateProvider {
            public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
            {
                return [new ShippingQuote('std', 'Standard', 500)];
            }
        };
    }

    private function tax(): TaxCalculator
    {
        return new class implements TaxCalculator {
            public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
            {
                return new TaxQuote(0);
            }
        };
    }
}
