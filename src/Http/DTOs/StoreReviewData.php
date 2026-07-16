<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Storefront public review submit (design spec Layer 6 §2 decision 6):
 * body-sourced (no #[FromQuery]/#[FromRoute] on any property -- the product
 * is identified by the route's `{slug}`, never a body field, so this DTO
 * carries no `product_uuid`).
 *
 * Deliberately carries NO `user_uuid` property either -- the framework has no
 * optional-auth seam for this genuinely public endpoint, so a caller-supplied
 * `user_uuid` in the JSON payload is structurally unreachable rather than
 * merely unused: {@see \Glueful\Validation\RequestDataHydrator} only ever
 * reads request keys that match a declared constructor parameter name, so an
 * extra `user_uuid` key in the body is silently ignored during hydration and
 * never reaches {@see \Glueful\Extensions\Commerce\Catalog\ReviewService::createForStorefront()},
 * which always stores `user_uuid = null` regardless.
 *
 * `author_name`/`author_email` are bounded to the `commerce_reviews` column
 * widths (255) and `body` to a 10,000-character HTTP cap directly via the
 * string-length `max:` rule (`Glueful\Validation\Rules\Length`, mb_strlen-based
 * -- safe here because all three properties are real `string` types). `rating`
 * deliberately carries NO `min`/`max` rule: the framework's `min`/`max` map
 * unconditionally to that same Length rule regardless of the property's PHP
 * type (see `CreateReviewData`'s docblock for the same finding), so applying
 * it to an `int` property would always fail ("Expected string."), not bound
 * the numeric range. The 1-5 range is enforced in `ReviewService`, exactly
 * like `CreateReviewData::$rating`.
 */
final class StoreReviewData implements RequestData
{
    public function __construct(
        #[Rule('required|integer')]
        public readonly int $rating,
        #[Rule('required|string|max:10000')]
        public readonly string $body,
        #[Rule('required|string|max:255')]
        public readonly string $author_name,
        #[Rule('required|email|max:255')]
        public readonly string $author_email,
    ) {
    }
}
