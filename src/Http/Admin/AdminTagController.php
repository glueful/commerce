<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\StaleCatalogRevisionException;
use Glueful\Extensions\Commerce\Catalog\TagService;
use Glueful\Extensions\Commerce\Http\DTOs\CreateTagData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductTagsData;
use Glueful\Extensions\Commerce\Http\DTOs\TagListQuery;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminTagController
{
    use ReadsAdminInput;

    public function __construct(
        private ApplicationContext $context,
        private ?TagService $tags = null,
    ) {
        $this->tags ??= app($context, TagService::class);
    }

    #[ApiOperation(summary: 'List tags', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Tags retrieved')]
    public function index(TagListQuery $query, Request $request): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $result = $this->tags->list(
            $this->context,
            array_filter(['q' => $query->q], static fn (mixed $value): bool => $value !== null),
            $page,
            $perPage
        );

        return Response::paginated($result['items'], $result['total'], $page, $perPage, null, 'Tags retrieved');
    }

    #[ApiOperation(summary: 'Get a tag', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Tag retrieved')]
    #[ApiResponse(404, description: 'Tag not found')]
    public function show(Request $request, string $uuid): Response
    {
        return Response::success($this->tags->show($this->context, $uuid), 'Tag retrieved');
    }

    #[ApiOperation(summary: 'Rename a tag', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Tag updated')]
    #[ApiResponse(404, description: 'Tag not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $uuid): Response
    {
        try {
            $tag = $this->tags->rename($this->context, $uuid, $this->input($request));

            return Response::success($tag, 'Tag updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Create a tag', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Tag created')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(CreateTagData $input, Request $request): Response
    {
        try {
            $tag = $this->tags->create($this->context, [
                'slug' => $input->slug,
                'name' => $input->name,
            ]);

            return Response::created($tag, 'Tag created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Delete a tag', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Tag deleted')]
    #[ApiResponse(404, description: 'Tag not found')]
    public function destroy(Request $request, string $uuid): Response
    {
        $this->tags->delete($this->context, $uuid);

        return Response::noContent();
    }

    #[ApiOperation(summary: 'List the tags attached to a product', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Product tags retrieved')]
    #[ApiResponse(404, description: 'Product not found')]
    public function forProductIndex(Request $request, string $uuid): Response
    {
        return Response::success($this->tags->forProduct($this->context, $uuid), 'Product tags retrieved');
    }

    #[ApiOperation(summary: 'Set the tags attached to a product', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Product tags updated')]
    #[ApiResponse(404, description: 'Product not found')]
    #[ApiResponse(409, description: 'Product was modified by another request')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function setForProduct(SetProductTagsData $input, Request $request, string $uuid): Response
    {
        try {
            $tags = $this->tags->setProductTags(
                $this->context,
                $uuid,
                $input->tag_uuids ?? [],
                $input->expected_revision
            );

            return Response::success($tags, 'Product tags updated');
        } catch (StaleCatalogRevisionException $e) {
            return Response::error($e->getMessage(), 409);
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }
}
