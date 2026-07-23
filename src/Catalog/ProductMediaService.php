<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Repository\BlobRepository;
use Glueful\Validation\ValidationException;

/**
 * Product media (gallery/cover) attach/update/detach/reorder.
 *
 * Every mutation claims the product's `catalog_revision` FIRST (affected-row-checked
 * UPDATE) inside the transaction, then re-reads media/variant state before enforcing
 * any invariant — the claimed row lock is the real serialization primitive (see
 * ProductRepository::claimCatalogRevision docs); reads taken before the claim are
 * snapshots only and are never trusted for a business decision.
 *
 * Cover invariant: at most one cover per product. Attaching or promoting a new cover
 * DEMOTES the existing cover to gallery in the same transaction — deterministic, not
 * rejected.
 *
 * Blob validation is soft: `BlobRepository` (framework core) is resolved by the
 * service provider only if the container has it bound. When unresolvable, every
 * attach fails 422 with a message naming the missing subsystem rather than crashing.
 */
final class ProductMediaService
{
    public function __construct(
        private ProductRepository $products,
        private VariantRepository $variants,
        private ProductMediaRepository $media,
        private CurrentTenantResolver $tenants,
        private ?BlobRepository $blobs = null,
        private ?StorefrontCatalogChangeDispatcher $catalogEvents = null,
    ) {
    }

    /**
     * Product->media read (single-page product editor plan, Task A3):
     * `{revision, items}` envelope (Global Constraints) -- `items` is the
     * whitelisted `{uuid, blob_uuid, role, position, alt, variant_uuid}`
     * projection of every `commerce_product_media` row for the product,
     * position-ordered.
     *
     * `catalogRevision()` reads `revision` FIRST, before `items` is queried, for
     * the same reason documented in full on {@see CategoryService::forProduct()}
     * (not repeated here to avoid drift between two copies of the same
     * reasoning): a concurrent write landing between the two reads only ever
     * costs a later CAS save (Task A5) a harmless 409, never a false pass. It is
     * also the 404 guard -- null (unknown uuid, cross-tenant uuid, or a
     * tombstoned product) is the same non-revealing 404 every write endpoint on
     * this product uses.
     *
     * @return array{revision: int, items: list<array{
     *     uuid: string, blob_uuid: string, role: string, position: int,
     *     alt: ?string, variant_uuid: ?string
     * }>}
     */
    public function forProduct(ApplicationContext $c, string $productUuid): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        $revision = $this->products->catalogRevision($c, $tenant, $productUuid);
        if ($revision === null) {
            throw new NotFoundException('Resource not found.');
        }

