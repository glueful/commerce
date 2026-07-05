<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class CartController
{
    use ReadsStorefrontInput;

    public function __construct(
        private ApplicationContext $context,
        private ?CartService $carts = null,
    ) {
        $this->carts ??= app($context, CartService::class);
    }

    public function create(Request $request): Response
    {
        $created = $this->carts->create($this->context);

        return Response::created([
            'cart' => $this->publicCart($created['cart']),
            'token' => $created['token'],
        ], 'Cart created');
    }

    public function show(Request $request): Response
    {
        return Response::success($this->carts->view($this->context, $this->cart($request)), 'Cart retrieved');
    }

    public function addLine(Request $request): Response
    {
        try {
            $input = $this->input($request);
            $cart = $this->carts->addLine(
                $this->context,
                $this->cart($request),
                (string) ($input['variant_uuid'] ?? ''),
                (int) ($input['quantity'] ?? 0)
            );

            return Response::success($this->carts->view($this->context, $cart), 'Cart updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    public function updateLine(Request $request, string $uuid): Response
    {
        try {
            $input = $this->input($request);
            $cart = $this->carts->setLineQuantity(
                $this->context,
                $this->cart($request),
                $uuid,
                (int) ($input['quantity'] ?? 0)
            );

            return Response::success($this->carts->view($this->context, $cart), 'Cart updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    public function removeLine(Request $request, string $uuid): Response
    {
        try {
            $cart = $this->carts->setLineQuantity($this->context, $this->cart($request), $uuid, 0);

            return Response::success($this->carts->view($this->context, $cart), 'Cart updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    public function applyDiscount(Request $request): Response
    {
        try {
            $input = $this->input($request);
            $cart = $this->carts->applyDiscount(
                $this->context,
                $this->cart($request),
                (string) ($input['code'] ?? '')
            );

            return Response::success($this->carts->view($this->context, $cart), 'Discount applied');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    public function removeDiscount(Request $request): Response
    {
        $cart = $this->carts->removeDiscount($this->context, $this->cart($request));

        return Response::success($this->carts->view($this->context, $cart), 'Discount removed');
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

    /** @param array<string,mixed> $cart @return array<string,mixed> */
    private function publicCart(array $cart): array
    {
        unset($cart['token_hash']);

        return $cart;
    }
}
