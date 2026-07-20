<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Middleware;

use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * `interactive_session` (design spec §2.8/§5, MV5c-1 Task 6): the JWT-
 * INTERACTIVE-ONLY gate every seller-API-key MANAGEMENT route
 * (`POST /{sellerUuid}/api-keys`, `GET /{sellerUuid}/api-keys`,
 * `POST /{sellerUuid}/api-keys/{lineageUuid}/rotate`,
 * `POST /{sellerUuid}/api-keys/{lineageUuid}/revoke`) runs through BEFORE
 * `commerce_seller:commerce.seller.apikeys.manage` -- see routes.php for the
 * exact `auth -> tenant (when enabled) -> interactive_session ->
 * commerce_seller:...` composition this relies on. A stolen or misused API
 * key must NEVER be able to mint, list, rotate, or revoke credentials --
 * "not an API key" alone is an insufficient predicate (design spec §2.8): a
 * future non-interactive provider (a service-to-service SAML/LDAP bearer,
 * for instance) must be refused too, not just the framework's own API-key
 * auth method.
 *
 * **The canonical POSITIVE predicate (verified against the framework source,
 * not assumed):** `AuthMiddleware::authenticateRequest()`
 * (`src/Routing/Middleware/AuthMiddleware.php`) sets `$user['auth_provider']`
 * to `'jwt'` when the presented credential looks like a JWT (three
 * dot-separated segments) and to `'api_key'` otherwise, UNLESS a provider
 * already populated `auth_provider` itself -- neither
 * `JwtAuthenticationProvider` nor `ApiKeyAuthenticationProvider` sets it, so
 * this fallback is what actually runs in practice. `AuthMiddleware::handle()`
 * then copies that value onto the REQUEST attribute
 * (`$request->attributes->set('auth_provider', $user['auth_provider'] ??
 * 'unknown')`) -- this is the attribute this middleware reads.
 * `ApiKeyAuthenticationProvider::authenticate()` additionally sets the
 * request's `api_key_uuid` attribute directly (never present for a JWT
 * session). This middleware therefore requires BOTH
 * `auth_provider === 'jwt'` AND the ABSENCE of `api_key_uuid` -- a positive
 * JWT check, never merely `auth_method !== 'api_key'` (design spec §2.8) --
 * so a hypothetical future provider that is neither JWT nor the framework's
 * own API-key auth (e.g. SAML/LDAP) is refused here too, exactly like an API
 * key is.
 *
 * Refusal is a stable 403 on EVERY management route, regardless of HTTP
 * method or whether the underlying principal would otherwise hold
 * `apikeys.manage` -- this gate runs BEFORE `commerce_seller` ever resolves
 * the seller/membership/capability, so a denial here is never
 * distinguishable from (nor dependent on) seller-membership state.
 */
final class InteractiveSessionMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $authProvider = (string) $request->attributes->get('auth_provider', '');
        $isInteractiveJwt = $authProvider === 'jwt' && !$request->attributes->has('api_key_uuid');

        if (!$isInteractiveJwt) {
            return Response::forbidden('An interactive session is required to manage API keys.');
        }

        return $next($request);
    }
}
