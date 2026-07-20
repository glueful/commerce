<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `POST /commerce/seller/{sellerUuid}/webhooks` request body (design spec
 * §2.2/§2.10, MV5c-2 Task 7): `url` + a non-empty `events` list. This DTO
 * only enforces basic shape -- the REAL work (SSRF-safety validation of
 * `url`, catalog-subset validation of `events`) happens in
 * {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointService::register()}
 * (via its own `resolveUrlOrFail()`/`validateEvents()`), which throws a
 * {@see \Glueful\Validation\ValidationException} or
 * {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookException} the
 * controller maps to 422 -- never this DTO's concern.
 *
 * `#[Rule('required|array')]` on `events` already rejects `[]` (the
 * framework's own `Rules\Required` treats an empty array as "not provided",
 * identical to a blank string or `null`), mirroring
 * {@see CreateSellerApiKeyData}'s identical `declared_scopes` convention --
 * no separate min-count check needed here.
 */
final class RegisterSellerWebhookEndpointData implements RequestData
{
    /** @param list<string> $events */
    public function __construct(
        #[Rule('required|string|max:2048')]
        public readonly string $url = '',
        #[ArrayOf('string')]
        #[Rule('required|array')]
        public readonly array $events = [],
    ) {
    }
}
