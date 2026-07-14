<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

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
use Glueful\Validation\Contracts\RequestData;
use ReflectionClass;
use ReflectionMethod;

final class HttpDocumentationTest extends CommerceTestCase
{
    /** @return iterable<string, array{0: class-string}> */
    public static function controllers(): iterable
    {
        yield 'storefront products' => [ProductController::class];
        yield 'storefront cart' => [CartController::class];
        yield 'storefront checkout' => [CheckoutController::class];
        yield 'storefront orders' => [OrderController::class];
        yield 'admin products' => [AdminProductController::class];
        yield 'admin stock' => [AdminStockController::class];
        yield 'admin discounts' => [AdminDiscountController::class];
        yield 'admin orders' => [AdminOrderController::class];
        yield 'admin refunds' => [AdminRefundController::class];
    }

    /** @dataProvider controllers */
    public function testRouteMethodsDeclareOpenApiOperationAndResponses(string $controller): void
    {
        foreach ($this->routeMethods($controller) as $method) {
            self::assertNotSame(
                [],
                $method->getAttributes(ApiOperation::class),
                $controller . '::' . $method->getName() . ' is missing #[ApiOperation].'
            );
            self::assertNotSame(
                [],
                $method->getAttributes(ApiResponse::class),
                $controller . '::' . $method->getName() . ' is missing #[ApiResponse].'
            );
        }
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

    /**
     * @param class-string $controller
     * @return list<ReflectionMethod>
     */
    private function routeMethods(string $controller): array
    {
        $reflection = new ReflectionClass($controller);

        return array_values(array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool =>
                $method->getDeclaringClass()->getName() === $controller
                && $method->getName() !== '__construct'
        ));
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
