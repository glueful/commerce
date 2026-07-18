<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyEventRepository;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyException;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyService;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Commission-policy authority + durable audit (design spec §2.3, MV3 Task 4):
 * setting commission policy on a product, seller, or workspace is
 * platform-operator-only, and every mutation writes an append-only
 * {@see CommissionPolicyEventRepository} row in the SAME transaction as the
 * policy write itself. Covers the {@see CommissionPolicyService} contract
 * directly (applied + audited atomically, forced audit-insert failure rolls
 * back the policy change, invalid policy rejected before any write, the
 * event is optional), its wiring into {@see CatalogService::updateProduct()}/
 * {@see SellerService::update()}, and the seller-side rejection over REAL
 * routes (field-specific 422 on create/update, 403 precedence for a caller
 * lacking the route capability).
 */
final class CommissionAuthorityTest extends CommerceRouterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMarketplace();
        $this->bindFakeAuth();
    }

    // -----------------------------------------------------------------
    // CommissionPolicyService: applied + audited atomically, before/after.
    // -----------------------------------------------------------------

    public function testSetProductAppliesPolicyAndWritesAuditRowInOneTransaction(): void
    {
        $this->seedRawProduct('prodAAAA001', ['commission_kind' => 'percentage', 'commission_bps' => 500]);

        $this->commissionService()->setProduct(
            $this->context,
            $this->tenant,
            'prodAAAA001',
            ['kind' => 'fixed', 'fixed' => 250],
            'operatorU001'
        );

        $product = $this->connection->table('commerce_products')->where('uuid', '=', 'prodAAAA001')->first();
        self::assertSame('fixed', $product['commission_kind']);
        self::assertNull($product['commission_bps']);
        self::assertSame(250, (int) $product['commission_fixed']);

        $events = $this->connection->table('commerce_commission_policy_events')
            ->where('subject_kind', '=', 'product')
            ->where('subject_uuid', '=', 'prodAAAA001')
            ->get();
        self::assertCount(1, $events);
        self::assertSame('operatorU001', $events[0]['actor_uuid']);
        self::assertSame(
            ['kind' => 'percentage', 'bps' => 500, 'fixed' => null],
            json_decode((string) $events[0]['before_policy'], true)
        );
        self::assertSame(
            ['kind' => 'fixed', 'bps' => null, 'fixed' => 250],
            json_decode((string) $events[0]['after_policy'], true)
        );
    }

    public function testSetProductBeforePolicyCapturesAllNullWhenNoPriorOverrideExisted(): void
    {
        $this->seedRawProduct('prodNULLBEF1');

        $this->commissionService()->setProduct(
            $this->context,
            $this->tenant,
            'prodNULLBEF1',
            ['kind' => 'percentage', 'bps' => 100],
            'operatorU002'
        );

        $event = $this->connection->table('commerce_commission_policy_events')
            ->where('subject_uuid', '=', 'prodNULLBEF1')
            ->first();
        self::assertSame(
            ['kind' => null, 'bps' => null, 'fixed' => null],
            json_decode((string) $event['before_policy'], true)
        );
    }

    public function testSetSellerAppliesPolicyAndWritesAuditRow(): void
    {
        $this->seedRawSeller('sellerAUD001', ['commission_kind' => 'fixed', 'commission_fixed' => 100]);

        $this->commissionService()->setSeller(
            $this->context,
            $this->tenant,
            'sellerAUD001',
            ['kind' => 'percentage', 'bps' => 1200],
            'operatorU003'
        );

        $seller = $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerAUD001')->first();
        self::assertSame('percentage', $seller['commission_kind']);
        self::assertSame(1200, (int) $seller['commission_bps']);
        self::assertNull($seller['commission_fixed']);

        $events = $this->connection->table('commerce_commission_policy_events')
            ->where('subject_kind', '=', 'seller')
            ->where('subject_uuid', '=', 'sellerAUD001')
            ->get();
        self::assertCount(1, $events);
        self::assertSame(
            ['kind' => 'fixed', 'bps' => null, 'fixed' => 100],
            json_decode((string) $events[0]['before_policy'], true)
        );
    }

    public function testSetWorkspaceLazilyCreatesTheSettingsRowAndAudits(): void
    {
        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_settings')
                ->where('tenant_uuid', '=', $this->tenant)->count(),
            'precondition: this tenant has never activated marketplace mode'
        );

        $this->commissionService()->setWorkspace(
            $this->context,
            $this->tenant,
            $this->tenant,
            ['kind' => 'percentage', 'bps' => 250],
            'operatorU004'
        );

        $settings = $this->connection->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', $this->tenant)
            ->first();
        self::assertNotNull($settings);
        self::assertSame('percentage', $settings['commission_kind']);
        self::assertSame(250, (int) $settings['commission_bps']);
        self::assertSame('disabled', $settings['status'], 'setWorkspace never itself activates marketplace mode');

        $events = $this->connection->table('commerce_commission_policy_events')
            ->where('subject_kind', '=', 'workspace')
            ->where('subject_uuid', '=', $this->tenant)
            ->get();
        self::assertCount(1, $events);
        self::assertSame(
            ['kind' => null, 'bps' => null, 'fixed' => null],
            json_decode((string) $events[0]['before_policy'], true)
        );
    }

    // -----------------------------------------------------------------
    // Forced audit-insert failure rolls back the policy change too.
    // -----------------------------------------------------------------

    public function testForcedAuditInsertFailureRollsBackTheProductPolicyChange(): void
    {
        $this->seedRawProduct('prodROLLBK01');
        // Pre-seed a colliding audit row under the EXACT (tenant_uuid, uuid) the
        // fixed generator below will hand to the audit insert -- a genuine
        // unique-constraint PDOException, forcing the SAME transaction's product
        // commission write (which "committed" moments earlier in the SAME
        // closure) to roll back too.
        $this->connection->table('commerce_commission_policy_events')->insert([
            'uuid' => 'collideevt01',
            'tenant_uuid' => $this->tenant,
            'subject_kind' => 'product',
            'subject_uuid' => 'someOtherPro',
            'actor_uuid' => 'someoneElse1',
            'before_policy' => json_encode(['kind' => null, 'bps' => null, 'fixed' => null]),
            'after_policy' => json_encode(['kind' => null, 'bps' => null, 'fixed' => null]),
        ]);

        $service = $this->commissionService(static fn (): string => 'collideevt01');

        try {
            $service->setProduct(
                $this->context,
                $this->tenant,
                'prodROLLBK01',
                ['kind' => 'percentage', 'bps' => 750],
                'operatorU005'
            );
            self::fail('expected the forced audit-insert collision to propagate');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        $product = $this->connection->table('commerce_products')->where('uuid', '=', 'prodROLLBK01')->first();
        self::assertNull($product['commission_kind'], 'the product commission columns must roll back too');

        self::assertSame(
            1,
            $this->connection->table('commerce_commission_policy_events')
                ->where('uuid', '=', 'collideevt01')->count(),
            'no second audit row must have been inserted'
        );
    }

    public function testForcedAuditInsertFailureRollsBackTheSellerPolicyChange(): void
    {
        $this->seedRawSeller('sellerROLLBK1');
        $this->connection->table('commerce_commission_policy_events')->insert([
            'uuid' => 'collideevt02',
            'tenant_uuid' => $this->tenant,
            'subject_kind' => 'seller',
            'subject_uuid' => 'someOtherSlr',
            'actor_uuid' => 'someoneElse2',
            'before_policy' => json_encode(['kind' => null, 'bps' => null, 'fixed' => null]),
            'after_policy' => json_encode(['kind' => null, 'bps' => null, 'fixed' => null]),
        ]);

        $service = $this->commissionService(static fn (): string => 'collideevt02');

        try {
            $service->setSeller(
                $this->context,
                $this->tenant,
                'sellerROLLBK1',
                ['kind' => 'fixed', 'fixed' => 50],
                'operatorU006'
            );
            self::fail('expected the forced audit-insert collision to propagate');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        $seller = $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerROLLBK1')->first();
        self::assertNull($seller['commission_kind']);
    }

    // -----------------------------------------------------------------
    // Invalid policy: rejected before any write.
    // -----------------------------------------------------------------

    public function testInvalidMixedPolicyIsRejectedBeforeAnyWrite(): void
    {
        $this->seedRawProduct('prodINVALID1');

        try {
            $this->commissionService()->setProduct(
                $this->context,
                $this->tenant,
                'prodINVALID1',
                ['kind' => 'percentage', 'bps' => 500, 'fixed' => 100],
                'operatorU007'
            );
            self::fail('expected CommissionPolicyException for a mixed percentage+fixed policy');
        } catch (CommissionPolicyException) {
            $this->addToAssertionCount(1);
        }

        $product = $this->connection->table('commerce_products')->where('uuid', '=', 'prodINVALID1')->first();
        self::assertNull($product['commission_kind']);
        self::assertSame(0, $product['catalog_revision'], 'an invalid policy must never even claim the row');
        self::assertSame(0, $this->connection->table('commerce_commission_policy_events')->count());
    }

    public function testOutOfRangeBpsIsRejected(): void
    {
        $this->seedRawProduct('prodBPSOOR01');

        $this->expectException(CommissionPolicyException::class);
        $this->commissionService()->setProduct(
            $this->context,
            $this->tenant,
            'prodBPSOOR01',
            ['kind' => 'percentage', 'bps' => 10001],
            'operatorU008'
        );
    }

    public function testMissingActorIsRejectedWithA422MappableValidationException(): void
    {
        $this->seedRawProduct('prodNOACTOR1');

        try {
            $this->commissionService()->setProduct(
                $this->context,
                $this->tenant,
                'prodNOACTOR1',
                ['kind' => 'percentage', 'bps' => 100],
                null
            );
            self::fail('expected ValidationException for a null actor');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('actor_uuid', $e->firstErrors());
        }

        self::assertSame(0, $this->connection->table('commerce_commission_policy_events')->count());
    }

    // -----------------------------------------------------------------
    // Append-only: no update/delete surface on the audit repository.
    // -----------------------------------------------------------------

    public function testAuditRepositoryExposesNoUpdateOrDeleteMethod(): void
    {
        self::assertFalse(method_exists(CommissionPolicyEventRepository::class, 'update'));
        self::assertFalse(method_exists(CommissionPolicyEventRepository::class, 'delete'));
    }

    // -----------------------------------------------------------------
    // CommissionPolicyChanged is optional: unbound dispatch never affects
    // audit durability.
    // -----------------------------------------------------------------

    public function testUnboundEventDispatchNeverAffectsAuditDurability(): void
    {
        self::assertFalse(
            $this->contextContainer()->has(EventService::class),
            'this harness must not bind EventService -- proves an unbound dispatch is a true no-op'
        );

        $this->seedRawProduct('prodEVTOPT01');
        $this->commissionService()->setProduct(
            $this->context,
            $this->tenant,
            'prodEVTOPT01',
            ['kind' => 'percentage', 'bps' => 300],
            'operatorU009'
        );

        $product = $this->connection->table('commerce_products')->where('uuid', '=', 'prodEVTOPT01')->first();
        self::assertSame('percentage', $product['commission_kind']);
        self::assertSame(
            1,
            $this->connection->table('commerce_commission_policy_events')
                ->where('subject_uuid', '=', 'prodEVTOPT01')->count()
        );
    }

    // -----------------------------------------------------------------
    // CatalogService::updateProduct()/SellerService::update() wiring
    // (design spec §2.3: "attaches to the existing update surfaces").
    // -----------------------------------------------------------------

    public function testCatalogServiceUpdateProductRoutesCommissionThroughPolicyServiceAndAppliesOtherFieldsToo(): void
    {
        $this->seedRawProduct('prodCATSVC01');
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant(),
            new StockRepository(),
            new ProductChildrenRepository(),
            new ShippingClassRepository(),
            new MarketplaceMode(),
            new MarketplaceWorkspaceLock(),
            new SellerRepository(),
            $this->commissionService()
        );

        $catalog->updateProduct(
            $this->context,
            'prodCATSVC01',
            ['commission_kind' => 'fixed', 'commission_fixed' => 400, 'name' => 'Renamed Product'],
            'operatorU010'
        );

        $product = $this->connection->table('commerce_products')->where('uuid', '=', 'prodCATSVC01')->first();
        self::assertSame('fixed', $product['commission_kind']);
        self::assertSame(400, (int) $product['commission_fixed']);
        self::assertSame('Renamed Product', $product['name']);

        self::assertSame(
            1,
            $this->connection->table('commerce_commission_policy_events')
                ->where('subject_kind', '=', 'product')->where('subject_uuid', '=', 'prodCATSVC01')->count()
        );
    }

    public function testCatalogServiceUpdateProductWithoutACommissionPolicyCollaboratorRejectsCommissionFields(): void
    {
        $this->seedRawProduct('prodNOCOLLAB1');
        // Deliberately NO marketplace collaborators at all (pre-MV1 direct
        // construction) -- proves commission fields are never silently
        // written when there is nothing to validate/audit them.
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant(),
            new StockRepository()
        );

        $this->expectException(ValidationException::class);
        $catalog->updateProduct($this->context, 'prodNOCOLLAB1', [
            'commission_kind' => 'fixed',
            'commission_fixed' => 1,
        ]);
    }

    public function testSellerServiceUpdateRoutesCommissionThroughPolicyServiceAndAppliesOtherFieldsToo(): void
    {
        $this->seedRawSeller('sellerOPUP001');
        $service = new SellerService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            null,
            $this->commissionService()
        );

        $updated = $service->update(
            $this->context,
            $this->tenant,
            'sellerOPUP001',
            ['commission_kind' => 'percentage', 'commission_bps' => 800, 'name' => 'Renamed Seller'],
            'operatorU011'
        );

        self::assertSame('percentage', $updated['commission_kind']);
        self::assertSame(800, (int) $updated['commission_bps']);
        self::assertSame('Renamed Seller', $updated['name']);

        self::assertSame(
            1,
            $this->connection->table('commerce_commission_policy_events')
                ->where('subject_kind', '=', 'seller')->where('subject_uuid', '=', 'sellerOPUP001')->count()
        );
    }

    // -----------------------------------------------------------------
    // Seller commission rejection over REAL routes: field-specific 422 on
    // create AND update, plus the service-level backstop.
    // -----------------------------------------------------------------

    public function testSellerCreateWithACommissionFieldIsRejectedWithFieldSpecific422(): void
    {
        $this->activateWorkspace();
        $seller = $this->seedSeller('create-reject', 'ownerUserCR1');

        $router = $this->freshRouter();
        $payload = $this->productPayload('create-reject-p');
        $payload['commission_kind'] = 'percentage';
        $payload['commission_bps'] = 500;

        $response = $this->dispatch($router, $this->requestAs(
            'ownerUserCR1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/products",
            $payload
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('commission_kind', $this->json($response)['error']['details']);
        self::assertSame(
            0,
            $this->connection->table('commerce_products')->where('slug', '=', 'create-reject-p')->count(),
            'no product must have been created'
        );
    }

    public function testSellerUpdateWithACommissionFieldIsRejectedWithFieldSpecific422(): void
    {
        $this->activateWorkspace();
        $seller = $this->seedSeller('update-reject', 'ownerUserUR1');
        $product = $this->seedProduct($seller['uuid'], 'update-reject-p');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUserUR1',
            'PATCH',
            "/commerce/seller/{$seller['uuid']}/products/{$product['uuid']}",
            ['commission_bps' => 999, 'name' => 'Should Not Apply']
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('commission_bps', $this->json($response)['error']['details']);

        $row = $this->connection->table('commerce_products')->where('uuid', '=', $product['uuid'])->first();
        self::assertNull($row['commission_kind']);
        self::assertNotSame(
            'Should Not Apply',
            $row['name'],
            'the whole request must be rejected, not partially applied'
        );
    }

    public function testCatalogServiceUpdateSellerProductBackstopRejectsCommissionFields(): void
    {
        $this->activateWorkspace();
        $seller = $this->seedSeller('backstop-seller', 'ownerUserB001');
        $product = $this->seedProduct($seller['uuid'], 'backstop-product');

        $catalog = $this->buildCatalogService();

        try {
            $catalog->updateSellerProduct(
                $this->context,
                $seller['uuid'],
                $product['uuid'],
                ['commission_kind' => 'percentage', 'commission_bps' => 100]
            );
            self::fail('expected ValidationException from the service-level backstop');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('commission_kind', $e->firstErrors());
        }

        $row = $this->connection->table('commerce_products')->where('uuid', '=', $product['uuid'])->first();
        self::assertNull($row['commission_kind']);
    }

    // -----------------------------------------------------------------
    // Capability check precedes the field check: 403, never 422.
    // -----------------------------------------------------------------

    public function testSellerLackingCatalogWriteCapabilityGets403NotFieldSpecific422ForACommissionField(): void
    {
        $this->activateWorkspace();
        $seller = $this->seedSeller('capability-order', 'ownerUserCO1');
        $this->seedMembership($seller['uuid'], 'analystUserCO', 'seller_analyst');
        $product = $this->seedProduct($seller['uuid'], 'capability-order-p');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'analystUserCO',
            'PATCH',
            "/commerce/seller/{$seller['uuid']}/products/{$product['uuid']}",
            ['commission_kind' => 'percentage', 'commission_bps' => 500]
        ));

        self::assertSame(403, $response->getStatusCode(), 'the capability check must precede the field check');
    }

    public function testSellerLackingCatalogWriteCapabilityGets403ForACommissionFieldOnCreateToo(): void
    {
        $this->activateWorkspace();
        $seller = $this->seedSeller('capability-order-create', 'ownerUserCOC1');
        $this->seedMembership($seller['uuid'], 'analystUserCOC', 'seller_analyst');

        $router = $this->freshRouter();
        $payload = $this->productPayload('capability-order-create-p');
        $payload['commission_kind'] = 'fixed';
        $payload['commission_fixed'] = 10;

        $response = $this->dispatch($router, $this->requestAs(
            'analystUserCOC',
            'POST',
            "/commerce/seller/{$seller['uuid']}/products",
            $payload
        ));

        self::assertSame(403, $response->getStatusCode(), 'the capability check must precede the field check');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function commissionService(?callable $uuidGenerator = null): CommissionPolicyService
    {
        return new CommissionPolicyService(
            new ProductRepository(),
            new SellerRepository(),
            new MarketplaceWorkspaceLock(),
            new CommissionPolicyEventRepository(),
            $uuidGenerator
        );
    }

    /** @param array<string,mixed> $commission */
    private function seedRawProduct(string $uuid, array $commission = []): void
    {
        $this->connection->table('commerce_products')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => $this->tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'type' => 'physical',
            'status' => 'active',
        ], $commission));
    }

    /** @param array<string,mixed> $commission */
    private function seedRawSeller(string $uuid, array $commission = []): void
    {
        $this->connection->table('commerce_sellers')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => $this->tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'status' => 'active',
        ], $commission));
    }

    private function requestAs(string $userUuid, string $method, string $uri, array $body = []): Request
    {
        $content = $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $request = Request::create($uri, $method, [], [], [], [], $content);
        if ($content !== null) {
            $request->headers->set('Content-Type', 'application/json');
        }
        $request->headers->set('X-Test-User', $userUuid);

        return $request;
    }

    /** @return array<string,mixed> */
    private function productPayload(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '', $slug)),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ];
    }
}
