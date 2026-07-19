<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;

/**
 * Rolling-reserve hold posting at settlement (design spec §2.2, MV5a Task
 * 7). {@see self::holdForSettlement()} is called by
 * {@see LedgerPostingService::postSale()} for EVERY settling seller order,
 * from INSIDE `postSale()`'s own seller/currency account lock + transaction,
 * AFTER that seller's `sale_credit`/`commission_debit` are already posted. A
 * seller whose resolved policy is non-zero and whose derived reserve amount
 * is positive gets a durable `commerce_seller_reserves` row PLUS a matching
 * `reserve_hold` ledger entry; everyone else -- policy `0`/`0`, or a reserve
 * base/amount that floors to zero -- posts NOTHING (no row, no ledger
 * noise, design spec §2.1/§2.2 off-invariance).
 *
 * **Reserve base** (design spec §2.2): `attributed_total` already includes
 * allocated shipping and tax, so it is NOT the reserve basis.
 * `merchandise_after_discount = subtotal - allocated_discount` is asserted
 * (a hard {@see LedgerException}, never silently skipped) to equal
 * `attributed_total - allocated_shipping - allocated_tax` -- a mismatch
 * means the seller order's own settlement-shape invariant has drifted, a
 * real bug worth failing loudly on. `reserve_base = max(0,
 * merchandise_after_discount - commission_amount)`; `allocated_shipping_discount`
 * is NEVER subtracted (mirrors MV2's free-shipping invariant).
 * `reserve_amount = floor(reserve_base * reserve_bps / 10000)`.
 *
 * **Degrade safely** (design spec §2.2): a reserve-policy LOOKUP must never
 * break the sale posting that already committed above it in the same
 * transaction. In real settlement, `$sellerOrder['seller_uuid']` always
 * references a seller that genuinely exists (the checkout flow that created
 * the seller order required one), so {@see ReservePolicyService::resolve()}
 * resolving that seller should never throw. This is nonetheless guarded
 * defensively: a {@see NotFoundException} from `resolve()` is caught here
 * and treated as "no reserve policy for this settlement" (skip the hold)
 * rather than allowed to propagate and abort the whole `postSale()`
 * transaction -- also what keeps every pre-MV5a hand-built `postSale()`
 * call site (tests that post a sale for a `seller_uuid` with no
 * corresponding `commerce_sellers` row) byte-identical. This catch is
 * narrowly scoped to `NotFoundException` only -- a genuine settlement-shape
 * integrity failure (the guardrail above, or a replay mismatch below) is a
 * REAL bug and always propagates.
 *
 * **Settlement replay** (design spec §2.2): {@see ReserveRepository::findRollingHold()}
 * is checked FIRST, before resolving policy or computing anything. If a
 * hold already exists for this `(seller_order, seller)`, its own persisted
 * snapshot (`reserve_bps_snapshot`/`reserve_days_snapshot`/`amount`/
 * `held_at`/`release_at`) is re-verified against the seller order's own
 * IMMUTABLE settlement fields and re-posted to the ledger (an idempotent
 * no-op via {@see LedgerRepository::post()}'s own verify) -- `resolve()` is
 * never called again and `release_at` is never recomputed from a fresh
 * `now()`, so a reserve-policy change or wall-clock advance BETWEEN the
 * original hold and the replay can never alter the outcome. A row whose
 * OWN persisted fields are internally inconsistent (e.g. a hand-corrupted
 * `amount` that does not reproduce from its own `reserve_bps_snapshot`) is
 * a genuine conflict and throws.
 *
 * {@see self::releaseDue()} (design spec §2.3, MV5a Task 8) is the SEPARATE
 * scheduled-release entry point. Unlike `holdForSettlement()`, which is
 * always called from INSIDE an already-open caller transaction/lock
 * ({@see LedgerPostingService::postOneSeller()}'s), the release sweep is a
 * top-level entry point with no such caller -- it claims the seller/currency
 * account lock and opens its OWN transaction, mirroring
 * {@see PayoutService}'s `reserve()`/`finalize()` top-level saga steps.
 * `$lock` defaults to a fresh {@see LedgerAccountLock} instance -- a
 * stateless collaborator, so a bare `new ReserveService($a, $b, $c)` (every
 * pre-Task-8 call site, including {@see \Glueful\Extensions\Commerce\Tests\Integration\Marketplace\ReserveHoldTest},
 * none of which ever exercise the release path) keeps building correctly
 * without having to also construct and pass one.
 */
final class ReserveService
{
    public function __construct(
        private ReservePolicyService $reservePolicy,
        private ReserveRepository $reserves,
        private LedgerRepository $ledger,
        private LedgerAccountLock $lock = new LedgerAccountLock(),
    ) {
    }

