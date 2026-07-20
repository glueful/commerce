<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\QueryBuilder;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Support\JsonStringArrayContainsSql;
use Glueful\Extensions\Commerce\Support\LiteralLike;
use Glueful\Extensions\Commerce\Support\UtcNowSql;

final class ProductRepository
{
    /**
     * Task 3 centralized buyer-availability predicate (design spec §2.3,
     * MV5b): a product is buyer-visible/buyable only when it carries NO
     * seller (`seller_uuid IS NULL` -- an ordinary, non-marketplace-store
     * product, ALWAYS allowed by this predicate) OR its seller CURRENTLY has
     * `status = 'active'`. A correlated `EXISTS`, never an inner `JOIN`
     * (which would silently drop every sellerless row) and never a
     * per-status deny-list (`onboarding`/`suspended`/`closed` are excluded
     * simply by not being `active`). Shared verbatim by
     * {@see self::findBuyerAvailableByUuid()}, {@see self::findBuyerAvailableBySlug()},
     * and {@see self::activeFilteredQuery()} -- exactly ONE reusable
     * predicate string, so a suspended/reinstated seller's products
     * disappear/reappear across every buyer read in lockstep, WITHOUT ever
     * mutating this product row. {@see self::findLiveByUuid()} /
     * {@see self::findLiveBySlug()} deliberately do NOT carry this predicate
     * -- admin and internal mutation services must keep reaching a suspended
     * seller's products.
     */
    private const BUYER_SELLER_ACTIVE_SQL = <<<'SQL'
(seller_uuid IS NULL OR EXISTS (
    SELECT 1 FROM commerce_sellers
    WHERE commerce_sellers.uuid = commerce_products.seller_uuid
    AND commerce_sellers.tenant_uuid = ?
    AND commerce_sellers.status = 'active'
))
SQL;

