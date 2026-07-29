<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\CountingPdoStatement;

/**
 * Storefront-v1 Task 1: {@see ProductRepository::findActiveBuyerAvailableByUuids()}
 * -- ONE batched `IN (...)` read that resolves each candidate uuid to exactly
 * the row {@see ProductRepository::listActive()} would consider buyable, by
 * reusing `activeFilteredQuery()` (the live+active+buyer-available predicate
 * authority) rather than re-deriving any predicate. Input passes through the
 * shared pinned uuid normalizer (`/\A[A-Za-z0-9]{12}\z/`, first-occurrence
 * dedupe, first-100 cap, malformed dropped); an empty normalized set issues
 * zero queries.
 */
final class FindActiveBuyerAvailableByUuidsTest extends CommerceTestCase
{
    private const TENANT = 'tenantFAB001';
    private const OTHER_TENANT = 'tenantFAB002';

    private ProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();

        // The buyer-availability leg of the predicate is gated on the
        // marketplace INSTALL master switch (see
        // `ProductRepository::applyBuyerAvailability()`) -- enable it so the
        // seller-unavailable parity case below is a real exclusion.
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);

        $this->products = new ProductRepository();
    }

    // === Empty / malformed input: zero queries ==================================

    public function testEmptyInputReturnsEmptyArrayWithZeroQueries(): void
    {
        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        CountingPdoStatement::$count = 0;

        self::assertSame([], $this->products->findActiveBuyerAvailableByUuids($this->context, self::TENANT, []));
        self::assertSame(0, CountingPdoStatement::$count, 'empty input must never reach the database');
    }

    public function testAllMalformedInputReturnsEmptyArrayWithZeroQueries(): void
    {
        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        CountingPdoStatement::$count = 0;

        $result = $this->products->findActiveBuyerAvailableByUuids($this->context, self::TENANT, [
            'too-short',
            'thirteenchars',
            'under_score1',
            'hyphen-uuid1',
            '',
            123,
            null,
            ['prodavail001'],
        ]);

        self::assertSame([], $result);
        self::assertSame(0, CountingPdoStatement::$count, 'a fully-dropped input must never reach the database');
    }

    // === Parity with listActive(): the buyable set ==============================

    public function testReturnsOnlyTheRowsListActiveWouldConsiderBuyableKeyedByUuid(): void
    {
        $this->seedProduct(['uuid' => 'prodavail001', 'slug' => 'avail']);
        $this->seedProduct(['uuid' => 'prodinact001', 'slug' => 'inact', 'status' => 'draft']);
        $this->seedProduct(['uuid' => 'proddelet001', 'slug' => 'delet', 'deleted_at' => '2026-01-01 00:00:00']);
        $this->seedProduct(['uuid' => 'prodother001', 'slug' => 'other'], self::OTHER_TENANT);
        $this->seedSeller('sellersusp01', 'suspended');
        $this->seedProduct(['uuid' => 'prodsusp0001', 'slug' => 'susp', 'seller_uuid' => 'sellersusp01']);

        $result = $this->products->findActiveBuyerAvailableByUuids($this->context, self::TENANT, [
            'prodavail001',
            'prodinact001',
            'proddelet001',
            'prodother001',
            'prodsusp0001',
        ]);

        self::assertSame(['prodavail001'], array_keys($result));
        self::assertSame('prodavail001', $result['prodavail001']['uuid']);

        // Cross-check against the authority itself: the same fixture through
        // listActive() yields the same single buyable product.
        $listed = $this->products->listActive($this->context, self::TENANT, 1, 25, null);
        self::assertSame(1, $listed['total']);
        self::assertSame('prodavail001', $listed['items'][0]['uuid']);
    }

    public function testRowsComeBackJsonDecodedLikeEveryOtherProductRead(): void
    {
        $this->products->insert($this->context, [
            'uuid' => 'proddecode01',
            'tenant_uuid' => self::TENANT,
            'slug' => 'decode',
            'name' => 'Decode',
            'type' => 'physical',
            'status' => 'active',
            'options' => ['color' => 'red'],
        ]);

        $result = $this->products->findActiveBuyerAvailableByUuids(
            $this->context,
            self::TENANT,
            ['proddecode01']
        );

        self::assertSame(['color' => 'red'], $result['proddecode01']['options']);
    }

    // === Normalizer: dedupe, malformed-among-valid, first-100 cap ===============

    public function testDuplicatesAreDedupedAndAMalformedValueAmongValidOnesIsIgnored(): void
    {
        $this->seedProduct(['uuid' => 'proddedup001', 'slug' => 'dedup-a']);
        $this->seedProduct(['uuid' => 'proddedup002', 'slug' => 'dedup-b']);

        $result = $this->products->findActiveBuyerAvailableByUuids($this->context, self::TENANT, [
            'proddedup001',
            'not!a!uuid!!',
            'proddedup001',
            'proddedup002',
        ]);

        self::assertSame(['proddedup001', 'proddedup002'], array_keys($result));
    }

    public function testCapAppliesAfterDedupeNotBeforeIt(): void
    {
        $this->seedProduct(['uuid' => 'prodcapdup01', 'slug' => 'cap-dup-a']);
        $this->seedProduct(['uuid' => 'prodcapdup02', 'slug' => 'cap-dup-b']);

        // 100 raw occurrences of A followed by B: dedupe-first leaves [A, B],
        // both inside the cap -- a cap-first implementation would drop B.
        $input = array_fill(0, 100, 'prodcapdup01');
        $input[] = 'prodcapdup02';

        $result = $this->products->findActiveBuyerAvailableByUuids($this->context, self::TENANT, $input);

        self::assertSame(['prodcapdup01', 'prodcapdup02'], array_keys($result));
    }

    public function testOnlyTheFirstHundredDistinctUuidsResolve(): void
    {
        $this->seedProduct(['uuid' => 'prodcaplast1', 'slug' => 'cap-last']);
        $this->seedProduct(['uuid' => 'prodcapover1', 'slug' => 'cap-over']);

        // 99 well-formed fillers, then a buyer-available product at position
        // 100 (kept) and another at position 101 (dropped by the cap).
        $input = [];
        for ($i = 1; $i <= 99; $i++) {
            $input[] = sprintf('uuidcap%05d', $i);
        }
        $input[] = 'prodcaplast1';
        $input[] = 'prodcapover1';

        $result = $this->products->findActiveBuyerAvailableByUuids($this->context, self::TENANT, $input);

        self::assertArrayHasKey('prodcaplast1', $result);
        self::assertArrayNotHasKey('prodcapover1', $result, 'value 101 must be dropped by the first-100 cap');
    }

    // === Query-count guard =======================================================

    public function testNonEmptyInputIssuesExactlyOneQuery(): void
    {
        $this->seedProduct(['uuid' => 'prodcount001', 'slug' => 'count-a']);
        $this->seedProduct(['uuid' => 'prodcount002', 'slug' => 'count-b']);

        // Warm-up: the framework's SoftDeleteHandler runs a one-time,
        // process-cached schema probe the first time any query touches
        // `commerce_products` -- run once, unmeasured.
        $this->products->findActiveBuyerAvailableByUuids($this->context, self::TENANT, ['prodcount001']);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        CountingPdoStatement::$count = 0;

        $result = $this->products->findActiveBuyerAvailableByUuids($this->context, self::TENANT, [
            'prodcount001',
            'prodcount002',
        ]);

        self::assertSame(1, CountingPdoStatement::$count, 'expected exactly one batched query');
        self::assertSame(['prodcount001', 'prodcount002'], array_keys($result));
    }

    // === Fixtures ================================================================

    /** @param array<string,mixed> $overrides */
    private function seedProduct(array $overrides, string $tenant = self::TENANT): string
    {
        $uuid = (string) $overrides['uuid'];
        $this->connection->table('commerce_products')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => 'slug-' . $uuid,
            'name' => 'Product ' . $uuid,
            'type' => 'physical',
            'status' => 'active',
        ], $overrides, ['uuid' => $uuid, 'tenant_uuid' => $tenant]));

        return $uuid;
    }

    private function seedSeller(string $uuid, string $status): void
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'status' => $status,
        ]);
    }
}
