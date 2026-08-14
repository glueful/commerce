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
    /** Payment-links spec §2.2: the closed range a per-hour initiation ceiling is clamped into. */
    public const PAYMENT_LINK_INITIATIONS_MIN = 1;
    public const PAYMENT_LINK_INITIATIONS_MAX = 100;

    /** Payment-links spec §2.2: the closed range a link's TTL, in days, is clamped into. */
    public const PAYMENT_LINK_TTL_DAYS_MIN = 1;
    public const PAYMENT_LINK_TTL_DAYS_MAX = 30;

    /** Cleanup-train Task 5: the closed range draft-artifact retention, in days, is clamped into. */
    public const DRAFT_PURGE_DAYS_MIN = 1;
    public const DRAFT_PURGE_DAYS_MAX = 365;

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

    /**
     * Admin-order-creation cycle 2, Task 8: the draft-order TTL, in days, that
     * {@see \Glueful\Extensions\Commerce\Orders\DraftCleanupService::cancelStale()}
     * sweeps against. Same defensive-cast contract as every sibling here -- a
     * malformed override falls through to config rather than reaching the
     * cutoff arithmetic.
     */
    public static function draftTtlDays(ApplicationContext $context): int
    {
        return self::intValue($context, 'commerce.orders.draft_ttl_days', 30);
    }

    /**
     * Cleanup-train Task 5: how long a canceled DRAFT ARTIFACT is retained
     * before {@see \Glueful\Extensions\Commerce\Orders\DraftCleanupService::purgeStale()}
     * hard-deletes it. Default 30 days.
     *
     * CLAMPED rather than merely cast, unlike its `draftTtlDays()` sibling and
     * for the same reason the payment-link TTL is: this value governs a
     * DESTRUCTIVE sweep with no undo. A configured `0` (or a negative, or a
     * malformed override that a future writer coerced to zero) would purge an
     * artifact in the very tick that canceled it, destroying the row an operator
     * is about to look at; an unbounded value would silently disable the sweep
     * while looking configured. Neither is honoured -- both are brought back
     * inside 1..365.
     */
    public static function draftPurgeDays(ApplicationContext $context): int
    {
        return self::clampDraftPurgeDays(
            self::intValue($context, 'commerce.orders.draft_purge_days', 30)
        );
    }

    public static function clampDraftPurgeDays(int $value): int
    {
        return max(
            self::DRAFT_PURGE_DAYS_MIN,
            min(self::DRAFT_PURGE_DAYS_MAX, $value)
        );
    }

    /**
     * Payment-links Task 5 (design spec §2.2): how many checkout initiations ONE
     * payment link may claim inside a FIXED UTC one-hour window. Default 10.
     *
     * Unlike every other int getter here, the resolved value is CLAMPED rather
     * than merely cast: 0 (or negative) would make every link permanently
     * unusable and an unbounded value would make the ceiling meaningless, so
     * both are brought back inside 1..100 instead of being honoured. The clamp
     * is exposed as {@see self::clampInitiationsPerHour()} so
     * {@see \Glueful\Extensions\Commerce\Orders\PaymentLinkRepository::claimInitiationWindow()}
     * applies the SAME bound to an explicitly supplied ceiling -- there is
     * exactly one definition of the range.
     */
    public static function paymentLinkInitiationsPerHour(ApplicationContext $context): int
    {
        return self::clampInitiationsPerHour(
            self::intValue($context, 'commerce.payment_links.initiations_per_hour', 10)
        );
    }

    public static function clampInitiationsPerHour(int $value): int
    {
        return max(
            self::PAYMENT_LINK_INITIATIONS_MIN,
            min(self::PAYMENT_LINK_INITIATIONS_MAX, $value)
        );
    }

    /**
     * Payment-links Task 6 (design spec §2.2): how long a freshly minted payment
     * link stays valid, in days. Default 7.
     *
     * The ONE resolution point for a link's TTL, which is why it takes the
     * caller's REQUESTED value too: an operator may choose a TTL per link, and
     * both that choice and the configured default must pass through the SAME
     * 1..30 clamp. Routing them separately is how a store ends up able to mint a
     * zero-day link (already expired the moment it is sent) through one path and
     * not the other.
     *
     * Clamped rather than merely cast, exactly like
     * {@see self::paymentLinkInitiationsPerHour()}: 0 or negative would mint an
     * already-dead link, and an unbounded value would make a bearer credential
     * effectively permanent.
     *
     * @param int|null $requested the caller's chosen TTL, or null to take the
     *     configured default
     */
    public static function paymentLinkTtlDays(ApplicationContext $context, ?int $requested = null): int
    {
        return self::clampTtlDays(
            $requested ?? self::intValue($context, 'commerce.payment_links.ttl_days', 7)
        );
    }

    public static function clampTtlDays(int $value): int
    {
        return max(
            self::PAYMENT_LINK_TTL_DAYS_MIN,
            min(self::PAYMENT_LINK_TTL_DAYS_MAX, $value)
        );
    }

    public static function cartTtlDays(ApplicationContext $context): int
    {
        return self::intValue($context, 'commerce.cart.ttl_days', 30);
    }

    public static function lowStockThreshold(ApplicationContext $context): int
    {
        return self::intValue($context, 'commerce.reports.low_stock_threshold', 2);
    }

    public static function marketplaceEnabled(ApplicationContext $context): bool
    {
        return self::boolValue($context, 'commerce.marketplace.enabled', false);
    }

    /**
     * Admin-order-creation cycle 2, Task 10 (design spec §2.5.9): whether an
     * ADMIN-ORIGIN order still sends the "we received your order" mail on
     * `OrderPlaced`. Default TRUE, so an install that never touches this key
     * behaves exactly as it always has. Consulted by
     * {@see \Glueful\Extensions\Commerce\Mail\OrderMailListener::onOrderPlaced()}
     * and nowhere else -- it is deliberately NOT a second master switch, and it
     * never gates a storefront order.
     */
    public static function orderConfirmation(ApplicationContext $context): bool
    {
        return self::boolValue($context, 'commerce.order_confirmation', true);
    }

    /**
     * Shared boolean resolution, with the SAME defensive-cast contract as every
     * sibling getter: an override that is not recognisably boolean is treated as
     * no-override and falls through to config, never coerced (a stored `"yes"`
     * must not silently become true when the store's own writer never validated
     * it).
     */
    private static function boolValue(ApplicationContext $context, string $key, bool $default): bool
    {
        $override = self::override($context, $key);
        if ($override !== null) {
            $flag = strtolower(trim($override));
            if (in_array($flag, ['1', 'true', '0', 'false'], true)) {
                return in_array($flag, ['1', 'true'], true);
            }
        }

        return (bool) config($context, $key, $default);
    }

    public static function downloadsUrlTtl(ApplicationContext $context): int
    {
        return self::intValue($context, 'commerce.downloads.url_ttl', 300);
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
