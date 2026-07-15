<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Cart;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Validation\ValidationException;

/**
 * Add-ons (design spec §4): every cart line carries a canonical, immutable
 * {@see AddonSnapshot} plus its `addons_hash`. `addLine()` builds a fresh snapshot
 * from the product's ACTIVE addon definitions and the variant's current price;
 * `pricedLines()` NEVER re-resolves definitions -- it reads only the already-
 * PERSISTED snapshot on each line, so a later definition edit can never change an
 * existing line's price. Line identity (find-existing / guest→user merge) is
 * cart + variant + hash: two lines for the same variant with different add-on
 * selections are genuinely different lines, and stock checks aggregate quantity
 * for a variant across every one of its hashes. The legacy no-addons path hashes
 * to `''` and behaves exactly as it did before add-ons existed.
 */
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
        private ?AddonRepository $addons = null,
        private ?ShippingClassRepository $shippingClasses = null,
    ) {
        $this->addons ??= new AddonRepository();
        $this->shippingClasses ??= new ShippingClassRepository();
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

    /**
     * @param array<string,mixed> $cart
     * @param list<array{addon_uuid:string,choice_key?:string,value?:mixed}> $addons
     * @return array<string,mixed>
     */
    public function addLine(
        ApplicationContext $context,
        array $cart,
        string $variantUuid,
        int $quantity,
        array $addons = []
    ): array {
        if ($quantity <= 0) {
            throw ValidationException::forField('quantity', 'Quantity must be greater than zero.');
        }

        $tenant = $this->tenants->tenantUuid($context);
        $this->assertVariantCanSupply(
            $context,
            $tenant,
            $variantUuid,
            $this->carts->totalQuantityForVariant($context, (string) $cart['uuid'], $variantUuid) + $quantity
        );

        $variant = $this->variants->findByUuid($context, $tenant, $variantUuid);
        if ($variant === null) {
            throw ValidationException::forField('variant_uuid', 'Variant not found.');
        }

        ['snapshot' => $snapshot, 'hash' => $hash] = $this->buildAddonSnapshot(
            $context,
            $tenant,
            (string) $variant['product_uuid'],
            (int) $variant['price'],
            $addons
        );

        $line = $this->carts->findLineByVariantAndHash($context, (string) $cart['uuid'], $variantUuid, $hash);
        if ($line === null) {
            $this->carts->insertLine($context, (string) $cart['uuid'], $variantUuid, $quantity, $snapshot, $hash);
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

    /**
     * unit_price = variant price + Σ(price_delta from the PERSISTED snapshot).
     * NEVER re-resolves addon definitions -- a definition edited after a line was
     * added does not change that line's price; only a NEW line (new hash) picks up
     * the edit. Fails closed (throws) if a persisted snapshot computes a negative
     * unit price -- this should be unreachable in practice (build() already
     * enforces the invariant before a snapshot is ever persisted), so surfacing it
     * loudly here is a deliberate defensive backstop against a corrupted row
     * rather than something callers are expected to catch routinely.
     *
     * `line_uuid` (the cart line's own uuid, for deterministic discount-allocation
     * ties, {@see \Glueful\Extensions\Commerce\Tax\DiscountAllocation}), `shipping_class`
     * (the variant's resolved nullable shipping-class slug, for
     * {@see \Glueful\Extensions\Commerce\Shipping\DbShippingRateProvider}'s
     * `per_class_table` pricing), and `tax_class` (the product's resolved
     * tax class, `null` normalizing to `'standard'`, for
     * {@see \Glueful\Extensions\Commerce\Tax\DbTaxCalculator}'s per-line rate
     * selection) are ADDITIVE keys layered onto the existing shape -- nothing
     * else changes, `addons`/`addons_hash` untouched. `tax_class` needs no
     * extra query -- the product row is already fetched to build this line.
     * The shipping-class slug resolution is ONE batched query per cart
     * (mirrors {@see ShippingClassRepository::slugsByUuids()}'s batch
     * pattern), never one lookup per line.
     *
     * @param array<string,mixed> $cart
     * @return list<array<string,mixed>>
     */
    public function pricedLines(ApplicationContext $context, array $cart): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $rows = [];
        foreach ($this->carts->lines($context, (string) $cart['uuid']) as $line) {
            $variant = $this->variants->findByUuid($context, $tenant, (string) $line['variant_uuid']);
            if ($variant === null) {
                continue;
            }
            $product = $this->products->findByUuid($context, $tenant, (string) $variant['product_uuid']);
            if ($product === null) {
                continue;
            }

            $rows[] = ['line' => $line, 'variant' => $variant, 'product' => $product];
        }

        $classUuids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?string => $row['variant']['shipping_class_uuid'] ?? null,
            $rows
        ))));
        $slugsByUuid = $this->shippingClasses->slugsByUuids($context, $tenant, $classUuids);

        $priced = [];
        foreach ($rows as $row) {
            $line = $row['line'];
            $variant = $row['variant'];
            $product = $row['product'];

            $snapshot = is_array($line['addons'] ?? null) ? $line['addons'] : [];
            $unitPrice = (int) $variant['price'] + AddonSnapshot::delta($snapshot);
            if ($unitPrice < 0) {
                throw new AddonValidationException(
                    "Persisted add-on snapshot for cart line '" . (string) $line['uuid']
                    . "' computes a negative unit price."
                );
            }

            $classUuid = $variant['shipping_class_uuid'] ?? null;

            $priced[] = [
                'line_uuid' => (string) $line['uuid'],
                'product_uuid' => (string) $product['uuid'],
                'variant_uuid' => (string) $variant['uuid'],
                'unit_price' => $unitPrice,
                'currency' => (string) $variant['currency'],
                'quantity' => (int) $line['quantity'],
                'sku' => (string) $variant['sku'],
                'product_name' => (string) $product['name'],
                'option_values' => $variant['option_values'] ?? [],
                'type' => (string) ($product['type'] ?? 'physical'),
                'addons' => $snapshot,
                'shipping_class' => $classUuid !== null ? ($slugsByUuid[$classUuid] ?? null) : null,
                'tax_class' => (string) ($product['tax_class'] ?? 'standard'),
            ];
        }

        return $priced;
    }

    /**
     * Builds the canonical snapshot for a fresh selection against the product's
     * ACTIVE addon definitions. Translates the pure {@see AddonValidationException}
     * into the framework-facing {@see ValidationException} so every addLine()
     * failure -- addon-related or not -- surfaces through the same 422 contract.
     *
     * @param list<array{addon_uuid:string,choice_key?:string,value?:mixed}> $addons
     * @return array{snapshot: list<array<string,mixed>>, hash: string}
     */
    private function buildAddonSnapshot(
        ApplicationContext $context,
        string $tenant,
        string $productUuid,
        int $variantPrice,
        array $addons
    ): array {
        $definitions = $this->addons->activeForProduct($context, $tenant, $productUuid);

        try {
            return AddonSnapshot::build($definitions, $addons, $variantPrice);
        } catch (AddonValidationException $e) {
            throw ValidationException::forField('addons', $e->getMessage());
        }
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
     * Guest→user merge combines ONLY equal variant+hash lines (design spec §4):
     * two lines for the same variant with different add-on selections stay
     * separate after the merge, exactly as they were separate lines in the guest
     * cart. The guest line's already-persisted snapshot is copied verbatim into
     * the newly-inserted user-cart line -- never rebuilt -- so a merge can never
     * pick up a definition edit that happened after the guest added the line.
     *
     * @param array<string,mixed> $userCart
     * @param array<string,mixed> $guestLine
     */
    private function mergeLine(ApplicationContext $context, string $tenant, array $userCart, array $guestLine): void
    {
        $variantUuid = (string) $guestLine['variant_uuid'];
        $hash = (string) ($guestLine['addons_hash'] ?? '');
        $existing = $this->carts->findLineByVariantAndHash($context, (string) $userCart['uuid'], $variantUuid, $hash);
        $target = (int) $guestLine['quantity'] + ($existing === null ? 0 : (int) $existing['quantity']);
        if ($this->stock->isTracked($context, $tenant, $variantUuid)) {
            $target = min($target, $this->stock->quantity($context, $tenant, $variantUuid));
        }

        if ($existing === null) {
            if ($target > 0) {
                $snapshot = is_array($guestLine['addons'] ?? null) ? $guestLine['addons'] : [];
                $this->carts->insertLine($context, (string) $userCart['uuid'], $variantUuid, $target, $snapshot, $hash);
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
