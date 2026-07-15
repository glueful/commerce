<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Customers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;

/**
 * Address book data access (design spec §2): the `commerce_customer_address_books`
 * parent row per (tenant, user) plus its `commerce_customer_addresses` children.
 *
 * Parent-claim discipline: {@see self::ensureBook()} MUST be called OUTSIDE the
 * caller's mutation transaction (or under an explicit savepoint) — it is the
 * same idempotent insert-or-ignore idiom as
 * {@see \Glueful\Extensions\Commerce\Inventory\StockRepository::ensureRow()},
 * relying entirely on the unique (tenant_uuid, user_uuid) index as the
 * concurrency guard. Catching a PostgreSQL unique-violation INSIDE an open
 * transaction aborts that transaction outright, so this insert attempt (and
 * its catch) must complete, one way or the other, before any transaction
 * begins. Once the parent is known to exist, every mutation claims it via
 * {@see self::claimBook()} — the same `catalog_revision`-style
 * affected-row-checked UPDATE
 * {@see \Glueful\Extensions\Commerce\Catalog\ProductRepository::claimCatalogRevision()}
 * uses — INSIDE its transaction: the claimed row lock is what actually
 * serializes two concurrent first-address (or default-swap) requests against
 * the same account onto the same parent row, not the counter itself.
 */
final class AddressBookRepository
{
    private const BOOKS_TABLE = 'commerce_customer_address_books';
    private const ADDRESSES_TABLE = 'commerce_customer_addresses';

    // -----------------------------------------------------------------
    // Parent (address book)
    // -----------------------------------------------------------------

    public function ensureBook(ApplicationContext $context, string $tenant, string $userUuid): void
    {
        try {
            db($context)->table(self::BOOKS_TABLE)->insert([
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => $tenant,
                'user_uuid' => $userUuid,
                'revision' => 0,
            ]);
        } catch (\Throwable) {
            // The unique (tenant_uuid, user_uuid) index is the idempotency guard --
            // a losing concurrent ensure reloads the winner's row below.
        }
    }

    /** @return array<string,mixed>|null */
    public function findBook(ApplicationContext $context, string $tenant, string $userUuid): ?array
    {
        return db($context)->table(self::BOOKS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->first();
    }

    /**
     * Affected-row-checked claim: bumps `revision` first, inside the caller's
     * transaction. Returns false only if the book row is missing at claim
     * time -- it must have been ensured via {@see self::ensureBook()}
     * immediately before the transaction began, so a false here signals a
     * genuine anomaly rather than an expected outcome.
     */
    public function claimBook(ApplicationContext $context, string $tenant, string $userUuid): bool
    {
        $affected = db($context)->table(self::BOOKS_TABLE)->executeModification(
            <<<'SQL'
UPDATE commerce_customer_address_books
SET revision = revision + 1, updated_at = ?
WHERE tenant_uuid = ? AND user_uuid = ?
SQL,
            [db($context)->getDriver()->formatDateTime(), $tenant, $userUuid]
        );

        return $affected === 1;
    }

    // -----------------------------------------------------------------
    // Addresses
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table(self::ADDRESSES_TABLE)->insert($this->encodeJson($row));
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $userUuid, string $uuid): ?array
    {
        $row = db($context)->table(self::ADDRESSES_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /** @return list<array<string,mixed>> */
    public function forUser(ApplicationContext $context, string $tenant, string $userUuid): array
    {
        $rows = db($context)->table(self::ADDRESSES_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->orderBy('created_at', 'ASC')
            ->get();

        return array_map(fn (array $row): array => $this->decodeJson($row), $rows);
    }

    /** @param array<string,mixed> $changes */
    public function update(
        ApplicationContext $context,
        string $tenant,
        string $userUuid,
        string $uuid,
        array $changes
    ): void {
        $changes['updated_at'] = db($context)->getDriver()->formatDateTime();

        db($context)->table(self::ADDRESSES_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->where('uuid', '=', $uuid)
            ->update($this->encodeJson($changes));
    }

    public function delete(ApplicationContext $context, string $tenant, string $userUuid, string $uuid): void
    {
        db($context)->table(self::ADDRESSES_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->where('uuid', '=', $uuid)
            ->delete();
    }

    /**
     * The "clear" half of clear-then-set (design spec §2), always run inside
     * the caller's already-claimed transaction. $exceptUuid excludes the row
     * being promoted to default (update path only -- create always omits it,
     * since the new row doesn't exist yet).
     */
    public function clearDefaultShipping(
        ApplicationContext $context,
        string $tenant,
        string $userUuid,
        ?string $exceptUuid = null
    ): void {
        $query = db($context)->table(self::ADDRESSES_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->where('is_default_shipping', '=', true);
        if ($exceptUuid !== null) {
            $query->where('uuid', '!=', $exceptUuid);
        }

        $query->update(['is_default_shipping' => false]);
    }

    public function clearDefaultBilling(
        ApplicationContext $context,
        string $tenant,
        string $userUuid,
        ?string $exceptUuid = null
    ): void {
        $query = db($context)->table(self::ADDRESSES_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->where('is_default_billing', '=', true);
        if ($exceptUuid !== null) {
            $query->where('uuid', '!=', $exceptUuid);
        }

        $query->update(['is_default_billing' => false]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function encodeJson(array $row): array
    {
        if (isset($row['address']) && is_array($row['address'])) {
            $row['address'] = json_encode($row['address'], JSON_THROW_ON_ERROR);
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeJson(array $row): array
    {
        if (isset($row['address']) && is_string($row['address']) && $row['address'] !== '') {
            $decoded = json_decode($row['address'], true);
            $row['address'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }
}
