<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Reports;

/**
 * Resolves the effective low-stock threshold for the stock report: a
 * per-request `?threshold=` override when present, otherwise the deployment
 * default from `config('commerce.reports.low_stock_threshold')`. Both are
 * constrained to `0..100000`.
 *
 * The request override's range is primarily a DTO/controller 422 concern
 * (the framework's string-based `#[Rule(...)]` syntax has no numeric-range
 * rule wired up -- `min`/`max` map to `Glueful\Validation\Rules\Length`,
 * a STRING-length check, not a numeric bound; see `CreateRefundData`'s
 * `amount` docblock for the same finding elsewhere in this codebase). This
 * method still defends the override's range directly, so `resolve()` itself
 * is never the source of a silently-wrong threshold if a caller skips DTO
 * validation.
 *
 * An invalid *configured* value is a deployment error, not a request error:
 * it throws `ReportConfigurationException` naming the config key rather than
 * being clamped into range (a clamp would produce a misleading report with
 * no signal that the deployment value is wrong).
 */
final class StockThreshold
{
    private const MIN = 0;
    private const MAX = 100000;
    private const CONFIG_KEY = 'commerce.reports.low_stock_threshold';

    public static function resolve(?int $override, mixed $configured): int
    {
        if ($override !== null) {
            if ($override < self::MIN || $override > self::MAX) {
                throw new \InvalidArgumentException(
                    'StockThreshold::resolve(): override must be between '
                        . self::MIN . ' and ' . self::MAX . ", got {$override}."
                );
            }

            return $override;
        }

        return self::resolveConfigured($configured);
    }

    private static function resolveConfigured(mixed $configured): int
    {
        if (is_int($configured)) {
            $value = $configured;
        } elseif (is_numeric($configured) && (string) (int) $configured === (string) $configured) {
            $value = (int) $configured;
        } else {
            throw new ReportConfigurationException(
                self::CONFIG_KEY,
                'must be an integer between ' . self::MIN . ' and ' . self::MAX
                    . ', got ' . self::describe($configured) . '.'
            );
        }

        if ($value < self::MIN || $value > self::MAX) {
            throw new ReportConfigurationException(
                self::CONFIG_KEY,
                'must be between ' . self::MIN . ' and ' . self::MAX . ", got {$value}."
            );
        }

        return $value;
    }

    private static function describe(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_string($value)) {
            return "'{$value}'";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return get_debug_type($value);
    }

    private function __construct()
    {
    }
}
