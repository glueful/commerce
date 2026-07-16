<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CreateReviewData implements RequestData
{
    /**
     * `rating` carries no `min`/`max` rule: the framework's `min`/`max` map to a
     * STRING-length rule (Glueful\Validation\Rules\Length), not a numeric bound
     * (see CreateRefundData's docblock for the same finding) -- so the 1-5 range
     * is enforced in ReviewService, not here.
     */
    public function __construct(
        #[Rule('required|string')]
        public readonly string $product_uuid,
        #[Rule('required|integer')]
        public readonly int $rating,
        #[Rule('required|string')]
        public readonly string $body,
        #[Rule('required|string')]
        public readonly string $author_name,
        #[Rule('required|email')]
        public readonly string $author_email,
        #[Rule('string')]
        public readonly ?string $user_uuid = null,
    ) {
    }
}