    /**
     * @param array<string,mixed> $sellerOrder the full `commerce_seller_orders`
     *     row (design spec §2.2): `uuid`, `order_uuid`, `seller_uuid`, `currency`,
     *     `subtotal`, `allocated_discount`, `allocated_shipping`, `allocated_tax`,
     *     `attributed_total`, `commission_amount`, `confirmed_at` are read.
     *     `allocated_shipping_discount` is deliberately never read here (design
     *     spec §2.2: never subtracted from the reserve base).
     */
    public function holdForSettlement(ApplicationContext $c, string $tenant, array $sellerOrder): void
    {
        $sellerUuid = (string) $sellerOrder['seller_uuid'];
        $sellerOrderUuid = (string) $sellerOrder['uuid'];
        $orderUuid = (string) ($sellerOrder['order_uuid'] ?? '');
        $currency = (string) $sellerOrder['currency'];

        $existing = $this->reserves->findRollingHold($c, $tenant, $sellerOrderUuid, $sellerUuid);
        if ($existing !== null) {
            $this->replay($c, $tenant, $sellerOrder, $existing, $sellerUuid, $orderUuid, $currency, $sellerOrderUuid);
            return;
        }

        try {
            $policy = $this->reservePolicy->resolve($c, $tenant, $sellerUuid);
        } catch (NotFoundException) {
            return;
        }

        $reserveBps = $policy['reserve_bps'];
        $reserveDays = $policy['reserve_days'];
        if ($reserveBps === 0 || $reserveDays === 0) {
            return;
        }

        $reserveBase = $this->reserveBase($sellerOrder, $orderUuid, $sellerUuid);
        $reserveAmount = intdiv($reserveBase * $reserveBps, 10000);
        if ($reserveAmount <= 0) {
            return;
        }

        $confirmedAt = $this->requireConfirmedAt($sellerOrder, $orderUuid, $sellerUuid);
        $releaseAt = self::releaseAt($confirmedAt, $reserveDays);

        $inserted = $this->reserves->insertRollingHold($c, $tenant, [
            'uuid' => Utils::generateNanoID(),
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'seller_order_uuid' => $sellerOrderUuid,
            'amount' => $reserveAmount,
            'reserve_bps_snapshot' => $reserveBps,
            'reserve_days_snapshot' => $reserveDays,
            'held_at' => $confirmedAt,
            'release_at' => $releaseAt,
        ]);

        $this->postLedgerHold(
            $c,
            $tenant,
            $orderUuid,
            $sellerUuid,
            $currency,
            $sellerOrderUuid,
            (int) $inserted['amount'],
            (string) $inserted['uuid']
        );
    }

    /**
     * @param array<string,mixed> $sellerOrder
     * @param array<string,mixed> $existing
     */
    private function replay(
        ApplicationContext $c,
        string $tenant,
        array $sellerOrder,
        array $existing,
        string $sellerUuid,
        string $orderUuid,
        string $currency,
        string $sellerOrderUuid
    ): void {
        if ((string) $existing['seller_uuid'] !== $sellerUuid) {
            throw new LedgerException(sprintf(
                'Reserve hold integrity failure (seller order %s): existing hold seller_uuid "%s" '
                    . 'does not match "%s".',
                $sellerOrderUuid,
                (string) $existing['seller_uuid'],
                $sellerUuid
            ));
        }
        if ((string) $existing['currency'] !== $currency) {
            throw new LedgerException(sprintf(
                'Reserve hold integrity failure (seller order %s): existing hold currency "%s" '
                    . 'does not match "%s".',
                $sellerOrderUuid,
                (string) $existing['currency'],
                $currency
            ));
        }

        // The existing row's OWN snapshot is the verdict -- current policy and
        // current time are NEVER recomputed into a second verdict (design spec
        // §2.2). The merchandise math is recomputed only from the seller
        // order's own IMMUTABLE settlement fields against the EXISTING row's
        // OWN snapshotted bps -- never a fresh policy resolve, never `now()` --
        // so a policy change or clock advance between the original hold and
        // this replay can never alter the outcome.
        $reserveBase = $this->reserveBase($sellerOrder, $orderUuid, $sellerUuid);
        $expectedAmount = intdiv($reserveBase * (int) $existing['reserve_bps_snapshot'], 10000);
        if ($expectedAmount !== (int) $existing['amount']) {
            throw new LedgerException(sprintf(
                'Reserve hold integrity failure (seller order %s): existing hold amount %d does not match '
                    . 'the recomputed amount %d for its own snapshotted bps %d.',
                $sellerOrderUuid,
                (int) $existing['amount'],
                $expectedAmount,
                (int) $existing['reserve_bps_snapshot']
            ));
        }

        $confirmedAt = $this->requireConfirmedAt($sellerOrder, $orderUuid, $sellerUuid);
        if (self::normalizeDate($confirmedAt) !== self::normalizeDate((string) $existing['held_at'])) {
            throw new LedgerException(sprintf(
                'Reserve hold integrity failure (seller order %s): existing hold held_at "%s" does not match '
                    . "the seller order's own confirmed_at \"%s\".",
                $sellerOrderUuid,
                (string) $existing['held_at'],
                $confirmedAt
            ));
        }

        $expectedReleaseAt = self::releaseAt($confirmedAt, (int) $existing['reserve_days_snapshot']);
        if (self::normalizeDate($expectedReleaseAt) !== self::normalizeDate((string) $existing['release_at'])) {
            throw new LedgerException(sprintf(
                'Reserve hold integrity failure (seller order %s): existing hold release_at "%s" does not match '
                    . 'the recomputed release_at "%s" for its own snapshotted %d-day window.',
                $sellerOrderUuid,
                (string) $existing['release_at'],
                $expectedReleaseAt,
                (int) $existing['reserve_days_snapshot']
            ));
        }

        $this->postLedgerHold(
            $c,
            $tenant,
            $orderUuid,
            $sellerUuid,
            $currency,
            $sellerOrderUuid,
            (int) $existing['amount'],
            (string) $existing['uuid']
        );
    }

