<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `POST /commerce/admin/marketplace/payouts/accounts/sync` request body
 * (design spec §2.7, MV4 Task 10): trigger a DNS-style readiness sync for one
 * (seller, provider) destination -- readiness is always provider-sourced
 * ({@see \Glueful\Extensions\Commerce\Marketplace\PayoutAccountService::sync()}),
 * never operator-asserted.
 */
final class SyncPayoutAccountData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $seller_uuid = '',
        #[Rule('required|string|max:32')]
        public readonly string $provider = '',
    ) {
    }
}
