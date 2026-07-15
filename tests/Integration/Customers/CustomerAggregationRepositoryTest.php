<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Customers;

use Glueful\Extensions\Commerce\Customers\CustomerAggregationRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Order-derived customer aggregation (design spec §7) — grouping math, sort,
 * pagination, email-substring filter, and both by-key detail lookups. No
 * dedicated customer table exists; every assertion here is really an
 * assertion about the raw SQL this repository hand-builds (see its own
 * docblock for why `groupBy()`/`orderBy()` can't be used for the listing
 * query), so this suite is the primary portability proof for that SQL.
 */
final class CustomerAggregationRepositoryTest extends CommerceTestCase
{
    public function testMixedUserAndGuestOrdersAggregateIntoSeparateCustomersWithCorrectTotals(): void
    {
        // Same authenticated user, two orders.
        $this->seedOrder('orderagg0001', userUuid: 'useragg00001', email: 'user@example.com', grandTotal: 1000);
        $this->seedOrder(
            'orderagg0002',
            userUuid: 'useragg00001',
            email: 'user@example.com',
            grandTotal: 500,
            refundedTotal: 100
        );
        // A distinct guest, one order.
        $this->seedOrder('orderagg0003', userUuid: null, email: 'guest@example.com', grandTotal: 700);

        $result = $this->repo()->paginate($this->context, '', [], 'last_order_at', 'desc', 1, 25);

        self::assertSame(2, $result['total']);
        $byKey = $this->indexByKey($result['items']);

        self::assertSame('user', $byKey['useragg00001']['key_type']);
        self::assertSame('useragg00001', $byKey['useragg00001']['user_uuid']);
        self::assertSame(2, $byKey['useragg00001']['orders_count']);
        self::assertSame(1500, $byKey['useragg00001']['total_spent_minor']);
        self::assertSame(100, $byKey['useragg00001']['refunded_minor']);

        self::assertSame('email', $byKey['guest@example.com']['key_type']);
        self::assertNull($byKey['guest@example.com']['user_uuid']);
        self::assertSame(1, $byKey['guest@example.com']['orders_count']);
        self::assertSame(700, $byKey['guest@example.com']['total_spent_minor']);
        self::assertSame(0, $byKey['guest@example.com']['refunded_minor']);
    }

