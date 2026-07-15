<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;

final class DownloadRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_downloads')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_downloads')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /** @return list<array<string,mixed>> every definition for the variant, ordered by position */
    public function forVariant(ApplicationContext $context, string $tenant, string $variantUuid): array
    {
        return db($context)->table('commerce_downloads')
            ->where('tenant_uuid', '=', $tenant)
            ->where('variant_uuid', '=', $variantUuid)
            ->orderBy('position', 'ASC')
            ->get();
    }

    /**
     * ACTIVE-only definitions -- what {@see \Glueful\Extensions\Commerce\Orders\CheckoutService}
     * snapshots into an order line's `downloads` json at checkout time (design spec
     * §2). A definition flipped to `inactive` is simply no longer purchasable for
     * NEW checkouts; already-persisted line snapshots are unaffected (snapshot,
     * don't reference).
     *
     * @return list<array<string,mixed>> ordered by position ascending
     */
    public function activeForVariant(ApplicationContext $context, string $tenant, string $variantUuid): array
    {
        return db($context)->table('commerce_downloads')
            ->where('tenant_uuid', '=', $tenant)
            ->where('variant_uuid', '=', $variantUuid)
            ->where('status', '=', 'active')
            ->orderBy('position', 'ASC')
            ->get();
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        $changes['updated_at'] = db($context)->getDriver()->formatDateTime();

        db($context)->table('commerce_downloads')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($changes);
    }

    public function delete(ApplicationContext $context, string $tenant, string $uuid): void
    {
        db($context)->table('commerce_downloads')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->delete();
    }
}
