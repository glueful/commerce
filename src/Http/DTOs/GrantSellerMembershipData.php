<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class GrantSellerMembershipData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $user_uuid = '',
        #[Rule('required|string')]
        public readonly string $role = '',
    ) {
    }
}
