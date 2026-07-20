<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown by {@see PayoutService::record()} for every §2.10 VALIDATION
 * condition on a fresh (non-replay) payout request: a non-positive `amount`,
 * an empty `externalRef`, an empty `actorUuid`, or the balance recheck
 * (claimed UNDER the seller account lock) finding `amount > available`. A
 * `\DomainException` -- an operator-input/business-rule rejection, not a
 * runtime integrity failure -- so {@see \Glueful\Extensions\Commerce\Http\Admin\AdminPayoutController}
 * maps it to a `422`, mirroring {@see CommissionPolicyException}'s
 * plain-`\DomainException`-caught-by-controller convention. A duplicate
 * idempotency-key replay whose payout row or ledger entry does NOT match the
 * replayed request is a DIFFERENT failure mode -- an integrity failure, never
 * a `422` -- and throws {@see LedgerException} instead (never this class).
 */
final class PayoutException extends \DomainException
{
}
