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
use Glueful\Extensions\Commerce\Orders\PurchasableLineResolver;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
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
        private ?PurchasableLineResolver $lineResolver = null,
    ) {
        $this->addons ??= new AddonRepository();
        $this->shippingClasses ??= new ShippingClassRepository();
        $this->lineResolver ??= new PurchasableLineResolver(
            $this->variants,
            $this->products,
            $this->addons,
            $this->shippingClasses
        );
    }

    /** @return array{cart: array<string,mixed>, token: string} */
    public function create(ApplicationContext $context): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $token = TokenHasher::generate();
        $uuid = Utils::generateNanoID();
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . CommerceSettings::cartTtlDays($context) . ' days')
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
    public function claimForCheckout(ApplicationContext $context, array $cart): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        if (!$this->carts->convertIfActive($context, $tenant, (string) $cart['uuid'])) {
            throw ValidationException::forField('cart', 'Cart not found or no longer active.');
        }

        return $this->reloadCart($context, $tenant, (string) $cart['uuid']);
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

        return db($context)->transaction(function () use (
            $context,
            $tenant,
            $cart,
            $variantUuid,
            $quantity,
            $addons
        ): array {
            $this->claimActiveCart($context, $tenant, (string) $cart['uuid']);
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

            $line = $this->carts->findLineByVariantAndHash(
                $context,
                (string) $cart['uuid'],
                $variantUuid,
                $hash
            );
            if ($line === null) {
                $this->carts->insertLine($context, (string) $cart['uuid'], $variantUuid, $quantity, $snapshot, $hash);
            } else {
                $this->carts->setLineQuantity($context, (string) $line['uuid'], (int) $line['quantity'] + $quantity);
            }

            return $this->reloadCart($context, $tenant, (string) $cart['uuid']);
        });
    }

    /**
     * Convergent counterpart to {@see self::addLine()}: sets the matching line
     * (same variant+addons identity addLine uses) to the DESIRED `$quantity`
     * rather than adding a delta to whatever is already there. Two sequential
     * `putLine($v, 2)` calls leave ONE line at quantity 2 -- the equivalent
     * `addLine($v, 2)` pair would leave it at 4. `$quantity <= 0` removes the
     * matching line if one exists; an absent line + `$quantity <= 0` is a clean
     * no-op. Same claim protocol (`claimActiveCart()` serializes this against
     * every other cart mutation, so a sequence of puts against the same
     * identity deterministically converges to the LAST desired value) and the
     * same stock/availability validation as addLine -- except the stock check
     * validates the DESIRED total quantity for the variant (aggregated across
     * every add-on hash, mirroring addLine's own cross-hash aggregation), not
     * a delta from whatever the matching line already holds.
     *
     * @param array<string,mixed> $cart
     * @param list<array{addon_uuid:string,choice_key?:string,value?:mixed}> $addons
     * @return array<string,mixed>
     */
    public function putLine(
        ApplicationContext $context,
        array $cart,
        string $variantUuid,
        int $quantity,
        array $addons = []
    ): array {
        $tenant = $this->tenants->tenantUuid($context);

        return db($context)->transaction(function () use (
            $context,
            $tenant,
            $cart,
            $variantUuid,
            $quantity,
            $addons
        ): array {
            $this->claimActiveCart($context, $tenant, (string) $cart['uuid']);

            $variant = $this->variants->findByUuid($context, $tenant, $variantUuid);
            if ($variant === null) {
                throw ValidationException::forField('variant_uuid', 'Variant not found.');
            }

            ['hash' => $hash, 'snapshot' => $snapshot] = $this->buildAddonSnapshot(
                $context,
                $tenant,
                (string) $variant['product_uuid'],
                (int) $variant['price'],
                $addons
            );

            $line = $this->carts->findLineByVariantAndHash(
                $context,
                (string) $cart['uuid'],
                $variantUuid,
                $hash
            );

            if ($quantity <= 0) {
                if ($line !== null) {
                    $this->carts->deleteLine($context, (string) $line['uuid']);
                }

                return $this->reloadCart($context, $tenant, (string) $cart['uuid']);
            }

            $existingForHash = $line === null ? 0 : (int) $line['quantity'];
            $desiredVariantTotal = $this->carts->totalQuantityForVariant($context, (string) $cart['uuid'], $variantUuid)
                - $existingForHash
                + $quantity;
            $this->assertVariantCanSupply($context, $tenant, $variantUuid, $desiredVariantTotal);

            if ($line === null) {
                $this->carts->insertLine($context, (string) $cart['uuid'], $variantUuid, $quantity, $snapshot, $hash);
            } else {
                $this->carts->setLineQuantity($context, (string) $line['uuid'], $quantity);
            }

            return $this->reloadCart($context, $tenant, (string) $cart['uuid']);
        });
    }

    /** @param array<string,mixed> $cart @return array<string,mixed> */
    public function setLineQuantity(ApplicationContext $context, array $cart, string $lineUuid, int $quantity): array
    {
        $tenant = $this->tenants->tenantUuid($context);

        return db($context)->transaction(function () use ($context, $tenant, $cart, $lineUuid, $quantity): array {
            $this->claimActiveCart($context, $tenant, (string) $cart['uuid']);
            $line = $this->carts->findLine($context, $lineUuid);
            if ($line === null || $line['cart_uuid'] !== $cart['uuid']) {
                throw ValidationException::forField('line_uuid', 'Cart line not found.');
            }

            if ($quantity <= 0) {
                $this->carts->deleteLine($context, $lineUuid);
            } else {
                $this->assertVariantCanSupply($context, $tenant, (string) $line['variant_uuid'], $quantity);
                $this->carts->setLineQuantity($context, $lineUuid, $quantity);
            }

            return $this->reloadCart($context, $tenant, (string) $cart['uuid']);
        });
    }

    /** @param array<string,mixed> $cart @return array<string,mixed> */
    public function applyDiscount(ApplicationContext $context, array $cart, string $code): array
    {
        $tenant = $this->tenants->tenantUuid($context);

        return db($context)->transaction(function () use ($context, $tenant, $cart, $code): array {
            $this->claimActiveCart($context, $tenant, (string) $cart['uuid']);
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
        });
    }

    /** @param array<string,mixed> $cart @return array<string,mixed> */
    public function removeDiscount(ApplicationContext $context, array $cart): array
    {
        $tenant = $this->tenants->tenantUuid($context);

        return db($context)->transaction(function () use ($context, $tenant, $cart): array {
            $this->claimActiveCart($context, $tenant, (string) $cart['uuid']);
            $this->carts->update($context, $tenant, (string) $cart['uuid'], ['discount_code' => null]);

            return $this->reloadCart($context, $tenant, (string) $cart['uuid']);
        });
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
        if ($userCart !== null && $userCart['uuid'] === $guest['uuid']) {
            return $this->reloadCart($context, $tenant, (string) $guest['uuid']);
        }

        return db($context)->transaction(function () use ($context, $tenant, $guest, $userCart, $userUuid): array {
            $claimUuids = [(string) $guest['uuid']];
            if ($userCart !== null) {
                $claimUuids[] = (string) $userCart['uuid'];
            }
            sort($claimUuids);
            foreach ($claimUuids as $claimUuid) {
                $this->claimActiveCart($context, $tenant, $claimUuid);
            }

            if ($userCart === null) {
                $this->carts->update($context, $tenant, (string) $guest['uuid'], ['user_uuid' => $userUuid]);
                return $this->reloadCart($context, $tenant, (string) $guest['uuid']);
            }

            foreach ($this->carts->lines($context, (string) $guest['uuid']) as $line) {
                $this->mergeLine($context, $tenant, $userCart, $line);
            }

            $this->carts->update($context, $tenant, (string) $guest['uuid'], ['status' => 'abandoned']);

            return $this->reloadCart($context, $tenant, (string) $userCart['uuid']);
        });
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
     * Per-line resolution (variant lookup, buyer availability, unit-price
     * math, variant-derived option values, shipping/tax attachment, digital/
     * seller classification) is delegated to
     * {@see \Glueful\Extensions\Commerce\Orders\PurchasableLineResolver::resolvePersistedSnapshot()} --
     * this method's own job is cart-line bookkeeping: a batched shipping-
     * class pre-pass (see below), reading each persisted line, translating
     * the resolver's generic `variant_uuid` errors back onto ITS OWN two
     * established outcomes (a genuinely orphaned variant reference silently
     * skipped, a buyer-unavailable product surfaced as a `lines.{n}` 422),
     * and re-embedding the cart line's own uuid into a negative-unit-price
     * backstop's message.
     *
     * VALUE-identical to this method's pre-extraction body for any single
     * outcome -- but NOT byte-identical for FAILURE ORDERING on a
     * multi-line cart carrying more than one bad line: the pre-extraction
     * body checked EVERY line's buyer-availability first (across the whole
     * cart) before pricing ANY of them, so an unavailable-product error on
     * line 3 always surfaced even when line 2 also had a corrupted negative
     * price. This method now resolves one line fully (availability THEN
     * price) before moving to the next, so whichever bad line comes FIRST
     * in cart order wins, regardless of failure kind. A cart with at most
     * one failing line -- the overwhelmingly common and only currently
     * tested case -- is unaffected either way.
     *
     * Shipping-class slugs are batch-resolved ONCE for the whole cart (one
     * `IN (...)` query via {@see \Glueful\Extensions\Commerce\Catalog\VariantRepository::findByUuids()}
     * + {@see ShippingClassRepository::slugsByUuids()}, mirroring this
     * method's pre-Task-7 body) and threaded into the resolver via its
     * optional `$shippingClassSlugsByUuid` parameter -- never one lookup
     * per line.
     *
     * `line_uuid` (the cart line's own uuid, for deterministic discount-allocation
     * ties, {@see \Glueful\Extensions\Commerce\Tax\DiscountAllocation}), `shipping_class`
     * (the variant's resolved nullable shipping-class slug, for
     * {@see \Glueful\Extensions\Commerce\Shipping\DbShippingRateProvider}'s
     * `per_class_table` pricing), and `tax_class` (the product's resolved
     * tax class, `null` normalizing to `'standard'`, for
     * {@see \Glueful\Extensions\Commerce\Tax\DbTaxCalculator}'s per-line rate
     * selection) are ADDITIVE keys layered onto the existing shape -- nothing
     * else changes, `addons`/`addons_hash` untouched.
     *
     * A line whose variant no longer resolves live (design spec Layer 6 §2:
     * product soft delete) is NOT silently dropped -- unlike a genuinely
     * dangling variant reference (variant rows are never soft-deleted, so a
     * missing variant here would indicate real orphan data and is left as a
     * silent `continue`), a tombstoned product is an expected, reachable state
     * this method must surface: it throws a controlled 422 naming the offending
     * line so the caller (cart view, reprice, checkout quote/place) fails
     * closed instead of quietly re-totaling around a product that no longer
     * exists. A checkout that already read the product live before the delete
     * landed may still finish -- soft delete is not retroactive order
     * cancellation.
     *
     * Buyer-context product read (design spec §2.3/§2.4, MV5b): uses
     * {@see ProductRepository::findBuyerAvailableByUuid()}, NOT
     * `findLiveByUuid()` -- a seller-backed line whose seller is
     * `suspended`/`closed`/`onboarding` collapses to the SAME "no longer
     * available" 422 a tombstoned product produces, both in cart pricing
     * (this method) and in `CheckoutService::placeOrderAttempt()` (which
     * calls this method to resolve the lines it prices/writes). This is a
     * natural consequence of centralizing the buyer-availability predicate,
     * not a duplicate of `CheckoutService::claimMarketplaceOwnership()`'s
     * OWN claimed seller-status guard -- that guard remains the sole
     * defense against a suspension landing mid-transaction, AFTER this
     * method already read the line as available (see
     * `SuspendedSellerCheckoutTest`/`CheckoutPartitionTest`'s hook-based
     * race coverage).
     *
     * @param array<string,mixed> $cart
     * @return list<array<string,mixed>>
     */
    public function pricedLines(ApplicationContext $context, array $cart): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $lines = $this->carts->lines($context, (string) $cart['uuid']);

        // Batched shipping-class pre-pass (ONE query per cart, restoring
        // this method's pre-Task-7 behavior): a lightweight variant-uuid
        // batch JUST to collect distinct shipping_class_uuid values -- the
        // resolver below still owns its OWN authoritative per-line variant
        // lookup (availability, live price, etc.); this pre-pass exists
        // ONLY so `commerce_shipping_classes` is never queried once per line.
        $variantUuids = array_values(array_unique(array_map(
            static fn (array $line): string => (string) $line['variant_uuid'],
            $lines
        )));
        $variantsByUuid = $this->variants->findByUuids($context, $tenant, $variantUuids);
        $classUuids = array_values(array_unique(array_filter(array_map(
            static fn (array $variant): ?string => $variant['shipping_class_uuid'] ?? null,
            $variantsByUuid
        ))));
        $slugsByUuid = $this->shippingClasses->slugsByUuids($context, $tenant, $classUuids);

        $priced = [];
        foreach ($lines as $index => $line) {
            $snapshot = is_array($line['addons'] ?? null) ? $line['addons'] : [];

            try {
                $resolved = $this->lineResolver->resolvePersistedSnapshot(
                    $context,
                    $tenant,
                    (string) $line['variant_uuid'],
                    (int) $line['quantity'],
                    $snapshot,
                    $slugsByUuid
                );
            } catch (AddonValidationException) {
                throw new AddonValidationException(
                    "Persisted add-on snapshot for cart line '" . (string) $line['uuid']
                    . "' computes a negative unit price."
                );
            } catch (ValidationException $e) {
                if ($e->firstError('variant_uuid') === 'Variant not found.') {
                    // Variant rows are never soft-deleted -- reaching this means
                    // genuine orphan data, silently skipped exactly as before.
                    continue;
                }

                throw ValidationException::forField(
                    "lines.{$index}",
                    'This product is no longer available.'
                );
            }

            $priced[] = [
                'line_uuid' => (string) $line['uuid'],
                'product_uuid' => $resolved->productUuid,
                'variant_uuid' => $resolved->variantUuid,
                'unit_price' => $resolved->unitPrice,
                'currency' => $resolved->currency,
                'quantity' => $resolved->quantity,
                'sku' => $resolved->sku,
                'product_name' => $resolved->productName,
                'option_values' => $resolved->optionValues,
                'type' => $resolved->type,
                'addons' => $resolved->addons,
                'shipping_class' => $resolved->shippingClass,
                'tax_class' => $resolved->taxClass,
                // Marketplace commission (MV3, design spec §2.4): the product's own
                // commission-policy override level, riding the ALREADY-fetched product
                // row above -- no new query. Null means "inherit the next precedence
                // level"; a non-marketplace/non-partitioned checkout never resolves
                // these keys at all, so they are harmless when carried but unused.
                'commission_kind' => $resolved->commissionKind,
                'commission_bps' => $resolved->commissionBps,
                'commission_fixed' => $resolved->commissionFixed,
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
     *
     * Also the cart-ADD half of the tombstoned-product guard (design spec Layer
     * 6 §2): variant rows are never soft-deleted, so a live variant can still
     * reference a now-tombstoned product. A live product lookup here rejects
     * adding such a line outright with a controlled 422, rather than defaulting
     * to `'physical'` and letting an unavailable product be added silently --
     * {@see self::pricedLines()} is the symmetric guard for a line added before
     * its product was deleted. Called from both `addLine()` (cart ADD) and
     * `setLineQuantity()` (cart UPDATE), so both share this one guard.
     *
     * Buyer-context read (design spec §2.3/§2.4, MV5b): uses
     * {@see ProductRepository::findBuyerAvailableByUuid()} -- adding or
     * increasing a line for a product whose seller is `suspended`/`closed`/
     * `onboarding` is rejected with the SAME stable "no longer available"
     * error a tombstoned product produces, even when the line already
     * existed in the cart from before the seller was suspended.
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

        $product = $this->products->findBuyerAvailableByUuid($context, $tenant, (string) $variant['product_uuid']);
        if ($product === null) {
            throw ValidationException::forField('variant_uuid', 'This product is no longer available.');
        }

        $type = (string) ($product['type'] ?? 'physical');
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

    private function claimActiveCart(ApplicationContext $context, string $tenant, string $cartUuid): void
    {
        if (!$this->carts->claimActive($context, $tenant, $cartUuid)) {
            throw ValidationException::forField('cart', 'Cart not found or no longer active.');
        }
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
