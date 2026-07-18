<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;

/**
 * Append-only settlement ledger (design spec §2.5) -- the single financial
 * source of truth every posting/balance/reconciliation task builds on. Rows
 * are NEVER updated or deleted; a correction is always a new, compensating
 * entry. `amount` is a SIGNED bigint in minor units.
 *
 * Idempotency arbiter: the unique `(tenant_uuid, idempotency_key)`
 * constraint. A duplicate-key `\PDOException` on {@see self::post()} is
 * resolved by re-reading the existing row and VERIFYING it matches the
 * replayed entry on every immutable semantic field -- a match is an
 * idempotent no-op, ANY mismatch is an integrity failure
 * ({@see LedgerException}), and a second row for the same key is never
 * inserted either way. This is the same portable duplicate-key probe idiom
 * as {@see \Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantService::ensureOne()}
 * and {@see MarketplaceWorkspaceLock::ensureRow()}; unlike those two, this
 * table has no OTHER unique column besides the idempotency pair, so a
 * caught `\PDOException` here has exactly one possible cause once the
 * re-read confirms a row exists.
 */
final class LedgerRepository
{
    /** design spec §2.5: the marketplace account's canonical, literal key. */
    public const MARKETPLACE_ACCOUNT_KEY = 'marketplace';

    /** Every immutable semantic field a duplicate idempotency-key insert must match exactly. */
    private const VERIFIED_FIELDS = [
        'amount',
        'currency',
        'account_kind',
        'account_key',
        'seller_uuid',
        'entry_type',
        'order_uuid',
        'seller_order_uuid',
        'refund_uuid',
        'payout_uuid',
        'reason',
        'created_by',
    ];

    /** design spec §2.5: canonical seller account key, `seller:{uuid}`. */
    public static function accountKeyForSeller(string $sellerUuid): string
    {
        return 'seller:' . $sellerUuid;
    }

    /**
     * The account-identity invariant (§2.5): a `seller` account has a non-null
     * `seller_uuid` and `account_key` = `seller:{seller_uuid}`; the `marketplace`
     * account has a null `seller_uuid` and `account_key` = `marketplace`. Any
     * other combination is an integrity failure.
     */
    private function assertAccountIdentity(string $accountKind, string $accountKey, ?string $sellerUuid): void
    {
        $valid = match ($accountKind) {
            'seller' => $sellerUuid !== null && $sellerUuid !== ''
                && $accountKey === self::accountKeyForSeller($sellerUuid),
            'marketplace' => $sellerUuid === null && $accountKey === self::MARKETPLACE_ACCOUNT_KEY,
            default => false,
        };
        if (!$valid) {
            throw new LedgerException(sprintf(
                'Inconsistent ledger account identity: account_kind=%s account_key=%s seller_uuid=%s',
                $accountKind,
                $accountKey,
                $sellerUuid ?? 'null'
            ));
        }
    }

