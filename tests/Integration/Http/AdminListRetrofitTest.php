<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\TagService;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminAttributeController;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingClassController;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingZoneController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTagController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTaxRateController;
use Glueful\Extensions\Commerce\Http\DTOs\AdminProductListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\AttributeListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\ShippingClassListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\ShippingZoneListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\TagListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\TaxRateListQuery;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingClassService;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneService;
use Glueful\Extensions\Commerce\Tax\TaxRateRepository;
use Glueful\Extensions\Commerce\Tax\TaxRateService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Layer 6 Task 4 retrofit sweep: pagination + shared literal-`q` filters on the
 * admin products/tags/attributes/shipping-zones/shipping-classes/tax-rates
 * lists, plus tag rename (design spec Layer 6 §2 decisions 1/2/4). Every list
 * asserts the house paginated envelope, the clamp, a stable uuid tie-break
 * after the primary sort, and (where filters exist) that `total` and the
 * returned row count agree under duplicate-y fixtures. Discounts' equivalent
 * coverage lives in {@see DiscountLifecycleTest} (Task 3); refunds' in
 * {@see RefundsListTest}.
 */
final class AdminListRetrofitTest extends CommerceTestCase
{
    // === Admin products =====================================================

    public function testProductIndexIsPaginatedAndExcludesOtherTenants(): void
    {
        $this->seedProduct(['uuid' => 'prodlist0001', 'slug' => 'a1', 'name' => 'A1']);
        $this->seedProduct(['uuid' => 'prodlist0002', 'slug' => 'a2', 'name' => 'A2']);
        $this->seedProduct(['uuid' => 'prodlist0003', 'slug' => 'a3', 'name' => 'A3'], 'tenant-b');

        $response = $this->productController()->index(new AdminProductListQuery(), Request::create('/x'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(2, $body['total']);
        self::assertCount(2, $body['data']);
        self::assertSame(1, $body['current_page']);
        self::assertSame(24, $body['per_page']);
    }

    public function testProductIndexExcludesSoftDeletedProducts(): void
    {
        $this->seedProduct(['uuid' => 'prodlist0011', 'slug' => 'live1', 'name' => 'Live']);
        $this->seedProduct(['uuid' => 'prodlist0012', 'slug' => 'dead1', 'name' => 'Dead', 'deleted_at' => '2026-01-01 00:00:00']);

        $response = $this->productController()->index(new AdminProductListQuery(), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('prodlist0011', $body['data'][0]['uuid']);
    }

    public function testProductIndexFiltersByStatus(): void
    {
        $this->seedProduct(['uuid' => 'prodlist0021', 'slug' => 's1', 'name' => 'Active One', 'status' => 'active']);
        $this->seedProduct(['uuid' => 'prodlist0022', 'slug' => 's2', 'name' => 'Draft One', 'status' => 'draft']);

        $response = $this->productController()->index(
            new AdminProductListQuery(status: 'active'),
            Request::create('/x')
        );

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('prodlist0021', $body['data'][0]['uuid']);
    }

    /**
     * `ValidatesSelf` runs at the `RequestDataHydrator` boundary (the house
     * pattern already used by BulkEndpointsTest's bad-vocabulary tests), never
     * when a test constructs the DTO directly. This proves `status` is
     * rejected against the shared product status vocabulary at that boundary.
     */
    public function testProductIndexUnknownStatusRejectsAtTheHydrationBoundary(): void
    {
        try {
            (new RequestDataHydrator())->hydrate(
                AdminProductListQuery::class,
                [],
                [],
                ['status' => 'not-a-status']
            );
            self::fail('expected ValidationException for an unknown status');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors());
        }
    }

    public function testProductIndexFiltersByType(): void
    {
        $this->seedProduct(['uuid' => 'prodlist0031', 'slug' => 't1', 'name' => 'Physical One', 'type' => 'physical']);
        $this->seedProduct(['uuid' => 'prodlist0032', 'slug' => 't2', 'name' => 'Digital One', 'type' => 'digital']);

        $response = $this->productController()->index(
            new AdminProductListQuery(type: 'digital'),
            Request::create('/x')
        );

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('prodlist0032', $body['data'][0]['uuid']);
    }

    public function testProductIndexUnknownTypeRejectsAtTheHydrationBoundary(): void
    {
        try {
            (new RequestDataHydrator())->hydrate(
                AdminProductListQuery::class,
                [],
                [],
                ['type' => 'not-a-type']
            );
            self::fail('expected ValidationException for an unknown type');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('type', $e->errors());
        }
    }

    public function testProductIndexQFilterIsCaseInsensitiveSubstringOnName(): void
    {
        $this->seedProduct(['uuid' => 'prodlist0041', 'slug' => 'q1', 'name' => 'Blue Widget']);
        $this->seedProduct(['uuid' => 'prodlist0042', 'slug' => 'q2', 'name' => 'Red Widget']);
        $this->seedProduct(['uuid' => 'prodlist0043', 'slug' => 'q3', 'name' => 'Gadget']);

        $response = $this->productController()->index(new AdminProductListQuery(q: 'WIDGET'), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(2, $body['total']);
        self::assertCount(2, $body['data']);
    }

    public function testProductIndexQFilterTreatsPercentAndUnderscoreAsLiterals(): void
    {
        $this->seedProduct(['uuid' => 'prodlist0051', 'slug' => 'lit1', 'name' => '50% Off']);
        $this->seedProduct(['uuid' => 'prodlist0052', 'slug' => 'lit2', 'name' => '5000 Off']);
        $this->seedProduct(['uuid' => 'prodlist0053', 'slug' => 'lit3', 'name' => 'A_B Bundle']);
        $this->seedProduct(['uuid' => 'prodlist0054', 'slug' => 'lit4', 'name' => 'AXB Bundle']);

        $percent = $this->json($this->productController()->index(
            new AdminProductListQuery(q: '50%'),
            Request::create('/x')
        ));
        self::assertSame(1, $percent['total']);
        self::assertSame('prodlist0051', $percent['data'][0]['uuid']);

        $underscore = $this->json($this->productController()->index(
            new AdminProductListQuery(q: 'a_b'),
            Request::create('/x')
        ));
        self::assertSame(1, $underscore['total']);
        self::assertSame('prodlist0053', $underscore['data'][0]['uuid']);
    }

    public function testProductIndexCombinedFiltersApplyToCountAndRows(): void
    {
        $this->seedProduct([
            'uuid' => 'prodlist0061', 'slug' => 'c1', 'name' => 'Summer Sale',
            'status' => 'active', 'type' => 'physical',
        ]);
        $this->seedProduct([
            'uuid' => 'prodlist0062', 'slug' => 'c2', 'name' => 'Summer End',
            'status' => 'draft', 'type' => 'physical',
        ]);

        $response = $this->productController()->index(
            new AdminProductListQuery(status: 'active', q: 'summer'),
            Request::create('/x')
        );

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('prodlist0061', $body['data'][0]['uuid']);
    }

    public function testProductIndexOrdersByCreatedAtDescWithStableUuidTieBreak(): void
    {
        $tiedAt = '2026-01-01 00:00:00';
        $this->seedProduct(['uuid' => 'prodtie00002', 'slug' => 'tie2', 'name' => 'Tie', 'created_at' => $tiedAt]);
        $this->seedProduct(['uuid' => 'prodtie00001', 'slug' => 'tie1', 'name' => 'Tie', 'created_at' => $tiedAt]);

        $response = $this->productController()->index(new AdminProductListQuery(), Request::create('/x'));
        $uuids = array_column($this->json($response)['data'], 'uuid');

        self::assertSame(['prodtie00001', 'prodtie00002'], $uuids);
    }

    public function testProductIndexPaginatesWithClamp(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->seedProduct(['uuid' => 'prodpage000' . $i, 'slug' => 'page' . $i, 'name' => 'Page ' . $i]);
        }

        $response = $this->productController()->index(
            new AdminProductListQuery(page: 1, per_page: 2),
            Request::create('/x')
        );

        $body = $this->json($response);
        self::assertSame(3, $body['total']);
        self::assertCount(2, $body['data']);
        self::assertSame(2, $body['per_page']);
    }

    // === Tags ================================================================

    public function testTagIndexOnlyListsOwnTenantWithPaginationEnvelope(): void
    {
        $this->seedTag('taglist00001', 'summer', 'Summer');
        $this->seedTag('taglist00002', 'sale', 'Sale');
        $this->seedTag('taglist00003', 'other', 'Other', 'tenant-b');

        $response = $this->tagController()->index(new TagListQuery(), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(2, $body['total']);
        self::assertCount(2, $body['data']);
        self::assertSame(24, $body['per_page']);
    }

    public function testTagIndexQFilterMatchesNameOrSlugCaseInsensitive(): void
    {
        $this->seedTag('taglist00011', 'blue-tag', 'Something Else');
        $this->seedTag('taglist00012', 'unrelated', 'Also Blue');
        $this->seedTag('taglist00013', 'green-tag', 'Green');

        $response = $this->tagController()->index(new TagListQuery(q: 'BLUE'), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(2, $body['total']);
    }

    public function testTagIndexQFilterTreatsPercentAsLiteral(): void
    {
        $this->seedTag('taglist00021', 'p1', '50% Off');
        $this->seedTag('taglist00022', 'p2', '5000 Off');

        $response = $this->tagController()->index(new TagListQuery(q: '50%'), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('taglist00021', $body['data'][0]['uuid']);
    }

    public function testTagIndexOrdersByNameAscWithStableUuidTieBreak(): void
    {
        $this->seedTag('tagtie000002', 'tie-b', 'Tie');
        $this->seedTag('tagtie000001', 'tie-a', 'Tie');

        $response = $this->tagController()->index(new TagListQuery(), Request::create('/x'));
        $uuids = array_column($this->json($response)['data'], 'uuid');

        self::assertSame(['tagtie000001', 'tagtie000002'], $uuids);
    }

    public function testTagIndexPaginatesWithClamp(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->seedTag('tagpage0000' . $i, 'page' . $i, 'Page ' . $i);
        }

        $response = $this->tagController()->index(new TagListQuery(page: 1, per_page: 2), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(3, $body['total']);
        self::assertCount(2, $body['data']);
    }

    public function testTagRenameHappyPathClaimsRevisionAndAppliesName(): void
    {
        $uuid = $this->seedTag('tagrename001', 'summer', 'Summer');

        $response = $this->tagController()->update($this->patchRequest(['name' => 'Summer Sale']), $uuid);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('Summer Sale', $data['name']);
        self::assertSame('summer', $data['slug']);
        self::assertSame(1, (int) $data['revision']);
    }

    public function testTagRenameWithSlugKeyReturns422EvenWhenUnchanged(): void
    {
        $uuid = $this->seedTag('tagrename002', 'summer', 'Summer');

        $response = $this->tagController()->update($this->patchRequest(['slug' => 'summer']), $uuid);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('slug', $this->json($response)['error']['details']);
        self::assertSame(0, (int) (new TagRepository())->findByUuid($this->context, '', $uuid)['revision']);
    }

    public function testTagRenameUnknownTagThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->tagController()->update($this->patchRequest(['name' => 'New Name']), 'no-such-tag');
    }

    public function testTagRenameCrossTenantTagThrowsNotFound(): void
    {
        $uuid = $this->seedTag('tagrename003', 'summer', 'Summer', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->tagController()->update($this->patchRequest(['name' => 'New Name']), $uuid);
    }

    // === Attributes ===========================================================

    public function testAttributeIndexPaginatedWithEmbeddedValuesBatchLoaded(): void
    {
        $color = $this->seedAttribute('attrlist0001', 'color', 'Color', 0);
        $this->seedAttributeValue($color, 'attrval00001', 'red', 'Red', 0);
        $this->seedAttributeValue($color, 'attrval00002', 'blue', 'Blue', 1);
        $this->seedAttribute('attrlist0002', 'size', 'Size', 1);

        $response = $this->attributeController()->index(new AttributeListQuery(), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(2, $body['total']);
        $byUuid = [];
        foreach ($body['data'] as $row) {
            $byUuid[$row['uuid']] = $row;
        }
        self::assertSame(['red', 'blue'], array_column($byUuid['attrlist0001']['values'], 'slug'));
        self::assertSame([], $byUuid['attrlist0002']['values']);
    }

    public function testAttributeIndexQFilterMatchesNameOrSlug(): void
    {
        $this->seedAttribute('attrlist0011', 'color', 'Color', 0);
        $this->seedAttribute('attrlist0012', 'size', 'Size', 1);

        $response = $this->attributeController()->index(new AttributeListQuery(q: 'colo'), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('attrlist0011', $body['data'][0]['uuid']);
    }

    public function testAttributeIndexQFilterTreatsUnderscoreAsLiteral(): void
    {
        $this->seedAttribute('attrlist0021', 'a_b', 'A Under B', 0);
        $this->seedAttribute('attrlist0022', 'axb', 'A X B', 1);

        $response = $this->attributeController()->index(new AttributeListQuery(q: 'a_b'), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('attrlist0021', $body['data'][0]['uuid']);
    }

    public function testAttributeIndexOrdersByPositionThenNameWithStableUuidTieBreak(): void
    {
        $this->seedAttribute('attrtie00002', 'tie-b', 'Tie', 0);
        $this->seedAttribute('attrtie00001', 'tie-a', 'Tie', 0);

        $response = $this->attributeController()->index(new AttributeListQuery(), Request::create('/x'));
        $uuids = array_column($this->json($response)['data'], 'uuid');

        self::assertSame(['attrtie00001', 'attrtie00002'], $uuids);
    }

    public function testAttributeIndexPaginatesWithClamp(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->seedAttribute('attrpage000' . $i, 'attr' . $i, 'Attr ' . $i, $i);
        }

        $response = $this->attributeController()->index(
            new AttributeListQuery(page: 1, per_page: 2),
            Request::create('/x')
        );

        $body = $this->json($response);
        self::assertSame(3, $body['total']);
        self::assertCount(2, $body['data']);
    }

    // === Shipping zones (pagination only, shadows_later_zones preserved) =====

    public function testShippingZoneIndexPaginatedEnvelope(): void
    {
        $this->seedZone('zonelist0001', 'Domestic', 0);
        $this->seedZone('zonelist0002', 'International', 1);

        $response = $this->zoneController()->index(new ShippingZoneListQuery(), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(2, $body['total']);
        self::assertCount(2, $body['data']);
        self::assertSame(24, $body['per_page']);
    }

    public function testShippingZoneIndexOrdersByPositionThenUuidAcrossPages(): void
    {
        $this->seedZone('zonepage0001', 'Zone A', 0);
        $this->seedZone('zonepage0002', 'Zone B', 1);
        $this->seedZone('zonepage0003', 'Zone C', 2);

        $page1 = $this->json($this->zoneController()->index(
            new ShippingZoneListQuery(page: 1, per_page: 2),
            Request::create('/x')
        ));
        $page2 = $this->json($this->zoneController()->index(
            new ShippingZoneListQuery(page: 2, per_page: 2),
            Request::create('/x')
        ));

        self::assertSame(3, $page1['total']);
        self::assertSame(['zonepage0001', 'zonepage0002'], array_column($page1['data'], 'uuid'));
        self::assertSame(['zonepage0003'], array_column($page2['data'], 'uuid'));
    }

    public function testShippingZoneIndexShadowsLaterZonesComputedAcrossFullSetNotJustOnePage(): void
    {
        // The catch-all zone (no locations, position 0) is on page 1; the zone it
        // shadows sits on page 2. shadows_later_zones must still see it.
        $this->seedZone('zoneshadow001', 'Catch-all', 0);
        $this->seedZone('zoneshadow002', 'United States', 1);
        $this->connection->table('commerce_shipping_zone_locations')->insert([
            'zone_uuid' => 'zoneshadow002',
            'kind' => 'country',
            'value' => 'US',
        ]);

        $page1 = $this->json($this->zoneController()->index(
            new ShippingZoneListQuery(page: 1, per_page: 1),
            Request::create('/x')
        ));

        self::assertTrue($page1['data'][0]['shadows_later_zones']);
    }

    // === Shipping classes ======================================================

    public function testShippingClassIndexPaginatedEnvelope(): void
    {
        $this->seedShippingClass('classlist0001', 'fragile', 'Fragile');
        $this->seedShippingClass('classlist0002', 'oversized', 'Oversized');
        $this->seedShippingClass('classlist0003', 'other', 'Other', 'tenant-b');

        $response = $this->classController()->index(new ShippingClassListQuery(), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(2, $body['total']);
        self::assertCount(2, $body['data']);
    }

    public function testShippingClassIndexQFilterMatchesNameOrSlug(): void
    {
        $this->seedShippingClass('classlist0011', 'fragile', 'Fragile');
        $this->seedShippingClass('classlist0012', 'oversized', 'Oversized');

        $response = $this->classController()->index(new ShippingClassListQuery(q: 'frag'), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('classlist0011', $body['data'][0]['uuid']);
    }

    public function testShippingClassIndexQFilterTreatsPercentAsLiteral(): void
    {
        $this->seedShippingClass('classlist0021', 'p1', '50% Off Rate');
        $this->seedShippingClass('classlist0022', 'p2', '5000 Off Rate');

        $response = $this->classController()->index(new ShippingClassListQuery(q: '50%'), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('classlist0021', $body['data'][0]['uuid']);
    }

    public function testShippingClassIndexOrdersByNameWithStableUuidTieBreak(): void
    {
        $this->seedShippingClass('classtie00002', 'tie-b', 'Tie');
        $this->seedShippingClass('classtie00001', 'tie-a', 'Tie');

        $response = $this->classController()->index(new ShippingClassListQuery(), Request::create('/x'));
        $uuids = array_column($this->json($response)['data'], 'uuid');

        self::assertSame(['classtie00001', 'classtie00002'], $uuids);
    }

    // === Tax rates (pagination + tie-break; country/class filters already =====
    // === covered by TaxRateEndpointTest) =======================================

    public function testTaxRateIndexPaginatedEnvelope(): void
    {
        $this->seedTaxRate('ratelist0001', 'US', 500, 0);
        $this->seedTaxRate('ratelist0002', 'CA', 500, 0);

        $response = $this->rateController()->index(new TaxRateListQuery());

        $body = $this->json($response);
        self::assertSame(2, $body['total']);
        self::assertCount(2, $body['data']);
        self::assertSame(24, $body['per_page']);
    }

    public function testTaxRateIndexOrdersByCountryThenPriorityWithStableUuidTieBreak(): void
    {
        $this->seedTaxRate('ratetie00002', 'US', 500, 0);
        $this->seedTaxRate('ratetie00001', 'US', 500, 0);

        $response = $this->rateController()->index(new TaxRateListQuery());
        $uuids = array_column($this->json($response)['data'], 'uuid');

        self::assertSame(['ratetie00001', 'ratetie00002'], $uuids);
    }

    public function testTaxRateIndexPaginatesWithClamp(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->seedTaxRate('ratepage000' . $i, 'US', 500, $i);
        }

        $response = $this->rateController()->index(new TaxRateListQuery(page: 1, per_page: 2));

        $body = $this->json($response);
        self::assertSame(3, $body['total']);
        self::assertCount(2, $body['data']);
    }

    // === Seeding helpers =====================================================

    /** @param array<string,mixed> $overrides */
    private function seedProduct(array $overrides, string $tenant = ''): string
    {
        $uuid = (string) ($overrides['uuid'] ?? 'prod' . substr(md5((string) ($overrides['slug'] ?? 'x') . $tenant), 0, 8));
        $this->connection->table('commerce_products')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => 'slug-' . $uuid,
            'name' => 'Product',
            'type' => 'physical',
            'status' => 'active',
        ], $overrides, ['uuid' => $uuid, 'tenant_uuid' => $tenant]));

        return $uuid;
    }

    private function seedTag(string $uuid, string $slug, string $name, string $tenant = ''): string
    {
        $this->connection->table('commerce_tags')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
        ]);

        return $uuid;
    }

    private function seedAttribute(string $uuid, string $slug, string $name, int $position, string $tenant = ''): string
    {
        $this->connection->table('commerce_attributes')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
            'position' => $position,
        ]);

        return $uuid;
    }

    private function seedAttributeValue(string $attributeUuid, string $uuid, string $slug, string $value, int $position): void
    {
        $this->connection->table('commerce_attribute_values')->insert([
            'uuid' => $uuid,
            'attribute_uuid' => $attributeUuid,
            'slug' => $slug,
            'value' => $value,
            'position' => $position,
        ]);
    }

    private function seedZone(string $uuid, string $name, int $position, string $tenant = ''): string
    {
        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'name' => $name,
            'position' => $position,
        ]);

        return $uuid;
    }

    private function seedShippingClass(string $uuid, string $slug, string $name, string $tenant = ''): string
    {
        $this->connection->table('commerce_shipping_classes')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
        ]);

