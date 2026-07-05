<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Extensions\Commerce\Http\Admin\AdminDiscountController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminStockController;
use Glueful\Extensions\Commerce\Http\Storefront\CartController;
use Glueful\Extensions\Commerce\Http\Storefront\CheckoutController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
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

    public function testJsonWriteMethodsDeclareDtoBackedRequestBodies(): void
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
        ];

        foreach ($methods as [$controller, $name]) {
            $method = new ReflectionMethod($controller, $name);
            $attrs = $method->getAttributes(ApiRequestBody::class);
            self::assertCount(1, $attrs, $controller . '::' . $name . ' must declare one DTO request body.');

            $body = $attrs[0]->newInstance();
            self::assertNotNull($body->schema, $controller . '::' . $name . ' must use a schema class.');
            self::assertTrue(
                is_a($body->schema, RequestData::class, true),
                $controller . '::' . $name . ' body schema must implement RequestData.'
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
}
