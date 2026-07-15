<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Cart;

use Glueful\Extensions\Commerce\Cart\AddonSnapshot;
use Glueful\Extensions\Commerce\Cart\AddonValidationException;
use PHPUnit\Framework\TestCase;

final class AddonSnapshotTest extends TestCase
{
    // -----------------------------------------------------------------
    // Empty selections
    // -----------------------------------------------------------------

    public function testEmptySelectionsProduceEmptySnapshotAndEmptyHash(): void
    {
        $result = AddonSnapshot::build([], [], 1000);

        self::assertSame([], $result['snapshot']);
        self::assertSame('', $result['hash']);
    }

    // -----------------------------------------------------------------
    // Validation matrix
    // -----------------------------------------------------------------

    public function testUnknownAddonUuidThrows(): void
    {
        $this->expectException(AddonValidationException::class);

        AddonSnapshot::build(
            [$this->textAddon('addon1', required: false)],
            [['addon_uuid' => 'nope', 'value' => 'hi']],
            1000
        );
    }

    public function testDuplicateAddonUuidThrows(): void
    {
        $definitions = [$this->textAddon('addon1', required: false)];
        $selections = [
            ['addon_uuid' => 'addon1', 'value' => 'hi'],
            ['addon_uuid' => 'addon1', 'value' => 'again'],
        ];

        $this->expectException(AddonValidationException::class);

        AddonSnapshot::build($definitions, $selections, 1000);
    }

    public function testMissingRequiredAddonThrows(): void
    {
        $definitions = [$this->textAddon('addon1', required: true)];

        $this->expectException(AddonValidationException::class);

        AddonSnapshot::build($definitions, [], 1000);
    }

    public function testMissingRequiredAddonDoesNotThrowWhenSelected(): void
    {
        $definitions = [$this->textAddon('addon1', required: true)];

        $result = AddonSnapshot::build($definitions, [['addon_uuid' => 'addon1', 'value' => 'Engrave: HI']], 1000);

        self::assertCount(1, $result['snapshot']);
    }

    public function testSelectInvalidChoiceKeyThrows(): void
    {
        $definitions = [$this->selectAddon('addon1', [
            ['key' => 'red', 'label' => 'Red', 'price_delta' => 100],
        ])];

        $this->expectException(AddonValidationException::class);

        AddonSnapshot::build($definitions, [['addon_uuid' => 'addon1', 'choice_key' => 'blue']], 1000);
    }

    public function testSelectMissingChoiceKeyThrows(): void
    {
        $definitions = [$this->selectAddon('addon1', [
            ['key' => 'red', 'label' => 'Red', 'price_delta' => 100],
        ])];

        $this->expectException(AddonValidationException::class);

        AddonSnapshot::build($definitions, [['addon_uuid' => 'addon1']], 1000);
    }

    public function testCheckboxNonBooleanThrows(): void
    {
        $definitions = [$this->checkboxAddon('addon1', 200)];

        $this->expectException(AddonValidationException::class);

        AddonSnapshot::build($definitions, [['addon_uuid' => 'addon1', 'value' => 'yes']], 1000);
    }

    public function testCheckboxMissingValueThrows(): void
    {
        $definitions = [$this->checkboxAddon('addon1', 200)];

        $this->expectException(AddonValidationException::class);

        AddonSnapshot::build($definitions, [['addon_uuid' => 'addon1']], 1000);
    }

    public function testTextEmptyThrows(): void
    {
        $definitions = [$this->textAddon('addon1', required: false)];

        $this->expectException(AddonValidationException::class);

        AddonSnapshot::build($definitions, [['addon_uuid' => 'addon1', 'value' => '   ']], 1000);
    }

    public function testTextOver500CharsThrows(): void
    {
        $definitions = [$this->textAddon('addon1', required: false)];

        $this->expectException(AddonValidationException::class);

        AddonSnapshot::build($definitions, [['addon_uuid' => 'addon1', 'value' => str_repeat('a', 501)]], 1000);
    }

