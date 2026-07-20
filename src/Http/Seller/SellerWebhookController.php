<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Seller;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\RegisterSellerWebhookEndpointData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateSellerWebhookEndpointData;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookException;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Seller self-service outbound-webhook management (design spec §2.2/§2.6/
 * §2.9/§2.10/§5, MV5c-2 Task 7): register/list/update/rotate-secret/disable/
 * enable/delete + delivery history + dead-letter replay -- own-seller-only,
 * JWT-interactive-ONLY. Every route runs behind `interactive_session`
 * (see {@see \Glueful\Extensions\Commerce\Http\Middleware\InteractiveSessionMiddleware})
 * THEN `commerce_seller:commerce.seller.webhooks.manage` (see routes.php) --
 * mirroring {@see SellerApiKeyController}'s EXACT surface shape, so this
 * controller is only ever reached by an interactive JWT session whose live
 * seller role already holds `webhooks.manage`; an API key can NEVER reach
 * a method on this class at all, let alone register/read/rotate/replay a
 * webhook.
 *
 * The tenant is always the RESOLVED session tenant ({@see CurrentTenantResolver}),
 * the seller is always the route `{sellerUuid}` `commerce_seller` already
 * authorized the caller against, and the acting subject for every mutation
 * is always the authenticated principal ({@see ReadsSellerRequest::principalUuid()})
 * -- NEVER a body-supplied actor/tenant/seller (design spec §2.10) -- the
 * underlying {@see SellerWebhookEndpointService} re-derives that actor's
 * LIVE membership/role inside its own transaction regardless.
 *
 * **Cross-resource ownership (the one gap the services below do NOT close
 * themselves):** {@see SellerWebhookEndpointService}'s own mutation methods
 * (`updateEndpoint()`/`rotateSecret()`/`disable()`/`enable()`/`delete()`)
 * already verify `{uuid}` belongs to `{sellerUuid}` internally (their shared
 * `claimAndAuthorize()`). But {@see SellerWebhookDeliveryRepository::deliveriesForEndpoint()}
 * and {@see SellerWebhookDeliveryService::replay()} take NO `$sellerUuid`
 * (replay derives its own seller/endpoint straight from the delivery row) --
 * so THIS controller is the one place that must verify the route's
 * `{uuid}`/`{deliveryUuid}` chain actually belongs to the route's
 * `{sellerUuid}` BEFORE ever calling either: {@see self::requireOwnedEndpoint()}
 * (endpoint.seller_uuid === route sellerUuid) and
 * {@see self::requireOwnedDelivery()} (delivery.endpoint_uuid === route
 * endpoint uuid, chaining back to the SAME seller check). Both throw the
 * SAME non-revealing {@see NotFoundException} every other mutation in this
 * subsystem uses for "unknown/cross-seller/tombstoned" -- deliberately
 * un-caught here, so it auto-maps 404 through the framework's own exception
 * handler exactly like {@see SellerApiKeyController}'s docblock describes.
 *
 * **Exception mapping (Task 7 contract, design spec §5):**
 * {@see ValidationException} and {@see SellerWebhookException} both map to
 * `422` -- INCLUDING an SSRF-rejected URL (`unsafe_url`, whose message is
 * already generic and never embeds a resolved internal address) -- with ONE
 * deliberate exception: {@see self::replay()} maps the specific
 * `delivery_not_replayable` error code to `409` (an incompatible resource
 * STATE, not an authority/safety failure) while every other
 * {@see SellerWebhookException} code it can throw (`seller_inactive`,
 * `endpoint_inactive`) stays `422`, consistent with every other method here.
 * `NotFoundException` is NEVER caught -- it already extends the framework's
 * `HttpException` and auto-maps to `404`, exactly like every other seller
 * controller in this codebase.
 */
final class SellerWebhookController
{
    use ReadsSellerRequest;

