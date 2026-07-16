<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Customers\AddressBookRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\DTOs\CheckoutPlaceData;
use Glueful\Extensions\Commerce\Http\Storefront\CheckoutController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Checkout's optional address-book integration (design spec §7): resolving
 * `shipping_address_uuid`/`billing_address_uuid` into the exact same inline
 * `{shipping, billing}` shape `CheckoutService::placeOrder()` already
 * accepted -- the resolved address is snapshotted verbatim into
 * `orders.addresses`, never a reference back to the book. Authenticated-only,
 * owner-only, same-kind-ambiguous-is-422, cross-kind-mixing-is-fine.
 */
final class CheckoutAddressBookTest extends CommerceTestCase
{
    public function testResolvesShippingUuidAndSnapshotsTheExactAddressJson(): void
    {
        $addressJson = ['country' => 'US', 'line1' => '1 Main St'];
        $this->seedAddress('addrchkout01', 'usercheckout1', $addressJson);
        [$token] = $this->seedCartWithLine('SKU-ADR-1');

        $response = $this->place($token, 'usercheckout1', [
            'shipping_address_uuid' => 'addrchkout01',
            'addresses' => ['billing' => ['country' => 'US']],
        ]);
        $body = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($addressJson, $body['data']['order']['addresses']['shipping']);
        self::assertSame(['country' => 'US'], $body['data']['order']['addresses']['billing']);
    }

    public function testCrossKindMixingIsAllowed(): void
    {
        $shippingJson = ['country' => 'US'];
        $billingJson = ['country' => 'CA'];
        $this->seedAddress('addrchkout02', 'usercheckout2', $shippingJson);
        $this->seedAddress('addrchkout03', 'usercheckout2', $billingJson, isDefaultBilling: false);
        [$token] = $this->seedCartWithLine('SKU-ADR-2');

        $response = $this->place($token, 'usercheckout2', [
            'shipping_address_uuid' => 'addrchkout02',
            'billing_address_uuid' => 'addrchkout03',
            'addresses' => [],
        ]);
        $body = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($shippingJson, $body['data']['order']['addresses']['shipping']);
        self::assertSame($billingJson, $body['data']['order']['addresses']['billing']);
    }

    public function testCrossUserAddressUuidReturns422(): void
    {
        $this->seedAddress('addrchkout04', 'usercheckout3a', ['country' => 'US']);
        [$token] = $this->seedCartWithLine('SKU-ADR-3');

        $response = $this->place($token, 'usercheckout3b', [
            'shipping_address_uuid' => 'addrchkout04',
            'addresses' => ['billing' => ['country' => 'US']],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('shipping_address_uuid', $this->json($response)['error']['details']);
    }

    public function testUnauthenticatedRequestWithUuidReturns422(): void
    {
        $this->seedAddress('addrchkout05', 'usercheckout4', ['country' => 'US']);
        [$token] = $this->seedCartWithLine('SKU-ADR-4');

        $response = $this->place($token, null, [
            'shipping_address_uuid' => 'addrchkout05',
            'addresses' => ['billing' => ['country' => 'US']],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('shipping_address_uuid', $this->json($response)['error']['details']);
    }

    public function testSameKindUuidPlusInlineIsAmbiguousAndReturns422(): void
    {
        $this->seedAddress('addrchkout06', 'usercheckout5', ['country' => 'US']);
        [$token] = $this->seedCartWithLine('SKU-ADR-5');

        $response = $this->place($token, 'usercheckout5', [
            'shipping_address_uuid' => 'addrchkout06',
            'addresses' => ['shipping' => ['country' => 'CA'], 'billing' => ['country' => 'US']],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('shipping_address_uuid', $this->json($response)['error']['details']);
    }

    public function testInlineOnlyCheckoutIsUnchanged(): void
    {
        [$token] = $this->seedCartWithLine('SKU-ADR-6');

        $response = $this->place($token, null, [
            'addresses' => ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
        ]);
        $body = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['country' => 'US'], $body['data']['order']['addresses']['shipping']);
        self::assertSame(['country' => 'US'], $body['data']['order']['addresses']['billing']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $overrides merged into the JSON body ('buyer'/'shipping_method' default) */
    private function place(string $cartToken, ?string $userUuid, array $overrides): HttpResponse
    {
        $payload = array_merge([
            'buyer' => ['email' => 'buyer@example.com'],
            'addresses' => [],
            'shipping_method' => 'std',
        ], $overrides);

        $request = Request::create(
            '/commerce/checkout',
            'POST',
            [],
            [],
            [],
            [],
            (string) json_encode($payload, JSON_THROW_ON_ERROR)
        );
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-Cart-Token', $cartToken);
        if ($userUuid !== null) {
            $request->attributes->set('user', ['uuid' => $userUuid]);
        }

        $input = new CheckoutPlaceData(
            buyer: $payload['buyer'],
            addresses: $payload['addresses'],
            shipping_method: $payload['shipping_method'],
            shipping_address_uuid: $overrides['shipping_address_uuid'] ?? null,
            billing_address_uuid: $overrides['billing_address_uuid'] ?? null,
        );

        return $this->checkoutController()->place($input, $request);
    }

    /** @param array<string,mixed> $address */
    private function seedAddress(
        string $uuid,
        string $userUuid,
        array $address,
        bool $isDefaultShipping = false,
        bool $isDefaultBilling = false,
        string $tenant = '',
    ): void {
        $this->connection->table('commerce_customer_addresses')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'user_uuid' => $userUuid,
            'label' => null,
            'address' => json_encode($address, JSON_THROW_ON_ERROR),
            'is_default_shipping' => $isDefaultShipping,
            'is_default_billing' => $isDefaultBilling,
        ]);
    }

    private function checkoutController(): CheckoutController
    {
        return new CheckoutController(
            $this->context,
            $this->cart(),
            $this->checkout(),
            new AddressBookRepository(),
            new SentinelTenantResolver()
        );
    }

    private function checkout(): CheckoutService
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
            new SentinelTenantResolver()
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
    private function seedCartWithLine(string $sku, int $stock = 5, int $quantity = 1, int $price = 1000): array
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

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
