<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Shipping;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;

final class ConfigShippingRateProvider implements ShippingRateProvider
{
    public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
    {
        if ($lines === [] || $this->isDigitalOnly($lines)) {
            return [];
        }

        $country = strtoupper((string) ($shippingAddress['country'] ?? ''));
        $subtotal = $this->subtotal($lines);
        $methods = config($context, 'commerce.shipping.methods', []);
        if (!is_array($methods)) {
            return [];
        }

        $quotes = [];
        foreach ($methods as $method) {
            if (!is_array($method) || !$this->matchesZone($method, $country)) {
                continue;
            }

            $amount = (int) ($method['amount'] ?? 0);
            if (isset($method['free_over']) && $subtotal >= (int) $method['free_over']) {
                $amount = 0;
            }

            $quotes[] = new ShippingQuote(
                (string) ($method['id'] ?? 'shipping'),
                (string) ($method['label'] ?? 'Shipping'),
                $amount
            );
        }

        return $quotes;
    }

    /** @param list<array<string,mixed>> $lines */
    private function isDigitalOnly(array $lines): bool
    {
        foreach ($lines as $line) {
            if (($line['type'] ?? 'physical') !== 'digital') {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string,mixed>> $lines */
    private function subtotal(array $lines): int
    {
        $subtotal = 0;
        foreach ($lines as $line) {
            $subtotal += (int) ($line['unit_price'] ?? 0) * (int) ($line['quantity'] ?? 0);
        }

        return $subtotal;
    }

    /** @param array<string,mixed> $method */
    private function matchesZone(array $method, string $country): bool
    {
        if (!isset($method['zones']) || !is_array($method['zones']) || $method['zones'] === []) {
            return true;
        }

        return in_array($country, array_map('strtoupper', array_map('strval', $method['zones'])), true);
    }
}
