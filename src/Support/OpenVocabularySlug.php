<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

use Glueful\Validation\ValidationException;

/**
 * The single normalizer for the "open vocabulary slug" grammar shared by three
 * independent write paths (design spec §2/§5/§6): shipping-class `slug`
 * ({@see \Glueful\Extensions\Commerce\Shipping\ShippingClassService}, immutable
 * after create), product `tax_class`
 * ({@see \Glueful\Extensions\Commerce\Catalog\CatalogService}), and tax-rate
 * `class` ({@see \Glueful\Extensions\Commerce\Tax\TaxRateService}, default
 * 'standard'). All three are intentionally an open vocabulary -- a syntactically
 * valid value with no matching counterpart elsewhere is still accepted (e.g. a
 * tax_class with no matching rate simply taxes at 0, spec §5) -- so this
 * normalizer only ever enforces the grammar, never existence.
 *
 * Grammar: lowercase, `[a-z][a-z0-9_-]{0,15}` (must start with a letter, max 16
 * characters total). Input is trimmed and lowercased before the pattern check so
 * `" Fragile "` normalizes to `fragile` rather than being rejected outright.
 */
final class OpenVocabularySlug
{
    private const PATTERN = '/^[a-z][a-z0-9_-]{0,15}$/';

    public static function normalize(string $raw, string $field): string
    {
        $slug = strtolower(trim($raw));
        if (preg_match(self::PATTERN, $slug) !== 1) {
            throw ValidationException::forField(
                $field,
                "{$field} must be lowercase and match [a-z][a-z0-9_-]{0,15} (start with a letter, max 16 chars)."
            );
        }

        return $slug;
    }
}
