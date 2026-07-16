<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

/**
 * Thrown by ReviewService when an approve/spam status-transition claim affects
 * zero rows because the review EXISTS in this tenant but is not currently in the
 * expected source status -- e.g. approving an already-approved or already-spam
 * review. Mapped to a 409 by AdminReviewController, mirroring
 * ConcurrentCatalogMutationException's retryable-conflict mapping. An unknown or
 * cross-tenant claim failure is a NotFoundException (404) instead -- see
 * ReviewService::throwTransitionFailure() for the classification.
 */
final class ReviewStateException extends \DomainException
{
}
