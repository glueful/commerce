<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown by {@see PayoutService::execute()} when a `PayoutCollector::transfer()`
 * call either throws (a transport/infrastructure failure) or returns a
 * {@see \Glueful\Extensions\Contracts\Payments\PayoutResult} with status
 * `UNKNOWN` -- the outcome is genuinely ambiguous: the transfer may or may not
 * have moved money. The reserve hold is deliberately left in place (never
 * released) and the payout row stays `status=pending` with a fresh
 * `next_reconcile_at` watchdog; only a reconcile (design spec §2.6, Task 9)
 * may resolve it -- never a blind retry, which risks a double transfer.
 * Mirrors {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundOutcomeUnknownException}.
 */
final class PayoutOutcomeUnknownException extends \RuntimeException
{
}
