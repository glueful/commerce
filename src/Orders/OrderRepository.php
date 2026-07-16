<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;

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

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        $row = db($context)->table('commerce_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /** @return array<string,mixed>|null */
    public function findByNumber(ApplicationContext $context, string $tenant, string $number): ?array
    {
        $row = db($context)->table('commerce_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('order_number', '=', $number)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function listFor(ApplicationContext $context, string $tenant, array $filters = []): array
    {
        $query = db($context)->table('commerce_orders')
            ->where('tenant_uuid', '=', $tenant)
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
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function paginatedFor(
        ApplicationContext $context,
        string $tenant,
        array $filters,
        int $page,
        int $perPage,
    ): array {
        $count = db($context)->table('commerce_orders')->where('tenant_uuid', '=', $tenant);
        $rows = db($context)->table('commerce_orders')->where('tenant_uuid', '=', $tenant);

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
     * row. Returns false for an unknown or cross-tenant order.
     */
    public function claimOrderFinancialMutation(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $affected = db($context)->table('commerce_orders')->executeModification(
            <<<'SQL'
UPDATE commerce_orders
SET refund_revision = refund_revision + 1, updated_at = ?
WHERE tenant_uuid = ? AND uuid = ?
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
     * Guarded stamp of `user_uuid` onto a guest order (design spec §7, guest
     * linking, `commerce:customers:link-guests`): only when the order is
     * CURRENTLY unlinked, so a concurrent link/checkout race can't silently
     * overwrite an existing owner. Returns false for an unknown/cross-tenant
     * order, an order that's already linked, or a race lost to a concurrent
     * linker — the CLI caller treats any of those as "nothing changed" and
     * reports it, never a hard failure.
     */
    public function linkGuestToUser(ApplicationContext $context, string $tenant, string $uuid, string $userUuid): bool
    {
        $affected = db($context)->table('commerce_orders')->executeModification(
            <<<'SQL'
UPDATE commerce_orders
SET user_uuid = ?, updated_at = ?
WHERE tenant_uuid = ? AND uuid = ? AND user_uuid IS NULL
SQL,
            [$userUuid, db($context)->getDriver()->formatDateTime(), $tenant, $uuid]
        );

        return $affected === 1;
    }

    /** @param array<string,mixed> $changes */
    public function transition(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $to,
        array $changes = []
    ): void {
        $order = $this->findByUuid($context, $tenant, $uuid);
        if ($order === null) {
            throw new \RuntimeException('Order not found.');
        }

        $from = (string) $order['status'];
        OrderStateMachine::assertTransition($from, $to);

        unset($changes['tenant_uuid'], $changes['uuid'], $changes['status']);
        $changes['status'] = $to;
        $changes['updated_at'] = db($context)->getDriver()->formatDateTime();

        $query = db($context)->table('commerce_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->where('status', '=', $from);
        $affected = $query->update($changes);

        if ($affected !== 1) {
            throw new \DomainException('Order status changed concurrently; retry the operation.');
        }

        $this->recordEvent($context, $uuid, 'status:' . $to);
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

        return [
            'uuid' => Utils::generateNanoID(),
            'order_uuid' => (string) $order['uuid'],
            'variant_uuid' => (string) $line['variant_uuid'],
            'product_name' => (string) $line['product_name'],
            'sku' => (string) $line['sku'],
            'option_values' => json_encode($line['option_values'] ?? [], JSON_THROW_ON_ERROR),
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => $unitPrice * $quantity,
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
}
