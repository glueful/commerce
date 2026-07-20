<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Review moderation (design spec §5): admin/importer create (always lands
 * `pending`, never touches rollups), the `pending -> approved | spam` and
 * `approved -> spam` transitions, and the guarded delete.
 *
 * Every transition is an affected-row-checked status claim
 * ({@see ReviewRepository::claimTransition()}) followed, in the SAME
 * transaction, by a `commerce_products.rating_sum`/`rating_count` rollup mutation
 * for the SAME rating value the review carries: approve adds it, an
 * approved->spam reversal subtracts it. `spam()` doesn't know in advance whether
 * the review is currently `pending` or `approved` -- it tries the
 * no-rollup-effect `pending -> spam` claim first, and only if that affects zero
 * rows does it try `approved -> spam` (which DOES reverse the rollup). Each
 * attempt is its own affected-row-checked UPDATE, so a concurrent writer racing
 * either transition still serializes correctly -- this is not a pre-claim
 * business decision, it's two independently-guarded claim attempts.
 *
 * A claim that affects zero rows is ambiguous by itself (unknown/cross-tenant
 * review, OR a review that exists but isn't in the expected source status), so
 * failure is classified by a POST-failure re-read purely to pick an HTTP status:
 * unknown/cross-tenant -> NotFoundException (404, non-revealing); known but
 * wrong status -> ReviewStateException (409, states the actual status). This
 * re-read never feeds a business decision -- it only selects which exception to
 * throw once the claim has already failed.
 *
 * A missing product on rollup (the review's own `product_uuid` no longer
 * resolves in this tenant) is an integrity violation, not a client error: it
 * throws, which rolls back the WHOLE transaction -- including the status claim
 * that already "succeeded" moments earlier -- leaving the review in its prior
 * status.
 */
final class ReviewService
{
    private const CREATE_STATUS = 'pending';

    public function __construct(
        private ReviewRepository $reviews,
        private ProductRepository $products,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /**
     * Admin/importer create: rating 1-5, body, author_name/email, optional
     * user_uuid. Status always starts `pending` -- creation never touches
     * rollups (design spec §5).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(ApplicationContext $c, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        $productUuid = $this->requiredString($input, 'product_uuid');
        $rating = $this->requiredRating($input['rating'] ?? null);
        $body = $this->requiredString($input, 'body');
        $authorName = $this->requiredString($input, 'author_name');
        $authorEmail = $this->requiredEmail($input['author_email'] ?? null);
        $userUuid = $this->normalizeNullableString($input['user_uuid'] ?? null);

        if ($this->products->findLiveByUuid($c, $tenant, $productUuid) === null) {
            throw ValidationException::forField('product_uuid', 'product_uuid must reference an existing product.');
        }

        $uuid = Utils::generateNanoID();
        $this->reviews->insert($c, [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'user_uuid' => $userUuid,
            'author_name' => $authorName,
            'author_email' => $authorEmail,
            'rating' => $rating,
            'body' => $body,
            'status' => self::CREATE_STATUS,
        ]);

        return $this->mustFind($c, $tenant, $uuid);
    }

    /**
     * @param array<string,mixed> $filters 'status' and/or 'product'
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function list(ApplicationContext $c, array $filters, int $page, int $perPage): array
    {
        return $this->reviews->paginatedFor($c, $this->tenants->tenantUuid($c), $filters, $page, $perPage);
    }

    /**
     * Storefront public submit (design spec Layer 6 §2 decision 6): unlike
     * {@see self::create()} above (admin/importer, unchanged), this path is
     * genuinely public and carries its OWN live+active product guard -- a
     * draft or tombstoned product is the SAME non-revealing 404 as an unknown
     * slug, resolved via {@see self::resolveLiveActiveProduct()} BEFORE any
     * row is written. Always lands `pending` (same `self::CREATE_STATUS` as
     * `create()`, never touches rollups). `user_uuid` is ALWAYS stored null:
     * the framework has no optional-auth seam for this genuinely public
     * route, so a caller can never attribute a review to an account it
     * doesn't control. `$input` structurally carries no `user_uuid`/
     * `product_uuid` key -- `StoreReviewData` never declares either property
     * -- so this method doesn't need to (and must not) read either from it.
     *
     * @param array{rating:mixed,body:mixed,author_name:mixed,author_email:mixed} $input
     */
    public function createForStorefront(ApplicationContext $c, string $slug, array $input): void
    {
        $tenant = $this->tenants->tenantUuid($c);
        $product = $this->resolveLiveActiveProduct($c, $tenant, $slug);

        $rating = $this->requiredRating($input['rating'] ?? null);
        $body = $this->requiredString($input, 'body');
        $authorName = $this->requiredString($input, 'author_name');
        $authorEmail = $this->requiredEmail($input['author_email'] ?? null);

        $this->reviews->insert($c, [
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $tenant,
            'product_uuid' => (string) $product['uuid'],
            'user_uuid' => null,
            'author_name' => $authorName,
            'author_email' => $authorEmail,
            'rating' => $rating,
            'body' => $body,
            'status' => self::CREATE_STATUS,
        ]);
    }

    /**
     * Storefront approved-review list (design spec Layer 6 §2 decision 6):
     * the SAME live+active guard {@see self::createForStorefront()} uses,
     * then delegates to the SAME `paginatedFor()` primitive {@see self::list()}
     * uses -- `status` is hardcoded to `approved` here (never client-
     * controlled), so pending/spam rows are invisible with no separate
     * "hidden row" count anywhere in this path.
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function listForStorefront(ApplicationContext $c, string $slug, int $page, int $perPage): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $product = $this->resolveLiveActiveProduct($c, $tenant, $slug);

        return $this->reviews->paginatedFor(
            $c,
            $tenant,
            ['status' => 'approved', 'product' => (string) $product['uuid']],
            $page,
            $perPage
        );
    }

    /**
     * The storefront buyer-availability product guard shared by
     * {@see self::createForStorefront()} and {@see self::listForStorefront()}
     * (design spec Layer 6 §2 decision 6; §2.3/MV5b): draft, tombstoned, AND
     * (as of MV5b) a suspended/onboarding/closed seller's product all
     * collapse to the SAME non-revealing 404 as an unknown slug --
     * `findBuyerAvailableBySlug()` already excludes tombstones and a
     * non-active seller's rows, so only the explicit `status === 'active'`
     * check is needed to also exclude drafts (and any other non-active
     * status the product vocabulary may carry). This is the genuinely
     * public submit/read path; {@see self::create()} above (admin/importer)
     * deliberately keeps using `findLiveByUuid()`.
     *
     * @return array<string,mixed>
     */
    private function resolveLiveActiveProduct(ApplicationContext $c, string $tenant, string $slug): array
    {
        $product = $this->products->findBuyerAvailableBySlug($c, $tenant, $slug);
        if ($product === null || ($product['status'] ?? '') !== 'active') {
            throw new NotFoundException('Resource not found.');
        }

        return $product;
    }

    /** @return array<string,mixed> */
    public function show(ApplicationContext $c, string $uuid): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $review = $this->reviews->findByUuid($c, $tenant, $uuid);
        if ($review === null) {
            throw new NotFoundException('Resource not found.');
        }

        return $review;
    }

    /**
     * `pending -> approved`: claim, then add the review's rating to the
     * product's rollup in the same transaction.
     *
     * @return array<string,mixed>
     */
    public function approve(ApplicationContext $c, string $uuid): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        return db($c)->transaction(function () use ($c, $tenant, $uuid): array {
            if (!$this->reviews->claimTransition($c, $tenant, $uuid, 'pending', 'approved')) {
                $this->throwTransitionFailure($c, $tenant, $uuid, ['pending']);
            }

            $review = $this->mustFind($c, $tenant, $uuid);
            $this->applyRollup($c, $tenant, $review, 1);

            return $review;
        });
    }

    /**
     * `pending -> spam` (no rollup effect -- the review never contributed) or
     * `approved -> spam` (reverses the rollup). Tries the no-op-rollup claim
     * first; only tries the reversing claim if the first affects zero rows.
     *
     * @return array<string,mixed>
     */
    public function spam(ApplicationContext $c, string $uuid): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        return db($c)->transaction(function () use ($c, $tenant, $uuid): array {
            if ($this->reviews->claimTransition($c, $tenant, $uuid, 'pending', 'spam')) {
                return $this->mustFind($c, $tenant, $uuid);
            }

            if ($this->reviews->claimTransition($c, $tenant, $uuid, 'approved', 'spam')) {
                $review = $this->mustFind($c, $tenant, $uuid);
                $this->applyRollup($c, $tenant, $review, -1);

                return $review;
            }

            $this->throwTransitionFailure($c, $tenant, $uuid, ['pending', 'approved']);
        });
    }

    /**
     * ONE guarded delete (design spec §5) -- no read-then-delete. `approved`
     * reviews are rejected by the same non-revealing 404 as unknown/cross-tenant
     * ones: they must be spammed first so the rollup they contributed is
     * reversed before the row can disappear.
     */
    public function delete(ApplicationContext $c, string $uuid): void
    {
        $tenant = $this->tenants->tenantUuid($c);

        if (!$this->reviews->guardedDelete($c, $tenant, $uuid)) {
            throw new NotFoundException('Resource not found.');
        }
    }

    /**
     * `POST /commerce/admin/reviews/bulk` (design spec Layer 6 §2/Task 2):
     * per-item atomicity, input order preserved, reusing the SAME
     * `claimTransition`/`guardedDelete`-backed primitives ({@see self::approve()},
     * {@see self::spam()}, {@see self::delete()}) a single-resource moderation
     * call reaches -- a bulk write can never race a single write with different
     * serialization discipline. Each item gets its OWN transaction (one
     * expected item failure never rolls back neighbors); an unexpected
     * exception (anything other than {@see NotFoundException}/
     * {@see ReviewStateException}) is NOT caught here and aborts the whole
     * request with 500 rather than being mislabeled as an item failure.
     *
     * `delete` classifies its failure the same way {@see self::throwTransitionFailure()}
     * does for approve/spam -- a post-failure re-read purely to pick a reason,
     * never a business decision -- so an `approved` review (ineligible for
     * delete) reports `invalid_transition` instead of collapsing into
     * `not_found` the way the single-resource {@see self::delete()} endpoint
     * deliberately does for its own non-revealing-404 posture.
     *
     * @param list<string> $uuids
     * @return array{applied: list<string>, failed: list<array{uuid: string, reason: string}>}
     */
    public function bulk(ApplicationContext $c, string $action, array $uuids): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $applied = [];
        $failed = [];

        foreach ($uuids as $uuid) {
            $reason = match ($action) {
                'approve' => $this->bulkAttempt(fn (): array => $this->approve($c, $uuid)),
                'spam' => $this->bulkAttempt(fn (): array => $this->spam($c, $uuid)),
                'delete' => $this->bulkDeleteReason($c, $tenant, $uuid),
                default => throw ValidationException::forField(
                    'action',
                    'action must be one of: approve, spam, delete.'
                ),
            };

            if ($reason === null) {
                $applied[] = $uuid;
            } else {
                $failed[] = ['uuid' => $uuid, 'reason' => $reason];
            }
        }

        return ['applied' => $applied, 'failed' => $failed];
    }

    /** @param callable(): array<string,mixed> $attempt */
    private function bulkAttempt(callable $attempt): ?string
    {
        try {
            $attempt();

            return null;
        } catch (NotFoundException) {
            return 'not_found';
        } catch (ReviewStateException) {
            return 'invalid_transition';
        }
    }

    /**
     * `delete` outcome classifier for {@see self::bulk()}: attempts the SAME
     * guarded `guardedDelete()` primitive {@see self::delete()} uses, then --
     * only on failure, and only to pick a reason -- re-reads to distinguish an
     * unknown/cross-tenant review from one that exists but isn't currently
     * `pending`/`spam` (i.e. `approved`).
     */
    private function bulkDeleteReason(ApplicationContext $c, string $tenant, string $uuid): ?string
    {
        if ($this->reviews->guardedDelete($c, $tenant, $uuid)) {
            return null;
        }

        return $this->reviews->findByUuid($c, $tenant, $uuid) === null ? 'not_found' : 'invalid_transition';
    }

    /** @param array<string,mixed> $review */
    private function applyRollup(ApplicationContext $c, string $tenant, array $review, int $sign): void
    {
        $rating = (int) $review['rating'];
        $productUuid = (string) $review['product_uuid'];

        if (!$this->products->adjustRating($c, $tenant, $productUuid, $sign * $rating, $sign)) {
            // Should never happen in practice (products are soft-deleted, and the
            // rollup primitive deliberately reaches soft-deleted rows) -- an
            // integrity violation, not a client error. Throwing here rolls back
            // the whole transaction, including the status claim made moments ago.
            throw new \RuntimeException('Review rollup failed: referenced product not found.');
        }
    }

    /**
     * Classifies an already-failed transition claim purely to pick an HTTP
     * status -- never a business decision, the claim has already failed by the
     * time this runs.
     *
     * @param list<string> $expectedFrom
     */
    private function throwTransitionFailure(
        ApplicationContext $c,
        string $tenant,
        string $uuid,
        array $expectedFrom
    ): never {
        $review = $this->reviews->findByUuid($c, $tenant, $uuid);
        if ($review === null) {
            throw new NotFoundException('Resource not found.');
        }

        throw new ReviewStateException(
            "Review status is '{$review['status']}'; expected " . implode(' or ', $expectedFrom) . '.'
        );
    }

    /** @return array<string,mixed> */
    private function mustFind(ApplicationContext $c, string $tenant, string $uuid): array
    {
        $review = $this->reviews->findByUuid($c, $tenant, $uuid);
        if ($review === null) {
            throw new \RuntimeException('Review could not be reloaded.');
        }

        return $review;
    }

    private function requiredRating(mixed $raw): int
    {
        if (!is_int($raw)) {
            throw ValidationException::forField('rating', 'rating must be an integer.');
        }
        if ($raw < 1 || $raw > 5) {
            throw ValidationException::forField('rating', 'rating must be between 1 and 5.');
        }

        return $raw;
    }

    private function requiredEmail(mixed $raw): string
    {
        $email = trim((string) $raw);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::forField('author_email', 'author_email must be a valid email address.');
        }

        return $email;
    }

    /** @param array<string,mixed> $input */
    private function requiredString(array $input, string $field): string
    {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value === '') {
            throw ValidationException::forField($field, ucfirst(str_replace('_', ' ', $field)) . ' is required.');
        }

        return $value;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
