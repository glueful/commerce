<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Discounts;

/**
 * Thrown by DiscountService::delete() when the discount's post-claim
 * redemption probe finds at least one row in `commerce_discount_redemptions`.
 * Mapped to a 409 by AdminDiscountController with a "disable via status
 * instead" hint, mirroring ShippingClassInUseException / ReviewStateException's
 * plain-\DomainException-caught-by-controller convention. An unknown or
 * cross-tenant discount is a NotFoundException (404) instead -- see
 * DiscountService::delete()'s docblock for the claim-first classification and
 * the full delete-vs-checkout-redemption race analysis.
 */
final class DiscountRedeemedException extends \DomainException
{
}
