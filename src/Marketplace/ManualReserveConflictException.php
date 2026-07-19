<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown by {@see ReserveService::manualHold()} (design spec §2.8) when a
 * caller-supplied `idempotencyKey` that already claimed a
 * `commerce_seller_reserves` `source_kind=manual` row is REUSED with a
 * different `seller_uuid`/`currency`/`amount`/`reason` -- an idempotency-key
 * MISUSE, never a legitimate exact replay (which returns the existing row
 * untouched instead, {@see ReserveService::manualHold()}'s own identity
 * verify). A `\DomainException` -- the SAME convention this codebase already
 * uses for a caller-supplied key reused with different request content (see
 * {@see \Glueful\Extensions\Commerce\Orders\Refunds\IdempotencyConflictException},
 * {@see CheckoutConflictException}) -- so the Task 16 HTTP surface maps it to
 * `409`, never `422` (this is a conflict with existing server state, not
 * malformed input).
 */
final class ManualReserveConflictException extends \DomainException
{
}
