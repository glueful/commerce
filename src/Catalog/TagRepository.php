<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;

final class TagRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_tags')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_tags')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(ApplicationContext $context, string $tenant, string $slug): ?array
    {
        return db($context)->table('commerce_tags')
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->first();
    }

    /** @return list<array<string,mixed>> */
    public function all(ApplicationContext $context, string $tenant): array
    {
        return db($context)->table('commerce_tags')
            ->where('tenant_uuid', '=', $tenant)
            ->orderBy('name', 'ASC')
            ->get();
    }

    public function delete(ApplicationContext $context, string $tenant, string $uuid): void
    {
        db($context)->table('commerce_tags')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->delete();
    }

    /**
     * Affected-row-checked serialization primitive for tag deletion (tags carry no
     * tree structure, so this is the only mutation that needs it — product/tag
     * set-lists claim the PRODUCT only and resolve tags by plain in-tenant lookup).
     * Returns false for an unknown or cross-tenant tag.
     */
    public function claimRevision(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $affected = db($context)->table('commerce_tags')->executeModification(
            <<<'SQL'
UPDATE commerce_tags SET revision = revision + 1 WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [$tenant, $uuid]
        );

        return $affected === 1;
    }

    /** Detaches every product from a tag being deleted. */
    public function detachProducts(ApplicationContext $context, string $tagUuid): void
    {
        db($context)->table('commerce_product_tags')
            ->where('tag_uuid', '=', $tagUuid)
            ->delete();
    }

    /** @return list<string> tag uuids currently attached to the product */
    public function tagUuidsForProduct(ApplicationContext $context, string $productUuid): array
    {
        $rows = db($context)->table('commerce_product_tags')
            ->where('product_uuid', '=', $productUuid)
            ->get();

        return array_map(static fn (array $row): string => (string) $row['tag_uuid'], $rows);
    }

    /** @return list<array<string,mixed>> full, tenant-scoped tag rows attached to the product */
    public function tagsForProduct(ApplicationContext $context, string $tenant, string $productUuid): array
    {
        return db($context)->table('commerce_product_tags')
            ->join('commerce_tags', 'commerce_product_tags.tag_uuid', '=', 'commerce_tags.uuid')
            ->select(['commerce_tags.*'])
            ->where('commerce_tags.tenant_uuid', '=', $tenant)
            ->where('commerce_product_tags.product_uuid', '=', $productUuid)
            ->orderBy('commerce_tags.name', 'ASC')
            ->get();
    }

    public function attachProduct(ApplicationContext $context, string $productUuid, string $tagUuid): void
    {
        db($context)->table('commerce_product_tags')->insert([
            'product_uuid' => $productUuid,
            'tag_uuid' => $tagUuid,
        ]);
    }

    public function detachProduct(ApplicationContext $context, string $productUuid, string $tagUuid): void
    {
        db($context)->table('commerce_product_tags')
            ->where('product_uuid', '=', $productUuid)
            ->where('tag_uuid', '=', $tagUuid)
            ->delete();
    }
}
