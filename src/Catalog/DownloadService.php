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
 * Download definition (variant-scoped) attach/update/detach for digital products.
 *
 * The `commerce_downloads` row carries no `product_uuid` column of its own --
 * every mutation resolves the owning product THROUGH the variant, then claims
 * the product's `catalog_revision` FIRST (affected-row-checked UPDATE) inside
 * the transaction, then re-reads variant/download state before enforcing any
 * invariant -- the claimed row lock is the real serialization primitive (see
 * ProductRepository::claimCatalogRevision docs), same claim-then-re-read
 * discipline as {@see ProductMediaService}/{@see AddonService}. Reads taken
 * before the claim are snapshots only, never trusted for a business decision.
 *
 * Attach rules: the variant must resolve in-tenant (else non-revealing 404,
 * matching the update()/detach() peek pattern) AND belong to a `digital`-type
 * product (else 422 -- the variant itself was found, but the operation is not
 * allowed against it); the referenced blob must exist, be `active`, and be
 * PRIVATE -- the INVERSE of {@see ProductMediaService}, because merchandise
 * must never be publicly fetchable. Detach never touches the underlying blob
 * -- only the definition row.
 *
 * Blob validation is soft: `BlobRepository` (framework core) is resolved by
 * the service provider only if the container has it bound. When unresolvable,
 * every attach fails 422 with a message naming the missing subsystem rather
 * than crashing.
 */
final class DownloadService
{
    public function __construct(
        private ProductRepository $products,
        private VariantRepository $variants,
        private DownloadRepository $downloads,
        private CurrentTenantResolver $tenants,
        private ?BlobRepository $blobs = null,
    ) {
    }

    /** @return list<array<string,mixed>> every definition for the variant, ordered by position */
    public function list(ApplicationContext $c, string $variantUuid): array
    {
        return $this->downloads->forVariant($c, $this->tenants->tenantUuid($c), $variantUuid);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function attach(ApplicationContext $c, string $variantUuid, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        // Tenant-scoped snapshot read purely to discover which product to claim --
        // the URL carries no product uuid. Never trusted by itself; the
        // transaction re-reads this row again immediately after the claim succeeds.
        $peek = $this->variants->findByUuid($c, $tenant, $variantUuid);
        if ($peek === null) {
            throw new NotFoundException('Resource not found.');
        }
        $productUuid = (string) $peek['product_uuid'];

        return db($c)->transaction(function () use ($c, $tenant, $productUuid, $variantUuid, $input): array {
            if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            // Post-claim re-read: the variant must still exist under this tenant
            // and belong to the claimed product now that we hold its claim.
            $variant = $this->variants->findByUuid($c, $tenant, $variantUuid);
            if ($variant === null || (string) $variant['product_uuid'] !== $productUuid) {
                throw new NotFoundException('Resource not found.');
            }

            $product = $this->products->findLiveByUuid($c, $tenant, $productUuid);
            if ($product === null) {
                throw new NotFoundException('Resource not found.');
            }
            if ((string) ($product['type'] ?? 'physical') !== 'digital') {
                throw ValidationException::forField(
                    'variant_uuid',
                    'Downloads can only be attached to a variant of a digital product.'
                );
            }

            $blobUuid = trim((string) ($input['blob_uuid'] ?? ''));
            $this->assertBlobAttachable($blobUuid);

            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                throw ValidationException::forField('name', 'name is required.');
            }

            $existing = $this->downloads->forVariant($c, $tenant, $variantUuid);
            foreach ($existing as $row) {
                if ((string) $row['blob_uuid'] === $blobUuid) {
                    throw ValidationException::forField(
                        'blob_uuid',
                        'This blob is already attached to the variant.'
                    );
                }
            }

            $uuid = Utils::generateNanoID();
            $this->downloads->insert($c, [
                'uuid' => $uuid,
                'tenant_uuid' => $tenant,
                'variant_uuid' => $variantUuid,
                'blob_uuid' => $blobUuid,
                'name' => $name,
                'download_limit' => $this->normalizeNonNegativeInt(
                    $input['download_limit'] ?? null,
                    'download_limit'
                ),
                'expiry_days' => $this->normalizeNonNegativeInt($input['expiry_days'] ?? null, 'expiry_days'),
                'position' => isset($input['position']) ? (int) $input['position'] : count($existing),
                'status' => 'active',
            ]);

            $row = $this->downloads->findByUuid($c, $tenant, $uuid);
            if ($row === null) {
                throw new \RuntimeException('Created download row could not be reloaded.');
            }

            return $row;
        });
    }

