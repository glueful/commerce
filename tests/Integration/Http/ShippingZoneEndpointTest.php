<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingZoneController;
use Glueful\Extensions\Commerce\Http\DTOs\CreateMethodData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateZoneData;
use Glueful\Extensions\Commerce\Http\DTOs\SetZoneLocationsData;
use Glueful\Extensions\Commerce\Http\DTOs\ShippingZoneListQuery;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ShippingZoneEndpointTest extends CommerceTestCase
{
    // --- Zone CRUD -----------------------------------------------------

    public function testCreateZoneHappyPath(): void
    {
        $response = $this->controller()->store(
            new CreateZoneData(name: 'Domestic'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('Domestic', $data['name']);
        self::assertSame(0, (int) $data['position']);
        self::assertSame(0, (int) $data['revision']);
    }

    public function testCreateZoneDuplicateNameSameTenantReturns422(): void
    {
        $this->createZone('Domestic');

        $response = $this->controller()->store(
            new CreateZoneData(name: 'Domestic'),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('name', $this->json($response)['error']['details']);
    }

    public function testCreateZoneSameNameDifferentTenantSucceeds(): void
    {
        $this->createZone('Domestic', 'tenant-b');

        $response = $this->controller()->store(
            new CreateZoneData(name: 'Domestic'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
    }

    public function testIndexListsAllZonesForTenant(): void
    {
        $this->createZone('Domestic');
        $this->createZone('International');
        $this->createZone('Other Tenant Zone', 'tenant-b');

        $response = $this->controller()->index(new ShippingZoneListQuery(), Request::create('/x'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $this->json($response)['data']);
    }

    public function testUpdateRenamesAndRepositions(): void
    {
        $zone = $this->createZone('Domestic', '', 0);

        $response = $this->controller()->update(
            $this->patchRequest(['name' => 'Domestic Shipping', 'position' => 5]),
            $zone['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('Domestic Shipping', $data['name']);
        self::assertSame(5, (int) $data['position']);
        self::assertSame(1, (int) $data['revision']);
    }

    public function testUpdateNameConflictReturns422(): void
    {
        $this->createZone('Domestic');
        $other = $this->createZone('International');

        $response = $this->controller()->update(
            $this->patchRequest(['name' => 'Domestic']),
            $other['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('name', $this->json($response)['error']['details']);
    }

    public function testUpdateUnknownZoneThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['name' => 'x']), 'no-such-zone');
    }

    public function testUpdateCrossTenantZoneThrowsNotFound(): void
    {
        $zone = $this->createZone('Domestic', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['name' => 'x']), $zone['uuid']);
    }

    public function testDeleteUnknownZoneThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), 'no-such-zone');
    }

    public function testDeleteCrossTenantZoneThrowsNotFound(): void
    {
        $zone = $this->createZone('Domestic', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), $zone['uuid']);
    }

    public function testDeleteZoneCascadesLocationsAndMethods(): void
    {
        $zone = $this->createZone('Domestic');
        $this->setLocations($zone['uuid'], [['kind' => 'country', 'value' => 'US']]);
        $this->createMethod($zone['uuid'], 'flat', 'Standard', ['amount' => 500]);

        $response = $this->controller()->destroy(Request::create('/x', 'DELETE'), $zone['uuid']);

        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());

        self::assertNull((new ShippingZoneRepository())->findByUuid($this->context, '', $zone['uuid']));
        self::assertSame(
            [],
            $this->connection->table('commerce_shipping_zone_locations')
                ->where('zone_uuid', '=', $zone['uuid'])
                ->get()
        );
        self::assertSame(
            [],
            $this->connection->table('commerce_shipping_methods')
                ->where('zone_uuid', '=', $zone['uuid'])
                ->get()
        );
    }

    /**
     * Deterministic stand-in for the zone-delete-vs-method-create race (mirrors
     * ShippingClassEndpointTest::testClassDeleteThenVariantAssignReturnsUnprocessable()):
     * the zone is fully deleted (claim+re-read+cascade, one committed
     * transaction) BEFORE a method-create attempt names it -- exactly as it
     * would appear to a racing create that loses the interleave. The create's
     * own claim on the now-gone zone row affects zero rows, so it must resolve
     * as a non-revealing 404, never silently insert a method row referencing a
     * deleted zone. The real two-connection interleave is exercised by
     * ZoneMethodConcurrencyTest's pgsql-gated test.
     */
    public function testDeleteZoneThenMethodCreateThrowsNotFoundLeavingNoOrphanedMethod(): void
    {
        $zone = $this->createZone('Race Zone');

        $response = $this->controller()->destroy(Request::create('/x', 'DELETE'), $zone['uuid']);
        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());

        try {
            $this->controller()->storeMethod(
                new CreateMethodData(kind: 'flat', label: 'Standard', config: ['amount' => 500]),
                Request::create('/x', 'POST'),
                $zone['uuid']
            );
            self::fail('expected NotFoundException');
        } catch (NotFoundException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(
            0,
            $this->connection->table('commerce_shipping_methods')
                ->where('zone_uuid', '=', $zone['uuid'])
                ->count(),
            'No method row may exist against a deleted zone.'
        );
    }

    // --- Locations set-list -----------------------------------------------------

    public function testSetLocationsCountryHappyPathAndNormalizesCase(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->setLocations(
            new SetZoneLocationsData(locations: [['kind' => 'country', 'value' => 'us']]),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertCount(1, $data);
        self::assertSame('country', $data[0]['kind']);
        self::assertSame('US', $data[0]['value']);
    }

    public function testSetLocationsRejectsInvalidKind(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->setLocations(
            new SetZoneLocationsData(locations: [['kind' => 'continent', 'value' => 'EU']]),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('locations.0.kind', $this->json($response)['error']['details']);
    }

    public function testSetLocationsRejectsMalformedCountry(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->setLocations(
            new SetZoneLocationsData(locations: [['kind' => 'country', 'value' => 'USA']]),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('locations.0.value', $this->json($response)['error']['details']);
    }

    public function testSetLocationsStateHappyPath(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->setLocations(
            new SetZoneLocationsData(locations: [['kind' => 'state', 'value' => 'us:ca']]),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('US:CA', $this->json($response)['data'][0]['value']);
    }

    public function testSetLocationsRejectsStateWithoutColon(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->setLocations(
            new SetZoneLocationsData(locations: [['kind' => 'state', 'value' => 'USCA']]),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('locations.0.value', $this->json($response)['error']['details']);
    }

    public function testSetLocationsRejectsStateWithInvalidCountryPrefix(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->setLocations(
            new SetZoneLocationsData(locations: [['kind' => 'state', 'value' => '12:CA']]),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('locations.0.value', $this->json($response)['error']['details']);
    }

    public function testSetLocationsPostcodeExactAndWildcardHappyPathWithSiblingCountry(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->setLocations(
            new SetZoneLocationsData(locations: [
                ['kind' => 'country', 'value' => 'US'],
                ['kind' => 'postcode_pattern', 'value' => '90210'],
                ['kind' => 'postcode_pattern', 'value' => '90*'],
            ]),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(3, $this->json($response)['data']);
    }

    /**
     * @return list<string>
     */
    public static function invalidPostcodePatternProvider(): array
    {
        return [
            'leading wildcard' => ['*90'],
            'embedded wildcard' => ['9*0'],
            'multiple wildcards' => ['9**'],
            'double trailing wildcard' => ['90**'],
        ];
    }

    /** @dataProvider invalidPostcodePatternProvider */
    public function testSetLocationsRejectsInvalidPostcodePatterns(string $pattern): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->setLocations(
            new SetZoneLocationsData(locations: [
                ['kind' => 'country', 'value' => 'US'],
                ['kind' => 'postcode_pattern', 'value' => $pattern],
            ]),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );

        self::assertSame(422, $response->getStatusCode(), "pattern '{$pattern}' should have been rejected");
        self::assertArrayHasKey('locations.1.value', $this->json($response)['error']['details']);
    }

    public function testSetLocationsPostcodeWithoutCountryReturns422(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->setLocations(
            new SetZoneLocationsData(locations: [['kind' => 'postcode_pattern', 'value' => '90210']]),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('locations', $this->json($response)['error']['details']);
    }

    public function testSetLocationsPostcodeWithOnlyStateSiblingStillReturns422(): void
    {
        // A sibling `state` row is not a substitute for `country` -- the spec pins
        // the requirement specifically to a `country` row (§2/§3).
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->setLocations(
            new SetZoneLocationsData(locations: [
                ['kind' => 'state', 'value' => 'US:CA'],
                ['kind' => 'postcode_pattern', 'value' => '90210'],
            ]),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('locations', $this->json($response)['error']['details']);
    }

    public function testSetLocationsEmptyListMakesZoneEverywhere(): void
    {
        $zone = $this->createZone('Domestic');
        $this->setLocations($zone['uuid'], [['kind' => 'country', 'value' => 'US']]);

        $response = $this->controller()->setLocations(
            new SetZoneLocationsData(locations: []),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->json($response)['data']);
    }

    public function testSetLocationsIsIdempotentOnRepeatedIdenticalSubmission(): void
    {
        $zone = $this->createZone('Domestic');
        $payload = [['kind' => 'country', 'value' => 'US'], ['kind' => 'country', 'value' => 'CA']];

        $first = $this->controller()->setLocations(
            new SetZoneLocationsData(locations: $payload),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );
        self::assertSame(200, $first->getStatusCode());

        $second = $this->controller()->setLocations(
            new SetZoneLocationsData(locations: $payload),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );
        self::assertSame(200, $second->getStatusCode());

        self::assertSame(
            array_map(fn (array $row) => $row['value'], $this->json($first)['data']),
            array_map(fn (array $row) => $row['value'], $this->json($second)['data'])
        );
        self::assertSame(
            2,
            $this->connection->table('commerce_shipping_zone_locations')
                ->where('zone_uuid', '=', $zone['uuid'])->count()
        );
    }

    public function testSetLocationsDedupesDuplicateRowsInPostedSet(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->setLocations(
            new SetZoneLocationsData(locations: [
                ['kind' => 'country', 'value' => 'US'],
                ['kind' => 'country', 'value' => 'us'],
            ]),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->json($response)['data']);
    }

    public function testSetLocationsUnknownZoneThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->setLocations(
            new SetZoneLocationsData(locations: []),
            Request::create('/x', 'PUT'),
            'no-such-zone'
        );
    }

    public function testSetLocationsCrossTenantZoneThrowsNotFound(): void
    {
        $zone = $this->createZone('Domestic', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->setLocations(
            new SetZoneLocationsData(locations: []),
            Request::create('/x', 'PUT'),
            $zone['uuid']
        );
    }

    // --- Method CRUD -----------------------------------------------------

    public function testCreateFlatMethodHappyPath(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->storeMethod(
            new CreateMethodData(kind: 'flat', label: 'Standard', config: ['amount' => 500]),
            Request::create('/x', 'POST'),
            $zone['uuid']
        );

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('flat', $data['kind']);
        self::assertSame(['amount' => 500], $data['config']);
        self::assertTrue($data['enabled']);
        self::assertSame([], $data['warnings']);
    }

    public function testCreateFlatMethodMissingAmountReturns422(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->storeMethod(
            new CreateMethodData(kind: 'flat', label: 'Standard', config: []),
            Request::create('/x', 'POST'),
            $zone['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('config.amount', $this->json($response)['error']['details']);
    }

    public function testCreateFlatMethodNegativeAmountReturns422(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->storeMethod(
            new CreateMethodData(kind: 'flat', label: 'Standard', config: ['amount' => -1]),
            Request::create('/x', 'POST'),
            $zone['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('config.amount', $this->json($response)['error']['details']);
    }

    public function testCreateFreeOverMethodHappyPath(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->storeMethod(
            new CreateMethodData(
                kind: 'free_over',
                label: 'Free over $50',
                config: ['amount' => 500, 'free_over' => 5000]
            ),
            Request::create('/x', 'POST'),
            $zone['uuid']
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['amount' => 500, 'free_over' => 5000],
            $this->json($response)['data']['config']
        );
    }

    public function testCreateFreeOverMethodMissingFreeOverReturns422(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->storeMethod(
            new CreateMethodData(kind: 'free_over', label: 'Free over', config: ['amount' => 500]),
            Request::create('/x', 'POST'),
            $zone['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('config.free_over', $this->json($response)['error']['details']);
    }

    public function testCreatePerClassTableMethodMissingDefaultAmountReturns422(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->storeMethod(
            new CreateMethodData(kind: 'per_class_table', label: 'By class', config: ['classes' => []]),
            Request::create('/x', 'POST'),
            $zone['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('config.default_amount', $this->json($response)['error']['details']);
    }

    public function testCreatePerClassTableMethodUnknownSlugWarnsButAllows(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->storeMethod(
            new CreateMethodData(
                kind: 'per_class_table',
                label: 'By class',
                config: ['default_amount' => 500, 'classes' => ['fragile' => 1000]]
            ),
            Request::create('/x', 'POST'),
            $zone['uuid']
        );

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame(['default_amount' => 500, 'classes' => ['fragile' => 1000]], $data['config']);
        self::assertCount(1, $data['warnings']);
        self::assertStringContainsString('fragile', $data['warnings'][0]);
    }

    public function testCreatePerClassTableMethodKnownSlugHasNoWarnings(): void
    {
        $zone = $this->createZone('Domestic');
        $this->connection->table('commerce_shipping_classes')->insert([
            'uuid' => 'clasfragile1',
            'tenant_uuid' => '',
            'slug' => 'fragile',
            'name' => 'Fragile',
        ]);

        $response = $this->controller()->storeMethod(
            new CreateMethodData(
                kind: 'per_class_table',
                label: 'By class',
                config: ['default_amount' => 500, 'classes' => ['fragile' => 1000]]
            ),
            Request::create('/x', 'POST'),
            $zone['uuid']
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame([], $this->json($response)['data']['warnings']);
    }

    public function testCreatePerClassTableMethodNegativeClassAmountReturns422(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->storeMethod(
            new CreateMethodData(
                kind: 'per_class_table',
                label: 'By class',
                config: ['default_amount' => 500, 'classes' => ['fragile' => -1]]
            ),
            Request::create('/x', 'POST'),
            $zone['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('config.classes.fragile', $this->json($response)['error']['details']);
    }

    public function testCreateMethodInvalidKindReturns422(): void
    {
        $zone = $this->createZone('Domestic');

        $response = $this->controller()->storeMethod(
            new CreateMethodData(kind: 'carrier_live_rate', label: 'Bogus', config: []),
            Request::create('/x', 'POST'),
            $zone['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('kind', $this->json($response)['error']['details']);
    }

    public function testCreateMethodUnknownZoneThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->storeMethod(
            new CreateMethodData(kind: 'flat', label: 'Standard', config: ['amount' => 500]),
            Request::create('/x', 'POST'),
            'no-such-zone'
        );
    }

    public function testCreateMethodCrossTenantZoneThrowsNotFound(): void
    {
        $zone = $this->createZone('Domestic', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->storeMethod(
            new CreateMethodData(kind: 'flat', label: 'Standard', config: ['amount' => 500]),
            Request::create('/x', 'POST'),
            $zone['uuid']
        );
    }

    public function testIndexMethodsOrderedByPositionThenUuid(): void
    {
        $zone = $this->createZone('Domestic');
        $this->createMethod($zone['uuid'], 'flat', 'Second', ['amount' => 100], 1);
        $this->createMethod($zone['uuid'], 'flat', 'First', ['amount' => 200], 0);

        $response = $this->controller()->indexMethods(Request::create('/x'), $zone['uuid']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['First', 'Second'], array_column($this->json($response)['data'], 'label'));
    }

    public function testIndexMethodsUnknownZoneThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->indexMethods(Request::create('/x'), 'no-such-zone');
    }

    public function testIndexMethodsCrossTenantZoneThrowsNotFound(): void
    {
        $zone = $this->createZone('Domestic', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->indexMethods(Request::create('/x'), $zone['uuid']);
    }

    public function testUpdateMethodEnabledToggle(): void
    {
        $zone = $this->createZone('Domestic');
        $method = $this->createMethod($zone['uuid'], 'flat', 'Standard', ['amount' => 500]);
        self::assertTrue($method['enabled']);

        $response = $this->controller()->updateMethod(
            $this->patchRequest(['enabled' => false]),
            $method['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->json($response)['data']['enabled']);
    }

    public function testUpdateMethodLabelAndPosition(): void
    {
        $zone = $this->createZone('Domestic');
        $method = $this->createMethod($zone['uuid'], 'flat', 'Standard', ['amount' => 500]);

        $response = $this->controller()->updateMethod(
            $this->patchRequest(['label' => 'Standard Shipping', 'position' => 3]),
            $method['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('Standard Shipping', $data['label']);
        self::assertSame(3, (int) $data['position']);
    }

    public function testUpdateMethodRevalidatesConfigAgainstExistingKind(): void
    {
        $zone = $this->createZone('Domestic');
        $method = $this->createMethod($zone['uuid'], 'flat', 'Standard', ['amount' => 500]);

        $response = $this->controller()->updateMethod(
            $this->patchRequest(['config' => ['amount' => -5]]),
            $method['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('config.amount', $this->json($response)['error']['details']);
    }

    public function testUpdateMethodConfigChangeSucceedsAndCarriesWarnings(): void
    {
        $zone = $this->createZone('Domestic');
        $method = $this->createMethod(
            $zone['uuid'],
            'per_class_table',
            'By class',
            ['default_amount' => 500, 'classes' => []]
        );

        $response = $this->controller()->updateMethod(
            $this->patchRequest(['config' => ['default_amount' => 500, 'classes' => ['oversized' => 2000]]]),
            $method['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame(['default_amount' => 500, 'classes' => ['oversized' => 2000]], $data['config']);
        self::assertCount(1, $data['warnings']);
    }

    public function testUpdateMethodUnknownThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->updateMethod($this->patchRequest(['label' => 'x']), 'no-such-method');
    }

    public function testUpdateMethodCrossTenantThrowsNotFound(): void
    {
        $zone = $this->createZone('Domestic', 'tenant-b');
        $method = $this->createMethod($zone['uuid'], 'flat', 'Standard', ['amount' => 500], null, 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->updateMethod($this->patchRequest(['label' => 'x']), $method['uuid']);
    }

    public function testDeleteMethodRemovesRow(): void
    {
        $zone = $this->createZone('Domestic');
        $method = $this->createMethod($zone['uuid'], 'flat', 'Standard', ['amount' => 500]);

        $response = $this->controller()->destroyMethod(Request::create('/x', 'DELETE'), $method['uuid']);

        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull((new ShippingZoneRepository())->findMethodByUuid($this->context, $method['uuid']));
    }

    public function testDeleteMethodUnknownThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->destroyMethod(Request::create('/x', 'DELETE'), 'no-such-method');
    }

    public function testDeleteMethodCrossTenantThrowsNotFound(): void
    {
        $zone = $this->createZone('Domestic', 'tenant-b');
        $method = $this->createMethod($zone['uuid'], 'flat', 'Standard', ['amount' => 500], null, 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->destroyMethod(Request::create('/x', 'DELETE'), $method['uuid']);
    }

    // --- Zone list projection: shadows_later_zones -----------------------------------------------------

    public function testEverywhereZonePositionedFirstShadowsLaterZones(): void
    {
        $catchAll = $this->createZone('Catch-all', '', 0);
        $this->createZone('United States', '', 1);
        $this->setLocations($this->lastZoneUuid('United States'), [['kind' => 'country', 'value' => 'US']]);

        $response = $this->controller()->index(new ShippingZoneListQuery(), Request::create('/x'));
        $byName = $this->indexByName($this->json($response)['data']);

        self::assertTrue($byName['Catch-all']['shadows_later_zones']);
        self::assertFalse($byName['United States']['shadows_later_zones']);
    }

    public function testEverywhereZonePositionedLastDoesNotShadowAnything(): void
    {
        $this->createZone('United States', '', 0);
        $this->setLocations($this->lastZoneUuid('United States'), [['kind' => 'country', 'value' => 'US']]);
        $this->createZone('Catch-all', '', 1);

        $response = $this->controller()->index(new ShippingZoneListQuery(), Request::create('/x'));
        $byName = $this->indexByName($this->json($response)['data']);

        self::assertFalse($byName['United States']['shadows_later_zones']);
        self::assertFalse($byName['Catch-all']['shadows_later_zones']);
    }

    public function testZoneWithLocationsNeverShadowsRegardlessOfPosition(): void
    {
        $this->createZone('United States', '', 0);
        $this->setLocations($this->lastZoneUuid('United States'), [['kind' => 'country', 'value' => 'US']]);
        $this->createZone('Canada', '', 1);
        $this->setLocations($this->lastZoneUuid('Canada'), [['kind' => 'country', 'value' => 'CA']]);

        $response = $this->controller()->index(new ShippingZoneListQuery(), Request::create('/x'));
        $byName = $this->indexByName($this->json($response)['data']);

        self::assertFalse($byName['United States']['shadows_later_zones']);
        self::assertFalse($byName['Canada']['shadows_later_zones']);
    }

    public function testIndexProjectionCarriesLocationsAndMethods(): void
    {
        $zone = $this->createZone('Domestic');
        $this->setLocations($zone['uuid'], [['kind' => 'country', 'value' => 'US']]);
        $this->createMethod($zone['uuid'], 'flat', 'Standard', ['amount' => 500]);

        $response = $this->controller()->index(new ShippingZoneListQuery(), Request::create('/x'));
        $data = $this->json($response)['data'][0];

        self::assertCount(1, $data['locations']);
        self::assertCount(1, $data['methods']);
        self::assertSame('flat', $data['methods'][0]['kind']);
    }

    // --- Helpers -----------------------------------------------------

    /** @var array<string,string> name => uuid, populated by createZone() for lastZoneUuid() lookups */
    private array $zoneUuidsByName = [];

    /** @return array<string,mixed> */
    private function createZone(string $name, string $tenant = '', ?int $position = null): array
    {
        $response = $this->controller($tenant)->store(
            new CreateZoneData(name: $name, position: $position),
            Request::create('/x', 'POST')
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $zone = $this->json($response)['data'];
        $this->zoneUuidsByName[$name] = (string) $zone['uuid'];

        return $zone;
    }

    private function lastZoneUuid(string $name): string
    {
        return $this->zoneUuidsByName[$name];
    }

    /** @param list<array<string,mixed>> $locations */
    private function setLocations(string $zoneUuid, array $locations, string $tenant = ''): void
    {
        $response = $this->controller($tenant)->setLocations(
            new SetZoneLocationsData(locations: $locations),
            Request::create('/x', 'PUT'),
            $zoneUuid
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function createMethod(
        string $zoneUuid,
        string $kind,
        string $label,
        array $config,
        ?int $position = null,
        string $tenant = ''
    ): array {
        $response = $this->controller($tenant)->storeMethod(
            new CreateMethodData(kind: $kind, label: $label, config: $config, position: $position),
            Request::create('/x', 'POST'),
            $zoneUuid
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response)['data'];
    }

    /**
     * @param list<array<string,mixed>> $zones
     * @return array<string,array<string,mixed>>
     */
    private function indexByName(array $zones): array
    {
        $result = [];
        foreach ($zones as $zone) {
            $result[(string) $zone['name']] = $zone;
        }

        return $result;
    }

    /** @param array<string,mixed> $body */
    private function patchRequest(array $body): Request
    {
        $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    private function controller(string $tenant = ''): AdminShippingZoneController
    {
        return new AdminShippingZoneController($this->context, $this->zoneService($tenant));
    }

    private function zoneService(string $tenant = ''): ShippingZoneService
    {
        return new ShippingZoneService(
            new ShippingZoneRepository(),
            new ShippingClassRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant)
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

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
