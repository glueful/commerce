<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Seller;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\CreateSellerApiKeyData;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyException;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Seller self-service API-key management (design spec §2.8/§5, MV5c-1 Task
 * 6): create/list/rotate/revoke, own-seller-only. Every route runs behind
 * `interactive_session` (JWT-interactive-only, see
 * {@see \Glueful\Extensions\Commerce\Http\Middleware\InteractiveSessionMiddleware})
 * THEN `commerce_seller:commerce.seller.apikeys.manage` (see routes.php) --
 * so this controller is only ever reached by an interactive JWT session
 * whose live seller role already holds `apikeys.manage`; a key can never
 * reach a method on this class at all, let alone mint/list/rotate/revoke
 * another key.
 *
 * The tenant is always the RESOLVED session tenant
 * ({@see CurrentTenantResolver}), and the seller is always the route
 * `{sellerUuid}` `commerce_seller` already authorized the caller against --
 * NEVER a body-supplied tenant/seller (design spec §2.8). The acting subject
 * for create/rotate/revoke is always the authenticated principal (the SAME
 * `ReadsSellerRequest::principalUuid()` house rule every other seller
 * controller uses), never a caller-supplied actor -- the underlying
 * {@see SellerApiKeyService} re-derives that actor's LIVE membership/role
 * inside its own transaction regardless (see that class's docblock), so this
 * controller never needs to (and never should) pre-validate authority itself.
 *
 * **Exception mapping (Task 6 contract):** {@see ValidationException} and
 * {@see SellerApiKeyException} (the service's own live-authority domain
 * exception -- `seller_inactive`/`membership_inactive`/`capability_denied`)
 * both map to `422`. `NotFoundException`/`ConflictException` -- thrown by
 * {@see SellerApiKeyService} for an unknown seller, an unknown/cross-seller
 * lineage, or a rotate against an already-revoked lineage -- are NOT caught
 * here: both extend the framework's `HttpException` and already auto-map to
 * `404`/`409` through the standard exception handler, exactly like every
 * other seller controller in this codebase (see e.g.
 * {@see \Glueful\Extensions\Commerce\Http\Admin\AdminReserveController}'s
 * own docblock for the same convention).
 */
final class SellerApiKeyController
{
    use ReadsSellerRequest;

    public function __construct(
        private ApplicationContext $context,
        private ?SellerApiKeyService $apiKeys = null,
        private ?SellerApiKeyRepository $apiKeyRepository = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->apiKeys ??= app($context, SellerApiKeyService::class);
        $this->apiKeyRepository ??= app($context, SellerApiKeyRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    #[ApiOperation(summary: "Create a seller API key", tags: ['Commerce Seller'])]
    #[ApiResponse(201, description: 'API key created; the raw secret is returned exactly once')]
    #[ApiResponse(403, description: 'Interactive JWT session required, or insufficient seller role')]
    #[ApiResponse(404, description: 'Unknown seller')]
    #[ApiResponse(422, description: 'Validation failed, or the acting user no longer holds apikeys.manage')]
    public function store(CreateSellerApiKeyData $input, Request $request, string $sellerUuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $result = $this->apiKeys->create(
                $this->context,
                $tenant,
                $sellerUuid,
                $input->name,
                $input->declared_scopes,
                $input->expires_at,
                $this->principalUuid($request)
            );

            return Response::created(
                $this->lineageProjection($result['lineage']) + ['secret' => $result['plain_key']],
                'Seller API key created'
            );
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (SellerApiKeyException $e) {
            return Response::error($e->getMessage(), 422);
        }
    }

    #[ApiOperation(summary: "List a seller's API keys", tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'API key lineages retrieved -- NEVER includes a secret')]
    #[ApiResponse(403, description: 'Interactive JWT session required, or insufficient seller role')]
    public function index(Request $request, string $sellerUuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $lineages = $this->apiKeyRepository->listForSeller($this->context, $tenant, $sellerUuid);

        return Response::success(
            array_map(fn (array $lineage): array => $this->lineageProjection($lineage), $lineages),
            'Seller API keys retrieved'
        );
    }

    #[ApiOperation(summary: "Rotate a seller API key", tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'API key rotated; the new raw secret is returned exactly once')]
    #[ApiResponse(403, description: 'Interactive JWT session required, or insufficient seller role')]
    #[ApiResponse(404, description: 'Unknown seller or unknown/cross-seller lineage')]
    #[ApiResponse(409, description: 'Lineage already revoked')]
    public function rotate(Request $request, string $sellerUuid, string $lineageUuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $result = $this->apiKeys->rotate(
                $this->context,
                $tenant,
                $sellerUuid,
                $lineageUuid,
                $this->principalUuid($request)
            );

            return Response::success(
                $this->lineageProjection($result['lineage']) + ['secret' => $result['plain_key']],
                'Seller API key rotated'
            );
        } catch (SellerApiKeyException $e) {
            return Response::error($e->getMessage(), 422);
        }
    }

    #[ApiOperation(summary: "Revoke a seller API key (whole lineage)", tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'API key lineage revoked -- every generation, current and grace predecessor')]
    #[ApiResponse(403, description: 'Interactive JWT session required, or insufficient seller role')]
    #[ApiResponse(404, description: 'Unknown seller or unknown/cross-seller lineage')]
    public function revoke(Request $request, string $sellerUuid, string $lineageUuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $result = $this->apiKeys->revoke(
                $this->context,
                $tenant,
                $sellerUuid,
                $lineageUuid,
                $this->principalUuid($request)
            );

            return Response::success($this->lineageProjection($result['lineage']), 'Seller API key revoked');
        } catch (SellerApiKeyException $e) {
            return Response::error($e->getMessage(), 422);
        }
    }

    /**
     * The lineage-metadata allowlist projection (design spec §2.8): `uuid`,
     * `name`, `declared_scopes`, an EFFECTIVE `status` (see
     * {@see self::effectiveStatus()} -- `active`/`expired`/`revoked`,
     * folding a lapsed `expires_at` in even though the persisted `status`
     * column stays `active` until an explicit revoke), `expires_at`,
     * `last_rotated_at`, `created_by` -- and NEVER a secret. Create/rotate
     * layer the raw secret on TOP of this same projection (`+ ['secret' =>
     * ...]`) rather than duplicating the field list, so list/create/rotate
     * can never accidentally diverge on what counts as "lineage metadata".
     *
     * @param array<string,mixed> $lineage
     * @return array<string,mixed>
     */
    private function lineageProjection(array $lineage): array
    {
        return [
            'uuid' => (string) $lineage['uuid'],
            'name' => (string) $lineage['name'],
            'declared_scopes' => $lineage['declared_scopes'] ?? [],
            'status' => self::effectiveStatus($lineage),
            'expires_at' => $lineage['expires_at'] ?? null,
            'last_rotated_at' => $lineage['last_rotated_at'] ?? null,
            'created_by' => (string) $lineage['created_by'],
        ];
    }

    /**
     * `revoked` (persisted) stays `revoked`. An `active` lineage whose
     * `expires_at` has already passed reports `expired` -- the underlying
     * framework key's own expiry is the actual enforcement mechanism (this
     * is a read-time DISPLAY fold only, never written back) -- compared
     * against PHP's OWN UTC wall clock, mirroring
     * {@see \Glueful\Extensions\Commerce\Marketplace\SellerApiKeyAuthorizer::credentialIsUsable()}'s
     * identical "no extra DB round-trip for a read-only display value"
     * reasoning. Anything else `active`.
     *
     * @param array<string,mixed> $lineage
     */
    private static function effectiveStatus(array $lineage): string
    {
        $status = (string) ($lineage['status'] ?? 'active');
        if ($status !== 'active') {
            return $status;
        }

        $expiresAt = $lineage['expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '' && $expiresAt <= gmdate('Y-m-d H:i:s')) {
            return 'expired';
        }

        return 'active';
    }
}
