<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Tenancy;

use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

final class TenantAdopterTest extends CommerceTestCase
{
    public function testAdoptRekeysSentinelRowsAndRefusesMixedData(): void
    {
        $this->seedSentinelCatalog();

        $result = (new TenantAdopter())->adopt($this->context, 'tenantAAAA01');

        self::assertGreaterThan(0, $result['tables']['commerce_products']);
        self::assertSame(0, $this->connection->table('commerce_products')->where('tenant_uuid', '=', '')->count());
        self::assertGreaterThan(0, $this->connection->table('commerce_products')
            ->where('tenant_uuid', '=', 'tenantAAAA01')
            ->count());

        $this->expectException(\RuntimeException::class);
        (new TenantAdopter())->adopt($this->context, 'tenantCCCC03');
    }

    /**
     * Design spec §2.1 explicit exception: `commerce:tenancy:adopt` stays
     * marketplace-aware REGARDLESS of `commerce.marketplace.enabled`, so a
     * workspace's marketplace foundation data (created before, or while, the
     * install switch is off) is never stranded when the switch flips on later.
     * The master switch is left at its default (off, unset) for this whole
     * test -- {@see \Glueful\Extensions\Commerce\Support\DiagnosticsReport::tenantTables()}
     * feeds `TenantAdopter` unconditionally.
     */
    /**
     * A parent list rekeyed without its items would leave a wishlist whose contents belong to a
     * different tenant -- invisible until a visitor's list renders somebody else's products.
     */
    public function testAdoptRekeysWishlistParentsAndItems(): void
    {
        $this->connection->table('commerce_wishlists')->insert([
            'uuid' => 'wlstadopt001',
            'tenant_uuid' => '',
            'user_uuid' => 'useradopt001',
            'revision' => 0,
        ]);
        $this->connection->table('commerce_wishlist_items')->insert([
            'uuid' => 'wishadopt001',
            'tenant_uuid' => '',
            'user_uuid' => 'useradopt001',
            'product_uuid' => 'prodadopt001',
            'position' => 0,
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantWSH0001');

        self::assertSame(1, $result['tables']['commerce_wishlists']);
        self::assertSame(1, $result['tables']['commerce_wishlist_items']);

        foreach (['commerce_wishlists', 'commerce_wishlist_items'] as $table) {
            self::assertSame(
                0,
                $this->connection->table($table)->where('tenant_uuid', '=', '')->count(),
                "{$table} must have no sentinel rows left behind"
            );
            self::assertSame(
                1,
                $this->connection->table($table)->where('tenant_uuid', '=', 'tenantWSH0001')->count(),
                "{$table} row must be rekeyed to the adopted tenant"
            );
        }
    }

    /**
     * Admin-order-creation cycle 2, Task 6 (design spec §2.6): the finalize
     * idempotency ledger carries its own `tenant_uuid` directly, so it is swept
     * by the ordinary `tenantTables()` inventory with no special-cased handling.
     */
    public function testAdoptRekeysDraftAttemptsTable(): void
    {
        $this->connection->table('commerce_order_draft_attempts')->insert([
            'tenant_uuid' => '',
            'idempotency_key' => 'idem-adopt-001',
            'request_fingerprint' => md5('adopt-001'),
            'order_uuid' => 'orderadopt01',
            'status' => 'pending',
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantDFT0001');

        self::assertSame(1, $result['tables']['commerce_order_draft_attempts']);
        self::assertSame(
            0,
            $this->connection->table('commerce_order_draft_attempts')->where('tenant_uuid', '=', '')->count()
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_order_draft_attempts')
                ->where('tenant_uuid', '=', 'tenantDFT0001')
                ->count()
        );
    }

    /**
     * Payment-links Task 5 (spec §2.2): `commerce_payment_links` carries its own
     * `tenant_uuid` directly, so adoption rekeys it through the ordinary
     * `tenantTables()` inventory. A link left behind on the sentinel tenant
     * would become unresolvable the moment the workspace is adopted -- the
     * repository only ever queries under the host-resolved tenant.
     */
    public function testAdoptRekeysPaymentLinksTable(): void
    {
        $this->connection->table('commerce_payment_links')->insert([
            'uuid' => 'plinkadopt01',
            'tenant_uuid' => '',
            'order_uuid' => 'orderadopt02',
            'token_hash' => hash('sha256', 'adopt-payment-link'),
            'status' => 'active',
            'expires_at' => '2026-09-01 00:00:00',
            'created_by' => 'actoradopt01',
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantPLK0001');

        self::assertSame(1, $result['tables']['commerce_payment_links']);
        self::assertSame(
            0,
            $this->connection->table('commerce_payment_links')->where('tenant_uuid', '=', '')->count()
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_payment_links')
                ->where('tenant_uuid', '=', 'tenantPLK0001')
                ->count()
        );
    }

    public function testAdoptRekeysMarketplaceTablesEvenWhenTheMasterSwitchIsOff(): void
    {
        self::assertFalse(
            (bool) config($this->context, 'commerce.marketplace.enabled', false),
            'this test relies on the master switch being off (the default)'
        );

        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktadoptset1',
            'tenant_uuid' => '',
            'status' => 'disabled',
        ]);
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => 'mktadoptsel1',
            'tenant_uuid' => '',
            'slug' => 'sentinel-seller',
            'name' => 'Sentinel Seller',
        ]);
        $this->connection->table('commerce_seller_memberships')->insert([
            'uuid' => 'mktadoptmem1',
            'tenant_uuid' => '',
            'seller_uuid' => 'mktadoptsel1',
            'user_uuid' => 'user000000001',
            'role' => 'seller_owner',
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantMKT0001');

        self::assertSame(1, $result['tables']['commerce_marketplace_settings']);
        self::assertSame(1, $result['tables']['commerce_sellers']);
        self::assertSame(1, $result['tables']['commerce_seller_memberships']);

        foreach (['commerce_marketplace_settings', 'commerce_sellers', 'commerce_seller_memberships'] as $table) {
            self::assertSame(
                0,
                $this->connection->table($table)->where('tenant_uuid', '=', '')->count(),
                "{$table} must have no sentinel rows left behind"
            );
            self::assertSame(
                1,
                $this->connection->table($table)->where('tenant_uuid', '=', 'tenantMKT0001')->count(),
                "{$table} row must be rekeyed to the adopted tenant"
            );
        }
    }

    /**
     * Design spec §7/§3.3, MV2 Task 10: `commerce_seller_orders` is
     * marketplace-aware regardless of the master switch, exactly like the
     * MV1 trio above -- {@see \Glueful\Extensions\Commerce\Support\DiagnosticsReport::tenantTables()}
     * lists it unconditionally, so `TenantAdopter` rekeys it too, switch off
     * or on.
     */
    public function testAdoptRekeysSellerOrdersTableEvenWhenTheMasterSwitchIsOff(): void
    {
        self::assertFalse(
            (bool) config($this->context, 'commerce.marketplace.enabled', false),
            'this test relies on the master switch being off (the default)'
        );

        $this->connection->table('commerce_seller_orders')->insert([
            'uuid' => 'mktadoptso01',
            'tenant_uuid' => '',
            'order_uuid' => 'mktadoptord1',
            'seller_uuid' => 'mktadoptsel1',
            'seller_name_snapshot' => 'Sentinel Seller',
            'partition_number' => 1,
            'seller_reference' => 'MKTADOPT-1',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
            'tax_attribution_method' => 'aggregate_allocated',
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantMKT0002');

        self::assertSame(1, $result['tables']['commerce_seller_orders']);
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_orders')->where('tenant_uuid', '=', '')->count(),
            'commerce_seller_orders must have no sentinel rows left behind'
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_orders')
                ->where('tenant_uuid', '=', 'tenantMKT0002')
                ->count(),
            'commerce_seller_orders row must be rekeyed to the adopted tenant'
        );
    }

    /**
     * Design spec §3: `commerce_seller_lifecycle_events` (MV5b, migration 017)
     * is marketplace-aware regardless of the master switch, exactly like the
     * MV1-MV5a tables above -- {@see \Glueful\Extensions\Commerce\Support\DiagnosticsReport::tenantTables()}
     * lists it unconditionally, so `TenantAdopter` rekeys it too, switch off
     * or on.
     */
    public function testAdoptRekeysSellerLifecycleEventsTableEvenWhenTheMasterSwitchIsOff(): void
    {
        self::assertFalse(
            (bool) config($this->context, 'commerce.marketplace.enabled', false),
            'this test relies on the master switch being off (the default)'
        );

        $this->connection->table('commerce_seller_lifecycle_events')->insert([
            'uuid' => 'mktadoptlev1',
            'tenant_uuid' => '',
            'seller_uuid' => 'mktadoptsel1',
            'from_status' => 'active',
            'to_status' => 'suspended',
            'actor_uuid' => 'operatoradopt1',
            'reason' => 'Sentinel adoption fixture.',
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantMKT0003');

        self::assertSame(1, $result['tables']['commerce_seller_lifecycle_events']);
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_lifecycle_events')
                ->where('tenant_uuid', '=', '')->count(),
            'commerce_seller_lifecycle_events must have no sentinel rows left behind'
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_lifecycle_events')
                ->where('tenant_uuid', '=', 'tenantMKT0003')->count(),
            'commerce_seller_lifecycle_events row must be rekeyed to the adopted tenant'
        );
    }

    /**
     * Cross-tenant isolation safety net (design spec §5/§6, mirroring
     * {@see testAdoptRekeysSentinelRowsAndRefusesMixedData}'s "refuses mixed
     * data" proof for `commerce_products`): a `commerce_seller_lifecycle_events`
     * row already keyed to a DIFFERENT, already-resolved tenant must never be
     * silently rekeyed by a later `adopt()` call targeting a new tenant --
     * the shared mixed-data guard must refuse, and the row must stay exactly
     * as it was.
     */
    public function testAdoptRefusesAndLeavesCrossTenantSellerLifecycleEventsRowIsolated(): void
    {
        $this->connection->table('commerce_seller_lifecycle_events')->insert([
            'uuid' => 'mktadoptlev2',
            'tenant_uuid' => 'tenantOTHER01',
            'seller_uuid' => 'mktadoptsel2',
            'from_status' => 'active',
            'to_status' => 'suspended',
            'actor_uuid' => 'operatoradopt2',
            'reason' => 'Cross-tenant control row.',
        ]);

        // The assertion is made OUTSIDE the try/catch on purpose: PHPUnit's
        // own self::fail() throws AssertionFailedError, which extends
        // \RuntimeException, so a self::fail() call placed inside a
        // `catch (\RuntimeException)` block would be silently swallowed by
        // that same catch and falsely reported as a pass.
        $refused = false;
        try {
            (new TenantAdopter())->adopt($this->context, 'tenantMKT0004');
        } catch (\RuntimeException) {
            $refused = true;
        }
        self::assertTrue(
            $refused,
            'adopt() must refuse when commerce_seller_lifecycle_events already contains another tenant\'s row'
        );

        $row = $this->connection->table('commerce_seller_lifecycle_events')
            ->where('uuid', '=', 'mktadoptlev2')
            ->first();
        self::assertNotNull($row);
        self::assertSame(
            'tenantOTHER01',
            $row['tenant_uuid'],
            'a row already keyed to another tenant must remain untouched by a refused adoption'
        );
    }

    /**
     * Design spec §3: all three MV5c-1 seller-API-key tables (migration 018)
     * -- `commerce_seller_api_keys`, `commerce_seller_api_key_credentials`,
     * `commerce_seller_api_key_events` -- are marketplace-aware regardless of
     * the master switch, exactly like the MV1-MV5b tables above --
     * {@see \Glueful\Extensions\Commerce\Support\DiagnosticsReport::tenantTables()}
     * lists all three unconditionally, so `TenantAdopter` rekeys all three
     * too, switch off or on.
     */
    public function testAdoptRekeysSellerApiKeyTablesEvenWhenTheMasterSwitchIsOff(): void
    {
        self::assertFalse(
            (bool) config($this->context, 'commerce.marketplace.enabled', false),
            'this test relies on the master switch being off (the default)'
        );

        $this->connection->table('commerce_seller_api_keys')->insert([
            'uuid' => 'mktadoptak01',
            'tenant_uuid' => '',
            'seller_uuid' => 'mktadoptsel1',
            'subject_user_uuid' => 'mktadoptusr1',
            'declared_scopes' => '["commerce.seller.orders.read"]',
            'name' => 'Sentinel key',
            'current_credential_uuid' => 'mktadoptcr01',
            'created_by' => 'mktadoptusr1',
        ]);
        $this->connection->table('commerce_seller_api_key_credentials')->insert([
            'uuid' => 'mktadoptcr01',
            'tenant_uuid' => '',
            'lineage_uuid' => 'mktadoptak01',
            'framework_key_uuid' => 'mktadoptfk01',
            'generation' => 1,
            'relationship' => 'current',
        ]);
        $this->connection->table('commerce_seller_api_key_events')->insert([
            'uuid' => 'mktadoptev01',
            'tenant_uuid' => '',
            'lineage_uuid' => 'mktadoptak01',
            'seller_uuid' => 'mktadoptsel1',
            'subject_user_uuid' => 'mktadoptusr1',
            'action' => 'created',
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantMKT0005');

        foreach (
            [
            'commerce_seller_api_keys',
            'commerce_seller_api_key_credentials',
            'commerce_seller_api_key_events',
            ] as $table
        ) {
            self::assertSame(1, $result['tables'][$table], "{$table} should have adopted exactly 1 sentinel row.");
            self::assertSame(
                0,
                $this->connection->table($table)->where('tenant_uuid', '=', '')->count(),
                "{$table} must have no sentinel rows left behind"
            );
            self::assertSame(
                1,
                $this->connection->table($table)->where('tenant_uuid', '=', 'tenantMKT0005')->count(),
                "{$table} row must be rekeyed to the adopted tenant"
            );
        }
    }

    /**
     * Cross-tenant isolation safety net (design spec §5/§6), mirroring
     * {@see testAdoptRefusesAndLeavesCrossTenantSellerLifecycleEventsRowIsolated}
     * for all three MV5c-1 seller-API-key tables: a row already keyed to a
     * DIFFERENT, already-resolved tenant in ANY of the three tables must
     * never be silently rekeyed by a later `adopt()` call -- the shared
     * mixed-data guard must refuse, and every row must stay exactly as it
     * was.
     */
    public function testAdoptRefusesAndLeavesCrossTenantSellerApiKeyRowsIsolated(): void
    {
        $this->connection->table('commerce_seller_api_keys')->insert([
            'uuid' => 'mktadoptak02',
            'tenant_uuid' => 'tenantOTHER01',
            'seller_uuid' => 'mktadoptsel2',
            'subject_user_uuid' => 'mktadoptusr2',
            'declared_scopes' => '["commerce.seller.orders.read"]',
            'name' => 'Cross-tenant control key',
            'current_credential_uuid' => 'mktadoptcr02',
            'created_by' => 'mktadoptusr2',
        ]);
        $this->connection->table('commerce_seller_api_key_credentials')->insert([
            'uuid' => 'mktadoptcr02',
            'tenant_uuid' => 'tenantOTHER01',
            'lineage_uuid' => 'mktadoptak02',
            'framework_key_uuid' => 'mktadoptfk02',
            'generation' => 1,
            'relationship' => 'current',
        ]);
        $this->connection->table('commerce_seller_api_key_events')->insert([
            'uuid' => 'mktadoptev02',
            'tenant_uuid' => 'tenantOTHER01',
            'lineage_uuid' => 'mktadoptak02',
            'seller_uuid' => 'mktadoptsel2',
            'subject_user_uuid' => 'mktadoptusr2',
            'action' => 'created',
        ]);

        // The assertion is made OUTSIDE the try/catch on purpose -- see the
        // note on testAdoptRefusesAndLeavesCrossTenantSellerLifecycleEventsRowIsolated()
        // above.
        $refused = false;
        try {
            (new TenantAdopter())->adopt($this->context, 'tenantMKT0006');
        } catch (\RuntimeException) {
            $refused = true;
        }
        self::assertTrue(
            $refused,
            'adopt() must refuse when a seller-API-key table already contains another tenant\'s row'
        );

        foreach (
            [
            'commerce_seller_api_keys' => 'mktadoptak02',
            'commerce_seller_api_key_credentials' => 'mktadoptcr02',
            'commerce_seller_api_key_events' => 'mktadoptev02',
            ] as $table => $uuid
        ) {
            $row = $this->connection->table($table)->where('uuid', '=', $uuid)->first();
            self::assertNotNull($row);
            self::assertSame(
                'tenantOTHER01',
                $row['tenant_uuid'],
                "a {$table} row already keyed to another tenant must remain untouched by a refused adoption"
            );
        }
    }

    /**
     * Design spec §3: all five MV5c-2 seller-webhook tables (migration 019)
     * -- `commerce_seller_webhook_endpoints`, `commerce_seller_webhook_secrets`,
     * `commerce_seller_webhook_events`, `commerce_seller_webhook_deliveries`,
     * `commerce_seller_webhook_endpoint_events` -- are marketplace-aware
     * regardless of the master switch, exactly like the MV1-MV5c-1 tables
     * above -- {@see \Glueful\Extensions\Commerce\Support\DiagnosticsReport::tenantTables()}
     * lists all five unconditionally, so `TenantAdopter` rekeys all five too,
     * switch off or on.
     */
    public function testAdoptRekeysSellerWebhookTablesEvenWhenTheMasterSwitchIsOff(): void
    {
        self::assertFalse(
            (bool) config($this->context, 'commerce.marketplace.enabled', false),
            'this test relies on the master switch being off (the default)'
        );

        $this->connection->table('commerce_seller_webhook_endpoints')->insert([
            'uuid' => 'mktadoptwe01',
            'tenant_uuid' => '',
            'seller_uuid' => 'mktadoptsel1',
            'url' => 'https://example.test/hooks',
            'subscribed_events' => '["order.placed"]',
            'created_by' => 'mktadoptusr1',
        ]);
        $this->connection->table('commerce_seller_webhook_secrets')->insert([
            'uuid' => 'mktadoptws01',
            'tenant_uuid' => '',
            'endpoint_uuid' => 'mktadoptwe01',
            'secret_ciphertext' => 'ciphertext-placeholder',
            'relationship' => 'current',
        ]);
        $this->connection->table('commerce_seller_webhook_events')->insert([
            'uuid' => 'mktadoptwv01',
            'tenant_uuid' => '',
            'seller_uuid' => 'mktadoptsel1',
            'event_type' => 'order.placed',
            'payload' => '{}',
            'occurred_at' => '2026-07-19 00:00:00',
        ]);
        $this->connection->table('commerce_seller_webhook_deliveries')->insert([
            'uuid' => 'mktadoptwd01',
            'tenant_uuid' => '',
            'endpoint_uuid' => 'mktadoptwe01',
            'webhook_event_uuid' => 'mktadoptwv01',
            'seller_uuid' => 'mktadoptsel1',
        ]);
        $this->connection->table('commerce_seller_webhook_endpoint_events')->insert([
            'uuid' => 'mktadoptwx01',
            'tenant_uuid' => '',
            'endpoint_uuid' => 'mktadoptwe01',
            'seller_uuid' => 'mktadoptsel1',
            'action' => 'register',
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantMKT0007');

        foreach (
            [
            'commerce_seller_webhook_endpoints',
            'commerce_seller_webhook_secrets',
            'commerce_seller_webhook_events',
            'commerce_seller_webhook_deliveries',
            'commerce_seller_webhook_endpoint_events',
            ] as $table
        ) {
            self::assertSame(1, $result['tables'][$table], "{$table} should have adopted exactly 1 sentinel row.");
            self::assertSame(
                0,
                $this->connection->table($table)->where('tenant_uuid', '=', '')->count(),
                "{$table} must have no sentinel rows left behind"
            );
            self::assertSame(
                1,
                $this->connection->table($table)->where('tenant_uuid', '=', 'tenantMKT0007')->count(),
                "{$table} row must be rekeyed to the adopted tenant"
            );
        }
    }

    /**
     * Cross-tenant isolation safety net (design spec §5/§6), mirroring
     * {@see testAdoptRefusesAndLeavesCrossTenantSellerApiKeyRowsIsolated} for
     * all five MV5c-2 seller-webhook tables: a row already keyed to a
     * DIFFERENT, already-resolved tenant in ANY of the five tables must never
     * be silently rekeyed by a later `adopt()` call -- the shared mixed-data
     * guard must refuse, and every row must stay exactly as it was.
     */
    public function testAdoptRefusesAndLeavesCrossTenantSellerWebhookRowsIsolated(): void
    {
        $this->connection->table('commerce_seller_webhook_endpoints')->insert([
            'uuid' => 'mktadoptwe02',
            'tenant_uuid' => 'tenantOTHER01',
            'seller_uuid' => 'mktadoptsel2',
            'url' => 'https://example.test/hooks-2',
            'subscribed_events' => '["order.placed"]',
            'created_by' => 'mktadoptusr2',
        ]);
        $this->connection->table('commerce_seller_webhook_secrets')->insert([
            'uuid' => 'mktadoptws02',
            'tenant_uuid' => 'tenantOTHER01',
            'endpoint_uuid' => 'mktadoptwe02',
            'secret_ciphertext' => 'ciphertext-placeholder',
            'relationship' => 'current',
        ]);
        $this->connection->table('commerce_seller_webhook_events')->insert([
            'uuid' => 'mktadoptwv02',
            'tenant_uuid' => 'tenantOTHER01',
            'seller_uuid' => 'mktadoptsel2',
            'event_type' => 'order.placed',
            'payload' => '{}',
            'occurred_at' => '2026-07-19 00:00:00',
        ]);
        $this->connection->table('commerce_seller_webhook_deliveries')->insert([
            'uuid' => 'mktadoptwd02',
            'tenant_uuid' => 'tenantOTHER01',
            'endpoint_uuid' => 'mktadoptwe02',
            'webhook_event_uuid' => 'mktadoptwv02',
            'seller_uuid' => 'mktadoptsel2',
        ]);
        $this->connection->table('commerce_seller_webhook_endpoint_events')->insert([
            'uuid' => 'mktadoptwx02',
            'tenant_uuid' => 'tenantOTHER01',
            'endpoint_uuid' => 'mktadoptwe02',
            'seller_uuid' => 'mktadoptsel2',
            'action' => 'register',
        ]);

        // The assertion is made OUTSIDE the try/catch on purpose -- see the
        // note on testAdoptRefusesAndLeavesCrossTenantSellerLifecycleEventsRowIsolated()
        // above.
        $refused = false;
        try {
            (new TenantAdopter())->adopt($this->context, 'tenantMKT0008');
        } catch (\RuntimeException) {
            $refused = true;
        }
        self::assertTrue(
            $refused,
            'adopt() must refuse when a seller-webhook table already contains another tenant\'s row'
        );

        foreach (
            [
            'commerce_seller_webhook_endpoints' => 'mktadoptwe02',
            'commerce_seller_webhook_secrets' => 'mktadoptws02',
            'commerce_seller_webhook_events' => 'mktadoptwv02',
            'commerce_seller_webhook_deliveries' => 'mktadoptwd02',
            'commerce_seller_webhook_endpoint_events' => 'mktadoptwx02',
            ] as $table => $uuid
        ) {
            $row = $this->connection->table($table)->where('uuid', '=', $uuid)->first();
            self::assertNotNull($row);
            self::assertSame(
                'tenantOTHER01',
                $row['tenant_uuid'],
                "a {$table} row already keyed to another tenant must remain untouched by a refused adoption"
            );
        }
    }

    private function seedSentinelCatalog(): void
    {
        (new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository()
        ))->createProduct($this->context, [
            'slug' => 'tee',
            'name' => 'Tee',
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => 'TEE',
                'option_values' => [],
                'price' => 100,
                'currency' => 'USD',
            ]],
        ]);
    }
}
