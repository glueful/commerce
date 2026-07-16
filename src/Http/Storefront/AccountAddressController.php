<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Customers\AddressBookService;
use Glueful\Extensions\Commerce\Http\DTOs\CreateAddressData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateAddressData;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Storefront address book (design spec §2/§7): CRUD over the authenticated
 * user's saved addresses, actor resolved via the SAME `request->attributes
 * ->get('user')` extraction {@see OrderController::mine()} uses -- never
 * `auth.user` (that identity is for admin-audit attribution, see
 * `ResolvesActor`). Every route is `auth`-protected, but the actor check
 * below also fails non-revealing (404, matching `mine()`) when a test
 * constructs this controller directly against a request that never went
 * through the `auth` middleware.
 *
 * Every row is scoped to (tenant, this user) inside {@see AddressBookService}
 * -- an unknown OR another user's address uuid both collapse to the same
 * non-revealing 404 here.
 */
final class AccountAddressController
{
    use ReadsStorefrontInput;

    public function __construct(
        private ApplicationContext $context,
        private ?AddressBookService $addresses = null,
    ) {
        $this->addresses ??= app($context, AddressBookService::class);
    }

    #[ApiOperation(summary: "List the authenticated user's saved addresses", tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Addresses retrieved')]
    public function index(Request $request): Response
    {
        return Response::success(
            $this->addresses->list($this->context, $this->actorUuid($request)),
            'Addresses retrieved'
        );
    }

    #[ApiOperation(summary: 'Create a saved address', tags: ['Commerce Storefront'])]
    #[ApiResponse(201, description: 'Address created')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(CreateAddressData $input, Request $request): Response
    {
        try {
            $address = $this->addresses->create($this->context, $this->actorUuid($request), [
                'label' => $input->label,
                'address' => $input->address,
                'is_default_shipping' => $input->is_default_shipping,
                'is_default_billing' => $input->is_default_billing,
            ]);

            return Response::created($address, 'Address created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update a saved address', tags: ['Commerce Storefront'])]
    #[ApiRequestBody(schema: UpdateAddressData::class)]
    #[ApiResponse(200, description: 'Address updated')]
    #[ApiResponse(404, description: 'Address not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $uuid): Response
    {
        try {
            $address = $this->addresses->update(
                $this->context,
                $this->actorUuid($request),
                $uuid,
                $this->input($request)
            );

            return Response::success($address, 'Address updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Delete a saved address', tags: ['Commerce Storefront'])]
    #[ApiResponse(204, description: 'Address deleted')]
    #[ApiResponse(404, description: 'Address not found')]
    public function destroy(Request $request, string $uuid): Response
    {
        $this->addresses->delete($this->context, $this->actorUuid($request), $uuid);

        return Response::noContent();
    }

    private function actorUuid(Request $request): string
    {
        $user = $request->attributes->get('user');
        $userUuid = is_array($user) && isset($user['uuid']) ? (string) $user['uuid'] : '';
        if ($userUuid === '') {
            throw new NotFoundException('Resource not found.');
        }

        return $userUuid;
    }
}
