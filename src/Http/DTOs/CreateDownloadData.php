<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CreateDownloadData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $blob_uuid,
        #[Rule('required|string|max:255')]
        public readonly string $name,
        #[Rule('integer')]
        public readonly ?int $download_limit = null,
        #[Rule('integer')]
        public readonly ?int $expiry_days = null,
        #[Rule('integer')]
        public readonly ?int $position = null,
    ) {
    }
}
