<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Commerce\Support\CommerceSettingsOverride;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Store-settings spec §3.2: the CommerceSettings read surface. No binding → pure config
 * passthrough (every existing install unchanged); a bound override wins; a null or MALFORMED
 * override (bad currency shape, non-numeric int) falls back to config — a corrupted stored row
 * must never leak into money math.
 */
final class CommerceSettingsTest extends CommerceTestCase
{
    /** @param array<string,?string> $values */
    private function bindOverride(array $values): void
    {
        $this->bindings[CommerceSettingsOverride::class] = new class ($values) implements CommerceSettingsOverride {
            /** @param array<string,?string> $values */
            public function __construct(private array $values)
            {
            }

            public function value(ApplicationContext $context, string $key): ?string
            {
                return $this->values[$key] ?? null;
            }
        };
    }

    public function testNoBindingFallsThroughToConfigDefaults(): void
    {
        self::assertSame('USD', CommerceSettings::currency($this->context));
        self::assertSame(0, CommerceSettings::taxFlatRateBps($this->context));
        self::assertSame('ORD-{seq}', CommerceSettings::orderNumberFormat($this->context));
        self::assertSame(60, CommerceSettings::orderExpiryMinutes($this->context));
        self::assertSame(30, CommerceSettings::cartTtlDays($this->context));
        self::assertSame(2, CommerceSettings::lowStockThreshold($this->context));
    }

    public function testBoundOverrideWinsForEveryKey(): void
    {
        $this->bindOverride([
            'commerce.currency' => 'ghs',
            'commerce.tax.flat_rate_bps' => '750',
            'commerce.orders.number_format' => 'THL-{seq}',
            'commerce.orders.expiry_minutes' => '120',
            'commerce.cart.ttl_days' => '7',
            'commerce.reports.low_stock_threshold' => '10',
        ]);

        // Currency normalizes to upper case on the way through.
        self::assertSame('GHS', CommerceSettings::currency($this->context));
        self::assertSame(750, CommerceSettings::taxFlatRateBps($this->context));
        self::assertSame('THL-{seq}', CommerceSettings::orderNumberFormat($this->context));
        self::assertSame(120, CommerceSettings::orderExpiryMinutes($this->context));
        self::assertSame(7, CommerceSettings::cartTtlDays($this->context));
        self::assertSame(10, CommerceSettings::lowStockThreshold($this->context));
    }

    public function testNullOverrideFallsBackPerKey(): void
    {
        // Only currency overridden — every other key must keep its config default.
        $this->bindOverride(['commerce.currency' => 'EUR']);

        self::assertSame('EUR', CommerceSettings::currency($this->context));
        self::assertSame(0, CommerceSettings::taxFlatRateBps($this->context));
        self::assertSame('ORD-{seq}', CommerceSettings::orderNumberFormat($this->context));
    }

    public function testSellerIdentityKeysAreNullableAndOverridable(): void
    {
        // Config default is null-tolerant (unset env) — getters answer null, never ''.
        self::assertNull(CommerceSettings::sellerName($this->context));
        self::assertNull(CommerceSettings::sellerAddress($this->context));
        self::assertNull(CommerceSettings::sellerTaxId($this->context));

        $this->bindOverride([
            'commerce.seller.name' => 'Aurora Lighting Co.',
            'commerce.seller.address' => "12 Osu Lane\nAccra",
            'commerce.seller.tax_id' => 'GH-TIN-0042',
        ]);
        self::assertSame('Aurora Lighting Co.', CommerceSettings::sellerName($this->context));
        self::assertSame("12 Osu Lane\nAccra", CommerceSettings::sellerAddress($this->context));
        self::assertSame('GH-TIN-0042', CommerceSettings::sellerTaxId($this->context));
    }

    public function testMalformedOverridesFallBackDefensively(): void
    {
        $this->bindOverride([
            'commerce.currency' => 'EURO',                 // not a 3-letter code
            'commerce.tax.flat_rate_bps' => 'lots',        // not an int
            'commerce.orders.number_format' => 'NO-SEQ',   // missing {seq}
            'commerce.orders.expiry_minutes' => '',        // blank
        ]);

        self::assertSame('USD', CommerceSettings::currency($this->context));
        self::assertSame(0, CommerceSettings::taxFlatRateBps($this->context));
        self::assertSame('ORD-{seq}', CommerceSettings::orderNumberFormat($this->context));
        self::assertSame(60, CommerceSettings::orderExpiryMinutes($this->context));
    }
}
