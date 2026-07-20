<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;

/**
 * `commerce_chargebacks` reads/writes (design spec §2.4, MV5a Task 10): the
 * durable, event-first record of a provider-reported chargeback or reversal.
 *
 * Idempotency arbiter: the unique `(tenant_uuid, provider, provider_event_id)`
 * constraint. A duplicate-key `\PDOException` on {@see self::insert()} is
 * resolved by re-reading the existing row and VERIFYING it matches the
 * replayed event on every immutable semantic field -- a match is an
 * idempotent no-op (the existing row is returned, never a second insert),
 * ANY mismatch is an integrity failure ({@see ChargebackIntegrityException}).
 * This is the SAME portable duplicate-key probe idiom as
 * {@see LedgerRepository::post()} and {@see ReserveRepository::insertRollingHold()}.
 *
 * `insert()` runs its actual INSERT attempt inside its OWN nested transaction
 * (a SAVEPOINT when called from inside an already-open caller transaction --
 * {@see ChargebackService::ingest()} always calls this from inside one) so a
 * duplicate-key `\PDOException` never poisons the caller's whole transaction,
 * mirroring {@see ReserveRepository::insertRollingHold()}'s identical
 * convention.
 */
final class ChargebackRepository
{
    /**
     * Every immutable semantic field a duplicate `(tenant, provider,
     * provider_event_id)` insert must match exactly (design spec §2.4).
     * `provider` is technically tautological (it's part of the lookup key
     * {@see self::findByProviderEvent()} uses to fetch `$existing`), but is
     * listed for the same defense-in-depth completeness
     * {@see LedgerRepository::VERIFIED_FIELDS} follows.
     *
     * `status` and `posted_at` are deliberately ABSENT -- both are mutated
     * AFTER insert, over this row's own posting lifecycle, by
     * {@see self::transitionStatus()}, never by a second `insert()` call, so a
     * legitimate replay's freshly-built `received`/`null` values must never be
     * compared against the row's current (possibly `posted`) state.
     * `related_chargeback_uuid` (MV5a Task 14, design spec §2.10) is the SAME
     * kind of field for a `kind=reversal` row: always `null` at insert time
     * (deliberately unresolved here, see {@see \Glueful\Extensions\Commerce\Marketplace\ChargebackService::ingest()}),
     * then resolved and persisted LATER, in the SAME transaction, via
     * `transitionStatus()`'s `$extra` -- so it is likewise excluded here, or
     * every replay of an already-resolved reversal event would spuriously
     * mismatch its own freshly-built (still-null) insert attempt against the
     * row it already correctly updated, and throw instead of no-op.
     */
    private const VERIFIED_FIELDS = [
        'provider',
        'payment_reference',
        'order_uuid',
        'amount',
        'currency',
        'reason_code',
        'occurred_at',
        'kind',
    ];

