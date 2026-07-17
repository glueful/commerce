<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown by SellerMembershipService for the seller-membership fail-closed /
 * anti-lockout rules (design spec §2.4/§2.6): a mutation attempted while the
 * seller is suspended/closed, the last-owner guard on demote/revoke, and a
 * duplicate grant against an already-active membership. Mapped to a 409 by
 * MarketplaceAdminController. An unknown seller/membership is a
 * NotFoundException (404) instead; an unrecognized role is a
 * ValidationException (422) instead -- see SellerMembershipService for the
 * classification.
 */
final class SellerMembershipException extends \DomainException
{
}