    /** @param array<string,mixed> $sellerOrder */
    private function reserveBase(array $sellerOrder, string $orderUuid, string $sellerUuid): int
    {
        $subtotal = (int) $sellerOrder['subtotal'];
        $allocatedDiscount = (int) $sellerOrder['allocated_discount'];
        $allocatedShipping = (int) $sellerOrder['allocated_shipping'];
        $allocatedTax = (int) $sellerOrder['allocated_tax'];
        $attributedTotal = (int) $sellerOrder['attributed_total'];
        $commissionAmount = (int) ($sellerOrder['commission_amount'] ?? 0);

        $merchandiseAfterDiscount = $subtotal - $allocatedDiscount;
        $expected = $attributedTotal - $allocatedShipping - $allocatedTax;

        if ($merchandiseAfterDiscount !== $expected) {
            throw new LedgerException(sprintf(
                'Reserve base integrity failure (seller order %s, seller %s): merchandise_after_discount %d '
                    . '(subtotal %d - allocated_discount %d) does not equal attributed_total - allocated_shipping '
                    . '- allocated_tax %d (attributed_total %d - allocated_shipping %d - allocated_tax %d).',
                $orderUuid,
                $sellerUuid,
                $merchandiseAfterDiscount,
                $subtotal,
                $allocatedDiscount,
                $expected,
                $attributedTotal,
                $allocatedShipping,
                $allocatedTax
            ));
        }

        return max(0, $merchandiseAfterDiscount - $commissionAmount);
    }

    /** @param array<string,mixed> $sellerOrder */
    private function requireConfirmedAt(array $sellerOrder, string $orderUuid, string $sellerUuid): string
    {
        $confirmedAt = $sellerOrder['confirmed_at'] ?? null;
        if ($confirmedAt === null || $confirmedAt === '') {
            throw new LedgerException(sprintf(
                'Reserve hold failure (seller order %s, seller %s): seller order has no confirmed_at to '
                    . 'base the hold on.',
                $orderUuid,
                $sellerUuid
            ));
        }

        return (string) $confirmedAt;
    }

    /**
     * `release_at = confirmed_at + reserve_days` (design spec §2.2) -- PHP-side date math on
     * the PERSISTED `confirmed_at` string, never the wall clock, mirroring
     * {@see \Glueful\Extensions\Commerce\Cart\CartService}'s `DateTimeImmutable::modify('+N days')` idiom.
     */
    private static function releaseAt(string $confirmedAt, int $reserveDays): string
    {
        return (new \DateTimeImmutable($confirmedAt, new \DateTimeZone('UTC')))
            ->modify('+' . $reserveDays . ' days')
            ->format('Y-m-d H:i:s');
    }

