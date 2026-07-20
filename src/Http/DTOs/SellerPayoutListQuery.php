<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `GET /commerce/seller/{sellerUuid}/payouts` query params (design spec
 * §6.2, MV3 Task 11): an optional currency filter (payouts are currency-
 * scoped, mirroring the ledger's own currency separation, design spec §2.9)
 * plus house pagination, the SAME shape {@see SellerOrderListQuery} already
 * establishes for the seller-order list.
 */
final class SellerPayoutListQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Filter by ISO-4217 currency code.')]
        #[Rule('string|max:3')]
        public readonly ?string $currency = null,
        #[FromQuery(description: 'Page number.')]
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(description: 'Items per page, clamped to 100.')]
        #[Rule('numeric')]
        public readonly ?int $per_page = null,
    ) {
    }
}
