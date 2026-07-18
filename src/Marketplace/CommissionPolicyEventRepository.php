<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Durable, append-only audit trail for every commission-policy mutation
 * (design spec §2.3/§3.4, MV3 Task 4): {@see CommissionPolicyService} inserts
 * exactly one row per mutation, in the SAME transaction as the policy write
 * itself -- a failure appending this row rolls back the policy change too
 * (there is no such thing as an unaudited commission-policy change).
 *
 * Deliberately INSERT/LIST ONLY: no `update()`/`delete()` method exists on
 * this class, on purpose. A correction is a NEW row (a subsequent policy
 * change, itself audited), never an edit to history.
 */
final class CommissionPolicyEventRepository
{
    /**
     * @param array{
     *     uuid: string,
     *     subject_kind: 'product'|'seller'|'workspace',
     *     subject_uuid: string,
     *     actor_uuid: string,
     *     before_policy: array{kind:?string,bps:?int,fixed:?int},
     *     after_policy: array{kind:?string,bps:?int,fixed:?int}
     * } $row
     */
    public function insert(ApplicationContext $context, string $tenant, array $row): void
    {
        db($context)->table('commerce_commission_policy_events')->insert([
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
     * Newest-first, matching the design spec §3.4 index
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
        $rows = db($context)->table('commerce_commission_policy_events')
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
