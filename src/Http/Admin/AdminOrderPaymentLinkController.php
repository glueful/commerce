<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkException;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkService;
use Glueful\Extensions\Commerce\Orders\PaymentSessionExposureGuard;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * THE ONE HTTP OWNER of payment-link mint, revoke, and status (payment-links
 * Task 8, design spec §2.2 "Catalog entries (manage mode)":
 * `orders.payment_link.store|destroy|show`).
 *
 * "One owner" is a custody statement, not tidiness. A payment link is a BEARER
 * credential, and every additional route that can mint one is another place a
 * raw token can escape, another error-mapping table to keep honest, and another
 * surface to audit. Embedding hosts mount THESE catalog entries -- Thallo's pack
 * adds only its own `.../payment-link/send` delivery route on top and never
 * redeclares these method/path pairs.
 *
 * ## The three rules this class exists to keep
 *
 *  1. `store()` calls {@see PaymentLinkService::mintPublic()}, NEVER `mint()`.
 *     `mint()` hands back a bare token; `mintPublic()` composes and VALIDATES
 *     the public URL before the mint transaction opens, so a host with no
 *     public-URL provider mints nothing and no operator is shown a "link
 *     created" nobody can open. The URL carries the token exactly once and is
 *     returned exactly once -- there is no way to re-read it later, which is
 *     precisely why {@see self::show()} exists.
 *  2. `show()` publishes STATE, EXPIRY, and EXPOSURE only. Never the token,
 *     never its hash. It is the token-free half of the surface, which is what
 *     lets an operator UI stay truthful after the one-time URL is gone.
 *  3. Every UNKNOWN throwable becomes a BODILESS 500. The service's typed
 *     refusals are a closed set ({@see PaymentLinkException::ERROR_CODES}) and
 *     each gets an explicit status below; everything else -- a lost connection,
 *     a deadlock, a constraint violation, a `LogicException` from a caller bug --
 *     is an engine internal. Its message may quote SQL, a provider payload, or a
 *     URL, and its `getTraceAsString()` may quote arguments, so NOTHING about it
 *     reaches the wire.
 *
 * ## Transactions
 *
 * This class opens NONE. Every action delegates to a service method that owns
 * its own transaction and its own lock order (ORDER, then link). That matters
 * beyond style: {@see PaymentLinkService::initiateByToken()} REFUSES to run
 * inside a caller's transaction (a nested Phase A would hold row locks across a
 * provider network call), and a controller that had acquired the habit of
 * wrapping service calls would eventually wrap that one. Initiation itself is
 * not mounted here at all -- it is the host's unauthenticated payer surface.
 *
 * ## Rate limiting is NOT here, and that is deliberate
 *
 * These three routes are authenticated operator endpoints behind the admin
 * middleware stack. The engine's per-link hourly ceiling
 * ({@see PaymentLinkRepository::claimInitiationWindow()}) bounds how often ONE
 * KNOWN link may open a checkout; it cannot bound an anonymous prober guessing
 * UNKNOWN tokens, because a guess never resolves to a link to count against.
 * That is route-level throttling on the host's public landing/initiate routes
 * (design spec §2.3), not something the engine can express.
 */
final class AdminOrderPaymentLinkController
{
    use ReadsAdminInput;
    use ResolvesActor;

