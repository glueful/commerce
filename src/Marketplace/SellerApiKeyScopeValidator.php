<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\Contracts\SellerRoleAuthority;
use Glueful\Validation\ValidationException;

/**
 * Seller-API-key declared-scope validation (design spec §2.5): canonicalizes
 * a caller-declared scope list and enforces the three-way grantability rule
 * BEFORE any key is minted -- a key's declared scopes must be an exact,
 * non-empty subset of BOTH the fixed {@see SellerApiKeyCapabilityCatalog}
 * (machine-grantable at all) AND the acting subject's LIVE role capabilities
 * (via the injected {@see SellerRoleAuthority} -- never
 * `FixedSellerRoleAuthority` directly, and never a method absent from the
 * `SellerRoleAuthority` interface).
 *
 * {@see SellerApiKeyCapabilityCatalog} is used ONLY through its own static
 * `contains()` -- it is a fixed, stateless, code-defined allow-list (no
 * per-request/per-tenant state), so it is deliberately NOT constructor
 * injected, mirroring how {@see \Glueful\Helpers\Utils} /
 * {@see \Glueful\Extensions\Commerce\Support\UtcNowSql} are called
 * statically elsewhere in this codebase rather than injected.
 *
 * Canonicalization: trim every entry, drop blanks, de-duplicate, and sort
 * (`SORT_STRING`) -- the exact form persisted as `declared_scopes` JSON and
 * the exact form the design spec §2.3 step 3 per-request authorizer later
 * compares the framework-authenticated scope copy against for EXACT
 * equality, so this canonical form must be deterministic and stable.
 *
 * Rejection order (design spec §2.5, 422 {@see ValidationException} for
 * every branch): EMPTY (after canonicalization) -> any WILDCARD/fnmatch
 * metacharacter -> any scope outside {@see SellerApiKeyCapabilityCatalog}
 * (this single check covers BOTH "not an exact known capability slug" and
 * "known but non-grantable", e.g. `commerce.seller.apikeys.manage` /
 * `commerce.seller.members.manage` -- the catalog IS the exact, known,
 * grantable vocabulary, so anything outside it is rejected identically
 * whether it's gibberish or a real-but-forbidden capability) -> any scope
 * not currently held by the subject's live role.
 */
final class SellerApiKeyScopeValidator
{
    public function __construct(private SellerRoleAuthority $roles)
    {
    }

    /**
     * @param list<string> $declared
     * @return list<string> the canonical (trimmed, de-duplicated, sorted) scope list
     */
    public function validate(array $declared, string $role): array
    {
        $canonical = $this->canonicalize($declared);

        if ($canonical === []) {
            throw ValidationException::forField('scopes', 'At least one scope is required.');
        }

        foreach ($canonical as $scope) {
            if (preg_match('/[*?\[\]]/', $scope) === 1) {
                throw ValidationException::forField(
                    'scopes',
                    "Scope '{$scope}' may not contain a wildcard or pattern character."
                );
            }
        }

        foreach ($canonical as $scope) {
            if (!SellerApiKeyCapabilityCatalog::contains($scope)) {
                throw ValidationException::forField(
                    'scopes',
                    "Scope '{$scope}' is not a valid API-key-grantable capability."
                );
            }
        }

        $heldByRole = $this->roles->capabilitiesFor($role);
        foreach ($canonical as $scope) {
            if (!in_array($scope, $heldByRole, true)) {
                throw ValidationException::forField(
                    'scopes',
                    "Scope '{$scope}' is not held by the current role."
                );
            }
        }

        return $canonical;
    }

    /**
     * @param list<string> $declared
     * @return list<string>
     */
    private function canonicalize(array $declared): array
    {
        $unique = [];
        foreach ($declared as $scope) {
            if (!is_string($scope)) {
                throw ValidationException::forField('scopes', 'Every scope must be a string.');
            }
            $trimmed = trim($scope);
            if ($trimmed !== '') {
                $unique[$trimmed] = true;
            }
        }

        $canonical = array_keys($unique);
        sort($canonical, SORT_STRING);

        return $canonical;
    }
}
