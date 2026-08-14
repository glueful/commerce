<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\DraftCleanupService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\OrderScope;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * THE ONE HTTP OWNER of draft-artifact deletion (cleanup-train Task 5):
 * `DELETE /orders/{uuid}/artifact`, catalog key `orders.artifact.destroy`.
 *
 * ## Why this is its own class
 *
 * It is not a draft endpoint. {@see AdminOrderDraftController} owns
 * `/orders/drafts/...` -- the LIVE draft editor, every action of which routes
 * through `DraftOrderService` and refuses anything that is not currently a
 * draft. An artifact is the opposite: a row that has already STOPPED being a
 * draft, addressed on the ordinary `/orders/{uuid}/...` path family beside
 * `payment-link`, `cancel` and `refunds`. Folding it into the draft controller
 * would put this engine's only hard delete behind a class whose whole contract
 * is "the row is a draft", and would give the delete a `/orders/drafts/` path
 * for a row that is not one.
 *
 * It follows {@see AdminOrderPaymentLinkController}'s shape instead, and for the
 * same reason that class exists: a single custody-sensitive concern deserves one
 * small, heavily-documented owner, so there is exactly one place to audit and no
 * second route can quietly grow the capability.
 *
 * ## The refusal matrix IS the feature
 *
 *  - **200** -- the tenant-scoped row has `order_number IS NULL` AND
 *    `status = 'canceled'`. That pair is a structural proof it never touched
 *    money ({@see OrderScope::deletableArtifactSql()}), which is the only reason
 *    a hard delete is legal.
 *  - **409 `order_not_deletable`** -- any other row this tenant owns. That
 *    deliberately INCLUDES an active draft: it must be canceled first, so a
 *    mis-click can never destroy work in progress without an intermediate,
 *    reversible state. It also includes a canceled order that HAS a number,
 *    which is a real order with real money history and is never deletable.
 *  - **404** -- unknown and cross-tenant uuids alike, byte-identical, so this
 *    endpoint can never be used to enumerate another tenant's orders.
 *
 * ## The lookup is draft-INCLUSIVE, deliberately
 *
 * Every sibling admin order endpoint reads through the draft-BLIND
 * `findByUuid()` and therefore answers a draft uuid with its non-revealing 404.
 * This one opts in to `includeDrafts: true` because a draft's answer here is
 * load-bearing: "cancel it first" is the remedy, and a 404 would both misstate
 * the row's existence to an operator who is looking straight at it in the orders
 * list and leave the remedy undiscoverable. Nothing leaks -- a draft is already
 * visible to this same operator through `orders.drafts.*`, and the artifact this
 * endpoint exists for is a `canceled` row that the draft-blind reader would have
 * resolved anyway.
 *
 * ## The precheck is a courtesy; the compare-and-set is the authority
 *
 * The classification read takes no lock and makes no promise. The real
 * authorization is the guard inside {@see DraftCleanupService::deleteArtifact()}'s
 * own `DELETE ... WHERE`, so a row that stops being an artifact between the two
 * is refused by the database, not by this class. When that happens the endpoint
 * RE-READS and answers from what is actually there now -- a vanished row is the
 * ordinary 404 (a concurrent operator or the purge sweep got there first), and a
 * row that became something else is the ordinary 409. Proven against a genuinely
 * concurrent second process in `Orders\ArtifactDeleteRacePgsqlTest`.
 *
 * ## Deliberately unaudited
 *
 * There is no `commerce_order_events` row for a deletion: it would reference an
 * order that no longer exists and would be unreadable the moment it was written.
 * See `DraftCleanupService::deleteArtifact()`'s docblock for the full argument
 * and for the app-log line that stands in its place.
 */
final class AdminOrderArtifactController
{
    use ResolvesActor;

    /**
     * The ONE typed refusal this endpoint can return -- the machine-readable
     * discriminator a client branches on, published under `error.details.reason`
     * exactly as {@see AdminOrderPaymentLinkController} publishes its own.
     *
     * One code covers every non-artifact row rather than one per status, because
     * the client's decision is binary (offer the delete, or do not) and the
     * accompanying `status` already carries the specifics needed to write honest
     * copy. A per-status vocabulary would be a closed set that has to be
     * re-negotiated with every new order status.
     */
    public const REASON_NOT_DELETABLE = 'order_not_deletable';

    private CurrentTenantResolver $tenants;

    public function __construct(
        private ApplicationContext $context,
        private ?OrderRepository $orders = null,
        private ?DraftCleanupService $artifacts = null,
        ?CurrentTenantResolver $tenants = null,
    ) {
        $this->orders ??= app($context, OrderRepository::class);
        $this->artifacts ??= app($context, DraftCleanupService::class);
        $this->tenants = $tenants ?? (container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver());
    }

    /**
     * `DELETE /orders/{uuid}/artifact` -- permanently destroy a canceled,
     * never-numbered order row and its lines, events and finalize attempts.
     *
     * There is no soft-delete, no undo, and no body: the uuid in the path is the
     * whole request. The response echoes the uuid so a client can settle its own
     * optimistic list update without re-reading a row that no longer exists.
     */
    #[ApiOperation(summary: 'Permanently delete a canceled draft artifact', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Draft artifact deleted')]
    #[ApiResponse(404, description: 'Order not found')]
    #[ApiResponse(409, description: 'Order is not a deletable draft artifact')]
    public function destroy(Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);

        $order = $this->orders->findByUuid($this->context, $tenant, $uuid, includeDrafts: true);
        if ($order === null) {
            throw new NotFoundException('Resource not found.');
        }
        if (!OrderScope::isDeletableArtifact($order)) {
            return $this->notDeletable($order);
        }

        $deleted = $this->artifacts->deleteArtifact(
            $this->context,
            $tenant,
            $uuid,
            $this->actorUuid($request),
            DraftCleanupService::REASON_ADMIN
        );

        if (!$deleted) {
            // The guard refused what the precheck had approved, so something
            // changed underneath. Re-read and answer from the CURRENT truth
            // rather than from the stale row this method started with.
            $observed = $this->orders->findByUuid($this->context, $tenant, $uuid, includeDrafts: true);
            if ($observed === null) {
                throw new NotFoundException('Resource not found.');
            }

            return $this->notDeletable($observed);
        }

        return Response::success(['order_uuid' => $uuid], 'Draft artifact deleted');
    }

    /**
     * The typed 409. `status` rides alongside the discriminator because it is
     * what the operator's remedy depends on -- an active draft is one click
     * (cancel it) away from deletable, and a numbered order is never deletable
     * at all -- and it reveals nothing: the caller has already resolved this row
     * within their own tenant.
     *
     * @param array<string,mixed> $order
     */
    private function notDeletable(array $order): Response
    {
        $status = (string) ($order['status'] ?? '');
        $message = $status === OrderScope::DRAFT
            ? 'Cancel this draft before deleting it.'
            : 'This order has been placed and can never be deleted.';

        return Response::error($message, 409, [
            'reason' => self::REASON_NOT_DELETABLE,
            'status' => $status,
        ]);
    }
}
