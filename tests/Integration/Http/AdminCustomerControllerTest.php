<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\UserIdentity;
use Glueful\Extensions\Commerce\Customers\AddressBookRepository;
use Glueful\Extensions\Commerce\Customers\CustomerAggregationRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminCustomerController;
use Glueful\Extensions\Commerce\Http\DTOs\CustomerListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\CustomerLookupQuery;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * `AdminCustomerController` wiring: explicit `?by=` keying (design spec
 * Resolved Decision 2), non-revealing 404s (unknown key, cross-tenant), and
 * soft `UserProviderInterface` enrichment degrade. Aggregation math itself is
 * covered by {@see \Glueful\Extensions\Commerce\Tests\Integration\Customers\CustomerAggregationRepositoryTest}.
 */
final class AdminCustomerControllerTest extends CommerceTestCase
{
    // -----------------------------------------------------------------
    // ?by= required/invalid — real hydrator, real 422
    // -----------------------------------------------------------------

    public function testMissingByQueryParamThrowsValidationException(): void
    {
        try {
            (new RequestDataHydrator())->hydrate(CustomerLookupQuery::class, [], [], []);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('by', $e->errors());
        }
    }

    public function testInvalidByQueryParamThrowsValidationException(): void
    {
        try {
            (new RequestDataHydrator())->hydrate(CustomerLookupQuery::class, [], [], ['by' => 'uuid']);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('by', $e->errors());
        }
    }

    public function testValidByValuesHydrateSuccessfully(): void
    {
        $user = (new RequestDataHydrator())->hydrate(CustomerLookupQuery::class, [], [], ['by' => 'user']);
        $email = (new RequestDataHydrator())->hydrate(CustomerLookupQuery::class, [], [], ['by' => 'email']);

        self::assertSame('user', $user->by);
        self::assertSame('email', $email->by);
    }

    // -----------------------------------------------------------------
    // show(): by=user / by=email / 404 / cross-tenant
    // -----------------------------------------------------------------

    public function testShowByUserReturnsAggregateAndRecentOrders(): void
    {
        $this->seedOrder('ordercustu01', userUuid: 'usercustu001', email: 'u@example.com', grandTotal: 1000);
        $this->seedOrder('ordercustu02', userUuid: 'usercustu001', email: 'u@example.com', grandTotal: 500);

        $response = $this->controller()->show(
            new CustomerLookupQuery(by: 'user'),
            Request::create('/x', 'GET'),
            'usercustu001'
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user', $body['data']['key_type']);
        self::assertSame(2, $body['data']['orders_count']);
        self::assertSame(1500, $body['data']['total_spent_minor']);
        self::assertCount(2, $body['data']['orders']);
        self::assertSame([], $body['data']['addresses']);
    }

    public function testShowByUserIncludesTheAccountAddressBook(): void
    {
        $this->seedOrder('ordercustu03', userUuid: 'usercustu003', email: 'u3@example.com', grandTotal: 1000);
        $this->seedAddress('addrcustu001', 'usercustu003', ['country' => 'US'], isDefaultShipping: true);

        $response = $this->controller()->show(
            new CustomerLookupQuery(by: 'user'),
            Request::create('/x', 'GET'),
            'usercustu003'
        );
        $body = $this->json($response);

        self::assertCount(1, $body['data']['addresses']);
        self::assertSame('addrcustu001', $body['data']['addresses'][0]['uuid']);
        self::assertSame(['country' => 'US'], $body['data']['addresses'][0]['address']);
        self::assertTrue($body['data']['addresses'][0]['is_default_shipping']);
        self::assertArrayNotHasKey('tenant_uuid', $body['data']['addresses'][0]);
        self::assertArrayNotHasKey('user_uuid', $body['data']['addresses'][0]);
    }

    public function testShowByEmailNeverIncludesAddressesKey(): void
    {
        $this->seedOrder('ordercustu04', userUuid: null, email: 'guest4@example.com', grandTotal: 100);

        $response = $this->controller()->show(
            new CustomerLookupQuery(by: 'email'),
            Request::create('/x', 'GET'),
            'guest4@example.com'
        );
        $body = $this->json($response);

        self::assertArrayNotHasKey('addresses', $body['data']);
    }

    public function testShowByEmailNormalizesKeyAndReturnsAggregateAndRecentOrders(): void
    {
        $this->seedOrder('ordercuste01', userUuid: null, email: ' Guest@Example.COM ', grandTotal: 300);
        $this->seedOrder('ordercuste02', userUuid: null, email: 'guest@example.com', grandTotal: 200);

        $response = $this->controller()->show(
            new CustomerLookupQuery(by: 'email'),
            Request::create('/x', 'GET'),
            'GUEST@example.com'
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('email', $body['data']['key_type']);
        self::assertSame(2, $body['data']['orders_count']);
        self::assertSame(500, $body['data']['total_spent_minor']);
        self::assertCount(2, $body['data']['orders']);
    }

    public function testShowUnknownUserReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->show(new CustomerLookupQuery(by: 'user'), Request::create('/x', 'GET'), 'nosuchuser01');
    }

    public function testShowUnknownEmailReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->show(
            new CustomerLookupQuery(by: 'email'),
            Request::create('/x', 'GET'),
            'nobody@example.com'
        );
    }

