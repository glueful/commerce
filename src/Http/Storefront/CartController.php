<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\AddonSnapshot;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Http\DTOs\AddCartLineData;
use Glueful\Extensions\Commerce\Http\DTOs\ApplyDiscountData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateCartLineData;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
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

    #[ApiOperation(summary: 'Create a cart', tags: ['Commerce Storefront'])]
    #[ApiResponse(201, description: 'Cart created')]
    public function create(Request $request): Response
    {
        $created = $this->carts->create($this->context);

        return Response::created([
            'cart' => $this->publicCart($created['cart']),
            'token' => $created['token'],
        ], 'Cart created');
    }

    #[ApiOperation(summary: 'Get the current cart', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Cart retrieved')]
    #[ApiResponse(404, description: 'Cart not found')]
    public function show(Request $request): Response
    {
        return Response::success(
            $this->sanitizedView($this->carts->view($this->context, $this->cart($request))),
            'Cart retrieved'
        );
    }

    #[ApiOperation(summary: 'Add a line to the current cart', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Cart updated')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function addLine(AddCartLineData $input, Request $request): Response
    {
        try {
            $cart = $this->carts->addLine(
                $this->context,
                $this->cart($request),
                $input->variant_uuid,
                $input->quantity,
                $input->addons ?? []
            );

            return Response::success($this->sanitizedView($this->carts->view($this->context, $cart)), 'Cart updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update a cart line quantity', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Cart updated')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function updateLine(UpdateCartLineData $input, Request $request, string $uuid): Response
    {
        try {
            $cart = $this->carts->setLineQuantity(
                $this->context,
                $this->cart($request),
                $uuid,
                $input->quantity
            );

            return Response::success($this->sanitizedView($this->carts->view($this->context, $cart)), 'Cart updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Remove a cart line', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Cart updated')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function removeLine(Request $request, string $uuid): Response
    {
        try {
            $cart = $this->carts->setLineQuantity($this->context, $this->cart($request), $uuid, 0);

            return Response::success($this->sanitizedView($this->carts->view($this->context, $cart)), 'Cart updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Apply a discount code to the current cart', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Discount applied')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function applyDiscount(ApplyDiscountData $input, Request $request): Response
    {
        try {
            $cart = $this->carts->applyDiscount(
                $this->context,
                $this->cart($request),
                $input->code
            );

            return Response::success(
                $this->sanitizedView($this->carts->view($this->context, $cart)),
                'Discount applied'
            );
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Remove the current cart discount', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Discount removed')]
    public function removeDiscount(Request $request): Response
    {
        $cart = $this->carts->removeDiscount($this->context, $this->cart($request));

        return Response::success($this->sanitizedView($this->carts->view($this->context, $cart)), 'Discount removed');
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

    /**
     * Sanitizes every line's `addons` echo to the whitelisted projection (design
     * spec §4) -- `CartService::pricedLines()` returns the FULL internal snapshot
     * (including `addon_uuid`/`choice_key`) because checkout needs it verbatim for
     * persistence; the storefront cart view is not that surface.
     *
     * @param array<string,mixed> $view
     * @return array<string,mixed>
     */
    private function sanitizedView(array $view): array
    {
        $lines = is_array($view['lines'] ?? null) ? $view['lines'] : [];
        $view['lines'] = array_map(static function (array $line): array {
            $line['addons'] = AddonSnapshot::sanitize(is_array($line['addons'] ?? null) ? $line['addons'] : []);

            return $line;
        }, $lines);

        return $view;
    }
}
