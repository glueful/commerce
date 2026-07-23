<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\StaleCatalogRevisionException;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Task A1 (single-page product editor plan): the guarded, CAS-style sibling of
 * {@see ProductRepository::claimCatalogRevision()}. That primitive is an
 * unconditional claim-then-bump used by every existing relationship/set-list
 * mutation; THIS one only bumps when the caller's `$expected` revision still
 * matches -- the guard a future `expected_revision` replacement mutation
 * (Task A5) needs to fail a stale save with 409 instead of silently
 * overwriting a concurrent edit. Covers every 0-affected-row outcome the
 * guarded UPDATE can produce (stale live row, unknown uuid, cross-tenant uuid,
 * tombstoned product either with a matching or a stale stored revision -- all
 * of which must resolve to 'missing', never bump the counter, and never be
 * confused with a genuine 'stale') plus {@see ProductRepository::catalogRevision()}'s
 * live-only read.
 */
final class ClaimCatalogRevisionExpectingTest extends CommerceTestCase
{
    private const TENANT_A = 'tenantAAAA01';
    private const TENANT_B = 'tenantBBBB02';

    public function testMatchingRevisionClaimsAndBumpsCounterByOne(): void
    {
        $this->seedProduct(self::TENANT_A, 'prodexp00001');
        $repository = new ProductRepository();

        self::assertSame(
            'claimed',
            $repository->claimCatalogRevisionExpecting($this->context, self::TENANT_A, 'prodexp00001', 0)
        );
        self::assertSame(1, $this->currentRevision('prodexp00001', self::TENANT_A));
    }

    public function testStaleRevisionReturnsStaleAndLeavesCounterUnchanged(): void
    {
        $this->seedProduct(self::TENANT_A, 'prodexp00002');
        $repository = new ProductRepository();

        self::assertSame(
            'stale',
            $repository->claimCatalogRevisionExpecting($this->context, self::TENANT_A, 'prodexp00002', 1)
        );
        self::assertSame(0, $this->currentRevision('prodexp00002', self::TENANT_A));
    }

    public function testUnknownUuidReturnsMissing(): void
    {
        $repository = new ProductRepository();

        self::assertSame(
            'missing',
            $repository->claimCatalogRevisionExpecting($this->context, self::TENANT_A, 'no-such-product', 0)
        );
    }

    public function testCrossTenantUuidReturnsMissingAndLeavesCounterUnchanged(): void
    {
        $this->seedProduct(self::TENANT_B, 'prodexp00003');
        $repository = new ProductRepository();

        self::assertSame(
            'missing',
            $repository->claimCatalogRevisionExpecting($this->context, self::TENANT_A, 'prodexp00003', 0)
        );
        self::assertSame(0, $this->currentRevision('prodexp00003', self::TENANT_B));
    }

    public function testTombstonedProductWithMatchingRevisionReturnsMissingAndIsNeverBumped(): void
    {
        $this->seedProduct(self::TENANT_A, 'prodexp00004');
        $repository = new ProductRepository();
        self::assertTrue($repository->markDeleted($this->context, self::TENANT_A, 'prodexp00004'));

        self::assertSame(
            'missing',
            $repository->claimCatalogRevisionExpecting($this->context, self::TENANT_A, 'prodexp00004', 0)
        );
        self::assertSame(0, $this->currentRevision('prodexp00004', self::TENANT_A));
    }

    public function testTombstonedProductWithStaleRevisionReturnsMissingAndIsNeverBumped(): void
    {
        $this->seedProduct(self::TENANT_A, 'prodexp00005');
        $repository = new ProductRepository();
        self::assertTrue($repository->markDeleted($this->context, self::TENANT_A, 'prodexp00005'));

        self::assertSame(
            'missing',
            $repository->claimCatalogRevisionExpecting($this->context, self::TENANT_A, 'prodexp00005', 7)
        );
        self::assertSame(0, $this->currentRevision('prodexp00005', self::TENANT_A));
    }

    public function testCatalogRevisionReturnsLiveValueAndReflectsASubsequentClaim(): void
    {
        $this->seedProduct(self::TENANT_A, 'prodexp00006');
        $repository = new ProductRepository();

        self::assertSame(0, $repository->catalogRevision($this->context, self::TENANT_A, 'prodexp00006'));

        self::assertSame(
            'claimed',
            $repository->claimCatalogRevisionExpecting($this->context, self::TENANT_A, 'prodexp00006', 0)
        );
        self::assertSame(1, $repository->catalogRevision($this->context, self::TENANT_A, 'prodexp00006'));
    }

    public function testCatalogRevisionReturnsNullForUnknownUuid(): void
    {
        $repository = new ProductRepository();

        self::assertNull($repository->catalogRevision($this->context, self::TENANT_A, 'no-such-product'));
    }

    public function testCatalogRevisionReturnsNullForCrossTenantUuid(): void
    {
        $this->seedProduct(self::TENANT_B, 'prodexp00007');
        $repository = new ProductRepository();

        self::assertNull($repository->catalogRevision($this->context, self::TENANT_A, 'prodexp00007'));
    }

    public function testCatalogRevisionReturnsNullForTombstonedProduct(): void
    {
        $this->seedProduct(self::TENANT_A, 'prodexp00008');
        $repository = new ProductRepository();
        self::assertTrue($repository->markDeleted($this->context, self::TENANT_A, 'prodexp00008'));

        self::assertNull($repository->catalogRevision($this->context, self::TENANT_A, 'prodexp00008'));
    }

    /**
     * Structural smoke test for the new exception type this task defines
     * (Task A5 throws it later; nothing in this task throws it yet).
     */
    public function testStaleCatalogRevisionExceptionExtendsDomainException(): void
    {
        $exception = new StaleCatalogRevisionException('Product was modified by another request.');

        self::assertInstanceOf(\DomainException::class, $exception);
        self::assertSame('Product was modified by another request.', $exception->getMessage());
    }

    /**
     * `withTrashed()` -- a tombstoned row must stay reachable here so a
     * tombstone test can assert its counter truly never moved, the same way
     * {@see \Glueful\Extensions\Commerce\Catalog\ProductRepository::findIncludingDeletedByUuid()}
     * bypasses the framework's automatic soft-delete filter.
     */
    private function currentRevision(string $uuid, string $tenant): int
    {
        $row = $this->connection->table('commerce_products')
            ->withTrashed()
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? -1 : (int) $row['catalog_revision'];
    }

    private function seedProduct(string $tenant, string $uuid): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'type' => 'physical',
            'status' => 'active',
        ]);
    }
}
