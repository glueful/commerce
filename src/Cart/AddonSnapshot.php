<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Cart;

/**
 * Pure add-on selection validator + canonical snapshot/hash builder. No I/O: callers
 * (currently only {@see CartService::addLine()}) resolve the product's ACTIVE
 * `commerce_product_addons` definitions and the variant's price, then hand both to
 * `build()` here.
 *
 * "Snapshot, don't reference" (design spec §4): a definition edit (name/label/price)
 * never mutates an already-persisted cart/order line. `build()` produces a fully
 * self-contained entry per selected addon -- display AND price fields both baked in
 * -- and `hash()` covers the FULL entry, so editing a definition changes the hash of
 * any FUTURE selection of it (a new line, never a stale-price merge) while leaving
 * every already-persisted snapshot byte-for-byte untouched. `pricedLines()` reads
 * ONLY the persisted snapshot's `price_delta` values via `delta()` -- it never calls
 * back into this class or re-resolves definitions.
 *
 * Canonical entry shape (fixed key order, always all seven keys):
 * `{addon_uuid, name, field_type, choice_key, choice_label, value, price_delta}`.
 * `choice_key`/`choice_label` are populated for `select`, left `null` otherwise;
 * `value` carries the checkbox bool or the trimmed text string, left `null` for
 * `select`. This is the INTERNAL/storage form -- {@see sanitize()} is the separate
 * whitelist used for customer/admin-facing echoes.
 */
final class AddonSnapshot
{
    private const MAX_TEXT_LENGTH = 500;

    /**
     * Validate selections against ACTIVE definitions and build the canonical snapshot.
     *
     * @param list<array<string,mixed>> $definitions active commerce_product_addons rows
     * @param list<array{addon_uuid:string,choice_key?:string,value?:mixed}> $selections
     * @param int $variantPrice variant price in minor units
     * @return array{snapshot: list<array<string,mixed>>, hash: string}
     * @throws AddonValidationException on: unknown addon_uuid, duplicate addon_uuid,
     *         missing required addon, invalid choice_key, checkbox non-boolean, text
     *         empty/over 500 chars, or variantPrice + delta < 0.
     */
    public static function build(array $definitions, array $selections, int $variantPrice): array
    {
        $byUuid = [];
        foreach ($definitions as $definition) {
            $byUuid[(string) $definition['uuid']] = $definition;
        }

        $seen = [];
        $entries = [];
        // Re-widened to `mixed` per element: the docblock above documents the
        // shape selections are EXPECTED to have once validated, but this method
        // is the validator -- the actual runtime payload is raw, untrusted HTTP
        // JSON that may not match it, so every shape assumption below is checked,
        // never trusted.
        /** @var list<mixed> $rawSelections */
        $rawSelections = array_values($selections);
        foreach ($rawSelections as $index => $selection) {
            if (!is_array($selection) || !isset($selection['addon_uuid'])) {
                throw new AddonValidationException("selections.{$index}: addon_uuid is required.");
            }

            $addonUuid = (string) $selection['addon_uuid'];
            if (isset($seen[$addonUuid])) {
                throw new AddonValidationException("Duplicate addon selection for '{$addonUuid}'.");
            }
            $seen[$addonUuid] = true;

            $definition = $byUuid[$addonUuid] ?? null;
            if ($definition === null) {
                throw new AddonValidationException("Unknown addon '{$addonUuid}'.");
            }

            $entries[] = self::buildEntry($definition, $selection);
        }

        foreach ($definitions as $definition) {
            $addonUuid = (string) $definition['uuid'];
            if ((bool) ($definition['required'] ?? false) && !isset($seen[$addonUuid])) {
                throw new AddonValidationException(
                    "Missing required addon '" . (string) ($definition['name'] ?? $addonUuid) . "'."
                );
            }
        }

        if ($variantPrice + self::delta($entries) < 0) {
            throw new AddonValidationException('The selected add-ons would make the unit price negative.');
        }

        usort($entries, static fn (array $a, array $b): int => $a['addon_uuid'] <=> $b['addon_uuid']);
        $entries = array_values($entries);

        return ['snapshot' => $entries, 'hash' => self::hash($entries)];
    }

