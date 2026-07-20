<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\ActivateMarketplaceData;
use Glueful\Extensions\Commerce\Http\DTOs\AssignSellerData;
use Glueful\Extensions\Commerce\Http\DTOs\ChangeSellerMembershipRoleData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateSellerData;
use Glueful\Extensions\Commerce\Http\DTOs\GrantSellerMembershipData;
use Glueful\Extensions\Commerce\Http\DTOs\SellerLifecycleListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\SellerListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\SellerMembershipListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateSellerData;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyException;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyService;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceActivationException;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceActivationService;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\SellerAttributionException;
use Glueful\Extensions\Commerce\Marketplace\SellerAttributionService;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
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
 * gated on the install master switch (see routes.php).
 *
 * Activation/deactivation/adoption/transfer (Task 3) delegate to
 * {@see MarketplaceActivationService}/{@see SellerAttributionService} --
 * both ALSO operator-foundation surfaces (activation config precedes
 * activation itself; adoption/transfer are the promised inactive-mode
 * repair surface, design spec §2.3).
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
        private ?MarketplaceActivationService $activation = null,
        private ?SellerAttributionService $attribution = null,
        private ?CommissionPolicyService $commissionPolicy = null,
        private ?MarketplaceMode $marketplaceMode = null,
        private ?SellerLifecycleEventRepository $lifecycleEvents = null,
    ) {
        $this->sellers ??= app($context, SellerService::class);
        $this->memberships ??= app($context, SellerMembershipService::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        $this->activation ??= app($context, MarketplaceActivationService::class);
        $this->attribution ??= app($context, SellerAttributionService::class);
        $this->commissionPolicy ??= app($context, CommissionPolicyService::class);
        $this->marketplaceMode ??= app($context, MarketplaceMode::class);
        $this->lifecycleEvents ??= app($context, SellerLifecycleEventRepository::class);
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
            // A commission_kind/bps/fixed field (design spec §2.3, MV3 Task 4) is
            // operator-only and IS allowed here -- SellerService::update() routes
            // it through CommissionPolicyService for validation + a durable audit
            // row, using the resolved actor below.
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
        } catch (CommissionPolicyException $e) {
            return Response::validation(['commission' => $e->getMessage()]);
        }
    }

    #[ApiOperation(summary: 'Suspend a marketplace seller', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Seller suspended (or already suspended -- a stable no-op)')]
    #[ApiResponse(404, description: 'Seller not found')]
    #[ApiResponse(409, description: 'Seller cannot be suspended from its current status')]
    #[ApiResponse(422, description: 'reason is required')]
    public function suspendSeller(Request $request, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $seller = $this->sellers->suspend(
                $this->context,
                $tenant,
                $uuid,
                $this->reasonInput($request),
                (string) ($this->actorUuid($request) ?? '')
            );

            return Response::success($seller, 'Seller suspended');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (SellerLifecycleException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(summary: 'Reactivate a suspended marketplace seller', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Seller reactivated (or already active -- a stable no-op)')]
    #[ApiResponse(404, description: 'Seller not found')]
    #[ApiResponse(409, description: 'Seller cannot be reactivated from its current status')]
    #[ApiResponse(422, description: 'reason is required')]
    public function reactivateSeller(Request $request, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $seller = $this->sellers->reactivate(
                $this->context,
                $tenant,
                $uuid,
                $this->reasonInput($request),
                (string) ($this->actorUuid($request) ?? '')
            );

            return Response::success($seller, 'Seller reactivated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (SellerLifecycleException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(summary: 'Close a marketplace seller', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Seller closed')]
    #[ApiResponse(404, description: 'Seller not found')]
    #[ApiResponse(409, description: 'Seller still owns products, or is already closed')]
    #[ApiResponse(422, description: 'reason is required')]
    public function closeSeller(Request $request, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $seller = $this->sellers->close(
                $this->context,
                $tenant,
                $uuid,
                $this->reasonInput($request),
                (string) ($this->actorUuid($request) ?? '')
            );

            return Response::success($seller, 'Seller closed');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (SellerLifecycleException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(summary: "A seller's lifecycle audit history", tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Seller lifecycle history retrieved')]
    #[ApiResponse(404, description: 'Seller not found')]
    public function sellerLifecycle(SellerLifecycleListQuery $query, Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        // Re-read the seller through the tenant-scoped SellerService FIRST
        // (design spec §4): an unknown OR cross-tenant uuid is the SAME
        // non-revealing 404, mirroring every other seller-scoped operator
        // read (e.g. AdminReserveController::requireSeller()).
        $this->sellers->show($this->context, $tenant, $uuid);

        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));

        $result = $this->lifecycleEvents->paginatedForSeller($this->context, $tenant, $uuid, $page, $perPage);

        return Response::paginated(
            $result['items'],
            $result['total'],
            $page,
            $perPage,
            null,
            'Seller lifecycle history retrieved'
        );
    }

    /**
     * `reason` read from the JSON/form body -- never trusted as a string
     * without checking (a non-string value, e.g. an array, is treated as
     * blank so {@see SellerService}'s own guard rejects it with a 422
     * instead of a TypeError).
     */
    private function reasonInput(Request $request): string
    {
        $reason = $this->input($request)['reason'] ?? null;

        return is_string($reason) ? $reason : '';
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

    #[ApiOperation(summary: 'Activate marketplace mode for this workspace', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Marketplace activated')]
    #[ApiResponse(409, description: 'Unassigned products remain, or default_seller_uuid is not active')]
    #[ApiResponse(422, description: 'default_seller_uuid does not reference an existing seller')]
    public function activate(ActivateMarketplaceData $input, Request $request): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $settings = $this->activation->activate(
                $this->context,
                $tenant,
                $input->default_seller_uuid,
                $this->actorUuid($request)
            );

            return Response::success($settings, 'Marketplace activated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (MarketplaceActivationException $e) {
            return Response::error($e->getMessage(), 409, ['unassigned_count' => $e->unassignedCount]);
        } catch (SellerAttributionException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    #[ApiOperation(
        summary: 'Deactivate marketplace mode for this workspace',
        tags: ['Commerce Admin', 'Marketplace']
    )]
    #[ApiResponse(200, description: 'Marketplace deactivated (non-destructive)')]
    public function deactivate(Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $settings = $this->activation->deactivate($this->context, $tenant, $this->actorUuid($request));

        return Response::success($settings, 'Marketplace deactivated');
    }

    #[ApiOperation(
        summary: 'Set the workspace-level (fallback) commission policy',
        tags: ['Commerce Admin', 'Marketplace']
    )]
    #[ApiResponse(200, description: 'Workspace commission policy updated')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function updateWorkspaceCommission(Request $request): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $body = $this->input($request);

            $this->commissionPolicy->setWorkspace(
                $this->context,
                $tenant,
                $tenant,
                [
                    'kind' => $body['commission_kind'] ?? null,
                    'bps' => $body['commission_bps'] ?? null,
                    'fixed' => $body['commission_fixed'] ?? null,
                ],
                $this->actorUuid($request)
            );

            $settings = $this->marketplaceMode->settingsRowFor($this->context, $tenant);

            return Response::success($settings, 'Workspace commission policy updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (CommissionPolicyException $e) {
            return Response::validation(['commission' => $e->getMessage()]);
        }
    }

    #[ApiOperation(summary: 'Adopt or transfer a product to a seller', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Product attributed (adoption or transfer)')]
    #[ApiResponse(404, description: 'Product not found')]
    #[ApiResponse(409, description: 'Target seller not active, or ownership changed since it was read')]
    #[ApiResponse(422, description: 'seller_uuid does not reference an existing seller in this tenant')]
    public function assignSeller(AssignSellerData $input, Request $request, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $product = $this->attribution->assign(
                $this->context,
                $tenant,
                $uuid,
                $input->seller_uuid,
                $this->actorUuid($request)
            );

            return Response::success($product, 'Product attributed');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (SellerAttributionException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }
}
