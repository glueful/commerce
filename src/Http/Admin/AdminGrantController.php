<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Operator surfaces over an already-issued download grant (design spec §8,
 * carried forward from Task 5): revoke (kill switch) and the audited
 * full-refund access override set/clear.
 *
 * Every mutation is a single guarded UPDATE
 * ({@see DownloadGrantRepository::revoke()}/`setOverride()`/`clearOverride()`)
 * affecting exactly one row on success. A zero-row result is ambiguous by
 * itself, so it's classified the same way every other guarded transition in
 * this codebase is (see
 * {@see \Glueful\Extensions\Commerce\Catalog\ReviewService}'s
 * `claimTransition()`/`ReviewStateException`): unknown/cross-tenant grant →
 * 404 (non-revealing, checked via a separate lookup BEFORE attempting the
 * guarded UPDATE); grant that exists but is already in the requested target
 * state → 409. This codebase never reports a repeat one-shot transition as an
 * idempotent 200 — 409 is the established house pattern, applied identically
 * to all three actions here for consistency.
 *
 * Every successful mutation appends an actor-bearing, INTERNAL-visibility
 * order event (`download.grant_revoked` / `download.override_set` /
 * `download.override_cleared`) carrying only the grant uuid + name — never
 * the token hash or blob uuid (design spec §8 whitelist). The admin response
 * body is likewise whitelisted ({@see self::projection()}): this is otherwise
 * the trusted, full-visibility operator surface (see other Admin*Controller
 * `linesProjection()` docblocks), but token_hash/blob_uuid stay internal-only
 * regardless of caller.
 */
final class AdminGrantController
{
    use ResolvesActor;

    public function __construct(
        private ApplicationContext $context,
        private ?DownloadGrantRepository $grants = null,
        private ?OrderRepository $orders = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->grants ??= app($context, DownloadGrantRepository::class);
        $this->orders ??= app($context, OrderRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    #[ApiOperation(summary: 'Revoke a digital-download grant', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Grant revoked')]
    #[ApiResponse(404, description: 'Grant not found')]
    #[ApiResponse(409, description: 'Grant already revoked')]
    public function revoke(Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $grant = $this->grant($tenant, $uuid);

        if (!$this->grants->revoke($this->context, $tenant, $uuid)) {
            return Response::error('Grant is already revoked.', 409);
        }

        $this->recordEvent($request, $grant, 'download.grant_revoked');

        return Response::success($this->projection($this->grant($tenant, $uuid)), 'Grant revoked');
    }

    #[ApiOperation(summary: 'Set a refund-access override for a grant', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Override set')]
    #[ApiResponse(404, description: 'Grant not found')]
    #[ApiResponse(409, description: 'Override already set')]
    public function setOverride(Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $grant = $this->grant($tenant, $uuid);

        if (!$this->grants->setOverride($this->context, $tenant, $uuid, $this->actorUuid($request))) {
            return Response::error('Refund-access override is already set.', 409);
        }

        $this->recordEvent($request, $grant, 'download.override_set');

        return Response::success($this->projection($this->grant($tenant, $uuid)), 'Override set');
    }

    #[ApiOperation(summary: 'Clear a grant refund-access override', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Override cleared')]
    #[ApiResponse(404, description: 'Grant not found')]
    #[ApiResponse(409, description: 'Override already cleared')]
    public function clearOverride(Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $grant = $this->grant($tenant, $uuid);

        if (!$this->grants->clearOverride($this->context, $tenant, $uuid)) {
            return Response::error('Refund-access override is already cleared.', 409);
        }

        $this->recordEvent($request, $grant, 'download.override_cleared');

        return Response::success($this->projection($this->grant($tenant, $uuid)), 'Override cleared');
    }

    /** @param array<string,mixed> $grant */
    private function recordEvent(Request $request, array $grant, string $type): void
    {
        $this->orders->recordEvent(
            $this->context,
            (string) $grant['order_uuid'],
            $type,
            ['grant_uuid' => (string) $grant['uuid'], 'name' => (string) $grant['name']],
            $this->actorUuid($request),
            'internal'
        );
    }

    /** @return array<string,mixed> */
    private function grant(string $tenant, string $uuid): array
    {
        $grant = $this->grants->findByUuid($this->context, $tenant, $uuid);
        if ($grant === null) {
            throw new NotFoundException('Resource not found.');
        }

        return $grant;
    }

    /**
     * @param array<string,mixed> $grant
     * @return array<string,mixed>
     */
    private function projection(array $grant): array
    {
        return [
            'grant_uuid' => (string) $grant['uuid'],
            'order_uuid' => (string) $grant['order_uuid'],
            'name' => (string) $grant['name'],
            'remaining' => $grant['remaining'] !== null ? (int) $grant['remaining'] : null,
            'expires_at' => $grant['expires_at'],
            'mint_count' => (int) $grant['mint_count'],
            'last_minted_at' => $grant['last_minted_at'],
            'revoked_at' => $grant['revoked_at'],
            'refund_access_override_at' => $grant['refund_access_override_at'],
            'refund_access_override_by' => $grant['refund_access_override_by'],
        ];
    }
}
