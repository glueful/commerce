<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Exceptions\ConstraintViolationException;

/**
 * Owns ALL access to `commerce_order_draft_attempts` (admin-order-creation
 * cycle 2, Task 6; design spec §2.6) -- the finalize idempotency ledger for
 * admin-created ("walk-in") orders. UNIQUE `(tenant_uuid, idempotency_key)`
 * is the SOLE key authority: no `draft_uuid`, no generic `key` column, no
 * guest credential. Every field this repository reads/writes stays inside
 * it -- no projection ever forwards an attempt row to an HTTP response.
 *
 * `claimOrReplay()` is called INSIDE the caller's transaction (Task 10's
 * finalize flow: after the tenant-scoped order is locked `FOR UPDATE`,
 * before any stock or business mutation). The fresh-insert attempt runs in
 * its OWN savepoint -- {@see \Glueful\Database\Connection::transaction()}
 * nests as a savepoint when a transaction is already open, exactly like
 * {@see OrderNumberGenerator::next()}'s savepoint-isolated sequence
 * allocation (admin-order-creation cycle 2, Task 4). Without that isolation,
 * a caught unique-violation on PostgreSQL leaves the CALLER's already-open
 * enclosing transaction aborted -- every later statement (including the
 * re-read below) would fail with "current transaction is aborted". Rolling
 * back to the savepoint instead discards only this failed insert attempt and
 * keeps the enclosing transaction usable, so a same-key race against a
 * DIFFERENT draft resolves to a clean, typed `fingerprint_mismatch` instead
 * of a raw driver error, and the caller can still commit further work of its
 * own afterward.
 *
 * Catch target: {@see ConstraintViolationException}, NOT its
 * `UniqueConstraintViolationException` subclass. The framework does NOT enable
 * `PDO::SQLITE_ATTR_EXTENDED_RESULT_CODES` (`ExceptionClassifier`'s own
 * docblock), so on SQLite a duplicate-key insert reports the BARE vendor code
 * 19 (`SQLITE_CONSTRAINT`) -- which `ExceptionClassifier::VENDOR_MAP['sqlite']`
 * maps to the PARENT `ConstraintViolationException`, "kind unknowable without
 * messages", never to `UniqueConstraintViolationException` (that needs the
 * EXTENDED codes 2067/1555 this framework never turns on). Catching only the
 * subclass would let every SQLite duplicate-key race escape uncaught as a raw
 * 500. PostgreSQL's SQLSTATE 23505 and MySQL's vendor code 1062 both still map
 * to `UniqueConstraintViolationException`, which IS a `ConstraintViolationException`,
 * so this wider catch loses no coverage on either driver. The real
 * discriminator is the winner re-read below, not the exception's exact
 * subtype: catching the parent also catches a genuinely UNRELATED constraint
 * failure on this same insert (e.g. a NOT NULL violation on some other
 * column), but such a failure leaves no row behind under this
 * `(tenant, idempotency_key)`, so `$winner === null` and the original
 * exception is rethrown -- never silently misreported as a replay/mismatch.
 */
final class DraftAttemptRepository
{
    private const TABLE = 'commerce_order_draft_attempts';

    /**
     * @return array{state: 'fresh'|'replay'|'fingerprint_mismatch', attempt: array<string,mixed>}
     */
    public function claimOrReplay(
        ApplicationContext $context,
        string $tenant,
        string $idempotencyKey,
        string $fingerprint,
        string $orderUuid
    ): array {
        $existing = $this->findByKey($context, $tenant, $idempotencyKey);
        if ($existing !== null) {
            return $this->resolve($existing, $fingerprint);
        }

        try {
            db($context)->transaction(function () use (
                $context,
                $tenant,
                $idempotencyKey,
                $fingerprint,
                $orderUuid
            ): void {
                db($context)->table(self::TABLE)->insert([
                    'tenant_uuid' => $tenant,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'order_uuid' => $orderUuid,
                    'status' => 'pending',
                ]);
            });
        } catch (ConstraintViolationException $e) {
            // A concurrent claim for this (tenant, idempotency_key) won the race
            // between our lookup above and our own insert. Re-read and verify the
            // winner -- if it is genuinely not there (should be unreachable, since
            // the constraint violation proves a row now exists), rethrow rather
            // than silently fabricate a result.
            $winner = $this->findByKey($context, $tenant, $idempotencyKey);
            if ($winner === null) {
                throw $e;
            }

            return $this->resolve($winner, $fingerprint);
        }

        $fresh = $this->findByKey($context, $tenant, $idempotencyKey);
        if ($fresh === null) {
            throw new \RuntimeException(
                'commerce_order_draft_attempts insert reported success but the row cannot be found.'
            );
        }

        return ['state' => 'fresh', 'attempt' => $fresh];
    }

    public function complete(ApplicationContext $context, int $id): void
    {
        db($context)->table(self::TABLE)
            ->where('id', '=', $id)
            ->update([
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /** @return array<string,mixed>|null */
    private function findByKey(ApplicationContext $context, string $tenant, string $idempotencyKey): ?array
    {
        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('idempotency_key', '=', $idempotencyKey)
            ->first();
    }

    /**
     * @param array<string,mixed> $existing
     * @return array{state: 'replay'|'fingerprint_mismatch', attempt: array<string,mixed>}
     */
    private function resolve(array $existing, string $fingerprint): array
    {
        $matches = hash_equals((string) $existing['request_fingerprint'], $fingerprint);

        return ['state' => $matches ? 'replay' : 'fingerprint_mismatch', 'attempt' => $existing];
    }
}
