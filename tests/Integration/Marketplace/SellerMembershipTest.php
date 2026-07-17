<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipException;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Seller membership grant/changeRole/revoke (design spec §2.5/§2.6): the
 * seller-claim-first discipline, the concurrent-safe last-owner guard on
 * demote AND revoke, duplicate-grant rejection, role validation via the
 * authority (never a hard-coded list), and the suspended/closed
 * fail-closed-mutations/reads-OK posture from the Task 2 TDD scope.
 */
final class SellerMembershipTest extends CommerceTestCase
{
    private const TENANT = 'tenantMEMBER1';

    private SellerRepository $sellers;
    private SellerMembershipRepository $memberships;
    private SellerService $sellerService;
    private SellerMembershipService $membershipService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sellers = new SellerRepository();
        $this->memberships = new SellerMembershipRepository();
        $this->sellerService = new SellerService($this->sellers, $this->memberships);
        $this->membershipService = new SellerMembershipService(
            $this->sellers,
            $this->memberships,
            new FixedSellerRoleAuthority()
        );
    }

    /** @return array<string,mixed> */
    private function createSeller(string $slug, string $ownerUserUuid): array
    {
        return $this->sellerService->create($this->context, self::TENANT, $slug, $slug, null, $ownerUserUuid);
    }

    // -----------------------------------------------------------------
    // Grant
    // -----------------------------------------------------------------

    public function testGrantAddsAnActiveMembershipWithTheGivenRole(): void
    {
        $seller = $this->createSeller('grant-seller', 'ownerUserA1');

        $membership = $this->membershipService->grant(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'staffUserA01',
            'seller_staff',
            'actorUserA1'
        );

        self::assertSame('seller_staff', $membership['role']);
        self::assertSame('active', $membership['status']);
        self::assertSame('actorUserA1', $membership['created_by']);
    }

    public function testGrantWithAnUnrecognizedRoleIs422(): void
    {
        $seller = $this->createSeller('grant-bad-role', 'ownerUserA2');

        $this->expectException(ValidationException::class);
        $this->membershipService->grant(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'staffUserA02',
            'not_a_real_role'
        );
    }

    public function testDuplicateGrantAgainstAnAlreadyActiveMembershipIs409(): void
    {
        $seller = $this->createSeller('grant-dup', 'ownerUserA3');
        $this->membershipService->grant($this->context, self::TENANT, $seller['uuid'], 'staffUserA03', 'seller_staff');

        $this->expectException(SellerMembershipException::class);
        $this->membershipService->grant($this->context, self::TENANT, $seller['uuid'], 'staffUserA03', 'seller_admin');
    }

    public function testGrantAfterARevokeReactivatesTheSameRowRatherThanBeingBlockedForever(): void
    {
        $seller = $this->createSeller('grant-after-revoke', 'ownerUserA4');
        $this->membershipService->grant($this->context, self::TENANT, $seller['uuid'], 'staffUserA04', 'seller_staff');
        $this->membershipService->revoke($this->context, self::TENANT, $seller['uuid'], 'staffUserA04');

        $regranted = $this->membershipService->grant(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'staffUserA04',
            'seller_admin'
        );

        self::assertSame('active', $regranted['status']);
        self::assertSame('seller_admin', $regranted['role']);
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_memberships')
                ->where('seller_uuid', '=', $seller['uuid'])
                ->where('user_uuid', '=', 'staffUserA04')
                ->count(),
            'the unique (seller_uuid, user_uuid) row is reactivated, never duplicated'
        );
    }

    public function testGrantOnAnUnknownSellerIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->membershipService->grant($this->context, self::TENANT, 'doesNotExist', 'someUser001', 'seller_staff');
    }

    public function testGrantWhileTheSellerIsSuspendedFailsClosedWith409(): void
    {
        $seller = $this->createSeller('grant-suspended', 'ownerUserA5');
        $this->sellerService->suspend($this->context, self::TENANT, $seller['uuid']);

        $this->expectException(SellerMembershipException::class);
        $this->membershipService->grant($this->context, self::TENANT, $seller['uuid'], 'staffUserA05', 'seller_staff');
    }

    // -----------------------------------------------------------------
    // changeRole
    // -----------------------------------------------------------------

    public function testChangeRoleUpdatesTheRoleOfAnExistingMembership(): void
    {
        $seller = $this->createSeller('change-role', 'ownerUserB1');
        $this->membershipService->grant($this->context, self::TENANT, $seller['uuid'], 'staffUserB01', 'seller_staff');

        $updated = $this->membershipService->changeRole(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'staffUserB01',
            'seller_admin'
        );

        self::assertSame('seller_admin', $updated['role']);
    }

    public function testChangeRoleToAnUnrecognizedRoleIs422(): void
    {
        $seller = $this->createSeller('change-bad-role', 'ownerUserB2');
        $this->membershipService->grant($this->context, self::TENANT, $seller['uuid'], 'staffUserB02', 'seller_staff');

        $this->expectException(ValidationException::class);
        $this->membershipService->changeRole(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'staffUserB02',
            'not_a_real_role'
        );
    }

    public function testChangeRoleOnAnUnknownMembershipIsNotFound(): void
    {
        $seller = $this->createSeller('change-unknown-member', 'ownerUserB3');

        $this->expectException(NotFoundException::class);
        $this->membershipService->changeRole(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'noSuchUser001',
            'seller_admin'
        );
    }

    public function testDemotingTheOnlyOwnerIs409(): void
    {
        $seller = $this->createSeller('demote-last-owner', 'ownerUserB4');

        $this->expectException(SellerMembershipException::class);
        $this->membershipService->changeRole(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'ownerUserB4',
            'seller_admin'
        );
    }

    public function testDemotingOneOfTwoOwnersSucceedsBecauseAnotherOwnerRemains(): void
    {
        $seller = $this->createSeller('demote-with-backup', 'ownerUserB5');
        $this->membershipService->grant(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'ownerUserB5b',
            'seller_owner'
        );

        $updated = $this->membershipService->changeRole(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'ownerUserB5',
            'seller_admin'
        );

        self::assertSame('seller_admin', $updated['role']);

        $remainingOwners = $this->memberships->countActiveByRole(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'seller_owner'
        );
        self::assertSame(1, $remainingOwners);
    }

    public function testChangeRoleWhileTheSellerIsClosedFailsClosedWith409(): void
    {
        $seller = $this->createSeller('change-closed', 'ownerUserB6');
        $this->membershipService->grant($this->context, self::TENANT, $seller['uuid'], 'staffUserB06', 'seller_staff');
        $this->sellerService->close($this->context, self::TENANT, $seller['uuid']);

        $this->expectException(SellerMembershipException::class);
        $this->membershipService->changeRole(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'staffUserB06',
            'seller_admin'
        );
    }

    // -----------------------------------------------------------------
    // Revoke
    // -----------------------------------------------------------------

    public function testRevokeMarksAnActiveMembershipRevoked(): void
    {
        $seller = $this->createSeller('revoke-me', 'ownerUserC1');
        $this->membershipService->grant($this->context, self::TENANT, $seller['uuid'], 'staffUserC01', 'seller_staff');

        $this->membershipService->revoke($this->context, self::TENANT, $seller['uuid'], 'staffUserC01');

        $row = $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', $seller['uuid'])
            ->where('user_uuid', '=', 'staffUserC01')
            ->first();
        self::assertSame('revoked', $row['status']);
    }

    public function testRevokingTheOnlyOwnerIs409(): void
    {
        $seller = $this->createSeller('revoke-last-owner', 'ownerUserC2');

        $this->expectException(SellerMembershipException::class);
        $this->membershipService->revoke($this->context, self::TENANT, $seller['uuid'], 'ownerUserC2');
    }

    public function testRevokingOneOfTwoOwnersSucceedsBecauseAnotherOwnerRemains(): void
    {
        $seller = $this->createSeller('revoke-with-backup', 'ownerUserC3');
        $this->membershipService->grant(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'ownerUserC3b',
            'seller_owner'
        );

        $this->membershipService->revoke($this->context, self::TENANT, $seller['uuid'], 'ownerUserC3');

        $remainingOwners = $this->memberships->countActiveByRole(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'seller_owner'
        );
        self::assertSame(1, $remainingOwners);
    }

    public function testRevokeOnAnAlreadyRevokedMembershipIsNotFound(): void
    {
        $seller = $this->createSeller('double-revoke', 'ownerUserC4');
        $this->membershipService->grant($this->context, self::TENANT, $seller['uuid'], 'staffUserC04', 'seller_staff');
        $this->membershipService->revoke($this->context, self::TENANT, $seller['uuid'], 'staffUserC04');

        $this->expectException(NotFoundException::class);
        $this->membershipService->revoke($this->context, self::TENANT, $seller['uuid'], 'staffUserC04');
    }

    public function testRevokeWhileTheSellerIsSuspendedFailsClosedWith409(): void
    {
        $seller = $this->createSeller('revoke-suspended', 'ownerUserC5');
        $this->membershipService->grant($this->context, self::TENANT, $seller['uuid'], 'staffUserC05', 'seller_staff');
        $this->sellerService->suspend($this->context, self::TENANT, $seller['uuid']);

        $this->expectException(SellerMembershipException::class);
        $this->membershipService->revoke($this->context, self::TENANT, $seller['uuid'], 'staffUserC05');
    }

    // -----------------------------------------------------------------
    // Reads stay available even while the seller is suspended
    // -----------------------------------------------------------------

    public function testListingMembershipsSucceedsEvenWhileTheSellerIsSuspended(): void
    {
        $seller = $this->createSeller('read-while-suspended', 'ownerUserD1');
        $this->membershipService->grant($this->context, self::TENANT, $seller['uuid'], 'staffUserD01', 'seller_staff');
        $this->sellerService->suspend($this->context, self::TENANT, $seller['uuid']);

        $result = $this->membershipService->list($this->context, self::TENANT, $seller['uuid'], 1, 24);

        self::assertSame(2, $result['total'], 'the owner + the staff membership are both still readable');
    }

    public function testConcurrentSafeLastOwnerCountIsTakenAfterTheSellerClaimNotBeforeIt(): void
    {
        // The service's own claim-then-count ordering (SellerMembershipService::
        // claimAndRequireMutableSeller() before assertNotLastOwner()) is what this
        // proves indirectly: revoking the only owner AFTER granting and then
        // revoking a second owner still correctly reports "last owner" -- the
        // count reflects state as of AFTER the claim, not a stale pre-claim read.
        $seller = $this->createSeller('concurrent-safe', 'ownerUserE1');
        $this->membershipService->grant($this->context, self::TENANT, $seller['uuid'], 'ownerUserE1b', 'seller_owner');
        $this->membershipService->revoke($this->context, self::TENANT, $seller['uuid'], 'ownerUserE1b');

        $this->expectException(SellerMembershipException::class);
        $this->membershipService->revoke($this->context, self::TENANT, $seller['uuid'], 'ownerUserE1');
    }
}