    public function testShowCrossTenantUserReturns404NonRevealing(): void
    {
        $this->seedOrder('ordercustx01', userUuid: 'usercustx001', email: 'x@example.com', grandTotal: 100, tenant: 'tenantAAAA01');

        $this->expectException(NotFoundException::class);
        $this->controller('tenantBBBB02')->show(
            new CustomerLookupQuery(by: 'user'),
            Request::create('/x', 'GET'),
            'usercustx001'
        );
    }

    // -----------------------------------------------------------------
    // index(): shape + pagination envelope
    // -----------------------------------------------------------------

    public function testIndexReturnsPaginatedEnvelope(): void
    {
        $this->seedOrder('ordercusti01', userUuid: null, email: 'a@example.com', grandTotal: 100);
        $this->seedOrder('ordercusti02', userUuid: null, email: 'b@example.com', grandTotal: 100);

        $response = $this->controller()->index(new CustomerListQuery(), Request::create('/x', 'GET'));
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['data']);
        self::assertSame(2, $body['total']);
    }

    // -----------------------------------------------------------------
    // Enrichment soft-degrade
    // -----------------------------------------------------------------

    public function testEnrichmentAddsUsernameWhenProviderResolvesTheUser(): void
    {
        $this->seedOrder('ordercuste11', userUuid: 'usercuste0011', email: 'e@example.com', grandTotal: 100);

        $provider = $this->fakeProvider(['usercuste0011' => new UserIdentity(uuid: 'usercuste0011', username: 'jdoe')]);

        $response = $this->controller(users: $provider)->show(
            new CustomerLookupQuery(by: 'user'),
            Request::create('/x', 'GET'),
            'usercuste0011'
        );
        $body = $this->json($response);

        self::assertSame('jdoe', $body['data']['username']);
    }

    public function testEnrichmentOmitsUsernameKeyWhenNoProviderBound(): void
    {
        $this->seedOrder('ordercuste21', userUuid: 'usercuste0021', email: 'e2@example.com', grandTotal: 100);

        $response = $this->controller()->show(
            new CustomerLookupQuery(by: 'user'),
            Request::create('/x', 'GET'),
            'usercuste0021'
        );
        $body = $this->json($response);

        self::assertArrayNotHasKey('username', $body['data']);
    }

    public function testEnrichmentOmitsUsernameKeyWhenProviderCannotResolveTheUuid(): void
    {
        $this->seedOrder('ordercuste31', userUuid: 'usercuste0031', email: 'e3@example.com', grandTotal: 100);

        $provider = $this->fakeProvider([]);

        $response = $this->controller(users: $provider)->show(
            new CustomerLookupQuery(by: 'user'),
            Request::create('/x', 'GET'),
            'usercuste0031'
        );
        $body = $this->json($response);

        self::assertArrayNotHasKey('username', $body['data']);
    }

    public function testEnrichmentNeverAppliesToEmailKeyedCustomers(): void
    {
        $this->seedOrder('ordercuste41', userUuid: null, email: 'guest41@example.com', grandTotal: 100);

        $provider = $this->fakeProvider([]);

        $response = $this->controller(users: $provider)->show(
            new CustomerLookupQuery(by: 'email'),
            Request::create('/x', 'GET'),
            'guest41@example.com'
        );
        $body = $this->json($response);

        self::assertArrayNotHasKey('username', $body['data']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** @param array<string,UserIdentity> $byUuid */
    private function fakeProvider(array $byUuid): UserProviderInterface
    {
        return new class ($byUuid) implements UserProviderInterface {
            /** @param array<string,UserIdentity> $byUuid */
            public function __construct(private array $byUuid)
            {
            }

            public function findByUuid(string $uuid): ?UserIdentity
            {
                return $this->byUuid[$uuid] ?? null;
            }

            public function findByLogin(string $identifier): ?UserIdentity
            {
                return null;
            }

            public function verifyCredentials(string $identifier, string $password): ?UserIdentity
            {
                return null;
            }
        };
    }

    private function controller(string $tenant = '', ?UserProviderInterface $users = null): AdminCustomerController
    {
        return new AdminCustomerController(
            $this->context,
            new CustomerAggregationRepository(),
            new OrderRepository(),
            $this->tenantResolver($tenant),
            $users,
            new AddressBookRepository()
        );
    }

    private function tenantResolver(string $tenant): CurrentTenantResolver
    {
        return new class ($tenant) implements CurrentTenantResolver {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
    }

    private function seedOrder(
        string $uuid,
        ?string $userUuid,
        string $email,
        int $grandTotal,
        int $refundedTotal = 0,
        string $tenant = '',
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
        ]);
    }

    /** @param array<string,mixed> $address */
    private function seedAddress(
        string $uuid,
        string $userUuid,
        array $address,
        bool $isDefaultShipping = false,
        bool $isDefaultBilling = false,
        string $tenant = '',
    ): void {
        $this->connection->table('commerce_customer_addresses')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'user_uuid' => $userUuid,
            'label' => null,
            'address' => json_encode($address, JSON_THROW_ON_ERROR),
            'is_default_shipping' => $isDefaultShipping,
            'is_default_billing' => $isDefaultBilling,
        ]);
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
