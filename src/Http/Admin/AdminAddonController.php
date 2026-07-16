<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonService;
use Glueful\Extensions\Commerce\Http\DTOs\CreateAddonData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateAddonData;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminAddonController
{
    use ReadsAdminInput;

    public function __construct(
        private ApplicationContext $context,
        private ?AddonService $addons = null,
    ) {
        $this->addons ??= app($context, AddonService::class);
    }

    #[ApiOperation(summary: 'List add-on definitions for a product', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Add-ons retrieved')]
    public function index(Request $request, string $uuid): Response
    {
        return Response::success($this->addons->list($this->context, $uuid), 'Add-ons retrieved');
    }

    #[ApiOperation(summary: 'Create an add-on definition for a product', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Add-on created')]
    #[ApiResponse(404, description: 'Product not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(CreateAddonData $input, Request $request, string $uuid): Response
    {
        try {
            $addon = $this->addons->create($this->context, $uuid, [
                'name' => $input->name,
                'field_type' => $input->field_type,
                'required' => $input->required,
                'choices' => $input->choices,
                'price_delta' => $input->price_delta,
                'position' => $input->position,
                'status' => $input->status,
            ]);

            return Response::created($addon, 'Add-on created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update an add-on definition', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateAddonData::class)]
    #[ApiResponse(200, description: 'Add-on updated')]
    #[ApiResponse(404, description: 'Add-on not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $uuid): Response
    {
        try {
            $addon = $this->addons->update($this->context, $uuid, $this->input($request));

            return Response::success($addon, 'Add-on updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Delete an add-on definition', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Add-on deleted')]
    #[ApiResponse(404, description: 'Add-on not found')]
    public function destroy(Request $request, string $uuid): Response
    {
        $this->addons->delete($this->context, $uuid);

        return Response::noContent();
    }
}
