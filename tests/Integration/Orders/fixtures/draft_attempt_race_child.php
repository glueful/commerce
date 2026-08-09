<?php

declare(strict_types=1);

/**
 * Standalone subprocess for DraftAttemptRepositoryPgsqlTest's real-pgsql race lane
 * (admin-order-creation cycle 2, Task 6): runs one action against a genuinely
 * separate OS process/database connection/session, so its
 * `commerce_order_draft_attempts` insert really blocks on PostgreSQL unique-index
 * contention held by the parent test process's own connection. Mirrors
 * `Orders/fixtures/order_number_race_child.php`'s shape.
 *
 * argv: 1=pgConfig JSON, 2=action, 3=args JSON
 *
 * actions:
 *  - hold_insert: begins a transaction, inserts a fresh
 *    `commerce_order_draft_attempts(tenant_uuid, idempotency_key, request_fingerprint,
 *    order_uuid, status)` row using args.tenant/args.idempotencyKey/args.fingerprint/
 *    args.orderUuid, sleeps for `args.sleepMs` milliseconds (holding the insert
 *    open/uncommitted so a concurrent connection's competing insert of the SAME
 *    (tenant, idempotency_key) blocks on it), then commits. stdout: {"ok":true}.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Database\Connection;

[, $pgConfigJson, $action, $argsJson] = $argv;
/** @var array<string,mixed> $pgConfig */
$pgConfig = json_decode($pgConfigJson, true, 512, JSON_THROW_ON_ERROR);
/** @var array<string,mixed> $args */
$args = json_decode($argsJson, true, 512, JSON_THROW_ON_ERROR);

$connection = new Connection($pgConfig);

$out = [];

try {
    switch ($action) {
        case 'hold_insert':
            $connection->getTransactionManager()->begin();
            $connection->table('commerce_order_draft_attempts')->insert([
                'tenant_uuid' => (string) $args['tenant'],
                'idempotency_key' => (string) $args['idempotencyKey'],
                'request_fingerprint' => (string) $args['fingerprint'],
                'order_uuid' => (string) $args['orderUuid'],
                'status' => 'pending',
            ]);
            usleep((int) $args['sleepMs'] * 1000);
            $connection->getTransactionManager()->commit();
            $out = ['ok' => true];
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
