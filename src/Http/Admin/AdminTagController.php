<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\TagService;
use Glueful\Extensions\Commerce\Http\DTOs\CreateTagData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductTagsData;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminTagController
{
    public function __construct(
        private ApplicationContext $context,
        private ?TagService $tags = null,
    ) {
        $this->tags ??= app($context, TagService::class);
    }

    #[ApiOperation(summary: 'List tags', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Tags retrieved')]
    public function index(Request $request): Response
    {
        return Response::success($this->tags->list($this->context), 'Tags retrieved');
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

    #[ApiOperation(summary: 'Set the tags attached to a product', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Product tags updated')]
    #[ApiResponse(404, description: 'Product not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function setForProduct(SetProductTagsData $input, Request $request, string $uuid): Response
    {
        try {
            $tags = $this->tags->setProductTags($this->context, $uuid, $input->tag_uuids ?? []);

            return Response::success($tags, 'Product tags updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }
}
