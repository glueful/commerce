<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `PATCH /commerce/seller/{sellerUuid}/webhooks/{uuid}` request body (design
 * spec §2.2/§2.10, MV5c-2 Task 7): both `url` and `events` are OPTIONAL --
 * either, both, or neither may be given (a request with neither is a
 * meaningful, harmless no-op --
 * {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointService::updateEndpoint()}
 * only writes/audits the fields that actually changed). A given `url` change
 * RE-VALIDATES SSRF (same rules as register); a given `events` list is
 * RE-VALIDATED against the catalog -- both in the SERVICE, never this DTO,
 * mirroring {@see RegisterSellerWebhookEndpointData}'s identical "shape only"
 * convention.
 */
final class UpdateSellerWebhookEndpointData implements RequestData
{
    /** @param list<string>|null $events */
    public function __construct(
        #[Rule('string|max:2048')]
        public readonly ?string $url = null,
        #[ArrayOf('string')]
        #[Rule('array')]
        public readonly ?array $events = null,
    ) {
    }
}
