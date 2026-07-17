<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use PHPUnit\Framework\TestCase;

/**
 * The fixed role/capability matrix, copied verbatim from design spec §2.6:
 *
 * | capability                      | owner | admin | staff | analyst |
 * |----------------------------------|:-----:|:-----:|:-----:|:-------:|
 * | commerce.seller.catalog.read     |   x   |   x   |   x   |    x    |
 * | commerce.seller.catalog.write    |   x   |   x   |       |         |
 * | commerce.seller.inventory.read   |   x   |   x   |   x   |    x    |
 * | commerce.seller.inventory.write  |   x   |   x   |   x   |         |
 * | commerce.seller.members.manage   |   x   |       |       |         |
 *
 * Pure logic, zero DB/context dependency -- extends plain TestCase, mirroring
 * DateBucketSqlTest/LiteralLikeTest's convention for this codebase's other
 * side-effect-free normalizers.
 */
final class FixedSellerRoleAuthorityTest extends TestCase
{
    private const CAPABILITIES = [
        'commerce.seller.catalog.read',
        'commerce.seller.catalog.write',
        'commerce.seller.inventory.read',
        'commerce.seller.inventory.write',
        'commerce.seller.members.manage',
    ];

    private function authority(): FixedSellerRoleAuthority
    {
        return new FixedSellerRoleAuthority();
    }

    public function testRolesReturnsExactlyTheFourFixedRoleNames(): void
    {
        self::assertSame(
            ['seller_owner', 'seller_admin', 'seller_staff', 'seller_analyst'],
            $this->authority()->roles()
        );
    }

    /** @return array<string,array{0:string,1:string,2:bool}> */
    public static function matrixProvider(): array
    {
        $expected = [
            'seller_owner' => [
                'commerce.seller.catalog.read' => true,
                'commerce.seller.catalog.write' => true,
                'commerce.seller.inventory.read' => true,
                'commerce.seller.inventory.write' => true,
                'commerce.seller.members.manage' => true,
            ],
            'seller_admin' => [
                'commerce.seller.catalog.read' => true,
                'commerce.seller.catalog.write' => true,
                'commerce.seller.inventory.read' => true,
                'commerce.seller.inventory.write' => true,
                'commerce.seller.members.manage' => false,
            ],
            'seller_staff' => [
                'commerce.seller.catalog.read' => true,
                'commerce.seller.catalog.write' => false,
                'commerce.seller.inventory.read' => true,
                'commerce.seller.inventory.write' => true,
                'commerce.seller.members.manage' => false,
            ],
            'seller_analyst' => [
                'commerce.seller.catalog.read' => true,
                'commerce.seller.catalog.write' => false,
                'commerce.seller.inventory.read' => true,
                'commerce.seller.inventory.write' => false,
                'commerce.seller.members.manage' => false,
            ],
        ];

        $cases = [];
        foreach ($expected as $role => $capabilities) {
            foreach ($capabilities as $capability => $allowed) {
                $cases["{$role}:{$capability}"] = [$role, $capability, $allowed];
            }
        }

        return $cases;
    }

    /** @dataProvider matrixProvider */
    public function testAllowsMatchesTheSpecMatrixExactlyForEveryRoleCapabilityCell(
        string $role,
        string $capability,
        bool $expected
    ): void {
        self::assertSame($expected, $this->authority()->allows($role, $capability), "{$role}:{$capability}");
    }

    /** @dataProvider matrixProvider */
    public function testCapabilitiesForContainsExactlyTheAllowedCapabilitiesForEachRole(
        string $role,
        string $capability,
        bool $expected
    ): void {
        self::assertSame($expected, in_array($capability, $this->authority()->capabilitiesFor($role), true));
    }

    public function testUnknownRoleReturnsEmptyCapabilitiesAndDeniesEveryCapability(): void
    {
        $authority = $this->authority();

        self::assertSame([], $authority->capabilitiesFor('bogus_role'));
        foreach (self::CAPABILITIES as $capability) {
            self::assertFalse($authority->allows('bogus_role', $capability), $capability);
        }
    }

    public function testUnknownCapabilityIsDeniedForEveryKnownRole(): void
    {
        $authority = $this->authority();

        foreach ($authority->roles() as $role) {
            self::assertFalse($authority->allows($role, 'commerce.seller.bogus.capability'), $role);
        }
    }

    public function testEmptyStringRoleAndCapabilityAreBothDenied(): void
    {
        $authority = $this->authority();

        self::assertSame([], $authority->capabilitiesFor(''));
        self::assertFalse($authority->allows('', ''));
        self::assertFalse($authority->allows('seller_owner', ''));
    }
}
