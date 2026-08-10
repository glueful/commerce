<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * The ONE authority for "may this purchasable become an admin draft line?"
 * (admin-order-creation cycle 2, Task 9, design spec §2.3's eligibility
 * surface).
 *
 * Design spec §2.3 requires ONE path, not two agreeing implementations: the
 * admin product-search projection publishes `admin_draft_eligible` +
 * `admin_draft_ineligible_reason` so the SPA can disable an ineligible result
 * BEFORE any mutation, and the draft line endpoint independently rechecks and
 * returns the SAME closed reason as the write authority. Both call into this
 * class, so the vocabulary is written once and the two surfaces cannot drift.
 *
 * The reasons are a CLOSED vocabulary ({@see self::REASONS}) -- a client may
 * branch on them exhaustively:
 *  - `digital`     -- design Ruling 9: account-attached digital admin orders
 *                     are a recorded follow-up, not this cycle. Note that
 *                     {@see PurchasableLineResolver::resolveSelections()} does
 *                     NOT reject digital (a digital variant is perfectly
 *                     purchasable through storefront checkout); this is a
 *                     DRAFT-specific rejection and lives here.
 *  - `marketplace` -- design Ruling 9: partitioned admin orders are likewise
 *                     deferred. See {@see self::reason()} on why this needs an
 *                     ORDER-level input rather than the per-line seller fact
 *                     alone.
 *  - `unavailable` -- the purchasable would not resolve at all: an unknown
 *                     variant, a product that is not buyer-available
 *                     (tombstoned, or owned by a non-active seller while the
 *                     marketplace install switch is on), or a product whose
 *                     type is outside `physical|digital` (`external`/
 *                     `grouped`). This is exactly the set
 *                     `resolveSelections()` itself refuses.
 *
 * ORDERING is fixed and deliberate: `unavailable` -> `digital` ->
 * `marketplace`. A product can qualify for more than one (a digital
 * marketplace product, say); reporting the first match keeps BOTH surfaces
 * reporting the same single reason for the same product, which is the entire
 * point of a shared authority. `unavailable` leads because a product that
 * cannot resolve has no trustworthy type/seller facts to classify further.
 */
final class DraftLineEligibility
{
    public const DIGITAL = 'digital';
    public const MARKETPLACE = 'marketplace';
    public const UNAVAILABLE = 'unavailable';

    /** The closed vocabulary, in the fixed evaluation order documented above. */
    public const REASONS = [self::DIGITAL, self::MARKETPLACE, self::UNAVAILABLE];

    /**
     * Mirrors {@see PurchasableLineResolver}'s own private `PURCHASABLE_TYPES`
     * (and {@see \Glueful\Extensions\Commerce\Cart\CartService::assertVariantCanSupply()}'s
     * inline list). Kept local for the same reason the resolver keeps its own:
     * two literal strings are not worth a cross-namespace coupling.
     */
    private const PURCHASABLE_TYPES = ['physical', 'digital'];

    /**
     * @param string $type the product's own `type` column
     * @param string|null $sellerUuid the product's own `seller_uuid` column --
     *     the RAW per-line marketplace fact, never a resolved boolean (see
     *     {@see ResolvedLine}'s docblock)
     * @param bool $buyerAvailable whether the product resolves through
     *     {@see \Glueful\Extensions\Commerce\Catalog\ProductRepository::findBuyerAvailableByUuid()}
     * @param bool $partitioning the ORDER-level marketplace decision --
     *     `MarketplaceMode::installEnabled($context) && activeFor($context, $tenant)`,
     *     computed exactly ONCE per request by the caller, exactly as
     *     {@see CheckoutService::placeOrder()} does. It is deliberately an
     *     input rather than something this class resolves: `activeFor()` is a
     *     database read, and a per-row/per-line call would turn one page of
     *     product search into N `commerce_marketplace_settings` queries.
     */
    public static function reason(
        string $type,
        ?string $sellerUuid,
        bool $buyerAvailable,
        bool $partitioning
    ): ?string {
        if (!$buyerAvailable || !in_array($type, self::PURCHASABLE_TYPES, true)) {
            return self::UNAVAILABLE;
        }

        if ($type === self::DIGITAL) {
            return self::DIGITAL;
        }

        if ($sellerUuid !== null && $sellerUuid !== '' && $partitioning) {
            return self::MARKETPLACE;
        }

        return null;
    }

    /**
     * Projection entry point: classify a RAW `commerce_products` row (the shape
     * {@see \Glueful\Extensions\Commerce\Catalog\ProductRepository::paginatedForAdmin()}
     * returns). `$buyerAvailable` is supplied by the caller because the admin
     * list resolves it for a WHOLE page in one batched seller read rather than
     * per row.
     *
     * @param array<string,mixed> $row
     */
    public static function forProductRow(array $row, bool $buyerAvailable, bool $partitioning): ?string
    {
        $sellerUuid = isset($row['seller_uuid']) ? (string) $row['seller_uuid'] : null;

        return self::reason((string) ($row['type'] ?? 'physical'), $sellerUuid, $buyerAvailable, $partitioning);
    }

    /**
     * Mutation entry point: classify a line that ALREADY resolved through
     * {@see PurchasableLineResolver::resolveSelections()}. `$buyerAvailable` is
     * hard-coded true because the resolver threw otherwise -- the draft
     * service translates that throw into the SAME `unavailable` reason, so the
     * two surfaces still agree.
     */
    public static function forResolvedLine(ResolvedLine $line, bool $partitioning): ?string
    {
        return self::reason($line->type, $line->sellerUuid, true, $partitioning);
    }
}
