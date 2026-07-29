<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Wishlist;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;

/**
 * Rows and the parent lock for the account wishlist. No business rules live here: the cap,
 * availability and merge ordering belong to {@see WishlistService}.
 *
 * The `ensureList()` / `claimList()` pair mirrors
 * {@see \Glueful\Extensions\Commerce\Customers\AddressBookRepository}: ensure OUTSIDE any
 * transaction (idempotent, insert-or-ignore against the unique index), claim INSIDE it
 * (affected-row-checked), and only then read-and-mutate. The claimed parent row is what
 * actually serializes two concurrent writes to one account.
 *
 * Every method takes `$tenant` explicitly -- resolution is a service concern, so a repository
 * can never silently read the wrong tenant.
 */
final class WishlistRepository
{
    private const LISTS_TABLE = 'commerce_wishlists';
    private const ITEMS_TABLE = 'commerce_wishlist_items';

    /**
     * Create the parent list row if it does not exist yet.
     *
     * Nested transaction for the same reason as {@see insertAt()}: a unique violation aborts a
     * PostgreSQL transaction, so the winner-row check must run outside the failed statement's
     * scope. Only a VERIFIED duplicate is swallowed -- an unrelated failure here would otherwise
     * surface later as an inexplicable "could not be saved" from the claim.
     */
    public function ensureList(ApplicationContext $context, string $tenant, string $userUuid): void
    {
        try {
            db($context)->transaction(function () use ($context, $tenant, $userUuid): void {
                db($context)->table(self::LISTS_TABLE)->insert([
                    'uuid' => Utils::generateNanoID(12),
                    'tenant_uuid' => $tenant,
                    'user_uuid' => $userUuid,
                    'revision' => 0,
                ]);
            });
        } catch (\PDOException $e) {
            // A losing concurrent ensure simply uses the winner's row.
            if (!$this->hasList($context, $tenant, $userUuid)) {
                throw $e;
            }
        }
    }

    public function hasList(ApplicationContext $context, string $tenant, string $userUuid): bool
    {
        return db($context)->table(self::LISTS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->count() > 0;
    }

    /** Affected-row-checked claim: false means there is no list row to serialize on. */
    public function claimList(ApplicationContext $context, string $tenant, string $userUuid): bool
    {
        $affected = db($context)->table(self::LISTS_TABLE)->executeModification(
            <<<'SQL'
UPDATE commerce_wishlists
SET revision = revision + 1, updated_at = ?
WHERE tenant_uuid = ? AND user_uuid = ?
SQL,
            [db($context)->getDriver()->formatDateTime(), $tenant, $userUuid]
        );

        return $affected === 1;
    }

    /** @return list<array<string,mixed>> display order */
    public function forUser(ApplicationContext $context, string $tenant, string $userUuid): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = db($context)->table(self::ITEMS_TABLE)
            ->select(['uuid', 'product_uuid', 'position', 'created_at'])
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->orderBy('position', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        return $rows;
    }

    /** @return list<string> product uuids in display order */
    public function productUuidsForUser(ApplicationContext $context, string $tenant, string $userUuid): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['product_uuid'],
            $this->forUser($context, $tenant, $userUuid)
        ));
    }

    public function countForUser(ApplicationContext $context, string $tenant, string $userUuid): int
    {
        return (int) db($context)->table(self::ITEMS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->count();
    }

    /** Lowest position in the list, or 0 when empty. Saves go to `frontPosition() - 1`. */
    public function frontPosition(ApplicationContext $context, string $tenant, string $userUuid): int
    {
        return $this->boundary($context, $tenant, $userUuid, 'MIN');
    }

    /** Highest position in the list, or 0 when empty. Imports append from `backPosition() + 1`. */
    public function backPosition(ApplicationContext $context, string $tenant, string $userUuid): int
    {
        return $this->boundary($context, $tenant, $userUuid, 'MAX');
    }

    /**
     * Insert at an explicit position.
     *
     * The insert runs in its OWN nested transaction (a savepoint when the caller already holds
     * one), because PostgreSQL aborts the whole transaction on a unique violation: the
     * duplicate re-check below would itself fail with "current transaction is aborted" if it
     * ran inside the poisoned transaction. Rolling back to the savepoint leaves the caller's
     * transaction usable. Same discipline as
     * {@see \Glueful\Extensions\Commerce\Marketplace\ChargebackRepository::insert()}.
     *
     * Returns false ONLY when the product is already on this list -- verified by re-reading
     * after the failure. Any other error is rethrown: an outage or schema fault reported as
     * "already saved" would silently lose a save the caller believes succeeded.
     */
    public function insertAt(
        ApplicationContext $context,
        string $tenant,
        string $userUuid,
        string $productUuid,
        int $position,
    ): bool {
        $row = [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenant,
            'user_uuid' => $userUuid,
            'product_uuid' => $productUuid,
            'position' => $position,
            'created_at' => db($context)->getDriver()->formatDateTime(),
        ];

        try {
            db($context)->transaction(function () use ($context, $row): void {
                db($context)->table(self::ITEMS_TABLE)->insert($row);
            });
        } catch (\PDOException $e) {
            // Verified duplicate -> idempotent no-op. Anything else is a real failure.
            if ($this->has($context, $tenant, $userUuid, $productUuid)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public function has(ApplicationContext $context, string $tenant, string $userUuid, string $productUuid): bool
    {
        return db($context)->table(self::ITEMS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->where('product_uuid', '=', $productUuid)
            ->count() > 0;
    }

    public function remove(ApplicationContext $context, string $tenant, string $userUuid, string $productUuid): bool
    {
        $affected = db($context)->table(self::ITEMS_TABLE)->executeModification(
            <<<'SQL'
DELETE FROM commerce_wishlist_items
WHERE tenant_uuid = ? AND user_uuid = ? AND product_uuid = ?
SQL,
            [$tenant, $userUuid, $productUuid]
        );

        return $affected > 0;
    }

    private function boundary(ApplicationContext $context, string $tenant, string $userUuid, string $fn): int
    {
        /** @var array<string,mixed>|null $row */
        $row = db($context)->table(self::ITEMS_TABLE)
            ->selectRaw($fn . '(position) AS boundary')
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->first();

        $value = $row['boundary'] ?? null;

        return $value === null ? 0 : (int) $value;
    }
}
