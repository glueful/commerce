<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Reports;

/**
 * A *deployment* configuration value for the reports layer is out of its
 * allowed range (e.g. `commerce.reports.low_stock_threshold` outside
 * `0..100000`). This is distinct from an invalid per-request override, which
 * is a 422 concern handled by request validation -- an invalid configured
 * default is an operator/deployment error and must fail loudly rather than
 * being silently clamped into range (a clamp would produce a misleading
 * report without any signal that the configuration is wrong).
 */
final class ReportConfigurationException extends \RuntimeException
{
    public function __construct(
        public readonly string $configKey,
        string $reason,
    ) {
        parent::__construct("Invalid commerce reports configuration for '{$configKey}': {$reason}");
    }
}
