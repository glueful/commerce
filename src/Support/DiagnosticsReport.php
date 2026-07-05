<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Shipping\ConfigShippingRateProvider;
use Glueful\Extensions\Commerce\Tax\FlatRateTaxCalculator;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry;

final class DiagnosticsReport
{
    /** @return array<string,mixed> */
    public static function build(ApplicationContext $context): array
    {
        $container = container($context);

        return [
            'contracts' => [
                'payment_collector' => self::contract(
                    $context,
                    PaymentCollector::class,
                    ManualPaymentCollector::class
                ),
                'current_tenant_resolver' => self::contract(
                    $context,
                    CurrentTenantResolver::class,
                    SentinelTenantResolver::class
                ),
                'tenant_table_registry' => self::contract($context, TenantTableRegistry::class, null),
                'tax_calculator' => self::contract($context, TaxCalculator::class, FlatRateTaxCalculator::class),
                'shipping_rate_provider' => self::contract(
                    $context,
                    ShippingRateProvider::class,
                    ConfigShippingRateProvider::class
                ),
            ],
            'tenancy' => [
                'enabled' => (bool) config($context, 'commerce.tenancy.enabled', false),
                'sentinel_rows' => self::sentinelRows($context),
            ],
            'database' => [
                'commerce_tables_present' => self::tablesPresent($context),
            ],
            'container' => [
                'has_payment_collector' => $container->has(PaymentCollector::class),
                'has_current_tenant_resolver' => $container->has(CurrentTenantResolver::class),
                'has_tenant_table_registry' => $container->has(TenantTableRegistry::class),
            ],
        ];
    }

    /**
     * @param class-string $contract
     * @param class-string|null $fallback
     * @return array{source: string, class: string|null}
     */
    private static function contract(ApplicationContext $context, string $contract, ?string $fallback): array
    {
        $container = container($context);
        if ($container->has($contract)) {
            $service = $container->get($contract);

            return ['source' => 'bound', 'class' => is_object($service) ? $service::class : get_debug_type($service)];
        }

        return ['source' => 'fallback', 'class' => $fallback];
    }

    /** @return array<string,int> */
    private static function sentinelRows(ApplicationContext $context): array
    {
        $rows = [];
        foreach (self::tenantTables() as $table) {
            if (!db($context)->getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            $rows[$table] = (int) db($context)->table($table)->where('tenant_uuid', '=', '')->count();
        }

        return $rows;
    }

    /** @return array<string,bool> */
    private static function tablesPresent(ApplicationContext $context): array
    {
        $present = [];
        foreach (self::commerceTables() as $table) {
            $present[$table] = db($context)->getSchemaBuilder()->hasTable($table);
        }

        return $present;
    }

    /** @return list<string> */
    public static function commerceTables(): array
    {
        return [
            'commerce_products',
            'commerce_variants',
            'commerce_stock',
            'commerce_stock_movements',
            'commerce_carts',
            'commerce_cart_lines',
            'commerce_orders',
            'commerce_order_lines',
            'commerce_order_events',
            'commerce_sequences',
            'commerce_discounts',
            'commerce_discount_redemptions',
        ];
    }

    /** @return list<string> */
    public static function tenantTables(): array
    {
        return array_values(array_filter(
            self::commerceTables(),
            static fn (string $table): bool => !in_array($table, [
                'commerce_cart_lines',
                'commerce_order_lines',
                'commerce_order_events',
            ], true)
        ));
    }
}
