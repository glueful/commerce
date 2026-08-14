<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;

final class OrderRepository
{
    /**
     * @param array<string,mixed> $order
     * @param list<array<string,mixed>> $lines
     */
    public function insert(ApplicationContext $context, array $order, array $lines = []): void
    {
        db($context)->table('commerce_orders')->insert($this->encodeJson($order));

        foreach ($lines as $line) {
            db($context)->table('commerce_order_lines')->insert($this->orderLineRow($order, $line));
        }
    }

    /**
     * Cheapest possible "does this tenant have ANY recorded order?" probe (store-settings spec
     * §3.4, revised): the currency lock's predicate. Orders are the DURABLE money history --
     * their stored amounts are integers in the order's currency, so the first order ever placed
     * is the moment the store currency stops being safely changeable. (Catalog prices alone
     * don't lock: during setup a merchant's own draft prices are theirs to reinterpret.)
     *
     * Admin-order-creation cycle 2, Task 8: DRAFTS DON'T LOCK either, for the
     * exact same reason catalog prices don't -- an unfinalized draft is the
     * merchant's own scratch work, not recorded money history. A store whose
     * only "orders" are drafts is still in setup and its currency is still
     * freely changeable ({@see OrderScope}).
     */
    public function anyExistsForTenant(ApplicationContext $context, string $tenant): bool
    {
        $rows = OrderScope::excludeDrafts(
            db($context)->table('commerce_orders')
                ->select(['uuid'])
                ->where('tenant_uuid', '=', $tenant)
        )
            ->limit(1)
            ->get();

        return $rows !== [];
    }

