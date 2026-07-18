<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;

/**
 * The durable `commerce_payouts` row (design spec §2.10/§3.5) -- the
 * operator-confirmed record that a manual payout moved funds externally,
 * inserted atomically alongside its matching `payout_debit` ledger entry by
 * {@see PayoutService::record()}. Deliberately minimal: insert + the one
 * idempotency lookup {@see PayoutService} needs to detect a replay. Rows are
 * never updated or deleted (§2.10: append-only, mirroring the ledger itself).
 */
final class PayoutRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_payouts')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findByIdempotencyKey(ApplicationContext $context, string $tenant, string $idempotencyKey): ?array
    {
        return db($context)->table('commerce_payouts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('idempotency_key', '=', $idempotencyKey)
            ->first();
    }
}