    /**
     * The COMPLETE typed-refusal mapping, in one place so this controller and
     * Thallo's client publish one table rather than two.
     *
     * All twelve codes are mapped even though only the first five are reachable
     * from mint/revoke/status: a partial map would mean a future service change
     * silently falling through to a default, and the point of a closed
     * discriminator domain is that nothing falls through.
     * `AdminOrderPaymentLinkEndpointTest` asserts this map and
     * `PaymentLinkException::ERROR_CODES` are the same set.
     *
     * 503 for the "unavailable" family (no public-URL provider, no return
     * routes, a manual or broken gateway): those are CONFIGURATION or provider
     * facts, not client mistakes, and a 4xx would tell an operator to change
     * their request when the fix is in the install.
     *
     * @var array<string,int>
     */
    public const STATUS_BY_ERROR_CODE = [
        PaymentLinkException::ORDER_NOT_FOUND => 404,
        PaymentLinkException::ORDER_NOT_ADMIN_ORIGIN => 409,
        PaymentLinkException::ORDER_NOT_PENDING_PAYMENT => 409,
        PaymentLinkException::LINK_CHANGED => 409,
        PaymentLinkException::PUBLIC_URL_UNAVAILABLE => 503,
        // Initiation codes: unreachable from these three actions today (the
        // payer surface belongs to the host), mapped so they cannot become a
        // default if that ever changes.
        PaymentLinkException::PAYMENT_LINK_NOT_PAYABLE => 404,
        PaymentLinkException::INITIATION_RATE_LIMITED => 429,
        PaymentLinkException::RETURN_URL_UNAVAILABLE => 503,
        PaymentLinkException::CHECKOUT_MANUAL => 503,
        PaymentLinkException::CHECKOUT_URL_MISSING => 503,
        PaymentLinkException::CHECKOUT_URL_UNTRUSTED => 503,
        PaymentLinkException::CHECKOUT_INITIATION_FAILED => 503,
    ];

    private CurrentTenantResolver $tenants;
    private PaymentSessionExposureGuard $exposure;

    public function __construct(
        private ApplicationContext $context,
        private ?PaymentLinkService $links = null,
        private ?OrderRepository $orders = null,
        ?CurrentTenantResolver $tenants = null,
        ?PaymentSessionExposureGuard $exposure = null,
    ) {
        $this->orders ??= app($context, OrderRepository::class);
        $this->tenants = $tenants ?? (container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver());
        $this->exposure = $exposure ?? new PaymentSessionExposureGuard(
            new PaymentLinkRepository(),
            $this->orders
        );
    }

    /**
     * Resolved LAZILY, not in the constructor: `PaymentLinkService` pulls the
     * public-URL seam, the return-URL seam, and the payment collector behind it,
     * and a status read on an install with no gateway must not fail at
     * construction time. Same pattern (and same reason) as
     * {@see AdminOrderDraftController}'s finalization service.
     */
    private function links(): PaymentLinkService
    {
        return $this->links ??= app($this->context, PaymentLinkService::class);
    }

    /**
     * `POST /orders/{uuid}/payment-link` -- mint (or REGENERATE: a mint that
     * found a predecessor revokes it in the same transaction) and return the
     * one-time public URL.
     *
     * The URL is in the response and NOWHERE else -- not in the table, not in
     * the audit trail, not re-readable from {@see self::show()}. A client that
     * loses it regenerates.
     */
    #[ApiOperation(summary: 'Create a payment link for an order', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Payment link created')]
    #[ApiResponse(404, description: 'Order not found')]
    #[ApiResponse(409, description: 'Order is not an admin-origin order awaiting payment')]
    #[ApiResponse(422, description: 'Validation failed')]
    #[ApiResponse(503, description: 'No public payment-link address is configured')]
    public function store(Request $request, string $uuid): Response
    {
        return $this->guard(function () use ($request, $uuid): Response {
            $ttlDays = $this->ttlDays($request);
            if ($ttlDays === false) {
                return Response::validation(['ttl_days' => 'ttl_days must be a whole number of days.']);
            }

            $minted = $this->links()->mintPublic(
                $this->context,
                $this->tenant(),
                $uuid,
                $ttlDays,
                (string) ($this->actorUuid($request) ?? '')
            );

            return Response::created([
                'url' => $minted['url'],
                'link' => $minted['link']->toArray(),
            ], 'Payment link created');
        });
    }

