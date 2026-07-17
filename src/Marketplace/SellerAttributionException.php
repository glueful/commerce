<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown for a catalog-attribution conflict (design spec §2.7/§4): the
 * target seller referenced by an attributed create, adoption, or transfer
 * exists and is in-tenant but is `suspended`/`closed` (products cannot be
 * attributed to it), or a transfer's post-claim re-read finds the product's
 * current seller no longer matches the pre-claim snapshot (a competing
 * adoption/transfer won the race -- "stale ownership"). Mapped to a 409 by
 * {@see \Glueful\Extensions\Commerce\Http\Admin\MarketplaceAdminController}
 * and by {@see \Glueful\Extensions\Commerce\Catalog\CatalogService}'s own
 * callers. An unknown or cross-tenant seller_uuid is a
 * {@see \Glueful\Validation\ValidationException} (422) instead -- see
 * {@see SellerAttributionService}/{@see \Glueful\Extensions\Commerce\Catalog\CatalogService}
 * for the classification.
 */
final class SellerAttributionException extends \DomainException
{
}
