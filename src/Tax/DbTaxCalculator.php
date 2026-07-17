<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tax;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\LineTaxCalculator;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Pricing\TaxBreakdown;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * Data-driven tax quotes from `commerce_tax_rates` (design spec §5). Never
 * consulted directly by the contract default -- {@see DelegatingTaxCalculator}
 * routes here only when the current tenant has at least one rate row.
 *
 * Address matching convention (BINDING -- mirrors
 * {@see \Glueful\Extensions\Commerce\Shipping\ZoneMatcher}'s docblock EXACTLY
 * so shipping and tax addressing never silently diverge): `country`
 * (ISO-3166 alpha-2, case-insensitive), optional bare `state` region --
 * composed with the address's own `country` into the `COUNTRY:REGION` form a
 * rate's `state` column stores (e.g. `'US:CA'`) -- and optional `postcode`
 * (normalized exact-or-single-trailing-wildcard match against a rate's
 * `postcode_pattern`). Unlike a shipping zone (many location rows OR'd within
 * a geographic group), each tax rate is exactly ONE row: its own `country`
 * MUST equal the address country; a non-null `state`/`postcode_pattern`
 * additionally narrows. There is no "zero location = everywhere" analogue --
 * `country` is a required column on every rate row.
 *
 * Rate selection per class (spec §5): among address-matched rows for that
 * class, ordered `priority ASC, uuid ASC` (the order {@see TaxRateRepository::search()}
 * already returns), the FIRST match applies; no match taxes that class at 0 --
 * tax classes are an intentionally open vocabulary, so a syntactically valid
 * class with no corresponding rate is allowed rather than falling back to an
 * implicit `standard` rate. Shipping is taxed by the first address-matched
 * `standard`-class rate whose `shipping_taxable` is true; no such rate leaves
 * shipping untaxed.
 *
 * `TaxQuote::$label` is the sole applied rate's label when exactly one
 * DISTINCT rate row (by uuid) contributed to the quote, regardless of which
 * class(es) it taxed; with zero or multiple distinct rates the label is the
 * `TaxQuote` default, `'Tax'`.
 *
 * Direct aggregate `quote()` cannot distinguish merchandise from shipping --
 * it treats the caller-supplied amount as ONE opaque `standard`-class taxable
 * base (no `shipping_taxable` narrowing). This is deliberately different from
 * the checkout flow, which never reaches this method through
 * {@see DelegatingTaxCalculator} once rows exist (the delegator always prefers
 * `quoteDetailed()`); `quote()` remains for direct/backward-compatible callers
 * only.
 */
