<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tax;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\OpenVocabularySlug;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Tax rate CRUD (design spec §2, §6). `commerce_tax_rates` carries its own
 * revision column -- PATCH/DELETE claim it directly (URL's primary resource; a
 * failed claim is a non-revealing 404). Unlike shipping classes, tax rates have
 * no cross-table reference to guard at delete time, so DELETE is unconditional
 * once claimed.
 *
 * Write-time validation (spec §6):
 * - `rate_bps` an integer in `0..10000` inclusive.
 * - `country` normalized ISO-3166 alpha-2, uppercased.
 * - `state` null, or `COUNTRY:REGION` whose country prefix EQUALS the row's
 *   OWN `country` (`country=US`, `state=CA:ON` is rejected) -- unlike a zone
 *   location's `state`, which only needs to match the ADDRESS's country, a rate's
 *   `state` must be internally consistent with its own `country` column.
 * - `postcode_pattern` null, or the same normalized exact-or-single-trailing-
 *   wildcard grammar as zone locations (spec §2).
 * - `class` the open-vocabulary rule (default 'standard' when omitted).
 * - `label` required, non-empty.
 *
 * On UPDATE, a `country` change re-validates any EXISTING (unchanged) `state`
 * against the new country -- otherwise a bare `country` PATCH could silently
 * strand a `state` whose prefix now disagrees with its own row's `country`.
 */
final class TaxRateService
{
    public function __construct(
        private TaxRateRepository $rates,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function list(ApplicationContext $c, ?string $country, ?string $class, int $page, int $perPage): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $normalizedCountry = $country !== null ? $this->normalizeCountry($country) : null;
        $normalizedClass = $class !== null ? OpenVocabularySlug::normalize($class, 'class') : null;

        return $this->rates->paginatedSearch($c, $tenant, $normalizedCountry, $normalizedClass, $page, $perPage);
    }

