<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyEventRepository;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Rolling-reserve policy authority + durable audit (design spec §2.1,
 * MV5a Task 6): {@see ReservePolicyService} mirrors MV3's
 * {@see \Glueful\Extensions\Commerce\Marketplace\CommissionPolicyService}
 * pattern -- workspace default + per-seller override, both mutations
 * applied + audited atomically in the SAME transaction, actor mandatory,
 * invalid input rejected before any write.
 */
final class ReservePolicyTest extends CommerceTestCase
{
    // -----------------------------------------------------------------
    // resolve(): precedence -- seller override wins; NULL inherits; an
    // explicit 0 disables without inheriting; bps/days resolve
    // independently.
    // -----------------------------------------------------------------

    public function testResolveSellerOverrideWinsOverWorkspaceDefault(): void
    {
        $this->seedWorkspace('tenantRSV001', 500, 10);
        $this->seedSeller('tenantRSV001', 'sellerRSV001', 200, 5);

        $resolved = $this->reserveService()->resolve($this->context, 'tenantRSV001', 'sellerRSV001');

        self::assertSame(['reserve_bps' => 200, 'reserve_days' => 5], $resolved);
    }

    public function testResolveNullSellerOverrideInheritsWorkspaceDefaultForBothFields(): void
    {
        $this->seedWorkspace('tenantRSV002', 500, 10);
        $this->seedSeller('tenantRSV002', 'sellerRSV002', null, null);

        $resolved = $this->reserveService()->resolve($this->context, 'tenantRSV002', 'sellerRSV002');

        self::assertSame(['reserve_bps' => 500, 'reserve_days' => 10], $resolved);
    }

    public function testResolveExplicitZeroSellerBpsDisablesWithoutInheritingWorkspace(): void
    {
        $this->seedWorkspace('tenantRSV003', 500, 10);
        $this->seedSeller('tenantRSV003', 'sellerRSV003', 0, null);

        $resolved = $this->reserveService()->resolve($this->context, 'tenantRSV003', 'sellerRSV003');

        self::assertSame(
            ['reserve_bps' => 0, 'reserve_days' => 10],
            $resolved,
            'explicit 0 bps must disable (not inherit) while days independently inherits'
        );
    }

    public function testResolveExplicitZeroSellerDaysDisablesWithoutInheritingWorkspace(): void
    {
        $this->seedWorkspace('tenantRSV004', 500, 10);
        $this->seedSeller('tenantRSV004', 'sellerRSV004', null, 0);

        $resolved = $this->reserveService()->resolve($this->context, 'tenantRSV004', 'sellerRSV004');

        self::assertSame(
            ['reserve_bps' => 500, 'reserve_days' => 0],
            $resolved,
            'explicit 0 days must disable (not inherit) while bps independently inherits'
        );
    }

    public function testResolveIndependentFieldsOneOverriddenOneInherited(): void
    {
        $this->seedWorkspace('tenantRSV005', 500, 10);
        $this->seedSeller('tenantRSV005', 'sellerRSV005', 1200, null);

        $resolved = $this->reserveService()->resolve($this->context, 'tenantRSV005', 'sellerRSV005');

        self::assertSame(['reserve_bps' => 1200, 'reserve_days' => 10], $resolved);
    }

    public function testResolveWithNoWorkspaceSettingsRowYetDefaultsToZeroZero(): void
    {
        // No commerce_marketplace_settings row for this tenant at all -- a
        // never-activated/never-configured workspace. Folded column default
        // (design spec §3.1) is 0/0, never an error.
        $this->seedSeller('tenantRSV006', 'sellerRSV006', null, null);

        $resolved = $this->reserveService()->resolve($this->context, 'tenantRSV006', 'sellerRSV006');

        self::assertSame(['reserve_bps' => 0, 'reserve_days' => 0], $resolved);
    }

    public function testResolveUnknownSellerThrowsNotFound(): void
    {
        $this->seedWorkspace('tenantRSV007', 500, 10);

        $this->expectException(NotFoundException::class);
        $this->reserveService()->resolve($this->context, 'tenantRSV007', 'missingSlr01');
    }

    // -----------------------------------------------------------------
    // setWorkspace()/setSeller(): applied + audited atomically, before/after.
    // -----------------------------------------------------------------

