<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Dispatched by {@see \Glueful\Extensions\Commerce\Marketplace\CommissionPolicyService}
 * AFTER commit, for notifications/integrations only (design spec §2.3/§5) --
 * it is NOT the audit authority. That is the durable
 * {@see \Glueful\Extensions\Commerce\Marketplace\CommissionPolicyEventRepository}
 * row, written inside the SAME transaction as the policy mutation itself. A
 * failed or unbound dispatch of this event can never undo, hide, or delay
 * the already-committed audit row.
 */
final class CommissionPolicyChanged extends BaseEvent
{
    /** @param array<string,mixed> $payload tenant_uuid, subject_kind, subject_uuid, actor_uuid, before, after */
    public function __construct(public readonly array $payload)
    {
        parent::__construct();
    }
}
