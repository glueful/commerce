<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\AdminDiscountController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminRefundController;
use Glueful\Extensions\Commerce\Http\Admin\AdminStockController;
use Glueful\Extensions\Commerce\Http\Storefront\CartController;
use Glueful\Extensions\Commerce\Http\Storefront\CheckoutController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Router;
use Glueful\Validation\Contracts\RequestData;
use ReflectionMethod;

/**
 * Hardened OpenAPI enforcement (Layer 6 Task 7, design spec §2 decision 7):
 * rather than a hand-maintained 9-controller list, this walks EVERY route
 * `routes.php` actually registers -- a fresh `Router` is constructed against
 * this test's own `ApplicationContext` (bound into the container so
 * `Router::getContext()`/the `config()` helper `routes.php` calls both
 * resolve), `routes.php` is `require`d with that local `$router` in scope
 * (mirroring how `ServiceProvider::loadRoutesFrom()` exposes `$router` to the
 * included file), and `Router::getAllRoutes()` is walked to collect every
 * `[class, method]` action whose class lives under the commerce HTTP
 * namespace (never a framework/application route the extension doesn't own).
 * Every such action must carry `#[ApiOperation]` plus at least one
 * `#[ApiResponse]`; an unsupported handler shape (a closure, an invokable
 * object, anything that isn't a `[class-string, method]` pair) fails
 * EXPLICITLY rather than being silently skipped. This test therefore proves
 * coverage for every Layer 6 route (retrofit lists/filters, shows, tag
 * rename, product/discount delete, bulk endpoints, storefront catalog/review
 * additions) with no hand-list to fall out of sync. `Router`'s constructor
 * only ever loads a route cache when one already exists on disk for this
 * exact context+signature; this test never calls `RouteCache::save()`, so
 * `wasLoadedFromCache()` staying false also proves the manifest was built
 * fresh rather than depending on any app route cache.
 */
final class HttpDocumentationTest extends CommerceTestCase
{
    private const COMMERCE_HTTP_NAMESPACE_PREFIX = 'Glueful\\Extensions\\Commerce\\Http\\';

    public function testEveryRegisteredCommerceRouteActionDeclaresOpenApiOperationAndResponse(): void
    {
        $this->assertEveryCommerceRouteActionIsDocumented($this->freshRouter());
    }

    /**
     * Marketplace MV1 (plan Task 5), extended by MV2 (plan Task 10): with
     * `commerce.marketplace.enabled` off (the default the test above
     * walks), `routes.php` never registers the `/commerce/admin/marketplace/*`
     * or `/commerce/seller/*` groups at all -- so the walk above cannot
     * enforce `#[ApiOperation]`/`#[ApiResponse]` on any of their actions;
     * there is nothing to find. This second pass flips the master switch ON
     * before building a fresh `Router`, so every marketplace/seller route
     * action -- MV1's AND MV2's, since every new seller-order/
     * admin-seller-order route lives in the SAME `if ($marketplaceEnabled)`
     * gate (`routes.php`) -- gets the SAME documentation-gate enforcement as
     * every pre-existing route. `commerceRouteActions()`'s walk is fully
     * generic (every `[class, method]` handler under the commerce HTTP
     * namespace, no hand-maintained list), so it automatically picks up new
     * MV2 actions with zero changes; the explicit path assertions below only
     * pin that the NEW routes are genuinely present in this pass (never
     * silently absent), not that they're individually documented.
     *
     * MV3 (plan Task 11, gated here by Task 12): the commission-policy /
     * payout / adjustment / operator-financial / seller-financial routes all
     * live in the SAME `if ($marketplaceEnabled)` groups (`routes.php`), so
     * they too fall out of the fully generic walk with zero changes -- the
     * additional `assertContains` pins below only prove they are genuinely
     * present in this pass.
     */
    public function testEveryCommerceRouteActionIsDocumentedWithMarketplaceEnabled(): void
    {
        $this->context->overrideConfig('commerce.marketplace.enabled', true);
        $router = $this->freshRouter();

        $actions = $this->commerceRouteActions($router);
        $paths = array_map(static fn (array $route): string => (string) $route['path'], $router->getAllRoutes());

        self::assertContains('/commerce/admin/marketplace/sellers', $paths);
        self::assertNotSame(
            [],
            array_filter($paths, static fn (string $path): bool => str_starts_with($path, '/commerce/seller')),
            'Expected at least one seller-scoped route once the master switch is on.'
        );

        // MV2 Task 10: the new seller-order/admin-seller-order routes
        // specifically (§6.1/§6.2) -- proves they're genuinely walked by
        // this pass, not merely covered "in principle" by the generic scan.
        self::assertContains('/commerce/admin/orders/{uuid}/seller-orders/{sellerOrderUuid}/fulfill', $paths);
        self::assertContains('/commerce/seller/{sellerUuid}/orders', $paths);
        self::assertContains('/commerce/seller/{sellerUuid}/orders/{sellerOrderUuid}', $paths);
        self::assertContains('/commerce/seller/{sellerUuid}/orders/{sellerOrderUuid}/fulfill', $paths);

        // MV3 Task 11/12: the settlement-ledger surfaces -- manual payouts,
        // operator adjustments, operator financial summary/seller balance/
        // seller report, and the seller's own financial surfaces (report,
        // balance, payouts, effective commission policy).
        self::assertContains('/commerce/admin/marketplace/payouts', $paths);
        self::assertContains('/commerce/admin/marketplace/adjustments', $paths);
        self::assertContains('/commerce/admin/marketplace/financials/summary', $paths);
        self::assertContains('/commerce/admin/marketplace/sellers/{uuid}/balance', $paths);
        self::assertContains('/commerce/admin/marketplace/sellers/{uuid}/report', $paths);
        self::assertContains('/commerce/seller/{sellerUuid}/financials/report', $paths);
        self::assertContains('/commerce/seller/{sellerUuid}/financials/balance', $paths);
        self::assertContains('/commerce/seller/{sellerUuid}/payouts', $paths);
        self::assertContains('/commerce/seller/{sellerUuid}/commission-policy', $paths);

        $this->assertEveryCommerceRouteActionIsDocumented($router, $actions);
    }

