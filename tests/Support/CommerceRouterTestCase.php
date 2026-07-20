<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Middleware\InteractiveSessionMiddleware;
use Glueful\Extensions\Commerce\Http\Middleware\SellerMemberMiddleware;
use Glueful\Extensions\Commerce\Http\Seller\SellerApiKeyController;
use Glueful\Extensions\Commerce\Http\Seller\SellerCatalogController;
use Glueful\Extensions\Commerce\Http\Seller\SellerFinancialController;
use Glueful\Extensions\Commerce\Http\Seller\SellerInventoryController;
use Glueful\Extensions\Commerce\Http\Seller\SellerMembershipController;
use Glueful\Extensions\Commerce\Http\Seller\SellerOrderController;
use Glueful\Extensions\Commerce\Http\Seller\SellerWebhookController;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyAuthorizer;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyScopeValidator;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookSecretService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Reports\SellerFinancialReportRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Client;
use Glueful\Http\Exceptions\Handler as ExceptionHandler;
use Glueful\Http\Response;
use Glueful\Http\Security\SafeOutboundTargetResolver;
use Glueful\Routing\RouteMiddleware;
use Glueful\Routing\Router;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
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

    /**
     * Fixed hostname -> IP DNS convention {@see self::buildSellerWebhookController()}
     * uses for BOTH registration-time and delivery-time SSRF resolution --
     * any hostname not listed here resolves to a single genuinely public,
     * non-reserved address. A test may mutate this map BEFORE calling
     * {@see self::freshRouter()} to simulate a DNS rebind between
     * registration and a later `enable()` re-validation, mirroring
     * {@see \Glueful\Extensions\Commerce\Tests\Integration\Marketplace\SellerWebhookEndpointTest::service()}'s
     * identical `$dnsMap` convention.
     *
     * @var array<string,list<string>>
     */
    protected array $webhookDnsMap = [];

    protected function freshRouter(): Router
    {
        $this->bind(ApplicationContext::class, $this->context);
        $this->bind('commerce_seller', $this->buildSellerMiddleware());
        $this->bind(SellerCatalogController::class, $this->buildCatalogController());
        $this->bind(SellerInventoryController::class, $this->buildInventoryController());
        $this->bind(SellerMembershipController::class, $this->buildMembershipController());
        $this->bind(SellerOrderController::class, $this->buildSellerOrderController());
        $this->bind(SellerFinancialController::class, $this->buildSellerFinancialController());
        $this->bind(SellerApiKeyController::class, $this->buildSellerApiKeyController());
        $this->bind(SellerWebhookController::class, $this->buildSellerWebhookController());
        $this->bind('interactive_session', new InteractiveSessionMiddleware());

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
        return new SellerService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            new SellerLifecycleEventRepository()
        );
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
            $this->fixedTenant(),
            new PayoutAccountRepository()
        );
    }

    /**
     * Seller self-service API-key management surface (design spec §2.8,
     * MV5c-1 Task 6): a REAL {@see SellerApiKeyController} wired against a
     * REAL {@see SellerApiKeyService}/{@see SellerApiKeyRepository}/
     * {@see SellerApiKeyScopeValidator} stack, sharing the SAME
     * {@see FixedSellerRoleAuthority} instance {@see self::buildSellerMiddleware()}
     * uses -- mirrors every other `build*Controller()` helper above.
     */
    protected function buildSellerApiKeyController(): SellerApiKeyController
    {
        $roles = new FixedSellerRoleAuthority();
        $apiKeys = new SellerApiKeyRepository();

        return new SellerApiKeyController(
            $this->context,
            new SellerApiKeyService(
                new SellerRepository(),
                new SellerMembershipRepository(),
                $apiKeys,
                $roles,
                new SellerApiKeyScopeValidator($roles)
            ),
            $apiKeys,
            $this->fixedTenant()
        );
    }

    /**
     * Seller self-service webhook management surface (design spec §2.10,
     * MV5c-2 Task 7): a REAL {@see SellerWebhookController} wired against a
     * REAL {@see SellerWebhookEndpointService}/{@see SellerWebhookDeliveryService}
     * stack sharing the SAME {@see SellerWebhookEndpointRepository}/
     * {@see SellerWebhookDeliveryRepository} instances -- mirrors every
     * other `build*Controller()` helper above. The delivery service's HTTP
     * client is a {@see MockHttpClient} that is never actually expected to
     * be called by this surface's own tests (`replay()` only inserts a new
     * `pending` row; it never performs delivery I/O itself) -- a 500
     * placeholder response guards against a silent behavior change if that
     * ever stops being true.
     */
    protected function buildSellerWebhookController(): SellerWebhookController
    {
        $endpoints = new SellerWebhookEndpointRepository();
        $deliveries = new SellerWebhookDeliveryRepository();
        $secrets = new SellerWebhookSecretService($endpoints, $this->webhookEncryptionService());

        $map = $this->webhookDnsMap;
        $resolver = new SafeOutboundTargetResolver(static fn (string $host): array => $map[$host] ?? ['1.1.1.1']);

        $endpointService = new SellerWebhookEndpointService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            $endpoints,
            $deliveries,
            new FixedSellerRoleAuthority(),
            $secrets,
            $resolver
        );

        $httpClient = new Client(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 500])),
            new NullLogger(),
            $this->context,
            $resolver
        );
        $deliveryService = new SellerWebhookDeliveryService(
            new SellerRepository(),
            $endpoints,
            $deliveries,
            new SellerWebhookEventRepository(),
            $secrets,
            $httpClient
        );

        return new SellerWebhookController(
            $this->context,
            $endpointService,
            $endpoints,
            $deliveries,
            $deliveryService,
            $this->fixedTenant()
        );
    }

    /**
     * Mirrors {@see \Glueful\Extensions\Commerce\Tests\Integration\Marketplace\SellerWebhookEndpointTest::encryptionService()}'s
     * identical process-lifetime-cached fixed-key convention.
     */
    protected function webhookEncryptionService(): EncryptionService
    {
        static $encryption = null;
        if ($encryption === null) {
            $this->context->overrideConfig('encryption.key', 'base64:' . base64_encode(str_repeat('k', 32)));
            $encryption = new EncryptionService($this->context);
        }

        return $encryption;
    }

    protected function buildSellerMiddleware(): SellerMemberMiddleware
    {
        return new SellerMemberMiddleware(
            $this->context,
            new SellerRepository(),
            new SellerMembershipRepository(),
            new FixedSellerRoleAuthority(),
            new MarketplaceMode(),
            $this->fixedTenant(),
            new SellerApiKeyAuthorizer(new SellerApiKeyRepository())
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
