<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Middleware\SellerMemberMiddleware;
use Glueful\Extensions\Commerce\Http\Seller\SellerCatalogController;
use Glueful\Extensions\Commerce\Http\Seller\SellerFinancialController;
use Glueful\Extensions\Commerce\Http\Seller\SellerInventoryController;
use Glueful\Extensions\Commerce\Http\Seller\SellerMembershipController;
use Glueful\Extensions\Commerce\Http\Seller\SellerOrderController;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Reports\SellerFinancialReportRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Handler as ExceptionHandler;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared harness for exercising the REAL seller-scoped routes through a REAL
 * {@see Router} + middleware pipeline (MV1 Task 4).
 *
 * {@see self::freshRouter()} mirrors `HttpDocumentationTest::freshRouter()`:
 * a fresh Router bound to THIS test's own {@see ApplicationContext},
 * `routes.php` `require`d directly against it (never through
 * `ServiceProvider::loadRoutesFrom()`'s process-global once-only latch, so
 * every test method gets its own independent route manifest reflecting
 * whatever config it set beforehand). `auth`/`tenant` are FAKED via
 * container bindings -- they belong to the host framework/tenancy
 * extension, outside commerce's scope, and {@see CommerceTestCase}'s
 * lightweight container has no real implementation of either -- while
 * `commerce_seller` is the REAL {@see SellerMemberMiddleware}, wired against
 * real repositories/the real {@see FixedSellerRoleAuthority}, and every
 * seller controller is the REAL controller wired against a REAL
 * {@see CatalogService}/{@see InventoryService}/{@see SellerMembershipService}
 * stack -- only the tenant resolver is fixed to a single test-chosen tenant
 * string (mirroring {@see \Glueful\Extensions\Commerce\Tests\Integration\Marketplace\CatalogOwnershipTest}'s
 * `fixedTenant()` convention), since resolving a REAL per-request tenant is
 * the host tenancy extension's own concern, not something this middleware or
 * these controllers ever do themselves (design spec §2.5).
 *
 * {@see self::dispatch()} catches a thrown domain exception (NotFoundException,
 * ValidationException, ...) through the framework's own real
 * {@see ExceptionHandler} -- the SAME conversion a production HTTP Kernel
 * performs before a response ever reaches middleware/the client -- so tests
 * can assert ordinary response status codes exactly as a real client would
 * observe them, even though `Router::dispatch()` itself has no such
 * try/catch (that responsibility lives one layer up, at the Kernel, in a
 * real boot).
 */
abstract class CommerceRouterTestCase extends CommerceTestCase
{
    protected string $tenant = 'sellerRteT01';

    protected function freshRouter(): Router
    {
        $this->bind(ApplicationContext::class, $this->context);
        $this->bind('commerce_seller', $this->buildSellerMiddleware());
        $this->bind(SellerCatalogController::class, $this->buildCatalogController());
        $this->bind(SellerInventoryController::class, $this->buildInventoryController());
        $this->bind(SellerMembershipController::class, $this->buildMembershipController());
        $this->bind(SellerOrderController::class, $this->buildSellerOrderController());
        $this->bind(SellerFinancialController::class, $this->buildSellerFinancialController());

        $router = new Router($this->contextContainer());

        require __DIR__ . '/../../routes.php';

        return $router;
    }

    protected function dispatch(Router $router, Request $request): Response
    {
        try {
            $response = $router->dispatch($request);
        } catch (\Throwable $e) {
            $response = (new ExceptionHandler())->handle($e, $request);
        }

        return $response instanceof Response ? $response : new Response((string) $response);
    }

    /**
     * A request carrying `X-Test-User: <uuid>` authenticates as that
     * principal (sets the post-auth `user` request attribute -- the SAME
     * array shape the real `AuthMiddleware` sets, `['uuid' => ...]`); a
     * request without the header is a 401, mirroring "authentication
     * required" -- fail-closed exactly like the real middleware for a
     * missing credential.
     */
    protected function bindFakeAuth(): void
    {
        $this->bind('auth', new class implements RouteMiddleware {
            public function handle(Request $request, callable $next, mixed ...$params): mixed
            {
                $userUuid = $request->headers->get('X-Test-User');
                if ($userUuid === null || $userUuid === '') {
                    return Response::unauthorized('Authentication required');
                }

                $request->attributes->set('user', ['uuid' => $userUuid]);

                return $next($request);
            }
        });
    }

    /**
     * A request carrying `X-Test-Tenant-Denied` is REJECTED before
     * `commerce_seller` ever runs -- simulating the host tenancy extension
     * denying a caller with no active WORKSPACE membership (a seller
     * membership row is not enough). This proves ordering only (`auth` ->
     * `tenant` -> `commerce_seller`), never real tenancy logic -- that suite
     * belongs to T2/tenancy itself.
     */
    protected function bindFakeTenant(): void
    {
        $this->bind('tenant', new class implements RouteMiddleware {
            public function handle(Request $request, callable $next, mixed ...$params): mixed
            {
                if ($request->headers->get('X-Test-Tenant-Denied') !== null) {
                    return Response::forbidden('Tenant membership required');
                }

                return $next($request);
            }
        });
    }

