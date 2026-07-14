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
     * @param array<string,mixed> $filters
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
     * Affected-row-checked serialization primitive for refund mutations. Every
     * issue/reserve/finalize transaction claims the order this way before reading any
     * state or capacity; validation and capacity reads only ever happen after the claim
     * succeeds. Returns false for an unknown or cross-tenant order.
     */
    public function claimRefundMutation(ApplicationContext $context, string $tenant, string $uuid): bool
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

    public function transition(ApplicationContext $context, string $tenant, string $uuid, string $to): void
    {
        $order = $this->findByUuid($context, $tenant, $uuid);
        if ($order === null) {
            throw new \RuntimeException('Order not found.');
        }

        OrderStateMachine::assertTransition((string) $order['status'], $to);
        db($context)->table('commerce_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update([
                'status' => $to,
                'updated_at' => db($context)->getDriver()->formatDateTime(),
            ]);

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
     * @param array<string,mixed> $order
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private function orderLineRow(array $order, array $line): array
    {
        $quantity = (int) $line['quantity'];
        $unitPrice = (int) $line['unit_price'];

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
}
