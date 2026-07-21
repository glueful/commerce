<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;

/**
 * The read-only cross-domain catalog seam (Commerce-Slice-1 Task 2): other
 * domains that need to check a product's existence/tombstone state without
 * depending on {@see ProductRepository} or {@see CatalogService} directly go
 * through this contract instead. Both methods are tenant-scoped and total
 * over an unknown/cross-tenant uuid -- never throws.
 */
interface CatalogReader
{
    /**
     * @return array<string,mixed>|null Live product row for this tenant, or
     *     null (never a tombstone).
     */
    public function findLiveProduct(ApplicationContext $context, string $tenant, string $productUuid): ?array;

    /** True when the uuid exists for this tenant only as a tombstone. */
    public function isTombstoned(ApplicationContext $context, string $tenant, string $productUuid): bool;
}