    public function testTextExactly500CharsIsAccepted(): void
    {
        $definitions = [$this->textAddon('addon1', required: false)];

        $result = AddonSnapshot::build(
            $definitions,
            [['addon_uuid' => 'addon1', 'value' => str_repeat('a', 500)]],
            1000
        );

        self::assertCount(1, $result['snapshot']);
    }

    public function testNegativeFinalUnitPriceThrows(): void
    {
        $definitions = [$this->checkboxAddon('addon1', -600)];

        $this->expectException(AddonValidationException::class);

        AddonSnapshot::build($definitions, [['addon_uuid' => 'addon1', 'value' => true]], 500);
    }

    public function testZeroFinalUnitPriceIsAccepted(): void
    {
        $definitions = [$this->checkboxAddon('addon1', -500)];

        $result = AddonSnapshot::build($definitions, [['addon_uuid' => 'addon1', 'value' => true]], 500);

        self::assertSame(-500, AddonSnapshot::delta($result['snapshot']));
    }

    // -----------------------------------------------------------------
    // Hash stability and canonicalization
    // -----------------------------------------------------------------

    public function testHashStableRegardlessOfSelectionOrder(): void
    {
        $definitions = [
            $this->textAddon('addon1', required: false),
            $this->checkboxAddon('addon2', 100),
        ];

        $first = AddonSnapshot::build($definitions, [
            ['addon_uuid' => 'addon1', 'value' => 'hello'],
            ['addon_uuid' => 'addon2', 'value' => true],
        ], 1000);

        $second = AddonSnapshot::build($definitions, [
            ['addon_uuid' => 'addon2', 'value' => true],
            ['addon_uuid' => 'addon1', 'value' => 'hello'],
        ], 1000);

        self::assertSame($first['hash'], $second['hash']);
        self::assertNotSame('', $first['hash']);
    }

    public function testHashChangesWhenDefinitionNameEdited(): void
    {
        $definitions = [$this->textAddon('addon1', required: false)];
        $selections = [['addon_uuid' => 'addon1', 'value' => 'hello']];

        $before = AddonSnapshot::build($definitions, $selections, 1000);

        $editedDefinitions = [$this->textAddon('addon1', required: false, name: 'Renamed Addon')];
        $after = AddonSnapshot::build($editedDefinitions, $selections, 1000);

        self::assertNotSame($before['hash'], $after['hash']);
    }

    public function testHashChangesWhenDefinitionPriceEdited(): void
    {
        $definitions = [$this->checkboxAddon('addon1', 100)];
        $selections = [['addon_uuid' => 'addon1', 'value' => true]];

        $before = AddonSnapshot::build($definitions, $selections, 1000);

        $editedDefinitions = [$this->checkboxAddon('addon1', 200)];
        $after = AddonSnapshot::build($editedDefinitions, $selections, 1000);

        self::assertNotSame($before['hash'], $after['hash']);
    }

    public function testHashIndependentOfSnapshotArrayOrderPassedDirectly(): void
    {
        $entryA = [
            'addon_uuid' => 'a',
            'name' => 'A',
            'field_type' => 'checkbox',
            'choice_key' => null,
            'choice_label' => null,
            'value' => true,
            'price_delta' => 100,
        ];
        $entryB = [
            'addon_uuid' => 'b',
            'name' => 'B',
            'field_type' => 'text',
            'choice_key' => null,
            'choice_label' => null,
            'value' => 'hello',
            'price_delta' => 50,
        ];

        self::assertSame(
            AddonSnapshot::hash([$entryA, $entryB]),
            AddonSnapshot::hash([$entryB, $entryA])
        );
    }

    public function testHashTrimsTextValues(): void
    {
        $entry = [
            'addon_uuid' => 'a',
            'name' => 'A',
            'field_type' => 'text',
            'choice_key' => null,
            'choice_label' => null,
            'value' => 'hello',
            'price_delta' => 0,
        ];
        $entryPadded = $entry;
        $entryPadded['value'] = '  hello  ';

        self::assertSame(AddonSnapshot::hash([$entry]), AddonSnapshot::hash([$entryPadded]));
    }

