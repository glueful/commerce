<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Http\DTOs\CheckoutPlaceData;
use Glueful\Extensions\Commerce\Http\DTOs\CheckoutQuoteData;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\InsufficientStockException;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class CheckoutController
{
    use ReadsStorefrontInput;

    public function __construct(
        private ApplicationContext $context,
        private ?CartService $carts = null,
        private ?CheckoutService $checkout = null,
    ) {
        $this->carts ??= app($context, CartService::class);
        $this->checkout ??= app($context, CheckoutService::class);
    }

    #[ApiOperation(summary: 'Quote checkout totals', tags: ['Commerce Storefront'])]
    #[ApiRequestBody(schema: CheckoutQuoteData::class)]
    #[ApiResponse(200, description: 'Checkout quoted')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function quote(Request $request): Response
    {
        try {
            $input = $this->input($request);
            $cart = $this->cart($request);

            return Response::success($this->checkout->quote(
                $this->context,
                $cart,
                is_array($input['shipping_address'] ?? null) ? $input['shipping_address'] : [],
                isset($input['shipping_method']) ? (string) $input['shipping_method'] : null
            ), 'Checkout quoted');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Place an order from the current cart', tags: ['Commerce Storefront'])]
    #[ApiRequestBody(schema: CheckoutPlaceData::class)]
    #[ApiResponse(201, description: 'Order placed')]
    #[ApiResponse(409, description: 'Insufficient stock')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function place(Request $request): Response
    {
        try {
            $input = $this->input($request);
            $placed = $this->checkout->placeOrder(
                $this->context,
                $this->cartToken($request),
                $this->buyer($request, $input),
                is_array($input['addresses'] ?? null) ? $input['addresses'] : [],
                isset($input['shipping_method']) ? (string) $input['shipping_method'] : null
            );

            return Response::created($placed, 'Order placed');
        } catch (InsufficientStockException $e) {
            return Response::error('Insufficient stock', 409, [
                'short' => [[
                    'variant_uuid' => $e->variantUuid,
                    'sku' => $e->sku,
                ]],
            ]);
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    /** @return array<string,mixed> */
    private function cart(Request $request): array
    {
        $cart = $this->carts->byToken($this->context, $this->cartToken($request));
        if ($cart === null) {
            throw new NotFoundException('Resource not found.');
        }

        return $cart;
    }

    /** @param array<string,mixed> $input @return array{email: string, user_uuid?: string|null} */
    private function buyer(Request $request, array $input): array
    {
        $buyer = is_array($input['buyer'] ?? null) ? $input['buyer'] : [];
        $user = $request->attributes->get('user');
        $userUuid = is_array($user) && isset($user['uuid']) ? (string) $user['uuid'] : null;

        return [
            'email' => (string) ($buyer['email'] ?? ''),
            'user_uuid' => $userUuid,
        ];
    }
}
