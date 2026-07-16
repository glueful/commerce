<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Shipping;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\OpenVocabularySlug;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Shipping class CRUD (design spec §2, §6).
 *
 * The class `slug` is an immutable pricing identity once created:
 * `per_class_table` shipping-method config references classes BY SLUG while
 * variants retain the class UUID (migration 009's docblock / spec §2), so
 * permitting a slug rename without rewriting every method configuration would
 * silently change live shipping charges. PATCH therefore accepts `name` only --
 * a `slug` key present ANYWHERE in the PATCH payload, even set to its own
 * current value, is rejected with 422 rather than silently ignored (unlike
 * `ShippingZoneService::updateMethod()`'s immutable-but-silently-dropped `kind`
 * key: the spec here calls for a loud rejection instead).
 *
 * Claim discipline: `commerce_shipping_classes` carries its own revision column.
 * PATCH/DELETE claim it directly (URL's primary resource; a failed claim is a
 * non-revealing 404). Variant shipping-class assignment (the §6 shared-claim
 * protocol, {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::updateVariant()})
 * claims the SAME row from the other side, so a class-delete-vs-variant-assign
 * race has one winner: whichever transaction's claim UPDATE commits first wins
 * the row lock. If assignment wins, delete's subsequent claim still succeeds
 * (the row survives) but its post-claim reference re-check now sees the fresh
 * assignment and refuses with 409. If delete wins the claim race, its post-claim
 * re-check runs against whatever the variant referenced BEFORE the blocked
 * assignment could write anything -- so it either refuses (variant still points
 * here) or proceeds (nothing pointed here), and only afterward does the blocked
 * assignment's own claim resolve: it succeeds (delete refused, row intact) or
 * fails 0-rows (delete proceeded, class gone -- assignment then 422s on the
 * proposed class not existing).
 *
 * DELETE is refused with a 409 ({@see ShippingClassInUseException}) while ANY
 * variant currently references the class -- mirrors the blob-deletion posture
 * (explicit detach first; no silent nulling of the variant's assignment).
 */
final class ShippingClassService
{
    public function __construct(
        private ShippingClassRepository $classes,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /**
     * @param array<string,mixed> $filters 'q' (literal substring on name/slug)
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function list(ApplicationContext $c, array $filters, int $page, int $perPage): array
    {
        return $this->classes->paginatedFor($c, $this->tenants->tenantUuid($c), $filters, $page, $perPage);
    }

    /** @return array<string,mixed> */
    public function show(ApplicationContext $c, string $uuid): array
    {
        $class = $this->classes->findByUuid($c, $this->tenants->tenantUuid($c), $uuid);
        if ($class === null) {
            throw new NotFoundException('Resource not found.');
        }

        return $class;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(ApplicationContext $c, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $slug = OpenVocabularySlug::normalize($this->requiredString($input, 'slug'), 'slug');
        $name = $this->requiredString($input, 'name');

        if ($this->classes->findBySlug($c, $tenant, $slug) !== null) {
            throw ValidationException::forField('slug', 'Slug already in use.');
        }

        $uuid = Utils::generateNanoID();
        $this->classes->insert($c, [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
        ]);

        $class = $this->classes->findByUuid($c, $tenant, $uuid);
        if ($class === null) {
            throw new \RuntimeException('Created shipping class could not be reloaded.');
        }

        return $class;
    }

    /**
     * @param array<string,mixed> $changes `name` only -- see class docblock for the
     *   `slug` immutability rejection
     * @return array<string,mixed>
     */
    public function update(ApplicationContext $c, string $uuid, array $changes): array
    {
        if (array_key_exists('slug', $changes)) {
            throw ValidationException::forField('slug', 'slug is immutable and cannot be changed after creation.');
        }

        $tenant = $this->tenants->tenantUuid($c);

        return db($c)->transaction(function () use ($c, $tenant, $uuid, $changes): array {
            if (!$this->classes->claimRevision($c, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->classes->findByUuid($c, $tenant, $uuid);
            if ($current === null) {
                throw new NotFoundException('Resource not found.');
            }

            $set = [];
            if (array_key_exists('name', $changes) && $changes['name'] !== null) {
                $name = trim((string) $changes['name']);
                if ($name === '') {
                    throw ValidationException::forField('name', 'Name is required.');
                }
                $set['name'] = $name;
            }

            if ($set !== []) {
                $this->classes->update($c, $tenant, $uuid, $set);
            }

            $class = $this->classes->findByUuid($c, $tenant, $uuid);
            if ($class === null) {
                throw new \RuntimeException('Updated shipping class could not be reloaded.');
            }

            return $class;
        });
    }

    /**
     * Claims the class, then -- post-claim, inside the same transaction -- checks
     * whether any variant STILL references it (see class docblock for the full
     * race analysis). A concurrent variant-assign either lands first (our
     * re-check then sees the reference and refuses) or blocks on our held claim
     * (and resolves only after we commit).
     */
    public function delete(ApplicationContext $c, string $uuid): void
    {
        $tenant = $this->tenants->tenantUuid($c);

        db($c)->transaction(function () use ($c, $tenant, $uuid): void {
            if (!$this->classes->claimRevision($c, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }

            if ($this->classes->findByUuid($c, $tenant, $uuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            if ($this->classes->isReferencedByVariant($c, $uuid)) {
                throw new ShippingClassInUseException(
                    'This shipping class is still assigned to one or more variants. Detach it first.'
                );
            }

            $this->classes->delete($c, $tenant, $uuid);
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
}
