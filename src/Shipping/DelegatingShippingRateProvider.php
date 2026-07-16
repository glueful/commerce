<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Shipping;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;

/**
 * The `ShippingRateProvider::class` default (design spec §4): Db when the
 * current tenant has at least one shipping zone row, else config -- one
 * existence query per quote ({@see DbShippingRateProvider::hasZonesForCurrentTenant()},
 * index-covered). A tenant is always wholly on one source or the other; rows
 * are never mixed per-request. An application that rebinds
 * `ShippingRateProvider::class` replaces this whole chain (its DI definition
 * wins over {@see \Glueful\Extensions\Commerce\CommerceServiceProvider}'s).
 */
final class DelegatingShippingRateProvider implements ShippingRateProvider
{
    public function __construct(
        private DbShippingRateProvider $db,
        private ConfigShippingRateProvider $config,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $shippingAddress
     * @return list<ShippingQuote>
     */
    public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
    {
        $provider = $this->db->hasZonesForCurrentTenant($context) ? $this->db : $this->config;

        return $provider->quote($context, $lines, $shippingAddress);
    }
}
