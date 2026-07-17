<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown by SellerService when a lifecycle transition (suspend/reactivate/
 * close) is attempted from a status that does not allow it, or when close is
 * blocked because the seller still owns a live product (design spec §2.4).
 * Mapped to a 409 by MarketplaceAdminController, mirroring
 * ReviewStateException/ShippingClassInUseException's plain-\DomainException-
 * caught-by-controller convention. An unknown or cross-tenant seller is a
 * NotFoundException (404) instead -- see SellerService's claim-first
 * classification.
 */
final class SellerLifecycleException extends \DomainException
{
}
