<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\ProductMediaService;
use Glueful\Extensions\Commerce\Http\DTOs\AttachMediaData;
use Glueful\Extensions\Commerce\Http\DTOs\ReorderMediaData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateMediaData;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminMediaController
{
    use ReadsAdminInput;

    public function __construct(
        private ApplicationContext $context,
        private ?ProductMediaService $media = null,
    ) {
        $this->media ??= app($context, ProductMediaService::class);
    }

    #[ApiOperation(summary: 'Attach media to a product', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Media attached')]
    #[ApiResponse(404, description: 'Product not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function attach(AttachMediaData $input, Request $request, string $uuid): Response
    {
        try {
            $media = $this->media->attach($this->context, $uuid, [
                'blob_uuid' => $input->blob_uuid,
                'role' => $input->role,
                'alt' => $input->alt,
                'variant_uuid' => $input->variant_uuid,
            ]);

            return Response::created($media, 'Media attached');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update product media', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateMediaData::class)]
    #[ApiResponse(200, description: 'Media updated')]
    #[ApiResponse(404, description: 'Media not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $uuid): Response
    {
        try {
            $media = $this->media->update($this->context, $uuid, $this->input($request));

            return Response::success($media, 'Media updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Detach media from a product', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Media detached')]
    #[ApiResponse(404, description: 'Media not found')]
    public function detach(Request $request, string $uuid): Response
    {
        $this->media->detach($this->context, $uuid);

        return Response::noContent();
    }

    #[ApiOperation(summary: 'Reorder product media', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Media reordered')]
    #[ApiResponse(404, description: 'Product not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function reorder(ReorderMediaData $input, Request $request, string $uuid): Response
    {
        try {
            $media = $this->media->reorder($this->context, $uuid, $this->validatePositions($input->positions));

            return Response::success($media, 'Media reordered');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    /**
     * Shape-checks each raw `positions` element before it reaches the service: a
     * malformed element (missing/incorrectly-typed key) becomes a 422 rather than a
     * TypeError. Mirrors {@see AdminRefundController::validateLines()}.
     *
     * @return list<array{uuid:string,position:int}>
     */
    private function validatePositions(?array $positions): array
    {
        if ($positions === null || $positions === []) {
            throw ValidationException::forField('positions', 'positions is required.');
        }

        $result = [];
        foreach ($positions as $index => $entry) {
            if (
                !is_array($entry)
                || !isset($entry['uuid'], $entry['position'])
                || !is_string($entry['uuid'])
                || !is_int($entry['position'])
            ) {
                throw ValidationException::forField(
                    "positions.{$index}",
                    'Each entry must include uuid (string) and position (int).'
                );
            }

            $result[] = ['uuid' => $entry['uuid'], 'position' => $entry['position']];
        }

        return $result;
    }
}
