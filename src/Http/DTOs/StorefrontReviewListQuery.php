<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Storefront approved-review list pagination (design spec Layer 6 §2 decision
 * 6). Deliberately carries no `status`/`product` fields, unlike the
 * admin-only {@see ReviewListQuery}: `status` is hardcoded to `approved`
 * server-side and the product comes from the route's `{slug}`, never a query
 * param, on this genuinely public surface.
 */
final class StorefrontReviewListQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Page number.')]
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(description: 'Items per page, clamped to 100.')]
        #[Rule('numeric')]
        public readonly ?int $per_page = null,
    ) {
    }
}
