<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;

/**
 * `POST /commerce/seller/{sellerUuid}/api-keys` request body (design spec
 * §2.8, MV5c-1 Task 6): `name` + a non-empty `declared_scopes` list --
 * {@see \Glueful\Extensions\Commerce\Marketplace\SellerApiKeyService::create()}
 * (via {@see \Glueful\Extensions\Commerce\Marketplace\SellerApiKeyScopeValidator})
 * validates the scopes FULLY against the freshly-derived live role + the
 * grantable catalog; this DTO only enforces basic shape -- an array of
 * strings, at least one entry. `#[Rule('required|array')]` already rejects
 * `[]` (the framework's own `Rules\Required` treats an empty array as "not
 * provided", identical to a blank string or `null`), so no separate
 * min-count check is needed here.
 *
 * `expires_at` (Task 6 carry-forward, commit-1 review Minor 1): the SERVICE
 * already enforces "parseable UTC, strictly after DB-now"
 * ({@see \Glueful\Extensions\Commerce\Marketplace\SellerApiKeyService::validateExpiry()})
 * -- but PHP's `DateTimeImmutable` constructor happily parses a RELATIVE
 * expression too (`+1 day`, `now`, `tomorrow`), which resolves to a real,
 * zero-UTC-offset, future timestamp and would otherwise sail straight
 * through that check. This DTO closes that gap: {@see self::validate()}
 * requires an ABSOLUTE `Y-m-d[ T]H:i:s`-style / ISO-8601 timestamp (a
 * leading 4-digit year) BEFORE the value ever reaches the service -- a
 * relative expression never matches {@see self::EXPIRES_AT_PATTERN} and is
 * rejected here with a 422, never silently accepted as "the future".
 */
final class CreateSellerApiKeyData implements RequestData, ValidatesSelf
{
    private const EXPIRES_AT_PATTERN =
        '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/';

    /** @param list<string> $declared_scopes */
    public function __construct(
        #[Rule('required|string|max:120')]
        public readonly string $name = '',
        #[ArrayOf('string')]
        #[Rule('required|array')]
        public readonly array $declared_scopes = [],
        #[Rule('string')]
        public readonly ?string $expires_at = null,
    ) {
    }

    /** @return array<string,list<string>> */
    public function validate(): array
    {
        $errors = [];

        $expiresAt = $this->expires_at !== null ? trim($this->expires_at) : '';
        if ($expiresAt !== '' && preg_match(self::EXPIRES_AT_PATTERN, $expiresAt) !== 1) {
            $errors['expires_at'][] = 'expires_at must be an absolute UTC timestamp '
                . '(e.g. "2026-08-01 12:00:00" or "2026-08-01T12:00:00Z"), never a relative expression '
                . '(e.g. "+1 day", "now", "tomorrow").';
        }

        return $errors;
    }
}
