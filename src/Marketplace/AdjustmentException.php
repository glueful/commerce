<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown by {@see AdjustmentService::post()} for every §2.10 validation
 * condition: a zero `signedAmount`, an empty `reason`, an empty `actorUuid`,
 * or an `accountKey` that is neither the literal `marketplace` nor the
 * canonical `seller:{uuid}` shape. A `\DomainException` -- an operator-input
 * rejection, not a runtime integrity failure -- so
 * {@see \Glueful\Extensions\Commerce\Http\Admin\AdminPayoutController} maps it
 * to a `422`, mirroring {@see PayoutException}'s convention. A duplicate
 * idempotency-key replay whose ledger entry does NOT match the replayed
 * request is a DIFFERENT failure mode and throws {@see LedgerException}
 * instead (via {@see LedgerRepository::post()}'s own verify), never this
 * class.
 */
final class AdjustmentException extends \DomainException
{
}
