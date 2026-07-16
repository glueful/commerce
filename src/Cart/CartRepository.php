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

    /**
     * Serializes a cart mutation without changing its lifecycle state.
     * The timestamp stamp acquires the cart row lock for the surrounding transaction.
     */
    public function claimActive(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $affected = db($context)->table('commerce_carts')->executeModification(
            <<<'SQL'
UPDATE commerce_carts
SET updated_at = ?
WHERE tenant_uuid = ? AND uuid = ? AND status = 'active'
SQL,
            [db($context)->getDriver()->formatDateTime(), $tenant, $uuid]
        );

        return $affected === 1;
    }

    /**
     * Claims checkout ownership of an active cart. A rollback restores `active`.
     */
    public function convertIfActive(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $affected = db($context)->table('commerce_carts')->executeModification(
            <<<'SQL'
UPDATE commerce_carts
SET status = 'converted', updated_at = ?
WHERE tenant_uuid = ? AND uuid = ? AND status = 'active'
SQL,
            [db($context)->getDriver()->formatDateTime(), $tenant, $uuid]
        );

        return $affected === 1;
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
        $rows = db($context)->table('commerce_cart_lines')
            ->where('cart_uuid', '=', $cartUuid)
            ->orderBy('id', 'ASC')
            ->get();

        return array_map(fn (array $row): array => $this->decodeJson($row), $rows);
    }

    /** @return array<string,mixed>|null */
    public function findLine(ApplicationContext $context, string $lineUuid): ?array
    {
        $row = db($context)->table('commerce_cart_lines')
            ->where('uuid', '=', $lineUuid)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /**
     * Line identity: cart + variant + add-ons hash. `$addonsHash` is `''` for the
     * legacy no-addons path (the composite unique's default), so this replaces the
     * old variant-only lookup exactly for that case while also finding the correct
     * one of several hashed lines for the same variant.
     *
     * @return array<string,mixed>|null
     */
    public function findLineByVariantAndHash(
        ApplicationContext $context,
        string $cartUuid,
        string $variantUuid,
        string $addonsHash
    ): ?array {
        $row = db($context)->table('commerce_cart_lines')
            ->where('cart_uuid', '=', $cartUuid)
            ->where('variant_uuid', '=', $variantUuid)
            ->where('addons_hash', '=', $addonsHash)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /**
     * Aggregate quantity for a variant across EVERY add-on hash in this cart --
     * splitting a variant into several configured (hashed) lines must not let a
     * stock check see only one line's quantity.
     */
    public function totalQuantityForVariant(ApplicationContext $context, string $cartUuid, string $variantUuid): int
    {
        $rows = db($context)->table('commerce_cart_lines')
            ->where('cart_uuid', '=', $cartUuid)
            ->where('variant_uuid', '=', $variantUuid)
            ->get();

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['quantity'];
        }

        return $total;
    }

    /**
     * @param list<array<string,mixed>> $snapshot canonical AddonSnapshot entries; [] for none
     */
    public function insertLine(
        ApplicationContext $context,
        string $cartUuid,
        string $variantUuid,
        int $quantity,
        array $snapshot = [],
        string $addonsHash = ''
    ): void {
        db($context)->table('commerce_cart_lines')->insert([
            'uuid' => \Glueful\Helpers\Utils::generateNanoID(),
            'cart_uuid' => $cartUuid,
            'variant_uuid' => $variantUuid,
            'quantity' => $quantity,
            'addons' => $snapshot === [] ? null : json_encode($snapshot, JSON_THROW_ON_ERROR),
            'addons_hash' => $addonsHash,
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

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeJson(array $row): array
    {
        if (isset($row['addons']) && is_string($row['addons']) && $row['addons'] !== '') {
            $decoded = json_decode($row['addons'], true);
            $row['addons'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['addons'] = [];
        }

        return $row;
    }
}
