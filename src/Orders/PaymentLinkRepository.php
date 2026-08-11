<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\CommerceSettings;

/**
 * Owns ALL access to `commerce_payment_links` (payment-links Task 5, design
 * spec §2.2 bullet 1) -- ROW MECHANICS ONLY.
 *
 * This class deliberately contains no policy: no token generation, no TTL
 * clamping, no order-eligibility check, no URL composition, no view
 * projection. Those belong to `PaymentLinkService` and the host seams (Tasks
 * 6-8). What lives here is the set of primitives those layers cannot express
 * safely themselves: a tenant-scoped hashed lookup, four compare-and-set
 * status transitions, the fixed-UTC-hour initiation counter claimed under the
 * link row lock, the provider-session exposure stamp, and the expiry/cancel
 * guard's candidate read.
 *
 * HASHED CUSTODY. Every token-shaped parameter on this class is a
 * `$tokenHash` -- a SHA-256 hex digest. There is no raw-token parameter
 * anywhere, by construction: a raw bearer token that reached this layer could
 * reach a slow-query log, a driver exception trace, or the table itself.
 * `Orders\PaymentLinkRepositoryTest::testNoPublicMethodAcceptsARawToken()`
 * pins that by reflection so a future signature cannot quietly reintroduce
 * one. Shape-gating a submitted token (64 lowercase hex) and hashing it is
 * the SERVICE's job, before it ever calls in here.
 *
 * INJECTED CLOCK. Every method that writes or compares a timestamp takes an
 * explicit `\DateTimeImmutable $now`, never `time()` -- the same discipline as
 * {@see DraftCleanupService::cancelStale()}. Callers normalise nothing: this
 * class converts to UTC itself, so a caller in any timezone lands in the same
 * fixed hour window as a UTC caller at the same instant, and tests assert the
 * exact hour boundary with no tolerance.
 *
 * LOCK DISCIPLINE. `findByUuidForUpdate()` / `findActiveForOrderForUpdate()`
 * append `FOR UPDATE` only for drivers that HAVE it (PostgreSQL, MySQL),
 * exactly like {@see OrderRepository::findByUuidForUpdate()} -- SQLite's
 * database-wide write lock is strictly stronger and the clause would be a
 * syntax error there. They are only meaningful INSIDE an open transaction.
 * The design spec §2.2 lock order is ORDER FIRST, THEN LINK; this class never
 * touches `commerce_orders`, so honouring that order is the caller's job.
 *
 * The one-active-link-per-order authority is Ruling 7 and is TRANSACTIONAL --
 * `findActiveForOrderForUpdate()` is the primitive that authority is built
 * from, not an enforcement mechanism in itself. The schema carries no partial
 * unique index (see `Database\Migrations\CreatePaymentLinksTable`).
 */
final class PaymentLinkRepository
{
    public const TABLE = 'commerce_payment_links';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CONSUMED = 'consumed';

