<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown by {@see MarketplaceActivationService::activate()} when the
 * adoption gate (design spec §2.2) is not satisfied: after an optional
 * `default_seller_uuid` bulk-adopt, at least one live product for the
 * tenant still has no `seller_uuid`. Carries the exact remaining count so
 * {@see \Glueful\Extensions\Commerce\Http\Admin\MarketplaceAdminController}
 * can surface it in the 409 response body, mirroring
 * {@see \Glueful\Extensions\Commerce\Orders\InsufficientStockException}'s
 * readonly-property-carries-the-detail convention.
 */
final class MarketplaceActivationException extends \DomainException
{
    public function __construct(public readonly int $unassignedCount)
    {
        parent::__construct(
            "Cannot activate: {$unassignedCount} product(s) still lack a seller. Provide a "
                . 'default_seller_uuid to bulk-adopt them, or attribute them individually first.'
        );
    }
}
