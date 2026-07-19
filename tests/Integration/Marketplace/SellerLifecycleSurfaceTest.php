<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Http\Admin\MarketplaceAdminController;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyEventRepository;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyService;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceActivationService;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\SellerAttributionService;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase;
use Glueful\Http\Response;
use Glueful\Routing\Middleware\RequireScopeMiddleware;
use Glueful\Routing\RouteMiddleware;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;

/**
 * The operator HTTP surface for seller-lifecycle transitions + history
 * (design spec §2.1/§2.2/§4, MV5b Task 2): {@see MarketplaceAdminController}'s
 * `suspendSeller`/`reactivateSeller`/`closeSeller`/`sellerLifecycle` exercised
 * over REAL routes through a REAL {@see Router} + middleware pipeline,
 * mirroring {@see ReserveChargebackSurfaceTest}'s admin-side harness (a fake
 * `auth` setting `auth.user`/`api_key_scopes`, plus a REAL `require_scope`
 * middleware) -- `reason` required on every mutation (422 if blank), the
 * actor derived from the authenticated admin (never a body field), and the
 * paginated newest-first lifecycle-history read with a non-revealing 404 for
 * an unknown OR cross-tenant seller uuid.
 */
final class SellerLifecycleSurfaceTest extends CommerceRouterTestCase
{
    private SellerService $sellerService;
    private SellerLifecycleEventRepository $lifecycleEvents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMarketplace();
        $this->activateWorkspace();
    }

    // ===================================================================
    // 1. Suspend/reactivate/close: reason required (422 if blank), actor
    //    derived from the authenticated admin.
    // ===================================================================

    public function testSuspendRejectsABlankReasonWith422AndNeverWrites(): void
    {
        $seller = $this->seedSeller('surf-lc-blank-susp', 'ownerLCBS0001');
        $router = $this->freshRouter();

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/suspend",
            ['reason' => '']
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('reason', $this->json($response)['error']['details']);

        $reloaded = $this->connection->table('commerce_sellers')->where('uuid', '=', $seller['uuid'])->first();
        self::assertSame('active', $reloaded['status']);
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_lifecycle_events')
                ->where('seller_uuid', '=', $seller['uuid'])->count()
        );
    }

    public function testSuspendOverRealRouteWritesStatusAndAuditRowWithActorFromAuthNeverFromBody(): void
    {
        $seller = $this->seedSeller('surf-lc-suspend', 'ownerLCSUS001');
        $router = $this->freshRouter();

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/suspend",
            ['reason' => 'Chargeback spike.', 'actor' => 'spoofedActor1']
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('suspended', $this->json($response)['data']['status']);

        $event = $this->connection->table('commerce_seller_lifecycle_events')
            ->where('seller_uuid', '=', $seller['uuid'])
            ->first();
        self::assertNotNull($event);
        self::assertSame('active', $event['from_status']);
        self::assertSame('suspended', $event['to_status']);
        self::assertSame('Chargeback spike.', $event['reason']);
        self::assertSame(
            'operatorSURF01',
            $event['actor_uuid'],
            'the actor must come from the authenticated identity, never a spoofable body field'
        );
    }

    public function testReactivateRejectsABlankReasonWith422(): void
    {
        $seller = $this->seedSeller('surf-lc-blank-reac', 'ownerLCBR0001');
        $router = $this->freshRouter();
        $this->sellerService->suspend($this->context, $this->tenant, $seller['uuid'], 'Initial.', 'operatorSURF01');

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/reactivate",
            []
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('reason', $this->json($response)['error']['details']);
    }

    public function testReactivateOverRealRouteWritesStatusAndAuditRow(): void
    {
        $seller = $this->seedSeller('surf-lc-reactivate', 'ownerLCREA001');
        $router = $this->freshRouter();
        $this->sellerService->suspend($this->context, $this->tenant, $seller['uuid'], 'Initial.', 'operatorSURF01');

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/reactivate",
            ['reason' => 'Review cleared.']
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('active', $this->json($response)['data']['status']);
        self::assertSame(
            2,
            $this->connection->table('commerce_seller_lifecycle_events')
                ->where('seller_uuid', '=', $seller['uuid'])->count()
        );
    }

    public function testCloseRejectsABlankReasonWith422(): void
    {
        $seller = $this->seedSeller('surf-lc-blank-close', 'ownerLCBC0001');
        $router = $this->freshRouter();

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/close",
            ['reason' => '   ']
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('reason', $this->json($response)['error']['details']);
    }

    public function testCloseOverRealRouteWritesStatusAndAuditRow(): void
    {
        $seller = $this->seedSeller('surf-lc-close', 'ownerLCCLO001');
        $router = $this->freshRouter();

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/close",
            ['reason' => 'Shutting down.']
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('closed', $this->json($response)['data']['status']);

        $event = $this->connection->table('commerce_seller_lifecycle_events')
            ->where('seller_uuid', '=', $seller['uuid'])
            ->first();
        self::assertNotNull($event);
        self::assertSame('closed', $event['to_status']);
        self::assertSame('Shutting down.', $event['reason']);
    }

    public function testResuspendingAnAlreadySuspendedSellerOverRealRouteIsA200NoOpNeverA409(): void
    {
        $seller = $this->seedSeller('surf-lc-idem-susp', 'ownerLCIDS001');
        $router = $this->freshRouter();
        $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/suspend",
            ['reason' => 'First suspension.']
        ));

        $second = $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/suspend",
            ['reason' => 'Second attempt.']
        ));

        self::assertSame(200, $second->getStatusCode());
        self::assertSame('suspended', $this->json($second)['data']['status']);
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_lifecycle_events')
                ->where('seller_uuid', '=', $seller['uuid'])->count(),
            'a same-state resuspend must never write a second event'
        );
    }

    public function testReactivatingAClosedSellerOverRealRouteIs409(): void
    {
        $seller = $this->seedSeller('surf-lc-closed-reac', 'ownerLCCLR001');
        $router = $this->freshRouter();
        $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/close",
            ['reason' => 'Shutting down.']
        ));

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/reactivate",
            ['reason' => 'Trying to come back.']
        ));

        self::assertSame(409, $response->getStatusCode());
    }

    // ===================================================================
    // 2. Lifecycle-history read: tenant-bound, paginated, newest-first,
    //    non-revealing 404 for unknown/cross-tenant.
    // ===================================================================

    public function testLifecycleHistoryReturnsPaginatedNewestFirst(): void
    {
        $seller = $this->seedSeller('surf-lc-history', 'ownerLCHIS001');
        $router = $this->freshRouter();

        $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/suspend",
            ['reason' => 'First event.']
        ));
        $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/reactivate",
            ['reason' => 'Second event.']
        ));

        $response = $this->dispatch($router, $this->operatorRequest(
            'GET',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/lifecycle"
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(2, $body['total']);
        $items = $body['data'];
        self::assertCount(2, $items);
        self::assertSame('Second event.', $items[0]['reason'], 'newest-first: the reactivate event comes first');
        self::assertSame('First event.', $items[1]['reason']);
    }

    public function testUnknownSellerUuidAndCrossTenantSellerUuidLifecycleReadAreIdentical404(): void
    {
        $router = $this->freshRouter();

        $ownerTenant = $this->tenant;
        $otherTenant = 'tenantLCOTHER1';
        $foreignSellerUuid = 'sellerLCFRGN1';
        $this->sellers()->insert($this->context, [
            'uuid' => $foreignSellerUuid,
            'tenant_uuid' => $otherTenant,
            'slug' => 'foreign-lc-seller',
            'name' => 'Foreign LC Seller',
        ]);
        self::assertSame($ownerTenant, $this->tenant, 'sanity: the resolved tenant is unchanged');

        $unknown = $this->dispatch($router, $this->operatorRequest(
            'GET',
            '/commerce/admin/marketplace/sellers/doesNotExist1/lifecycle'
        ));
        $crossTenant = $this->dispatch($router, $this->operatorRequest(
            'GET',
            "/commerce/admin/marketplace/sellers/{$foreignSellerUuid}/lifecycle"
        ));

        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame(404, $crossTenant->getStatusCode());
        self::assertSame($this->json($unknown), $this->json($crossTenant));
    }

    // -----------------------------------------------------------------
    // Fixtures + helpers.
    // -----------------------------------------------------------------

    private function sellers(): SellerRepository
    {
        return new SellerRepository();
    }

    private function operatorRequest(string $method, string $uri, array $body = []): Request
    {
        $content = $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $request = Request::create($uri, $method, [], [], [], [], $content);
        if ($content !== null) {
            $request->headers->set('Content-Type', 'application/json');
        }
        $request->headers->set('X-Test-User', 'operatorSURF01');
        $request->headers->set('X-Test-Scopes', 'commerce:read,commerce:write');

        return $request;
    }

    /**
     * Builds a fresh {@see Router} bound to a REAL {@see MarketplaceAdminController}
     * sharing THIS test's own {@see SellerService}/{@see SellerLifecycleEventRepository}
     * stack, mirroring {@see ReserveChargebackSurfaceTest::freshRouter()}'s exact
     * admin-side harness (a fake `auth` setting both `auth.user`/`api_key_scopes`, plus
     * a REAL `require_scope` middleware).
     */
    protected function freshRouter(): Router
    {
        $this->bind(ApplicationContext::class, $this->context);
        $this->bind('auth', $this->buildScopedAuthMiddleware());
        $this->bind('require_scope', new RequireScopeMiddleware());

        $sellers = new SellerRepository();
        $memberships = new SellerMembershipRepository();
        $this->lifecycleEvents = new SellerLifecycleEventRepository();
        $this->sellerService = new SellerService($sellers, $memberships, $this->lifecycleEvents);
        $membershipService = new SellerMembershipService($sellers, $memberships, new FixedSellerRoleAuthority());
        $workspaceLock = new MarketplaceWorkspaceLock();
        $products = new ProductRepository();
        $commissionPolicy = new CommissionPolicyService(
            $products,
            $sellers,
            $workspaceLock,
            new CommissionPolicyEventRepository()
        );

        $this->bind(MarketplaceAdminController::class, new MarketplaceAdminController(
            $this->context,
            $this->sellerService,
            $membershipService,
            $this->fixedTenant(),
            new MarketplaceActivationService($workspaceLock, $sellers, $products),
            new SellerAttributionService($workspaceLock, $sellers, $products),
            $commissionPolicy,
            new MarketplaceMode(),
            $this->lifecycleEvents
        ));

        $router = new Router($this->contextContainer());
        require __DIR__ . '/../../../routes.php';

        return $router;
    }

    private function buildScopedAuthMiddleware(): RouteMiddleware
    {
        return new class implements RouteMiddleware {
            public function handle(Request $request, callable $next, mixed ...$params): mixed
            {
                $userUuid = $request->headers->get('X-Test-User');
                if ($userUuid === null || $userUuid === '') {
                    return Response::unauthorized('Authentication required');
                }

                $request->attributes->set('user', ['uuid' => $userUuid]);
                $request->attributes->set('auth.user', new UserIdentity($userUuid));

                $scopesHeader = $request->headers->get('X-Test-Scopes');
                if ($scopesHeader !== null && trim($scopesHeader) !== '') {
                    $request->attributes->set(
                        'api_key_scopes',
                        array_map('trim', explode(',', $scopesHeader))
                    );
                }

                return $next($request);
            }
        };
    }
}
