<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\AddonSnapshot;
use Glueful\Extensions\Commerce\Cart\AddonValidationException;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Validation\ValidationException;

/**
 * Shared authority for resolving ONE purchasable line (variant + quantity +
 * add-ons) to a priced, classified {@see ResolvedLine} -- extracted verbatim
 * from {@see \Glueful\Extensions\Commerce\Cart\CartService::pricedLines()}'s
 * per-line resolution (variant lookup, buyer availability, live variant
 * price, variant-derived option values, shipping/tax attachment, digital/
 * marketplace classification) so a second caller (the admin draft order
 * flow) never has to re-derive any of it.
 *
 * Two typed entry points converge on the SAME private pipeline
 * ({@see self::loadVariantAndProduct()} + {@see self::build()}), differing
 * ONLY in where the canonical add-on snapshot they price comes from:
 *
 * - {@see self::resolvePersistedSnapshot()}: carts/checkout. Prices the
 *   ALREADY-PERSISTED canonical snapshot exactly as it was saved -- an
 *   add-on definition edited (or price-changed) AFTER the snapshot was
 *   persisted can never reprice it. This is `pricedLines()`'s own
 *   "snapshot, don't reference" contract (design spec §4), now shared.
 * - {@see self::resolveSelections()}: admin drafts (mutation/recalculate/
 *   finalize). Resolves RAW selections against the product's CURRENT active
 *   add-on definitions -- via the SAME {@see AddonSnapshot::build()} pure
 *   validator {@see \Glueful\Extensions\Commerce\Cart\CartService::addLine()}
 *   uses -- and returns a FRESH canonical snapshot. Comparing this fresh
 *   snapshot's hash against a previously-persisted one is how a draft
 *   surfaces addon drift instead of silently repricing around it.
 *
 * Neither method accepts a caller-supplied options argument: `optionValues`
 * on the returned {@see ResolvedLine} always comes from the variant row
 * itself, never from the caller.
 */
final class PurchasableLineResolver
{
    public function __construct(
        private VariantRepository $variants,
        private ProductRepository $products,
        private ?AddonRepository $addons = null,
        private ?ShippingClassRepository $shippingClasses = null,
        private ?MarketplaceMode $marketplaceMode = null,
    ) {
        $this->addons ??= new AddonRepository();
        $this->shippingClasses ??= new ShippingClassRepository();
        $this->marketplaceMode ??= new MarketplaceMode();
    }

    /**
     * Carts/checkout path: prices the PERSISTED canonical add-on snapshot
     * verbatim -- see {@see \Glueful\Extensions\Commerce\Cart\CartService::pricedLines()},
     * whose per-line resolution this method now IS. Throws
     * {@see ValidationException} (field `variant_uuid`) when the variant
     * doesn't resolve or its product is no longer buyer-available, and
     * {@see AddonValidationException} if the persisted snapshot computes a
     * negative unit price (a defensive backstop against a corrupted row --
     * `AddonSnapshot::build()` already enforces the invariant before a
     * snapshot is ever persisted, so this should be unreachable in
     * practice).
     *
     * @param list<array<string,mixed>> $canonicalAddonSnapshot the exact,
     *   already-persisted snapshot -- never re-validated against current
     *   addon definitions
     */
    public function resolvePersistedSnapshot(
        ApplicationContext $context,
        string $tenant,
        string $variantUuid,
        int $quantity,
        array $canonicalAddonSnapshot
    ): ResolvedLine {
        [$variant, $product] = $this->loadVariantAndProduct($context, $tenant, $variantUuid);

        return $this->build($context, $tenant, $variant, $product, $quantity, $canonicalAddonSnapshot);
    }

    /**
     * Admin draft path: resolves RAW addon selections against the product's
     * CURRENT active addon definitions (mirrors
     * {@see \Glueful\Extensions\Commerce\Cart\CartService::addLine()}'s own
     * `buildAddonSnapshot()`) and returns a FRESH canonical snapshot --
     * never the persisted one. A definition edited since a draft line was
     * last resolved is picked up HERE, unlike
     * {@see self::resolvePersistedSnapshot()}. Invalid selections raise the
     * SAME {@see ValidationException} (field `addons`) `addLine()` raises,
     * translated from the pure {@see AddonValidationException}.
     *
     * @param list<array{addon_uuid:string,choice_key?:string,value?:mixed}> $rawSelections
     */
    public function resolveSelections(
        ApplicationContext $context,
        string $tenant,
        string $variantUuid,
        int $quantity,
        array $rawSelections
    ): ResolvedLine {
        [$variant, $product] = $this->loadVariantAndProduct($context, $tenant, $variantUuid);

        $definitions = $this->addons->activeForProduct($context, $tenant, (string) $product['uuid']);
        try {
            $built = AddonSnapshot::build($definitions, $rawSelections, (int) $variant['price']);
        } catch (AddonValidationException $e) {
            throw ValidationException::forField('addons', $e->getMessage());
        }

        return $this->build($context, $tenant, $variant, $product, $quantity, $built['snapshot']);
    }

