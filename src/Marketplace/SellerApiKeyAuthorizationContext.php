<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * The typed result of a PASSED {@see SellerApiKeyAuthorizer::authorize()}
 * call (design spec §2.3 step 6, MV5c-1 Task 4): carries exactly what
 * {@see \Glueful\Extensions\Commerce\Http\Middleware\SellerMemberMiddleware}
 * needs to (a) run its OWN existing seller/membership/lifecycle/live-role
 * checks against the SAME resolved binding, and (b) key a denial audit row
 * on that binding's lineage via {@see SellerApiKeyAuthorizer::recordDenied()}
 * if any of those checks subsequently fails.
 *
 * A `null` value of this type (never an instance) is what
 * {@see SellerApiKeyAuthorizer::authorize()} returns for a non-API-key
 * (session) request -- see that class's docblock for the full contract.
 * There is deliberately no "denied" state on this class: a denial is either
 * the off-invariant `null` (not a key request) or a `Response` returned
 * directly by `authorize()` (see its docblock) -- an instance of THIS class
 * only ever exists once a request has passed every authorizer-owned check
 * (principal integrity, exact scope match, one-seller, effective-scope
 * gate).
 */
final class SellerApiKeyAuthorizationContext
{
    /**
     * @param array<string,mixed> $lineage the `commerce_seller_api_keys` row (design spec §2.2)
     * @param array<string,mixed> $credential the `commerce_seller_api_key_credentials` row
     */
    public function __construct(
        public readonly string $tenant,
        public readonly array $lineage,
        public readonly array $credential,
        public readonly string $subjectUserUuid,
        public readonly string $sellerUuid,
    ) {
    }

    public function lineageUuid(): string
    {
        return (string) ($this->lineage['uuid'] ?? '');
    }
}