    public function testGuestEmailCaseAndWhitespaceVariationsGroupIntoOneCustomer(): void
    {
        $this->seedOrder('orderagg0011', userUuid: null, email: ' Guest@Example.COM ', grandTotal: 300);
        $this->seedOrder('orderagg0012', userUuid: null, email: 'guest@example.com', grandTotal: 200);
        $this->seedOrder('orderagg0013', userUuid: null, email: 'GUEST@example.com', grandTotal: 100);

        $result = $this->repo()->paginate($this->context, '', [], 'last_order_at', 'desc', 1, 25);

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['items']);
        self::assertSame(3, $result['items'][0]['orders_count']);
        self::assertSame(600, $result['items'][0]['total_spent_minor']);
        self::assertSame('guest@example.com', $result['items'][0]['key']);
    }

    public function testSortByTotalSpentDescendingOrdersCustomersHighestFirst(): void
    {
        $this->seedOrder('orderagg0021', userUuid: null, email: 'low@example.com', grandTotal: 100);
        $this->seedOrder('orderagg0022', userUuid: null, email: 'high@example.com', grandTotal: 900);

        $result = $this->repo()->paginate($this->context, '', [], 'total_spent', 'desc', 1, 25);

        self::assertSame('high@example.com', $result['items'][0]['key']);
        self::assertSame('low@example.com', $result['items'][1]['key']);
    }

    public function testSortByLastOrderAtAscendingOrdersOldestFirst(): void
    {
        $this->seedOrder(
            'orderagg0031',
            userUuid: null,
            email: 'later@example.com',
            grandTotal: 100,
            createdAt: '2026-02-01 00:00:00'
        );
        $this->seedOrder(
            'orderagg0032',
            userUuid: null,
            email: 'earlier@example.com',
            grandTotal: 100,
            createdAt: '2026-01-01 00:00:00'
        );

        $result = $this->repo()->paginate($this->context, '', [], 'last_order_at', 'asc', 1, 25);

        self::assertSame('earlier@example.com', $result['items'][0]['key']);
        self::assertSame('later@example.com', $result['items'][1]['key']);
    }

    public function testEmailSubstringFilterNarrowsListing(): void
    {
        $this->seedOrder('orderagg0041', userUuid: null, email: 'alpha@foo.com', grandTotal: 100);
        $this->seedOrder('orderagg0042', userUuid: null, email: 'beta@bar.com', grandTotal: 100);

        $result = $this->repo()->paginate($this->context, '', ['email' => 'foo'], 'last_order_at', 'desc', 1, 25);

        self::assertSame(1, $result['total']);
        self::assertSame('alpha@foo.com', $result['items'][0]['key']);
    }

    public function testPaginationLimitsAndOffsetsResults(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seedOrder(
                'orderaggpg' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                userUuid: null,
                email: "guest{$i}@example.com",
                grandTotal: 100,
                createdAt: sprintf('2026-01-0%d 00:00:00', $i)
            );
        }

        $page1 = $this->repo()->paginate($this->context, '', [], 'last_order_at', 'asc', 1, 2);
        $page2 = $this->repo()->paginate($this->context, '', [], 'last_order_at', 'asc', 2, 2);
        $page3 = $this->repo()->paginate($this->context, '', [], 'last_order_at', 'asc', 3, 2);

        self::assertSame(5, $page1['total']);
        self::assertCount(2, $page1['items']);
        self::assertCount(2, $page2['items']);
        self::assertCount(1, $page3['items']);
        self::assertSame('guest1@example.com', $page1['items'][0]['key']);
        self::assertSame('guest3@example.com', $page2['items'][0]['key']);
        self::assertSame('guest5@example.com', $page3['items'][0]['key']);
    }

    public function testTenantScopingExcludesOrdersFromAnotherTenant(): void
    {
        $this->seedOrder('orderaggtA01', userUuid: null, email: 'a@example.com', grandTotal: 100, tenant: 'tenantAAAA01');
        $this->seedOrder('orderaggtB01', userUuid: null, email: 'b@example.com', grandTotal: 100, tenant: 'tenantBBBB02');

        $result = $this->repo()->paginate($this->context, 'tenantAAAA01', [], 'last_order_at', 'desc', 1, 25);

        self::assertSame(1, $result['total']);
        self::assertSame('a@example.com', $result['items'][0]['key']);
    }

    public function testFindByUserReturnsAggregateForExactUuid(): void
    {
        $this->seedOrder('orderaggu001', userUuid: 'useraggu0001', email: 'u@example.com', grandTotal: 1000);
        $this->seedOrder(
            'orderaggu002',
            userUuid: 'useraggu0001',
            email: 'u@example.com',
            grandTotal: 500,
            refundedTotal: 50
        );

        $customer = $this->repo()->findByUser($this->context, '', 'useraggu0001');

        self::assertNotNull($customer);
        self::assertSame('user', $customer['key_type']);
        self::assertSame(2, $customer['orders_count']);
        self::assertSame(1500, $customer['total_spent_minor']);
        self::assertSame(50, $customer['refunded_minor']);
    }

    public function testFindByUserUnknownUuidReturnsNull(): void
    {
        self::assertNull($this->repo()->findByUser($this->context, '', 'nosuchuser01'));
    }

    public function testFindByEmailNormalizesLookupKeyAndReturnsAggregate(): void
    {
        $this->seedOrder('orderagge001', userUuid: null, email: ' Foo@Example.COM ', grandTotal: 400);
        $this->seedOrder('orderagge002', userUuid: null, email: 'foo@example.com', grandTotal: 100);

        $customer = $this->repo()->findByEmail($this->context, '', 'FOO@EXAMPLE.com');

        self::assertNotNull($customer);
        self::assertSame('email', $customer['key_type']);
        self::assertSame(2, $customer['orders_count']);
        self::assertSame(500, $customer['total_spent_minor']);
    }

    public function testFindByEmailNeverMatchesAnAuthenticatedOrderSharingTheSameEmail(): void
    {
        $this->seedOrder('orderagge011', userUuid: 'useragge0011', email: 'shared@example.com', grandTotal: 100);

        self::assertNull($this->repo()->findByEmail($this->context, '', 'shared@example.com'));
    }

    public function testFindByEmailUnknownEmailReturnsNull(): void
    {
        self::assertNull($this->repo()->findByEmail($this->context, '', 'nobody@example.com'));
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function repo(): CustomerAggregationRepository
    {
        return new CustomerAggregationRepository();
    }

    /** @return array<string,array<string,mixed>> keyed by row['key'] */
    private function indexByKey(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            $indexed[$item['key']] = $item;
        }

        return $indexed;
    }

    private function seedOrder(
        string $uuid,
        ?string $userUuid,
        string $email,
        int $grandTotal,
        int $refundedTotal = 0,
        string $tenant = '',
        ?string $createdAt = null,
    ): void {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'email' => $email,
            'user_uuid' => $userUuid,
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => $grandTotal,
            'grand_total' => $grandTotal,
            'refunded_total' => $refundedTotal,
            'created_at' => $createdAt ?? '2026-01-01 00:00:00',
        ]);
    }
}