    /**
     * Applies {@see self::BUYER_SELLER_ACTIVE_SQL} to $query, but ONLY when
     * the marketplace INSTALL master switch is on (design spec §2.1's
     * MASTER-OFF FAST PATH, mirrored from {@see MarketplaceMode::installEnabled()}
     * -- a config-only, zero-database-query check). With the switch off, a
     * plain/legacy install (or a tenant that ran the marketplace before
     * later switching it off, leaving a stray `seller_uuid` behind) must stay
     * byte-identical to a pre-MV5b (indeed pre-MV1) read: no `commerce_sellers`
     * reference in the SQL at all, and no product ever excluded by a seller
     * row this install no longer consults. Switch on: the predicate is a
     * genuine no-op for sellerless/active-seller products and only excludes
     * a non-active seller's rows.
     */
    private function applyBuyerAvailability(QueryBuilder $query, ApplicationContext $context, string $tenant): void
    {
        if ((new MarketplaceMode())->installEnabled($context)) {
            $query->whereRaw(self::BUYER_SELLER_ACTIVE_SQL, [$tenant]);
        }
    }

    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_products')->insert($this->encodeJson($row));
    }

    /**
     * Live read (design spec Layer 6 §2): excludes a tombstoned row. This is the
     * read EVERY interactive admin/storefront/relationship/review-create/cart/
     * catalog-mutation path must use. The framework's query builder already
     * auto-excludes soft-deleted rows for a plain `where()->first()` on a table
     * carrying a `deleted_at` column (see `SoftDeleteHandler`) -- the explicit
     * `deleted_at IS NULL` predicate below is deliberate belt-and-suspenders: this
     * read's safety must not depend on that framework mechanism staying enabled.
     *
     * @return array<string,mixed>|null
     */
    public function findLiveByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        $row = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->whereRaw('deleted_at IS NULL')
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /** @see self::findLiveByUuid() @return array<string,mixed>|null */
    public function findLiveBySlug(ApplicationContext $context, string $tenant, string $slug): ?array
    {
        $row = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->whereRaw('deleted_at IS NULL')
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /**
     * Dedicated buyer-availability read (design spec §2.3, MV5b): the exact
     * SAME tombstone-only predicate {@see self::findLiveByUuid()} applies,
     * PLUS {@see self::BUYER_SELLER_ACTIVE_SQL}. This is the read every
     * buyer-context storefront direct-read and public-review path must use
     * -- {@see self::findLiveByUuid()} stays unchanged and tombstone-only
     * for admin/catalog-mutation/importer/relationship/inventory/media/
     * download/commission/attribution callers.
     *
     * @return array<string,mixed>|null
     */
    public function findBuyerAvailableByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        $query = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->whereRaw('deleted_at IS NULL');
        $this->applyBuyerAvailability($query, $context, $tenant);

        $row = $query->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /** @see self::findBuyerAvailableByUuid() @return array<string,mixed>|null */
    public function findBuyerAvailableBySlug(ApplicationContext $context, string $tenant, string $slug): ?array
    {
        $query = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->whereRaw('deleted_at IS NULL');
        $this->applyBuyerAvailability($query, $context, $tenant);

        $row = $query->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /**
     * Explicitly named integrity/uniqueness read (design spec Layer 6 §2): reaches
     * a tombstoned row ON PURPOSE via `withTrashed()` (bypassing the framework's
     * automatic soft-delete filter). Reserved for named history/integrity paths
     * (a direct product-row integrity check) and slug create/rename collision
     * checks -- a tombstone keeps reserving its slug, so a new/renamed product
     * colliding with it is a normal slug-in-use 422, never a raw unique-constraint
     * error. NEVER an interactive admin/storefront/relationship/cart path.
     *
     * @return array<string,mixed>|null
     */
    public function findIncludingDeletedByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        $row = db($context)->table('commerce_products')
            ->withTrashed()
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /** @see self::findIncludingDeletedByUuid() @return array<string,mixed>|null */
    public function findIncludingDeletedBySlug(ApplicationContext $context, string $tenant, string $slug): ?array
    {
        $row = db($context)->table('commerce_products')
            ->withTrashed()
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /**
     * ONE affected-row-checked soft-delete write (design spec Layer 6 §2):
     * DB-time `deleted_at`, guarded by `deleted_at IS NULL` so a re-delete (or a
     * losing concurrent racer) affects zero rows. Raw SQL rather than the query
     * builder's generic soft-delete-aware `delete()` -- consistent with every
     * other affected-row-checked primitive on this table
     * ({@see self::claimCatalogRevision()}, {@see self::adjustRating()}) and
     * unaffected by the framework's soft-delete column-existence cache. False
     * means "already deleted or unknown/cross-tenant" --
     * {@see CatalogService::deleteProduct()} maps that to the same 404 as an
     * unknown product. Variants/stock/media rows are untouched; there is no
     * restore.
     */
    public function markDeleted(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        // UtcNowSql, not formatDateTime() (PHP-process tz + clock skew) and not bare
        // CURRENT_TIMESTAMP (non-UTC pgsql sessions) -- same rationale as
        // DownloadGrantRepository::mint(). The stamp is forensic only; delete/visibility
        // logic tests deleted_at solely via IS [NOT] NULL.
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        $affected = db($context)->table('commerce_products')->executeModification(
            <<<SQL
UPDATE commerce_products
SET deleted_at = {$utcNow}
WHERE tenant_uuid = ? AND uuid = ? AND deleted_at IS NULL
SQL,
            [
                $tenant,
                $uuid,
            ]
        );

        return $affected === 1;
    }

    /**
     * Storefront active-product listing (Layer 6 Global Constraints): live+active
     * only, with optional `category`/`tag`/`attributes` filters already resolved
     * to a {@see ResolvedProductFilters} value by
     * `Http\Storefront\ProductController::index()`. Count and row queries are
     * built by the SAME {@see self::activeFilteredQuery()} call (two fresh
     * builder instances, identical predicates) so a filter can never drift
     * between the two. Ordered `created_at DESC, uuid ASC` -- the uuid
     * tie-break keeps pages stable when several products share a `created_at`.
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function listActive(
        ApplicationContext $context,
        string $tenant,
        int $page,
        int $perPage,
        ?ResolvedProductFilters $filters = null
    ): array {
        $total = $this->activeFilteredQuery($context, $tenant, $filters)->count();
        $rows = $this->activeFilteredQuery($context, $tenant, $filters)
            ->orderBy('created_at', 'DESC')
            ->orderBy('uuid', 'ASC')
            ->limit($perPage)
            ->offset(max(0, $page - 1) * $perPage)
            ->get();

        return [
            'items' => array_map(fn (array $row): array => $this->decodeJson($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * Builds the live+active predicate set {@see self::listActive()}'s count and
     * row queries share, plus every EXISTS semijoin a resolved category/tag/
     * attribute-pair filter contributes. A FRESH `QueryBuilder` every call (never
     * shared/reused across count and rows) -- returned rather than executed, and
     * deliberately public (not merely private), so the Layer 6 query-plan test
     * can `->explain()` this EXACT predicate set without ever duplicating it
     * (and therefore without ever silently drifting from what `listActive()`
     * actually runs).
     *
     * Category/tag filters are correlated `EXISTS` subqueries against the
     * `commerce_product_categories`/`commerce_product_tags` join tables (never a
     * `JOIN` on the main query) -- a product attached to several categories/tags
     * still contributes AT MOST ONE row, never one per matching join row.
     * Attribute pairs are one correlated `EXISTS` per pair against
     * `commerce_product_attributes` (unique on `(product_uuid, attribute_uuid)`,
     * so at most one candidate row per pair per product), testing value-slug
     * membership via {@see JsonStringArrayContainsSql} rather than a
     * substring-unsafe `LIKE`. Every filter composes with AND semantics.
     *
     * Also carries {@see self::applyBuyerAvailability()} (design spec §2.3,
     * MV5b) -- this is the storefront listing/search query, a buyer-context
     * read by construction, so `listActive()`'s count/row queries and every
     * category/tag/attribute semijoin above all inherit buyer availability
     * the same way {@see self::findBuyerAvailableByUuid()} does (subject to
     * the SAME master-off fast path: a no-op, zero `commerce_sellers`
     * reference, while the marketplace install switch is off). Switch on: a
     * no-op for active/sellerless products; a suspended/onboarding/closed
     * seller's products drop out of listing/search without a separate query.
     */
    public function activeFilteredQuery(
        ApplicationContext $context,
        string $tenant,
        ?ResolvedProductFilters $filters
    ): QueryBuilder {
        $query = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('status', '=', 'active')
            ->whereRaw('deleted_at IS NULL');
        $this->applyBuyerAvailability($query, $context, $tenant);

        if ($filters === null || $filters->isEmpty()) {
            return $query;
        }

        if ($filters->categoryUuid !== null) {
            $query->whereRaw(
                <<<'SQL'
EXISTS (
    SELECT 1 FROM commerce_product_categories
    WHERE commerce_product_categories.product_uuid = commerce_products.uuid
    AND commerce_product_categories.category_uuid = ?
)
SQL,
                [$filters->categoryUuid]
            );
        }

        if ($filters->tagUuid !== null) {
            $query->whereRaw(
                <<<'SQL'
EXISTS (
    SELECT 1 FROM commerce_product_tags
    WHERE commerce_product_tags.product_uuid = commerce_products.uuid
    AND commerce_product_tags.tag_uuid = ?
)
SQL,
                [$filters->tagUuid]
            );
        }

        $driver = db($context)->getDriverName();
        foreach ($filters->attributePairs as $pair) {
            $membership = JsonStringArrayContainsSql::condition(
                $driver,
                'commerce_product_attributes.values',
                $pair['value_slug']
            );

            $query->whereRaw(
                <<<SQL
EXISTS (
    SELECT 1 FROM commerce_product_attributes
    WHERE commerce_product_attributes.product_uuid = commerce_products.uuid
    AND commerce_product_attributes.attribute_uuid = ?
    AND ({$membership['sql']})
)
SQL,
                array_merge([$pair['attribute_uuid']], $membership['bindings'])
            );
        }

        return $query;
    }

    /**
     * Admin list projection (Layer 6 Global Constraints): replaces the raw
     * unbounded `db()->get()` AdminProductController::index() used to run.
     * `status`/`type` are exact (already vocabulary-validated at the DTO
     * boundary); `q` is a case-insensitive literal substring match on `name` via
     * {@see LiteralLike}. Tombstoned rows (`deleted_at IS NOT NULL`) are excluded
     * -- this is an interactive admin catalog surface, not a named
     * history/integrity read. Ordered `created_at DESC, uuid ASC` (stable
     * tie-break); count and row queries apply the identical predicate set.
     *
     * @param array<string,mixed> $filters 'status'/'type' (exact) and/or 'q' (literal substring on name)
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function paginatedForAdmin(
        ApplicationContext $context,
        string $tenant,
        array $filters,
        int $page,
        int $perPage
    ): array {
        $count = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->whereRaw('deleted_at IS NULL');
        $rows = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->whereRaw('deleted_at IS NULL');

        if (isset($filters['status']) && (string) $filters['status'] !== '') {
            $count->where('status', '=', (string) $filters['status']);
            $rows->where('status', '=', (string) $filters['status']);
        }
        if (isset($filters['type']) && (string) $filters['type'] !== '') {
            $count->where('type', '=', (string) $filters['type']);
            $rows->where('type', '=', (string) $filters['type']);
        }
        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $pattern = LiteralLike::pattern($q);
            $count->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
            $rows->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
        }

        $items = $rows->orderBy('created_at', 'DESC')
            ->orderBy('uuid', 'ASC')
            ->limit($perPage)
            ->offset(max(0, $page - 1) * $perPage)
            ->get();

        return [
            'items' => array_map(fn (array $row): array => $this->decodeJson($row), $items),
            'total' => $count->count(),
        ];
    }

    /**
     * Seller-scoped admin-shaped list projection (design spec §2.8, MV1 Task
     * 4): the SAME status/type/q filter set {@see self::paginatedForAdmin()}
     * supports, plus a mandatory `seller_uuid` predicate baked into BOTH the
     * count and row queries -- a seller can never see another seller's
     * products through this read, regardless of filters. Ordered
     * `created_at DESC, uuid ASC`, same tie-break as the admin projection.
     *
     * @param array<string,mixed> $filters 'status'/'type' (exact) and/or 'q' (literal substring on name)
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function paginatedForSeller(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        array $filters,
        int $page,
        int $perPage
    ): array {
        $count = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->whereRaw('deleted_at IS NULL');
        $rows = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->whereRaw('deleted_at IS NULL');

        if (isset($filters['status']) && (string) $filters['status'] !== '') {
            $count->where('status', '=', (string) $filters['status']);
            $rows->where('status', '=', (string) $filters['status']);
        }
        if (isset($filters['type']) && (string) $filters['type'] !== '') {
            $count->where('type', '=', (string) $filters['type']);
            $rows->where('type', '=', (string) $filters['type']);
        }
        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $pattern = LiteralLike::pattern($q);
            $count->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
            $rows->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
        }

        $items = $rows->orderBy('created_at', 'DESC')
            ->orderBy('uuid', 'ASC')
            ->limit($perPage)
            ->offset(max(0, $page - 1) * $perPage)
            ->get();

        return [
            'items' => array_map(fn (array $row): array => $this->decodeJson($row), $items),
            'total' => $count->count(),
        ];
    }

    /**
     * Affected-row-checked serialization primitive shared by every product-scoped
     * relationship/set-list mutation (media, categories, tags, attributes, children):
     * claim first via this `catalog_revision` bump, then re-read state and enforce
     * invariants — the claimed row lock is what actually serializes concurrent
     * mutations against the same product; the counter itself is just evidence.
     * Returns false for an unknown or cross-tenant product.
     */
    public function claimCatalogRevision(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $affected = db($context)->table('commerce_products')->executeModification(
            <<<'SQL'
UPDATE commerce_products
SET catalog_revision = catalog_revision + 1, updated_at = ?
WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [
                db($context)->getDriver()->formatDateTime(),
                $tenant,
                $uuid,
            ]
        );

        return $affected === 1;
    }

    /**
     * Affected-row-checked rating rollup primitive (design spec §5): a review
     * approval calls this with `(+rating, +1)`; an approved->spam reversal calls
     * it with `(-rating, -1)`. Raw SQL, not the fluent `update()`, so a
     * soft-deleted product's row is still reachable -- its own affected-row
     * result IS the "product still exists in this tenant" guard ReviewService
     * relies on to roll back a moderation claim when it returns false.
     */
    public function adjustRating(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        int $ratingDelta,
        int $countDelta
    ): bool {
        $affected = db($context)->table('commerce_products')->executeModification(
            <<<'SQL'
UPDATE commerce_products
SET rating_sum = rating_sum + ?, rating_count = rating_count + ?, updated_at = ?
WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [$ratingDelta, $countDelta, db($context)->getDriver()->formatDateTime(), $tenant, $uuid]
        );

        return $affected === 1;
    }

    /**
     * The marketplace checkout ownership snapshot (design spec §2.7): ONE
     * batched read of the CURRENT `seller_uuid` for every distinct product a
     * cart's priced lines reference -- {@see \Glueful\Extensions\Commerce\Orders\CheckoutService}
     * calls this TWICE inside its checkout transaction (the pre-claim
     * snapshot and the post-claim re-read) to detect ownership drift. Uses
     * the framework's default live-row filtering (a soft-deleted product
     * silently drops out of the result, same as {@see self::findLiveByUuid()});
     * every product reaching this call already resolved live via
     * `CartService::pricedLines()`, so a missing key here signals a genuine
     * concurrent tombstone, not the normal case.
     *
     * @param list<string> $uuids
     * @return array<string,?string> product_uuid => seller_uuid; a key
     *   absent from the result means the product no longer resolves live
     */
    public function sellerSnapshot(ApplicationContext $context, string $tenant, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $rows = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->whereIn('uuid', $uuids)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['uuid']] = $row['seller_uuid'] !== null ? (string) $row['seller_uuid'] : null;
        }

        return $result;
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        $changes['updated_at'] = db($context)->getDriver()->formatDateTime();

        db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($this->encodeJson($changes));
    }

    /**
     * The activation adoption gate read (design spec §2.2): count of live
     * (non-deleted) products for the tenant that carry no `seller_uuid`.
     * {@see \Glueful\Extensions\Commerce\Marketplace\MarketplaceActivationService::activate()}
     * calls this AFTER any optional default-seller bulk-adopt -- a non-zero
     * result blocks activation.
     */
    public function unassignedCount(ApplicationContext $context, string $tenant): int
    {
        return db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->whereRaw('deleted_at IS NULL')
            ->whereRaw('seller_uuid IS NULL')
            ->count();
    }

    /**
     * Sorted uuid list of every live, unassigned product for the tenant --
     * design spec §4 lock order ("product catalog_revision claims sorted by
     * product UUID"): {@see \Glueful\Extensions\Commerce\Marketplace\MarketplaceActivationService::activate()}
     * claims each of these, in this exact order, before running
     * {@see self::bulkAdoptUnassigned()}.
     *
     * @return list<string>
     */
    public function liveUnassignedUuids(ApplicationContext $context, string $tenant): array
    {
        $rows = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->whereRaw('deleted_at IS NULL')
            ->whereRaw('seller_uuid IS NULL')
            ->orderBy('uuid', 'ASC')
            ->get();

        return array_map(static fn (array $row): string => (string) $row['uuid'], $rows);
    }

    /**
     * The activation bulk-adopt write (design spec §2.2): assigns EVERY
     * currently-unassigned, live product for the tenant to $sellerUuid in
     * ONE statement. MUST run inside the SAME transaction as -- and after --
     * the per-product `catalog_revision` claims {@see self::liveUnassignedUuids()}
     * enumerated; those claims are what serialize this write against any
     * other product-scoped mutation racing the same rows (design spec §4
     * lock order). This write does not bump `catalog_revision` again -- the
     * claim already did that exactly once per product.
     */
    public function bulkAdoptUnassigned(ApplicationContext $context, string $tenant, string $sellerUuid): int
    {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        return db($context)->table('commerce_products')->executeModification(
            <<<SQL
UPDATE commerce_products
SET seller_uuid = ?, updated_at = {$utcNow}
WHERE tenant_uuid = ? AND seller_uuid IS NULL AND deleted_at IS NULL
SQL,
            [$sellerUuid, $tenant]
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function encodeJson(array $row): array
    {
        foreach (['options', 'metadata'] as $column) {
            if (isset($row[$column]) && is_array($row[$column])) {
                $row[$column] = json_encode($row[$column], JSON_THROW_ON_ERROR);
            }
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeJson(array $row): array
    {
        foreach (['options', 'metadata'] as $column) {
            if (isset($row[$column]) && is_string($row[$column]) && $row[$column] !== '') {
                $decoded = json_decode($row[$column], true);
                $row[$column] = is_array($decoded) ? $decoded : null;
            }
        }

        return $row;
    }
}
