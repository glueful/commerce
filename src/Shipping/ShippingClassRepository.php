<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Shipping;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\LiteralLike;

final class ShippingClassRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_shipping_classes')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_shipping_classes')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(ApplicationContext $context, string $tenant, string $slug): ?array
    {
        return db($context)->table('commerce_shipping_classes')
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->first();
    }

    /** @return list<array<string,mixed>> every shipping class for the tenant, ordered by name */
    public function all(ApplicationContext $context, string $tenant): array
    {
        return db($context)->table('commerce_shipping_classes')
            ->where('tenant_uuid', '=', $tenant)
            ->orderBy('name', 'ASC')
            ->get();
    }

    /**
     * Paginated admin list (Layer 6 Global Constraints): `q` is a
     * case-insensitive literal substring match on name OR slug via
     * {@see LiteralLike}. Ordered `name ASC, uuid ASC` (stable tie-break); count
     * and row queries apply the identical predicate set.
     *
     * @param array<string,mixed> $filters 'q' (literal substring on name/slug)
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function paginatedFor(
        ApplicationContext $context,
        string $tenant,
        array $filters,
        int $page,
        int $perPage
    ): array {
        $count = db($context)->table('commerce_shipping_classes')->where('tenant_uuid', '=', $tenant);
        $rows = db($context)->table('commerce_shipping_classes')->where('tenant_uuid', '=', $tenant);

        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $pattern = LiteralLike::pattern($q);
            $condition = "(LOWER(name) LIKE ? ESCAPE '!' OR LOWER(slug) LIKE ? ESCAPE '!')";
            $count->whereRaw($condition, [$pattern, $pattern]);
            $rows->whereRaw($condition, [$pattern, $pattern]);
        }

        $items = $rows->orderBy('name', 'ASC')
            ->orderBy('uuid', 'ASC')
            ->limit($perPage)
            ->offset(max(0, $page - 1) * $perPage)
            ->get();

        return [
            'items' => $items,
            'total' => $count->count(),
        ];
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        db($context)->table('commerce_shipping_classes')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($changes);
    }

    public function delete(ApplicationContext $context, string $tenant, string $uuid): void
    {
        db($context)->table('commerce_shipping_classes')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->delete();
    }

    /**
     * Affected-row-checked serialization primitive: shipping-class PATCH/DELETE
     * claim it directly (URL's primary resource); variant shipping-class
     * assignment (the §6 shared-claim protocol,
     * {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::updateVariant()})
     * claims it too, from the other side, so a class-delete-vs-variant-assign race
     * has one winner (see ShippingClassService's class docblock). Returns false
     * for an unknown or cross-tenant class.
     */
    public function claimRevision(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $affected = db($context)->table('commerce_shipping_classes')->executeModification(
            <<<'SQL'
UPDATE commerce_shipping_classes SET revision = revision + 1 WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [$tenant, $uuid]
        );

        return $affected === 1;
    }

    /**
     * Which of $slugs currently exist as shipping classes for the tenant --
     * absorbed from `ShippingZoneRepository::existingClassSlugs()` (T2's interim
     * read of this table before this repository existed): `per_class_table`
     * shipping-method config references class slugs by an open reference (classes
     * may be created later, spec §2), so this is used only to compute the
     * unknown-slug WARN-but-allow list, never to reject a method-config write.
     *
     * @param list<string> $slugs
     * @return list<string>
     */
    public function existingClassSlugs(ApplicationContext $context, string $tenant, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $rows = db($context)->table('commerce_shipping_classes')
            ->where('tenant_uuid', '=', $tenant)
            ->whereIn('slug', $slugs)
            ->get();

        return array_values(array_unique(
            array_map(static fn (array $row): string => (string) $row['slug'], $rows)
        ));
    }

    /**
     * True if any variant currently references the class -- the DELETE refusal
     * check (spec §6: "Class DELETE is REFUSED (409) while any variant references
     * it"). Queried directly against `commerce_variants` rather than through
     * VariantRepository: this is a class-delete concern, not a variant-read one.
     */
    public function isReferencedByVariant(ApplicationContext $context, string $classUuid): bool
    {
        return db($context)->table('commerce_variants')
            ->where('shipping_class_uuid', '=', $classUuid)
            ->count() > 0;
    }

    /**
     * Batched slug resolution for the variant `shipping_class` projection field
     * (spec §6): one IN query for every distinct class uuid referenced across a
     * list of variant uuids, tenant-scoped -- avoids one lookup per uuid.
     *
     * @param list<string> $uuids
     * @return array<string,string> resolved slug keyed by class uuid
     */
    public function slugsByUuids(ApplicationContext $context, string $tenant, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $rows = db($context)->table('commerce_shipping_classes')
            ->where('tenant_uuid', '=', $tenant)
            ->whereIn('uuid', $uuids)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['uuid']] = (string) $row['slug'];
        }

        return $result;
    }

    /**
     * Attaches a resolved, nullable `shipping_class` slug to each variant row
     * alongside its raw `shipping_class_uuid` (spec §6: read projections must
     * preserve BOTH the uuid for editing and the resolved slug for pricing/
     * display -- one must not replace the other). One batched IN query rather
     * than one lookup per variant.
     *
     * @param list<array<string,mixed>> $variants
     * @return list<array<string,mixed>>
     */
    public function attachResolvedSlugs(ApplicationContext $context, string $tenant, array $variants): array
    {
        $uuids = array_values(array_unique(array_filter(array_map(
            static fn (array $variant): ?string => $variant['shipping_class_uuid'] ?? null,
            $variants
        ))));
        $bySlug = $this->slugsByUuids($context, $tenant, $uuids);

        return array_map(static function (array $variant) use ($bySlug): array {
            $classUuid = $variant['shipping_class_uuid'] ?? null;
            $variant['shipping_class'] = $classUuid !== null ? ($bySlug[$classUuid] ?? null) : null;

            return $variant;
        }, $variants);
    }

    /** Single-row convenience wrapper around {@see self::attachResolvedSlugs()}. */
    public function attachResolvedSlug(ApplicationContext $context, string $tenant, array $variant): array
    {
        return $this->attachResolvedSlugs($context, $tenant, [$variant])[0];
    }
}
