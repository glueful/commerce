<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CategoryService;
use Glueful\Extensions\Commerce\Catalog\ConcurrentCatalogMutationException;
use Glueful\Extensions\Commerce\Http\DTOs\CreateCategoryData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductCategoriesData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateCategoryData;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminCategoryController
{
    use ReadsAdminInput;

    public function __construct(
        private ApplicationContext $context,
        private ?CategoryService $categories = null,
    ) {
        $this->categories ??= app($context, CategoryService::class);
    }

    #[ApiOperation(summary: 'List categories', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Categories retrieved')]
    public function index(Request $request): Response
    {
        return Response::success($this->categories->list($this->context), 'Categories retrieved');
    }

    #[ApiOperation(summary: 'Create a category', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Category created')]
    #[ApiResponse(409, description: 'Category ancestry changed concurrently; retry')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(CreateCategoryData $input, Request $request): Response
    {
        try {
            $category = $this->categories->create($this->context, [
                'slug' => $input->slug,
                'name' => $input->name,
                'description' => $input->description,
                'parent_uuid' => $input->parent_uuid,
                'position' => $input->position,
                'blob_uuid' => $input->blob_uuid,
            ]);

            return Response::created($category, 'Category created');
        } catch (ConcurrentCatalogMutationException $e) {
            return Response::error($e->getMessage(), 409);
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update a category', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateCategoryData::class)]
    #[ApiResponse(200, description: 'Category updated')]
    #[ApiResponse(404, description: 'Category not found')]
    #[ApiResponse(409, description: 'Category ancestry changed concurrently; retry')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $uuid): Response
    {
        try {
            $category = $this->categories->update($this->context, $uuid, $this->input($request));

            return Response::success($category, 'Category updated');
        } catch (ConcurrentCatalogMutationException $e) {
            return Response::error($e->getMessage(), 409);
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Delete a category', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Category deleted')]
    #[ApiResponse(404, description: 'Category not found')]
    public function destroy(Request $request, string $uuid): Response
    {
        $this->categories->delete($this->context, $uuid);

        return Response::noContent();
    }

    #[ApiOperation(summary: 'Set the categories attached to a product', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Product categories updated')]
    #[ApiResponse(404, description: 'Product not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function setForProduct(SetProductCategoriesData $input, Request $request, string $uuid): Response
    {
        try {
            $categories = $this->categories->setProductCategories($this->context, $uuid, $input->category_uuids ?? []);

            return Response::success($categories, 'Product categories updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }
}
