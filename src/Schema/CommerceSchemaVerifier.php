<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/commerce (schema policy spec B7): create migrations prove
 * every table they create; 021 proves the NOT NULL constraints it enforces on
 * commerce_stock.quantity/tracked (nullability is read from the live catalog — column existence
 * cannot certify it); 022 proves the walk-in order columns AND the draft-attempt ledger table.
 * Unknown basenames are never adoptable.
 */
final class CommerceSchemaVerifier implements StructuralVerifierInterface
{
    /** @var array<string, list<string>> create-only migration => every table it creates */
    private const CREATED_TABLES = [
        '001_CreateCommerceCatalogTables.php' => ['commerce_products', 'commerce_variants'],
        '002_CreateCommerceInventoryTables.php' => ['commerce_stock', 'commerce_stock_movements'],
        '003_CreateCommerceCartTables.php' => ['commerce_carts', 'commerce_cart_lines'],
        '004_CreateCommerceOrderTables.php' => [
            'commerce_orders', 'commerce_order_lines', 'commerce_order_events', 'commerce_sequences',
        ],
        '005_CreateCommerceDiscountTables.php' => ['commerce_discounts', 'commerce_discount_redemptions'],
        '006_CreateCommerceRefundTables.php' => ['commerce_refunds', 'commerce_refund_lines'],
        '007_CreateCommerceCatalogBreadthTables.php' => [
            'commerce_product_media', 'commerce_categories', 'commerce_product_categories',
            'commerce_tags', 'commerce_product_tags', 'commerce_attributes', 'commerce_attribute_values',
            'commerce_product_attributes', 'commerce_product_children', 'commerce_product_addons',
            'commerce_reviews',
        ],
        '008_CreateCommerceCustomerDeliveryTables.php' => [
            'commerce_customer_address_books', 'commerce_customer_addresses',
            'commerce_downloads', 'commerce_download_grants',
        ],
        '009_CreateCommerceShippingTaxTables.php' => [
            'commerce_shipping_zones', 'commerce_shipping_zone_locations', 'commerce_shipping_methods',
            'commerce_shipping_classes', 'commerce_tax_rates',
        ],
        '010_CreateMarketplaceSellerTables.php' => [
            'commerce_marketplace_settings', 'commerce_sellers', 'commerce_seller_memberships',
        ],
        '011_CreateSellerOrderTables.php' => ['commerce_seller_orders'],
        '012_CreateMarketplaceLedgerTables.php' => [
            'commerce_marketplace_ledger', 'commerce_ledger_account_locks', 'commerce_commission_policy_events',
        ],
        '013_CreatePayoutTable.php' => ['commerce_payouts'],
        '014_CreateSellerPayoutAccountsTable.php' => ['commerce_seller_payout_accounts'],
        '015_CreateSellerReservesTable.php' => ['commerce_seller_reserves', 'commerce_reserve_policy_events'],
        '016_CreateChargebacksTable.php' => ['commerce_chargebacks', 'commerce_chargeback_lines'],
        '017_CreateSellerLifecycleEventsTable.php' => ['commerce_seller_lifecycle_events'],
        '018_CreateSellerApiKeysTables.php' => [
            'commerce_seller_api_keys', 'commerce_seller_api_key_credentials', 'commerce_seller_api_key_events',
        ],
        '019_CreateSellerWebhookTables.php' => [
            'commerce_seller_webhook_endpoints', 'commerce_seller_webhook_secrets',
            'commerce_seller_webhook_events', 'commerce_seller_webhook_deliveries',
            'commerce_seller_webhook_endpoint_events',
        ],
        '020_CreateWishlistTables.php' => ['commerce_wishlists', 'commerce_wishlist_items'],
        '023_CreatePaymentLinksTable.php' => ['commerce_payment_links'],
    ];

    public function source(): string
    {
        return 'glueful/commerce';
    }

    /** @return list<string> */
    public function migrationBasenames(): array
    {
        $names = array_keys(self::CREATED_TABLES);
        $names[] = '021_EnforceStockQuantityTrackedNotNull.php';
        $names[] = '022_AddWalkInOrderFieldsAndDraftAttemptLedger.php';
        sort($names);
        return $names;
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        if (isset(self::CREATED_TABLES[$migrationBasename])) {
            $schema = $db->getSchemaBuilder();
            foreach (self::CREATED_TABLES[$migrationBasename] as $table) {
                if (!$schema->hasTable($table)) {
                    return false;
                }
            }
            return true;
        }
        return match ($migrationBasename) {
            '021_EnforceStockQuantityTrackedNotNull.php' => $this->stockColumnsAreNotNull($db),
            '022_AddWalkInOrderFieldsAndDraftAttemptLedger.php' => $this->walkInOrderShapePresent($db),
            default => false,
        };
    }

    private function stockColumnsAreNotNull(Connection $db): bool
    {
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasTable('commerce_stock')) {
            return false;
        }
        return $this->columnIsNotNull($db, 'commerce_stock', 'quantity')
            && $this->columnIsNotNull($db, 'commerce_stock', 'tracked');
    }

    private function walkInOrderShapePresent(Connection $db): bool
    {
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasTable('commerce_order_draft_attempts')) {
            return false;
        }
        foreach (['phone_normalized', 'phone_display', 'customer_name', 'origin', 'fulfillment_mode'] as $column) {
            if (!$schema->hasColumn('commerce_orders', $column)) {
                return false;
            }
        }
        return true;
    }

    /** Nullability is a catalog fact — column existence cannot prove the 021 constraint. */
    private function columnIsNotNull(Connection $db, string $table, string $column): bool
    {
        $pdo = $db->getPDO();
        if ($db->getDriverName() === 'sqlite') {
            $stmt = $pdo->query('PRAGMA table_info("' . $table . '")');
            if ($stmt === false) {
                return false;
            }
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $info) {
                if ($info['name'] === $column) {
                    return (int) $info['notnull'] === 1;
                }
            }
            return false;
        }
        $stmt = $pdo->prepare(
            'SELECT is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        return $stmt->fetchColumn() === 'NO';
    }
}
