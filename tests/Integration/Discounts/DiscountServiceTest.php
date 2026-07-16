<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Discounts;

use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Validation\ValidationException;

final class DiscountServiceTest extends CommerceTestCase
{
    public function testGlobalUsageLimitIsConditional(): void
    {
        $uuid = $this->seedDiscount(['code' => 'ONCE', 'usage_limit' => 1]);
        $repository = new DiscountRepository();

        self::assertTrue($repository->consumeUsage($this->context, '', $uuid));
        self::assertFalse($repository->consumeUsage($this->context, '', $uuid));
    }

    public function testOncePerBuyerUniqueBlocksSecondRedemption(): void
    {
        $this->seedDiscount(['code' => 'BUYER1', 'once_per_buyer' => 1]);
        $repository = new DiscountRepository();
        $service = new DiscountService($repository, new SentinelTenantResolver());
        $discount = $repository->findByCode($this->context, '', 'BUYER1');
        self::assertNotNull($discount);

        $service->consume($this->context, $discount, 'order0000001', 'buyer@x.test');

        $this->expectException(ValidationException::class);
        $service->consume($this->context, $discount, 'order0000002', 'buyer@x.test');
    }

    public function testNonOnceDiscountRedeemsTwiceForSameBuyer(): void
    {
        $uuid = $this->seedDiscount(['code' => 'MANY', 'once_per_buyer' => 0]);
        $repository = new DiscountRepository();
        $service = new DiscountService($repository, new SentinelTenantResolver());
        $discount = $repository->findByCode($this->context, '', 'MANY');
        self::assertNotNull($discount);

        $service->consume($this->context, $discount, 'order0000003', 'buyer@x.test');
        $service->consume($this->context, $discount, 'order0000004', 'buyer@x.test');

        self::assertCount(
            2,
            $this->connection->table('commerce_discount_redemptions')
                ->where('discount_uuid', '=', $uuid)
                ->get()
        );
    }

    public function testWindowAndMinSubtotalValidation(): void
    {
        $this->seedDiscount([
            'code' => 'EXPIRED',
            'ends_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);
        $repository = new DiscountRepository();
        $service = new DiscountService($repository, new SentinelTenantResolver());
        $discount = $repository->findByCode($this->context, '', 'EXPIRED');
        self::assertNotNull($discount);

        $this->expectException(ValidationException::class);
        $service->validateForCart($this->context, $discount, 10000, []);
    }

    /** @param array<string,mixed> $overrides */
    private function seedDiscount(array $overrides): string
    {
        $uuid = $overrides['uuid'] ?? 'disc' . substr(md5((string) ($overrides['code'] ?? 'CODE')), 0, 8);
        $this->connection->table('commerce_discounts')->insert(array_merge([
            'uuid' => $uuid,
            'code' => 'SAVE10',
            'type' => 'percentage',
            'value' => 1000,
            'usage_limit' => null,
            'once_per_buyer' => 0,
            'usage_count' => 0,
            'status' => 'active',
        ], $overrides));

        return (string) $uuid;
    }
}
