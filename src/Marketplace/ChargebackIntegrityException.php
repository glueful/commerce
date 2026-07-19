<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown by {@see ChargebackRepository::insert()} when a duplicate
 * `(tenant_uuid, provider, provider_event_id)` insert's VERIFY finds the
 * existing `commerce_chargebacks` row does NOT match the replayed event on
 * every immutable semantic field (design spec §2.4): `provider`,
 * `payment_reference`, the resolved `order_uuid`, `amount`, `currency`,
 * `reason_code`, `occurred_at`, `kind`, and the resolved
 * `related_chargeback_uuid`.
 *
 * This is an INTEGRITY failure, not a validation error: the provider event
 * ID is the durable idempotency claim a payment gateway is contractually
 * expected to keep stable for the SAME underlying dispute, so a caller
 * replaying that exact ID with DIFFERENT semantic content means either a
 * misbehaving/duplicated upstream provider ID or an ownership-correlation
 * bug -- never legitimate replay traffic. A `\RuntimeException` (a
 * should-never-happen 500 condition), never a `\DomainException`/422 --
 * mirrors {@see LedgerException}'s identical discipline. Never silently
 * ignored, and NEVER results in a second row for the same key.
 */
final class ChargebackIntegrityException extends \RuntimeException
{
}
