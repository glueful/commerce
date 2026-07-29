<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Http\DTOs\WishlistImportData;
use Glueful\Extensions\Commerce\Http\DTOs\WishlistItemData;
use Glueful\Extensions\Commerce\Http\Storefront\AccountWishlistController;
use Glueful\Extensions\Commerce\Support\UuidBatch;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Wishlist\WishlistRepository;
use Glueful\Extensions\Commerce\Wishlist\WishlistService;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;

final class AccountWishlistHttpTest extends CommerceTestCase
{
    private function product(string $uuid): void
    {
        db($this->context)->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'name' => 'Widget',
            'slug' => strtolower($uuid),
            'status' => 'active',
            'created_at' => '2026-07-01 10:00:00',
        ]);
    }

    private function service(): WishlistService
    {
        return new WishlistService(new WishlistRepository(), new ProductRepository(), new SentinelTenantResolver());
    }

    private function controller(): AccountWishlistController
    {
        return new AccountWishlistController($this->context, $this->service());
    }

    private function authenticated(string $userUuid = 'user00000001'): Request
    {
        $request = Request::create('/commerce/account/wishlist', 'GET');
        $request->attributes->set('user', ['uuid' => $userUuid]);

        return $request;
    }

    public function testIndexReturnsTheUsersAvailableProducts(): void
    {
        $this->product('prod00000001');
        $this->service()->add($this->context, 'user00000001', 'prod00000001');

        $response = $this->controller()->index($this->authenticated());
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('prod00000001', $body['data'][0]['uuid']);
    }

    public function testAnUnauthenticatedRequestFailsClosed(): void
    {
        // Routes are auth-gated, but a controller must never treat "no actor" as a valid user.
        $this->expectException(NotFoundException::class);
        $this->controller()->index(Request::create('/commerce/account/wishlist', 'GET'));
    }

    public function testOneUsersListIsNeverVisibleToAnother(): void
    {
        $this->product('prod00000001');
        $this->service()->add($this->context, 'user00000001', 'prod00000001');

        $response = $this->controller()->index($this->authenticated('user00000002'));
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame([], $body['data']);
    }

    public function testImportReportsImportedUnavailableAndOverflowSeparately(): void
    {
        $this->product('prod00000001');

        $response = $this->controller()->import(
            new WishlistImportData(product_uuids: ['prod00000001', 'prodmissing1']),
            $this->authenticated(),
        );
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(['prod00000001'], $body['data']['imported']);
        self::assertSame(['prodmissing1'], $body['data']['unavailable']);
        self::assertSame([], $body['data']['overflow']);
    }

    public function testMalformedIdentifiersAreRejectedByTheDto(): void
    {
        // A 12-character maximum is not the same as an exact 12-character catalog uuid.
        self::assertNotSame([], (new WishlistItemData(product_uuid: 'x'))->validate());
        self::assertNotSame([], (new WishlistItemData(product_uuid: '../../etc/x'))->validate());
        // PCRE '$' matches before a trailing newline; the canonical \A..\z pattern does not.
        self::assertNotSame([], (new WishlistItemData(product_uuid: "prod00000001\n"))->validate());
        self::assertSame([], (new WishlistItemData(product_uuid: 'prod00000001'))->validate());
        self::assertNotSame([], (new WishlistImportData(product_uuids: ['prod00000001', 'nope']))->validate());
        // Over-limit is refused rather than truncated, so nothing vanishes unreported.
        self::assertNotSame(
            [],
            (new WishlistImportData(product_uuids: array_fill(0, UuidBatch::LIMIT + 1, 'prod00000001')))->validate()
        );
    }
}
