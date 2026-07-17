<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\ChangeSellerMembershipRoleData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateSellerData;
use Glueful\Extensions\Commerce\Http\DTOs\GrantSellerMembershipData;
use Glueful\Extensions\Commerce\Http\DTOs\SellerListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\SellerMembershipListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateSellerData;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleException;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipException;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipService;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Platform marketplace administration (design spec §2.8): sellers CRUD/
 * lifecycle plus membership admin, riding the existing `commerce:read`/
 * `commerce:write` scopes (the audited workspace bypass). These are OPERATOR
 * FOUNDATION surfaces -- available while the workspace is inactive (design
 * spec §2.3: "configuration precedes activation, which is what makes first
 * activation possible") -- so nothing here checks
 * `MarketplaceMode::activeFor()`; only the route group's registration is
 * gated on the install master switch (see routes.php). Activation/adoption/
 * transfer endpoints land in Task 3.
 */
final class MarketplaceAdminController
{
    use ReadsAdminInput;
    use ResolvesActor;

    public function __construct(
        private ApplicationContext $context,
        private ?SellerService $sellers = null,
        private ?SellerMembershipService $memberships = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->sellers ??= app($context, SellerService::class);
        $this->memberships ??= app($context, SellerMembershipService::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    #[ApiOperation(summary: 'List marketplace sellers', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Sellers retrieved')]
    public function indexSellers(SellerListQuery $query, Request $request): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $tenant = $this->tenants->tenantUuid($this->context);

        $result = $this->sellers->list(
            $this->context,
            $tenant,
            array_filter(
                ['q' => $query->q, 'status' => $query->status],
                static fn (mixed $value): bool => $value !== null
            ),
            $page,
            $perPage
        );

        return Response::paginated($result['items'], $result['total'], $page, $perPage, null, 'Sellers retrieved');
    }

    #[ApiOperation(summary: 'Get a marketplace seller', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Seller retrieved')]
    #[ApiResponse(404, description: 'Seller not found')]
    public function showSeller(Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);

        return Response::success($this->sellers->show($this->context, $tenant, $uuid), 'Seller retrieved');
    }

    #[ApiOperation(summary: 'Create a marketplace seller', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(201, description: 'Seller created (with its first owner membership)')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function storeSeller(CreateSellerData $input, Request $request): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $seller = $this->sellers->create(
                $this->context,
                $tenant,
                $input->slug,
                $input->name,
                $input->metadata,
                $input->owner_user_uuid,
                $this->actorUuid($request)
            );

            return Response::created($seller, 'Seller created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update a marketplace seller', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiRequestBody(schema: UpdateSellerData::class)]
    #[ApiResponse(200, description: 'Seller updated')]
    #[ApiResponse(404, description: 'Seller not found')]
    #[ApiResponse(422, description: 'Validation failed (e.g. attempting to change slug)')]
    public function updateSeller(Request $request, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $seller = $this->sellers->update(
                $this->context,
                $tenant,
                $uuid,
                $this->input($request),
                $this->actorUuid($request)
            );

            return Response::success($seller, 'Seller updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Suspend a marketplace seller', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Seller suspended')]
    #[ApiResponse(404, description: 'Seller not found')]
    #[ApiResponse(409, description: 'Seller cannot be suspended from its current status')]
    public function suspendSeller(Request $request, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $seller = $this->sellers->suspend($this->context, $tenant, $uuid, $this->actorUuid($request));

            return Response::success($seller, 'Seller suspended');
        } catch (SellerLifecycleException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(summary: 'Reactivate a suspended marketplace seller', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Seller reactivated')]
    #[ApiResponse(404, description: 'Seller not found')]
    #[ApiResponse(409, description: 'Seller cannot be reactivated from its current status')]
    public function reactivateSeller(Request $request, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $seller = $this->sellers->reactivate($this->context, $tenant, $uuid, $this->actorUuid($request));

            return Response::success($seller, 'Seller reactivated');
        } catch (SellerLifecycleException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(summary: 'Close a marketplace seller', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Seller closed')]
    #[ApiResponse(404, description: 'Seller not found')]
    #[ApiResponse(409, description: 'Seller still owns products, or is already closed')]
    public function closeSeller(Request $request, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $seller = $this->sellers->close($this->context, $tenant, $uuid, $this->actorUuid($request));

            return Response::success($seller, 'Seller closed');
        } catch (SellerLifecycleException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(summary: "List a seller's memberships", tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Memberships retrieved')]
    #[ApiResponse(404, description: 'Seller not found')]
    public function indexMemberships(SellerMembershipListQuery $query, Request $request, string $uuid): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $tenant = $this->tenants->tenantUuid($this->context);

        $result = $this->memberships->list($this->context, $tenant, $uuid, $page, $perPage);

        return Response::paginated(
            $result['items'],
            $result['total'],
            $page,
            $perPage,
            null,
            'Memberships retrieved'
        );
    }

    #[ApiOperation(summary: 'Grant a seller membership', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(201, description: 'Membership granted')]
    #[ApiResponse(404, description: 'Seller not found')]
    #[ApiResponse(409, description: 'Seller is suspended/closed, or the user already has an active membership')]
    #[ApiResponse(422, description: 'Validation failed (e.g. unrecognized role)')]
    public function storeMembership(GrantSellerMembershipData $input, Request $request, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $membership = $this->memberships->grant(
                $this->context,
                $tenant,
                $uuid,
                $input->user_uuid,
                $input->role,
                $this->actorUuid($request)
            );

            return Response::created($membership, 'Membership granted');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (SellerMembershipException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(summary: "Change a seller member's role", tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Membership role changed')]
    #[ApiResponse(404, description: 'Seller or membership not found')]
    #[ApiResponse(409, description: 'Seller is suspended/closed, or this would remove the last owner')]
    #[ApiResponse(422, description: 'Validation failed (e.g. unrecognized role)')]
    public function updateMembership(
        ChangeSellerMembershipRoleData $input,
        Request $request,
        string $uuid,
        string $userUuid
    ): Response {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $membership = $this->memberships->changeRole(
                $this->context,
                $tenant,
                $uuid,
                $userUuid,
                $input->role,
                $this->actorUuid($request)
            );

            return Response::success($membership, 'Membership role changed');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (SellerMembershipException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(summary: 'Revoke a seller membership', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(204, description: 'Membership revoked')]
    #[ApiResponse(404, description: 'Seller or membership not found')]
    #[ApiResponse(409, description: 'Seller is suspended/closed, or this would remove the last owner')]
    public function destroyMembership(Request $request, string $uuid, string $userUuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $this->memberships->revoke($this->context, $tenant, $uuid, $userUuid, $this->actorUuid($request));

            return Response::noContent();
        } catch (SellerMembershipException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }
}