    public function __construct(
        private ApplicationContext $context,
        private ?SellerWebhookEndpointService $endpoints = null,
        private ?SellerWebhookEndpointRepository $endpointRepository = null,
        private ?SellerWebhookDeliveryRepository $deliveryRepository = null,
        private ?SellerWebhookDeliveryService $deliveryService = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->endpoints ??= app($context, SellerWebhookEndpointService::class);
        $this->endpointRepository ??= app($context, SellerWebhookEndpointRepository::class);
        $this->deliveryRepository ??= app($context, SellerWebhookDeliveryRepository::class);
        $this->deliveryService ??= app($context, SellerWebhookDeliveryService::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    #[ApiOperation(summary: 'Register a seller webhook endpoint', tags: ['Commerce Seller'])]
    #[ApiResponse(201, description: 'Endpoint registered; the signing secret is returned exactly once')]
    #[ApiResponse(403, description: 'Interactive JWT session required, or insufficient seller role')]
    #[ApiResponse(404, description: 'Unknown seller')]
    #[ApiResponse(422, description: 'Validation failed, an unsafe URL, or the acting user no longer holds '
        . 'webhooks.manage')]
    public function store(RegisterSellerWebhookEndpointData $input, Request $request, string $sellerUuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $result = $this->endpoints->register(
                $this->context,
                $tenant,
                $sellerUuid,
                $input->url,
                $input->events,
                $this->principalUuid($request)
            );

            return Response::created(
                $this->endpointProjection($result['endpoint']) + ['secret' => $result['secret']],
                'Seller webhook endpoint registered'
            );
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (SellerWebhookException $e) {
            return Response::error($e->getMessage(), 422);
        }
    }