    /**
     * Shared variant + buyer-availability resolution (design spec §2.3/§2.4,
     * MV5b): identical predicate/error contract for both entry points --
     * `findByUuid()` then `findBuyerAvailableByUuid()`, `variant_uuid`-keyed
     * errors matching
     * {@see \Glueful\Extensions\Commerce\Cart\CartService::assertVariantCanSupply()}'s
     * own messages verbatim, so a caller translating this exception (e.g.
     * `CartService::pricedLines()` re-labeling it onto its own `lines.{n}`
     * field) can do so on message content alone.
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>} [variant, product]
     */
    private function loadVariantAndProduct(ApplicationContext $context, string $tenant, string $variantUuid): array
    {
        $variant = $this->variants->findByUuid($context, $tenant, $variantUuid);
        if ($variant === null) {
            throw ValidationException::forField('variant_uuid', 'Variant not found.');
        }

        $product = $this->products->findBuyerAvailableByUuid($context, $tenant, (string) $variant['product_uuid']);
        if ($product === null) {
            throw ValidationException::forField('variant_uuid', 'This product is no longer available.');
        }

        return [$variant, $product];
    }

    /**
     * The shared pricing/classification base both public methods converge
     * on: `unit_price = variant price + Σ(price_delta)` over WHATEVER
     * snapshot the caller resolved (persisted or fresh), variant-derived
     * option values, the variant's resolved nullable shipping-class slug
     * (single-item resolution -- {@see ShippingClassRepository::slugsByUuids()}'s
     * cart-wide batching is a `CartService::pricedLines()`-specific
     * optimization across MANY lines in one call; this method resolves
     * exactly one), the product's resolved tax class (`null` normalizing to
     * `'standard'`), the product's raw commission-policy override
     * (unresolved -- callers needing the FULLY resolved
     * product->seller->workspace->config commission level still resolve it
     * themselves, exactly as
     * {@see \Glueful\Extensions\Commerce\Orders\CheckoutService::attachCommissionSnapshot()}
     * does today), and digital/marketplace classification.
     *
     * `isMarketplacePartitioned` is the config-only, zero-query
     * {@see MarketplaceMode::installEnabled()} switch ONLY -- see
     * {@see ResolvedLine}'s docblock for why the per-tenant activated state
     * is deliberately not re-resolved here.
     *
     * @param array<string,mixed> $variant
     * @param array<string,mixed> $product
     * @param list<array<string,mixed>> $addons
     */
    private function build(
        ApplicationContext $context,
        string $tenant,
        array $variant,
        array $product,
        int $quantity,
        array $addons
    ): ResolvedLine {
        $unitPrice = (int) $variant['price'] + AddonSnapshot::delta($addons);
        if ($unitPrice < 0) {
            throw new AddonValidationException(
                "Persisted add-on snapshot for variant '" . (string) $variant['uuid']
                . "' computes a negative unit price."
            );
        }

        $classUuid = $variant['shipping_class_uuid'] ?? null;
        $shippingClass = $classUuid !== null
            ? ($this->shippingClasses->slugsByUuids($context, $tenant, [$classUuid])[$classUuid] ?? null)
            : null;

        $type = (string) ($product['type'] ?? 'physical');

        return new ResolvedLine(
            productUuid: (string) $product['uuid'],
            variantUuid: (string) $variant['uuid'],
            quantity: $quantity,
            unitPrice: $unitPrice,
            currency: (string) $variant['currency'],
            sku: (string) $variant['sku'],
            productName: (string) $product['name'],
            optionValues: is_array($variant['option_values'] ?? null) ? $variant['option_values'] : [],
            type: $type,
            addons: $addons,
            addonsHash: AddonSnapshot::hash($addons),
            shippingClass: $shippingClass,
            taxClass: (string) ($product['tax_class'] ?? 'standard'),
            commissionKind: isset($product['commission_kind']) ? (string) $product['commission_kind'] : null,
            commissionBps: isset($product['commission_bps']) ? (int) $product['commission_bps'] : null,
            commissionFixed: isset($product['commission_fixed']) ? (int) $product['commission_fixed'] : null,
            isDigital: $type === 'digital',
            isMarketplacePartitioned: $this->marketplaceMode->installEnabled($context),
        );
    }
}