    /**
     * @param list<array{0: class-string, 1: string}>|null $actions pass a
     *     pre-collected manifest to avoid walking the router twice
     */
    private function assertEveryCommerceRouteActionIsDocumented(Router $router, ?array $actions = null): void
    {
        $actions ??= $this->commerceRouteActions($router);

        self::assertNotSame(
            [],
            $actions,
            'Expected at least one commerce route action to be registered by routes.php.'
        );

        foreach ($actions as [$class, $methodName]) {
            $method = new ReflectionMethod($class, $methodName);

            self::assertNotSame(
                [],
                $method->getAttributes(ApiOperation::class),
                "{$class}::{$methodName} is missing #[ApiOperation]."
            );
            self::assertNotSame(
                [],
                $method->getAttributes(ApiResponse::class),
                "{$class}::{$methodName} is missing at least one #[ApiResponse]."
            );
        }
    }

    public function testTenancyEnabledRoutesResolveTenantBeforeCommerceHandlers(): void
    {
        $this->context->overrideConfig('commerce.tenancy.enabled', true);
        $router = $this->freshRouter();

        foreach ($router->getAllRoutes() as $route) {
            if (!str_starts_with((string) $route['path'], '/commerce')) {
                continue;
            }

            self::assertContains('tenant', $route['middleware'], (string) $route['path']);
            if (str_starts_with((string) $route['path'], '/commerce/admin')) {
                self::assertLessThan(
                    array_search('tenant', $route['middleware'], true),
                    array_search('auth', $route['middleware'], true),
                    'Authentication must run before tenant membership resolution.'
                );
            }
        }
    }

    public function testSingleStoreRoutesDoNotRequireTenancyExtensionMiddleware(): void
    {
        $router = $this->freshRouter();

        foreach ($router->getAllRoutes() as $route) {
            self::assertNotContains('tenant', $route['middleware']);
        }
    }

    /**
     * Builds a fresh `Router` bound to THIS test's `ApplicationContext` (never
     * the minimal/anonymous container `CommerceTestCase` otherwise wires) and
     * registers every commerce route by `require`-ing the real `routes.php`
     * against it -- the exact manifest-construction approach the plan pins.
     */
    private function freshRouter(): Router
    {
        $this->bind(ApplicationContext::class, $this->context);
        $router = new Router($this->contextContainer());

        self::assertFalse(
            $router->wasLoadedFromCache(),
            'The route manifest must be built fresh, never loaded from an app route cache.'
        );

        require __DIR__ . '/../../../routes.php';

        return $router;
    }

    /**
     * @return list<array{0: class-string, 1: string}>
     */
    private function commerceRouteActions(Router $router): array
    {
        $actions = [];

        foreach ($router->getAllRoutes() as $route) {
            $handler = $route['handler'];

            if (
                is_array($handler)
                && count($handler) === 2
                && array_is_list($handler)
                && is_string($handler[0])
                && is_string($handler[1])
                && class_exists($handler[0])
            ) {
                if (!str_starts_with($handler[0], self::COMMERCE_HTTP_NAMESPACE_PREFIX)) {
                    // Not one of this extension's own controllers -- a fresh
                    // Router built solely from this extension's routes.php
                    // should never actually reach this branch, but the filter
                    // stays explicit per the design spec rather than assuming.
                    continue;
                }

                /** @var class-string $class */
                $class = $handler[0];
                $actions[] = [$class, $handler[1]];
                continue;
            }

            self::fail(sprintf(
                'Unsupported commerce route handler shape for %s %s: expected [class-string, method], got %s.',
                (string) $route['method'],
                (string) $route['path'],
                is_object($handler) ? get_class($handler) : get_debug_type($handler)
            ));
        }

        return $actions;
    }