    #[ApiOperation(summary: "List a seller's webhook endpoints", tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Endpoints retrieved -- NEVER includes a secret or a deleted (tombstoned) '
        . 'endpoint')]
    #[ApiResponse(403, description: 'Interactive JWT session required, or insufficient seller role')]
    public function index(Request $request, string $sellerUuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $rows = $this->endpointRepository->listForSeller($this->context, $tenant, $sellerUuid);

        return Response::success(
            array_map(fn (array $row): array => $this->endpointProjection($row), $rows),
            'Seller webhook endpoints retrieved'
        );
    }

    #[ApiOperation(summary: 'Update a seller webhook endpoint', tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Endpoint updated -- NEVER returns a secret')]
    #[ApiResponse(403, description: 'Interactive JWT session required, or insufficient seller role')]
    #[ApiResponse(404, description: 'Unknown or cross-seller endpoint')]
    #[ApiResponse(422, description: 'Validation failed, an unsafe URL, or the acting user no longer holds '
        . 'webhooks.manage')]
    public function update(
        UpdateSellerWebhookEndpointData $input,
        Request $request,
        string $sellerUuid,
        string $uuid
    ): Response {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $result = $this->endpoints->updateEndpoint(
                $this->context,
                $tenant,
                $sellerUuid,
                $uuid,
                $input->url,
                $input->events,
                $this->principalUuid($request)
            );

            return Response::success(
                $this->endpointProjection($result['endpoint']),
                'Seller webhook endpoint updated'
            );
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (SellerWebhookException $e) {
            return Response::error($e->getMessage(), 422);
        }
    }

    #[ApiOperation(summary: 'Rotate a seller webhook endpoint signing secret', tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Secret rotated; the new raw secret is returned exactly once')]
    #[ApiResponse(403, description: 'Interactive JWT session required, or insufficient seller role')]
    #[ApiResponse(404, description: 'Unknown or cross-seller endpoint')]
    #[ApiResponse(422, description: 'The acting user no longer holds webhooks.manage, or no current secret exists')]
    public function rotateSecret(Request $request, string $sellerUuid, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $result = $this->endpoints->rotateSecret(
                $this->context,
                $tenant,
                $sellerUuid,
                $uuid,
                $this->principalUuid($request)
            );

            return Response::success(
                $this->endpointProjection($result['endpoint']) + ['secret' => $result['secret']],
                'Seller webhook endpoint secret rotated'
            );
        } catch (SellerWebhookException $e) {
            return Response::error($e->getMessage(), 422);
        }
    }

    #[ApiOperation(summary: 'Disable a seller webhook endpoint', tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Endpoint disabled; its pending deliveries are paused')]
    #[ApiResponse(403, description: 'Interactive JWT session required, or insufficient seller role')]
    #[ApiResponse(404, description: 'Unknown or cross-seller endpoint')]
    #[ApiResponse(422, description: 'The acting user no longer holds webhooks.manage')]
    public function disable(Request $request, string $sellerUuid, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $result = $this->endpoints->disable(
                $this->context,
                $tenant,
                $sellerUuid,
                $uuid,
                $this->principalUuid($request)
            );

            return Response::success($this->endpointProjection($result['endpoint']), 'Seller webhook endpoint '
                . 'disabled');
        } catch (SellerWebhookException $e) {
            return Response::error($e->getMessage(), 422);
        }
    }

    #[ApiOperation(summary: 'Enable a seller webhook endpoint', tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Endpoint re-enabled; SSRF-revalidated against its stored URL')]
    #[ApiResponse(403, description: 'Interactive JWT session required, or insufficient seller role')]
    #[ApiResponse(404, description: 'Unknown or cross-seller endpoint')]
    #[ApiResponse(422, description: 'The stored URL no longer passes SSRF validation, or the acting user no '
        . 'longer holds webhooks.manage')]
    public function enable(Request $request, string $sellerUuid, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $result = $this->endpoints->enable(
                $this->context,
                $tenant,
                $sellerUuid,
                $uuid,
                $this->principalUuid($request)
            );

            return Response::success($this->endpointProjection($result['endpoint']), 'Seller webhook endpoint '
                . 'enabled');
        } catch (SellerWebhookException $e) {
            return Response::error($e->getMessage(), 422);
        }
    }

    #[ApiOperation(summary: 'Delete (tombstone) a seller webhook endpoint', tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Endpoint tombstoned; secrets revoked, pending/paused deliveries canceled')]
    #[ApiResponse(403, description: 'Interactive JWT session required, or insufficient seller role')]
    #[ApiResponse(404, description: 'Unknown, cross-seller, or already-deleted endpoint')]
    #[ApiResponse(422, description: 'The acting user no longer holds webhooks.manage')]
    public function destroy(Request $request, string $sellerUuid, string $uuid): Response
    {
        try {
            $tenant = $this->tenants->tenantUuid($this->context);
            $result = $this->endpoints->delete(
                $this->context,
                $tenant,
                $sellerUuid,
                $uuid,
                $this->principalUuid($request)
            );

            return Response::success($this->endpointProjection($result['endpoint']), 'Seller webhook endpoint '
                . 'deleted');
        } catch (SellerWebhookException $e) {
            return Response::error($e->getMessage(), 422);
        }
    }

    #[ApiOperation(summary: 'Read a seller webhook endpoint delivery history', tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Sanitized delivery history retrieved -- no secret, no claim/lease internals, '
        . 'no internal address')]
    #[ApiResponse(403, description: 'Interactive JWT session required, or insufficient seller role')]
    #[ApiResponse(404, description: 'Unknown or cross-seller endpoint')]
    public function deliveries(Request $request, string $sellerUuid, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $this->requireOwnedEndpoint($tenant, $sellerUuid, $uuid);

        $rows = $this->deliveryRepository->deliveriesForEndpoint($this->context, $tenant, $uuid);

        return Response::success(
            array_map(fn (array $row): array => $this->deliveryProjection($row), $rows),
            'Seller webhook endpoint delivery history retrieved'
        );
    }

    #[ApiOperation(summary: 'Replay a dead-letter seller webhook delivery', tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'A new delivery attempt lineage was scheduled; the original attempt history '
        . 'is unchanged')]
    #[ApiResponse(403, description: 'Interactive JWT session required, or insufficient seller role')]
    #[ApiResponse(404, description: 'Unknown or cross-seller endpoint/delivery')]
    #[ApiResponse(409, description: 'The delivery is not currently dead_letter (e.g. canceled)')]
    #[ApiResponse(422, description: 'The seller is not active, or the endpoint is not active')]
    public function replay(Request $request, string $sellerUuid, string $uuid, string $deliveryUuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $this->requireOwnedEndpoint($tenant, $sellerUuid, $uuid);
        $this->requireOwnedDelivery($tenant, $uuid, $deliveryUuid);

        try {
            $replay = $this->deliveryService->replay($this->context, $tenant, $deliveryUuid);

            return Response::success(
                $this->deliveryProjection($replay),
                'Seller webhook delivery replay scheduled'
            );
        } catch (SellerWebhookException $e) {
            $status = $e->errorCode === 'delivery_not_replayable' ? 409 : 422;

            return Response::error($e->getMessage(), $status);
        }
    }

    /**
     * {@see SellerWebhookDeliveryRepository::deliveriesForEndpoint()}/
     * {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService::replay()}
     * take NO `$sellerUuid` at all -- this class's own docblock explains why
     * this check must live HERE. `findByUuid()` already excludes a
     * tombstoned (`deleted_at IS NOT NULL`) endpoint (the framework's
     * soft-delete auto-filter), so a deleted endpoint's delivery history and
     * replay are BOTH refused this SAME non-revealing 404 -- "a deleted
     * endpoint can never be resurrected" (design spec §2.2) extended to its
     * read/replay surfaces too.
     */
    private function requireOwnedEndpoint(string $tenant, string $sellerUuid, string $endpointUuid): void
    {
        $endpoint = $this->endpointRepository->findByUuid($this->context, $tenant, $endpointUuid);
        if ($endpoint === null || (string) $endpoint['seller_uuid'] !== $sellerUuid) {
            throw new NotFoundException('Resource not found.');
        }
    }

    /**
     * Chains the delivery back to the SAME endpoint the route already proved
     * belongs to this seller ({@see self::requireOwnedEndpoint()}) -- closing
     * the gap that {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService::replay()}
     * derives its OWN seller/endpoint straight from the delivery row, never
     * from a caller-supplied seller/endpoint.
     */
    private function requireOwnedDelivery(string $tenant, string $endpointUuid, string $deliveryUuid): void
    {
        $delivery = $this->deliveryRepository->findByUuid($this->context, $tenant, $deliveryUuid);
        if ($delivery === null || (string) $delivery['endpoint_uuid'] !== $endpointUuid) {
            throw new NotFoundException('Resource not found.');
        }
    }

    /**
     * The endpoint allowlist projection: `uuid`, `url`, `events` (the
     * decoded `subscribed_events`), `status`, `consecutive_failures`,
     * `created_by`, `disabled_at`, `disabled_reason`, `deleted_at`,
     * `created_at`, `updated_at` -- and NEVER a secret, `id`, `tenant_uuid`,
     * or `revision`. Create/rotate layer the raw secret on TOP of this SAME
     * projection (`+ ['secret' => ...]`), mirroring
     * {@see SellerApiKeyController::lineageProjection()}'s identical
     * "single allow-list, secret always layered on top" convention.
     *
     * @param array<string,mixed> $endpoint
     * @return array<string,mixed>
     */
    private function endpointProjection(array $endpoint): array
    {
        return [
            'uuid' => (string) $endpoint['uuid'],
            'url' => (string) $endpoint['url'],
            'events' => $endpoint['subscribed_events'] ?? [],
            'status' => (string) $endpoint['status'],
            'consecutive_failures' => (int) $endpoint['consecutive_failures'],
            'created_by' => (string) $endpoint['created_by'],
            'disabled_at' => $endpoint['disabled_at'] ?? null,
            'disabled_reason' => $endpoint['disabled_reason'] ?? null,
            'deleted_at' => $endpoint['deleted_at'] ?? null,
            'created_at' => $endpoint['created_at'] ?? null,
            'updated_at' => $endpoint['updated_at'] ?? null,
        ];
    }

    /**
     * The SAME allowlist {@see SellerWebhookDeliveryRepository::deliveriesForEndpoint()}
     * already enforces at the SQL level -- applied here a second time as an
     * application-level guard, since {@see self::replay()}'s newly-created
     * row instead comes back from
     * {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService::replay()}
     * as a FULL, unsanitized DB row (including `claim_token`/`tenant_uuid`/
     * `endpoint_uuid`/`webhook_event_uuid`/`seller_uuid`/`id`) -- this
     * method is what keeps that response identically shaped to the list
     * read, and identically secret/internal-free.
     *
     * @param array<string,mixed> $delivery
     * @return array<string,mixed>
     */
    private function deliveryProjection(array $delivery): array
    {
        return [
            'uuid' => (string) $delivery['uuid'],
            'status' => (string) $delivery['status'],
            'attempts' => (int) $delivery['attempts'],
            'next_attempt_at' => $delivery['next_attempt_at'] ?? null,
            'paused_at' => $delivery['paused_at'] ?? null,
            'paused_remaining_seconds' => $delivery['paused_remaining_seconds'] ?? null,
            'pause_reason' => $delivery['pause_reason'] ?? null,
            'last_attempt_at' => $delivery['last_attempt_at'] ?? null,
            'last_status_code' => $delivery['last_status_code'] ?? null,
            'last_error' => $delivery['last_error'] ?? null,
            'replay_of_uuid' => $delivery['replay_of_uuid'] ?? null,
            'created_at' => $delivery['created_at'] ?? null,
            'updated_at' => $delivery['updated_at'] ?? null,
        ];
    }
}
