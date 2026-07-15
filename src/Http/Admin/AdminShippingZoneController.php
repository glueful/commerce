<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\CreateMethodData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateZoneData;
use Glueful\Extensions\Commerce\Http\DTOs\SetZoneLocationsData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateMethodData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateZoneData;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneService;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminShippingZoneController
{
    use ReadsAdminInput;

    public function __construct(
        private ApplicationContext $context,
        private ?ShippingZoneService $zones = null,
    ) {
        $this->zones ??= app($context, ShippingZoneService::class);
    }

    #[ApiOperation(summary: 'List shipping zones', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Shipping zones retrieved')]
    public function index(Request $request): Response
    {
        return Response::success($this->zones->list($this->context), 'Shipping zones retrieved');
    }

    #[ApiOperation(summary: 'Create a shipping zone', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Shipping zone created')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(CreateZoneData $input, Request $request): Response
    {
        try {
            $zone = $this->zones->create($this->context, [
                'name' => $input->name,
                'position' => $input->position,
            ]);

            return Response::created($zone, 'Shipping zone created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update a shipping zone', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateZoneData::class)]
    #[ApiResponse(200, description: 'Shipping zone updated')]
    #[ApiResponse(404, description: 'Shipping zone not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $uuid): Response
    {
        try {
            $zone = $this->zones->update($this->context, $uuid, $this->input($request));

            return Response::success($zone, 'Shipping zone updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Delete a shipping zone', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Shipping zone deleted')]
    #[ApiResponse(404, description: 'Shipping zone not found')]
    public function destroy(Request $request, string $uuid): Response
    {
        $this->zones->delete($this->context, $uuid);

        return Response::noContent();
    }

    #[ApiOperation(summary: 'Replace a shipping zone\'s locations', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Shipping zone locations updated')]
    #[ApiResponse(404, description: 'Shipping zone not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function setLocations(SetZoneLocationsData $input, Request $request, string $uuid): Response
    {
        try {
            $locations = $this->zones->setLocations($this->context, $uuid, $input->locations ?? []);

            return Response::success($locations, 'Shipping zone locations updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'List a shipping zone\'s methods', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Shipping methods retrieved')]
    #[ApiResponse(404, description: 'Shipping zone not found')]
    public function indexMethods(Request $request, string $uuid): Response
    {
        return Response::success($this->zones->listMethods($this->context, $uuid), 'Shipping methods retrieved');
    }

    #[ApiOperation(summary: 'Create a shipping method', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Shipping method created')]
    #[ApiResponse(404, description: 'Shipping zone not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function storeMethod(CreateMethodData $input, Request $request, string $uuid): Response
    {
        try {
            $method = $this->zones->createMethod($this->context, $uuid, [
                'kind' => $input->kind,
                'label' => $input->label,
                'config' => $input->config,
                'position' => $input->position,
                'enabled' => $input->enabled,
            ]);

            return Response::created($method, 'Shipping method created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update a shipping method', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateMethodData::class)]
    #[ApiResponse(200, description: 'Shipping method updated')]
    #[ApiResponse(404, description: 'Shipping method not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function updateMethod(Request $request, string $uuid): Response
    {
        try {
            $method = $this->zones->updateMethod($this->context, $uuid, $this->input($request));

            return Response::success($method, 'Shipping method updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Delete a shipping method', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Shipping method deleted')]
    #[ApiResponse(404, description: 'Shipping method not found')]
    public function destroyMethod(Request $request, string $uuid): Response
    {
        $this->zones->deleteMethod($this->context, $uuid);

        return Response::noContent();
    }
}