    public function testJsonWriteMethodsHaveDtoBackedRequestBodies(): void
    {
        $methods = [
            [CartController::class, 'addLine'],
            [CartController::class, 'updateLine'],
            [CartController::class, 'applyDiscount'],
            [CheckoutController::class, 'quote'],
            [CheckoutController::class, 'place'],
            [AdminProductController::class, 'store'],
            [AdminProductController::class, 'update'],
            [AdminProductController::class, 'storeVariant'],
            [AdminProductController::class, 'updateVariant'],
            [AdminStockController::class, 'adjust'],
            [AdminDiscountController::class, 'store'],
            [AdminDiscountController::class, 'update'],
            [AdminOrderController::class, 'fulfill'],
            [AdminOrderController::class, 'addNote'],
            [AdminRefundController::class, 'store'],
        ];

        foreach ($methods as [$controller, $name]) {
            $method = new ReflectionMethod($controller, $name);
            self::assertTrue(
                $this->hasRequestDataParameter($method) || $this->hasApiRequestBodySchema($method),
                $controller . '::' . $name . ' must have a runtime DTO parameter or DTO request-body schema.'
            );
        }
    }

    public function testDocumentedOnlyBodiesUseRequestDataSchemas(): void
    {
        $methods = [
            [AdminProductController::class, 'update'],
            [AdminProductController::class, 'updateVariant'],
            [AdminDiscountController::class, 'update'],
        ];

        foreach ($methods as [$controller, $name]) {
            $method = new ReflectionMethod($controller, $name);
            $schema = $this->apiRequestBodySchema($method);
            self::assertNotNull($schema, $controller . '::' . $name . ' must declare one DTO request body.');
            self::assertTrue(
                is_a($schema, RequestData::class, true),
                $controller . '::' . $name . ' body schema must implement RequestData.'
            );
        }
    }

    public function testSafeJsonWriteMethodsUseRuntimeRequestDataParameters(): void
    {
        $methods = [
            [CartController::class, 'addLine'],
            [CartController::class, 'updateLine'],
            [CartController::class, 'applyDiscount'],
            [CheckoutController::class, 'quote'],
            [CheckoutController::class, 'place'],
            [AdminProductController::class, 'store'],
            [AdminProductController::class, 'storeVariant'],
            [AdminStockController::class, 'adjust'],
            [AdminDiscountController::class, 'store'],
            [AdminOrderController::class, 'fulfill'],
            [AdminOrderController::class, 'addNote'],
            [AdminRefundController::class, 'store'],
        ];

        foreach ($methods as [$controller, $name]) {
            $method = new ReflectionMethod($controller, $name);
            self::assertTrue(
                $this->hasRequestDataParameter($method),
                $controller . '::' . $name . ' must accept a RequestData parameter for runtime hydration.'
            );
        }
    }

    public function testPartialUpdateMethodsStayDocumentedOnly(): void
    {
        $methods = [
            [AdminProductController::class, 'update'],
            [AdminProductController::class, 'updateVariant'],
            [AdminDiscountController::class, 'update'],
        ];

        foreach ($methods as [$controller, $name]) {
            $method = new ReflectionMethod($controller, $name);
            self::assertFalse(
                $this->hasRequestDataParameter($method),
                $controller . '::' . $name . ' must stay manual so omitted fields are distinguishable.'
            );
        }
    }

    public function testQueryReadingMethodsUseRuntimeRequestDataParameters(): void
    {
        $methods = [
            [ProductController::class, 'index'],
            [AdminOrderController::class, 'index'],
            [OrderController::class, 'mine'],
        ];

        foreach ($methods as [$controller, $name]) {
            $method = new ReflectionMethod($controller, $name);
            self::assertTrue(
                $this->hasRequestDataParameter($method),
                $controller . '::' . $name . ' must accept a RequestData query DTO.'
            );
        }
    }

    private function hasRequestDataParameter(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && is_a($type->getName(), RequestData::class, true)) {
                return true;
            }
        }

        return false;
    }

    private function hasApiRequestBodySchema(ReflectionMethod $method): bool
    {
        return $this->apiRequestBodySchema($method) !== null;
    }

    /** @return class-string|null */
    private function apiRequestBodySchema(ReflectionMethod $method): ?string
    {
        $attrs = $method->getAttributes(\Glueful\Routing\Attributes\ApiRequestBody::class);
        if (count($attrs) !== 1) {
            return null;
        }

        return $attrs[0]->newInstance()->schema;
    }
}
