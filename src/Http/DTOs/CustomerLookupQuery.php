<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Design spec §7/Resolved Decision 2: the `{key}` route segment's kind is
 * NEVER inferred (no "looks like a uuid" heuristic) — the caller must say
 * explicitly whether it's a user uuid or an email. Missing/invalid `by` is a
 * 422, never a silent default.
 */
final class CustomerLookupQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Whether {key} is a user uuid or an email address.')]
        #[Rule('required|in:user,email')]
        public readonly string $by,
    ) {
    }
}
