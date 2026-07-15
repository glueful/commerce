<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Cart;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Validation\ValidationException;

final class CartService
{
    public function __construct(
        private CartRepository $carts,
        private VariantRepository $variants,
        private ProductRepository $products,
        private StockRepository $stock,
        private DiscountRepository $discounts,
        private PricingEngine $pricing,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /** @return array{cart: array<string,mixed>, token: string} */
    public function create(ApplicationContext $context): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $token = TokenHasher::generate();
        $uuid = Utils::generateNanoID();
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . (int) config($context, 'commerce.cart.ttl_days', 30) . ' days')
            ->format('Y-m-d H:i:s');

        $this->carts->insert($context, [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'token_hash' => $token['hash'],
            'status' => 'active',
            'expires_at' => $expiresAt,
        ]);

        $cart = $this->carts->findByUuid($context, $tenant, $uuid);
        if ($cart === null) {
            throw new \RuntimeException('Created cart could not be reloaded.');
        }

        return ['cart' => $cart, 'token' => $token['raw']];
    }

    /** @return array<string,mixed>|null */
    public function byToken(ApplicationContext $context, string $rawToken): ?array
    {
        return $this->carts->findActiveByTokenHash(
            $context,
            $this->tenants->tenantUuid($context),
            TokenHasher::hash($rawToken)
        );
    }

    /** @param array<string,mixed> $cart @return array<string,mixed> */
    public function addLine(ApplicationContext $context, array $cart, string $variantUuid, int $quantity): array
    {
        if ($quantity <= 0) {
            throw ValidationException::forField('quantity', 'Quantity must be greater than zero.');
        }

        $tenant = $this->tenants->tenantUuid($context);
        $this->assertVariantCanSupply(
            $context,
            $tenant,
            $variantUuid,
            $this->lineQuantity($context, $cart, $variantUuid) + $quantity
        );

        $line = $this->carts->findLineByVariant($context, (string) $cart['uuid'], $variantUuid);
        if ($line === null) {
            $this->carts->insertLine($context, (string) $cart['uuid'], $variantUuid, $quantity);
        } else {
            $this->carts->setLineQuantity($context, (string) $line['uuid'], (int) $line['quantity'] + $quantity);
        }

        return $this->reloadCart($context, $tenant, (string) $cart['uuid']);
    }

    /** @param array<string,mixed> $cart @return array<string,mixed> */
    public function setLineQuantity(ApplicationContext $context, array $cart, string $lineUuid, int $quantity): array
    {
        $line = $this->carts->findLine($context, $lineUuid);
        if ($line === null || $line['cart_uuid'] !== $cart['uuid']) {
            throw ValidationException::forField('line_uuid', 'Cart line not found.');
        }

        $tenant = $this->tenants->tenantUuid($context);
        if ($quantity <= 0) {
            $this->carts->deleteLine($context, $lineUuid);
        } else {
            $this->assertVariantCanSupply($context, $tenant, (string) $line['variant_uuid'], $quantity);
            $this->carts->setLineQuantity($context, $lineUuid, $quantity);
        }

        return $this->reloadCart($context, $tenant, (string) $cart['uuid']);
    }

    /** @param array<string,mixed> $cart @return array<string,mixed> */
    public function applyDiscount(ApplicationContext $context, array $cart, string $code): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $discount = $this->discounts->findByCode($context, $tenant, $code);
        if ($discount === null) {
            throw ValidationException::forField('discount_code', 'Discount not found.');
        }

        $lines = $this->pricedLines($context, $cart);
        $subtotal = (new PricingEngine())->discountableBase($lines, null);
        (new DiscountService($this->discounts, $this->tenants))
            ->validateForCart($context, $discount, $subtotal, $lines);
        $this->carts->update($context, $tenant, (string) $cart['uuid'], ['discount_code' => $code]);

        return $this->reloadCart($context, $tenant, (string) $cart['uuid']);
    }

    /** @param array<string,mixed> $cart @return array<string,mixed> */
    public function removeDiscount(ApplicationContext $context, array $cart): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $this->carts->update($context, $tenant, (string) $cart['uuid'], ['discount_code' => null]);

        return $this->reloadCart($context, $tenant, (string) $cart['uuid']);
    }

    /** @return array<string,mixed> */
    public function mergeIntoUser(ApplicationContext $context, string $rawGuestToken, string $userUuid): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $guest = $this->byToken($context, $rawGuestToken);
        if ($guest === null) {
            throw ValidationException::forField('cart_token', 'Cart not found.');
        }

        $userCart = $this->carts->findActiveForUser($context, $tenant, $userUuid);
        if ($userCart === null) {
            $this->carts->update($context, $tenant, (string) $guest['uuid'], ['user_uuid' => $userUuid]);
            return $this->reloadCart($context, $tenant, (string) $guest['uuid']);
        }

        foreach ($this->carts->lines($context, (string) $guest['uuid']) as $line) {
            $this->mergeLine($context, $tenant, $userCart, $line);
        }

        $this->carts->update($context, $tenant, (string) $guest['uuid'], ['status' => 'abandoned']);

        return $this->reloadCart($context, $tenant, (string) $userCart['uuid']);
    }

    /** @param array<string,mixed> $cart @return array<string,mixed> */
    public function view(ApplicationContext $context, array $cart): array
    {
        $lines = $this->pricedLines($context, $cart);
        $discount = null;
        if (is_string($cart['discount_code'] ?? null) && $cart['discount_code'] !== '') {
            $discount = $this->discounts->findByCode(
                $context,
                $this->tenants->tenantUuid($context),
                (string) $cart['discount_code']
            );
        }

        return [
            'cart' => $cart,
            'lines' => $lines,
            'totals' => $this->pricing->price($lines, $discount, null, null),
        ];
    }

    /** @param array<string,mixed> $cart @return list<array<string,mixed>> */
    public function pricedLines(ApplicationContext $context, array $cart): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $priced = [];
        foreach ($this->carts->lines($context, (string) $cart['uuid']) as $line) {
            $variant = $this->variants->findByUuid($context, $tenant, (string) $line['variant_uuid']);
            if ($variant === null) {
                continue;
            }
            $product = $this->products->findByUuid($context, $tenant, (string) $variant['product_uuid']);
            if ($product === null) {
                continue;
            }

            $priced[] = [
                'product_uuid' => (string) $product['uuid'],
                'variant_uuid' => (string) $variant['uuid'],
                'unit_price' => (int) $variant['price'],
                'currency' => (string) $variant['currency'],
                'quantity' => (int) $line['quantity'],
                'sku' => (string) $variant['sku'],
                'product_name' => (string) $product['name'],
                'option_values' => $variant['option_values'] ?? [],
                'type' => (string) ($product['type'] ?? 'physical'),
            ];
        }

        return $priced;
    }

    /** @param array<string,mixed> $cart */
    private function lineQuantity(ApplicationContext $context, array $cart, string $variantUuid): int
    {
        $line = $this->carts->findLineByVariant($context, (string) $cart['uuid'], $variantUuid);

        return $line === null ? 0 : (int) $line['quantity'];
    }

    /**
     * Defense in depth: even though CatalogService now blocks variant creation
     * for external/grouped products outright, a variant referencing one of them
     * could still exist (e.g. seeded directly, or a future code path). Reject it
     * here too, naming the offending type, rather than trusting the catalog side
     * alone.
     */
    private function assertVariantCanSupply(
        ApplicationContext $context,
        string $tenant,
        string $variantUuid,
        int $quantity,
    ): void {
        $variant = $this->variants->findByUuid($context, $tenant, $variantUuid);
        if ($variant === null) {
            throw ValidationException::forField('variant_uuid', 'Variant not found.');
        }

        $product = $this->products->findByUuid($context, $tenant, (string) $variant['product_uuid']);
        $type = $product !== null ? (string) ($product['type'] ?? 'physical') : 'physical';
        if (!in_array($type, ['physical', 'digital'], true)) {
            throw ValidationException::forField(
                'variant_uuid',
                "Products of type '{$type}' cannot be purchased."
            );
        }

        if (
            $this->stock->isTracked($context, $tenant, $variantUuid)
            && $quantity > $this->stock->quantity($context, $tenant, $variantUuid)
        ) {
            throw ValidationException::forField('quantity', 'Requested quantity exceeds available stock.');
        }
    }

    /**
     * @param array<string,mixed> $userCart
     * @param array<string,mixed> $guestLine
     */
    private function mergeLine(ApplicationContext $context, string $tenant, array $userCart, array $guestLine): void
    {
        $variantUuid = (string) $guestLine['variant_uuid'];
        $existing = $this->carts->findLineByVariant($context, (string) $userCart['uuid'], $variantUuid);
        $target = (int) $guestLine['quantity'] + ($existing === null ? 0 : (int) $existing['quantity']);
        if ($this->stock->isTracked($context, $tenant, $variantUuid)) {
            $target = min($target, $this->stock->quantity($context, $tenant, $variantUuid));
        }

        if ($existing === null) {
            if ($target > 0) {
                $this->carts->insertLine($context, (string) $userCart['uuid'], $variantUuid, $target);
            }
            return;
        }

        $this->carts->setLineQuantity($context, (string) $existing['uuid'], $target);
    }

    /** @return array<string,mixed> */
    private function reloadCart(ApplicationContext $context, string $tenant, string $cartUuid): array
    {
        $cart = $this->carts->findByUuid($context, $tenant, $cartUuid);
        if ($cart === null) {
            throw new \RuntimeException('Cart could not be reloaded.');
        }

        return $cart;
    }
}