    /**
     * @param array<string,mixed> $changes name/download_limit/expiry_days/position/status --
     *     only present keys are applied; an explicit null for download_limit or
     *     expiry_days IS a real value (unlimited/never), distinguished from an
     *     absent key ("leave unchanged") via array_key_exists.
     * @return array<string,mixed>
     */
    public function update(ApplicationContext $c, string $downloadUuid, array $changes): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $productUuid = $this->resolveProductUuidForDownload($c, $tenant, $downloadUuid);

        return db($c)->transaction(function () use ($c, $tenant, $productUuid, $downloadUuid, $changes): array {
            if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->downloads->findByUuid($c, $tenant, $downloadUuid);
            if ($current === null) {
                throw new NotFoundException('Resource not found.');
            }

            $set = $this->planUpdate($changes);
            if ($set !== []) {
                $this->downloads->update($c, $tenant, $downloadUuid, $set);
            }

            $row = $this->downloads->findByUuid($c, $tenant, $downloadUuid);
            if ($row === null) {
                throw new \RuntimeException('Updated download row could not be reloaded.');
            }

            return $row;
        });
    }

    /**
     * Removes the definition row only -- the underlying blob is never touched,
     * regardless of whether other definitions/grants still reference it.
     */
    public function detach(ApplicationContext $c, string $downloadUuid): void
    {
        $tenant = $this->tenants->tenantUuid($c);
        $productUuid = $this->resolveProductUuidForDownload($c, $tenant, $downloadUuid);

        db($c)->transaction(function () use ($c, $tenant, $productUuid, $downloadUuid): void {
            if (!$this->products->claimCatalogRevision($c, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            if ($this->downloads->findByUuid($c, $tenant, $downloadUuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $this->downloads->delete($c, $tenant, $downloadUuid);
        });
    }

    /**
     * Tenant-scoped, pre-claim resolution of "which product owns this download's
     * variant" -- the download row itself carries no product_uuid column, so this
     * hops download -> variant -> product. A snapshot only; the transaction
     * re-reads the download row again immediately after the claim succeeds.
     */
    private function resolveProductUuidForDownload(
        ApplicationContext $c,
        string $tenant,
        string $downloadUuid
    ): string {
        $download = $this->downloads->findByUuid($c, $tenant, $downloadUuid);
        if ($download === null) {
            throw new NotFoundException('Resource not found.');
        }

        $variant = $this->variants->findByUuid($c, $tenant, (string) $download['variant_uuid']);
        if ($variant === null) {
            throw new NotFoundException('Resource not found.');
        }

        return (string) $variant['product_uuid'];
    }

    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function planUpdate(array $changes): array
    {
        $set = [];
        if (array_key_exists('name', $changes) && $changes['name'] !== null) {
            $name = trim((string) $changes['name']);
            if ($name === '') {
                throw ValidationException::forField('name', 'name is required.');
            }
            $set['name'] = $name;
        }
        if (array_key_exists('download_limit', $changes)) {
            $set['download_limit'] = $this->normalizeNonNegativeInt($changes['download_limit'], 'download_limit');
        }
        if (array_key_exists('expiry_days', $changes)) {
            $set['expiry_days'] = $this->normalizeNonNegativeInt($changes['expiry_days'], 'expiry_days');
        }
        if (array_key_exists('position', $changes) && $changes['position'] !== null) {
            if (!is_int($changes['position']) || $changes['position'] < 0) {
                throw ValidationException::forField('position', 'position must be a non-negative integer.');
            }
            $set['position'] = $changes['position'];
        }
        if (array_key_exists('status', $changes) && $changes['status'] !== null) {
            $set['status'] = $this->normalizeStatus($changes['status']);
        }

        return $set;
    }

    private function normalizeStatus(mixed $raw): string
    {
        $status = (string) $raw;
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw ValidationException::forField('status', 'status must be one of: active, inactive.');
        }

        return $status;
    }

    /** null = unlimited/never; otherwise must be a non-negative integer. */
    private function normalizeNonNegativeInt(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 0) {
            throw ValidationException::forField($field, "{$field} must be a non-negative integer or null.");
        }

        return $value;
    }

    private function assertBlobAttachable(string $blobUuid): void
    {
        if ($blobUuid === '') {
            throw ValidationException::forField('blob_uuid', 'blob_uuid is required.');
        }

        if ($this->blobs === null) {
            throw ValidationException::forField(
                'blob_uuid',
                'Downloads require the blobs subsystem, which is not available.'
            );
        }

        $blob = $this->blobs->findByUuid($blobUuid);
        if (
            $blob === null
            || ($blob['status'] ?? null) !== 'active'
            || ($blob['visibility'] ?? null) !== 'private'
        ) {
            throw ValidationException::forField(
                'blob_uuid',
                'blob_uuid must reference an existing, active, private blob.'
            );
        }
    }
}
