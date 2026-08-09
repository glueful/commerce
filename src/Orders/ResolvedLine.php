<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * The typed result of {@see PurchasableLineResolver}: everything a caller
 * needs to price and classify ONE purchasable line, regardless of whether it
 * came from a persisted cart-line snapshot ({@see PurchasableLineResolver::resolvePersistedSnapshot()})
 * or a fresh raw selection resolved against CURRENT addon definitions
 * ({@see PurchasableLineResolver::resolveSelections()}).
 *
 * `optionValues` is ALWAYS the variant's own stored option values -- neither
 * resolver method accepts a caller-supplied options argument, so a line's
 * displayed options can never drift from the variant that was actually
 * priced.
 *
 * `addons`/`addonsHash` are the CANONICAL snapshot this line was priced
 * against: for `resolvePersistedSnapshot()` this is the untouched persisted
 * snapshot (a later addon-definition edit can never change it); for
 * `resolveSelections()` this is a FRESH snapshot built against the product's
 * ACTIVE addon definitions RIGHT NOW -- comparing this hash against a
 * previously-persisted line's `addons_hash` is how a caller detects addon
 * drift (definition changed since the line was drafted).
 *
 * `sellerUuid` is the genuine PER-LINE marketplace fact: the product's own
 * `seller_uuid` column, riding the ALREADY-fetched buyer-available product
 * row (zero new queries) -- `null` for an ordinary, non-marketplace-store
 * product. This is deliberately NOT a resolved "is this line partitioned"
 * boolean: whether a checkout/order is partitioned at all is an
 * ORDER-LEVEL decision (`installEnabled($context) && activeFor($context,
 * $tenant)`, {@see \Glueful\Extensions\Commerce\Marketplace\MarketplaceMode}),
 * computed exactly ONCE per checkout by
 * {@see \Glueful\Extensions\Commerce\Orders\CheckoutService::placeOrder()} --
 * never once per line ({@see CommissionSnapshotTest}'s pinned
 * exactly-once-query assertion against `commerce_marketplace_settings`
 * would break if a per-line resolver re-queried it). A caller deciding
 * whether THIS line participates in a partitioned order combines the two:
 * `sellerUuid !== null && <that order-level decision>` -- the same
 * composition `CheckoutService::partitionCheckout()` already performs
 * (per-product `seller_uuid` presence, gated on the order already being
 * partitioned), not something this resolver can decide alone since it never
 * sees the whole cart/order in one call.
 */
final readonly class ResolvedLine
{
    /**
     * @param array<string,mixed> $optionValues variant-derived, never caller-supplied
     * @param list<array<string,mixed>> $addons canonical addon snapshot entries
     */
    public function __construct(
        public string $productUuid,
        public string $variantUuid,
        public int $quantity,
        public int $unitPrice,
        public string $currency,
        public string $sku,
        public string $productName,
        public array $optionValues,
        public string $type,
        public array $addons,
        public string $addonsHash,
        public ?string $shippingClass,
        public string $taxClass,
        public ?string $commissionKind,
        public ?int $commissionBps,
        public ?int $commissionFixed,
        public bool $isDigital,
        public ?string $sellerUuid,
    ) {
    }
}
