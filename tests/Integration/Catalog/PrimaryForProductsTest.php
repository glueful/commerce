<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\CountingPdoStatement;

/**
 * Storefront-v1 Task 1: {@see ProductMediaRepository::primaryForProducts()}
 * -- ONE batched read resolving each product's primary media row: its
 * `cover`-role row when one exists (regardless of position -- the
 * at-most-one-cover invariant is `demoteCover()`'s), else the first gallery
 * row by `position ASC, uuid ASC`. The read is ordered
 * `product_uuid, position ASC, uuid ASC`; the cover preference is a PHP-side
 * reduction over that ordered set. Input passes through the shared pinned
 * uuid normalizer; empty normalized set issues zero queries; products
 * without media are simply absent.
 */
final class PrimaryForProductsTest extends CommerceTestCase
{
    private const TENANT = 'tenantPFP001';
    private const OTHER_TENANT = 'tenantPFP002';

    private ProductMediaRepository $media;

    protected function setUp(): void
    {
        parent::setUp();
        $this->media = new ProductMediaRepository();
    }

    // === Empty / malformed input: zero queries ==================================

    public function testEmptyAndFullyMalformedInputIssueNoQuery(): void
    {
        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        CountingPdoStatement::$count = 0;

        self::assertSame([], $this->media->primaryForProducts($this->context, self::TENANT, []));
        self::assertSame([], $this->media->primaryForProducts($this->context, self::TENANT, [
            'under_score1',
            'x',
            '',
            null,
        ]));
        self::assertSame(0, CountingPdoStatement::$count);
    }

    // === Cover preference ========================================================

    public function testCoverRoleWinsOverAnEarlierPositionedGalleryRow(): void
    {
        $this->seedMedia('mediagall001', 'prodpfpcov01', 'blobgall0001', 'gallery', 0);
        $this->seedMedia('mediacovr001', 'prodpfpcov01', 'blobcovr0001', 'cover', 5);

        $result = $this->media->primaryForProducts($this->context, self::TENANT, ['prodpfpcov01']);

        self::assertSame('mediacovr001', $result['prodpfpcov01']['uuid']);
        self::assertSame('cover', $result['prodpfpcov01']['role']);
    }

    public function testFirstGalleryByPositionWhenNoCoverExists(): void
    {
        $this->seedMedia('mediapos3001', 'prodpfpgal01', 'blobpos30001', 'gallery', 3);
        $this->seedMedia('mediapos1001', 'prodpfpgal01', 'blobpos10001', 'gallery', 1);

        $result = $this->media->primaryForProducts($this->context, self::TENANT, ['prodpfpgal01']);

        self::assertSame('mediapos1001', $result['prodpfpgal01']['uuid']);
    }

    public function testPositionTieBreaksByUuidAsc(): void
    {
        $this->seedMedia('mediatie0002', 'prodpfptie01', 'blobtie20001', 'gallery', 2);
        $this->seedMedia('mediatie0001', 'prodpfptie01', 'blobtie10001', 'gallery', 2);

        $result = $this->media->primaryForProducts($this->context, self::TENANT, ['prodpfptie01']);

        self::assertSame('mediatie0001', $result['prodpfptie01']['uuid']);
    }

    public function testProductsWithoutMediaAreAbsent(): void
    {
        $this->seedMedia('mediaonly001', 'prodpfphas01', 'blobonly0001', 'gallery', 0);

        $result = $this->media->primaryForProducts($this->context, self::TENANT, [
            'prodpfphas01',
            'prodpfpnone1',
        ]);

        self::assertSame(['prodpfphas01'], array_keys($result));
    }

    // === Tenant isolation ========================================================

    public function testAnotherTenantsMediaRowsNeverResolve(): void
    {
        $this->seedMedia('mediaother01', 'prodpfpoth01', 'blobother001', 'cover', 0, self::OTHER_TENANT);

        // Same product also has a gallery row in THIS tenant: the other
        // tenant's cover must not win, and the other-tenant-only product
        // stays absent.
        $this->seedMedia('mediamine001', 'prodpfpmix01', 'blobmine0001', 'gallery', 4);
        $this->seedMedia('mediaothcov1', 'prodpfpmix01', 'blobothcov01', 'cover', 0, self::OTHER_TENANT);

        $result = $this->media->primaryForProducts($this->context, self::TENANT, [
            'prodpfpoth01',
            'prodpfpmix01',
        ]);

        self::assertArrayNotHasKey('prodpfpoth01', $result);
        self::assertSame('mediamine001', $result['prodpfpmix01']['uuid']);
    }

    // === Query-count guard =======================================================

    public function testNonEmptyInputIssuesExactlyOneQuery(): void
    {
        $this->seedMedia('mediacount01', 'prodpfpcnt01', 'blobcount001', 'cover', 0);
        $this->seedMedia('mediacount02', 'prodpfpcnt02', 'blobcount002', 'gallery', 0);

        // Warm-up: the framework's SoftDeleteHandler runs a one-time,
        // process-cached schema probe the first time any query touches a
        // given table -- run once, unmeasured.
        $this->media->primaryForProducts($this->context, self::TENANT, ['prodpfpcnt01']);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        CountingPdoStatement::$count = 0;

        $result = $this->media->primaryForProducts($this->context, self::TENANT, [
            'prodpfpcnt01',
            'prodpfpcnt02',
        ]);

        self::assertSame(1, CountingPdoStatement::$count, 'expected exactly one batched query');
        self::assertSame(['prodpfpcnt01', 'prodpfpcnt02'], array_keys($result));
    }

    // === Fixtures ================================================================

    private function seedMedia(
        string $uuid,
        string $productUuid,
        string $blobUuid,
        string $role,
        int $position,
        string $tenant = self::TENANT
    ): void {
        $this->connection->table('commerce_product_media')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'blob_uuid' => $blobUuid,
            'role' => $role,
            'position' => $position,
        ]);
    }
}
