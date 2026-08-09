<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\AddonSnapshot;
use Glueful\Extensions\Commerce\Cart\AddonValidationException;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
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
    /**
     * Mirrors {@see \Glueful\Extensions\Commerce\Catalog\CatalogService}'s own
     * `PURCHASABLE_TYPES` (private there) -- `resolveSelections()`'s ONLY
     * purchasability guard; kept private/local rather than shared to avoid a
     * cross-namespace coupling for two literal strings.
     */
    private const PURCHASABLE_TYPES = ['physical', 'digital'];

    public function __construct(
        private VariantRepository $variants,
        private ProductRepository $products,
        private ?AddonRepository $addons = null,
        private ?ShippingClassRepository $shippingClasses = null,
    ) {
        $this->addons ??= new AddonRepository();
        $this->shippingClasses ??= new ShippingClassRepository();
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
     * practice). Product-type purchasability (physical/digital only) is
     * DELIBERATELY NOT re-checked here -- unlike {@see self::resolveSelections()}
     * (a NEW draft line being resolved), a persisted cart line was already
     * gated at `addLine()`/`assertVariantCanSupply()` time, and this path
     * must stay byte-identical to `pricedLines()`'s pre-extraction body.
     *
     * @param list<array<string,mixed>> $canonicalAddonSnapshot the exact,
     *   already-persisted snapshot -- never re-validated against current
     *   addon definitions
     * @param array<string,string>|null $shippingClassSlugsByUuid pre-resolved
     *   shipping-class slugs keyed by class uuid, for a caller (e.g.
     *   `CartService::pricedLines()`) that already batch-resolved every
     *   distinct class across a WHOLE cart in one query -- passing this
     *   avoids re-querying `commerce_shipping_classes` for a class this
     *   caller already knows. `null` (the default) falls back to a
     *   single-item lookup, correct for a lone draft-line resolution.
     */
    public function resolvePersistedSnapshot(
        ApplicationContext $context,
        string $tenant,
        string $variantUuid,
        int $quantity,
        array $canonicalAddonSnapshot,
        ?array $shippingClassSlugsByUuid = null
    ): ResolvedLine {
        [$variant, $product] = $this->loadVariantAndProduct($context, $tenant, $variantUuid);

        return $this->build(
            $context,
            $tenant,
            $variant,
            $product,
            $quantity,
            $canonicalAddonSnapshot,
            $shippingClassSlugsByUuid
        );
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
     * A NEW draft line being resolved here also gets a purchasability-type
     * guard {@see self::resolvePersistedSnapshot()} deliberately does NOT
     * carry (see that method's docblock): the SAME `physical`/`digital`
     * check and `variant_uuid` message
     * {@see \Glueful\Extensions\Commerce\Cart\CartService::assertVariantCanSupply()}
     * applies at cart-add time -- an `external`/`grouped` product's variant
     * must never resolve into a fresh draft line either.
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

        $type = (string) ($product['type'] ?? 'physical');
        if (!in_array($type, self::PURCHASABLE_TYPES, true)) {
            throw ValidationException::forField(
                'variant_uuid',
                "Products of type '{$type}' cannot be purchased."
            );
        }

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
     * option values, the variant's resolved nullable shipping-class slug,
     * the product's resolved tax class (`null` normalizing to `'standard'`),
     * the product's raw commission-policy override (unresolved -- callers
     * needing the FULLY resolved product->seller->workspace->config
     * commission level still resolve it themselves, exactly as
     * {@see \Glueful\Extensions\Commerce\Orders\CheckoutService::attachCommissionSnapshot()}
     * does today), and digital/seller classification.
     *
     * Shipping-class slug resolution: when `$shippingClassSlugsByUuid` is
     * given (a caller's own cart-wide batch), it is consulted directly --
     * ZERO extra queries per line. `null` falls back to a single-item
     * {@see ShippingClassRepository::slugsByUuids()} call, correct for a
     * lone draft-line resolution but O(n) queries if a batching caller
     * forgets to pass its map (`CartService::pricedLines()` never does).
     *
     * `sellerUuid` rides the ALREADY-fetched product row -- see
     * {@see ResolvedLine}'s docblock for why this is the raw per-line fact
     * rather than a resolved partitioned boolean.
     *
     * @param array<string,mixed> $variant
     * @param array<string,mixed> $product
     * @param list<array<string,mixed>> $addons
     * @param array<string,string>|null $shippingClassSlugsByUuid
     */
    private function build(
        ApplicationContext $context,
        string $tenant,
        array $variant,
        array $product,
        int $quantity,
        array $addons,
        ?array $shippingClassSlugsByUuid = null
    ): ResolvedLine {
        $unitPrice = (int) $variant['price'] + AddonSnapshot::delta($addons);
        if ($unitPrice < 0) {
            throw new AddonValidationException(
                "Persisted add-on snapshot for variant '" . (string) $variant['uuid']
                . "' computes a negative unit price."
            );
        }

        $classUuid = $variant['shipping_class_uuid'] ?? null;
        if ($classUuid === null) {
            $shippingClass = null;
        } elseif ($shippingClassSlugsByUuid !== null) {
            $shippingClass = $shippingClassSlugsByUuid[$classUuid] ?? null;
        } else {
            $shippingClass = $this->shippingClasses->slugsByUuids($context, $tenant, [$classUuid])[$classUuid]
                ?? null;
        }

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
            sellerUuid: isset($product['seller_uuid']) ? (string) $product['seller_uuid'] : null,
        );
    }
}