    /**
     * `GET /orders/{uuid}/payment-link` -- state, expiry, and exposure.
     *
     * `link` is null when the order has no ACTIVE link (never minted, or the
     * current one was revoked). `exposure` is the
     * {@see PaymentSessionExposureGuard} decision, which is what an operator UI
     * needs to render honest cancellation copy: whether the sweep still owns
     * this order, and whether cancelling it will require accepting the
     * late-payment risk.
     *
     * The guard is consulted here as an OBSERVATION, on an unlocked row: this
     * action authorizes nothing, and taking an order lock for a status poll
     * would hand every admin refresh a contention lever. The cancellation
     * authorities re-decide under their own locks.
     */
    #[ApiOperation(summary: "Get an order's payment link state", tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Payment link state retrieved')]
    #[ApiResponse(404, description: 'Order not found')]
    public function show(Request $request, string $uuid): Response
    {
        return $this->guard(function () use ($uuid): Response {
            $tenant = $this->tenant();
            $order = $this->orders->findByUuid($this->context, $tenant, $uuid, includeDrafts: true);
            if ($order === null) {
                throw PaymentLinkException::orderNotFound();
            }

            $link = $this->links()->currentLink($this->context, $tenant, $uuid);

            return Response::success([
                'link' => $link?->toArray(),
                'exposure' => $this->exposure->decide($this->context, $tenant, $order)->toArray(),
            ], 'Payment link state retrieved');
        });
    }

    /**
     * `DELETE /orders/{uuid}/payment-link` -- revoke the order's active link.
     *
     * Addressed by ORDER, never by token: an operator killing a leaked link does
     * not have, and must not need, the leaked value. Idempotent -- an order with
     * no active link is a clean 200 that audits nothing -- so a client may
     * always revoke without first reading state.
     */
    #[ApiOperation(summary: "Revoke an order's payment link", tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Payment link revoked')]
    #[ApiResponse(404, description: 'Order not found')]
    public function destroy(Request $request, string $uuid): Response
    {
        return $this->guard(function () use ($request, $uuid): Response {
            $this->links()->revoke(
                $this->context,
                $this->tenant(),
                $uuid,
                (string) ($this->actorUuid($request) ?? '')
            );

            return Response::success(['order_uuid' => $uuid], 'Payment link revoked');
        });
    }

    /**
     * The ONE refusal boundary.
     *
     * A typed {@see PaymentLinkException} becomes its mapped status plus the
     * machine-readable `error.details.reason` clients branch on. ANY other
     * throwable becomes a bodiless 500 -- see rule 3 in the class docblock. The
     * catch is `\Throwable`, not `\Exception`, so an `\Error` (and every
     * `\LogicException`, including the service's nested-transaction guard) is
     * covered too.
     *
     * @param callable(): Response $operation
     */
    private function guard(callable $operation): Response
    {
        try {
            return $operation();
        } catch (PaymentLinkException $e) {
            return Response::error(
                $e->getMessage(),
                self::STATUS_BY_ERROR_CODE[$e->errorCode] ?? 409,
                ['reason' => $e->errorCode]
            );
        } catch (\Throwable) {
            // Deliberately unbound and unlogged HERE: the framework's error
            // handler is the one place that decides what an install records.
            // What this method guarantees is only that the RESPONSE carries
            // nothing -- no message, no class, no trace.
            return new Response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The optional TTL, validated to a WHOLE NUMBER of days before it reaches
     * the service (which clamps it to 1..30). Absent means "the configured
     * default".
     *
     * Strict on purpose: `1.5`, `"soon"`, `true`, and an array are all a client
     * bug, and silently coercing any of them would give an operator a link with
     * a lifetime they did not ask for. A numeric STRING is accepted because form
     * encoding has no integers.
     *
     * @return int|null|false the TTL, null for "use the default", or false when the input is malformed
     */
    private function ttlDays(Request $request): int|null|false
    {
        $raw = $this->input($request)['ttl_days'] ?? null;
        if ($raw === null) {
            return null;
        }

        if (is_int($raw)) {
            return $raw;
        }

        return is_string($raw) && preg_match('/\A-?\d+\z/', $raw) === 1 ? (int) $raw : false;
    }

    private function tenant(): string
    {
        return $this->tenants->tenantUuid($this->context);
    }
}
