<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Discounts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Discount admin CRUD (list/show/update/delete) plus the checkout-facing
 * validate/consume pair.
 *
 * `commerce_discounts.revision` (folded, Layer 6) is claimed
 * ({@see DiscountRepository::claimRevision()}) directly by BOTH admin PATCH and
 * DELETE -- the URL's primary resource -- so a delete-vs-PATCH race has one
 * winner: whichever transaction's claim UPDATE commits first holds the row
 * lock. A PATCH racing a delete either claims first (and the delete's own
 * claim then affects zero rows -- the row is gone -- so delete 404s and PATCH
 * proceeds) or blocks on the delete's held claim and, once the delete commits
 * the hard DELETE, its own claim UPDATE affects zero rows -- a checked
 * affected-row count, never a false-success update on a vanished row.
 *
 * DELETE additionally serializes against checkout redemption through the SAME
 * claimed row, without any extra locking primitive: {@see DiscountRepository::consumeUsage()}
 * (called from {@see self::consume()}) is itself an `UPDATE ... WHERE tenant_uuid
 * = ? AND uuid = ? AND (usage_limit IS NULL OR usage_count < usage_limit)` against
 * this exact row, run inside checkout's order-placement transaction before it
 * inserts the redemption row. Two orderings, both race-safe:
 *   - Delete's claim commits FIRST: checkout's `consumeUsage()` UPDATE, once
 *     unblocked, matches zero rows (the row is gone) -- `consume()` throws and
 *     checkout rolls back the whole order.
 *   - Checkout's `consumeUsage()` commits FIRST (still inside checkout's
 *     transaction, redemption row inserted before commit): delete's claim,
 *     once unblocked, succeeds (the row still exists) but the post-claim
 *     {@see DiscountRepository::hasRedemptions()} probe -- run in the SAME
 *     transaction, after the claim -- now sees the committed redemption and
 *     refuses with {@see DiscountRedeemedException} (409, "disable via status"
 *     hint); the row is left completely intact.
 * Historical orders are unaffected either way -- they snapshot `discount_code`
 * rather than referencing this row. The real two-connection interleaving is
 * exercised by the pgsql-gated lane; this class's tests pin both orderings
 * deterministically (claim/consume calls issued in a fixed sequence).
 */
final class DiscountService
{
    public function __construct(
        private DiscountRepository $discounts,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /**
     * @param array<string,mixed> $filters 'status' (exact) and/or 'q' (code literal substring)
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function list(ApplicationContext $c, array $filters, int $page, int $perPage): array
    {
        return $this->discounts->paginatedFor($c, $this->tenants->tenantUuid($c), $filters, $page, $perPage);
    }

    /** @return array<string,mixed> */
    public function show(ApplicationContext $c, string $uuid): array
    {
        $discount = $this->discounts->findByUuid($c, $this->tenants->tenantUuid($c), $uuid);
        if ($discount === null) {
            throw new NotFoundException('Resource not found.');
        }

        return $discount;
    }

    /**
     * Guarded PATCH: claims the discount (checked affected rows) before
     * applying already-validated partial changes, so a concurrent delete can
     * never yield a false-success update (class docblock).
     *
     * @param array<string,mixed> $changes already-validated partial changes
     * @return array<string,mixed>
     */
    public function update(ApplicationContext $c, string $uuid, array $changes): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        return db($c)->transaction(function () use ($c, $tenant, $uuid, $changes): array {
            if (!$this->discounts->claimRevision($c, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }
            if ($this->discounts->findByUuid($c, $tenant, $uuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            if ($changes !== []) {
                $this->discounts->update($c, $tenant, $uuid, $changes);
            }

            $discount = $this->discounts->findByUuid($c, $tenant, $uuid);
            if ($discount === null) {
                throw new \RuntimeException('Updated discount could not be reloaded.');
            }

            return $discount;
        });
    }

    /**
     * Guarded hard delete: claims the discount, then -- post-claim, inside the
     * SAME transaction -- probes for any redemption. See class docblock for the
     * full delete-vs-checkout-redemption race analysis.
     */
    public function delete(ApplicationContext $c, string $uuid): void
    {
        $tenant = $this->tenants->tenantUuid($c);

        db($c)->transaction(function () use ($c, $tenant, $uuid): void {
            if (!$this->discounts->claimRevision($c, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }
            if ($this->discounts->findByUuid($c, $tenant, $uuid) === null) {
                throw new NotFoundException('Resource not found.');
            }
            if ($this->discounts->hasRedemptions($c, $tenant, $uuid)) {
                throw new DiscountRedeemedException(
                    'This discount has been redeemed and cannot be deleted. Disable it via status instead.'
                );
            }

            $this->discounts->delete($c, $tenant, $uuid);
        });
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
