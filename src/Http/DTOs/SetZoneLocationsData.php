<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Contracts\RequestData;

final class SetZoneLocationsData implements RequestData
{
    /**
     * No `required|array` rule: an empty list is a valid, meaningful request (a
     * zone with zero locations is the "everywhere" zone, spec §3), and the
     * framework's `required` rule treats an empty array as absent. Each row is
     * `{kind: string, value: string}`; format validation (ISO alpha-2 country,
     * COUNTRY:REGION state, exact-or-single-trailing-wildcard postcode_pattern)
     * and the postcode-needs-country set rule both happen in
     * {@see \Glueful\Extensions\Commerce\Shipping\ShippingZoneService::setLocations()}.
     *
     * @param list<array<string,mixed>>|null $locations
     */
    public function __construct(
        public readonly ?array $locations = null,
    ) {
    }
}
