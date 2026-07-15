<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Documentation-only schema (see {@see UpdateAddonData}): the controller reads
 * the raw request body directly so the service can distinguish an absent field
 * (leave unchanged) from an explicit null for `download_limit`/`expiry_days`
 * (a real value: unlimited/never).
 */
final class UpdateDownloadData implements RequestData
{
    public function __construct(
        #[Rule('string|max:255')]
        public readonly ?string $name = null,
        #[Rule('integer')]
        public readonly ?int $download_limit = null,
        #[Rule('integer')]
        public readonly ?int $expiry_days = null,
        #[Rule('integer')]
        public readonly ?int $position = null,
        #[Rule('in:active,inactive')]
        public readonly ?string $status = null,
    ) {
    }
}
