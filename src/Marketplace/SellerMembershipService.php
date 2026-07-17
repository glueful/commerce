<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Marketplace\Contracts\SellerRoleAuthority;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Seller membership CRUD (design spec §2.5/§2.6, §4 lock order): every
 * mutation claims the SELLER's `revision` FIRST -- via
 * {@see self::claimAndRequireMutableSeller()} -- then re-reads fresh seller
 * state before touching a membership row. This is what makes the last-owner
 * count concurrent-safe: it is always taken AFTER the claim, inside the SAME
 * transaction, never against a pre-claim snapshot.
 *
 * Fail-closed while the seller is `suspended`/`closed` (design spec §2.4):
 * every mutation here is refused with {@see SellerMembershipException}
 * (409); reads ({@see self::list()}) are never gated on seller status --
 * "reads stay, so staff can see state".
 *
 * Role names are validated ONLY through the injected
 * {@see SellerRoleAuthority} -- never a hard-coded list -- so a later MV
 * slice can swap the authority without touching this class (design spec
 * §2.6 / Task 2 brief).
 *
 * `commerce_seller_memberships` carries a `(seller_uuid, user_uuid)` unique
 * constraint and rows are never deleted -- {@see self::revoke()} sets
 * `status = 'revoked'` rather than removing the row, so
 * {@see self::grant()} after a revoke reactivates the SAME row (an UPDATE,
 * never a fresh INSERT that would collide with the constraint) instead of
 * being permanently blocked. A duplicate grant against an already-ACTIVE
 * membership is rejected with a 409 {@see SellerMembershipException}.
 */
final class SellerMembershipService
{
    public function __construct(
        private SellerRepository $sellers,
        private SellerMembershipRepository $memberships,
        private SellerRoleAuthority $roles,
    ) {
    }

    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function list(ApplicationContext $c, string $tenant, string $sellerUuid, int $page, int $perPage): array
    {
        if ($this->sellers->findByUuid($c, $tenant, $sellerUuid) === null) {
            throw new NotFoundException('Resource not found.');
        }

        return $this->memberships->listForSeller($c, $tenant, $sellerUuid, $page, $perPage);
    }

    /** @return array<string,mixed> */
    public function grant(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $userUuid,
        string $role,
        ?string $actor = null
    ): array {
        $this->assertValidRole($role);
        $userUuid = trim($userUuid);
        if ($userUuid === '') {
            throw ValidationException::forField('user_uuid', 'user_uuid is required.');
        }

        return db($c)->transaction(function () use ($c, $tenant, $sellerUuid, $userUuid, $role, $actor): array {
            $this->claimAndRequireMutableSeller($c, $tenant, $sellerUuid);

            $existing = $this->memberships->findBySellerAndUser($c, $tenant, $sellerUuid, $userUuid);
            if ($existing !== null && (string) $existing['status'] === 'active') {
                throw new SellerMembershipException(
                    'This user already has an active membership for this seller.'
                );
            }

            if ($existing !== null) {
                $this->memberships->update($c, $tenant, (string) $existing['uuid'], [
                    'role' => $role,
                    'status' => 'active',
                    'created_by' => $actor,
                ]);
            } else {
                $this->memberships->insert($c, [
                    'uuid' => Utils::generateNanoID(),
                    'tenant_uuid' => $tenant,
                    'seller_uuid' => $sellerUuid,
                    'user_uuid' => $userUuid,
                    'role' => $role,
                    'status' => 'active',
                    'created_by' => $actor,
                ]);
            }

            return $this->requireMembership($c, $tenant, $sellerUuid, $userUuid);
        });
    }

    /** @return array<string,mixed> */
    public function changeRole(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $userUuid,
        string $role,
        ?string $actor = null
    ): array {
        $this->assertValidRole($role);

        return db($c)->transaction(function () use ($c, $tenant, $sellerUuid, $userUuid, $role): array {
            $this->claimAndRequireMutableSeller($c, $tenant, $sellerUuid);

            $membership = $this->requireActiveMembership($c, $tenant, $sellerUuid, $userUuid);

            if ((string) $membership['role'] === 'seller_owner' && $role !== 'seller_owner') {
                $this->assertNotLastOwner($c, $tenant, $sellerUuid, 'demote');
            }

            $this->memberships->update($c, $tenant, (string) $membership['uuid'], ['role' => $role]);

            return $this->requireMembership($c, $tenant, $sellerUuid, $userUuid);
        });
    }

    public function revoke(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $userUuid,
        ?string $actor = null
    ): void {
        db($c)->transaction(function () use ($c, $tenant, $sellerUuid, $userUuid): void {
            $this->claimAndRequireMutableSeller($c, $tenant, $sellerUuid);

            $membership = $this->requireActiveMembership($c, $tenant, $sellerUuid, $userUuid);

            if ((string) $membership['role'] === 'seller_owner') {
                $this->assertNotLastOwner($c, $tenant, $sellerUuid, 'revoke');
            }

            $this->memberships->update($c, $tenant, (string) $membership['uuid'], ['status' => 'revoked']);
        });
    }

    /**
     * Claims the seller's revision, then re-reads fresh state and fails
     * closed (409) while the seller is `suspended`/`closed` -- the shared
     * gate every mutation above enters through BEFORE touching a membership
     * row.
     *
     * @return array<string,mixed>
     */
    private function claimAndRequireMutableSeller(ApplicationContext $c, string $tenant, string $sellerUuid): array
    {
        if (!$this->sellers->claimRevision($c, $tenant, $sellerUuid)) {
            throw new NotFoundException('Resource not found.');
        }

        $seller = $this->sellers->findByUuid($c, $tenant, $sellerUuid);
        if ($seller === null) {
            throw new NotFoundException('Resource not found.');
        }

        if (in_array((string) $seller['status'], ['suspended', 'closed'], true)) {
            throw new SellerMembershipException(
                "Seller is '{$seller['status']}'; membership mutations are unavailable."
            );
        }

        return $seller;
    }

    /**
     * Concurrent-safe last-owner guard (design spec §2.6): the caller MUST
     * have already claimed the seller's revision (via
     * {@see self::claimAndRequireMutableSeller()}) before this count runs --
     * that claim is what serializes two simultaneous demote/revoke attempts
     * against the SAME seller's owner set.
     */
    private function assertNotLastOwner(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $action
    ): void {
        $owners = $this->memberships->countActiveByRole($c, $tenant, $sellerUuid, 'seller_owner');
        if ($owners <= 1) {
            throw new SellerMembershipException("Cannot {$action} the seller's last active owner.");
        }
    }

    /** @return array<string,mixed> */
    private function requireActiveMembership(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $userUuid
    ): array {
        $membership = $this->memberships->findBySellerAndUser($c, $tenant, $sellerUuid, $userUuid);
        if ($membership === null || (string) $membership['status'] !== 'active') {
            throw new NotFoundException('Resource not found.');
        }

        return $membership;
    }

    /** @return array<string,mixed> */
    private function requireMembership(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $userUuid
    ): array {
        $membership = $this->memberships->findBySellerAndUser($c, $tenant, $sellerUuid, $userUuid);
        if ($membership === null) {
            throw new \RuntimeException('Membership could not be reloaded.');
        }

        return $membership;
    }

    private function assertValidRole(string $role): void
    {
        if (!in_array($role, $this->roles->roles(), true)) {
            throw ValidationException::forField(
                'role',
                'role must be one of: ' . implode(', ', $this->roles->roles()) . '.'
            );
        }
    }
}
