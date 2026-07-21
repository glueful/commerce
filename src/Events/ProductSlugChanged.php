<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Dispatched by {@see \Glueful\Extensions\Commerce\Catalog\CatalogService}'s
 * rename path (a product PATCH whose `slug` key resolves to a value
 * DIFFERENT from the product's current stored slug) AFTER commit, exactly
 * once per successful rename -- never on product CREATE, never on a
 * slug-less patch, never on a slug patch that resubmits the current value
 * unchanged, and never on a rolled-back transaction (see that method's
 * `afterCommit()` registration, mirroring {@see ProductDeleted}'s identical
 * convention). Slug-ledger/cache-invalidation only (design spec §4) -- never
 * used for business-rule enforcement, which is
 * {@see \Glueful\Extensions\Commerce\Catalog\SlugLifecycleAuthority}'s job,
 * claimed synchronously inside the SAME transaction this event fires after.
 */
final class ProductSlugChanged extends BaseEvent
{
    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $productUuid,
        public readonly string $oldSlug,
        public readonly string $newSlug,
    ) {
        parent::__construct();
    }
}