    public function testSetWorkspaceWritesColumnsAndAMatchingAuditRowAtomically(): void
    {
        $this->seedWorkspace('tenantRSV010', 500, 10);

        $this->reserveService()->setWorkspace($this->context, 'tenantRSV010', 750, 21, 'operatorU001');

        $settings = $this->connection->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', 'tenantRSV010')
            ->first();
        self::assertSame(750, (int) $settings['reserve_bps']);
        self::assertSame(21, (int) $settings['reserve_days']);

        $events = $this->connection->table('commerce_reserve_policy_events')
            ->where('subject_kind', '=', 'workspace')
            ->where('subject_uuid', '=', 'tenantRSV010')
            ->get();
        self::assertCount(1, $events);
        self::assertSame('operatorU001', $events[0]['actor_uuid']);
        self::assertSame(
            ['reserve_bps' => 500, 'reserve_days' => 10],
            json_decode((string) $events[0]['before_policy'], true)
        );
        self::assertSame(
            ['reserve_bps' => 750, 'reserve_days' => 21],
            json_decode((string) $events[0]['after_policy'], true)
        );
    }

    public function testSetWorkspaceLazilyCreatesTheSettingsRowAndAuditsWhenTenantNeverActivated(): void
    {
        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_settings')
                ->where('tenant_uuid', '=', 'tenantRSV011')->count(),
            'precondition: this tenant has never activated marketplace mode'
        );

        $this->reserveService()->setWorkspace($this->context, 'tenantRSV011', 300, 14, 'operatorU002');

        $settings = $this->connection->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', 'tenantRSV011')
            ->first();
        self::assertNotNull($settings);
        self::assertSame(300, (int) $settings['reserve_bps']);
        self::assertSame(14, (int) $settings['reserve_days']);
        self::assertSame('disabled', $settings['status'], 'setWorkspace never itself activates marketplace mode');

