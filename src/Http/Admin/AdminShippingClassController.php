<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\CreateShippingClassData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateShippingClassData;
use Glueful\Extensions\Commerce\Shipping\ShippingClassInUseException;
use Glueful\Extensions\Commerce\Shipping\ShippingClassService;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminShippingClassController
{
    use ReadsAdminInput;

    public function __construct(
        private ApplicationContext $context,
        private ?ShippingClassService $classes = null,
    ) {
        $this->classes ??= app($context, ShippingClassService::class);
    }

    #[ApiOperation(summary: 'List shipping classes', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Shipping classes retrieved')]
    public function index(Request $request): Response
    {
        return Response::success($this->classes->list($this->context), 'Shipping classes retrieved');
    }

    #[ApiOperation(summary: 'Create a shipping class', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Shipping class created')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(CreateShippingClassData $input, Request $request): Response
    {
        try {
            $class = $this->classes->create($this->context, [
                'slug' => $input->slug,
                'name' => $input->name,
            ]);

            return Response::created($class, 'Shipping class created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update a shipping class', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateShippingClassData::class)]
    #[ApiResponse(200, description: 'Shipping class updated')]
    #[ApiResponse(404, description: 'Shipping class not found')]
    #[ApiResponse(422, description: 'Validation failed (e.g. attempting to change slug)')]
    public function update(Request $request, string $uuid): Response
    {
        try {
            $class = $this->classes->update($this->context, $uuid, $this->input($request));

            return Response::success($class, 'Shipping class updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Delete a shipping class', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Shipping class deleted')]
    #[ApiResponse(404, description: 'Shipping class not found')]
    #[ApiResponse(409, description: 'Shipping class is still referenced by a variant')]
    public function destroy(Request $request, string $uuid): Response
    {
        try {
            $this->classes->delete($this->context, $uuid);

            return Response::noContent();
        } catch (ShippingClassInUseException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }
}
