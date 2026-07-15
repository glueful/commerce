<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Tag CRUD (create/list/delete) plus product↔tag set-list assignment.
 *
 * Tags carry no tree structure, so unlike categories the product/tag set-list only
 * claims the PRODUCT and resolves proposed tags by a plain in-tenant lookup — no
 * per-tag claim. Tag DELETE still claims the tag itself (revision bump,
 * affected-row-checked) before detaching its product joins and deleting, so a
 * delete-vs-assignment race has one winner: either the tag is gone before
 * assignment resolves it (422, non-revealing) or the assignment's join row is
 * created before the delete's detach step removes it again.
 */
final class TagService
{
    public function __construct(
        private TagRepository $tags,
        private ProductRepository $products,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(ApplicationContext $c): array
    {
        return $this->tags->all($c, $this->tenants->tenantUuid($c));
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

        if ($this->tags->findBySlug($c, $tenant, $slug) !== null) {
            throw ValidationException::forField('slug', 'Slug already in use.');
        }

        $uuid = Utils::generateNanoID();
        $this->tags->insert($c, [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
        ]);

        $tag = $this->tags->findByUuid($c, $tenant, $uuid);
        if ($tag === null) {
            throw new \RuntimeException('Created tag could not be reloaded.');
        }

        return $tag;
    }

    public function delete(ApplicationContext $c, string $uuid): void
    {
        $tenant = $this->tenants->tenantUuid($c);

        db($c)->transaction(function () use ($c, $tenant, $uuid): void {
            if (!$this->tags->claimRevision($c, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }
            if ($this->tags->findByUuid($c, $tenant, $uuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $this->tags->detachProducts($c, $uuid);
            $this->tags->delete($c, $tenant, $uuid);
        });
    }

    /**
     * Idempotent set-list replace: claims the PRODUCT only. A failed claim is a
     * non-revealing 404 (URL's primary resource); a proposed tag that doesn't
     * resolve in-tenant is a 422 on tag_uuids. Issuing the same list twice is a
     * no-op on already-matching pairs.
     *
     * @param list<string> $tagUuids
     * @return list<array<string,mixed>>
     */
    public function setProductTags(ApplicationContext $c, string $productUuid, array $tagUuids): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        return db($c)->transaction(function () use ($c, $tenant, $productUuid, $tagUuids): array {
            if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }
            if ($this->products->findByUuid($c, $tenant, $productUuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $proposed = $this->normalizeUuidList($tagUuids, 'tag_uuids');
            $current = $this->tags->tagUuidsForProduct($c, $productUuid);

            foreach ($proposed as $tagUuid) {
                if ($this->tags->findByUuid($c, $tenant, $tagUuid) === null) {
                    throw ValidationException::forField(
                        'tag_uuids',
                        'tag_uuids must reference existing tags in this tenant.'
                    );
                }
            }

            foreach (array_diff($proposed, $current) as $tagUuid) {
                $this->tags->attachProduct($c, $productUuid, $tagUuid);
            }
            foreach (array_diff($current, $proposed) as $tagUuid) {
                $this->tags->detachProduct($c, $productUuid, $tagUuid);
            }

            return $this->tags->tagsForProduct($c, $tenant, $productUuid);
        });
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
