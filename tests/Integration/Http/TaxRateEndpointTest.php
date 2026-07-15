<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\AdminTaxRateController;
use Glueful\Extensions\Commerce\Http\DTOs\CreateTaxRateData;
use Glueful\Extensions\Commerce\Http\DTOs\TaxRateListQuery;
use Glueful\Extensions\Commerce\Tax\TaxRateRepository;
use Glueful\Extensions\Commerce\Tax\TaxRateService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Tax rate CRUD (spec §2, §6): create/list/update/delete, the full geography
 * validation matrix (country normalization, state country-prefix consistency,
 * postcode_pattern exact-or-wildcard grammar), rate_bps bounds, class
 * open-vocabulary normalization/default, and list filters.
 */
final class TaxRateEndpointTest extends CommerceTestCase
{
    // --- CRUD happy paths -----------------------------------------------------

    public function testCreateRateHappyPathDefaultsClassToStandard(): void
    {
        $response = $this->controller()->store(
            new CreateTaxRateData(country: 'US', rate_bps: 875, label: 'Sales Tax'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->json($response)['data'];
        self::assertSame('US', $data['country']);
        self::assertSame(875, (int) $data['rate_bps']);
        self::assertSame('standard', $data['class']);
        self::assertSame(0, (int) $data['priority']);
        self::assertFalse((bool) $data['shipping_taxable']);
        self::assertSame(0, (int) $data['revision']);
    }

    public function testCreateRateNormalizesCountryToUppercase(): void
    {
        $response = $this->controller()->store(
            new CreateTaxRateData(country: 'us', rate_bps: 500, label: 'GST'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('US', $this->json($response)['data']['country']);
    }

    public function testCreateRateWithMatchingStatePrefixSucceeds(): void
    {
        $response = $this->controller()->store(
            new CreateTaxRateData(country: 'US', state: 'us:ca', rate_bps: 725, label: 'CA Sales Tax'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('US:CA', $this->json($response)['data']['state']);
    }

    public function testCreateRateWithCustomClassAndFlags(): void
    {
        $response = $this->controller()->store(
            new CreateTaxRateData(
                country: 'US',
                rate_bps: 0,
                label: 'Shipping Tax',
                priority: 5,
                shipping_taxable: true,
                class: 'reduced'
            ),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('reduced', $data['class']);
        self::assertSame(5, (int) $data['priority']);
        self::assertTrue((bool) $data['shipping_taxable']);
    }

    public function testCreateRateMissingLabelReturns422(): void
    {
        $response = $this->controller()->store(
            new CreateTaxRateData(country: 'US', rate_bps: 500, label: ''),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('label', $this->json($response)['error']['details']);
    }

    // --- Geography matrix: country -----------------------------------------------------

    /** @return array<string,array{0:string}> */
    public static function invalidCountryProvider(): array
    {
        return [
            'three letters' => ['USA'],
            'one letter' => ['U'],
            'digits' => ['12'],
            'empty' => [''],
        ];
    }

    /** @dataProvider invalidCountryProvider */
    public function testCreateRateRejectsMalformedCountry(string $country): void
    {
        $response = $this->controller()->store(
            new CreateTaxRateData(country: $country, rate_bps: 500, label: 'Bad Country'),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode(), "country '{$country}' should have been rejected");
        self::assertArrayHasKey('country', $this->json($response)['error']['details']);
    }

    // --- Geography matrix: state -----------------------------------------------------

    public function testCreateRateRejectsStateWithMismatchedCountryPrefix(): void
    {
        // country=US, state=CA:ON -- the state's own country prefix (CA) does not
        // equal this rate's country (US); spec §6 pins this exact example.
        $response = $this->controller()->store(
            new CreateTaxRateData(country: 'US', state: 'CA:ON', rate_bps: 500, label: 'Mismatch'),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('state', $this->json($response)['error']['details']);
    }

    public function testCreateRateRejectsStateWithoutColon(): void
    {
        $response = $this->controller()->store(
            new CreateTaxRateData(country: 'US', state: 'USCA', rate_bps: 500, label: 'Bad State'),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('state', $this->json($response)['error']['details']);
    }

    // --- Geography matrix: postcode_pattern -----------------------------------------------------

    public function testCreateRateAcceptsExactAndWildcardPostcode(): void
    {
        $exact = $this->controller()->store(
            new CreateTaxRateData(country: 'US', postcode_pattern: '90210', rate_bps: 500, label: 'Exact'),
            Request::create('/x', 'POST')
        );
        $wildcard = $this->controller()->store(
            new CreateTaxRateData(country: 'US', postcode_pattern: '90*', rate_bps: 500, label: 'Wildcard'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $exact->getStatusCode());
        self::assertSame(201, $wildcard->getStatusCode());
        self::assertSame('90210', $this->json($exact)['data']['postcode_pattern']);
        self::assertSame('90*', $this->json($wildcard)['data']['postcode_pattern']);
    }

    /** @return array<string,array{0:string}> */
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
    public function testCreateRateRejectsInvalidPostcodePatterns(string $pattern): void
    {
        $response = $this->controller()->store(
            new CreateTaxRateData(country: 'US', postcode_pattern: $pattern, rate_bps: 500, label: 'Bad Postcode'),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode(), "pattern '{$pattern}' should have been rejected");
        self::assertArrayHasKey('postcode_pattern', $this->json($response)['error']['details']);
    }

    // --- rate_bps bounds -----------------------------------------------------

    /** @return array<string,array{0:int,1:bool}> bps => expected acceptance */
    public static function bpsBoundsProvider(): array
    {
        return [
            'zero is valid' => [0, true],
            'ten thousand is valid (100%)' => [10000, true],
            'mid-range valid' => [875, true],
            'negative rejected' => [-1, false],
            'above ten thousand rejected' => [10001, false],
        ];
    }

    /** @dataProvider bpsBoundsProvider */
    public function testCreateRateBpsBounds(int $bps, bool $shouldSucceed): void
    {
        $response = $this->controller()->store(
            new CreateTaxRateData(country: 'US', rate_bps: $bps, label: 'Bounds Test'),
            Request::create('/x', 'POST')
        );

        if ($shouldSucceed) {
            self::assertSame(201, $response->getStatusCode(), "bps {$bps} should have been accepted");
        } else {
            self::assertSame(422, $response->getStatusCode(), "bps {$bps} should have been rejected");
            self::assertArrayHasKey('rate_bps', $this->json($response)['error']['details']);
        }
    }

    public function testUpdateRejectsNonIntegerBpsFromRawBody(): void
    {
        $rate = $this->createRate('US', 500, 'Sales Tax');

        $response = $this->controller()->update(
            $this->patchRequest(['rate_bps' => '5000']),
            $rate['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('rate_bps', $this->json($response)['error']['details']);
    }

    // --- class open-vocabulary -----------------------------------------------------

    public function testCreateRateRejectsMalformedClass(): void
    {
        $response = $this->controller()->store(
            new CreateTaxRateData(country: 'US', rate_bps: 500, label: 'Bad Class', class: '1nvalid'),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('class', $this->json($response)['error']['details']);
    }

    public function testCreateRateNormalizesClassToLowercase(): void
    {
        $response = $this->controller()->store(
            new CreateTaxRateData(country: 'US', rate_bps: 500, label: 'Reduced', class: 'REDUCED'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('reduced', $this->json($response)['data']['class']);
    }

    // --- Update -----------------------------------------------------

    public function testUpdateChangesFieldsAndBumpsRevision(): void
    {
        $rate = $this->createRate('US', 500, 'Sales Tax');

        $response = $this->controller()->update(
            $this->patchRequest(['rate_bps' => 750, 'label' => 'Updated Tax']),
            $rate['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame(750, (int) $data['rate_bps']);
        self::assertSame('Updated Tax', $data['label']);
        self::assertSame(1, (int) $data['revision']);
    }

    public function testUpdateExplicitNullClearsStateAndPostcode(): void
    {
        $response = $this->controller()->store(
            new CreateTaxRateData(country: 'US', state: 'US:CA', postcode_pattern: '90210', rate_bps: 500, label: 'X'),
            Request::create('/x', 'POST')
        );
        $rate = $this->json($response)['data'];

        $updated = $this->controller()->update(
            $this->patchRequest(['state' => null, 'postcode_pattern' => null]),
            $rate['uuid']
        );

        self::assertSame(200, $updated->getStatusCode());
        $data = $this->json($updated)['data'];
        self::assertNull($data['state']);
        self::assertNull($data['postcode_pattern']);
    }

    public function testUpdateCountryChangeRevalidatesExistingStatePrefix(): void
    {
        $response = $this->controller()->store(
            new CreateTaxRateData(country: 'US', state: 'US:CA', rate_bps: 500, label: 'X'),
            Request::create('/x', 'POST')
        );
        $rate = $this->json($response)['data'];

        // Changing country to CA without touching state must re-validate the
        // EXISTING state (US:CA) against the new country -- its prefix no longer
        // matches, so this must be rejected rather than silently stranding a
        // state row whose prefix disagrees with its own row's country.
        $updated = $this->controller()->update(
            $this->patchRequest(['country' => 'CA']),
            $rate['uuid']
        );

        self::assertSame(422, $updated->getStatusCode());
        self::assertArrayHasKey('state', $this->json($updated)['error']['details']);
    }

    public function testUpdateCountryChangeWithMatchingNewStateSucceeds(): void
    {
        $response = $this->controller()->store(
            new CreateTaxRateData(country: 'US', state: 'US:CA', rate_bps: 500, label: 'X'),
            Request::create('/x', 'POST')
        );
        $rate = $this->json($response)['data'];

        $updated = $this->controller()->update(
            $this->patchRequest(['country' => 'CA', 'state' => 'CA:ON']),
            $rate['uuid']
        );

        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());
        $data = $this->json($updated)['data'];
        self::assertSame('CA', $data['country']);
        self::assertSame('CA:ON', $data['state']);
    }

    public function testUpdateUnknownRateThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['label' => 'x']), 'no-such-rate');
    }

    public function testUpdateCrossTenantRateThrowsNotFound(): void
    {
        $rate = $this->createRate('US', 500, 'Sales Tax', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['label' => 'x']), $rate['uuid']);
    }

    // --- Delete -----------------------------------------------------

    public function testDeleteRemovesRow(): void
    {
        $rate = $this->createRate('US', 500, 'Sales Tax');

        $response = $this->controller()->destroy(Request::create('/x', 'DELETE'), $rate['uuid']);

        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull((new TaxRateRepository())->findByUuid($this->context, '', $rate['uuid']));
    }

    public function testDeleteUnknownRateThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), 'no-such-rate');
    }

    public function testDeleteCrossTenantRateThrowsNotFound(): void
    {
        $rate = $this->createRate('US', 500, 'Sales Tax', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), $rate['uuid']);
    }

    // --- List filters -----------------------------------------------------

    public function testListFiltersByCountry(): void
    {
        $this->createRate('US', 500, 'US Tax');
        $this->createRate('CA', 500, 'CA Tax');

        $response = $this->controller()->index(new TaxRateListQuery(country: 'ca'));

        self::assertSame(200, $response->getStatusCode());
        $rows = $this->json($response)['data'];
        self::assertCount(1, $rows);
        self::assertSame('CA', $rows[0]['country']);
    }

    public function testListFiltersByClass(): void
    {
        $this->controller()->store(
            new CreateTaxRateData(country: 'US', rate_bps: 500, label: 'Standard'),
            Request::create('/x', 'POST')
        );
        $this->controller()->store(
            new CreateTaxRateData(country: 'US', rate_bps: 0, label: 'Reduced', class: 'reduced'),
            Request::create('/x', 'POST')
        );

        $response = $this->controller()->index(new TaxRateListQuery(class: 'reduced'));

        self::assertSame(200, $response->getStatusCode());
        $rows = $this->json($response)['data'];
        self::assertCount(1, $rows);
        self::assertSame('reduced', $rows[0]['class']);
    }

    public function testListOnlyReturnsTenantsOwnRates(): void
    {
        $this->createRate('US', 500, 'Mine');
        $this->createRate('US', 500, 'Other Tenant', 'tenant-b');

        $response = $this->controller()->index(new TaxRateListQuery());

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->json($response)['data']);
    }

    // --- Helpers -----------------------------------------------------

    /** @return array<string,mixed> */
    private function createRate(string $country, int $bps, string $label, string $tenant = ''): array
    {
        $response = $this->controller($tenant)->store(
            new CreateTaxRateData(country: $country, rate_bps: $bps, label: $label),
            Request::create('/x', 'POST')
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response)['data'];
    }

    /** @param array<string,mixed> $body */
    private function patchRequest(array $body): Request
    {
        $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    private function controller(string $tenant = ''): AdminTaxRateController
    {
        return new AdminTaxRateController($this->context, $this->rateService($tenant));
    }

    private function rateService(string $tenant = ''): TaxRateService
    {
        return new TaxRateService(
            new TaxRateRepository(),
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