    /**
     * sha256 over the canonical snapshot: sorted by addon_uuid, fixed key order
     * (addon_uuid, name, field_type, choice_key, choice_label, value, price_delta),
     * text values trimmed, JSON_THROW_ON_ERROR. Empty snapshot => ''.
     *
     * Defensive/idempotent: normalizes and sorts a COPY regardless of the input
     * array's order or whether values were already trimmed, so calling this twice
     * on equivalent-but-differently-ordered/formatted input is always stable.
     *
     * @param list<array<string,mixed>> $snapshot
     */
    public static function hash(array $snapshot): string
    {
        if ($snapshot === []) {
            return '';
        }

        $normalized = array_map(static function (array $entry): array {
            $value = $entry['value'] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            return [
                'addon_uuid' => (string) ($entry['addon_uuid'] ?? ''),
                'name' => (string) ($entry['name'] ?? ''),
                'field_type' => (string) ($entry['field_type'] ?? ''),
                'choice_key' => isset($entry['choice_key']) ? (string) $entry['choice_key'] : null,
                'choice_label' => isset($entry['choice_label']) ? (string) $entry['choice_label'] : null,
                'value' => $value,
                'price_delta' => (int) ($entry['price_delta'] ?? 0),
            ];
        }, array_values($snapshot));

        usort($normalized, static fn (array $a, array $b): int => $a['addon_uuid'] <=> $b['addon_uuid']);

        return \hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<array<string,mixed>> $snapshot
     * @return int total signed delta
     */
    public static function delta(array $snapshot): int
    {
        $sum = 0;
        foreach ($snapshot as $entry) {
            $sum += (int) ($entry['price_delta'] ?? 0);
        }

        return $sum;
    }

    /**
     * Whitelisted echo for order/cart line projections:
     * `{name, field_type?, choice_label?, value?, price_delta}` -- NEVER
     * `addon_uuid`, `choice_key`, choices arrays, status, or any other
     * addon-definition internal. Optional keys are omitted (not null) when the
     * source entry has nothing to say for that field, so the echo shape matches
     * exactly what applies to the field type (e.g. a checkbox entry never carries
     * `choice_label`).
     *
     * Shared by every projection surface (storefront cart, storefront/admin order
     * lines, invoice data) so the whitelist lives in exactly one place.
     *
     * @param list<array<string,mixed>> $snapshot
     * @return list<array<string,mixed>>
     */
    public static function sanitize(array $snapshot): array
    {
        return array_values(array_map(static function (array $entry): array {
            $echo = ['name' => (string) ($entry['name'] ?? '')];

            if (isset($entry['field_type']) && $entry['field_type'] !== '') {
                $echo['field_type'] = (string) $entry['field_type'];
            }
            if (isset($entry['choice_label']) && $entry['choice_label'] !== '') {
                $echo['choice_label'] = (string) $entry['choice_label'];
            }
            if (array_key_exists('value', $entry) && $entry['value'] !== null) {
                $echo['value'] = $entry['value'];
            }

            $echo['price_delta'] = (int) ($entry['price_delta'] ?? 0);

            return $echo;
        }, $snapshot));
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $selection */
    private static function buildEntry(array $definition, array $selection): array
    {
        $fieldType = (string) ($definition['field_type'] ?? '');

        return match ($fieldType) {
            'select' => self::buildSelectEntry($definition, $selection),
            'checkbox' => self::buildCheckboxEntry($definition, $selection),
            'text' => self::buildTextEntry($definition, $selection),
            default => throw new AddonValidationException(
                "Addon '" . (string) ($definition['uuid'] ?? '') . "' has an unsupported field_type."
            ),
        };
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $selection */
    private static function buildSelectEntry(array $definition, array $selection): array
    {
        $addonUuid = (string) $definition['uuid'];
        $choiceKey = isset($selection['choice_key']) && is_string($selection['choice_key'])
            ? trim($selection['choice_key'])
            : '';
        if ($choiceKey === '') {
            throw new AddonValidationException("Addon '{$addonUuid}' requires a choice_key.");
        }

        $choices = is_array($definition['choices'] ?? null) ? $definition['choices'] : [];
        $match = null;
        foreach ($choices as $choice) {
            if (is_array($choice) && (string) ($choice['key'] ?? '') === $choiceKey) {
                $match = $choice;
                break;
            }
        }
        if ($match === null) {
            throw new AddonValidationException("Invalid choice_key '{$choiceKey}' for addon '{$addonUuid}'.");
        }

        return self::entry(
            $addonUuid,
            (string) ($definition['name'] ?? ''),
            'select',
            $choiceKey,
            isset($match['label']) ? (string) $match['label'] : null,
            null,
            (int) ($match['price_delta'] ?? 0)
        );
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $selection */
    private static function buildCheckboxEntry(array $definition, array $selection): array
    {
        $addonUuid = (string) $definition['uuid'];
        if (!array_key_exists('value', $selection) || !is_bool($selection['value'])) {
            throw new AddonValidationException("Addon '{$addonUuid}' requires a boolean value.");
        }

        $value = $selection['value'];
        $delta = $value ? (int) ($definition['price_delta'] ?? 0) : 0;

        return self::entry($addonUuid, (string) ($definition['name'] ?? ''), 'checkbox', null, null, $value, $delta);
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $selection */
    private static function buildTextEntry(array $definition, array $selection): array
    {
        $addonUuid = (string) $definition['uuid'];
        $raw = $selection['value'] ?? null;
        $value = is_string($raw) ? trim($raw) : '';
        if ($value === '') {
            throw new AddonValidationException("Addon '{$addonUuid}' requires non-empty text.");
        }
        if (mb_strlen($value) > self::MAX_TEXT_LENGTH) {
            throw new AddonValidationException(
                "Addon '{$addonUuid}' text must be at most " . self::MAX_TEXT_LENGTH . ' characters.'
            );
        }

        return self::entry(
            $addonUuid,
            (string) ($definition['name'] ?? ''),
            'text',
            null,
            null,
            $value,
            (int) ($definition['price_delta'] ?? 0)
        );
    }

    private static function entry(
        string $addonUuid,
        string $name,
        string $fieldType,
        ?string $choiceKey,
        ?string $choiceLabel,
        bool|string|null $value,
        int $priceDelta
    ): array {
        return [
            'addon_uuid' => $addonUuid,
            'name' => $name,
            'field_type' => $fieldType,
            'choice_key' => $choiceKey,
            'choice_label' => $choiceLabel,
            'value' => $value,
            'price_delta' => $priceDelta,
        ];
    }
}
