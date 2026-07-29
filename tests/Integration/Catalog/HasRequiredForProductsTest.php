<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\CountingPdoStatement;

/**
 * Storefront-v1 Task 1: {@see AddonRepository::hasRequiredForProducts()} --
 * ONE batched presence read: `product_uuid => true` exactly where an ACTIVE
 * `required` add-on definition exists (mirroring the pool
 * {@see AddonRepository::activeForProduct()} feeds AddonSnapshot -- an
 * inactive definition is not selectable, so it must not flag the product).
 * Input passes through the shared pinned uuid normalizer; empty normalized
 * set issues zero queries; products without an active required add-on are
 * simply absent.
 */
final class HasRequiredForProductsTest extends CommerceTestCase
{
    private const TENANT = 'tenantHRF001';
    private const OTHER_TENANT = 'tenantHRF002';

    private AddonRepository $addons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addons = new AddonRepository();
    }

    // === Empty / malformed input: zero queries ==================================

    public function testEmptyAndFullyMalformedInputIssueNoQuery(): void
    {
        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        CountingPdoStatement::$count = 0;

        self::assertSame([], $this->addons->hasRequiredForProducts($this->context, self::TENANT, []));
        self::assertSame([], $this->addons->hasRequiredForProducts($this->context, self::TENANT, [
            'under_score1',
            'nope',
            '',
            7,
        ]));
        self::assertSame(0, CountingPdoStatement::$count);
    }

    // === Presence semantics ======================================================

    public function testActiveRequiredAddonFlagsTheProductTrue(): void
    {
        $this->seedAddon('addonreq0001', 'prodhrfreq01', required: true, status: 'active');

        $result = $this->addons->hasRequiredForProducts($this->context, self::TENANT, ['prodhrfreq01']);

        self::assertSame(['prodhrfreq01' => true], $result);
    }

    public function testInactiveRequiredAddonDoesNotFlagTheProduct(): void
    {
        $this->seedAddon('addoninact01', 'prodhrfina01', required: true, status: 'inactive');

        $result = $this->addons->hasRequiredForProducts($this->context, self::TENANT, ['prodhrfina01']);

        self::assertSame([], $result);
    }

    public function testOptionalOnlyAddonsDoNotFlagTheProduct(): void
    {
        $this->seedAddon('addonopt0001', 'prodhrfopt01', required: false, status: 'active');
        $this->seedAddon('addonopt0002', 'prodhrfopt01', required: false, status: 'active');

        $result = $this->addons->hasRequiredForProducts($this->context, self::TENANT, ['prodhrfopt01']);

        self::assertSame([], $result);
    }

    public function testMixedBatchFlagsOnlyProductsWithAnActiveRequiredAddon(): void
    {
        $this->seedAddon('addonmixreq1', 'prodhrfmix01', required: true, status: 'active');
        $this->seedAddon('addonmixopt1', 'prodhrfmix01', required: false, status: 'active');
        $this->seedAddon('addonmixopt2', 'prodhrfmix02', required: false, status: 'active');
        $this->seedAddon('addonmixina1', 'prodhrfmix03', required: true, status: 'inactive');

        $result = $this->addons->hasRequiredForProducts($this->context, self::TENANT, [
            'prodhrfmix01',
            'prodhrfmix02',
            'prodhrfmix03',
            'prodhrfmix04',
        ]);

        self::assertSame(['prodhrfmix01' => true], $result);
    }

    // === Tenant isolation ========================================================

    public function testAnotherTenantsRequiredAddonNeverFlagsTheProduct(): void
    {
        $this->seedAddon('addonother01', 'prodhrfoth01', required: true, status: 'active', tenant: self::OTHER_TENANT);

        $result = $this->addons->hasRequiredForProducts($this->context, self::TENANT, ['prodhrfoth01']);

        self::assertSame([], $result);
    }

    // === Query-count guard =======================================================

    public function testNonEmptyInputIssuesExactlyOneQuery(): void
    {
        $this->seedAddon('addoncount01', 'prodhrfcnt01', required: true, status: 'active');
        $this->seedAddon('addoncount02', 'prodhrfcnt02', required: true, status: 'active');

        // Warm-up: the framework's SoftDeleteHandler runs a one-time,
        // process-cached schema probe the first time any query touches a
        // given table -- run once, unmeasured.
        $this->addons->hasRequiredForProducts($this->context, self::TENANT, ['prodhrfcnt01']);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        CountingPdoStatement::$count = 0;

        $result = $this->addons->hasRequiredForProducts($this->context, self::TENANT, [
            'prodhrfcnt01',
            'prodhrfcnt02',
        ]);

        self::assertSame(1, CountingPdoStatement::$count, 'expected exactly one batched query');
        self::assertSame(['prodhrfcnt01' => true, 'prodhrfcnt02' => true], $result);
    }

    // === Fixtures ================================================================

    private function seedAddon(
        string $uuid,
        string $productUuid,
        bool $required,
        string $status,
        string $tenant = self::TENANT
    ): void {
        $this->connection->table('commerce_product_addons')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'name' => 'Addon ' . $uuid,
            'field_type' => 'text',
            'required' => $required ? 1 : 0,
            'price_delta' => 0,
            'position' => 0,
            'status' => $status,
        ]);
    }
}
