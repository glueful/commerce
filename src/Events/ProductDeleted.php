<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Dispatched by {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::deleteProduct()}
 * AFTER commit, exactly once per successful tombstone -- never on an
 * unknown/cross-tenant uuid, a repeat delete, a losing concurrent claim
 * racer, or a rolled-back transaction (see that method's `afterCommit()`
 * registration, mirroring {@see \Glueful\Extensions\Commerce\Orders\OrderPaymentService::markPaid()}'s
 * `OrderPaid` convention).
 */
final class ProductDeleted extends BaseEvent
{
    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $productUuid,
    ) {
        parent::__construct();
    }
}