        return [
            'revision' => $revision,
            'items' => $this->media->mediaProjectionsForProduct($c, $tenant, $productUuid),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function attach(ApplicationContext $c, string $productUuid, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        return db($c)->transaction(function () use ($c, $tenant, $productUuid, $input): array {
            if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            // Post-claim re-read: the product must still exist under this tenant now
            // that we hold its claim (the pre-claim caller never checked this).
            if ($this->products->findLiveByUuid($c, $tenant, $productUuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $blobUuid = trim((string) ($input['blob_uuid'] ?? ''));
            $this->assertBlobAttachable($blobUuid);

            $variantUuid = $this->normalizeVariantUuid($input);
            if ($variantUuid !== null) {
                $this->assertVariantBelongsToProduct($c, $tenant, $productUuid, $variantUuid);
            }

            $role = $this->normalizeRole($input['role'] ?? 'gallery');

            $existing = $this->media->forProduct($c, $tenant, $productUuid);
            foreach ($existing as $row) {
                if ((string) $row['blob_uuid'] === $blobUuid) {
                    throw ValidationException::forField('blob_uuid', 'This blob is already attached to the product.');
                }
            }

            if ($role === 'cover') {
                $this->media->demoteCover($c, $tenant, $productUuid);
            }

            $uuid = Utils::generateNanoID();
            $this->media->insert($c, [
                'uuid' => $uuid,
                'tenant_uuid' => $tenant,
                'product_uuid' => $productUuid,
                'variant_uuid' => $variantUuid,
                'blob_uuid' => $blobUuid,
                'role' => $role,
                'position' => isset($input['position']) ? (int) $input['position'] : count($existing),
                'alt' => isset($input['alt']) && $input['alt'] !== null ? (string) $input['alt'] : null,
            ]);

            $row = $this->media->findByUuid($c, $tenant, $uuid);
            if ($row === null) {
                throw new \RuntimeException('Created media row could not be reloaded.');
            }

            $this->catalogEvents?->dispatch($c, $tenant, $productUuid, StorefrontCatalogChanged::REASON_MEDIA_CHANGED);

            return $row;
        });
    }

    /**
     * @param array<string,mixed> $changes role/alt/position — only present keys are applied
     * @return array<string,mixed>
     */
    public function update(ApplicationContext $c, string $mediaUuid, array $changes): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        // Tenant-scoped snapshot read purely to discover which product to claim — the
        // URL carries no product uuid. Never used to decide anything by itself; the
        // transaction re-reads this row again immediately after the claim succeeds.
        $peek = $this->media->findByUuid($c, $tenant, $mediaUuid);
        if ($peek === null) {
            throw new NotFoundException('Resource not found.');
        }
        $productUuid = (string) $peek['product_uuid'];

        return db($c)->transaction(function () use ($c, $tenant, $productUuid, $mediaUuid, $changes): array {
            if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->media->findByUuid($c, $tenant, $mediaUuid);
            if ($current === null) {
                throw new NotFoundException('Resource not found.');
            }

            $set = [];
            if (array_key_exists('alt', $changes)) {
                $set['alt'] = $changes['alt'] !== null ? (string) $changes['alt'] : null;
            }
            if (array_key_exists('position', $changes)) {
                if (!is_int($changes['position']) || $changes['position'] < 0) {
                    throw ValidationException::forField('position', 'position must be a non-negative integer.');
                }
                $set['position'] = $changes['position'];
            }
            if (array_key_exists('role', $changes)) {
                $role = $this->normalizeRole($changes['role']);
                if ($role === 'cover' && (string) $current['role'] !== 'cover') {
                    $this->media->demoteCover($c, $tenant, $productUuid, $mediaUuid);
                }
                $set['role'] = $role;
            }

            if ($set !== []) {
                $this->media->update($c, $tenant, $mediaUuid, $set);
                $this->catalogEvents?->dispatch(
                    $c,
                    $tenant,
                    $productUuid,
                    StorefrontCatalogChanged::REASON_MEDIA_CHANGED
                );
            }

            $row = $this->media->findByUuid($c, $tenant, $mediaUuid);
            if ($row === null) {
                throw new \RuntimeException('Updated media row could not be reloaded.');
            }

            return $row;
        });
    }

    public function detach(ApplicationContext $c, string $mediaUuid): void
    {
        $tenant = $this->tenants->tenantUuid($c);

        $peek = $this->media->findByUuid($c, $tenant, $mediaUuid);
        if ($peek === null) {
            throw new NotFoundException('Resource not found.');
        }
        $productUuid = (string) $peek['product_uuid'];

        db($c)->transaction(function () use ($c, $tenant, $productUuid, $mediaUuid): void {
            if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            if ($this->media->findByUuid($c, $tenant, $mediaUuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $this->media->delete($c, $tenant, $mediaUuid);

            $this->catalogEvents?->dispatch($c, $tenant, $productUuid, StorefrontCatalogChanged::REASON_MEDIA_CHANGED);
        });
    }

    /**
     * @param list<array{uuid:string,position:int}> $positions
     * @return list<array<string,mixed>>
     */
    public function reorder(ApplicationContext $c, string $productUuid, array $positions): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        return db($c)->transaction(function () use ($c, $tenant, $productUuid, $positions): array {
            if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            if ($this->products->findLiveByUuid($c, $tenant, $productUuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $existingUuids = array_map(
                static fn (array $row): string => (string) $row['uuid'],
                $this->media->forProduct($c, $tenant, $productUuid)
            );

            $seen = [];
            foreach ($positions as $index => $entry) {
                $uuid = $entry['uuid'];
                if (!in_array($uuid, $existingUuids, true)) {
                    throw ValidationException::forField(
                        "positions.{$index}.uuid",
                        'Unknown media item for this product.'
                    );
                }
                if (isset($seen[$uuid])) {
                    throw ValidationException::forField(
                        "positions.{$index}.uuid",
                        'Duplicate media item in reorder list.'
                    );
                }
                $seen[$uuid] = true;
            }

            foreach ($positions as $entry) {
                $this->media->update($c, $tenant, $entry['uuid'], ['position' => $entry['position']]);
            }

            if ($positions !== []) {
                $this->catalogEvents?->dispatch(
                    $c,
                    $tenant,
                    $productUuid,
                    StorefrontCatalogChanged::REASON_MEDIA_CHANGED
                );
            }

            return $this->media->forProduct($c, $tenant, $productUuid);
        });
    }

    private function assertBlobAttachable(string $blobUuid): void
    {
        if ($blobUuid === '') {
            throw ValidationException::forField('blob_uuid', 'blob_uuid is required.');
        }

        if ($this->blobs === null) {
            throw ValidationException::forField(
                'blob_uuid',
                'Media requires the blobs subsystem, which is not available.'
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

    private function assertVariantBelongsToProduct(
        ApplicationContext $c,
        string $tenant,
        string $productUuid,
        string $variantUuid
    ): void {
        $variant = $this->variants->findByUuid($c, $tenant, $variantUuid);
        if ($variant === null || (string) $variant['product_uuid'] !== $productUuid) {
            // Non-revealing on purpose: unknown, cross-tenant, and belongs-to-another-
            // product all collapse to the same 422 message.
            throw ValidationException::forField(
                'variant_uuid',
                'variant_uuid must reference a variant belonging to this product.'
            );
        }
    }

    /** @param array<string,mixed> $input */
    private function normalizeVariantUuid(array $input): ?string
    {
        // isset() is false for both an absent key and a present-but-null value, so
        // this already covers both "not given" cases in one check.
        if (!isset($input['variant_uuid'])) {
            return null;
        }

        $variantUuid = trim((string) $input['variant_uuid']);

        return $variantUuid === '' ? null : $variantUuid;
    }

    private function normalizeRole(mixed $role): string
    {
        $role = (string) $role;
        if (!in_array($role, ['cover', 'gallery'], true)) {
            throw ValidationException::forField('role', 'role must be cover or gallery.');
        }

        return $role;
    }
}
