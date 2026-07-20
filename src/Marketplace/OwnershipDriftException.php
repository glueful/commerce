<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Internal signal thrown by {@see \Glueful\Extensions\Commerce\Orders\CheckoutService}'s
 * marketplace claim protocol (design spec §2.7) when the post-claim re-read
 * of a participating product's `seller_uuid` no longer matches the pre-claim
 * snapshot -- a competing adoption/transfer landed between the two reads.
 * Caught ONLY by `CheckoutService::placeOrder()`'s own retry loop: the first
 * occurrence rolls back the whole transaction and retries the complete
 * placement flow exactly once from a fresh snapshot; a second occurrence is
 * translated to {@see CheckoutConflictException} (409 `checkout_conflict`).
 * Never surfaced to a caller directly.
 */
final class OwnershipDriftException extends \RuntimeException
{
}
