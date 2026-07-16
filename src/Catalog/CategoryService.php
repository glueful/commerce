<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Repository\BlobRepository;
use Glueful\Validation\ValidationException;

/**
 * Category tree CRUD plus product↔category set-list assignment.
 *
 * Tree invariants (parent exists in-tenant, no cycles, max depth 6) are only ever
 * checked from POST-CLAIM re-reads — a pre-claim read is a snapshot used solely to
 * discover which rows to claim, never trusted for a business decision (see
 * ProductMediaService's docblock for the same discipline).
 *
 * Claim discipline: every mutation claims its own row first (revision bump,
 * affected-row-checked). A failed claim on the uuid NAMED IN THE URL (update,
 * delete) is a non-revealing 404 — the target vanished or belongs to another
 * tenant. A failed claim on a REFERENCED body field (parent_uuid on create/reparent)
 * is a 422 on that field instead, mirroring how ProductMediaService treats a bad
 * variant_uuid. create-with-parent additionally claims the parent FIRST (a
 * child-attach event on it), and reparent/delete claim multiple rows in
 * sorted-uuid order to avoid deadlocking against another category mutation
 * claiming the same rows in the opposite order.
 *
 * Ancestor-chain claim (create-with-parent and reparent): the depth check depends
 * on the (new) parent's FULL ancestor chain, not just the parent row itself. Both
 * mutations therefore (1) snapshot the parent's ancestor chain BEFORE the
 * transaction opens (a pre-claim read, same discipline as above), (2) claim the
 * parent PLUS every snapshotted ancestor (sorted-uuid order) so a concurrent
 * mutation sharing any of those rows serializes against this one instead of
 * racing it, and (3) after claiming, re-read the parent's ancestor chain fresh and
 * compare its uuid SET to the snapshot. A mismatch means the snapshot was already
 * stale by the time we started claiming (so our locks don't cover the tree's
 * actual current shape) and throws ConcurrentCatalogMutationException — a
 * retryable 409, mapped in AdminCategoryController exactly like
 * AdminRefundController maps ConcurrentRefundException — rather than trusting
 * unlocked rows for the depth/cycle checks that follow. A claim failure on one of
 * the snapshotted ancestors (as opposed to the parent uuid itself) is the same
 * signal — an ancestor vanished concurrently — and throws the same exception
 * rather than a 422, since the ancestor uuid was never client-supplied.
 */
final class CategoryService
{
    private const MAX_DEPTH = 6;

