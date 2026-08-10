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
 *  - {@see self::FINALIZED} -- the finalization authority,
 *    {@see \Glueful\Extensions\Commerce\Orders\DraftFinalizationService::finalize()}
 *    (Task 10), written INSIDE the finalize transaction.
 *
 * All four are recorded with the default `internal` visibility: a draft has no
 * customer-facing surface at all, so `customer` visibility would be meaningless.
 *
 * {@see self::FINALIZED} is the one that survives into a REAL order's trail, and
 * it is deliberately ADDITIVE to -- never a replacement for -- the ordinary
 * `status:pending_payment` row
 * {@see \Glueful\Extensions\Commerce\Orders\OrderRepository::finalizeDraftTransition()}
 * writes. The lifecycle row keeps a finalized walk-in order's trail
 * indistinguishable from a storefront order's; this row records the extra fact
 * that the order began life as a draft, and under which number it was closed.
 */
final class DraftOrderEvents
{
    public const CREATED = 'draft_created';
    public const CANCELED = 'draft_canceled';
    public const EXPIRED = 'draft_expired';
    public const FINALIZED = 'draft_finalized';
}