        $events = $this->connection->table('commerce_reserve_policy_events')
            ->where('subject_kind', '=', 'workspace')
            ->where('subject_uuid', '=', 'tenantRSV011')
            ->get();
        self::assertCount(1, $events);
        self::assertSame(
            ['reserve_bps' => 0, 'reserve_days' => 0],
            json_decode((string) $events[0]['before_policy'], true)
        );
    }

    public function testSetSellerWritesColumnsAndAMatchingAuditRowAtomically(): void
    {
        $this->seedWorkspace('tenantRSV012', 100, 3);
        $this->seedSeller('tenantRSV012', 'sellerRSV012', null, null);

        $this->reserveService()->setSeller($this->context, 'tenantRSV012', 'sellerRSV012', 300, 7, 'operatorU003');

        $seller = $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerRSV012')->first();
        self::assertSame(300, (int) $seller['reserve_bps']);
        self::assertSame(7, (int) $seller['reserve_days']);

        $events = $this->connection->table('commerce_reserve_policy_events')
            ->where('subject_kind', '=', 'seller')
            ->where('subject_uuid', '=', 'sellerRSV012')
            ->get();
        self::assertCount(1, $events);
        self::assertSame(
            ['reserve_bps' => null, 'reserve_days' => null],
            json_decode((string) $events[0]['before_policy'], true)
        );
        self::assertSame(
            ['reserve_bps' => 300, 'reserve_days' => 7],
            json_decode((string) $events[0]['after_policy'], true)
        );
    }

    public function testSetSellerCanExplicitlyClearBackToNullInheritAndItIsAudited(): void
    {
        $this->seedWorkspace('tenantRSV013', 100, 3);
        $this->seedSeller('tenantRSV013', 'sellerRSV013', 900, 30);

        $this->reserveService()->setSeller($this->context, 'tenantRSV013', 'sellerRSV013', null, null, 'operatorU004');

        $seller = $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerRSV013')->first();
        self::assertNull($seller['reserve_bps']);
        self::assertNull($seller['reserve_days']);

        $event = $this->connection->table('commerce_reserve_policy_events')
            ->where('subject_uuid', '=', 'sellerRSV013')
            ->first();
        self::assertSame(
            ['reserve_bps' => 900, 'reserve_days' => 30],
            json_decode((string) $event['before_policy'], true)
        );
        self::assertSame(
            ['reserve_bps' => null, 'reserve_days' => null],
            json_decode((string) $event['after_policy'], true)
        );
    }

    public function testSetSellerOnUnknownSellerThrowsNotFoundBeforeAnyAuditRow(): void
    {
        $this->expectException(NotFoundException::class);
        $this->reserveService()->setSeller($this->context, 'tenantRSV014', 'missingSlr02', 100, 1, 'operatorU005');
    }

    // -----------------------------------------------------------------
    // Forced audit-insert uuid collision rolls back the policy change too
    // (the CommissionPolicyService atomicity idiom).
    // -----------------------------------------------------------------

    public function testForcedAuditInsertUuidCollisionRollsBackTheWorkspacePolicyChange(): void
    {
        $this->seedWorkspace('tenantRSV020', 100, 3);
        // Pre-seed a colliding audit row under the EXACT (tenant_uuid, uuid) the
        // fixed generator below will hand to the audit insert -- a genuine
        // unique-constraint PDOException, forcing the SAME transaction's
        // workspace reserve-policy write (which "committed" moments earlier in
        // the SAME closure) to roll back too.
        $this->connection->table('commerce_reserve_policy_events')->insert([
            'uuid' => 'collideevt10',
            'tenant_uuid' => 'tenantRSV020',
            'subject_kind' => 'workspace',
            'subject_uuid' => 'someOtherTen',
            'actor_uuid' => 'someoneElse1',
            'before_policy' => json_encode(['reserve_bps' => 0, 'reserve_days' => 0]),
            'after_policy' => json_encode(['reserve_bps' => 0, 'reserve_days' => 0]),
        ]);

        $service = $this->reserveService(static fn (): string => 'collideevt10');

        try {
            $service->setWorkspace($this->context, 'tenantRSV020', 999, 60, 'operatorU006');
            self::fail('expected the forced audit-insert collision to propagate');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        $settings = $this->connection->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', 'tenantRSV020')
            ->first();
        self::assertSame(100, (int) $settings['reserve_bps'], 'the workspace reserve columns must roll back too');
        self::assertSame(3, (int) $settings['reserve_days']);

        self::assertSame(
            1,
            $this->connection->table('commerce_reserve_policy_events')
                ->where('uuid', '=', 'collideevt10')->count(),
            'no second audit row must have been inserted'
        );
    }

    public function testForcedAuditInsertUuidCollisionRollsBackTheSellerPolicyChange(): void
    {
        $this->seedWorkspace('tenantRSV021', 0, 0);
        $this->seedSeller('tenantRSV021', 'sellerRSV021', null, null);
        $this->connection->table('commerce_reserve_policy_events')->insert([
            'uuid' => 'collideevt11',
            'tenant_uuid' => 'tenantRSV021',
            'subject_kind' => 'seller',
            'subject_uuid' => 'someOtherSlr',
            'actor_uuid' => 'someoneElse2',
            'before_policy' => json_encode(['reserve_bps' => null, 'reserve_days' => null]),
            'after_policy' => json_encode(['reserve_bps' => null, 'reserve_days' => null]),
        ]);

        $service = $this->reserveService(static fn (): string => 'collideevt11');

        try {
            $service->setSeller($this->context, 'tenantRSV021', 'sellerRSV021', 400, 9, 'operatorU007');
            self::fail('expected the forced audit-insert collision to propagate');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        $seller = $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerRSV021')->first();
        self::assertNull($seller['reserve_bps'], 'the seller reserve columns must roll back too');
        self::assertNull($seller['reserve_days']);

        self::assertSame(
            1,
            $this->connection->table('commerce_reserve_policy_events')
                ->where('uuid', '=', 'collideevt11')->count(),
            'no second audit row must have been inserted'
        );
    }

    // -----------------------------------------------------------------
    // Invalid bps/days: rejected before any claim or write.
    // -----------------------------------------------------------------

    public function testSetWorkspaceBpsAboveRangeIsRejectedBeforeAnyWrite(): void
    {
        $this->seedWorkspace('tenantRSV030', 100, 3);

        try {
            $this->reserveService()->setWorkspace($this->context, 'tenantRSV030', 10001, 5, 'operatorU008');
            self::fail('expected ValidationException for an out-of-range reserve_bps');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('reserve_bps', $e->firstErrors());
        }

        $settings = $this->connection->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', 'tenantRSV030')
            ->first();
        self::assertSame(100, (int) $settings['reserve_bps'], 'the policy must be unchanged');
        self::assertSame(0, $settings['revision'], 'an invalid policy must never even claim the row');
        self::assertSame(0, $this->connection->table('commerce_reserve_policy_events')->count());
    }

    public function testSetWorkspaceBpsBelowRangeIsRejected(): void
    {
        $this->seedWorkspace('tenantRSV031', 100, 3);

        $this->expectException(ValidationException::class);
        $this->reserveService()->setWorkspace($this->context, 'tenantRSV031', -1, 5, 'operatorU009');
    }

    public function testSetWorkspaceNegativeDaysIsRejected(): void
    {
        $this->seedWorkspace('tenantRSV032', 100, 3);

        try {
            $this->reserveService()->setWorkspace($this->context, 'tenantRSV032', 100, -1, 'operatorU010');
            self::fail('expected ValidationException for a negative reserve_days');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('reserve_days', $e->firstErrors());
        }

        self::assertSame(0, $this->connection->table('commerce_reserve_policy_events')->count());
    }

    public function testSetSellerOutOfRangeBpsIsRejectedButNullIsStillAllowed(): void
    {
        $this->seedWorkspace('tenantRSV033', 0, 0);
        $this->seedSeller('tenantRSV033', 'sellerRSV033', null, null);

        $this->expectException(ValidationException::class);
        $this->reserveService()->setSeller($this->context, 'tenantRSV033', 'sellerRSV033', 20000, null, 'operatorU011');
    }

    public function testSetSellerNegativeDaysIsRejected(): void
    {
        $this->seedWorkspace('tenantRSV034', 0, 0);
        $this->seedSeller('tenantRSV034', 'sellerRSV034', null, null);

        $this->expectException(ValidationException::class);
        $this->reserveService()->setSeller($this->context, 'tenantRSV034', 'sellerRSV034', null, -5, 'operatorU012');
    }

    // -----------------------------------------------------------------
    // Missing/blank actor: rejected with a 422 before any claim.
    // -----------------------------------------------------------------

    public function testSetWorkspaceMissingActorIsRejectedWithA422MappableValidationExceptionBeforeAnyClaim(): void
    {
        $this->seedWorkspace('tenantRSV040', 100, 3);

        try {
            $this->reserveService()->setWorkspace($this->context, 'tenantRSV040', 200, 4, null);
            self::fail('expected ValidationException for a null actor');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('actor_uuid', $e->firstErrors());
        }

        $settings = $this->connection->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', 'tenantRSV040')
            ->first();
        self::assertSame(100, (int) $settings['reserve_bps']);
        self::assertSame(0, $settings['revision'], 'a missing actor must never even claim the row');
        self::assertSame(0, $this->connection->table('commerce_reserve_policy_events')->count());
    }

    public function testSetSellerBlankActorIsRejectedWithA422MappableValidationExceptionBeforeAnyClaim(): void
    {
        $this->seedWorkspace('tenantRSV041', 0, 0);
        $this->seedSeller('tenantRSV041', 'sellerRSV041', null, null);

        try {
            $this->reserveService()->setSeller($this->context, 'tenantRSV041', 'sellerRSV041', 100, 1, '   ');
            self::fail('expected ValidationException for a blank actor');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('actor_uuid', $e->firstErrors());
        }

        $seller = $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerRSV041')->first();
        self::assertNull($seller['reserve_bps']);
        self::assertSame(0, $seller['revision'], 'a blank actor must never even claim the row');
        self::assertSame(0, $this->connection->table('commerce_reserve_policy_events')->count());
    }

    // -----------------------------------------------------------------
    // Append-only: no update/delete surface on the audit repository.
    // -----------------------------------------------------------------

    public function testAuditRepositoryExposesNoUpdateOrDeleteMethod(): void
    {
        self::assertFalse(method_exists(ReservePolicyEventRepository::class, 'update'));
        self::assertFalse(method_exists(ReservePolicyEventRepository::class, 'delete'));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function reserveService(?callable $uuidGenerator = null): ReservePolicyService
    {
        return new ReservePolicyService(
            new SellerRepository(),
            new MarketplaceWorkspaceLock(),
            new ReservePolicyEventRepository(),
            $uuidGenerator
        );
    }

    private function seedWorkspace(string $tenant, int $bps = 0, int $days = 0): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'ws' . substr(md5($tenant), 0, 10),
            'tenant_uuid' => $tenant,
            'status' => 'disabled',
            'reserve_bps' => $bps,
            'reserve_days' => $days,
        ]);
    }

    private function seedSeller(string $tenant, string $uuid, ?int $bps, ?int $days): void
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'status' => 'active',
            'reserve_bps' => $bps,
            'reserve_days' => $days,
        ]);
    }
}
