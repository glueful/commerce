<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Cart;

use Glueful\Bootstrap\ApplicationContext;

final class CartRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_carts')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_carts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findActiveByTokenHash(ApplicationContext $context, string $tenant, string $tokenHash): ?array
    {
        return db($context)->table('commerce_carts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('token_hash', '=', $tokenHash)
            ->where('status', '=', 'active')
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findActiveForUser(ApplicationContext $context, string $tenant, string $userUuid): ?array
    {
        return db($context)->table('commerce_carts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->where('status', '=', 'active')
            ->orderBy('created_at', 'DESC')
            ->first();
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        $changes['updated_at'] = db($context)->getDriver()->formatDateTime();

        db($context)->table('commerce_carts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($changes);
    }

    /** @return list<array<string,mixed>> */
    public function lines(ApplicationContext $context, string $cartUuid): array
    {
        return db($context)->table('commerce_cart_lines')
            ->where('cart_uuid', '=', $cartUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /** @return array<string,mixed>|null */
    public function findLine(ApplicationContext $context, string $lineUuid): ?array
    {
        return db($context)->table('commerce_cart_lines')
            ->where('uuid', '=', $lineUuid)
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findLineByVariant(ApplicationContext $context, string $cartUuid, string $variantUuid): ?array
    {
        return db($context)->table('commerce_cart_lines')
            ->where('cart_uuid', '=', $cartUuid)
            ->where('variant_uuid', '=', $variantUuid)
            ->first();
    }

    public function insertLine(ApplicationContext $context, string $cartUuid, string $variantUuid, int $quantity): void
    {
        db($context)->table('commerce_cart_lines')->insert([
            'uuid' => \Glueful\Helpers\Utils::generateNanoID(),
            'cart_uuid' => $cartUuid,
            'variant_uuid' => $variantUuid,
            'quantity' => $quantity,
        ]);
    }

    public function setLineQuantity(ApplicationContext $context, string $lineUuid, int $quantity): void
    {
        db($context)->table('commerce_cart_lines')
            ->where('uuid', '=', $lineUuid)
            ->update([
                'quantity' => $quantity,
                'updated_at' => db($context)->getDriver()->formatDateTime(),
            ]);
    }

    public function deleteLine(ApplicationContext $context, string $lineUuid): void
    {
        db($context)->table('commerce_cart_lines')
            ->where('uuid', '=', $lineUuid)
            ->delete();
    }
}
