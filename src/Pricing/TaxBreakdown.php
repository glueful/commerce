<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Pricing;

/**
 * Per-line tax attribution attached to a `TaxQuote` when a calculator
 * genuinely computed tax per line (design spec §2.4, pinned) -- as opposed
 * to an aggregate/allocated result, which carries no `TaxBreakdown` at all.
 * Detection downstream is by BREAKDOWN PRESENCE, never `instanceof`.
 *
 * Validation runs in a fixed order against the RAW `$knownLineUuids` list:
 *
 * 1. Duplicate VALUES in `$knownLineUuids` are rejected before anything
 *    else -- once PHP folds a list into an associative map, duplicate keys
 *    are unrecoverable, so this must be checked against the list itself.
 * 2. Any `$taxByLine` key absent from `$knownLineUuids` (a foreign/unknown
 *    line) is rejected.
 * 3. Every known line UUID missing from `$taxByLine` is canonicalized to 0,
 *    so the stored map always has EXACTLY the known keys -- never sparse,
 *    never foreign-keyed.
 */
final readonly class TaxBreakdown
{
    /** @var array<string,int> canonicalized: exactly the known line UUIDs */
    private array $taxByLine;

    /**
     * @param array<string,int> $taxByLine per-line merchandise tax, keyed by
     *   line_uuid (may be sparse or empty; never a superset of $knownLineUuids)
     * @param list<string> $knownLineUuids the full set of line UUIDs the
     *   quote was computed over -- must contain no duplicate values
     */
    public function __construct(
        array $taxByLine,
        private int $shippingTaxTotal,
        array $knownLineUuids
    ) {
        if (count($knownLineUuids) !== count(array_unique($knownLineUuids))) {
            throw new \InvalidArgumentException(
                'TaxBreakdown: knownLineUuids contains duplicate values.'
            );
        }

        $known = array_fill_keys($knownLineUuids, true);
        foreach (array_keys($taxByLine) as $lineUuid) {
            if (!isset($known[$lineUuid])) {
                throw new \InvalidArgumentException(
                    "TaxBreakdown: taxByLine contains unknown line UUID '{$lineUuid}'."
                );
            }
        }

        $canonical = [];
        foreach ($knownLineUuids as $lineUuid) {
            $canonical[$lineUuid] = $taxByLine[$lineUuid] ?? 0;
        }

        $this->taxByLine = $canonical;
    }

    /** @return array<string,int> */
    public function taxByLine(): array
    {
        return $this->taxByLine;
    }

    public function shippingTaxTotal(): int
    {
        return $this->shippingTaxTotal;
    }

    public function total(): int
    {
        return array_sum($this->taxByLine) + $this->shippingTaxTotal;
    }
}
