<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Customers\AddressBookRepository;
use Glueful\Extensions\Commerce\Http\DTOs\CheckoutPlaceData;
use Glueful\Extensions\Commerce\Http\DTOs\CheckoutQuoteData;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Marketplace\CheckoutConflictException;
use Glueful\Extensions\Commerce\Orders\InsufficientStockException;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
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
        // Deliberately NOT resolved eagerly below (unlike carts/checkout above):
        // existing tests construct this controller with exactly three positional
        // args against lightweight DI containers that never bind
        // AddressBookRepository, and an eager `??= app(...)` here would throw even
        // though a plain inline-address checkout never touches it. See the lazy
        // accessor instead (same pattern as OrderController's download fields).
        private ?AddressBookRepository $addressBooks = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->carts ??= app($context, CartService::class);
        $this->checkout ??= app($context, CheckoutService::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    private function addressBooks(): AddressBookRepository
    {
        return $this->addressBooks ??= app($this->context, AddressBookRepository::class);
    }

    #[ApiOperation(summary: 'Quote checkout totals', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Checkout quoted')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function quote(CheckoutQuoteData $input, Request $request): Response
    {
        try {
            $cart = $this->cart($request);

            return Response::success($this->checkout->quote(
                $this->context,
                $cart,
                $input->shipping_address,
                $input->shipping_method
            ), 'Checkout quoted');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Place an order from the current cart', tags: ['Commerce Storefront'])]
    #[ApiResponse(201, description: 'Order placed')]
    #[ApiResponse(409, description: 'Insufficient stock')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function place(CheckoutPlaceData $input, Request $request): Response
    {
        try {
            $placed = $this->checkout->placeOrder(
                $this->context,
                $this->cartToken($request),
                $this->buyer($request, $input->buyer),
                $this->resolveAddresses($request, $input),
                $input->shipping_method
            );

            // Wire discipline (same as OrderController::show()/mine()): the service's
            // internal return keeps the RAW order (payment initiation and other
            // internal consumers need it) -- only the HTTP response boundary projects.
            $placed['order'] = StorefrontOrderProjection::forStorefront($placed['order']);

            return Response::created($placed, 'Order placed');
        } catch (InsufficientStockException $e) {
            return Response::error('Insufficient stock', 409, [
                'short' => [[
                    'variant_uuid' => $e->variantUuid,
                    'sku' => $e->sku,
                ]],
            ]);
        } catch (CheckoutConflictException $e) {
            return Response::error('Checkout conflict', 409, ['code' => $e->errorCode]);
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

    /** @param array<string,mixed> $buyer @return array{email: string, user_uuid?: string|null} */
    private function buyer(Request $request, array $buyer): array
    {
        return [
            'email' => (string) ($buyer['email'] ?? ''),
            'user_uuid' => $this->actorUuid($request),
        ];
    }

    /**
     * Optional address-book integration (design spec §7): resolves
     * `shipping_address_uuid`/`billing_address_uuid` into the SAME
     * `{shipping, billing}` shape `CheckoutService::placeOrder()` already
     * accepts inline -- the resolved address json is snapshotted in
     * verbatim, exactly like an inline address, so `orders.addresses` never
     * references the address book. Authenticated-only (an unauthenticated
     * request supplying either uuid is a 422, not a silent no-op); a uuid
     * plus inline data for the SAME kind is ambiguous (422); mixing kinds
     * (e.g. shipping uuid + inline billing) is fine.
     *
     * @return array<string,mixed>
     */
    private function resolveAddresses(Request $request, CheckoutPlaceData $input): array
    {
        $addresses = $input->addresses;

        $shipping = $this->resolveAddressKind(
            $request,
            'shipping_address_uuid',
            $input->shipping_address_uuid,
            $addresses['shipping'] ?? null
        );
        if ($shipping !== null) {
            $addresses['shipping'] = $shipping;
        }

        $billing = $this->resolveAddressKind(
            $request,
            'billing_address_uuid',
            $input->billing_address_uuid,
            $addresses['billing'] ?? null
        );
        if ($billing !== null) {
            $addresses['billing'] = $billing;
        }

        return $addresses;
    }

    /**
     * Null return means "nothing to change" -- neither a uuid nor inline data
     * was supplied for this kind, so the caller's original `$addresses` array
     * is left exactly as it was (present-and-empty stays that way, absent
     * stays absent): inline-only checkout is byte-for-byte unchanged.
     *
     * @param mixed $inline
     * @return array<string,mixed>|null
     */
    private function resolveAddressKind(Request $request, string $field, ?string $uuid, mixed $inline): ?array
    {
        $inlineGiven = is_array($inline) && $inline !== [];

        if ($uuid === null) {
            return $inlineGiven ? $inline : null;
        }

        if ($inlineGiven) {
            throw ValidationException::forField(
                $field,
                "Provide either {$field} or an inline address for this kind, not both."
            );
        }

        $userUuid = $this->actorUuid($request);
        if ($userUuid === null) {
            throw ValidationException::forField($field, "{$field} requires an authenticated request.");
        }

        $tenant = $this->tenants->tenantUuid($this->context);
        $address = $this->addressBooks()->findByUuid($this->context, $tenant, $userUuid, $uuid);
        if ($address === null) {
            throw ValidationException::forField($field, "{$field} must reference an address belonging to you.");
        }

        return $address['address'];
    }

    private function actorUuid(Request $request): ?string
    {
        $user = $request->attributes->get('user');
        $userUuid = is_array($user) && isset($user['uuid']) ? (string) $user['uuid'] : '';

        return $userUuid === '' ? null : $userUuid;
    }
}
