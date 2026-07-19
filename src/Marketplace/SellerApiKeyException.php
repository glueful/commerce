<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown by {@see SellerApiKeyService} for the seller-API-key live-authority
 * failures (design spec §2.8/§2.9): the seller is not `active`, the acting
 * subject no longer holds an active membership on this seller, or that
 * membership's freshly-derived role no longer holds
 * `commerce.seller.apikeys.manage`. A `\DomainException` -- a domain-state
 * refusal, not an input-validation error -- mirroring
 * {@see SellerMembershipException}/{@see SellerLifecycleException}'s
 * plain-\DomainException-caught-by-controller convention (no controller
 * exists yet in MV5c-1 Task 3; a later surface task maps this).
 *
 * `$errorCode` reuses the SAME closed reason vocabulary the design spec
 * §2.10 audit trail defines for the per-request key authorizer
 * (`membership_inactive | seller_inactive | capability_denied`, among
 * others) -- deliberately consistent so a future controller/audit consumer
 * never needs a second mapping table for the create-time equivalents of
 * these checks. Carried as a readonly property, mirroring
 * {@see CheckoutConflictException}'s `$errorCode` convention.
 *
 * Scope-declaration rejections (empty/wildcard/non-grantable/not-held-by-role)
 * are a SEPARATE concern and throw {@see \Glueful\Validation\ValidationException}
 * instead (422) -- see {@see SellerApiKeyScopeValidator} -- mirroring how
 * {@see SellerMembershipService::assertValidRole()} keeps input-shape
 * rejections on `ValidationException` while state-conflict rejections stay
 * on a dedicated domain exception.
 */
final class SellerApiKeyException extends \DomainException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function sellerInactive(string $status): self
    {
        return new self("Seller is '{$status}'; API keys are unavailable.", 'seller_inactive');
    }

    public static function membershipInactive(): self
    {
        return new self(
            'The acting user is not an active member of this seller.',
            'membership_inactive'
        );
    }

    public static function capabilityDenied(): self
    {
        return new self(
            "The acting user's current role does not hold 'commerce.seller.apikeys.manage'.",
            'capability_denied'
        );
    }
}
