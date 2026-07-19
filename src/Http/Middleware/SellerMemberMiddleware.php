<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Middleware;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Marketplace\Contracts\SellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * `commerce_seller:<capability>` (design spec §2.5/§2.8, MV1 Task 4): the
 * seller-member gate every `/commerce/seller/{sellerUuid}/...` route runs
 * through AFTER `auth` (and `tenant`, when tenancy is enabled) -- see
 * routes.php for the exact composition this mirrors.
 *
 * The seller is resolved ONLY from the route resource (`{sellerUuid}`, read
 * off the router's own `_route_params` request attribute) -- a body/query
 * seller identifier is never consulted, so it cannot be smuggled. The
 * principal is the post-auth `user` request attribute's `uuid` (the house
 * rule -- never `auth.user`, which is enrichment-optional).
 *
 * Fail-closed order (design spec §4/§5 error table), chosen so that no probe
 * can ever distinguish "seller exists but you're not a member" from "seller
 * doesn't exist":
 *
 * 1. Workspace not ACTIVE -> 409 for EVERY seller-member surface,
 *    unconditionally (design spec §2.3) -- this is a tenant-wide check, not
 *    seller-specific, so answering it first leaks nothing about a
 *    particular seller.
 * 2. Seller lookup + membership lookup are combined into ONE outcome: an
 *    unknown seller, a cross-tenant seller (the tenant predicate is baked
 *    into every read), no membership row at all, and a `revoked` membership
 *    are ALL the exact same non-revealing 404 -- same message, same shape.
 * 3. Lifecycle eligibility (design spec §2.6, MV5b Task 5):
 *    - A `suspended` seller fails closed with a stable 409 on EVERY route,
 *      regardless of HTTP method, UNLESS the route opts in via an explicit
 *      SECOND middleware parameter, `allow_suspended` (e.g.
 *      `commerce_seller:commerce.seller.orders.read,allow_suspended`) --
 *      {@see \Glueful\Routing\Router::resolveMiddleware()}'s own
 *      `name:param1,param2` parsing splits this into `$params[1]`. Only the
 *      minimum order-fulfillment + financial-read surface is marked (see
 *      routes.php): order list/detail, fulfill (incl. tracking), balance,
 *      and reserves. This REPLACES the prior method-based rule that allowed
 *      every GET/HEAD while suspended.
 *    - A `closed` seller NEVER qualifies for `allow_suspended` -- its
 *      pre-existing posture (409 on a MUTATION, i.e. any method other than
 *      GET/HEAD; reads pass through unchanged -- "reads stay, so staff can
 *      see state", design spec §2.4/§2.9) is untouched by this change. In
 *      practice a real `close()` also revokes every membership (so a closed
 *      seller's member never reaches this branch at all -- see step 2
 *      above); this branch exists to fail closed even for the
 *      unreachable-in-production case of a status flipped without a
 *      membership revoke.
 * 4. The capability, checked via the injected {@see SellerRoleAuthority}
 *    (never a hard-coded role list) against the membership's role -> 403
 *    when denied. This runs AFTER lifecycle eligibility and unconditionally
 *    on an `allow_suspended` route too -- the marker only relaxes the
 *    lifecycle gate, it never grants a capability.
 *
 * On success the resolved seller/membership rows are attached to the
 * request (`commerce_seller` / `commerce_seller_membership`) so downstream
 * controllers never need to re-query them.
 */
final class SellerMemberMiddleware implements RouteMiddleware
{
    public function __construct(
        private ApplicationContext $context,
        private SellerRepository $sellers,
        private SellerMembershipRepository $memberships,
        private SellerRoleAuthority $roles,
        private MarketplaceMode $marketplaceMode,
        private CurrentTenantResolver $tenants,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $capability = (string) ($params[0] ?? '');
        $allowSuspended = isset($params[1]) && (string) $params[1] === 'allow_suspended';

        /** @var array<string,mixed> $routeParams */
        $routeParams = (array) $request->attributes->get('_route_params', []);
        $sellerUuid = isset($routeParams['sellerUuid']) ? trim((string) $routeParams['sellerUuid']) : '';

        // Defensive only -- every route this middleware guards declares a
        // {sellerUuid} path segment, so the router never reaches here
        // without one. Fails closed rather than crashing.
        if ($sellerUuid === '' || $capability === '') {
            return Response::notFound('Resource not found.');
        }

        $tenant = $this->tenants->tenantUuid($this->context);

        // MarketplaceMode's own contract (see its class docblock): callers
        // must never call activeFor() when installEnabled() is false. This
        // branch is unreachable via routes.php (the whole seller group is
        // registered ONLY when the install switch is on), but the guard is
        // kept here so this middleware never violates that contract even if
        // constructed/dispatched outside the normal route registration path.
        if (!$this->marketplaceMode->installEnabled($this->context)) {
            return Response::notFound('Resource not found.');
        }

        if (!$this->marketplaceMode->activeFor($this->context, $tenant)) {
            return Response::error('Marketplace mode is not active for this workspace.', 409);
        }

        $userAttribute = $request->attributes->get('user');
        $principalUuid = is_array($userAttribute) ? trim((string) ($userAttribute['uuid'] ?? '')) : '';

        $seller = $principalUuid !== ''
            ? $this->sellers->findByUuid($this->context, $tenant, $sellerUuid)
            : null;
        $membership = $principalUuid !== ''
            ? $this->memberships->findBySellerAndUser($this->context, $tenant, $sellerUuid, $principalUuid)
            : null;

        if ($seller === null || $membership === null || (string) $membership['status'] !== 'active') {
            // Non-revealing (design spec §5): unknown seller, cross-tenant
            // seller, no membership, and a revoked membership are all this
            // SAME response -- never distinguished.
            return Response::notFound('Resource not found.');
        }

        $sellerStatus = (string) $seller['status'];
        if ($sellerStatus === 'suspended') {
            if (!$allowSuspended) {
                return Response::error("Seller is 'suspended'; this action is unavailable.", 409);
            }
        } elseif ($sellerStatus === 'closed') {
            $isMutation = !in_array($request->getMethod(), ['GET', 'HEAD'], true);
            if ($isMutation) {
                return Response::error("Seller is 'closed'; this action is unavailable.", 409);
            }
        }

        if (!$this->roles->allows((string) $membership['role'], $capability)) {
            return Response::forbidden('Insufficient seller role for this action.');
        }

        $request->attributes->set('commerce_seller', $seller);
        $request->attributes->set('commerce_seller_membership', $membership);

        return $next($request);
    }
}
