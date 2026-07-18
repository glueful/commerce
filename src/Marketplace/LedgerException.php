<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown by {@see LedgerRepository::post()} when a duplicate
 * `(tenant_uuid, idempotency_key)` insert's VERIFY finds the existing row
 * does NOT match the replayed entry on every immutable semantic field
 * (design spec §2.5): `amount`, `currency`, `account_kind`, `account_key`,
 * `seller_uuid`, `entry_type`, `order_uuid`, `seller_order_uuid`,
 * `refund_uuid`, `payout_uuid`, `reason`, `created_by`.
 *
 * This is an INTEGRITY failure, not a validation error: the ledger is
 * append-only and idempotency keys are deterministic
 * (`{order|refund|payout}_uuid:{seller_uuid|account_key}:{entry_type}`), so a
 * caller replaying the exact same key with DIFFERENT semantic content means
 * either a caller programming bug or ledger corruption -- never legitimate
 * end-user input. A `\RuntimeException` (a should-never-happen 500
 * condition), never a `\DomainException`/422 -- mirrors
 * {@see CommissionCalculator}'s hard-reconciliation guardrail. Never
 * silently ignored, and NEVER results in a second row for the same key.
 */
final class LedgerException extends \RuntimeException
{
}
