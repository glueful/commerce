<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * The shared per-workspace serialization boundary (design spec §4 lock
 * order). Every scenario here wraps `claim()` in its own `Connection::transaction()`
 * call, mirroring the contract real callers must honor (Task 3's product-create
 * and activation flows claim this INSIDE their own open transaction) -- this is
 * also what turns the nested-savepoint behavior under test into a real SAVEPOINT
 * rather than a bare top-level transaction.
 */
final class MarketplaceWorkspaceLockTest extends CommerceTestCase
{
    public function testTwoSequentialClaimsForABrandNewTenantEnsureTheRowExactlyOnceAndBumpRevisionEachTime(): void
    {
        $lock = new MarketplaceWorkspaceLock();

        // The second call's ensureRow() has no upfront existence check -- it always
        // attempts the insert directly (design docblock), so this second call is
        // ALSO the deterministic single-connection proxy for "two callers race to
        // create the first row": its insert genuinely collides with the row the
        // first call just committed, forcing the exact same catch-and-probe path a
        // true concurrent second caller would hit.
        $this->connection->transaction(function () use ($lock): void {
            $lock->claim($this->context, 'tenantSEQ0001');
        });
        $first = $this->settingsRow('tenantSEQ0001');
        self::assertNotNull($first);
        self::assertSame(1, (int) $first['revision']);
        self::assertSame('disabled', $first['status']);

        $this->connection->transaction(function () use ($lock): void {
            $lock->claim($this->context, 'tenantSEQ0001');
        });
        $second = $this->settingsRow('tenantSEQ0001');
        self::assertNotNull($second);
        self::assertSame(2, (int) $second['revision']);
        self::assertSame($first['uuid'], $second['uuid'], 'the same row is reused, never recreated');

        self::assertSame(
            1,
            $this->connection->table('commerce_marketplace_settings')
                ->where('tenant_uuid', '=', 'tenantSEQ0001')
                ->count(),
            'exactly one settings row ever exists for the tenant'
        );
    }

    public function testClaimOnATenantWhoseRowWasCreatedOutOfBandJustClaimsItWithoutDuplicating(): void
    {
        // Simulate a row a different request/process already ensured (but never
        // claimed) for this tenant -- the pre-insert bypasses the lock entirely,
        // the house "concurrent writer already committed" test convention (see
        // DownloadGrantService's GrantIssuanceTest::testPreInsertedGrantRowIsReloadedIdempotentlyNotDuplicated()).
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktpreexist1',
            'tenant_uuid' => 'tenantPRE0001',
            'status' => 'disabled',
        ]);

        $lock = new MarketplaceWorkspaceLock();
        $this->connection->transaction(function () use ($lock): void {
            $lock->claim($this->context, 'tenantPRE0001');
        });

        $row = $this->settingsRow('tenantPRE0001');
        self::assertNotNull($row);
        self::assertSame('mktpreexist1', $row['uuid'], 'the pre-existing row is reused, not duplicated');
        self::assertSame(1, (int) $row['revision']);
    }

    public function testVerifiedDuplicateConflictRollsBackOnlyTheSavepointAndOuterTransactionStaysUsable(): void
    {
        $lock = new MarketplaceWorkspaceLock();

        $this->connection->transaction(function () use ($lock): void {
            $lock->claim($this->context, 'tenantRACE001');
        });
        self::assertSame(1, (int) $this->settingsRow('tenantRACE001')['revision']);

        // The claim below forces ensureRow()'s insert attempt to collide with the
        // row created above (the verified tenant-unique conflict); the nested
        // transaction() call rolls back ONLY its own savepoint before this is
        // caught and probed. Proof the outer transaction survives: an UNRELATED
        // write happens in the SAME outer transaction right after, and the whole
        // thing commits.
        $this->connection->transaction(function () use ($lock): void {
            $lock->claim($this->context, 'tenantRACE001');

            $this->connection->table('commerce_sellers')->insert([
                'uuid' => 'sellerpostrc1',
                'tenant_uuid' => 'tenantRACE001',
                'slug' => 'post-race',
                'name' => 'Post Race',
            ]);
        });

        self::assertSame(
            2,
            (int) $this->settingsRow('tenantRACE001')['revision'],
            'the claim inside the same outer transaction as the conflict still committed'
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_marketplace_settings')
                ->where('tenant_uuid', '=', 'tenantRACE001')
                ->count()
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerpostrc1')->count(),
            'the unrelated write made AFTER the conflict, in the same outer transaction, was committed -- '
                . 'proof the outer transaction was never poisoned by the savepoint-scoped conflict'
        );
    }

    public function testUnrelatedInsertFailurePropagatesAndLeavesNoRowBehindForThatTenant(): void
    {
        // A DIFFERENT tenant already owns this uuid.
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'fixeduuid001',
            'tenant_uuid' => 'tenantOTHERX1',
            'status' => 'disabled',
        ]);

        // Force THIS tenant's ensure-insert to reuse that exact uuid -- a genuine
        // `\PDOException` from the separately-named `uuid` unique constraint, never
        // conflated with the `tenant_uuid` conflict this lock exists to arbitrate
        // (its probe reads by `tenant_uuid` only).
        $lock = new MarketplaceWorkspaceLock(static fn (): string => 'fixeduuid001');

        try {
            $this->connection->transaction(function () use ($lock): void {
                $lock->claim($this->context, 'tenantFRESH001');
            });
            self::fail('an unrelated uuid collision must propagate, never be swallowed as a verified duplicate');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        self::assertNull(
            $this->settingsRow('tenantFRESH001'),
            'no row should exist for the tenant whose claim failed for an unrelated reason'
        );
        self::assertNotNull(
            $this->settingsRow('tenantOTHERX1'),
            'the pre-existing, unrelated row must be untouched'
        );
    }

    /** @return array<string,mixed>|null */
    private function settingsRow(string $tenant): ?array
    {
        return $this->connection->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', $tenant)
            ->first();
    }
}