        return $uuid;
    }

    private function seedTaxRate(string $uuid, string $country, int $rateBps, int $priority, string $tenant = ''): string
    {
        $this->connection->table('commerce_tax_rates')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'country' => $country,
            'rate_bps' => $rateBps,
            'label' => 'Rate',
            'priority' => $priority,
            'class' => 'standard',
        ]);

        return $uuid;
    }

    // === Controller factories =================================================

    private function productController(string $tenant = ''): AdminProductController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new AdminProductController(
            $this->context,
            new CatalogService(new ProductRepository(), new VariantRepository(), $tenants, new StockRepository()),
            new ProductRepository(),
            new VariantRepository(),
            $tenants,
            new ShippingClassRepository()
        );
    }

    private function tagController(string $tenant = ''): AdminTagController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new AdminTagController(
            $this->context,
            new TagService(new TagRepository(), new ProductRepository(), $tenants)
        );
    }

    private function attributeController(string $tenant = ''): AdminAttributeController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new AdminAttributeController(
            $this->context,
            new AttributeService(new AttributeRepository(), new ProductRepository(), $tenants)
        );
    }

    private function zoneController(string $tenant = ''): AdminShippingZoneController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new AdminShippingZoneController(
            $this->context,
            new ShippingZoneService(new ShippingZoneRepository(), new ShippingClassRepository(), $tenants)
        );
    }

    private function classController(string $tenant = ''): AdminShippingClassController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new AdminShippingClassController(
            $this->context,
            new ShippingClassService(new ShippingClassRepository(), $tenants)
        );
    }

    private function rateController(string $tenant = ''): AdminTaxRateController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new AdminTaxRateController(
            $this->context,
            new TaxRateService(new TaxRateRepository(), $tenants)
        );
    }

    private function fixedTenant(string $tenant): CurrentTenantResolver
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

    /** @param array<string,mixed> $body */
    private function patchRequest(array $body): Request
    {
        $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
