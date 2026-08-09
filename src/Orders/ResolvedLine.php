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
 * `isMarketplacePartitioned` mirrors ONLY the config-only, zero-query
 * marketplace INSTALL master switch
 * ({@see \Glueful\Extensions\Commerce\Marketplace\MarketplaceMode::installEnabled()}) --
 * the SAME fast-path signal `CheckoutService::placeOrder()` computes ONCE
 * (as `$marketplaceInstalled`) before ever touching a per-tenant row. The
 * per-tenant ACTIVATED state
 * ({@see \Glueful\Extensions\Commerce\Marketplace\MarketplaceMode::activeFor()})
 * is deliberately NOT re-resolved per line here: it is a workspace-level
 * concern `CheckoutService` already resolves exactly ONCE per checkout
 * (never once per line, {@see CommissionSnapshotTest}'s pinned
 * exactly-once-query assertion against `commerce_marketplace_settings`)--
 * duplicating that read inside a per-line resolver called once per cart line
 * would multiply it.
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
        public bool $isMarketplacePartitioned,
    ) {
    }
}
