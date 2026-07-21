<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;

/**
 * {@see CatalogReader} backed entirely by the existing {@see ProductRepository}
 * reads -- no new SQL. `isTombstoned()` composes two calls: a uuid is a
 * tombstone only when it is absent from the live read AND present in the
 * including-deleted read; an unknown/cross-tenant uuid is absent from both,
 * so it is never misreported as tombstoned.
 */
final class ProductRepositoryCatalogReader implements CatalogReader
{
    public function __construct(private ProductRepository $products)
    {
    }

    public function findLiveProduct(ApplicationContext $context, string $tenant, string $productUuid): ?array
    {
        return $this->products->findLiveByUuid($context, $tenant, $productUuid);
    }

    public function isTombstoned(ApplicationContext $context, string $tenant, string $productUuid): bool
    {
        if ($this->products->findLiveByUuid($context, $tenant, $productUuid) !== null) {
            return false;
        }

        return $this->products->findIncludingDeletedByUuid($context, $tenant, $productUuid) !== null;
    }
}