    /** @return array<string,mixed> */
    public function show(ApplicationContext $c, string $uuid): array
    {
        $rate = $this->rates->findByUuid($c, $this->tenants->tenantUuid($c), $uuid);
        if ($rate === null) {
            throw new NotFoundException('Resource not found.');
        }

        return $rate;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(ApplicationContext $c, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $row = $this->planCreate($input);

        $uuid = Utils::generateNanoID();
        $this->rates->insert($c, array_merge($row, ['uuid' => $uuid, 'tenant_uuid' => $tenant]));

        $rate = $this->rates->findByUuid($c, $tenant, $uuid);
        if ($rate === null) {
            throw new \RuntimeException('Created tax rate could not be reloaded.');
        }

        return $rate;
    }

    /**
     * @param array<string,mixed> $changes only present keys are applied
     * @return array<string,mixed>
     */
    public function update(ApplicationContext $c, string $uuid, array $changes): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        return db($c)->transaction(function () use ($c, $tenant, $uuid, $changes): array {
            if (!$this->rates->claimRevision($c, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->rates->findByUuid($c, $tenant, $uuid);
            if ($current === null) {
                throw new NotFoundException('Resource not found.');
            }

            $set = $this->planUpdate($changes, $current);
            if ($set !== []) {
                $this->rates->update($c, $tenant, $uuid, $set);
            }

            $rate = $this->rates->findByUuid($c, $tenant, $uuid);
            if ($rate === null) {
                throw new \RuntimeException('Updated tax rate could not be reloaded.');
            }

            return $rate;
        });
    }

    public function delete(ApplicationContext $c, string $uuid): void
    {
        $tenant = $this->tenants->tenantUuid($c);

        db($c)->transaction(function () use ($c, $tenant, $uuid): void {
            if (!$this->rates->claimRevision($c, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }
            if ($this->rates->findByUuid($c, $tenant, $uuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $this->rates->delete($c, $tenant, $uuid);
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function planCreate(array $input): array
    {
        $country = $this->normalizeCountry($this->requiredString($input, 'country'));
        $state = isset($input['state']) && $input['state'] !== null
            ? $this->normalizeState($input['state'], $country)
            : null;
        $postcode = isset($input['postcode_pattern']) && $input['postcode_pattern'] !== null
            ? $this->normalizePostcode($input['postcode_pattern'])
            : null;
        $rateBps = $this->normalizeBps($input['rate_bps'] ?? null);
        $label = $this->requiredString($input, 'label');
        $priority = isset($input['priority']) ? (int) $input['priority'] : 0;
        $shippingTaxable = (bool) ($input['shipping_taxable'] ?? false);
        $class = OpenVocabularySlug::normalize((string) ($input['class'] ?? 'standard'), 'class');

        return [
            'country' => $country,
            'state' => $state,
            'postcode_pattern' => $postcode,
            'rate_bps' => $rateBps,
            'label' => $label,
            'priority' => $priority,
            'shipping_taxable' => $shippingTaxable,
            'class' => $class,
        ];
    }

    /**
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $current fresh post-claim row
     * @return array<string,mixed>
     */
    private function planUpdate(array $changes, array $current): array
    {
        $set = [];

        $touchesCountry = array_key_exists('country', $changes) && $changes['country'] !== null;
        $effectiveCountry = $touchesCountry
            ? $this->normalizeCountry((string) $changes['country'])
            : (string) $current['country'];
        if ($touchesCountry) {
            $set['country'] = $effectiveCountry;
        }

        if (array_key_exists('state', $changes)) {
            $set['state'] = $changes['state'] === null
                ? null
                : $this->normalizeState($changes['state'], $effectiveCountry);
        } elseif ($touchesCountry && $current['state'] !== null) {
            // A country change must re-validate the (unchanged) state against the
            // NEW effective country -- a stale state prefix silently surviving a
            // country change would violate the "state prefix equals own country"
            // invariant this service otherwise enforces on every write.
            $set['state'] = $this->normalizeState($current['state'], $effectiveCountry);
        }

        if (array_key_exists('postcode_pattern', $changes)) {
            $set['postcode_pattern'] = $changes['postcode_pattern'] === null
                ? null
                : $this->normalizePostcode($changes['postcode_pattern']);
        }

        if (array_key_exists('rate_bps', $changes) && $changes['rate_bps'] !== null) {
            $set['rate_bps'] = $this->normalizeBps($changes['rate_bps']);
        }

        if (array_key_exists('label', $changes) && $changes['label'] !== null) {
            $label = trim((string) $changes['label']);
            if ($label === '') {
                throw ValidationException::forField('label', 'Label is required.');
            }
            $set['label'] = $label;
        }

        if (array_key_exists('priority', $changes) && $changes['priority'] !== null) {
            $set['priority'] = (int) $changes['priority'];
        }

        if (array_key_exists('shipping_taxable', $changes) && $changes['shipping_taxable'] !== null) {
            $set['shipping_taxable'] = (bool) $changes['shipping_taxable'];
        }

        if (array_key_exists('class', $changes) && $changes['class'] !== null) {
            $set['class'] = OpenVocabularySlug::normalize((string) $changes['class'], 'class');
        }

        return $set;
    }

    private function normalizeCountry(string $raw): string
    {
        $country = strtoupper(trim($raw));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw ValidationException::forField('country', 'country must be an ISO-3166 alpha-2 code.');
        }

        return $country;
    }

    private function normalizeState(mixed $raw, string $country): string
    {
        $state = is_string($raw) ? trim(strtoupper($raw)) : '';
        if (preg_match('/^([A-Z]{2}):([A-Z0-9]+)$/', $state, $matches) !== 1) {
            throw ValidationException::forField(
                'state',
                'state must be COUNTRY:REGION with a valid ISO-3166 alpha-2 country prefix.'
            );
        }
        if ($matches[1] !== $country) {
            throw ValidationException::forField(
                'state',
                "state's country prefix must equal this rate's country ({$country})."
            );
        }

        return $state;
    }

    private function normalizePostcode(mixed $raw): string
    {
        $pattern = is_string($raw) || is_int($raw) ? trim(strtoupper((string) $raw)) : '';
        if (preg_match('/^[A-Z0-9]+\*?$/', $pattern) !== 1) {
            throw ValidationException::forField(
                'postcode_pattern',
                'postcode_pattern must be an exact value or end with a single trailing wildcard (*).'
            );
        }

        return $pattern;
    }

    private function normalizeBps(mixed $raw): int
    {
        if (!is_int($raw) || $raw < 0 || $raw > 10000) {
            throw ValidationException::forField(
                'rate_bps',
                'rate_bps must be an integer between 0 and 10000 inclusive.'
            );
        }

        return $raw;
    }

    /** @param array<string,mixed> $input */
    private function requiredString(array $input, string $field): string
    {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value === '') {
            throw ValidationException::forField($field, ucfirst($field) . ' is required.');
        }

        return $value;
    }
}
