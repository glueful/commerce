<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Seller identity + lifecycle (design spec §2.4/§2.6, §4 lock order). Tenant
 * is an explicit parameter on every method -- mirroring
 * {@see MarketplaceMode}/{@see MarketplaceWorkspaceLock} -- never resolved
 * internally, so callers (today the platform admin controller; Task 3's
 * attribution/activation flows tomorrow) always pass the SAME tenant they
 * used to claim any surrounding lock.
 *
 * {@see self::create()} writes the seller row and its first ACTIVE
 * `seller_owner` membership in ONE transaction: an ownerless seller must
 * never be externally visible (design spec §2.6), so a failure inserting
 * either row rolls back both. No workspace-lock claim here -- sellers don't
 * gate activation (Task 2 brief) -- but every OTHER mutation (update/
 * suspend/reactivate/close) claims the seller's OWN `revision` first via
 * {@see SellerRepository::claimRevision()}, then re-reads fresh state before
 * acting -- the same claim-then-re-read discipline
 * {@see \Glueful\Extensions\Commerce\Catalog\CatalogService} uses for
 * `commerce_products.catalog_revision`.
 *
 * Lifecycle (design spec §2.1/§2.2, MV5b Task 2): create always lands
 * directly on `active` (`onboarding` is reserved for MV4, unused here).
 * suspend/reactivate/close now REQUIRE a non-empty `$reason` and a
 * non-empty `$actor` -- validated FIRST, before any claim or write
 * ({@see self::guardReasonAndActor()}, a 422 {@see ValidationException}).
 * suspend requires the current status to be `active`; reactivate requires
 * `suspended`. Re-suspending an already-`suspended` seller (or
 * re-activating an already-`active` one) is a STABLE NO-OP -- it returns
 * the current row, writes NO status change and NO audit event, and is
 * checked BEFORE the `allowedFrom` guard so a same-state call never 409s.
 * Any other incompatible transition (a terminally `closed` seller, or
 * `onboarding -> suspended`) raises {@see SellerLifecycleException} (409).
 * close is blocked (409) while the seller owns any non-deleted product
 * ({@see SellerRepository::hasLiveProducts()}); once clear it is the ONLY
 * transition allowed to retire the seller's final owner -- it atomically
 * marks the seller `closed` AND revokes every currently-active membership
 * via {@see SellerMembershipRepository::deactivateAllForSeller()}, in the
 * SAME transaction as the claim.
 *
 * Every successful suspend/reactivate/close writes an append-only
 * {@see SellerLifecycleEventRepository} row (`from_status`, `to_status`,
 * `actor_uuid`, `reason`) in the SAME transaction as the `status` write
 * (design spec §2.2) -- a failure appending that row (e.g. a forced
 * audit-uuid collision) rolls back the status change too. There is no
 * such thing as an unaudited lifecycle transition.
 */
final class SellerService
{
    /** @var callable(): string */
    private $uuidGenerator;

    /**
     * @param (callable(): string)|null $uuidGenerator Injectable seam for tests
     *     forcing a create()-time uuid collision on the membership insert, or a
     *     lifecycle-transition-time uuid collision on the audit-event insert
     *     (see {@see MarketplaceWorkspaceLock}'s identical convention) -- proves
     *     both the seller+first-owner-membership write and the
     *     status+audit-event write are atomic. Defaults to the house
     *     {@see Utils::generateNanoID()} generator.
     */
    public function __construct(
        private SellerRepository $sellers,
        private SellerMembershipRepository $memberships,
        private SellerLifecycleEventRepository $lifecycleEvents,
        ?callable $uuidGenerator = null,
        private ?CommissionPolicyService $commissionPolicy = null,
    ) {
        $this->uuidGenerator = $uuidGenerator ?? static fn (): string => Utils::generateNanoID();
    }

    /**
     * @param array<string,mixed> $filters 'q' (literal substring on name/slug), 'status' (exact)
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function list(ApplicationContext $c, string $tenant, array $filters, int $page, int $perPage): array
    {
        return $this->sellers->paginatedFor($c, $tenant, $filters, $page, $perPage);
    }

    /** @return array<string,mixed> */
    public function show(ApplicationContext $c, string $tenant, string $uuid): array
    {
        $seller = $this->sellers->findByUuid($c, $tenant, $uuid);
        if ($seller === null) {
            throw new NotFoundException('Resource not found.');
        }

        return $seller;
    }

    /**
     * @param array<string,mixed>|null $metadata
     * @return array<string,mixed>
     */
    public function create(
        ApplicationContext $c,
        string $tenant,
        string $slug,
        string $name,
        ?array $metadata,
        string $ownerUserUuid,
        ?string $actor = null
    ): array {
        $slug = trim($slug);
        if ($slug === '') {
            throw ValidationException::forField('slug', 'Slug is required.');
        }
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::forField('name', 'Name is required.');
        }
        $ownerUserUuid = trim($ownerUserUuid);
        if ($ownerUserUuid === '') {
            throw ValidationException::forField('owner_user_uuid', 'owner_user_uuid is required.');
        }

