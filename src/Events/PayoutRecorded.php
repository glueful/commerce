<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Soft-dispatch signal (design spec §5) fired after-commit by
 * {@see \Glueful\Extensions\Commerce\Marketplace\PayoutService::record()} --
 * ONLY on a genuinely fresh payout (never on an idempotent replay). Not a
 * correctness/audit authority: the durable record is the `commerce_payouts`
 * row plus its `payout_debit` ledger entry, both already committed by the
 * time this dispatches.
 */
final class PayoutRecorded extends BaseEvent
{
    /** @param array<string,mixed> $payout */
    public function __construct(
        public readonly array $payout,
    ) {
        parent::__construct();
    }
}