    public function __construct(
        private CategoryRepository $categories,
        private ProductRepository $products,
        private CurrentTenantResolver $tenants,
        private ?BlobRepository $blobs = null,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(ApplicationContext $c): array
    {
        return $this->categories->all($c, $this->tenants->tenantUuid($c));
    }

    /** @return array<string,mixed> */
    public function show(ApplicationContext $c, string $uuid): array
    {
        $category = $this->categories->findByUuid($c, $this->tenants->tenantUuid($c), $uuid);
        if ($category === null) {
            throw new NotFoundException('Resource not found.');
        }

        return $category;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(ApplicationContext $c, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $slug = $this->requiredString($input, 'slug');
        $name = $this->requiredString($input, 'name');
        $parentUuid = $this->normalizeNullableUuid($input['parent_uuid'] ?? null);
        $blobUuid = $this->normalizeNullableUuid($input['blob_uuid'] ?? null);

        if ($blobUuid !== null) {
            $this->assertBlobAttachable($blobUuid);
        }

        // Pre-claim snapshot of the parent's ancestor chain -- discovery only,
        // never trusted for the depth decision (see class docblock); it only
        // determines which extra rows join the claim set below.
        $ancestorSnapshot = $parentUuid !== null ? $this->ancestorChain($c, $tenant, $parentUuid) : [];

        return db($c)->transaction(
            function () use ($c, $tenant, $slug, $name, $parentUuid, $blobUuid, $input, $ancestorSnapshot): array {
                $depth = 1;

                if ($parentUuid !== null) {
                    // create-with-parent is a child-attach event on the parent AND on
                    // every one of its snapshotted ancestors: claim all of them
                    // (sorted) FIRST so a concurrent mutation sharing any of those
                    // rows -- a parent delete, or a disjoint reparent elsewhere on the
                    // same root-to-leaf path -- serializes against this one instead of
                    // racing it. A failed claim on the parent itself collapses into
                    // the same non-revealing 422 as before; a failed claim on one of
                    // its ancestors means the ancestry changed concurrently.
                    $claimSet = array_values(array_unique(array_merge([$parentUuid], $ancestorSnapshot)));
                    sort($claimSet);

                    foreach ($claimSet as $claimUuid) {
                        if ($this->categories->claimRevision($c, $tenant, $claimUuid)) {
                            continue;
                        }
                        if ($claimUuid === $parentUuid) {
                            throw ValidationException::forField(
                                'parent_uuid',
                                'parent_uuid must reference an existing category.'
                            );
                        }
                        throw new ConcurrentCatalogMutationException(
                            'Category ancestry changed concurrently; retry.'
                        );
                    }

                    $freshAncestors = $this->ancestorChain($c, $tenant, $parentUuid);
                    if ($this->ancestrySetChanged($ancestorSnapshot, $freshAncestors)) {
                        throw new ConcurrentCatalogMutationException(
                            'Category ancestry changed concurrently; retry.'
                        );
                    }

                    $depth = $this->depthOf($c, $tenant, $parentUuid) + 1;
                }

                if ($depth > self::MAX_DEPTH) {
                    throw ValidationException::forField(
                        'parent_uuid',
                        'Category tree depth cannot exceed ' . self::MAX_DEPTH . '.'
                    );
                }

                if ($this->categories->findBySlug($c, $tenant, $slug) !== null) {
                    throw ValidationException::forField('slug', 'Slug already in use.');
                }

                $uuid = Utils::generateNanoID();
                $this->categories->insert($c, [
                    'uuid' => $uuid,
                    'tenant_uuid' => $tenant,
                    'parent_uuid' => $parentUuid,
                    'slug' => $slug,
                    'name' => $name,
                    'description' => isset($input['description']) ? (string) $input['description'] : null,
                    'position' => isset($input['position']) ? (int) $input['position'] : 0,
                    'blob_uuid' => $blobUuid,
                ]);

                $category = $this->categories->findByUuid($c, $tenant, $uuid);
                if ($category === null) {
                    throw new \RuntimeException('Created category could not be reloaded.');
                }

                return $category;
            }
        );
    }

    /**
     * @param array<string,mixed> $changes name/slug/description/position/blob_uuid/parent_uuid —
     *   only present keys are applied; parent_uuid presence (even as null) triggers reparenting.
     * @return array<string,mixed>
     */
    public function update(ApplicationContext $c, string $uuid, array $changes): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $reparenting = array_key_exists('parent_uuid', $changes);
        $newParentUuid = $reparenting ? $this->normalizeNullableUuid($changes['parent_uuid']) : null;

        if (array_key_exists('blob_uuid', $changes) && $changes['blob_uuid'] !== null) {
            $this->assertBlobAttachable((string) $changes['blob_uuid']);
        }

        // Pre-claim snapshot of the new parent's ancestor chain -- same discipline
        // as create-with-parent (see class docblock); only reparenting to a
        // non-null parent has an ancestor chain to protect.
        $ancestorSnapshot = ($reparenting && $newParentUuid !== null)
            ? $this->ancestorChain($c, $tenant, $newParentUuid)
            : [];

        return db($c)->transaction(
            function () use ($c, $tenant, $uuid, $changes, $reparenting, $newParentUuid, $ancestorSnapshot): array {
                $claimSet = array_values(array_unique(array_merge(
                    array_filter([$uuid, $newParentUuid], static fn (?string $value): bool => $value !== null),
                    $ancestorSnapshot
                )));
                sort($claimSet);

                foreach ($claimSet as $claimUuid) {
                    if ($this->categories->claimRevision($c, $tenant, $claimUuid)) {
                        continue;
                    }
                    if ($claimUuid === $uuid) {
                        throw new NotFoundException('Resource not found.');
                    }
                    if ($claimUuid === $newParentUuid) {
                        throw ValidationException::forField(
                            'parent_uuid',
                            'parent_uuid must reference an existing category.'
                        );
                    }
                    // A snapshotted ancestor, not $uuid or $newParentUuid itself,
                    // failed to claim -- the ancestry changed concurrently.
                    throw new ConcurrentCatalogMutationException(
                        'Category ancestry changed concurrently; retry.'
                    );
                }

                if ($reparenting && $newParentUuid !== null) {
                    $freshAncestors = $this->ancestorChain($c, $tenant, $newParentUuid);
                    if ($this->ancestrySetChanged($ancestorSnapshot, $freshAncestors)) {
                        throw new ConcurrentCatalogMutationException(
                            'Category ancestry changed concurrently; retry.'
                        );
                    }
                }

                $current = $this->categories->findByUuid($c, $tenant, $uuid);
                if ($current === null) {
                    throw new NotFoundException('Resource not found.');
                }

                $set = $this->planFieldChanges($c, $tenant, $uuid, $changes);

                if ($reparenting) {
                    if ($newParentUuid !== null && $this->isSelfOrDescendant($c, $tenant, $newParentUuid, $uuid)) {
                        throw ValidationException::forField(
                            'parent_uuid',
                            'parent_uuid would create a cycle in the category tree.'
                        );
                    }

                    $newDepth = $newParentUuid === null ? 1 : $this->depthOf($c, $tenant, $newParentUuid) + 1;
                    $subtreeHeight = $this->subtreeHeight($c, $tenant, $uuid);
                    if ($newDepth + $subtreeHeight > self::MAX_DEPTH) {
                        throw ValidationException::forField(
                            'parent_uuid',
                            'Reparenting would exceed the maximum category depth of ' . self::MAX_DEPTH . '.'
                        );
                    }

                    $set['parent_uuid'] = $newParentUuid;
                }

                if ($set !== []) {
                    $this->categories->update($c, $tenant, $uuid, $set);
                }

                $category = $this->categories->findByUuid($c, $tenant, $uuid);
                if ($category === null) {
                    throw new \RuntimeException('Updated category could not be reloaded.');
                }

                return $category;
            }
        );
    }

    /**
     * Claims target + parent + direct children (sorted), re-reads, then re-parents
     * children to the deleted node's parent, detaches product joins, and deletes —
     * all inside one transaction. A concurrent child-attach cannot land on this
     * category once we hold its claim: the attach's own claim on this same row
     * either blocks until we commit (then fails, 0 rows) or, if it committed first,
     * its new child is simply included in our fresh re-read and reparented normally.
     */
    public function delete(ApplicationContext $c, string $uuid): void
    {
        $tenant = $this->tenants->tenantUuid($c);

        // Read-only snapshot purely to discover which rows to claim; re-read fresh
        // after claiming before making any decision.
        $peek = $this->categories->findByUuid($c, $tenant, $uuid);
        if ($peek === null) {
            throw new NotFoundException('Resource not found.');
        }
        $peekParentUuid = $peek['parent_uuid'] ?? null;
        $peekChildUuids = array_map(
            static fn (array $row): string => (string) $row['uuid'],
            $this->categories->children($c, $tenant, $uuid)
        );

        $claimSet = array_values(array_unique(array_merge(
            [$uuid],
            $peekParentUuid !== null ? [(string) $peekParentUuid] : [],
            $peekChildUuids
        )));
        sort($claimSet);

        db($c)->transaction(function () use ($c, $tenant, $uuid, $claimSet): void {
            foreach ($claimSet as $claimUuid) {
                if (!$this->categories->claimRevision($c, $tenant, $claimUuid)) {
                    throw new NotFoundException('Resource not found.');
                }
            }

            $category = $this->categories->findByUuid($c, $tenant, $uuid);
            if ($category === null) {
                throw new NotFoundException('Resource not found.');
            }
            $parentUuid = $category['parent_uuid'] ?? null;

            $this->categories->reparentChildren($c, $tenant, $uuid, $parentUuid);
            $this->categories->detachProducts($c, $uuid);
            $this->categories->delete($c, $tenant, $uuid);
        });
    }

    /**
     * Idempotent set-list replace: claims the PRODUCT first (the URL's primary
     * resource — a failed claim is a non-revealing 404), then resolves every
     * proposed category in-tenant (a failed resolution is a 422 on
     * category_uuids). Categories currently attached but no longer proposed are
     * simply detached; issuing the same list twice is a no-op (no unnecessary
     * churn on unchanged pairs).
     *
     * @param list<string> $categoryUuids
     * @return list<array<string,mixed>>
     */
    public function setProductCategories(ApplicationContext $c, string $productUuid, array $categoryUuids): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        return db($c)->transaction(function () use ($c, $tenant, $productUuid, $categoryUuids): array {
            if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }
            if ($this->products->findLiveByUuid($c, $tenant, $productUuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $proposed = $this->normalizeUuidList($categoryUuids, 'category_uuids');
            $current = $this->categories->categoryUuidsForProduct($c, $productUuid);

            $union = array_values(array_unique(array_merge($current, $proposed)));
            sort($union);

            $claimed = [];
            foreach ($union as $categoryUuid) {
                $claimed[$categoryUuid] = $this->categories->claimRevision($c, $tenant, $categoryUuid);
            }

            foreach ($proposed as $categoryUuid) {
                if (!($claimed[$categoryUuid] ?? false)) {
                    throw ValidationException::forField(
                        'category_uuids',
                        'category_uuids must reference existing categories in this tenant.'
                    );
                }
            }

            foreach (array_diff($proposed, $current) as $categoryUuid) {
                $this->categories->attachProduct($c, $productUuid, $categoryUuid);
            }
            foreach (array_diff($current, $proposed) as $categoryUuid) {
                $this->categories->detachProduct($c, $productUuid, $categoryUuid);
            }

            return $this->categories->categoriesForProduct($c, $tenant, $productUuid);
        });
    }

    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function planFieldChanges(ApplicationContext $c, string $tenant, string $uuid, array $changes): array
    {
        $set = [];

        if (array_key_exists('slug', $changes) && $changes['slug'] !== null) {
            $slug = trim((string) $changes['slug']);
            $existing = $this->categories->findBySlug($c, $tenant, $slug);
            if ($existing !== null && (string) $existing['uuid'] !== $uuid) {
                throw ValidationException::forField('slug', 'Slug already in use.');
            }
            $set['slug'] = $slug;
        }
        if (array_key_exists('name', $changes) && $changes['name'] !== null) {
            $set['name'] = (string) $changes['name'];
        }
        if (array_key_exists('description', $changes)) {
            $set['description'] = $changes['description'] !== null ? (string) $changes['description'] : null;
        }
        if (array_key_exists('position', $changes) && $changes['position'] !== null) {
            $set['position'] = (int) $changes['position'];
        }
        if (array_key_exists('blob_uuid', $changes)) {
            $set['blob_uuid'] = $changes['blob_uuid'] !== null ? (string) $changes['blob_uuid'] : null;
        }

        return $set;
    }

    /**
     * $uuid's ancestors, walking parent_uuid up from $uuid to the root — excludes
     * $uuid itself, includes the root. Used both to build a create-with-parent/
     * reparent mutation's claim set (a pre-transaction snapshot) and, post-claim,
     * to verify the actual chain still matches what was claimed (see class
     * docblock's ancestor-chain-claim discipline).
     *
     * @return list<string>
     */
    private function ancestorChain(ApplicationContext $c, string $tenant, string $uuid): array
    {
        $chain = [];
        $current = $uuid;
        $guard = 0;
        while ($guard <= self::MAX_DEPTH + 1) {
            $row = $this->categories->findByUuid($c, $tenant, $current);
            if ($row === null) {
                break;
            }
            $parentUuid = $row['parent_uuid'] ?? null;
            if ($parentUuid === null) {
                break;
            }
            $chain[] = (string) $parentUuid;
            $current = (string) $parentUuid;
            $guard++;
        }

        return $chain;
    }

    /**
     * True when the two ancestor-chain snapshots differ as SETS (order-independent
     * — only membership matters for the depth/cycle checks that follow).
     *
     * @param list<string> $before
     * @param list<string> $after
     */
    private function ancestrySetChanged(array $before, array $after): bool
    {
        sort($before);
        sort($after);

        return $before !== $after;
    }

    /** Depth of $uuid: root = 1, each level below adds 1. */
    private function depthOf(ApplicationContext $c, string $tenant, string $uuid): int
    {
        $depth = 0;
        $current = $uuid;
        $guard = 0;
        while ($current !== null && $guard <= self::MAX_DEPTH + 1) {
            $depth++;
            $row = $this->categories->findByUuid($c, $tenant, $current);
            if ($row === null) {
                break;
            }
            $current = $row['parent_uuid'] ?? null;
            $guard++;
        }

        return $depth;
    }

    /**
     * True when $candidateUuid equals $nodeUuid, or lies within $nodeUuid's subtree
     * (walking UP from $candidateUuid eventually reaches $nodeUuid). Used to reject
     * both self-parenting and deeper cycles when reparenting $nodeUuid under
     * $candidateUuid.
     */
    private function isSelfOrDescendant(
        ApplicationContext $c,
        string $tenant,
        string $candidateUuid,
        string $nodeUuid
    ): bool {
        $current = $candidateUuid;
        $guard = 0;
        while ($current !== null && $guard <= self::MAX_DEPTH + 2) {
            if ($current === $nodeUuid) {
                return true;
            }
            $row = $this->categories->findByUuid($c, $tenant, $current);
            if ($row === null) {
                return false;
            }
            $current = $row['parent_uuid'] ?? null;
            $guard++;
        }

        return false;
    }

    /** Max relative depth of $uuid's subtree: 0 for a leaf, else 1+max(child subtree heights). */
    private function subtreeHeight(ApplicationContext $c, string $tenant, string $uuid, int $guard = 0): int
    {
        if ($guard > self::MAX_DEPTH + 2) {
            return 0; // defensive cutoff; a correctly maintained tree never gets here
        }

        $children = $this->categories->children($c, $tenant, $uuid);
        if ($children === []) {
            return 0;
        }

        $max = 0;
        foreach ($children as $child) {
            $height = 1 + $this->subtreeHeight($c, $tenant, (string) $child['uuid'], $guard + 1);
            $max = max($max, $height);
        }

        return $max;
    }

    private function assertBlobAttachable(string $blobUuid): void
    {
        if ($this->blobs === null) {
            throw ValidationException::forField(
                'blob_uuid',
                'Category images require the blobs subsystem, which is not available.'
            );
        }

        $blob = $this->blobs->findByUuid($blobUuid);
        if (
            $blob === null
            || ($blob['status'] ?? null) !== 'active'
            || ($blob['visibility'] ?? null) !== 'public'
        ) {
            throw ValidationException::forField(
                'blob_uuid',
                'blob_uuid must reference an existing, active, public blob.'
            );
        }
    }

    /** @param array<string,mixed> $input */
    private function requiredString(array $input, string $field): string
    {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value === '') {
            throw ValidationException::forField($field, ucfirst($field) . ' is required.');
        }

        return $value;
    }

    private function normalizeNullableUuid(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param mixed $raw
     * @return list<string> unique, trimmed, non-empty uuids
     */
    private function normalizeUuidList(mixed $raw, string $field): array
    {
        if (!is_array($raw)) {
            throw ValidationException::forField($field, "{$field} must be an array of uuids.");
        }

        $result = [];
        foreach ($raw as $index => $value) {
            if (!is_string($value) || trim($value) === '') {
                throw ValidationException::forField($field, "{$field}.{$index} must be a non-empty string.");
            }
            $result[] = trim($value);
        }

        return array_values(array_unique($result));
    }
}