    /**
     * Finalized-order lookup. `$includeDrafts` is the ONLY way to resolve a
     * draft row through this repository (admin-order-creation cycle 2, Task 8)
     * -- every pre-existing caller (admin show/cancel/fulfill, refunds,
     * downloads, payment confirmation, chargebacks, marketplace) keeps the
     * default and therefore fail-closes to its own non-revealing 404 when
     * handed a draft uuid. Only the admin draft surfaces (Task 9) and the
     * finalization path (Task 10) opt in.
     *
     * @return array<string,mixed>|null
     */
    public function findByUuid(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        bool $includeDrafts = false
    ): ?array {
        $query = db($context)->table('commerce_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid);
        if (!$includeDrafts) {
            OrderScope::excludeDrafts($query);
        }

        $row = $query->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /**
     * The SAME tenant-scoped lookup as {@see self::findByUuid()}, taken under a
     * ROW LOCK (admin-order-creation cycle 2, Task 10, design spec §2.5 step 1:
     * "Load the tenant-scoped order `FOR UPDATE` (or the driver's equivalent)").
     * Only ever meaningful INSIDE an open transaction -- the lock is released at
     * commit/rollback -- and its ONLY caller is
     * {@see DraftFinalizationService::finalize()}, whose whole idempotency
     * argument rests on it: two finalize requests carrying DIFFERENT idempotency
     * keys for the SAME draft serialize here, so the second one observes the
     * first one's committed status flip and loses on a typed conflict rather than
     * racing it through the stock claim and the number allocator.
     *
     * `FOR UPDATE` is appended only for drivers that HAVE it (PostgreSQL,
     * MySQL). SQLite has no row-level locking clause at all and does not need
     * one: a write transaction there holds a database-wide write lock, which is
     * strictly stronger than what this call asks for. Appending the clause
     * anyway would be a syntax error, and silently dropping it on a driver that
     * does support it would be far worse -- hence the explicit allowlist rather
     * than a "skip it on sqlite" exclusion, so a future driver fails closed into
     * the (correct, merely more contended) unlocked read instead of quietly
     * losing serialization.
     *
     * @return array<string,mixed>|null
     */
    public function findByUuidForUpdate(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        bool $includeDrafts = false
    ): ?array {
        $notDraft = $includeDrafts ? '' : ' AND ' . OrderScope::excludeDraftsSql();
        $lock = in_array(db($context)->getDriverName(), ['pgsql', 'mysql'], true) ? ' FOR UPDATE' : '';

        $row = db($context)->table('commerce_orders')->executeRawFirst(
            "SELECT * FROM commerce_orders WHERE tenant_uuid = ? AND uuid = ?{$notDraft}{$lock}",
            [$tenant, $uuid]
        );

        return $row === null ? null : $this->decodeJson($row);
    }

    /**
     * Number-keyed lookup -- the storefront's only order entry point. A draft
     * carries a NULL `order_number` by construction, so it could not match a
     * bound string anyway; the explicit exclusion is defense in depth, so this
     * surface stays draft-free even if a number were ever stamped early.
     *
     * @return array<string,mixed>|null
     */
    public function findByNumber(ApplicationContext $context, string $tenant, string $number): ?array
    {
        $row = OrderScope::excludeDrafts(
            db($context)->table('commerce_orders')
                ->where('tenant_uuid', '=', $tenant)
                ->where('order_number', '=', $number)
        )->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function listFor(ApplicationContext $context, string $tenant, array $filters = []): array
    {
        $query = OrderScope::excludeDrafts(
            db($context)->table('commerce_orders')->where('tenant_uuid', '=', $tenant)
        )
            ->orderBy('created_at', 'DESC');
        if (isset($filters['status'])) {
            $query->where('status', '=', (string) $filters['status']);
        }

        return array_map(fn (array $row): array => $this->decodeJson($row), $query->get());
    }

    /**
     * @param array<string,mixed> $filters 'status'/'user_uuid' (exact match), or
     *     'email_normalized' (design spec §7 customer-detail email-keyed query —
     *     the pre-existing 'user_uuid' exact-match filter alone cannot serve a
     *     guest customer's recent-orders listing): every guest order whose
     *     `LOWER(TRIM(email))` matches the already-normalized value, scoped to
     *     `user_uuid IS NULL` so a linked order never double-counts under both
     *     its owner's user-keyed listing and its old guest email.
     * @param bool $includeDrafts admin-order-creation cycle 2, Task 8: drafts are
     *     excluded from this listing unless explicitly requested. Note the
     *     ordering -- the exclusion is applied BEFORE the `status` filter, so
     *     `['status' => 'draft']` on the ordinary orders surface returns an
     *     empty page rather than acting as a back door; only this flag opens
     *     the draft surface (Task 9's admin draft listing).
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function paginatedFor(
        ApplicationContext $context,
        string $tenant,
        array $filters,
        int $page,
        int $perPage,
        bool $includeDrafts = false,
    ): array {
        $count = db($context)->table('commerce_orders')->where('tenant_uuid', '=', $tenant);
        $rows = db($context)->table('commerce_orders')->where('tenant_uuid', '=', $tenant);

        if (!$includeDrafts) {
            OrderScope::excludeDrafts($count);
            OrderScope::excludeDrafts($rows);
        }

        foreach (['status', 'user_uuid'] as $field) {
            if (isset($filters[$field])) {
                $count->where($field, '=', (string) $filters[$field]);
                $rows->where($field, '=', (string) $filters[$field]);
            }
        }

        if (isset($filters['email_normalized'])) {
            $normalized = (string) $filters['email_normalized'];
            $count->whereNull('user_uuid')->whereRaw('LOWER(TRIM(email)) = ?', [$normalized]);
            $rows->whereNull('user_uuid')->whereRaw('LOWER(TRIM(email)) = ?', [$normalized]);
        }

        return [
            'items' => array_map(
                fn (array $row): array => $this->decodeJson($row),
                $rows->orderBy('created_at', 'DESC')
                    ->limit($perPage)
                    ->offset(max(0, $page - 1) * $perPage)
                    ->get()
            ),
            'total' => $count->count(),
        ];
    }

    /**
     * Affected-row-checked serialization primitive for every order-financial mutation
     * (refunds AND digital-download mints, design spec §4.1) -- the neutral name
     * reflects that shared ownership; the underlying `refund_revision` column is
     * unchanged (renaming it would churn every refund migration for no behavioral
     * gain). Every issue/reserve/finalize refund transaction, and every atomic
     * download-URL mint, claims the order this way before reading any state or
     * capacity; validation and capacity reads only ever happen after the claim
     * succeeds. A full-refund completion and a mint therefore serialize on the same
     * row. Returns false for an unknown or cross-tenant order -- and, since
     * Task 8, for a DRAFT: a draft has no money history to mutate, so every
     * refund/mint surface fail-closes to its own 404 without needing its own
     * status check.
     */
    public function claimOrderFinancialMutation(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $notDraft = OrderScope::excludeDraftsSql();
        $affected = db($context)->table('commerce_orders')->executeModification(
            <<<SQL
UPDATE commerce_orders
SET refund_revision = refund_revision + 1, updated_at = ?
WHERE tenant_uuid = ? AND uuid = ? AND {$notDraft}
SQL,
            [
                db($context)->getDriver()->formatDateTime(),
                $tenant,
                $uuid,
            ]
        );

        return $affected === 1;
    }

    /**
     * Affected-row-checked serialization primitive for the parent side of the
     * fulfillment rollup (design spec §2.8): claimed FIRST, before touching
     * any `commerce_seller_orders` child, by BOTH
     * {@see \Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService::fulfill()}
     * and its `fanOutFulfill()` sibling -- concurrent child fulfillments
     * therefore serialize on this same row, so every rollup is computed on
     * committed state. Independent from `refund_revision`
     * ({@see claimOrderFinancialMutation()}) -- a disjoint concern (design
     * spec §4). Unlike that sibling, this THROWS rather than returning a
     * bool: every caller in the fulfillment chain treats an unknown or
     * cross-tenant order identically (a non-revealing 404), so there is no
     * caller that needs to distinguish/handle a false return. Task 8 folds
     * DRAFTS into that same non-revealing 404 -- nothing may be fulfilled
     * before it is an order.
     */
    public function claimFulfillmentMutation(ApplicationContext $context, string $tenant, string $uuid): void
    {
        $notDraft = OrderScope::excludeDraftsSql();
        $affected = db($context)->table('commerce_orders')->executeModification(
            <<<SQL
UPDATE commerce_orders
SET fulfillment_revision = fulfillment_revision + 1, updated_at = ?
WHERE tenant_uuid = ? AND uuid = ? AND {$notDraft}
SQL,
            [
                db($context)->getDriver()->formatDateTime(),
                $tenant,
                $uuid,
            ]
        );

        if ($affected !== 1) {
            throw new NotFoundException('Resource not found.');
        }
    }

    /**
     * Guarded stamp of `user_uuid` onto a guest order (design spec §7, guest
     * linking, `commerce:customers:link-guests`): only when the order is
     * CURRENTLY unlinked, so a concurrent link/checkout race can't silently
     * overwrite an existing owner. Returns false for an unknown/cross-tenant
     * order, an order that's already linked, or a race lost to a concurrent
     * linker — the CLI caller treats any of those as "nothing changed" and
     * reports it, never a hard failure. Task 8 adds drafts to that list: a
     * draft carries no email to match on and is not a guest order at all.
     */
    public function linkGuestToUser(ApplicationContext $context, string $tenant, string $uuid, string $userUuid): bool
    {
        $notDraft = OrderScope::excludeDraftsSql();
        $affected = db($context)->table('commerce_orders')->executeModification(
            <<<SQL
UPDATE commerce_orders
SET user_uuid = ?, updated_at = ?
WHERE tenant_uuid = ? AND uuid = ? AND user_uuid IS NULL AND {$notDraft}
SQL,
            [$userUuid, db($context)->getDriver()->formatDateTime(), $tenant, $uuid]
        );

        return $affected === 1;
    }

    /**
     * The generic lifecycle CAS for REAL orders. It reads drafts on purpose
     * (`includeDrafts: true`) only so it can RECOGNIZE one and refuse it with a
     * useful message, rather than report a misleading "Order not found."
     *
     * NO draft transition may run through here. Both of `draft`'s legal exits
     * are dedicated-path-only, symmetrically:
     *  - `draft -> pending_payment` belongs to
     *    {@see self::finalizeDraftTransition()} (Task 10's
     *    `DraftFinalizationService` is its sole caller);
     *  - `draft -> canceled` belongs to
     *    {@see DraftCleanupService::cancelDraft()}, shared by the TTL sweep and
     *    Task 9's explicit admin cancel.
     * Both are compare-and-set writes that never consult
     * {@see OrderStateMachine} at all, so refusing every draft here closes the
     * generic door completely without narrowing either sanctioned path.
     * `draft -> canceled` was the subtler of the two leaks: it would have
     * SUCCEEDED, but silently skipped the
     * {@see \Glueful\Extensions\Commerce\Orders\Events\DraftOrderEvents} audit
     * row that records why the draft died.
     *
     * The refusal fires BEFORE any write, so a rejected draft transition leaves
     * neither a status change nor an audit row. It is ordered AFTER
     * {@see OrderStateMachine::assertTransition()} so an outright illegal pair
     * (`draft -> paid`) still reports as the invalid transition it is, rather
     * than as a routing mistake.
     *
     * @param array<string,mixed> $changes
     */
    public function transition(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $to,
        array $changes = []
    ): void {
        $order = $this->findByUuid($context, $tenant, $uuid, true);
        if ($order === null) {
            throw OrderNotFoundException::forUuid($uuid);
        }

        $from = (string) $order['status'];
        OrderStateMachine::assertTransition($from, $to);

        if ($from === OrderScope::DRAFT) {
            throw new \DomainException(
                'Draft lifecycle changes must go through their dedicated paths '
                . '(finalizeDraftTransition() to finalize, DraftCleanupService::cancelDraft() '
                . 'to cancel), not transition().'
            );
        }

        unset($changes['tenant_uuid'], $changes['uuid'], $changes['status']);
        $changes['status'] = $to;
        $changes['updated_at'] = db($context)->getDriver()->formatDateTime();

        $query = db($context)->table('commerce_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->where('status', '=', $from);
        $affected = $query->update($changes);

        if ($affected !== 1) {
            // One extra read, on the LOST-RACE path only, so the exception can
            // name the state the winner left behind. A caller that wants to
            // answer idempotently ("the end state I was driving towards is
            // already true") needs that fact, and reading it here -- while the
            // failure is still in hand -- keeps every caller from re-deriving
            // it. `null` means the row vanished outright.
            $observed = $this->findByUuid($context, $tenant, $uuid, true);
            throw ConcurrentOrderTransitionException::lost(
                $from,
                $to,
                $observed === null ? null : (string) $observed['status']
            );
        }

        $this->recordEvent($context, $uuid, 'status:' . $to);
    }

    /**
     * The DEDICATED draft-finalization compare-and-set (admin-order-creation
     * cycle 2, Task 8, design spec §2.7) -- the only code path in the engine
     * that may perform `draft -> pending_payment`; {@see self::transition()}
     * refuses that pair outright.
     *
     * CALLER CONTRACT (enforced by convention + this docblock): the ONLY
     * permitted caller is {@see DraftFinalizationService::finalize()}, from
     * inside the finalize transaction that also stamps the order number, totals,
     * and `placed_at` -- that stamping and this compare-and-set share ONE
     * transaction and must never be separated by a commit, or an order could be
     * observed as `pending_payment` without a number. Nothing else -- no
     * controller, no console command, no listener -- may call this. A second
     * caller would mean a second definition of "finalized", which is exactly what
     * the dedicated path exists to prevent.
     *
     * Semantics: `WHERE ... AND status = 'draft'` makes this a genuine
     * compare-and-set. Two concurrent finalizations of the same draft cannot
     * both win -- the loser matches zero rows and throws, whether it lost to a
     * concurrent finalize, a concurrent draft cancellation, an unknown uuid,
     * or a cross-tenant one. It deliberately does NOT read `draft_revision`:
     * that counter guards draft EDITS (optimistic concurrency on the draft
     * body), and finalization is guarded by the status flip itself, so a
     * finalize never has to be retried merely because a concurrent edit bumped
     * the revision.
     *
     * Records the same `status:pending_payment` audit row `transition()` would
     * have, so the finalized order's event trail is indistinguishable from an
     * ordinary one; the draft-side story lives in the
     * {@see \Glueful\Extensions\Commerce\Orders\Events\DraftOrderEvents} rows
     * already on the order.
     */
    public function finalizeDraftTransition(ApplicationContext $context, string $tenant, string $uuid): void
    {
        $isDraft = OrderScope::isDraftSql();
        $affected = db($context)->table('commerce_orders')->executeModification(
            <<<SQL
UPDATE commerce_orders
SET status = 'pending_payment', updated_at = ?
WHERE tenant_uuid = ? AND uuid = ? AND {$isDraft}
SQL,
            [db($context)->getDriver()->formatDateTime(), $tenant, $uuid]
        );

        if ($affected !== 1) {
            throw new \DomainException('Draft is no longer finalizable; re-read the draft and retry.');
        }

        $this->recordEvent($context, $uuid, 'status:pending_payment');
    }

    /**
     * The §2.8 rollup write: applies a freshly computed parent
     * `fulfillment_status` to `commerce_orders`. When the rollup reaches
     * `fulfilled` this routes through the existing state-CAS
     * ({@see transition()}), so the parent order's own `status` flips
     * `paid -> fulfilled` guarded exactly like every other lifecycle
     * transition; for `unfulfilled`/`partial` it is a plain scoped UPDATE of
     * the `fulfillment_status` column alone -- that column sits outside
     * {@see OrderStateMachine} (design spec §2.8), so no CAS is needed here,
     * only the caller's own prior `claimFulfillmentMutation()` already
     * serializes concurrent writers.
     *
     * @return bool true iff this call transitioned the parent order's own
     *     `status` to `fulfilled` (the caller's signal to dispatch
     *     `OrderFulfilled` exactly once).
     */
    public function applyFulfillmentRollup(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $parentStatus
    ): bool {
        FulfillmentStatus::assertParent($parentStatus);

        if ($parentStatus === FulfillmentStatus::PARENT_FULFILLED) {
            $this->transition($context, $tenant, $uuid, 'fulfilled', [
                'fulfillment_status' => $parentStatus,
            ]);

            return true;
        }

        db($context)->table('commerce_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update([
                'fulfillment_status' => $parentStatus,
                'updated_at' => db($context)->getDriver()->formatDateTime(),
            ]);

        return false;
    }

    /** @param array<string,mixed> $payload */
    public function recordEvent(
        ApplicationContext $context,
        string $orderUuid,
        string $type,
        array $payload = [],
        ?string $actorUuid = null,
        string $visibility = 'internal'
    ): void {
        db($context)->table('commerce_order_events')->insert([
            'uuid' => Utils::generateNanoID(),
            'order_uuid' => $orderUuid,
            'type' => $type,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'actor_uuid' => $actorUuid,
            'visibility' => $visibility,
        ]);
    }

    /**
     * Tenant-constrained event read: joins through `commerce_orders` since
     * `commerce_order_events` carries no `tenant_uuid` column of its own. Returns every
     * event regardless of visibility — callers (e.g. the admin `show()` endpoint) are
     * the trusted, full-visibility surface; storefront-facing reads must filter by
     * `visibility` themselves.
     *
     * Draft isolation (Task 8): CALLER-GATED, deliberately NOT draft-excluding.
     * The join exists for tenant scoping, not order selection -- every caller
     * has already resolved the order through a draft-aware finder
     * ({@see self::findByUuid()}), so excluding drafts a second time here would
     * buy nothing and would break Task 9's admin draft-detail surface, which
     * legitimately reads a draft's own
     * {@see \Glueful\Extensions\Commerce\Orders\Events\DraftOrderEvents} trail.
     *
     * @return list<array<string,mixed>>
     */
    public function eventsForOrder(ApplicationContext $context, string $tenant, string $orderUuid): array
    {
        $rows = db($context)->table('commerce_order_events')
            ->join('commerce_orders', 'commerce_order_events.order_uuid', '=', 'commerce_orders.uuid')
            ->select(['commerce_order_events.*'])
            ->where('commerce_orders.tenant_uuid', '=', $tenant)
            ->where('commerce_order_events.order_uuid', '=', $orderUuid)
            ->orderBy('commerce_order_events.id', 'ASC')
            ->get();

        return array_map(fn (array $row): array => $this->decodeEventPayload($row), $rows);
    }

    /**
     * Tenant-constrained order line read: joins through `commerce_orders` since
     * `commerce_order_lines` carries no `tenant_uuid` column of its own — no bare
     * child-table lookup. This is the ONE place order-line `addons` AND
     * `option_values` json is decoded (design spec §4) — every caller
     * (invoice-data, storefront/admin order projections) gets already-decoded
     * `addons` and `option_values` arrays, never the raw JSON string.
     *
     * Draft isolation (Task 8): CALLER-GATED for the same reason as
     * {@see self::eventsForOrder()} -- the join is tenant scoping, not order
     * selection, and Task 9's draft editor reads a draft's lines through here.
     *
     * @return list<array<string,mixed>>
     */
    public function linesForOrder(ApplicationContext $context, string $tenant, string $orderUuid): array
    {
        $rows = db($context)->table('commerce_order_lines')
            ->join('commerce_orders', 'commerce_order_lines.order_uuid', '=', 'commerce_orders.uuid')
            ->select(['commerce_order_lines.*'])
            ->where('commerce_orders.tenant_uuid', '=', $tenant)
            ->where('commerce_order_lines.order_uuid', '=', $orderUuid)
            ->orderBy('commerce_order_lines.id', 'ASC')
            ->get();

        return array_map(fn (array $row): array => $this->decodeLineJson($row), $rows);
    }

    /**
     * How many lines each of these orders has -- ONE grouped, tenant-constrained
     * query for a whole page, never a per-row lookup and never the line payload
     * itself. The drafts listing is the caller: an admin list row needs the
     * COUNT ("3 items") and nothing else, and hydrating full line rows (with
     * their decoded add-on/option json) for up to a hundred drafts to compute a
     * number would be pure waste.
     *
     * Tenant scoping goes through the same join {@see self::linesForOrder()}
     * uses -- `commerce_order_lines` carries no `tenant_uuid` of its own, so a
     * bare child-table aggregate would be a cross-tenant read.
     *
     * Draft isolation: CALLER-GATED, exactly like `linesForOrder()`. The join is
     * tenant scoping, not order selection; the caller has already decided which
     * uuids it is allowed to ask about.
     *
     * Every requested uuid appears in the result, an order with no lines as `0`,
     * so a caller never has to distinguish "no lines" from "not in the map".
     *
     * @param list<string> $orderUuids
     * @return array<string,int> orderUuid => line count
     */
    public function lineCountsForOrders(ApplicationContext $context, string $tenant, array $orderUuids): array
    {
        $counts = array_fill_keys($orderUuids, 0);
        if ($orderUuids === []) {
            return $counts;
        }

        $placeholders = implode(', ', array_fill(0, count($orderUuids), '?'));
        $rows = db($context)->table('commerce_order_lines')->executeRaw(
            <<<SQL
SELECT l.order_uuid AS order_uuid, COUNT(*) AS line_count
FROM commerce_order_lines l
JOIN commerce_orders o ON o.uuid = l.order_uuid
WHERE o.tenant_uuid = ? AND l.order_uuid IN ({$placeholders})
GROUP BY l.order_uuid
SQL,
            [$tenant, ...$orderUuids]
        );

        foreach ($rows as $row) {
            $counts[(string) $row['order_uuid']] = (int) $row['line_count'];
        }

        return $counts;
    }

    /**
     * Add-ons: the priced line's snapshot (already-persisted, cart-line-verbatim —
     * `CartService::pricedLines()` reads it from the cart line, never re-resolving
     * definitions) is copied AS-IS into `commerce_order_lines.addons`. This is the
     * explicit add-on persistence boundary: nothing downstream rebuilds it.
     *
     * Downloads: `CheckoutService::withDownloadSnapshots()` already computed the
     * purchase-time entitlement snapshot per line — `null` for non-digital lines,
     * a (possibly empty) list for digital ones — so this only encodes whatever it
     * finds; NULL means "not applicable", `[]` means "digital, no active
     * downloads at checkout time" (design spec §2). Never re-derived here.
     *
     * Marketplace attribution (design spec §2.11/§3.1, MV2): the order line's
     * OWN `uuid` persists the incoming cart-line `line_uuid` when present
     * (`CartService::pricedLines()` stamps it, and it threads unmodified
     * through `CheckoutService::placeOrder()`) rather than minting a fresh
     * one — so the `taxByLine`/discount-allocation maps built against that
     * same `line_uuid` address the real, immutable order line. `seller_uuid`
     * (immutable seller snapshot), `discount_amount` (allocated value-discount
     * share), and `tax_amount` (per-line merchandise tax, `line_detailed`
     * only) are additive keys `CheckoutService`'s partition branch attaches
     * to each line before calling `insert()`; a non-partitioned checkout
     * never sets them, so they default to null/0 exactly as the schema does.
     *
     * Marketplace commission snapshot (design spec §2.4, MV3): `commission_source`
     * is the provenance signal -- ONLY `CheckoutService`'s commission-resolution
     * step (partitioned checkout only) ever sets it, so its presence (not the
     * mere presence of `commission_kind`/`commission_bps`/`commission_fixed`,
     * which `CartService::pricedLines()` ALSO populates from the product's raw,
     * unresolved override on every line, partitioned or not) is what gates
     * persisting the other five commission columns here. A non-partitioned
     * checkout therefore always writes null/0 commission columns regardless
     * of what a product's own raw commission override happens to hold.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private function orderLineRow(array $order, array $line): array
    {
        $quantity = (int) $line['quantity'];
        $unitPrice = (int) $line['unit_price'];
        $addons = is_array($line['addons'] ?? null) ? $line['addons'] : [];
        $downloads = $line['downloads'] ?? null;
        $commissionSource = isset($line['commission_source']) ? (string) $line['commission_source'] : null;

        return [
            'uuid' => (string) ($line['line_uuid'] ?? Utils::generateNanoID()),
            'order_uuid' => (string) $order['uuid'],
            'variant_uuid' => (string) $line['variant_uuid'],
            'product_name' => (string) $line['product_name'],
            'sku' => (string) $line['sku'],
            'option_values' => json_encode($line['option_values'] ?? [], JSON_THROW_ON_ERROR),
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => $unitPrice * $quantity,
            'seller_uuid' => isset($line['seller_uuid']) ? (string) $line['seller_uuid'] : null,
            'discount_amount' => (int) ($line['discount_amount'] ?? 0),
            'tax_amount' => (int) ($line['tax_amount'] ?? 0),
            'commission_source' => $commissionSource,
            'commission_kind' => $commissionSource !== null ? (string) $line['commission_kind'] : null,
            'commission_bps' => $commissionSource !== null && $line['commission_bps'] !== null
                ? (int) $line['commission_bps'] : null,
            'commission_fixed' => $commissionSource !== null && $line['commission_fixed'] !== null
                ? (int) $line['commission_fixed'] : null,
            'commission_basis' => $commissionSource !== null ? (int) $line['commission_basis'] : 0,
            'commission_amount' => $commissionSource !== null ? (int) $line['commission_amount'] : 0,
            'addons' => $addons === [] ? null : json_encode($addons, JSON_THROW_ON_ERROR),
            'downloads' => is_array($downloads) ? json_encode($downloads, JSON_THROW_ON_ERROR) : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function encodeJson(array $row): array
    {
        foreach (['addresses', 'metadata'] as $column) {
            if (isset($row[$column]) && is_array($row[$column])) {
                $row[$column] = json_encode($row[$column], JSON_THROW_ON_ERROR);
            }
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeJson(array $row): array
    {
        foreach (['addresses', 'metadata'] as $column) {
            if (isset($row[$column]) && is_string($row[$column]) && $row[$column] !== '') {
                $decoded = json_decode($row[$column], true);
                $row[$column] = is_array($decoded) ? $decoded : null;
            }
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeEventPayload(array $row): array
    {
        if (isset($row['payload']) && is_string($row['payload']) && $row['payload'] !== '') {
            $decoded = json_decode($row['payload'], true);
            $row['payload'] = is_array($decoded) ? $decoded : null;
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeLineJson(array $row): array
    {
        if (isset($row['addons']) && is_string($row['addons']) && $row['addons'] !== '') {
            $decoded = json_decode($row['addons'], true);
            $row['addons'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['addons'] = [];
        }

        if (isset($row['option_values']) && is_string($row['option_values']) && $row['option_values'] !== '') {
            $decoded = json_decode($row['option_values'], true);
            $row['option_values'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['option_values'] = [];
        }

        // Unlike addons/option_values, an absent/NULL raw column means "not
        // applicable" (non-digital line) and decodes to null, not []; an
        // explicitly-stored `[]` (digital line, no active downloads at checkout)
        // decodes to an empty array. The two are deliberately distinct.
        if (isset($row['downloads']) && is_string($row['downloads']) && $row['downloads'] !== '') {
            $decoded = json_decode($row['downloads'], true);
            $row['downloads'] = is_array($decoded) ? $decoded : null;
        } else {
            $row['downloads'] = null;
        }

        return $row;
    }

    /**
     * The most recent orders CONTAINING a given product (composed-editor spec §5.4b, phase 2:
     * the admin product page's "Recent orders" panel). Orders join through their lines' variants
     * (`commerce_order_lines.variant_uuid` → `commerce_variants.product_uuid`) — a multi-line
     * order matching twice still appears ONCE. Report time mirrors the reports' convention
     * ({@see \Glueful\Extensions\Commerce\Reports\ProductSalesReportRepository}): `placed_at`
     * when present, `created_at` otherwise. Raw SQL for the same reason the aggregation
     * repositories use it — exact, driver-verified GROUP BY/DISTINCT semantics.
     *
     * @return list<array<string,mixed>> full order rows (callers project before the wire)
     */
    public function recentForProduct(
        ApplicationContext $context,
        string $tenant,
        string $productUuid,
        int $limit
    ): array {
        $notDraft = OrderScope::excludeDraftsSql('o');
        $uuidRows = db($context)->table('commerce_orders')->executeRaw(
            <<<SQL
SELECT o.uuid AS uuid, MAX(COALESCE(o.placed_at, o.created_at)) AS report_time
FROM commerce_orders o
JOIN commerce_order_lines l ON l.order_uuid = o.uuid
JOIN commerce_variants v ON v.uuid = l.variant_uuid
WHERE o.tenant_uuid = ? AND v.product_uuid = ? AND {$notDraft}
GROUP BY o.uuid
ORDER BY report_time DESC
LIMIT ?
SQL,
            [$tenant, $productUuid, $limit]
        );
        if ($uuidRows === []) {
            return [];
        }

        $orderedUuids = array_map(static fn (array $row): string => (string) $row['uuid'], $uuidRows);
        $rows = OrderScope::excludeDrafts(
            db($context)->table('commerce_orders')->where('tenant_uuid', '=', $tenant)
        )
            ->whereIn('uuid', $orderedUuids)
            ->get();

        // Restore the recency ordering the IN() fetch discarded.
        $byUuid = [];
        foreach ($rows as $row) {
            $byUuid[(string) $row['uuid']] = $row;
        }

        $ordered = [];
        foreach ($orderedUuids as $uuid) {
            if (isset($byUuid[$uuid])) {
                $ordered[] = $byUuid[$uuid];
            }
        }

        return $ordered;
    }

    /**
     * Windowed, product-attributed order activity (composed-editor spec §5.4b, phase 2: the
     * "Last N days" tile). Mirrors the products report's revenue discipline exactly
     * ({@see \Glueful\Extensions\Commerce\Reports\ProductSalesReportRepository}): only orders in
     * `('paid','fulfilled','refunded')` count, windowed on `placed_at` falling back to
     * `created_at`, and revenue is the SUM of THIS product's line totals — attributed, never the
     * orders' grand totals. `$cutoff` is a PHP-computed UTC datetime string so the window
     * arithmetic is driver-portable (no DB date functions).
     *
     * Draft isolation (Task 8): the revenue-status ALLOWLIST below is strictly
     * stronger than {@see OrderScope}'s exclusion -- `draft` is not in it and
     * never can be (a draft is by definition not paid/fulfilled/refunded), so
     * a redundant `status <> 'draft'` here would be pure noise. This note is
     * the ratchet: the allowlist must stay an allowlist.
     *
     * @return array{orders: int, revenue_minor: int}
     */
    public function productOrderSummary(
        ApplicationContext $context,
        string $tenant,
        string $productUuid,
        string $cutoff
    ): array {
        $row = db($context)->table('commerce_orders')->executeRawFirst(
            <<<'SQL'
SELECT COUNT(DISTINCT o.uuid) AS orders_count, COALESCE(SUM(l.line_total), 0) AS revenue_minor
FROM commerce_orders o
JOIN commerce_order_lines l ON l.order_uuid = o.uuid
JOIN commerce_variants v ON v.uuid = l.variant_uuid
WHERE o.tenant_uuid = ?
  AND v.product_uuid = ?
  AND o.status IN ('paid', 'fulfilled', 'refunded')
  AND COALESCE(o.placed_at, o.created_at) >= ?
SQL,
            [$tenant, $productUuid, $cutoff]
        );

        return [
            'orders' => (int) ($row['orders_count'] ?? 0),
            'revenue_minor' => (int) ($row['revenue_minor'] ?? 0),
        ];
    }
}
