<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown by {@see SellerWebhookEndpointService}/{@see SellerWebhookSecretService}
 * for the seller-webhook live-authority and input-safety failures (design
 * spec §2.2/§2.6/§2.9/§2.10): the seller is not `active` (including
 * `suspended`), the acting subject no longer holds an active membership on
 * this seller, that membership's freshly-derived role no longer holds
 * `commerce.seller.webhooks.manage`, or a registered/updated/re-validated
 * URL failed the framework's `SafeOutboundTargetResolver::resolveWebhook()`
 * safety check. A `\DomainException` -- a domain-state refusal, not a
 * generic input-shape error -- mirroring
 * {@see SellerApiKeyException}'s identical plain-\DomainException
 * convention (no controller exists yet in MV5c-2 Task 3; a later surface
 * task maps this to its final HTTP status).
 *
 * `$errorCode` reuses the SAME closed reason-vocabulary shape
 * {@see SellerApiKeyException} already established
 * (`seller_inactive | membership_inactive | capability_denied`), plus two
 * webhook-specific codes (`unsafe_url | secret_unavailable`) -- deliberately
 * consistent so a future controller/audit consumer never needs a second
 * mapping table for the webhook equivalents of these checks.
 *
 * {@see self::unsafeUrl()} NEVER embeds a resolved internal IP address or
 * hostname beyond what the caller itself supplied (design spec §2.6): every
 * message the framework's `SafeOutboundTargetResolver` itself throws is
 * already generic ("host resolves to a private or reserved address") and
 * never interpolates the actual resolved address, so relaying that message
 * verbatim here stays non-revealing by construction.
 *
 * Event-catalog-subset/empty-events rejections are a SEPARATE concern and
 * throw {@see \Glueful\Validation\ValidationException} instead (422) --
 * mirroring how {@see SellerApiKeyScopeValidator} keeps input-shape
 * rejections on `ValidationException` while state-conflict/safety
 * rejections stay on this dedicated domain exception.
 */
final class SellerWebhookException extends \DomainException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function sellerNotActive(string $status): self
    {
        return new self("Seller is '{$status}'; webhook management is unavailable.", 'seller_inactive');
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
            "The acting user's current role does not hold 'commerce.seller.webhooks.manage'.",
            'capability_denied'
        );
    }

    /**
     * $reason is the framework `SafeOutboundTargetResolver`'s OWN exception
     * message -- already generic and never containing a resolved internal
     * address (see this class's own docblock).
     */
    public static function unsafeUrl(string $reason): self
    {
        return new self('The webhook URL failed safety validation: ' . $reason, 'unsafe_url');
    }

    public static function noCurrentSecret(): self
    {
        return new self(
            'No current signing secret is available for this webhook endpoint.',
            'secret_unavailable'
        );
    }
}
