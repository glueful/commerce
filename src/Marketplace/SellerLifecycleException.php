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
 *
 * Design spec §2.1 (MV5b): a SAME-STATE suspend/reactivate call (already
 * `suspended`/already `active`) is a stable NO-OP, never this exception --
 * SellerService checks that short-circuit BEFORE the `allowedFrom` guard
 * this exception represents. This exception is reserved for genuinely
 * INCOMPATIBLE transitions: reactivating or suspending a terminally
 * `closed` seller, `onboarding -> suspended`, closing an already-`closed`
 * seller, or closing a seller that still owns a live product.
 */
final class SellerLifecycleException extends \DomainException
{
}
