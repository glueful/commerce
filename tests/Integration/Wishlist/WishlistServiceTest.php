<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Wishlist;

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Support\UuidBatch;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Wishlist\WishlistRepository;
use Glueful\Extensions\Commerce\Wishlist\WishlistService;
use Glueful\Validation\ValidationException;

final class WishlistServiceTest extends CommerceTestCase
{
    private function product(string $uuid): string
    {
        db($this->context)->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'name' => 'Widget',
            'slug' => strtolower($uuid),
            'status' => 'active',
            'created_at' => '2026-07-01 10:00:00',
        ]);

        return $uuid;
    }

    private function service(): WishlistService
    {
        // CommerceTestCase exposes no container property; services are constructed directly,
        // matching AddressBookConcurrencyTest. SentinelTenantResolver yields the '' tenant.
        return new WishlistService(
            new WishlistRepository(),
            new ProductRepository(),
            new SentinelTenantResolver(),
        );
    }

    public function testSavesAppearNewestFirst(): void
    {
        $this->product('prod00000001');
        $this->product('prod00000002');
        $service = $this->service();

        $service->add($this->context, 'user00000001', 'prod00000001');
        $service->add($this->context, 'user00000001', 'prod00000002');

        self::assertSame(
            ['prod00000002', 'prod00000001'],
            array_column($service->list($this->context, 'user00000001'), 'uuid')
        );
    }

    public function testAnUnavailableProductCannotConsumeACapSlot(): void
    {
        // Without this check an arbitrary uuid fills the list invisibly: it never renders
        // (the read filters on availability) but it still counts against the cap.
        $service = $this->service();

        $this->expectException(ValidationException::class);
        $service->add($this->context, 'user00000001', 'prodmissing1');
    }

    public function testAProductThatGoesInactiveLeavesTheListWithoutBeingDeleted(): void
    {
        $this->product('prod00000001');
        $service = $this->service();
        $service->add($this->context, 'user00000001', 'prod00000001');

        db($this->context)->table('commerce_products')->executeModification(
            "UPDATE commerce_products SET status = 'draft' WHERE uuid = ?",
            ['prod00000001']
        );

        self::assertSame([], $service->list($this->context, 'user00000001'));
        // The saved row survives, so reactivating the product brings the item back.
        self::assertSame(1, (new WishlistRepository())->countForUser($this->context, '', 'user00000001'));
    }

    public function testTheCapIsRefusedExplicitlyRatherThanEvictingSilently(): void
    {
        $service = $this->service();
        for ($i = 1; $i <= WishlistService::MAX_ITEMS; $i++) {
            $uuid = sprintf('prod%08d', $i);
            $this->product($uuid);
            $service->add($this->context, 'user00000001', $uuid);
        }
        $this->product('prod00000999');

        $this->expectException(ValidationException::class);
        try {
            $service->add($this->context, 'user00000001', 'prod00000999');
        } finally {
            self::assertSame(
                WishlistService::MAX_ITEMS,
                (new WishlistRepository())->countForUser($this->context, '', 'user00000001')
            );
        }
    }

    public function testReSavingAnAlreadySavedProductAtTheCapIsANoOpNotAnError(): void
    {
        $service = $this->service();
        for ($i = 1; $i <= WishlistService::MAX_ITEMS; $i++) {
            $uuid = sprintf('prod%08d', $i);
            $this->product($uuid);
            $service->add($this->context, 'user00000001', $uuid);
        }

        // The cap governs growth; a duplicate save adds nothing, so it is not refused.
        self::assertFalse($service->add($this->context, 'user00000001', 'prod00000001'));
    }

    public function testImportKeepsAccountOrderThenAppendsDeviceOrder(): void
    {
        foreach (['prodaccount1', 'prodaccount2', 'proddevice01', 'proddevice02'] as $uuid) {
            $this->product($uuid);
        }
        $service = $this->service();
        $service->add($this->context, 'user00000001', 'prodaccount1');
        $service->add($this->context, 'user00000001', 'prodaccount2');

        $result = $service->import($this->context, 'user00000001', [
            'proddevice01',
            'prodaccount1',
            'proddevice02',
        ]);

        self::assertSame(['proddevice01', 'proddevice02'], $result->imported);
        self::assertSame([], $result->unavailable);
        self::assertSame([], $result->overflow);

        // Account items keep their own order (newest save first); device-only items follow in
        // the order the device supplied. A device list carries UUIDs and no timestamps, so no
        // time-based interleave is claimed or reconstructed.
        self::assertSame(
            ['prodaccount2', 'prodaccount1', 'proddevice01', 'proddevice02'],
            array_column($service->list($this->context, 'user00000001'), 'uuid')
        );
    }

    public function testImportDropsUnavailableProductsAndReportsThem(): void
    {
        $this->product('proddevice01');
        $service = $this->service();

        $result = $service->import($this->context, 'user00000001', ['proddevice01', 'prodmissing1']);

        self::assertSame(['proddevice01'], $result->imported);
        self::assertSame(['prodmissing1'], $result->unavailable);
    }

    public function testImportFillsRemainingHeadroomAndReportsTheOverflow(): void
    {
        $service = $this->service();
        for ($i = 1; $i <= WishlistService::MAX_ITEMS - 1; $i++) {
            $uuid = sprintf('prod%08d', $i);
            $this->product($uuid);
            $service->add($this->context, 'user00000001', $uuid);
        }
        $this->product('proddevice01');
        $this->product('proddevice02');

        $result = $service->import($this->context, 'user00000001', ['proddevice01', 'proddevice02']);

        self::assertSame(['proddevice01'], $result->imported);
        // Preserved for the caller to keep locally rather than silently dropped.
        self::assertSame(['proddevice02'], $result->overflow);
        self::assertSame(
            WishlistService::MAX_ITEMS,
            (new WishlistRepository())->countForUser($this->context, '', 'user00000001')
        );
    }

    public function testOnlyValidUniqueExcessIdentifiersBecomeOverflow(): void
    {
        // A direct service caller (another pack, not the HTTP boundary) can hand over more than
        // the batch limit. Everything past it must be reported, but overflow means "valid, did
        // not fit" -- telling a caller to keep a malformed string it can never import would be a
        // lie it acts on by preserving garbage locally forever.
        $service = $this->service();
        $limit = UuidBatch::LIMIT;

        $input = [];
        for ($i = 1; $i <= $limit; $i++) {
            $uuid = sprintf('prod%08d', $i);
            $this->product($uuid);
            $input[] = $uuid;
        }
        // Excess, in order: two valid new products, a duplicate of one of them, a duplicate of
        // an in-limit uuid, and two malformed strings.
        $this->product('prodexcess01');
        $this->product('prodexcess02');
        $input[] = 'prodexcess01';
        $input[] = 'prodexcess02';
        $input[] = 'prodexcess01';
        $input[] = 'prod00000001';
        $input[] = 'nope';
        $input[] = "prodexcess03\n";

        $result = $service->import($this->context, 'user00000001', $input);

        // The first `limit` valid uuids import (the account starts empty, and limit == the cap).
        self::assertCount($limit, $result->imported);
        // Only the valid, unique, not-already-counted excess is overflow: no duplicates, no
        // malformed strings, and not the uuid that was already inside the batch window.
        self::assertSame(['prodexcess01', 'prodexcess02'], $result->overflow);
    }

    public function testImportIsIdempotent(): void
    {
        $this->product('proddevice01');
        $service = $this->service();

        self::assertSame(
            ['proddevice01'],
            $service->import($this->context, 'user00000001', ['proddevice01'])->imported
        );
        self::assertSame([], $service->import($this->context, 'user00000001', ['proddevice01'])->imported);
        self::assertSame(1, (new WishlistRepository())->countForUser($this->context, '', 'user00000001'));
    }
}
