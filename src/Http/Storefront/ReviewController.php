<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\ReviewService;
use Glueful\Extensions\Commerce\Http\DTOs\StorefrontReviewListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\StoreReviewData;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public review submit + approved list (design spec Layer 6 §2 decision 6).
 * Both actions resolve the SAME live+active storefront product guard inside
 * {@see ReviewService} ({@see ReviewService::createForStorefront()} and
 * {@see ReviewService::listForStorefront()}) -- a draft or tombstoned product
 * is the identical non-revealing 404 as an unknown slug on both endpoints,
 * and neither action ever reads `$slug` against anything but a live+active
 * row before touching `commerce_reviews`.
 */
final class ReviewController
{
    public function __construct(
        private ApplicationContext $context,
        private ?ReviewService $reviews = null,
    ) {
        $this->reviews ??= app($context, ReviewService::class);
    }

    /**
     * Always lands `pending` -- never auto-approved (design spec Layer 6 §2
     * decision 6). The 201 body is deliberately EXACTLY `{status: "pending"}`:
     * no uuid, no moderation promises, nothing a caller could use to look the
     * review up or infer anything about the moderation queue.
     */
    #[ApiOperation(summary: 'Submit a product review', tags: ['Commerce Storefront'])]
    #[ApiResponse(201, description: 'Review submitted')]
    #[ApiResponse(404, description: 'Product not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(StoreReviewData $input, Request $request, string $slug): Response
    {
        try {
            $this->reviews->createForStorefront($this->context, $slug, [
                'rating' => $input->rating,
                'body' => $input->body,
                'author_name' => $input->author_name,
                'author_email' => $input->author_email,
            ]);

            return Response::created(['status' => 'pending'], 'Review submitted');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    /**
     * Approved-only, paginated, `created_at DESC, uuid ASC`. Field allowlist
     * EXACTLY rating/body/author_name/created_at -- email/user_uuid/status/
     * uuid never leave this surface. `status` is hardcoded to `approved`
     * inside {@see ReviewService::listForStorefront()} (never client-
     * controlled), so pending/spam rows are invisible and the total count
     * reflects approved rows only -- no separate "hidden row" count leaks
     * anywhere in this payload.
     */
    #[ApiOperation(summary: 'List approved reviews for a product', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Reviews retrieved')]
    #[ApiResponse(404, description: 'Product not found')]
    public function index(StorefrontReviewListQuery $query, Request $request, string $slug): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));

        $result = $this->reviews->listForStorefront($this->context, $slug, $page, $perPage);

        return Response::paginated(
            array_map(static fn (array $row): array => [
                'rating' => (int) $row['rating'],
                'body' => (string) $row['body'],
                'author_name' => (string) $row['author_name'],
                'created_at' => $row['created_at'],
            ], $result['items']),
            $result['total'],
            $page,
            $perPage,
            null,
            'Reviews retrieved'
        );
    }
}
