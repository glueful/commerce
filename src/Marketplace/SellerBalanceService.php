<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;

/**
 * The derived, currency-separated seller (and marketplace) balance (design
 * spec §2.9, MV3 Task 8). NEVER a stored/mutable balance -- every call is a
 * fresh `SUM` over the append-only `commerce_marketplace_ledger`, delegated
 * straight through to {@see LedgerRepository::balanceComponents()}, which
 * already carries the exact sign formulas. This class is a thin,
 * correctly-scoped wrapper: it constructs the canonical account key
 * ({@see LedgerRepository::accountKeyForSeller()} /
 * {@see LedgerRepository::MARKETPLACE_ACCOUNT_KEY}) and enumerates
 * currencies -- it never re-derives or duplicates the SUM math.
 */
final class SellerBalanceService
{
    public function __construct(private readonly LedgerRepository $ledger)
    {
    }

    /**
     * The full §2.9 component set for a seller's `seller:{uuid}` account,
     * scoped to a single currency. `pending` (MV4 §2.4) is the seller's
     * in-flight payout holds -- `reserve_hold`/`reserve_release` entries
     * carrying a `payout_uuid` -- distinct from `reserved` (MV5 risk holds,
     * the same entry types with no `payout_uuid`).
     *
     * @return array{
     *     available: int,
     *     pending: int,
     *     reserved: int,
     *     paid_out: int,
     *     gross_sales: int,
     *     commission: int,
     *     refunds: int,
     *     commission_reversed: int,
     *     adjustments: int,
     *     debt: int
     * }
     */
    public function balance(ApplicationContext $context, string $tenant, string $sellerUuid, string $currency): array
    {
        return $this->ledger->balanceComponents(
            $context,
            $tenant,
            LedgerRepository::accountKeyForSeller($sellerUuid),
            $currency
        );
    }

    /**
     * Convenience for the Task 9 payout check: just the `available`
     * component -- what a payout may draw against.
     */
    public function available(ApplicationContext $context, string $tenant, string $sellerUuid, string $currency): int
    {
        return $this->balance($context, $tenant, $sellerUuid, $currency)['available'];
    }

    /**
     * The marketplace account's own components (design spec §2.9, operator
     * surfaces -- Task 11), independent of any seller account.
     *
     * @return array{
     *     available: int,
     *     pending: int,
     *     reserved: int,
     *     paid_out: int,
     *     gross_sales: int,
     *     commission: int,
     *     refunds: int,
     *     commission_reversed: int,
     *     adjustments: int,
     *     debt: int
     * }
     */
    public function marketplaceBalance(ApplicationContext $context, string $tenant, string $currency): array
    {
        return $this->ledger->balanceComponents(
            $context,
            $tenant,
            LedgerRepository::MARKETPLACE_ACCOUNT_KEY,
            $currency
        );
    }

    /**
     * Every distinct currency a seller has ledger entries in -- balances are
     * per-currency (§2.9), so a seller with both USD and EUR entries has two
     * independent balances; this enumerates which currencies to even ask for.
     *
     * @return list<string>
     */
    public function currencies(ApplicationContext $context, string $tenant, string $sellerUuid): array
    {
        return $this->ledger->currenciesForAccount(
            $context,
            $tenant,
            LedgerRepository::accountKeyForSeller($sellerUuid)
        );
    }
}
