<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Seller;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\ChangeSellerMembershipRoleData;
use Glueful\Extensions\Commerce\Http\DTOs\GrantSellerMembershipData;
use Glueful\Extensions\Commerce\Http\DTOs\SellerMembershipListQuery;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipException;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Seller-scoped membership surface (design spec §2.5/§2.6/§2.8, MV1 Task 4).
 *
 * {@see self::mine()} is the ONE endpoint in this whole seller-scoped group
 * with no `{sellerUuid}` route resource -- it lists the AUTHENTICATED
 * caller's own active memberships in-tenant via
 * {@see SellerMembershipRepository::listActiveForUser()}'s
 * `(tenant_uuid, user_uuid)` index, so it never runs through
 * `commerce_seller` (there is no seller to authorize against).
 *
 * Every OTHER action here requires `commerce.seller.members.manage`
 * (design spec §2.6 grants it to `seller_owner` only -- there is no
 * `members.read` capability in v1, so even LISTING a seller's members is an
 * owner-only action through this surface).
 */
final class SellerMembershipController
{
    use ReadsSellerRequest;

    public function __construct(
        private ApplicationContext $context,
        private ?SellerMembershipService $memberships = null,
        private ?SellerMembershipRepository $membershipRepository = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->memberships ??= app($context, SellerMembershipService::class);
        $this->membershipRepository ??= app($context, SellerMembershipRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    #[ApiOperation(summary: "List the authenticated user's active seller memberships", tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Memberships retrieved')]
    public function mine(SellerMembershipListQuery $query, Request $request): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $tenant = $this->tenants->tenantUuid($this->context);

        $result = $this->membershipRepository->listActiveForUser(
            $this->context,
            $tenant,
            $this->principalUuid($request),
            $page,
            $perPage
        );

        return Response::paginated($result['items'], $result['total'], $page, $perPage, null, 'Memberships retrieved');
    }

    #[ApiOperation(summary: "List a seller's members", tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Members retrieved')]
    #[ApiResponse(404, description: 'Seller not found for this caller')]
    public function index(SellerMembershipListQuery $query, Request $request, string $sellerUuid): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $tenant = $this->tenants->tenantUuid($this->context);

        $result = $this->memberships->list($this->context, $tenant, $sellerUuid, $page, $perPage);

        return Response::paginated($result['items'], $result['total'], $page, $perPage, null, 'Members retrieved');
    }

    #[ApiOperation(summary: 'Grant a seller membership', tags: ['Commerce Seller'])]
    #[ApiResponse(201, description: 'Membership granted')]
    #[ApiResponse(409, description: 'Seller is suspended/closed, or the user already has an active membership')]
    #[ApiResponse(422, description: 'Validation failed (e.g. unrecognized role)')]
    public function store(GrantSellerMembershipData $input, Request $request, string $sellerUuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $membership = $this->memberships->grant(
                $this->context,
                $tenant,
                $sellerUuid,
                $input->user_uuid,
                $input->role,
                $this->principalUuid($request)
            );

            return Response::created($membership, 'Membership granted');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (SellerMembershipException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(summary: "Change a seller member's role", tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Membership role changed')]
    #[ApiResponse(404, description: 'Membership not found')]
    #[ApiResponse(409, description: 'Seller is suspended/closed, or this would remove the last owner')]
    #[ApiResponse(422, description: 'Validation failed (e.g. unrecognized role)')]
    public function update(
        ChangeSellerMembershipRoleData $input,
        Request $request,
        string $sellerUuid,
        string $userUuid
    ): Response {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $membership = $this->memberships->changeRole(
                $this->context,
                $tenant,
                $sellerUuid,
                $userUuid,
                $input->role,
                $this->principalUuid($request)
            );

            return Response::success($membership, 'Membership role changed');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (SellerMembershipException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(summary: 'Revoke a seller membership', tags: ['Commerce Seller'])]
    #[ApiResponse(204, description: 'Membership revoked')]
    #[ApiResponse(404, description: 'Membership not found')]
    #[ApiResponse(409, description: 'Seller is suspended/closed, or this would remove the last owner')]
    public function destroy(Request $request, string $sellerUuid, string $userUuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $this->memberships->revoke(
                $this->context,
                $tenant,
                $sellerUuid,
                $userUuid,
                $this->principalUuid($request)
            );

            return Response::noContent();
        } catch (SellerMembershipException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }
}
