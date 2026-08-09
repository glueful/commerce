<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Extensions\Commerce\Orders\DraftAttemptRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Admin-order-creation cycle 2, Task 6 (design spec §2.6): `DraftAttemptRepository`,
 * the finalize idempotency ledger for admin-created ("walk-in") orders. UNIQUE
 * `(tenant_uuid, idempotency_key)` is the sole key authority -- these tests exercise
 * `claimOrReplay()`/`complete()` sequentially (single connection); the genuine
 * concurrent-first-claim race lives in `DraftAttemptRepositoryPgsqlTest` (needs two
 * real database connections, so SQLite can't produce it).
 */
final class DraftAttemptRepositoryTest extends CommerceTestCase
{
    public function testFreshClaimInsertsPendingRowAndReturnsFreshState(): void
    {
        $repo = new DraftAttemptRepository();

        $result = $repo->claimOrReplay($this->context, 'tenantA00001', 'idem-key-1', 'fp-aaaa', 'orderuuid001');

        self::assertSame('fresh', $result['state']);
        self::assertSame('tenantA00001', $result['attempt']['tenant_uuid']);
        self::assertSame('idem-key-1', $result['attempt']['idempotency_key']);
        self::assertSame('fp-aaaa', $result['attempt']['request_fingerprint']);
        self::assertSame('orderuuid001', $result['attempt']['order_uuid']);
        self::assertSame('pending', $result['attempt']['status']);
        self::assertNull($result['attempt']['completed_at']);

        self::assertSame(
            1,
            $this->connection->table('commerce_order_draft_attempts')
                ->where('tenant_uuid', '=', 'tenantA00001')
                ->count()
        );
    }

    public function testSameKeySameFingerprintReplaysTheExistingPendingAttemptWithNoSecondInsert(): void
    {
        $repo = new DraftAttemptRepository();
        $repo->claimOrReplay($this->context, 'tenantA00002', 'idem-key-2', 'fp-bbbb', 'orderuuid002');

        $result = $repo->claimOrReplay($this->context, 'tenantA00002', 'idem-key-2', 'fp-bbbb', 'orderuuid999');

        self::assertSame('replay', $result['state']);
        // The ORIGINAL order_uuid, not the second caller's argument -- a replay
        // resolves to what already exists, never re-executes.
        self::assertSame('orderuuid002', $result['attempt']['order_uuid']);
        self::assertSame(
            1,
            $this->connection->table('commerce_order_draft_attempts')
                ->where('tenant_uuid', '=', 'tenantA00002')
                ->count()
        );
    }

    public function testSameKeyDifferentFingerprintReturnsFingerprintMismatchWithNoSecondInsert(): void
    {
        $repo = new DraftAttemptRepository();
        $repo->claimOrReplay($this->context, 'tenantA00003', 'idem-key-3', 'fp-cccc', 'orderuuid003');

        $result = $repo->claimOrReplay($this->context, 'tenantA00003', 'idem-key-3', 'fp-dddd', 'orderuuid888');

        self::assertSame('fingerprint_mismatch', $result['state']);
        self::assertSame('fp-cccc', $result['attempt']['request_fingerprint']);
        self::assertSame(
            1,
            $this->connection->table('commerce_order_draft_attempts')
                ->where('tenant_uuid', '=', 'tenantA00003')
                ->count()
        );
    }

    public function testCompleteMarksStatusCompletedWithATimestamp(): void
    {
        $repo = new DraftAttemptRepository();
        $result = $repo->claimOrReplay($this->context, 'tenantA00004', 'idem-key-4', 'fp-eeee', 'orderuuid004');

        $repo->complete($this->context, (int) $result['attempt']['id']);

        $row = $this->connection->table('commerce_order_draft_attempts')
            ->where('tenant_uuid', '=', 'tenantA00004')
            ->first();
        self::assertNotNull($row);
        self::assertSame('completed', $row['status']);
        self::assertNotNull($row['completed_at']);
    }

    public function testReplayAfterCompleteStillResolvesToTheCompletedAttempt(): void
    {
        $repo = new DraftAttemptRepository();
        $first = $repo->claimOrReplay($this->context, 'tenantA00005', 'idem-key-5', 'fp-ffff', 'orderuuid005');
        $repo->complete($this->context, (int) $first['attempt']['id']);

        $result = $repo->claimOrReplay($this->context, 'tenantA00005', 'idem-key-5', 'fp-ffff', 'orderuuid777');

        self::assertSame('replay', $result['state']);
        self::assertSame('completed', $result['attempt']['status']);
        self::assertSame('orderuuid005', $result['attempt']['order_uuid']);
    }

    public function testDifferentTenantsWithTheSameIdempotencyKeyClaimIndependently(): void
    {
        $repo = new DraftAttemptRepository();

        $a = $repo->claimOrReplay($this->context, 'tenantSHARE1', 'idem-shared', 'fp-a', 'orderuuidaaa');
        $b = $repo->claimOrReplay($this->context, 'tenantSHARE2', 'idem-shared', 'fp-b', 'orderuuidbbb');

        self::assertSame('fresh', $a['state']);
        self::assertSame('fresh', $b['state']);
        self::assertSame('orderuuidaaa', $a['attempt']['order_uuid']);
        self::assertSame('orderuuidbbb', $b['attempt']['order_uuid']);
    }

    /**
     * Mirrors `Wishlist\WishlistRepositoryTest::testARealDatabaseFailureIsNotReportedAsAlreadySaved()`:
     * dropping the table the repository targets makes BOTH the pre-check lookup and the
     * insert attempt fail with a genuinely unrelated error (not a unique violation) --
     * proving `claimOrReplay()` never swallows or misclassifies a real database outage /
     * schema-drift failure as any of its three typed states.
     */
    public function testUnrelatedDatabaseFailureIsNotSwallowedOrMisclassified(): void
    {
        $this->connection->getPDO()->exec('DROP TABLE commerce_order_draft_attempts');

        $this->expectException(\Throwable::class);
        (new DraftAttemptRepository())->claimOrReplay(
            $this->context,
            'tenantFAIL01',
            'idem-fail',
            'fp-fail',
            'orderuuidfai'
        );
    }
}
