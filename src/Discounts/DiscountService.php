<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Discounts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Validation\ValidationException;

final class DiscountService
{
    public function __construct(
        private DiscountRepository $discounts,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /**
     * @param array<string,mixed> $discount
     * @param list<array<string,mixed>> $lines
     */
    public function validateForCart(ApplicationContext $context, array $discount, int $subtotal, array $lines): void
    {
        if (($discount['status'] ?? 'active') !== 'active') {
            throw ValidationException::forField('discount_code', 'Discount is not active.');
        }

        $now = gmdate('Y-m-d H:i:s');
        if (is_string($discount['starts_at'] ?? null) && $discount['starts_at'] > $now) {
            throw ValidationException::forField('discount_code', 'Discount is not active yet.');
        }
        if (is_string($discount['ends_at'] ?? null) && $discount['ends_at'] < $now) {
            throw ValidationException::forField('discount_code', 'Discount has expired.');
        }

        if (isset($discount['min_subtotal']) && $subtotal < (int) $discount['min_subtotal']) {
            throw ValidationException::forField('discount_code', 'Cart subtotal is below the discount minimum.');
        }

        $scope = $discount['product_scope'] ?? null;
        if (is_array($scope) && $this->scopedBase($lines, $scope) <= 0) {
            throw ValidationException::forField('discount_code', 'Discount does not apply to this cart.');
        }
    }

    /** @param array<string,mixed> $discount */
    public function consume(
        ApplicationContext $context,
        array $discount,
        string $orderUuid,
        string $buyerIdentity,
    ): void {
        $tenant = $this->tenants->tenantUuid($context);
        $discountUuid = (string) ($discount['uuid'] ?? '');

        if (!$this->discounts->consumeUsage($context, $tenant, $discountUuid)) {
            throw ValidationException::forField('discount_code', 'Discount is exhausted.');
        }

        $this->discounts->insertRedemption($context, [
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $tenant,
            'discount_uuid' => $discountUuid,
            'order_uuid' => $orderUuid,
            'buyer_identity' => $buyerIdentity,
            'buyer_key' => (int) ($discount['once_per_buyer'] ?? 0) === 1 ? $buyerIdentity : $orderUuid,
        ]);
    }

    public static function buyerIdentity(?string $userUuid, string $email): string
    {
        return $userUuid !== null && $userUuid !== '' ? $userUuid : strtolower($email);
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<int|string,mixed> $scope
     */
    private function scopedBase(array $lines, array $scope): int
    {
        $allowed = array_fill_keys(array_map('strval', $scope), true);
        $base = 0;

        foreach ($lines as $line) {
            $productUuid = (string) ($line['product_uuid'] ?? '');
            if (!isset($allowed[$productUuid])) {
                continue;
            }

            $base += (int) ($line['unit_price'] ?? 0) * (int) ($line['quantity'] ?? 0);
        }

        return $base;
    }
}
