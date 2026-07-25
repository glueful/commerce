<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

use Glueful\Bootstrap\ApplicationContext;

/**
 * The ONE read surface for runtime-editable store settings (store-settings spec §3.2). Each
 * getter consults a host-bound {@see CommerceSettingsOverride} first, then falls back to the
 * matching `config/commerce.php` key — so with no binding every install behaves exactly as
 * before (pure config/env), and with one, hosts make these keys editable at runtime.
 *
 * Casting is DEFENSIVE: an override that doesn't parse as the expected shape (a currency that
 * isn't a 3-letter code, a non-numeric int) is treated as no-override and falls through to
 * config — a corrupted stored row must never leak a malformed value into money math or SQL.
 * Overrides are consulted per read; implementations are expected to memoize (the contract's
 * canonical implementation reads a per-request-cached settings store).
 */
final class CommerceSettings
{
    public static function currency(ApplicationContext $context): string
    {
        $override = self::override($context, 'commerce.currency');
        if ($override !== null) {
            $candidate = strtoupper(trim($override));
            if (preg_match('/^[A-Z]{3}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return (string) config($context, 'commerce.currency', 'USD');
    }

    public static function taxFlatRateBps(ApplicationContext $context): int
    {
        return self::intValue($context, 'commerce.tax.flat_rate_bps', 0);
    }

    public static function orderNumberFormat(ApplicationContext $context): string
    {
        $override = self::override($context, 'commerce.orders.number_format');
        if ($override !== null && trim($override) !== '' && str_contains($override, '{seq}')) {
            return $override;
        }

        return (string) config($context, 'commerce.orders.number_format', 'ORD-{seq}');
    }

    public static function orderExpiryMinutes(ApplicationContext $context): int
    {
        return self::intValue($context, 'commerce.orders.expiry_minutes', 60);
    }

    public static function cartTtlDays(ApplicationContext $context): int
    {
        return self::intValue($context, 'commerce.cart.ttl_days', 30);
    }

    public static function lowStockThreshold(ApplicationContext $context): int
    {
        return self::intValue($context, 'commerce.reports.low_stock_threshold', 2);
    }

    public static function sellerName(ApplicationContext $context): ?string
    {
        return self::nullableString($context, 'commerce.seller.name');
    }

    public static function sellerAddress(ApplicationContext $context): ?string
    {
        return self::nullableString($context, 'commerce.seller.address');
    }

    public static function sellerTaxId(ApplicationContext $context): ?string
    {
        return self::nullableString($context, 'commerce.seller.tax_id');
    }

    /** Null-tolerant free text (the invoice identity keys): override wins, else config, else null. */
    private static function nullableString(ApplicationContext $context, string $key): ?string
    {
        $override = self::override($context, $key);
        if ($override !== null && trim($override) !== '') {
            return $override;
        }
        $configured = config($context, $key);

        return is_string($configured) && trim($configured) !== '' ? $configured : null;
    }

    private static function intValue(ApplicationContext $context, string $key, int $default): int
    {
        $override = self::override($context, $key);
        if ($override !== null && preg_match('/^-?\d+$/', trim($override)) === 1) {
            return (int) trim($override);
        }

        return (int) config($context, $key, $default);
    }

    private static function override(ApplicationContext $context, string $key): ?string
    {
        $container = container($context);
        if (!$container->has(CommerceSettingsOverride::class)) {
            return null;
        }

        /** @var CommerceSettingsOverride $resolver */
        $resolver = $container->get(CommerceSettingsOverride::class);

        return $resolver->value($context, $key);
    }
}