    /** Canonical `Y-m-d H:i:s` comparison form, so a driver-formatted timestamp (which may carry
     * fractional seconds or a `T` separator) compares equal to a PHP-built one. */
    private static function normalizeDate(string $value): string
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function postLedgerHold(
        ApplicationContext $c,
        string $tenant,
        string $orderUuid,
        string $sellerUuid,
        string $currency,
        string $sellerOrderUuid,
        int $amount,
        string $reserveUuid
    ): void {
        $this->ledger->post($c, $tenant, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'entry_type' => 'reserve_hold',
            'amount' => -$amount,
            'order_uuid' => $orderUuid,
            'seller_order_uuid' => $sellerOrderUuid,
            'payout_uuid' => null,
            'reserve_uuid' => $reserveUuid,
            'idempotency_key' => "{$orderUuid}:{$sellerUuid}:reserve_hold",
        ]);
    }

    /**
     * Scheduled reserve-release sweep (design spec §2.3, MV5a Task 8): called per DUE
     * candidate row by {@see \Glueful\Extensions\Commerce\Console\ReservesReleaseSweepCommand},
     * independently -- one row's failure never aborts another's. `$reserve` is the
     * UNLOCKED hint {@see ReserveRepository::dueForRelease()} returned; only its immutable
     * `seller_uuid`/`currency` identity fields are trusted from it (they never change once
     * a reserve row is created) -- everything mutable is re-read fresh AFTER the lock claim
     * below.
     *
     * Opens its own transaction (there is no pre-existing caller transaction the way
     * `holdForSettlement()` has), claims the seller/currency account lock FIRST -- mirrors
     * {@see PayoutService}'s claim-lock-then-rederive idiom, not the payout-row-CAS-then-lock
     * ordering `PayoutService::applyPendingTransition()` uses, because here the resource
     * actually being coordinated (the ledger account) IS what the lock protects -- then
     * re-reads this reserve row UNDER that lock. A row no longer `status='held'` (already
     * released by a concurrent/earlier sweep, or consumed by a later task's chargeback
     * path) is a legitimate no-op: this method never double-releases, by construction.
     *
     * A `held` row has its remaining amount derived via
     * {@see LedgerRepository::remainingForReserve()} -- `max(0, -Σ(reserve_hold +
     * reserve_release))` over every ledger row carrying this `reserve_uuid`, NEVER a stored
     * balance. A positive remaining posts `reserve_release = +remaining` (`payout_uuid =
     * NULL`, `reserve_uuid` set), idempotency key `{reserve_uuid}:scheduled_release`; a
     * `remaining === 0` (already fully consumed by an earlier liability, e.g. a partial
     * chargeback/refund release posted against this SAME `reserve_uuid` before this hold
     * became due) skips the ledger post entirely -- no zero-amount noise. Either way the row
     * is then CAS-marked `released`/`closed_at=now` via {@see ReserveRepository::markReleased()}.
     *
     * Idempotent by construction on a replay of this EXACT method with the SAME row: the
     * second call re-reads `status` as no-longer-`held` (fast path, no re-derive) OR --
     * if somehow re-entered before the first call's own `markReleased()` -- would derive a
     * `remaining` of 0 (the first call's own release entry already lowered it) and post
     * nothing, then lose the `markReleased()` CAS harmlessly. Never a second `reserve_release`
     * row: {@see LedgerRepository::post()}'s own idempotency-key verify is the backstop even
     * if every guard above were somehow bypassed.
     *
     * @param array<string,mixed> $reserve
     * @return array{status: string, released_amount: int} `status` is the row's status
     *     immediately after this call returns (`released`, or whatever non-`held` status a
     *     concurrent path had already moved it to); `released_amount` is the amount THIS
     *     call itself posted (`0` for a no-op replay or a fully-consumed reserve).
     */
    public function releaseDue(ApplicationContext $c, string $tenant, array $reserve): array
    {
        $reserveUuid = (string) $reserve['uuid'];
        $sellerUuid = (string) $reserve['seller_uuid'];
        $currency = (string) $reserve['currency'];
        $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);

        $release = function () use ($c, $tenant, $reserveUuid, $sellerUuid, $currency, $accountKey): array {
            $this->lock->claim($c, $tenant, $accountKey, $currency);

            $current = $this->reserves->findByUuid($c, $tenant, $reserveUuid);
            if ($current === null) {
                throw new LedgerException(
                    "Reserve release failure: reserve '{$reserveUuid}' not found under lock."
                );
            }

            if ((string) $current['status'] !== 'held') {
                return ['status' => (string) $current['status'], 'released_amount' => 0];
            }

            $remaining = $this->ledger->remainingForReserve($c, $tenant, $reserveUuid);
            if ($remaining > 0) {
                $this->ledger->post($c, $tenant, [
                    'account_kind' => 'seller',
                    'account_key' => $accountKey,
                    'seller_uuid' => $sellerUuid,
                    'currency' => $currency,
                    'entry_type' => 'reserve_release',
                    'amount' => $remaining,
                    'payout_uuid' => null,
                    'reserve_uuid' => $reserveUuid,
                    'idempotency_key' => "{$reserveUuid}:scheduled_release",
                ]);
            }

            $this->reserves->markReleased($c, $tenant, $reserveUuid);

            return ['status' => 'released', 'released_amount' => $remaining];
        };

        return db($c)->transaction($release);
    }
}
