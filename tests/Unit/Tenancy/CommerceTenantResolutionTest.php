<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\CommerceServiceProvider;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * Task 1: the `CommerceTenantResolution` seam. Byte-parity-critical --
 * commerce 1.2.x is published, so every "seam not bound" branch must behave
 * exactly as it did before this seam existed.
 */
final class CommerceTenantResolutionTest extends CommerceTestCase
{
    // -----------------------------------------------------------------
    // Seam bound: takes priority, evaluated per call (no latch)
    // -----------------------------------------------------------------

    public function testBoundSeamYieldsItsTenantUuid(): void
    {
        $this->bind(CommerceTenantResolution::class, $this->fixedSeam('tenantX00001'));

        $resolver = CommerceServiceProvider::makeTenantResolver($this->contextContainer(), $this->context);

        self::assertSame('tenantX00001', $resolver->tenantUuid($this->context));
    }

    public function testSeamIsEvaluatedPerCallNotLatched(): void
    {
        $seam = $this->mutableSeam('tenantAAAA01');
        $this->bind(CommerceTenantResolution::class, $seam);

        $resolver = CommerceServiceProvider::makeTenantResolver($this->contextContainer(), $this->context);

        self::assertSame('tenantAAAA01', $resolver->tenantUuid($this->context));

        $seam->current = 'tenantBBBB02';

        self::assertSame('tenantBBBB02', $resolver->tenantUuid($this->context));
    }

    public function testSeamBoundWinsOverDisabledTenancy(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tenancy' => ['enabled' => false]]);
        $this->bind(CommerceTenantResolution::class, $this->fixedSeam('tenantX00001'));

        $resolver = CommerceServiceProvider::makeTenantResolver($this->contextContainer(), $this->context);

        self::assertSame('tenantX00001', $resolver->tenantUuid($this->context));
    }

    public function testSeamBoundWinsOverEnabledTenancyWithNoSharedResolverBound(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tenancy' => ['enabled' => true]]);
        $this->bind(CommerceTenantResolution::class, $this->fixedSeam('tenantX00001'));

        $resolver = CommerceServiceProvider::makeTenantResolver($this->contextContainer(), $this->context);

        self::assertSame('tenantX00001', $resolver->tenantUuid($this->context));
    }

    // -----------------------------------------------------------------
    // Byte-parity: nothing bound -- 1.2.x behavior, unchanged
    // -----------------------------------------------------------------

    public function testNothingBoundAndDisabledYieldsSentinelResolver(): void
    {
        $resolver = CommerceServiceProvider::makeTenantResolver($this->contextContainer(), $this->context);

        self::assertInstanceOf(SentinelTenantResolver::class, $resolver);
        self::assertSame('', $resolver->tenantUuid($this->context));
    }

    public function testNothingBoundAndEnabledThrowsTheExistingRuntimeException(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tenancy' => ['enabled' => true]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'commerce.tenancy.enabled requires a bound CurrentTenantResolver (install glueful/tenancy).'
        );
        CommerceServiceProvider::makeTenantResolver($this->contextContainer(), $this->context);
    }

    // -----------------------------------------------------------------
    // Byte-parity: shared CurrentTenantResolver bound, no seam -- unchanged
    // -----------------------------------------------------------------

    public function testSharedResolverIsUsedWhenEnabledAndNoSeamBound(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tenancy' => ['enabled' => true]]);
        $this->bind(CurrentTenantResolver::class, $this->fixedSharedResolver('tenantSHARED1'));

        $resolver = CommerceServiceProvider::makeTenantResolver($this->contextContainer(), $this->context);

        self::assertSame('tenantSHARED1', $resolver->tenantUuid($this->context));
    }

    private function fixedSeam(string $tenant): CommerceTenantResolution
    {
        return new class ($tenant) implements CommerceTenantResolution {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
    }

    private function mutableSeam(string $initial): object
    {
        return new class ($initial) implements CommerceTenantResolution {
            public string $current;

            public function __construct(string $initial)
            {
                $this->current = $initial;
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->current;
            }
        };
    }

    private function fixedSharedResolver(string $tenant): CurrentTenantResolver
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
}
