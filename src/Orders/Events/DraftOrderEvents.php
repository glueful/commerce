<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders\Events;

/**
 * The draft order's audit vocabulary (admin-order-creation cycle 2, Task 8).
 *
 * These are `commerce_order_events.type` values -- ROWS, not dispatched
 * lifecycle events. That distinction is the whole point: a draft's creation,
 * cancellation, and TTL expiry are operator-facing bookkeeping, not commerce
 * facts. Dispatching `OrderCanceled` (or any sibling in
 * {@see \Glueful\Extensions\Commerce\Events}) for a draft would wake the
 * transactional-mail listener, the seller-webhook outbox capture, and the
 * marketplace cancellation fan-out for an order the customer never placed and
 * no seller ever saw. So the draft path records here and dispatches NOTHING.
 *
 * Writers:
 *  - {@see self::CREATED}  -- the admin draft-create endpoint (Task 9).
 *  - {@see self::CANCELED} -- the explicit admin draft-cancel endpoint (Task 9),
 *    through {@see \Glueful\Extensions\Commerce\Orders\DraftCleanupService::cancelDraft()}.
 *  - {@see self::EXPIRED}  -- the TTL sweep,
 *    {@see \Glueful\Extensions\Commerce\Orders\DraftCleanupService::cancelStale()}.
 *
 * All three are recorded with the default `internal` visibility: a draft has no
 * customer-facing surface at all, so `customer` visibility would be meaningless.
 */
final class DraftOrderEvents
{
    public const CREATED = 'draft_created';
    public const CANCELED = 'draft_canceled';
    public const EXPIRED = 'draft_expired';
}