    /**
     * Event-first insert with deterministic idempotency (design spec §2.4).
     * A duplicate `(tenant_uuid, provider, provider_event_id)` triggers a
     * VERIFY against every field in {@see self::VERIFIED_FIELDS}: all match
     * => idempotent no-op (the pre-existing row is returned); any mismatch
     * => {@see ChargebackIntegrityException}. NEVER inserts a second row for
     * the same key.
     *
     * @param array{
     *     provider: string,
     *     provider_event_id: string,
     *     payment_reference: string,
     *     order_uuid: string|null,
     *     amount: int,
     *     currency: string,
     *     reason_code: string|null,
     *     occurred_at: string,
     *     kind: string,
     *     related_chargeback_uuid: string|null,
     *     status: string,
     *     related_event_id?: string|null
     * } $entry `related_event_id` (MV5a Task 14 review fix) is the RAW,
     *   unresolved `relatedEventId` off a `kind=reversal` event -- NEVER
     *   persisted as a column (see {@see self::VERIFIED_FIELDS}'s docblock
     *   for why `related_chargeback_uuid` itself is excluded from ordinary
     *   verification); passed through ONLY so a duplicate-key conflict can
     *   run {@see self::verifyReversalRelation()} against it.
     * @return array<string,mixed> the persisted row (freshly inserted, or the
     *     pre-existing verified-matching row on a duplicate replay)
     */
    public function insert(ApplicationContext $context, string $tenant, array $entry): array
    {
        $row = [
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $tenant,
            'provider' => $entry['provider'],
            'provider_event_id' => $entry['provider_event_id'],
            'payment_reference' => $entry['payment_reference'],
            'order_uuid' => $entry['order_uuid'],
            'amount' => $entry['amount'],
            'currency' => $entry['currency'],
            'reason_code' => $entry['reason_code'],
            'occurred_at' => $entry['occurred_at'],
            'kind' => $entry['kind'],
            'related_chargeback_uuid' => $entry['related_chargeback_uuid'],
            'status' => $entry['status'],
        ];

        try {
            db($context)->transaction(function () use ($context, $row): void {
                db($context)->table('commerce_chargebacks')->insert($row);
            });
        } catch (\PDOException $e) {
            $existing = $this->findByProviderEvent(
                $context,
                $tenant,
                (string) $row['provider'],
                (string) $row['provider_event_id']
            );
            if ($existing === null) {
                // Unrelated failure -- never swallowed as a verified duplicate
                // (mirrors LedgerRepository::post()'s identical discipline).
                throw $e;
            }

            $this->verify($tenant, $existing, $row);

            if ((string) $row['kind'] === 'reversal') {
                $this->verifyReversalRelation(
                    $context,
                    $tenant,
                    $existing,
                    (string) $row['provider'],
                    $entry['related_event_id'] ?? null
                );
            }

            return $existing;
        }

        $inserted = $this->findByProviderEvent(
            $context,
            $tenant,
            (string) $row['provider'],
            (string) $row['provider_event_id']
        );
        if ($inserted === null) {
            // Unreachable given the insert above just committed -- defensive only.
            throw new ChargebackIntegrityException(
                "Chargeback insert failure: row for provider '{$row['provider']}' "
                    . "event '{$row['provider_event_id']}' not found immediately after insert."
            );
        }

        return $inserted;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $row
     */
    private function verify(string $tenant, array $existing, array $row): void
    {
        foreach (self::VERIFIED_FIELDS as $field) {
            $existingValue = $existing[$field] ?? null;
            $newValue = $row[$field] ?? null;

            $matches = match ($field) {
                'amount' => (int) $existingValue === (int) $newValue,
                'occurred_at' => self::normalizeDate((string) $existingValue)
                    === self::normalizeDate((string) $newValue),
                default => $this->normalize($existingValue) === $this->normalize($newValue),
            };

            if (!$matches) {
                $provider = (string) $row['provider'];
                $eventId = (string) $row['provider_event_id'];
                throw new ChargebackIntegrityException(
                    "Chargeback integrity failure (tenant {$tenant}, provider {$provider}, "
                        . "event {$eventId}): field \"{$field}\" mismatch on replay -- "
                        . 'existing=' . var_export($existingValue, true) . ', '
                        . 'replayed=' . var_export($newValue, true) . '.'
                );
            }
        }
    }

    private function normalize(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * MV5a Task 14 review fix (design spec §2.4 "exact conflict
     * verification", §2.10): `related_chargeback_uuid` is deliberately
     * EXCLUDED from {@see self::VERIFIED_FIELDS} (it is a post-insert-
     * mutated column, resolved LATER by {@see self::transitionStatus()},
     * never re-verified against a freshly-built, still-null insert
     * attempt). That correctly stops every legitimate reversal replay from
     * spuriously throwing -- but it also means a conflicting event reusing
     * the SAME `(tenant, provider, provider_event_id)` with a DIFFERENT
     * `relatedEventId` would otherwise silently no-op instead of raising an
     * integrity failure. This closes that gap WITHOUT reopening the
     * original bug: it only compares the REPLAYED event's OWN
     * `relatedEventId`, re-resolved fresh under `(tenant, $provider,
     * relatedEventId)` -- the identical lookup
     * {@see \Glueful\Extensions\Commerce\Marketplace\ChargebackService::postReversalCompensation()}
     * itself uses -- against the STORED row's already-resolved
     * `related_chargeback_uuid`.
     *
     * Both sides must be RESOLVABLE to compare (design spec "when both
     * resolvable"): a stored row whose relation is still unresolved
     * (`related_chargeback_uuid` is `null` -- either a genuinely unknown
     * relation that will never resolve, or a same-transaction race this
     * replay lost) has nothing to compare against yet, and an incoming
     * `relatedEventId` that itself does not YET resolve to any persisted
     * chargeback is likewise not comparable -- neither case is a guessed
     * mismatch, both are silently skipped (never a false-positive
     * integrity failure). Only a STORED, RESOLVED relation disagreeing with
     * a NEWLY, DIFFERENTLY resolved one is the genuine integrity failure
     * this method exists to catch.
     */
    private function verifyReversalRelation(
        ApplicationContext $context,
        string $tenant,
        array $existing,
        string $provider,
        ?string $relatedEventId
    ): void {
        $storedRelation = $existing['related_chargeback_uuid'] ?? null;
        if ($storedRelation === null || $relatedEventId === null || $relatedEventId === '') {
            return;
        }

        $resolved = $this->findByProviderEvent($context, $tenant, $provider, $relatedEventId);
        if ($resolved === null) {
            return;
        }

        $resolvedUuid = (string) $resolved['uuid'];
        if ($resolvedUuid !== (string) $storedRelation) {
            $eventId = (string) $existing['provider_event_id'];
            throw new ChargebackIntegrityException(
                "Chargeback integrity failure (tenant {$tenant}, provider {$provider}, event {$eventId}): "
                    . "field \"related_chargeback_uuid\" mismatch on reversal replay -- "
                    . 'existing=' . var_export($storedRelation, true) . ', '
                    . 'replayed relatedEventId resolves to=' . var_export($resolvedUuid, true) . '.'
            );
        }
    }

    /**
     * Canonical `Y-m-d H:i:s` (UTC) comparison form, so a driver-formatted
     * timestamp (which may carry fractional seconds, a `T` separator, or a
     * timezone offset) compares equal to the value this repository itself
     * formatted at insert time -- mirrors
     * {@see ReserveService::normalizeDate()}'s identical convention.
     */
    private static function normalizeDate(string $value): string
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /** @return array<string,mixed>|null */
    public function findByProviderEvent(
        ApplicationContext $context,
        string $tenant,
        string $provider,
        string $providerEventId
    ): ?array {
        return db($context)->table('commerce_chargebacks')
            ->where('tenant_uuid', '=', $tenant)
            ->where('provider', '=', $provider)
            ->where('provider_event_id', '=', $providerEventId)
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_chargebacks')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /**
     * Affected-row-checked `$fromStatus` -> `$toStatus` claim (design spec
     * §2.4/§2.5, MV5a Task 11), mirroring {@see ReserveRepository::markReleased()}'s
     * CAS shape: {@see ChargebackService}'s posting methods only ever call this
     * AFTER re-reading the row and confirming `status = $fromStatus` themselves,
     * inside the SAME open transaction -- in ordinary (single-writer, per-test)
     * operation this always succeeds. A no-op CAS (a concurrent/earlier path
     * already moved the row) is not treated as fatal here -- the fresh re-read
     * below always returns whatever the row's TRUE current state is, mirroring
     * {@see ReserveRepository::markConsumed()}'s identical "false return is a
     * legitimate no-op" discipline.
     *
     * @param array<string,mixed> $extra additional columns to set alongside the
     *   status transition (MV5a Task 14) -- e.g. `['related_chargeback_uuid' =>
     *   $originalUuid]` when a reversal's relation resolves to a known original
     *   even though it isn't posting yet (still `awaiting_attribution`/
     *   `integrity_hold`). Applied AFTER the base `status`/`updated_at` set but
     *   BEFORE `$postedAt`, so `$postedAt` always wins on key collision.
     * @return array<string,mixed> the freshly re-read row
     */
    public function transitionStatus(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $fromStatus,
        string $toStatus,
        ?string $postedAt = null,
        array $extra = []
    ): array {
        $now = db($context)->getDriver()->formatDateTime();

        $set = array_merge(['status' => $toStatus, 'updated_at' => $now], $extra);
        if ($postedAt !== null) {
            $set['posted_at'] = $postedAt;
        }

        db($context)->table('commerce_chargebacks')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->where('status', '=', $fromStatus)
            ->update($set);

        return $this->findByUuid($context, $tenant, $uuid)
            ?? throw new ChargebackIntegrityException(
                "Chargeback status transition failure: row for uuid '{$uuid}' not found "
                    . "after a {$fromStatus} -> {$toStatus} transition attempt."
            );
    }

    /**
     * Bulk-persists the durable attribution rows for a chargeback (design spec
     * §2.5) -- the operator-supplied partial-event rows and the persisted
     * full-event auto-expansion result alike. Seller-attributed lines ONLY:
     * a line resolving to NO seller (nullable `commerce_order_lines.seller_uuid`)
     * never gets a row here -- its amount flows directly into
     * {@see ChargebackService}'s marketplace-funded remainder instead, mirroring
     * how {@see LedgerPostingService::postRefund()}'s own marketplace leg is
     * never backed by a `commerce_refund_lines` row either.
     *
     * @param list<array{order_line_uuid:string, seller_uuid:string, amount:int}> $lines
     */
    public function insertLines(
        ApplicationContext $context,
        string $tenant,
        string $chargebackUuid,
        array $lines
    ): void {
        foreach ($lines as $line) {
            db($context)->table('commerce_chargeback_lines')->insert([
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => $tenant,
                'chargeback_uuid' => $chargebackUuid,
                'order_line_uuid' => $line['order_line_uuid'],
                'seller_uuid' => $line['seller_uuid'],
                'amount' => $line['amount'],
            ]);
        }
    }

    /** @return list<array<string,mixed>> ordered by `order_line_uuid` ASC (stable) */
    public function linesFor(ApplicationContext $context, string $tenant, string $chargebackUuid): array
    {
        return db($context)->table('commerce_chargeback_lines')
            ->where('tenant_uuid', '=', $tenant)
            ->where('chargeback_uuid', '=', $chargebackUuid)
            ->orderBy('order_line_uuid', 'ASC')
            ->get();
    }

    /**
     * Cumulative amount this order's OTHER already-POSTED chargebacks have
     * already attributed to each order line (design spec §2.5) -- the
     * chargeback-side half of the shared "remaining after earlier
     * chargebacks/refunds" cap {@see ChargebackService} evaluates alongside
     * {@see ChargebackService}'s own refund-side query. Deliberately mirrors
     * {@see \Glueful\Extensions\Commerce\Marketplace\MarketplaceRefundGuard::completedAmountByLine()}'s
     * "POSTED/COMPLETED only, one batched query" discipline: an `awaiting_attribution`
     * or `integrity_hold` chargeback never actually reversed anything, so it must
     * never count toward this cumulative picture. `$excludeChargebackUuid` is
     * always the chargeback currently being posted -- on a settlement replay its
     * own (already-posted) lines must never be double-counted against itself.
     *
     * @return array<string,int> order_line_uuid => already-charged-back amount
     */
    public function priorPostedChargedBackByLine(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        string $excludeChargebackUuid
    ): array {
        $rows = db($context)->table('commerce_chargeback_lines')
            ->join(
                'commerce_chargebacks',
                'commerce_chargeback_lines.chargeback_uuid',
                '=',
                'commerce_chargebacks.uuid'
            )
            ->select(['commerce_chargeback_lines.order_line_uuid', 'commerce_chargeback_lines.amount'])
            ->where('commerce_chargebacks.tenant_uuid', '=', $tenant)
            ->where('commerce_chargebacks.order_uuid', '=', $orderUuid)
            ->where('commerce_chargebacks.status', '=', 'posted')
            ->where('commerce_chargebacks.uuid', '!=', $excludeChargebackUuid)
            ->get();

        $sums = [];
        foreach ($rows as $row) {
            $key = (string) $row['order_line_uuid'];
            $sums[$key] = ($sums[$key] ?? 0) + (int) $row['amount'];
        }

        return $sums;
    }
}
