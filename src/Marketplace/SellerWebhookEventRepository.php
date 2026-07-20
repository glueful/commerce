<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;

/**
 * `commerce_seller_webhook_events` persistence (design spec §2.3/§2.4/§3,
 * MV5c-2 Task 4): the durable, immutable per-seller event SNAPSHOT --
 * canonical payload BYTES (the exact bytes a later delivery signs and
 * sends), keyed by `uuid` (= `event_id`, the receiver dedup identity). Rows
 * are written ONLY by {@see SellerWebhookOutboxPublisher::capture()}, inside
 * the same transaction as the authoritative business transition that
 * produced them -- this class never opens its own transaction, never
 * mutates a row after insert (append-only, mirroring
 * {@see SellerWebhookEndpointRepository}'s own audit-table convention), and
 * exposes no delete.
 */
final class SellerWebhookEventRepository
{
    private const TABLE = 'commerce_seller_webhook_events';

    /**
     * @param array{
     *     uuid: string,
     *     seller_uuid: string,
     *     event_type: string,
     *     payload: string,
     *     occurred_at: string,
     *     source_ref?: ?string
     * } $row
     */
    public function insert(ApplicationContext $context, string $tenant, array $row): void
    {
        db($context)->table(self::TABLE)->insert([
            'uuid' => $row['uuid'],
            'tenant_uuid' => $tenant,
            'seller_uuid' => $row['seller_uuid'],
            'event_type' => $row['event_type'],
            'payload' => $row['payload'],
            'occurred_at' => $row['occurred_at'],
            'source_ref' => $row['source_ref'] ?? null,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /**
     * Every snapshot captured for one seller, oldest-first -- test/diagnostic
     * convenience mirroring {@see SellerWebhookEndpointRepository::listEventsForEndpoint()}'s
     * identical shape.
     *
     * @return list<array<string,mixed>>
     */
    public function forSeller(ApplicationContext $context, string $tenant, string $sellerUuid): array
    {
        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->orderBy('created_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();
    }
}