        if ($this->sellers->findBySlug($c, $tenant, $slug) !== null) {
            throw ValidationException::forField('slug', 'Slug already in use.');
        }

        $sellerUuid = ($this->uuidGenerator)();

        db($c)->transaction(function () use (
            $c,
            $tenant,
            $sellerUuid,
            $slug,
            $name,
            $metadata,
            $ownerUserUuid,
            $actor
        ): void {
            $this->sellers->insert($c, [
                'uuid' => $sellerUuid,
                'tenant_uuid' => $tenant,
                'slug' => $slug,
                'name' => $name,
                'metadata' => $metadata,
                'status' => 'active',
            ]);

            $this->memberships->insert($c, [
                'uuid' => ($this->uuidGenerator)(),
                'tenant_uuid' => $tenant,
                'seller_uuid' => $sellerUuid,
                'user_uuid' => $ownerUserUuid,
                'role' => 'seller_owner',
                'status' => 'active',
                'created_by' => $actor,
            ]);
        });

        $seller = $this->sellers->findByUuid($c, $tenant, $sellerUuid);
        if ($seller === null) {
            throw new \RuntimeException('Created seller could not be reloaded.');
        }

        return $seller;
    }

    /**
     * `slug` is immutable once created -- a `slug` key present ANYWHERE in
     * $changes is rejected with 422 (never silently dropped), mirroring
     * {@see \Glueful\Extensions\Commerce\Shipping\ShippingClassService::update()}.
     *
     * Commission policy (design spec §2.3, MV3 Task 4): $changes touching
     * ANY of {@see CommissionPolicyResolver::FIELDS} is routed through the
     * injected {@see CommissionPolicyService} FIRST -- validated, applied,
     * AND audited in its own transaction -- before the ordinary name/metadata
     * claim-then-patch below runs (which always executes regardless, exactly
     * as before, so the return value stays a fresh re-read of the seller
     * row). Operator only -- sellers have no route to this method.
     *
     * @param array<string,mixed> $changes 'name' and/or 'metadata' and/or commission fields
     * @return array<string,mixed>
     */
    public function update(
        ApplicationContext $c,
        string $tenant,
        string $uuid,
        array $changes,
        ?string $actor = null
    ): array {
        if (array_key_exists('slug', $changes)) {
            throw ValidationException::forField('slug', 'slug is immutable and cannot be changed after creation.');
        }

        $commission = CommissionPolicyResolver::extractFromChanges($changes);
        if ($commission !== null) {
            if ($this->commissionPolicy === null) {
                throw ValidationException::forField(
                    'commission_kind',
                    'Commission policy management is not available.'
                );
            }
            $this->commissionPolicy->setSeller($c, $tenant, $uuid, $commission, $actor);
        }

        $fieldChanges = CommissionPolicyResolver::withoutFields($changes);

        return db($c)->transaction(function () use ($c, $tenant, $uuid, $fieldChanges): array {
            if (!$this->sellers->claimRevision($c, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }

            if ($this->sellers->findByUuid($c, $tenant, $uuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $set = [];
            if (array_key_exists('name', $fieldChanges) && $fieldChanges['name'] !== null) {
                $name = trim((string) $fieldChanges['name']);
                if ($name === '') {
                    throw ValidationException::forField('name', 'Name is required.');
                }
                $set['name'] = $name;
            }
            if (array_key_exists('metadata', $fieldChanges)) {
                $set['metadata'] = $fieldChanges['metadata'];
            }

            if ($set !== []) {
                $this->sellers->update($c, $tenant, $uuid, $set);
            }

            $seller = $this->sellers->findByUuid($c, $tenant, $uuid);
            if ($seller === null) {
                throw new \RuntimeException('Updated seller could not be reloaded.');
            }

            return $seller;
        });
    }

    /** @return array<string,mixed> */
    public function suspend(ApplicationContext $c, string $tenant, string $uuid, string $reason, string $actor): array
    {
        return $this->transition($c, $tenant, $uuid, 'active', 'suspended', $reason, $actor);
    }

    /** @return array<string,mixed> */
    public function reactivate(
        ApplicationContext $c,
        string $tenant,
        string $uuid,
        string $reason,
        string $actor
    ): array {
        return $this->transition($c, $tenant, $uuid, 'suspended', 'active', $reason, $actor);
    }

    /**
     * The only transition allowed to retire the seller's final owner (design
     * spec §2.4): blocked with 409 while the seller owns any non-deleted
     * product; once clear, atomically marks the seller `closed`, revokes
     * every currently-active membership, AND appends the lifecycle-audit row
     * (design spec §2.2) -- all three writes in the SAME transaction as the
     * claim, so a failure on any of them leaves the seller, its memberships,
     * and its audit trail exactly as they were.
     *
     * `$reason`/`$actor` are validated FIRST via
     * {@see self::guardReasonAndActor()} -- a 422 before any claim.
     *
     * @return array<string,mixed>
     */
    public function close(ApplicationContext $c, string $tenant, string $uuid, string $reason, string $actor): array
    {
        $this->guardReasonAndActor($reason, $actor);

        return db($c)->transaction(function () use ($c, $tenant, $uuid, $reason, $actor): array {
            if (!$this->sellers->claimRevision($c, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->sellers->findByUuid($c, $tenant, $uuid);
            if ($current === null) {
                throw new NotFoundException('Resource not found.');
            }

            $currentStatus = (string) $current['status'];

            if ($currentStatus === 'closed') {
                throw new SellerLifecycleException('Seller is already closed.');
            }

            if ($this->sellers->hasLiveProducts($c, $tenant, $uuid)) {
                throw new SellerLifecycleException(
                    'Seller cannot be closed while it still owns products. Transfer or remove them first.'
                );
            }

            $this->sellers->update($c, $tenant, $uuid, ['status' => 'closed']);
            $this->memberships->deactivateAllForSeller($c, $tenant, $uuid);

            $this->lifecycleEvents->insert($c, $tenant, [
                'uuid' => ($this->uuidGenerator)(),
                'seller_uuid' => $uuid,
                'from_status' => $currentStatus,
                'to_status' => 'closed',
                'actor_uuid' => $actor,
                'reason' => $reason,
            ]);

            $seller = $this->sellers->findByUuid($c, $tenant, $uuid);
            if ($seller === null) {
                throw new \RuntimeException('Closed seller could not be reloaded.');
            }

            return $seller;
        });
    }

    /**
     * `$reason`/`$actor` are non-empty, or this throws a 422
     * {@see ValidationException} -- design spec §2.1, checked BEFORE the
     * caller opens any transaction or claims any row.
     */
    private function guardReasonAndActor(string $reason, string $actor): void
    {
        if (trim($reason) === '') {
            throw ValidationException::forField('reason', 'reason is required.');
        }
        if (trim($actor) === '') {
            throw ValidationException::forField('actor', 'actor is required.');
        }
    }

    /**
     * Shared claim -> post-claim-re-read -> status-guarded transition body
     * for suspend()/reactivate(): the SAME `claimRevision()` primitive
     * close() uses, applied to a two-state transition instead of the
     * products/memberships compound write.
     *
     * Design spec §2.1: `$reason`/`$actor` are validated FIRST (422 before
     * any claim). Once claimed, a seller ALREADY at `$to` is a STABLE NO-OP
     * -- returned as-is, no status write, no audit event -- checked BEFORE
     * the `$from` guard so a same-state call never 409s. Any other status
     * (including `$to`'s own reverse-direction terminal states, e.g. a
     * `closed` seller) is an incompatible transition -- 409. A successful
     * transition writes the new status AND appends the lifecycle-audit row
     * in the SAME transaction as the claim (design spec §2.2) -- a failure
     * appending that row (e.g. a forced audit-uuid collision) rolls back the
     * status write too.
     *
     * @return array<string,mixed>
     */
    private function transition(
        ApplicationContext $c,
        string $tenant,
        string $uuid,
        string $from,
        string $to,
        string $reason,
        string $actor
    ): array {
        $this->guardReasonAndActor($reason, $actor);

        return db($c)->transaction(function () use ($c, $tenant, $uuid, $from, $to, $reason, $actor): array {
            if (!$this->sellers->claimRevision($c, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->sellers->findByUuid($c, $tenant, $uuid);
            if ($current === null) {
                throw new NotFoundException('Resource not found.');
            }

            $currentStatus = (string) $current['status'];

            if ($currentStatus === $to) {
                return $current;
            }

            if ($currentStatus !== $from) {
                throw new SellerLifecycleException(
                    "Seller is '{$currentStatus}'; cannot transition to '{$to}'."
                );
            }

            $this->sellers->update($c, $tenant, $uuid, ['status' => $to]);

            $this->lifecycleEvents->insert($c, $tenant, [
                'uuid' => ($this->uuidGenerator)(),
                'seller_uuid' => $uuid,
                'from_status' => $currentStatus,
                'to_status' => $to,
                'actor_uuid' => $actor,
                'reason' => $reason,
            ]);

            $seller = $this->sellers->findByUuid($c, $tenant, $uuid);
            if ($seller === null) {
                throw new \RuntimeException('Updated seller could not be reloaded.');
            }

            return $seller;
        });
    }
}
