<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\DownloadService;
use Glueful\Extensions\Commerce\Http\DTOs\CreateDownloadData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateDownloadData;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminDownloadController
{
    use ReadsAdminInput;

    public function __construct(
        private ApplicationContext $context,
        private ?DownloadService $downloads = null,
    ) {
        $this->downloads ??= app($context, DownloadService::class);
    }

    #[ApiOperation(summary: 'List downloads for a variant', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Downloads retrieved')]
    public function index(Request $request, string $uuid): Response
    {
        return Response::success($this->downloads->list($this->context, $uuid), 'Downloads retrieved');
    }

    #[ApiOperation(summary: 'Attach a download to a digital variant', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Download attached')]
    #[ApiResponse(404, description: 'Variant not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function attach(CreateDownloadData $input, Request $request, string $uuid): Response
    {
        try {
            $download = $this->downloads->attach($this->context, $uuid, [
                'blob_uuid' => $input->blob_uuid,
                'name' => $input->name,
                'download_limit' => $input->download_limit,
                'expiry_days' => $input->expiry_days,
                'position' => $input->position,
            ]);

            return Response::created($download, 'Download attached');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update a download definition', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateDownloadData::class)]
    #[ApiResponse(200, description: 'Download updated')]
    #[ApiResponse(404, description: 'Download not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $uuid): Response
    {
        try {
            $download = $this->downloads->update($this->context, $uuid, $this->input($request));

            return Response::success($download, 'Download updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Detach a download from a variant', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Download detached')]
    #[ApiResponse(404, description: 'Download not found')]
    public function detach(Request $request, string $uuid): Response
    {
        $this->downloads->detach($this->context, $uuid);

        return Response::noContent();
    }
}
