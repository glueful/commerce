<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Extensions\Commerce\Customers\AddressBookRepository;
use Glueful\Extensions\Commerce\Customers\AddressBookService;
use Glueful\Extensions\Commerce\Http\DTOs\CreateAddressData;
use Glueful\Extensions\Commerce\Http\Storefront\AccountAddressController;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Storefront address book (design spec §2/§7): CRUD, actor extraction (the
 * same `request->attributes->get('user')` pattern as
 * {@see \Glueful\Extensions\Commerce\Http\Storefront\OrderController::mine()}),
 * non-revealing 404s for unknown/cross-user addresses, default-shipping/
 * billing swap determinism, and the parent-claim discipline that serializes
 * two concurrent first-address creations onto one
 * `commerce_customer_address_books` row (design spec §2, revision-3
 * hardening).
 */
final class AccountAddressTest extends CommerceTestCase
{
    // -----------------------------------------------------------------
    // Auth-required / non-revealing actor extraction
    // -----------------------------------------------------------------

    public function testIndexWithoutAuthenticationThrowsNonRevealing404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->index(Request::create('/x', 'GET'));
    }

    public function testStoreWithoutAuthenticationThrowsNonRevealing404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->store(
            new CreateAddressData(address: ['country' => 'US']),
            Request::create('/x', 'POST')
        );
    }

    // -----------------------------------------------------------------
    // CRUD happy path
    // -----------------------------------------------------------------

    public function testIndexReturnsEmptyListForAFreshAccount(): void
    {
        $response = $this->controller()->index($this->authed('useraddr001'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->json($response)['data']);
    }

    public function testStoreCreatesAndPersistsAnAddress(): void
    {
        $response = $this->controller()->store(
            new CreateAddressData(label: 'Home', address: ['country' => 'US', 'line1' => '1 Main St']),
            $this->authed('useraddr002')
        );
        $body = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Home', $body['data']['label']);
        self::assertSame(['country' => 'US', 'line1' => '1 Main St'], $body['data']['address']);
        self::assertFalse($body['data']['is_default_shipping']);
        self::assertFalse($body['data']['is_default_billing']);

        $listed = $this->json($this->controller()->index($this->authed('useraddr002')));
        self::assertCount(1, $listed['data']);
    }

    public function testStoreWithEmptyAddressObjectReturns422(): void
    {
        $response = $this->controller()->store(
            new CreateAddressData(address: []),
            $this->authed('useraddr003')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('address', $this->json($response)['error']['details']);
    }

    public function testStoreWithAListInsteadOfAnObjectReturns422(): void
    {
        $response = $this->controller()->store(
            new CreateAddressData(address: ['US', 'CA']),
            $this->authed('useraddr004')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('address', $this->json($response)['error']['details']);
    }

    public function testUpdateAppliesOnlySuppliedFields(): void
    {
        $created = $this->json($this->controller()->store(
            new CreateAddressData(label: 'Home', address: ['country' => 'US']),
            $this->authed('useraddr005')
        ))['data'];

        $request = $this->authedWithBody('useraddr005', 'PATCH', ['label' => 'Office']);
        $response = $this->controller()->update($request, (string) $created['uuid']);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Office', $body['data']['label']);
        self::assertSame(['country' => 'US'], $body['data']['address'], 'Untouched field must survive.');
    }

    public function testUpdateUnknownAddressThrows404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->authed('useraddr006', 'PATCH'), 'no-such-address');
    }

    public function testUpdateCrossUserAddressThrowsNonRevealing404(): void
    {
        $created = $this->json($this->controller()->store(
            new CreateAddressData(address: ['country' => 'US']),
            $this->authed('useraddr007a')
        ))['data'];

        try {
            $this->controller()->update($this->authed('useraddr007b', 'PATCH'), (string) $created['uuid']);
            self::fail('expected NotFoundException');
        } catch (NotFoundException $crossUser) {
            try {
                $this->controller()->update($this->authed('useraddr007b', 'PATCH'), 'totally-unknown');
                self::fail('expected NotFoundException');
            } catch (NotFoundException $unknown) {
                self::assertSame(
                    $unknown->getMessage(),
                    $crossUser->getMessage(),
                    'Cross-user and unknown must be indistinguishable.'
                );
            }
        }
    }

    public function testDestroyRemovesTheAddress(): void
    {
        $created = $this->json($this->controller()->store(
            new CreateAddressData(address: ['country' => 'US']),
            $this->authed('useraddr008')
        ))['data'];

        $response = $this->controller()->destroy($this->authed('useraddr008'), (string) $created['uuid']);
        self::assertSame(204, $response->getStatusCode());

        $listed = $this->json($this->controller()->index($this->authed('useraddr008')));
        self::assertSame([], $listed['data']);
    }

    public function testDestroyCrossUserAddressThrows404(): void
    {
        $created = $this->json($this->controller()->store(
            new CreateAddressData(address: ['country' => 'US']),
            $this->authed('useraddr009a')
        ))['data'];

        $this->expectException(NotFoundException::class);
        $this->controller()->destroy($this->authed('useraddr009b'), (string) $created['uuid']);
    }

    /**
     * Deliberate pin (design spec §2 is silent, so the actual behavior is the
     * contract): deleting the CURRENT default never auto-promotes a survivor.
     * `AddressBookService::delete()` is a plain claim-then-delete -- it does not
     * inspect the deleted row's default flags at all -- so an account can be left
     * with zero default addresses until the customer explicitly picks a new one.
     */
    public function testDestroyingTheCurrentDefaultLeavesZeroDefaultsNoAutoPromotion(): void
    {
        $userUuid = 'useraddr014';
        $default = $this->json($this->controller()->store(
            new CreateAddressData(
                address: ['country' => 'US'],
                is_default_shipping: true,
                is_default_billing: true
            ),
            $this->authed($userUuid)
        ))['data'];
        $survivor = $this->json($this->controller()->store(
            new CreateAddressData(address: ['country' => 'CA']),
            $this->authed($userUuid)
        ))['data'];

        $response = $this->controller()->destroy($this->authed($userUuid), (string) $default['uuid']);
        self::assertSame(204, $response->getStatusCode());

        $listed = $this->json($this->controller()->index($this->authed($userUuid)))['data'];
        self::assertCount(1, $listed);
        self::assertSame($survivor['uuid'], $listed[0]['uuid']);
        self::assertFalse(
            $listed[0]['is_default_shipping'],
            'The survivor must NOT be auto-promoted to default shipping.'
        );
        self::assertFalse(
            $listed[0]['is_default_billing'],
            'The survivor must NOT be auto-promoted to default billing.'
        );

        $anyDefault = array_filter(
            $listed,
            static fn (array $row): bool => $row['is_default_shipping'] || $row['is_default_billing']
        );
        self::assertSame([], $anyDefault, 'Zero addresses may be flagged default after the default was deleted.');
    }

    // -----------------------------------------------------------------
    // Default shipping/billing swap
    // -----------------------------------------------------------------

    public function testSettingShippingDefaultOnBClearsAOfShippingOnlyNotBilling(): void
    {
        $userUuid = 'useraddr010';
        $a = $this->json($this->controller()->store(
            new CreateAddressData(
                address: ['country' => 'US'],
                is_default_shipping: true,
                is_default_billing: true
            ),
            $this->authed($userUuid)
        ))['data'];
        $b = $this->json($this->controller()->store(
            new CreateAddressData(address: ['country' => 'CA']),
            $this->authed($userUuid)
        ))['data'];

        $request = $this->authedWithBody($userUuid, 'PATCH', ['is_default_shipping' => true]);
        $this->controller()->update($request, (string) $b['uuid']);

        $listed = $this->json($this->controller()->index($this->authed($userUuid)))['data'];
        $byUuid = [];
        foreach ($listed as $row) {
            $byUuid[$row['uuid']] = $row;
        }

        self::assertFalse($byUuid[$a['uuid']]['is_default_shipping'], 'A must lose the shipping default.');
        self::assertTrue($byUuid[$a['uuid']]['is_default_billing'], 'A must KEEP its unrelated billing default.');
        self::assertTrue($byUuid[$b['uuid']]['is_default_shipping'], 'B must become the new shipping default.');
        self::assertFalse($byUuid[$b['uuid']]['is_default_billing']);
    }

    // -----------------------------------------------------------------
    // Parent-claim discipline (design spec §2, revision-3 hardening)
    // -----------------------------------------------------------------

    public function testEnsureBookIsIdempotentAndReloadsOnADuplicateKeyInsert(): void
    {
        $repository = new AddressBookRepository();
        $repository->ensureBook($this->context, '', 'useraddr011');
        $first = $repository->findBook($this->context, '', 'useraddr011');
        self::assertNotNull($first);

        // A second "ensure" against the same (tenant, user) simulates the losing
        // side of a concurrent first-address request: its insert attempt hits the
        // unique (tenant_uuid, user_uuid) index and must be swallowed, never
        // thrown, never leaving a second row behind.
        $repository->ensureBook($this->context, '', 'useraddr011');
        $second = $repository->findBook($this->context, '', 'useraddr011');

        self::assertSame($first['uuid'], $second['uuid']);
        self::assertSame(
            1,
            $this->connection->table('commerce_customer_address_books')
                ->where('tenant_uuid', '=', '')
                ->where('user_uuid', '=', 'useraddr011')
                ->count()
        );
    }

    public function testClaimBookIncrementsRevisionAndReturnsTrueRepeatedly(): void
    {
        $repository = new AddressBookRepository();
        $repository->ensureBook($this->context, '', 'useraddr012');

        self::assertTrue($repository->claimBook($this->context, '', 'useraddr012'));
        self::assertSame(1, $this->currentRevision('useraddr012'));

        self::assertTrue($repository->claimBook($this->context, '', 'useraddr012'));
        self::assertSame(2, $this->currentRevision('useraddr012'));
    }

    public function testClaimBookReturnsFalseForAnUnknownBook(): void
    {
        $repository = new AddressBookRepository();
        self::assertFalse($repository->claimBook($this->context, '', 'no-such-book'));
    }

    /**
     * Deterministic simulation of "two concurrent first-address creations"
     * (design spec §2): PHP has no threads, so this proves the invariant
     * sequentially, matching the house
     * `AttributeConcurrencyTest::testClaimRevisionIncrementsAndReturnsTrueRepeatedly()`-style
     * split between a deterministic claim test and a genuine pgsql-race test.
     * Both creates for the SAME brand-new account must land -- the second
     * one's `ensureBook()` hits the unique-key path and reloads rather than
     * throwing into an open transaction -- and their claims must serialize
     * onto the ONE parent row: exactly one book row, revision == 2, two
     * distinct address rows.
     */
    public function testConcurrentFirstAddressCreationsSerializeOnTheSharedParentRow(): void
    {
        $userUuid = 'useraddr013';

        $first = $this->service()->create($this->context, $userUuid, ['address' => ['country' => 'US']]);
        $second = $this->service()->create($this->context, $userUuid, ['address' => ['country' => 'CA']]);

        self::assertNotSame($first['uuid'], $second['uuid']);
        self::assertSame(
            1,
            $this->connection->table('commerce_customer_address_books')
                ->where('tenant_uuid', '=', '')
                ->where('user_uuid', '=', $userUuid)
                ->count()
        );
        self::assertSame(
            2,
            $this->connection->table('commerce_customer_addresses')
                ->where('tenant_uuid', '=', '')
                ->where('user_uuid', '=', $userUuid)
                ->count()
        );
        self::assertSame(2, $this->currentRevision($userUuid));
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function controller(): AccountAddressController
    {
        return new AccountAddressController($this->context, $this->service());
    }

    private function service(): AddressBookService
    {
        return new AddressBookService(new AddressBookRepository(), new SentinelTenantResolver());
    }

    private function authed(string $userUuid, string $method = 'GET'): Request
    {
        $request = Request::create('/x', $method);
        $request->attributes->set('user', ['uuid' => $userUuid]);

        return $request;
    }

    /** @param array<string,mixed> $body */
    private function authedWithBody(string $userUuid, string $method, array $body): Request
    {
        $request = Request::create(
            '/x',
            $method,
            [],
            [],
            [],
            [],
            (string) json_encode($body, JSON_THROW_ON_ERROR)
        );
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('user', ['uuid' => $userUuid]);

        return $request;
    }

    private function currentRevision(string $userUuid): int
    {
        $row = $this->connection->table('commerce_customer_address_books')
            ->where('tenant_uuid', '=', '')
            ->where('user_uuid', '=', $userUuid)
            ->first();

        return $row === null ? -1 : (int) $row['revision'];
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
