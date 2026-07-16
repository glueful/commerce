<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Shipping;

/**
 * Thrown by ShippingClassService::delete() when the class's post-claim reference
 * re-check finds at least one variant still assigned to it. Mapped to a 409 by
 * AdminShippingClassController, mirroring IdempotencyConflictException /
 * ReviewStateException's plain-\DomainException-caught-by-controller convention.
 * An unknown or cross-tenant class is a NotFoundException (404) instead -- see
 * ShippingClassService::delete() for the claim-first classification.
 */
final class ShippingClassInUseException extends \DomainException
{
}