    /** The CLOSED status domain (design spec §2.2). @var list<string> */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_REVOKED,
        self::STATUS_EXPIRED,
        self::STATUS_CONSUMED,
    ];

    /**
     * Persist a freshly minted link. A new link is ALWAYS `active` with a zero
     * counter and no stamps, so neither status nor any counter/stamp column is
     * a parameter -- there is no way to insert a row already claiming to have
     * issued a provider session, or to insert one in a terminal state.
     *
     * `$expiresAt` and `$now` are stored as UTC `Y-m-d H:i:s`, matching the
     * comparisons {@see self::guardRelevantLinks()} makes against them.
     */
    public function insert(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $orderUuid,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        string $createdBy,
        \DateTimeImmutable $now
    ): void {
        db($context)->table(self::TABLE)->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'token_hash' => $tokenHash,
            'status' => self::STATUS_ACTIVE,
            'expires_at' => self::stamp($expiresAt),
            'created_by' => $createdBy,
            'initiation_count' => 0,
            'created_at' => self::stamp($now),
        ]);
    }

    /**
     * The resolve path's ONLY lookup. Tenant-scoped, hash-keyed, served by the
     * `(tenant_uuid, token_hash)` unique. An unknown hash and a hash belonging
     * to another tenant are the same answer -- null -- so the caller cannot
     * distinguish them and neither can a prober.
     *
     * @return array<string,mixed>|null
     */
    public function findByTokenHash(ApplicationContext $context, string $tenant, string $tokenHash): ?array
    {
        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('token_hash', '=', $tokenHash)
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $linkUuid): ?array
    {
        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $linkUuid)
            ->first();
    }

    /**
     * The same tenant-scoped lookup taken under a ROW LOCK -- design spec §2.2
     * initiation Phase B ("locks the same order then link UUID, rechecks every
     * predicate"). Only meaningful inside an open transaction.
     *
     * @return array<string,mixed>|null
     */
    public function findByUuidForUpdate(ApplicationContext $context, string $tenant, string $linkUuid): ?array
    {
        return db($context)->table(self::TABLE)->executeRawFirst(
            'SELECT * FROM ' . self::TABLE . ' WHERE tenant_uuid = ? AND uuid = ?' . $this->lockClause($context),
            [$tenant, $linkUuid]
        );
    }

    /**
     * The order's CURRENT link by status alone. Expiry is deliberately NOT part
     * of this predicate: a past-TTL row is still `active` until something
     * transitions it, and resolving it is exactly how the lazy `expired`
     * transition gets a chance to run. Callers that need "active AND unexpired"
     * compare `expires_at` themselves against their own injected clock.
     *
     * @return array<string,mixed>|null
     */
    public function findActiveForOrder(ApplicationContext $context, string $tenant, string $orderUuid): ?array
    {
        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('order_uuid', '=', $orderUuid)
            ->where('status', '=', self::STATUS_ACTIVE)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * {@see self::findActiveForOrder()} under a row lock -- the primitive
     * Ruling 7's transactional one-active-per-order authority is built from
     * (mint and revoke both serialize here). `ORDER BY id DESC` makes "the
     * current link" deterministic even in the transient two-active state the
     * schema permits by design.
     *
     * @return array<string,mixed>|null
     */
    public function findActiveForOrderForUpdate(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid
    ): ?array {
        return db($context)->table(self::TABLE)->executeRawFirst(
            'SELECT * FROM ' . self::TABLE
                . ' WHERE tenant_uuid = ? AND order_uuid = ? AND status = ?'
                . ' ORDER BY id DESC' . $this->lockClause($context),
            [$tenant, $orderUuid, self::STATUS_ACTIVE]
        );
    }

    /**
     * `active` -> `revoked`, stamping `revoked_at`. False (never an exception)
     * when the compare-and-set matches nothing: an unknown or cross-tenant
     * uuid, or a link some concurrent caller already moved out of `active`.
     * Callers that need a 404/409 distinction make it from their own prior
     * read under the lock.
     */
    public function revoke(
        ApplicationContext $context,
        string $tenant,
        string $linkUuid,
        \DateTimeImmutable $now
    ): bool {
        return $this->transitionFromActive($context, $tenant, $linkUuid, self::STATUS_REVOKED, 'revoked_at', $now);
    }

    /** `active` -> `consumed`, stamping `consumed_at`. Same false-on-no-match contract as {@see self::revoke()}. */
    public function consume(
        ApplicationContext $context,
        string $tenant,
        string $linkUuid,
        \DateTimeImmutable $now
    ): bool {
        return $this->transitionFromActive($context, $tenant, $linkUuid, self::STATUS_CONSUMED, 'consumed_at', $now);
    }

    /**
     * `active` -> `expired`. No dedicated stamp column: `expires_at` already
     * records WHEN the link lapsed, and `updated_at` records when the lapse was
     * observed. Same false-on-no-match contract as {@see self::revoke()}.
     */
    public function expire(
        ApplicationContext $context,
        string $tenant,
        string $linkUuid,
        \DateTimeImmutable $now
    ): bool {
        return $this->transitionFromActive($context, $tenant, $linkUuid, self::STATUS_EXPIRED, null, $now);
    }

    /**
     * Order-wide `active` -> `revoked` (design spec §2.2 `revoke()`, which is
     * addressed by ORDER, not by link). Idempotent by construction: a second
     * call affects zero rows.
     *
     * @return int rows actually transitioned
     */
    public function revokeActiveForOrder(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        \DateTimeImmutable $now
    ): int {
        return $this->transitionActiveForOrder($context, $tenant, $orderUuid, self::STATUS_REVOKED, 'revoked_at', $now);
    }

    /**
     * Order-wide `active` -> `consumed` -- the terminal transition an observed
     * `OrderPaid` drives (design spec §2.2: "order paid => link consumed").
     *
     * @return int rows actually transitioned
     */
    public function consumeActiveForOrder(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        \DateTimeImmutable $now
    ): int {
        return $this->transitionActiveForOrder(
            $context,
            $tenant,
            $orderUuid,
            self::STATUS_CONSUMED,
            'consumed_at',
            $now
        );
    }

    /**
     * Atomically claim one initiation against this link's FIXED UTC ONE-HOUR
     * window (design spec §2.2: "the rate window is a fixed UTC one-hour
     * window, reset atomically under the link lock").
     *
     * Fixed, not sliding: the window is the UTC hour `$now` falls in, floored.
     * A claim at 13:59:59 and one at 14:00:00 are in different windows, so the
     * counter RESETS at the boundary rather than aging entries out one at a
     * time. That is cheaper, has no per-attempt storage, and is the behaviour
     * the spec pins.
     *
     * MUST be called inside the caller's transaction, after the ORDER lock
     * (spec lock order). It takes the LINK lock itself rather than trusting the
     * caller to have taken it -- re-locking a row already locked by the same
     * transaction is free, and the alternative (an unlocked read-modify-write)
     * would double-count under concurrency. The write is additionally a
     * compare-and-set on the exact `(initiation_window_started_at,
     * initiation_count)` pair the locked read observed, so on any driver where
     * the lock were ever weaker than assumed the claim LOSES rather than
     * over-counting.
     *
     * The ceiling is `commerce.payment_links.initiations_per_hour` (default 10)
     * clamped to 1..100 by {@see CommerceSettings}. An explicit `$limit`
     * (tests, and any future per-tenant policy) goes through the SAME clamp, so
     * there is exactly one definition of the bound.
     *
     * Claimed BEFORE provider I/O, never after -- an unclaimed initiation that
     * later fails at the provider must still have consumed its budget, or the
     * ceiling protects nothing.
     *
     * @return bool true when this call consumed one unit of the window's
     *     budget; false when the ceiling is already reached, or the link is
     *     unknown/cross-tenant. A refused claim never advances the counter.
     */
    public function claimInitiationWindow(
        ApplicationContext $context,
        string $tenant,
        string $linkUuid,
        \DateTimeImmutable $now,
        ?int $limit = null
    ): bool {
        $ceiling = $limit === null
            ? CommerceSettings::paymentLinkInitiationsPerHour($context)
            : CommerceSettings::clampInitiationsPerHour($limit);

        $row = $this->findByUuidForUpdate($context, $tenant, $linkUuid);
        if ($row === null) {
            return false;
        }

        $windowStart = self::windowStart($now);
        $storedWindow = $row['initiation_window_started_at'] === null
            ? null
            : (string) $row['initiation_window_started_at'];
        $storedCount = (int) $row['initiation_count'];

        // A stored window that is not THIS window (or no window at all) means the
        // budget resets: the observed count is irrelevant to the new window.
        $sameWindow = $storedWindow !== null && self::normalize($storedWindow) === $windowStart;
        $countInWindow = $sameWindow ? $storedCount : 0;

        if ($countInWindow >= $ceiling) {
            return false;
        }

        // The CAS compares against the STORED pair, not the reset-to-zero view of it.
        $windowPredicate = $storedWindow === null
            ? 'initiation_window_started_at IS NULL'
            : 'initiation_window_started_at = ?';
        $params = [$windowStart, $countInWindow + 1, self::stamp($now), $tenant, $linkUuid, $storedCount];
        if ($storedWindow !== null) {
            $params[] = $storedWindow;
        }

        $affected = db($context)->table(self::TABLE)->executeModification(
            <<<SQL
UPDATE commerce_payment_links
SET initiation_window_started_at = ?, initiation_count = ?, updated_at = ?
WHERE tenant_uuid = ? AND uuid = ? AND initiation_count = ? AND {$windowPredicate}
SQL,
            $params
        );

        return $affected === 1;
    }

    /**
     * Record that a provider checkout session has been exposed for this link
     * (design spec §2.2 initiation Phase B: "stamps `provider_session_issued_at`
     * before returning it").
     *
     * `COALESCE` keeps the FIRST exposure instant: repeated clicks converge on
     * the one live session, and the forensic question the expiry/cancel guard
     * asks is "was a session EVER exposed", so overwriting the original time
     * would lose information and gain nothing. The stamp is never cleared and
     * survives every terminal transition.
     *
     * @return bool true when the tenant's link exists and is now marked exposed
     *     (whether or not this call was the one that first stamped it); false
     *     for an unknown or cross-tenant link.
     */
    public function stampProviderSessionIssued(
        ApplicationContext $context,
        string $tenant,
        string $linkUuid,
        \DateTimeImmutable $now
    ): bool {
        $affected = db($context)->table(self::TABLE)->executeModification(
            <<<SQL
UPDATE commerce_payment_links
SET provider_session_issued_at = COALESCE(provider_session_issued_at, ?), updated_at = ?
WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [self::stamp($now), self::stamp($now), $tenant, $linkUuid]
        );

        return $affected === 1;
    }

    /**
     * Every link for this order that the §2.2 expiry/cancel guard must consider:
     * an ACTIVE and UNEXPIRED link (a customer may still be about to pay), OR
     * ANY historical link -- whatever its status -- that has ever had a provider
     * session issued (money may already be in flight).
     *
     * This is the exact query the `(tenant_uuid, provider_session_issued_at,
     * order_uuid)` index exists for; the `(tenant_uuid, order_uuid, status)`
     * index serves the first branch. A revoked or expired link that was never
     * initiated is deliberately absent -- such an order returns to the ordinary
     * sweep.
     *
     * The expiry boundary is EXCLUSIVE (`expires_at > now`): at the stamp
     * itself the link has lapsed. Comparison is string-lexicographic over the
     * UTC `Y-m-d H:i:s` form both sides are written in, which is order-
     * preserving for that fixed-width format on every driver here.
     *
     * PREFILTER, NOT AUTHORITY: §2.2 is explicit that the guard re-reads and
     * re-decides inside each per-order transaction. This read is for candidate
     * selection only.
     *
     * @return list<array<string,mixed>>
     */
    public function guardRelevantLinks(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        \DateTimeImmutable $now
    ): array {
        return db($context)->table(self::TABLE)->executeRaw(
            <<<SQL
SELECT * FROM commerce_payment_links
WHERE tenant_uuid = ? AND order_uuid = ?
  AND ((status = ? AND expires_at > ?) OR provider_session_issued_at IS NOT NULL)
ORDER BY id ASC
SQL,
            [$tenant, $orderUuid, self::STATUS_ACTIVE, self::stamp($now)]
        );
    }

    /** The boolean form of {@see self::guardRelevantLinks()} -- same predicate, same caveats. */
    public function hasGuardRelevantLink(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        \DateTimeImmutable $now
    ): bool {
        return $this->guardRelevantLinks($context, $tenant, $orderUuid, $now) !== [];
    }

    /**
     * The shared per-link compare-and-set: only ever FROM `active`, so no
     * transition can resurrect a terminal link or overwrite another terminal
     * state's stamp.
     */
    private function transitionFromActive(
        ApplicationContext $context,
        string $tenant,
        string $linkUuid,
        string $status,
        ?string $stampColumn,
        \DateTimeImmutable $now
    ): bool {
        $stamp = $stampColumn === null ? '' : ", {$stampColumn} = ?";
        $params = [$status];
        if ($stampColumn !== null) {
            $params[] = self::stamp($now);
        }
        $params[] = self::stamp($now);
        $params[] = $tenant;
        $params[] = $linkUuid;
        $params[] = self::STATUS_ACTIVE;

        $affected = db($context)->table(self::TABLE)->executeModification(
            <<<SQL
UPDATE commerce_payment_links
SET status = ?{$stamp}, updated_at = ?
WHERE tenant_uuid = ? AND uuid = ? AND status = ?
SQL,
            $params
        );

        return $affected === 1;
    }

    /** The order-wide sibling of {@see self::transitionFromActive()}. */
    private function transitionActiveForOrder(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        string $status,
        ?string $stampColumn,
        \DateTimeImmutable $now
    ): int {
        $stamp = $stampColumn === null ? '' : ", {$stampColumn} = ?";
        $params = [$status];
        if ($stampColumn !== null) {
            $params[] = self::stamp($now);
        }
        $params[] = self::stamp($now);
        $params[] = $tenant;
        $params[] = $orderUuid;
        $params[] = self::STATUS_ACTIVE;

        return db($context)->table(self::TABLE)->executeModification(
            <<<SQL
UPDATE commerce_payment_links
SET status = ?{$stamp}, updated_at = ?
WHERE tenant_uuid = ? AND order_uuid = ? AND status = ?
SQL,
            $params
        );
    }

    /**
     * `FOR UPDATE` only for drivers that HAVE it. SQLite has no row-level
     * locking clause and needs none (a write transaction holds a database-wide
     * write lock, strictly stronger); appending it there is a syntax error. An
     * explicit allowlist rather than a sqlite exclusion, so a future driver
     * fails closed into the correct-but-more-contended unlocked read instead of
     * quietly losing serialization -- identical reasoning to
     * {@see OrderRepository::findByUuidForUpdate()}.
     */
    private function lockClause(ApplicationContext $context): string
    {
        return in_array(db($context)->getDriverName(), ['pgsql', 'mysql'], true) ? ' FOR UPDATE' : '';
    }

    /** The UTC hour `$now` falls in, floored -- the fixed rate window's identity. */
    private static function windowStart(\DateTimeImmutable $now): string
    {
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('G'), 0, 0)->format('Y-m-d H:i:s');
    }

    /** Every timestamp this class writes or compares is UTC `Y-m-d H:i:s`. */
    private static function stamp(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * A stored timestamp re-read for comparison. PostgreSQL may return a
     * fractional-second suffix a driver never wrote, so the stored value is
     * re-parsed rather than compared byte-for-byte. (The SQL compare-and-set
     * still binds the RAW stored string, so it matches whatever the column
     * actually holds.)
     */
    private static function normalize(string $stored): string
    {
        try {
            return (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return $stored;
        }
    }
}
