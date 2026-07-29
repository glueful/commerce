<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Wishlist;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Support\UuidBatch;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Validation\ValidationException;

/**
 * Account-backed wishlist rules (accounts design spec §11).
 *
 * Every growth path takes the same shape: `ensureList()` outside the transaction, `claimList()`
 * inside it, then re-read the count and mutate. Without that parent claim a count-then-insert
 * is a race -- two concurrent saves both read 99 and both insert, and the cap silently becomes
 * a suggestion.
 *
 * Availability is checked BEFORE a product can consume a slot, and re-checked on every read
 * through {@see ProductRepository::findActiveBuyerAvailableByUuids()}. A product that goes
 * inactive therefore leaves the list WITHOUT deleting the saved row: reactivate it and the
 * item returns.
 *
 * The cap is refused, never evicted. Silently dropping the oldest item to make room would
 * discard something a visitor deliberately saved.
 */
final class WishlistService
{
    /** Per (tenant, user). Matches the device-local bound so a full local list can round-trip. */
    public const MAX_ITEMS = 100;

    public function __construct(
        private WishlistRepository $repository,
        private ProductRepository $products,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /**
     * The user's saved products that are currently buyer-available, in display order.
     *
     * @return list<array<string,mixed>>
     */
    public function list(ApplicationContext $context, string $userUuid): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $saved = $this->repository->productUuidsForUser($context, $tenant, $userUuid);
        if ($saved === []) {
            return [];
        }

        $available = $this->availableByUuid($context, $tenant, $saved);

        $out = [];
        foreach ($saved as $uuid) {
            if (isset($available[$uuid])) {
                $out[] = $available[$uuid];
            }
        }

        return $out;
    }

    /**
     * Save a product to the front of the list.
     *
     * @throws ValidationException When the product is unavailable, or the list is full.
     */
    public function add(ApplicationContext $context, string $userUuid, string $productUuid): bool
    {
        $tenant = $this->tenants->tenantUuid($context);

        // Availability first: an unavailable uuid must never occupy a slot, since the read
        // filters it out and the visitor would see a shorter list that still refuses saves.
        if (!isset($this->availableByUuid($context, $tenant, [$productUuid])[$productUuid])) {
            throw ValidationException::forField('product_uuid', 'That product is not available.');
        }

        $this->repository->ensureList($context, $tenant, $userUuid);

        return db($context)->transaction(function () use ($context, $tenant, $userUuid, $productUuid): bool {
            if (!$this->repository->claimList($context, $tenant, $userUuid)) {
                throw ValidationException::forField('product_uuid', 'That product could not be saved.');
            }

            // Re-read UNDER the claim: a concurrent save may have added this product or taken
            // the last slot since the caller's own view of the list.
            if ($this->repository->has($context, $tenant, $userUuid, $productUuid)) {
                return false;
            }
            if ($this->repository->countForUser($context, $tenant, $userUuid) >= self::MAX_ITEMS) {
                throw ValidationException::forField(
                    'product_uuid',
                    sprintf('Your wishlist is full (%d items). Remove something to save this one.', self::MAX_ITEMS)
                );
            }

            $position = $this->repository->frontPosition($context, $tenant, $userUuid) - 1;

            return $this->repository->insertAt($context, $tenant, $userUuid, $productUuid, $position);
        });
    }

    public function remove(ApplicationContext $context, string $userUuid, string $productUuid): bool
    {
        return $this->repository->remove($context, $this->tenants->tenantUuid($context), $userUuid, $productUuid);
    }

    /**
     * Merge a device-local list into the account.
     *
     * Existing account items keep their own order; device-only items are appended in the order
     * the device supplied them. A device list carries UUIDs and no timestamps, so interleaving
     * by time is not something this merge could reconstruct, and it does not pretend to.
     *
     * @param list<string> $productUuids device order, first = the device's newest
     */
    public function import(ApplicationContext $context, string $userUuid, array $productUuids): WishlistImportResult
    {
        $tenant = $this->tenants->tenantUuid($context);
        $candidates = UuidBatch::normalize($productUuids);

        // normalize() keeps the FIRST UuidBatch::LIMIT values and drops the rest. Dropping them
        // silently would report them as neither imported nor overflow, so a caller clearing its
        // local list would lose them. Anything VALID beyond the batch limit is overflow: the
        // visitor-facing action is identical -- keep it locally and import it later. Malformed
        // values are not overflow; telling a caller to keep a string that can never import
        // would have it preserve garbage forever.
        $beyondLimit = [];
        if (count($productUuids) > UuidBatch::LIMIT) {
            $kept = array_flip($candidates);
            $seen = [];
            foreach ($productUuids as $uuid) {
                if (!is_string($uuid) || preg_match(UuidBatch::UUID_PATTERN, $uuid) !== 1) {
                    continue;
                }
                if (isset($kept[$uuid]) || isset($seen[$uuid])) {
                    continue;
                }
                $seen[$uuid] = true;
                $beyondLimit[] = $uuid;
            }
        }

        if ($candidates === []) {
            return new WishlistImportResult([], [], $beyondLimit);
        }

        $available = $this->availableByUuid($context, $tenant, $candidates);
        $this->repository->ensureList($context, $tenant, $userUuid);

        return db($context)->transaction(
            function () use (
                $context,
                $tenant,
                $userUuid,
                $candidates,
                $available,
                $beyondLimit
            ): WishlistImportResult {
                if (!$this->repository->claimList($context, $tenant, $userUuid)) {
                    // Nothing was imported and nothing was lost: the caller keeps its local list.
                    return new WishlistImportResult([], [], array_merge($candidates, $beyondLimit));
                }

                $saved = $this->repository->productUuidsForUser($context, $tenant, $userUuid);
                $count = count($saved);
                $position = $this->repository->backPosition($context, $tenant, $userUuid);

                $imported = [];
                $unavailable = [];
                $overflow = [];

                foreach ($candidates as $uuid) {
                    if (in_array($uuid, $saved, true)) {
                        continue; // dedupe by product uuid -- the account copy wins
                    }
                    if (!isset($available[$uuid])) {
                        $unavailable[] = $uuid;
                        continue;
                    }
                    if ($count >= self::MAX_ITEMS) {
                        $overflow[] = $uuid;
                        continue;
                    }
                    if ($this->repository->insertAt($context, $tenant, $userUuid, $uuid, ++$position)) {
                        $imported[] = $uuid;
                        $count++;
                    }
                }

                return new WishlistImportResult($imported, $unavailable, array_merge($overflow, $beyondLimit));
            }
        );
    }

    /**
     * @param list<string> $uuids
     * @return array<string,array<string,mixed>> keyed by product uuid
     */
    private function availableByUuid(ApplicationContext $context, string $tenant, array $uuids): array
    {
        $byUuid = [];
        foreach ($this->products->findActiveBuyerAvailableByUuids($context, $tenant, $uuids) as $row) {
            $byUuid[(string) $row['uuid']] = $row;
        }

        return $byUuid;
    }
}
