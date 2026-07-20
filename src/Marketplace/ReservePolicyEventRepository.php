<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Durable, append-only audit trail for every rolling-reserve-policy
 * mutation (design spec §2.1/§3.2, MV5a Task 6): {@see ReservePolicyService}
 * inserts exactly one row per mutation, in the SAME transaction as the
 * policy write itself -- a failure appending this row rolls back the
 * policy change too (there is no such thing as an unaudited
 * reserve-policy change). Mirrors
 * {@see CommissionPolicyEventRepository}'s exact idiom (the SAME pattern,
 * a DIFFERENT table -- `commerce_reserve_policy_events`, never
 * `commerce_commission_policy_events`).
 *
 * Deliberately INSERT/LIST ONLY: no `update()`/`delete()` method exists on
 * this class, on purpose. A correction is a NEW row (a subsequent policy
 * change, itself audited), never an edit to history.
 */
final class ReservePolicyEventRepository
{
    /**
     * @param array{
     *     uuid: string,
     *     subject_kind: 'workspace'|'seller',
     *     subject_uuid: string,
     *     actor_uuid: string,
     *     before_policy: array{reserve_bps:?int,reserve_days:?int},
     *     after_policy: array{reserve_bps:?int,reserve_days:?int}
     * } $row
     */
    public function insert(ApplicationContext $context, string $tenant, array $row): void
    {
        db($context)->table('commerce_reserve_policy_events')->insert([
            'uuid' => $row['uuid'],
            'tenant_uuid' => $tenant,
            'subject_kind' => $row['subject_kind'],
            'subject_uuid' => $row['subject_uuid'],
            'actor_uuid' => $row['actor_uuid'],
            'before_policy' => json_encode($row['before_policy'], JSON_THROW_ON_ERROR),
            'after_policy' => json_encode($row['after_policy'], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Newest-first, matching the design spec §3.2 index
     * `(tenant_uuid, subject_kind, subject_uuid, created_at)`.
     *
     * @return list<array<string,mixed>>
     */
    public function list(
        ApplicationContext $context,
        string $tenant,
        string $subjectKind,
        string $subjectUuid
    ): array {
        $rows = db($context)->table('commerce_reserve_policy_events')
            ->where('tenant_uuid', '=', $tenant)
            ->where('subject_kind', '=', $subjectKind)
            ->where('subject_uuid', '=', $subjectUuid)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();

        return array_map(fn (array $row): array => $this->decodeJson($row), $rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeJson(array $row): array
    {
        foreach (['before_policy', 'after_policy'] as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && $row[$field] !== '') {
                $decoded = json_decode($row[$field], true);
                $row[$field] = is_array($decoded) ? $decoded : null;
            }
        }

        return $row;
    }
}
