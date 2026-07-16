<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\ReviewService;
use Glueful\Extensions\Commerce\Catalog\ReviewStateException;
use Glueful\Extensions\Commerce\Http\DTOs\BulkReviewData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateReviewData;
use Glueful\Extensions\Commerce\Http\DTOs\ReviewListQuery;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminReviewController
{
    public function __construct(
        private ApplicationContext $context,
        private ?ReviewService $reviews = null,
    ) {
        $this->reviews ??= app($context, ReviewService::class);
    }

    #[ApiOperation(summary: 'List reviews', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Reviews retrieved')]
    public function index(ReviewListQuery $query, Request $request): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $result = $this->reviews->list(
            $this->context,
            array_filter(
                ['status' => $query->status, 'product' => $query->product],
                static fn (mixed $value): bool => $value !== null
            ),
            $page,
            $perPage
        );

        return Response::paginated($result['items'], $result['total'], $page, $perPage, null, 'Reviews retrieved');
    }

    #[ApiOperation(summary: 'Get a review', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Review retrieved')]
    #[ApiResponse(404, description: 'Review not found')]
    public function show(Request $request, string $uuid): Response
    {
        return Response::success($this->reviews->show($this->context, $uuid), 'Review retrieved');
    }

    #[ApiOperation(summary: 'Create a review', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Review created')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(CreateReviewData $input, Request $request): Response
    {
        try {
            $review = $this->reviews->create($this->context, [
                'product_uuid' => $input->product_uuid,
                'rating' => $input->rating,
                'body' => $input->body,
                'author_name' => $input->author_name,
                'author_email' => $input->author_email,
                'user_uuid' => $input->user_uuid,
            ]);

            return Response::created($review, 'Review created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Approve a review', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Review approved')]
    #[ApiResponse(404, description: 'Review not found')]
    #[ApiResponse(409, description: 'Review is not pending')]
    public function approve(Request $request, string $uuid): Response
    {
        try {
            return Response::success($this->reviews->approve($this->context, $uuid), 'Review approved');
        } catch (ReviewStateException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(summary: 'Mark a review as spam', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Review marked as spam')]
    #[ApiResponse(404, description: 'Review not found')]
    #[ApiResponse(409, description: 'Review is already spam')]
    public function spam(Request $request, string $uuid): Response
    {
        try {
            return Response::success($this->reviews->spam($this->context, $uuid), 'Review marked as spam');
        } catch (ReviewStateException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(summary: 'Bulk moderate reviews', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Bulk review moderation processed')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function bulk(BulkReviewData $input, Request $request): Response
    {
        $result = $this->reviews->bulk($this->context, $input->action, $input->uuids);

        return Response::success($result, 'Bulk review moderation processed');
    }

    #[ApiOperation(summary: 'Delete a review', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Review deleted')]
    #[ApiResponse(404, description: 'Review not found or not eligible for deletion')]
    public function destroy(Request $request, string $uuid): Response
    {
        $this->reviews->delete($this->context, $uuid);

        return Response::noContent();
    }
}