    protected function enableMarketplace(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
    }

    protected function enableTenancy(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tenancy' => ['enabled' => true]]);
    }

    protected function activateWorkspace(): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => substr('actv' . md5($this->tenant), 0, 12),
            'tenant_uuid' => $this->tenant,
            'status' => 'active',
        ]);
    }

    /** @return array<string,mixed> */
    protected function seedSeller(string $slug, string $ownerUserUuid): array
    {
        return $this->sellerService()->create(
            $this->context,
            $this->tenant,
            $slug,
            ucfirst(str_replace('-', ' ', $slug)),
            null,
            $ownerUserUuid
        );
    }

    protected function seedMembership(string $sellerUuid, string $userUuid, string $role): void
    {
        $this->membershipService()->grant($this->context, $this->tenant, $sellerUuid, $userUuid, $role);
    }

    /** @return array<string,mixed> */
    protected function seedProduct(string $sellerUuid, string $slug): array
    {
        return $this->buildCatalogService()->createProduct(
            $this->context,
            [
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
            ],
            $sellerUuid
        );
    }

    protected function sellerService(): SellerService
    {
        return new SellerService(new SellerRepository(), new SellerMembershipRepository());
    }

    protected function membershipService(): SellerMembershipService
    {
        return new SellerMembershipService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            new FixedSellerRoleAuthority()
        );
    }

    protected function buildCatalogService(): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant(),
            new StockRepository(),
            new ProductChildrenRepository(),
            new ShippingClassRepository(),
            new MarketplaceMode(),
            new MarketplaceWorkspaceLock(),
            new SellerRepository()
        );
    }

    protected function buildInventoryService(): InventoryService
    {
        return new InventoryService(
            new StockRepository(),
            $this->fixedTenant(),
            new VariantRepository(),
            new ProductRepository()
        );
    }

    protected function buildCatalogController(): SellerCatalogController
    {
        return new SellerCatalogController($this->context, $this->buildCatalogService());
    }

    protected function buildInventoryController(): SellerInventoryController
    {
        return new SellerInventoryController($this->context, $this->buildInventoryService());
    }

    protected function buildMembershipController(): SellerMembershipController
    {
        return new SellerMembershipController(
            $this->context,
            $this->membershipService(),
            new SellerMembershipRepository(),
            $this->fixedTenant()
        );
    }

    /**
     * Seller order surface (design spec §6.1/§2.12, MV2 Task 8): a REAL
     * {@see SellerOrderController} wired against a REAL
     * {@see SellerOrderService}/{@see SellerOrderFulfillmentService} stack,
     * both sharing the SAME {@see OrderRepository}/{@see SellerOrderRepository}
     * instances -- mirroring every other `build*Controller()` helper above.
     */
    protected function buildSellerOrderController(): SellerOrderController
    {
        $orders = new OrderRepository();
        $sellerOrders = new SellerOrderRepository();

        return new SellerOrderController(
            $this->context,
            new SellerOrderService($sellerOrders, $orders, $this->fixedTenant()),
            new SellerOrderFulfillmentService($orders, $sellerOrders),
            $this->fixedTenant()
        );
    }

    /**
     * Seller financial surfaces (design spec §6.2, MV3 Task 11): a REAL
     * {@see SellerFinancialController} wired against a REAL
     * {@see SellerFinancialReportRepository}/{@see SellerBalanceService}/
     * {@see PayoutRepository}/{@see MarketplaceMode} stack, sharing the SAME
     * {@see LedgerRepository} instance {@see SellerBalanceService} wraps --
     * mirroring every other `build*Controller()` helper above.
     */
    protected function buildSellerFinancialController(): SellerFinancialController
    {
        return new SellerFinancialController(
            $this->context,
            new SellerFinancialReportRepository(),
            new SellerBalanceService(new LedgerRepository()),
            new PayoutRepository(),
            new MarketplaceMode(),
            $this->fixedTenant()
        );
    }

    protected function buildSellerMiddleware(): SellerMemberMiddleware
    {
        return new SellerMemberMiddleware(
            $this->context,
            new SellerRepository(),
            new SellerMembershipRepository(),
            new FixedSellerRoleAuthority(),
            new MarketplaceMode(),
            $this->fixedTenant()
        );
    }

    /** @return array<string,mixed> */
    protected function json(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    protected function fixedTenant(): CurrentTenantResolver
    {
        $tenant = $this->tenant;

        return new class ($tenant) implements CurrentTenantResolver {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
    }
}