final class DbTaxCalculator implements TaxCalculator, LineTaxCalculator
{
    public function __construct(
        private TaxRateRepository $rates,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /** @param array<string,mixed> $shippingAddress */
    public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
    {
        if ($taxableAmount <= 0) {
            return new TaxQuote(0);
        }

        $tenant = $this->tenants->tenantUuid($context);
        $candidates = $this->candidateRates($context, $tenant, $shippingAddress);
        $rate = $this->firstMatching($candidates, 'standard', false);
        if ($rate === null) {
            return new TaxQuote(0);
        }

        return new TaxQuote($this->applyRate($taxableAmount, (int) $rate['rate_bps']), (string) $rate['label']);
    }

    /**
     * @param list<array{taxable_amount:int, tax_class:string, quantity:int, line_uuid:string}> $taxableLines
     * @param array<string,mixed> $shippingAddress
     */
    public function quoteDetailed(
        ApplicationContext $context,
        array $taxableLines,
        int $shippingAmount,
        array $shippingAddress
    ): TaxQuote {
        $tenant = $this->tenants->tenantUuid($context);
        $candidates = $this->candidateRates($context, $tenant, $shippingAddress);

        $total = 0;
        $applied = [];

        // Accumulated separately from $total (design spec §2.4) so a
        // TaxBreakdown can be attached below: taxByLine keyed by each
        // line's line_uuid (merchandise tax only) and shippingTaxTotal
        // tracked as its own scalar, rather than folded together.
        $taxByLine = [];
        $knownLineUuids = [];
        $shippingTaxTotal = 0;

        foreach ($taxableLines as $line) {
            $lineUuid = (string) $line['line_uuid'];
            $knownLineUuids[] = $lineUuid;

            $taxableAmount = (int) $line['taxable_amount'];
            if ($taxableAmount <= 0) {
                continue;
            }

            $class = (string) $line['tax_class'];
            $rate = $this->firstMatching($candidates, $class, false);
            if ($rate === null) {
                continue;
            }

            $lineTax = $this->applyRate($taxableAmount, (int) $rate['rate_bps']);
            $taxByLine[$lineUuid] = ($taxByLine[$lineUuid] ?? 0) + $lineTax;
            $total += $lineTax;
            $applied[(string) $rate['uuid']] = (string) $rate['label'];
        }

        if ($shippingAmount > 0) {
            $shippingRate = $this->firstMatching($candidates, 'standard', true);
            if ($shippingRate !== null) {
                $shippingTaxTotal = $this->applyRate($shippingAmount, (int) $shippingRate['rate_bps']);
                $total += $shippingTaxTotal;
                $applied[(string) $shippingRate['uuid']] = (string) $shippingRate['label'];
            }
        }

        $label = count($applied) === 1 ? (string) array_values($applied)[0] : 'Tax';
        $breakdown = new TaxBreakdown($taxByLine, $shippingTaxTotal, $knownLineUuids);

        return new TaxQuote($total, $label, $breakdown);
    }

    /**
     * Delegation predicate (spec §4): whether the CURRENT tenant has opted
     * into data-driven tax at all -- kept here rather than duplicated onto
     * {@see DelegatingTaxCalculator} so its constructor stays to exactly its
     * two calculators (Db + FlatRate), mirroring
     * {@see \Glueful\Extensions\Commerce\Shipping\DbShippingRateProvider::hasZonesForCurrentTenant()}.
     */
    public function hasRatesForCurrentTenant(ApplicationContext $context): bool
    {
        return $this->rates->existsForTenant($context, $this->tenants->tenantUuid($context));
    }

    /**
     * @param array<string,mixed> $shippingAddress
     * @return list<array<string,mixed>> address-matched rate rows for the
     *   tenant, ordered priority ASC, uuid ASC (rate selection order) -- one
     *   query per quote regardless of how many distinct classes/shipping are
     *   evaluated against it.
     */
    private function candidateRates(ApplicationContext $context, string $tenant, array $shippingAddress): array
    {
        $country = $this->addressCountry($shippingAddress);
        if ($country === '') {
            return [];
        }

        $rows = $this->rates->search($context, $tenant, $country, null);

        return array_values(array_filter(
            $rows,
            fn (array $rate): bool => $this->addressMatchesRate($rate, $shippingAddress, $country)
        ));
    }

    /** @param list<array<string,mixed>> $candidates @return array<string,mixed>|null */
    private function firstMatching(array $candidates, string $class, bool $requireShippingTaxable): ?array
    {
        foreach ($candidates as $rate) {
            if ((string) $rate['class'] !== $class) {
                continue;
            }
            if ($requireShippingTaxable && !(bool) $rate['shipping_taxable']) {
                continue;
            }

            return $rate;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $rate
     * @param array<string,mixed> $address
     */
    private function addressMatchesRate(array $rate, array $address, string $country): bool
    {
        if ((string) $rate['country'] !== $country) {
            return false;
        }

        $state = $rate['state'] ?? null;
        if ($state !== null) {
            $composite = $this->addressStateComposite($address, $country);
            if ($composite === null || $composite !== (string) $state) {
                return false;
            }
        }

        $pattern = $rate['postcode_pattern'] ?? null;
        if ($pattern !== null) {
            $postcode = $this->addressPostcode($address);
            if ($postcode === null || !$this->matchesPattern($postcode, (string) $pattern)) {
                return false;
            }
        }

        return true;
    }

    private function matchesPattern(string $postcode, string $pattern): bool
    {
        if (str_ends_with($pattern, '*')) {
            return str_starts_with($postcode, substr($pattern, 0, -1));
        }

        return $postcode === $pattern;
    }

    /** @param array<string,mixed> $address */
    private function addressCountry(array $address): string
    {
        $raw = $address['country'] ?? null;
        if (!is_string($raw) && !is_int($raw)) {
            return '';
        }

        return strtoupper(trim((string) $raw));
    }

    /** @param array<string,mixed> $address */
    private function addressStateComposite(array $address, string $country): ?string
    {
        $raw = $address['state'] ?? null;
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        $region = strtoupper(trim((string) $raw));
        if ($region === '' || $country === '') {
            return null;
        }

        return $country . ':' . $region;
    }

    /** @param array<string,mixed> $address */
    private function addressPostcode(array $address): ?string
    {
        $raw = $address['postcode'] ?? null;
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        $postcode = strtoupper(trim((string) $raw));

        return $postcode === '' ? null : $postcode;
    }

    /**
     * House half-up rounding (`intdiv(x*bps + 5000, 10000)`), with a
     * range-checked multiply guarding against integer overflow BEFORE the
     * multiplication is ever evaluated (spec §2/§5) -- fails loudly via
     * {@see TaxRateOverflowException} rather than silently wrapping or
     * truncating.
     */
    private function applyRate(int $amount, int $bps): int
    {
        if ($amount === 0 || $bps === 0) {
            return 0;
        }

        if ($amount > intdiv(PHP_INT_MAX - 5000, $bps)) {
            throw new TaxRateOverflowException($amount, $bps);
        }

        return intdiv($amount * $bps + 5000, 10000);
    }
}
