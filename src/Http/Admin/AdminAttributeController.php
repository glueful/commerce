<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AttributeService;
use Glueful\Extensions\Commerce\Http\DTOs\CreateAttributeData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateAttributeValueData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductAttributesData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateAttributeData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateAttributeValueData;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminAttributeController
{
    use ReadsAdminInput;

    public function __construct(
        private ApplicationContext $context,
        private ?AttributeService $attributes = null,
    ) {
        $this->attributes ??= app($context, AttributeService::class);
    }

    #[ApiOperation(summary: 'List attributes', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Attributes retrieved')]
    public function index(Request $request): Response
    {
        return Response::success($this->attributes->list($this->context), 'Attributes retrieved');
    }

    #[ApiOperation(summary: 'Create an attribute', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Attribute created')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(CreateAttributeData $input, Request $request): Response
    {
        try {
            $attribute = $this->attributes->create($this->context, [
                'slug' => $input->slug,
                'name' => $input->name,
                'position' => $input->position,
            ]);

            return Response::created($attribute, 'Attribute created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update an attribute', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateAttributeData::class)]
    #[ApiResponse(200, description: 'Attribute updated')]
    #[ApiResponse(404, description: 'Attribute not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $uuid): Response
    {
        try {
            $attribute = $this->attributes->update($this->context, $uuid, $this->input($request));

            return Response::success($attribute, 'Attribute updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Delete an attribute', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Attribute deleted')]
    #[ApiResponse(404, description: 'Attribute not found')]
    public function destroy(Request $request, string $uuid): Response
    {
        $this->attributes->delete($this->context, $uuid);

        return Response::noContent();
    }

    #[ApiOperation(summary: 'Create an attribute value', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Attribute value created')]
    #[ApiResponse(404, description: 'Attribute not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function storeValue(CreateAttributeValueData $input, Request $request, string $uuid): Response
    {
        try {
            $value = $this->attributes->createValue($this->context, $uuid, [
                'slug' => $input->slug,
                'value' => $input->value,
                'position' => $input->position,
            ]);

            return Response::created($value, 'Attribute value created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update an attribute value', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateAttributeValueData::class)]
    #[ApiResponse(200, description: 'Attribute value updated')]
    #[ApiResponse(404, description: 'Attribute value not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function updateValue(Request $request, string $uuid): Response
    {
        try {
            $value = $this->attributes->updateValue($this->context, $uuid, $this->input($request));

            return Response::success($value, 'Attribute value updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Delete an attribute value', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Attribute value deleted')]
    #[ApiResponse(404, description: 'Attribute value not found')]
    public function destroyValue(Request $request, string $uuid): Response
    {
        $this->attributes->deleteValue($this->context, $uuid);

        return Response::noContent();
    }

    #[ApiOperation(summary: 'Set the attributes attached to a product', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Product attributes updated')]
    #[ApiResponse(404, description: 'Product not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function setForProduct(SetProductAttributesData $input, Request $request, string $uuid): Response
    {
        try {
            $attributes = $this->attributes->setProductAttributes(
                $this->context,
                $uuid,
                $input->attributes ?? []
            );

            return Response::success($attributes, 'Product attributes updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }
}