    // -----------------------------------------------------------------
    // delta()
    // -----------------------------------------------------------------

    public function testDeltaSumsAllEntries(): void
    {
        $definitions = [
            $this->selectAddon('addon1', [
                ['key' => 'red', 'label' => 'Red', 'price_delta' => 150],
            ]),
            $this->checkboxAddon('addon2', 300),
            $this->textAddon('addon3', required: false, priceDelta: 50),
        ];

        $result = AddonSnapshot::build($definitions, [
            ['addon_uuid' => 'addon1', 'choice_key' => 'red'],
            ['addon_uuid' => 'addon2', 'value' => true],
            ['addon_uuid' => 'addon3', 'value' => 'engrave me'],
        ], 1000);

        self::assertSame(500, AddonSnapshot::delta($result['snapshot']));
    }

    public function testUncheckedCheckboxContributesZeroDelta(): void
    {
        $definitions = [$this->checkboxAddon('addon1', 300)];

        $result = AddonSnapshot::build($definitions, [['addon_uuid' => 'addon1', 'value' => false]], 1000);

        self::assertSame(0, AddonSnapshot::delta($result['snapshot']));
        self::assertFalse($result['snapshot'][0]['value']);
    }

    public function testDeltaOfEmptySnapshotIsZero(): void
    {
        self::assertSame(0, AddonSnapshot::delta([]));
    }

    // -----------------------------------------------------------------
    // sanitize()
    // -----------------------------------------------------------------

    public function testSanitizeStripsAddonUuidAndChoiceKey(): void
    {
        $definitions = [$this->selectAddon('addon1', [
            ['key' => 'red', 'label' => 'Red', 'price_delta' => 150],
        ])];

        $result = AddonSnapshot::build($definitions, [['addon_uuid' => 'addon1', 'choice_key' => 'red']], 1000);
        $sanitized = AddonSnapshot::sanitize($result['snapshot']);

        self::assertCount(1, $sanitized);
        self::assertSame(['name', 'field_type', 'choice_label', 'price_delta'], array_keys($sanitized[0]));
        self::assertSame('Red', $sanitized[0]['choice_label']);
        self::assertSame(150, $sanitized[0]['price_delta']);
    }

    public function testSanitizeOmitsChoiceLabelForCheckbox(): void
    {
        $definitions = [$this->checkboxAddon('addon1', 300)];

        $result = AddonSnapshot::build($definitions, [['addon_uuid' => 'addon1', 'value' => true]], 1000);
        $sanitized = AddonSnapshot::sanitize($result['snapshot']);

        self::assertArrayNotHasKey('choice_label', $sanitized[0]);
        self::assertArrayNotHasKey('addon_uuid', $sanitized[0]);
        self::assertArrayNotHasKey('choice_key', $sanitized[0]);
        self::assertTrue($sanitized[0]['value']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function textAddon(string $uuid, bool $required, string $name = 'Engraving', int $priceDelta = 0): array
    {
        return [
            'uuid' => $uuid,
            'name' => $name,
            'field_type' => 'text',
            'required' => $required,
            'choices' => null,
            'price_delta' => $priceDelta,
        ];
    }

    /** @return array<string,mixed> */
    private function checkboxAddon(string $uuid, int $priceDelta, bool $required = false): array
    {
        return [
            'uuid' => $uuid,
            'name' => 'Gift wrap',
            'field_type' => 'checkbox',
            'required' => $required,
            'choices' => null,
            'price_delta' => $priceDelta,
        ];
    }

    /**
     * @param list<array{key:string,label:string,price_delta:int}> $choices
     * @return array<string,mixed>
     */
    private function selectAddon(string $uuid, array $choices, bool $required = false): array
    {
        return [
            'uuid' => $uuid,
            'name' => 'Color',
            'field_type' => 'select',
            'required' => $required,
            'choices' => $choices,
            'price_delta' => 0,
        ];
    }
}