    /**
     * Append-only insert with deterministic idempotency (design spec §2.5).
     * A duplicate `(tenant_uuid, idempotency_key)` triggers a VERIFY against
     * every field in {@see self::VERIFIED_FIELDS}: all match => idempotent
     * no-op (returns normally); any mismatch => {@see LedgerException}.
     * NEVER inserts a second row for the same key.
     *
     * @param array{
     *     account_kind: string,
     *     account_key: string,
     *     seller_uuid?: string|null,
     *     currency: string,
     *     entry_type: string,
     *     amount: int,
     *     order_uuid?: string|null,
     *     seller_order_uuid?: string|null,
     *     refund_uuid?: string|null,
     *     payout_uuid?: string|null,
     *     idempotency_key: string,
     *     reason?: string|null,
     *     created_by?: string|null
     * } $entry
     */
    public function post(ApplicationContext $context, string $tenant, array $entry): void
    {
        // Enforce the account-identity invariant at the single chokepoint every
        // ledger entry flows through (§2.5). The migration-012 CHECK covers
        // PostgreSQL/SQLite, but the framework's MySQL generator omits column
        // checks -- and since balanceComponents() keys on account_key, an
        // inconsistent identity would misattribute money to the wrong account.
        // This service-level guard is the only enforcement possible on every driver.
        $this->assertAccountIdentity(
            (string) $entry['account_kind'],
            (string) $entry['account_key'],
            $entry['seller_uuid'] ?? null
        );

        $row = [
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $tenant,
            'account_key' => $entry['account_key'],
            'account_kind' => $entry['account_kind'],
            'seller_uuid' => $entry['seller_uuid'] ?? null,
            'currency' => $entry['currency'],
            'entry_type' => $entry['entry_type'],
            'amount' => $entry['amount'],
            'order_uuid' => $entry['order_uuid'] ?? null,
            'seller_order_uuid' => $entry['seller_order_uuid'] ?? null,
            'refund_uuid' => $entry['refund_uuid'] ?? null,
            'payout_uuid' => $entry['payout_uuid'] ?? null,
            'idempotency_key' => $entry['idempotency_key'],
            'reason' => $entry['reason'] ?? null,
            'created_by' => $entry['created_by'] ?? null,
        ];

        try {
            db($context)->transaction(function () use ($context, $row): void {
                db($context)->table('commerce_marketplace_ledger')->insert($row);
            });
        } catch (\PDOException $e) {
            $existing = $this->findByIdempotencyKey($context, $tenant, (string) $row['idempotency_key']);
            if ($existing === null) {
                // Unrelated failure (see class docblock) -- never swallowed as a
                // verified duplicate.
                throw $e;
            }

            $this->verify($tenant, $existing, $row);
        }
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $row
     */
    private function verify(string $tenant, array $existing, array $row): void
    {
        foreach (self::VERIFIED_FIELDS as $field) {
            $existingValue = $existing[$field] ?? null;
            $newValue = $row[$field] ?? null;

            $matches = $field === 'amount'
                ? (int) $existingValue === (int) $newValue
                : $this->normalize($existingValue) === $this->normalize($newValue);

            if (!$matches) {
                $key = (string) $row['idempotency_key'];
                throw new LedgerException(
                    "Ledger integrity failure (tenant {$tenant}, idempotency_key {$key}): "
                        . "field \"{$field}\" mismatch on replay -- "
                        . 'existing=' . var_export($existingValue, true) . ', '
                        . 'replayed=' . var_export($newValue, true) . '.'
                );
            }
        }
    }

    private function normalize(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** @return array<string,mixed>|null */
    private function findByIdempotencyKey(ApplicationContext $context, string $tenant, string $idempotencyKey): ?array
    {
        return db($context)->table('commerce_marketplace_ledger')
            ->where('tenant_uuid', '=', $tenant)
            ->where('idempotency_key', '=', $idempotencyKey)
            ->first();
    }

    /**
     * The design spec §2.9 exact sign formulas, currency-scoped. `amount` is
     * signed; `commission_debit`/`refund_debit`/`reserve_hold`/`payout_debit`
     * are stored NEGATIVE, so the `-S(...)` forms below present a positive
     * magnitude. One `GROUP BY entry_type` scan (never per-component
     * queries) so every component is computed from the exact same read.
     *
     * §2.4 (MV4): `reserve_hold`/`reserve_release` are REUSED for payout
     * accounting -- the row's `payout_uuid` disambiguates which balance
     * component it belongs to. A conditional `SUM(CASE WHEN payout_uuid IS
     * NULL ...)` pair (portable across SQLite/PostgreSQL/MySQL) buckets each
     * entry_type's total by payout-reference within the SAME grouped scan,
     * so `pending`/`reserved` never cost a second round-trip:
     *   - `pending`  = -(Σ reserve_hold + Σ reserve_release WHERE payout_uuid IS NOT NULL)
     *   - `reserved` = -(Σ reserve_hold + Σ reserve_release WHERE payout_uuid IS NULL)
     * `available` (SUM over every entry, hold included) and `paid_out` are
     * unaffected by the split.
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
     *     adjustments: int
     * }
     */
    public function balanceComponents(
        ApplicationContext $context,
        string $tenant,
        string $accountKey,
        string $currency
    ): array {
        $rows = db($context)->table('commerce_marketplace_ledger')
            ->selectRaw(
                'entry_type, SUM(amount) as total, '
                . 'SUM(CASE WHEN payout_uuid IS NULL THEN amount ELSE 0 END) as total_no_payout, '
                . 'SUM(CASE WHEN payout_uuid IS NOT NULL THEN amount ELSE 0 END) as total_with_payout'
            )
            ->where('tenant_uuid', '=', $tenant)
            ->where('account_key', '=', $accountKey)
            ->where('currency', '=', $currency)
            ->groupBy('entry_type')
            ->get();

        /** @var array<string,int> $sums entry_type => SUM(amount) over every entry */
        $sums = [];
        /** @var array<string,int> $sumsNoPayout entry_type => SUM(amount) WHERE payout_uuid IS NULL */
        $sumsNoPayout = [];
        /** @var array<string,int> $sumsWithPayout entry_type => SUM(amount) WHERE payout_uuid IS NOT NULL */
        $sumsWithPayout = [];
        $available = 0;
        foreach ($rows as $row) {
            $type = (string) $row['entry_type'];
            $total = (int) $row['total'];
            $sums[$type] = $total;
            $sumsNoPayout[$type] = (int) $row['total_no_payout'];
            $sumsWithPayout[$type] = (int) $row['total_with_payout'];
            $available += $total;
        }

        $s = static fn (string $type): int => $sums[$type] ?? 0;
        $sNoPayout = static fn (string $type): int => $sumsNoPayout[$type] ?? 0;
        $sWithPayout = static fn (string $type): int => $sumsWithPayout[$type] ?? 0;

        return [
            'available' => $available,
            'pending' => -($sWithPayout('reserve_hold') + $sWithPayout('reserve_release')),
            'reserved' => -($sNoPayout('reserve_hold') + $sNoPayout('reserve_release')),
            'paid_out' => -($s('payout_debit') + $s('payout_reversal')),
            'gross_sales' => $s('sale_credit'),
            'commission' => -$s('commission_debit'),
            'refunds' => -$s('refund_debit'),
            'commission_reversed' => $s('commission_reversal'),
            'adjustments' => $s('adjustment'),
        ];
    }

    /**
     * The distinct currencies an account has ANY ledger entries in (§2.9:
     * balances are currency-separated, so a seller with USD and EUR entries
     * has two independent balances). Task 8 ({@see SellerBalanceService})
     * consumer -- kept here rather than duplicated because it is a query
     * over the SAME table `balanceComponents()` reads, not balance math.
     *
     * @return list<string>
     */
    public function currenciesForAccount(ApplicationContext $context, string $tenant, string $accountKey): array
    {
        $rows = db($context)->table('commerce_marketplace_ledger')
            ->select(['currency'])
            ->distinct()
            ->where('tenant_uuid', '=', $tenant)
            ->where('account_key', '=', $accountKey)
            ->orderBy('currency', 'ASC')
            ->get();

        return array_map(static fn (array $row): string => (string) $row['currency'], $rows);
    }

    /** Reconciliation scan (design spec §2.11): every posting for an order, oldest first. @return list<array<string,mixed>> */
    public function entriesForOrder(ApplicationContext $context, string $tenant, string $orderUuid): array
    {
        return db($context)->table('commerce_marketplace_ledger')
            ->where('tenant_uuid', '=', $tenant)
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /** Reconciliation scan (design spec §2.11): every posting for a refund, oldest first. @return list<array<string,mixed>> */
    public function entriesForRefund(ApplicationContext $context, string $tenant, string $refundUuid): array
    {
        return db($context)->table('commerce_marketplace_ledger')
            ->where('tenant_uuid', '=', $tenant)
            ->where('refund_uuid', '=', $refundUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /** Reconciliation scan (design spec §2.11): every posting for a payout, oldest first. @return list<array<string,mixed>> */
    public function entriesForPayout(ApplicationContext $context, string $tenant, string $payoutUuid): array
    {
        return db($context)->table('commerce_marketplace_ledger')
            ->where('tenant_uuid', '=', $tenant)
            ->where('payout_uuid', '=', $payoutUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }
}
