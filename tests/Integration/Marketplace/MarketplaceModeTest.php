<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\CountingPdoStatement;

/**
 * The two-level marketplace switch gate matrix (design spec §2.1):
 * `installEnabled()` reads config ONLY (zero database queries, proven via the
 * house `CountingPdoStatement` query-count guard -- see
 * {@see \Glueful\Extensions\Commerce\Tests\Integration\Http\StorefrontCatalogFilterTest}
 * for the established pattern), and `activeFor()` reads the tenant's
 * settings row: no row and an explicit `disabled` row are both inactive; only
 * an explicit `active` row is active.
 */
final class MarketplaceModeTest extends CommerceTestCase
{
    private MarketplaceMode $mode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mode = new MarketplaceMode();
    }

    public function testInstallEnabledIsFalseByDefaultAndIssuesZeroDatabaseQueries(): void
    {
        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        CountingPdoStatement::$count = 0;

        self::assertFalse($this->mode->installEnabled($this->context));

        self::assertSame(
            0,
            CountingPdoStatement::$count,
            'installEnabled() must be config-only -- the MASTER-OFF fast path issues zero queries'
        );
    }

    public function testInstallEnabledIsTrueWhenConfigFlagIsOnAndStillIssuesZeroDatabaseQueries(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        CountingPdoStatement::$count = 0;

        self::assertTrue($this->mode->installEnabled($this->context));
        self::assertSame(0, CountingPdoStatement::$count);
    }

    public function testActiveForIsFalseWhenNoSettingsRowExistsForTheTenant(): void
    {
        self::assertFalse($this->mode->activeFor($this->context, 'tenantNOROW01'));
    }

    public function testActiveForIsTrueOnlyWhenTheSettingsRowStatusIsActive(): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktmodeactv1',
            'tenant_uuid' => 'tenantACTV01',
            'status' => 'active',
        ]);

        self::assertTrue($this->mode->activeFor($this->context, 'tenantACTV01'));
    }

    public function testActiveForIsFalseWhenTheSettingsRowStatusIsDisabled(): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktmodedsbl1',
            'tenant_uuid' => 'tenantDSBL01',
            'status' => 'disabled',
        ]);

        self::assertFalse($this->mode->activeFor($this->context, 'tenantDSBL01'));
    }

    public function testActiveForIsTenantScopedAndDoesNotLeakAcrossTenants(): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktmodeownr1',
            'tenant_uuid' => 'tenantOWNR01',
            'status' => 'active',
        ]);

        self::assertTrue($this->mode->activeFor($this->context, 'tenantOWNR01'));
        self::assertFalse($this->mode->activeFor($this->context, 'tenantOTHER02'));
    }
}
