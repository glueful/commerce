<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Shipping\DelegatingShippingRateProvider;
use Glueful\Extensions\Commerce\Tax\FlatRateTaxCalculator;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Uploader\Contracts\BlobAccessPolicyRegistry;

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
                    DelegatingShippingRateProvider::class
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
            'email' => self::emailStatus($context),
            'blob_policy' => self::blobPolicyStatus($context),
        ];
    }

    /**
     * 'active' when the framework's BlobAccessPolicyRegistry (design spec §5,
     * Task 1) is bound AND commerce's own contributor is registered under it;
     * 'unavailable' when the registry itself isn't bound at all (a framework
     * build without that seam) -- see {@see \Glueful\Extensions\Commerce\CommerceServiceProvider::boot()}.
     */
    private static function blobPolicyStatus(ApplicationContext $context): string
    {
        $container = container($context);
        if (!$container->has(BlobAccessPolicyRegistry::class)) {
            return 'unavailable';
        }

        $registry = $container->get(BlobAccessPolicyRegistry::class);
        if (!$registry instanceof BlobAccessPolicyRegistry) {
            return 'unavailable';
        }

        return $registry->has('commerce.downloads') ? 'active' : 'unavailable';
    }

    /**
     * `disabled` when the master `commerce.email.enabled` switch is off; otherwise
     * `active` only when the `email` channel is registered AND available on the shared
     * `NotificationDispatcher`, `inactive` when it is not. `NotificationDispatcher`/
     * `NotificationService` presence alone is never the signal — core always binds them
     * with only the `database` channel registered.
     */
    private static function emailStatus(ApplicationContext $context): string
    {
        if (!(bool) config($context, 'commerce.email.enabled', false)) {
            return 'disabled';
        }

        $container = container($context);
        if (!$container->has(NotificationDispatcher::class)) {
            return 'inactive';
        }

        $dispatcher = $container->get(NotificationDispatcher::class);
        if (!$dispatcher instanceof NotificationDispatcher) {
            return 'inactive';
        }

        return in_array('email', $dispatcher->getChannelManager()->getActiveChannelNames(), true)
            ? 'active'
            : 'inactive';
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
            'commerce_refunds',
            'commerce_order_events',
            'commerce_sequences',
            'commerce_discounts',
            'commerce_discount_redemptions',
            'commerce_product_media',
            'commerce_categories',
            'commerce_product_categories',
            'commerce_tags',
            'commerce_product_tags',
            'commerce_attributes',
            'commerce_attribute_values',
            'commerce_product_attributes',
            'commerce_product_children',
            'commerce_product_addons',
            'commerce_reviews',
            'commerce_customer_address_books',
            'commerce_customer_addresses',
            'commerce_downloads',
            'commerce_download_grants',
            'commerce_shipping_zones',
            'commerce_shipping_zone_locations',
            'commerce_shipping_methods',
            'commerce_shipping_classes',
            'commerce_tax_rates',
            // Marketplace MV1 foundation (design spec §3). Marketplace-aware
            // REGARDLESS of `commerce.marketplace.enabled`: data created before
            // a switch-off must stay coherent for diagnostics and tenant
            // adoption (design spec §2.1 explicit exceptions).
            'commerce_marketplace_settings',
            'commerce_sellers',
            'commerce_seller_memberships',
            // Marketplace MV2 shared checkout (design spec §3.3, §7). Marketplace-aware
            // REGARDLESS of `commerce.marketplace.enabled` for the same reason as the MV1
            // trio immediately above: a partitioned order's seller partitions must stay
            // coherent for diagnostics and tenant adoption even after the switch flips off.
            'commerce_seller_orders',
            // Marketplace MV3 commission & settlement ledger (design spec §3.2-§3.5,
            // §3.7). Marketplace-aware REGARDLESS of `commerce.marketplace.enabled` for
            // the same reason as the MV1/MV2 tables above. All four carry `tenant_uuid`
            // and are therefore swept by `tenantTables()` below by omission from its
            // exclusion list -- no addition to that list needed.
            'commerce_marketplace_ledger',
            'commerce_ledger_account_locks',
            'commerce_commission_policy_events',
            'commerce_payouts',
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
                'commerce_product_categories',
                'commerce_product_tags',
                'commerce_attribute_values',
                'commerce_product_attributes',
                'commerce_product_children',
                'commerce_shipping_zone_locations',
                'commerce_shipping_methods',
            ], true)
        ));
    }
}
