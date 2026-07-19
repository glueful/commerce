<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Shared FIFO reserve consumption (design spec §2.5, MV5a Task 9): the ONE
 * collaborator both chargeback attribution/reversal (Task 11) and refund
 * reserve-first posting (Task 12) call to draw down a seller's unreleased
 * `commerce_seller_reserves` holds before that liability is allowed to drive
 * `available` negative (debt, design spec §2.6).
 *
 * **PRECONDITION -- MUST be called from INSIDE the caller's own
 * ALREADY-CLAIMED seller/currency {@see LedgerAccountLock} claim (and the
 * caller's own open transaction).** This service deliberately has NO
 * `LedgerAccountLock` collaborator and NEVER claims one, and NEVER opens a
 * transaction of its own -- unlike {@see ReserveService::releaseDue()} (a
 * top-level entry point that claims its own lock), `consume()` mirrors
 * {@see ReserveService::holdForSettlement()}'s "always called from inside an
 * already-open caller lock/transaction" contract. Task 11's chargeback
 * posting and Task 12's `postRefund()` each claim their full sorted
 * seller/marketplace account-lock set exactly ONCE, then call `consume()`
 * under that already-held lock for the seller in question. `consume()` must
 * NEVER re-claim or recurse into locking -- {@see LedgerAccountLock::claim()}
 * is not re-entrant (a second claim from inside the same already-open
 * transaction would either spin forever against its own uncommitted revision
 * bump or, on a driver with row-level locking semantics, self-deadlock).
 *
 * **Idempotency model.** Unlike a single {@see LedgerRepository::post()} call
 * (whose OWN duplicate-idempotency-key verify is the idempotency arbiter),
 * `consume()` is a multi-row OPERATION: a retried invocation for the SAME
 * `(liabilityKind, liabilityUuid)` must reproduce the exact same set of
 * per-reserve `reserve_release` postings in the exact same amounts, or a
 * naive re-walk would recompute DIFFERENT slice amounts once any reserve has
 * moved to `status=consumed` (and therefore dropped out of
 * {@see ReserveRepository::heldForConsumption()}'s `held`-only candidate
 * scan) -- `LedgerRepository::post()` would then reject the mismatch as a
 * genuine integrity failure instead of a safe replay. `consume()` therefore
 * checks, UP FRONT, whether this EXACT liability has already posted ANY
 * `reserve_release` for this seller/currency
 * ({@see LedgerRepository::consumedForLiability()}); a non-zero prior total
 * means a full/partial walk already committed in an earlier, already-closed
 * call to this exact method, and THIS call is a pure no-op that returns that
 * SAME total without touching a single row -- never a second release, never
 * a re-derived (and possibly mismatched) slice.
 *
 * This short-circuit is safe because every real caller (Task 11/Task 12)
 * always calls `consume()` with the SAME `$liability` for the SAME
 * `(liabilityKind, liabilityUuid)` -- the liability amount is deterministically
 * derived from immutable order-line/chargeback data, never recomputed
 * differently between an original attempt and a retry -- and because
 * `consume()` always runs inside the CALLER's own transaction: a mid-walk
 * failure rolls back EVERY posting this call made, so a genuine "replay" is
 * always all-or-nothing from a previously fully-committed transaction, never
 * a half-applied one.
 */
final class ReserveConsumptionService
{
    private const CHARGEBACK = 'chargeback';
    private const REFUND = 'refund';

    public function __construct(
        private ReserveRepository $reserves,
        private LedgerRepository $ledger,
    ) {
    }

    /**
     * Consumes up to `$liability` from this seller's `status=held` reserves for
     * `$currency`, earliest-`release_at`-first (design spec §2.5; NULL `release_at` --
     * an indefinite manual hold -- sorts last, see
     * {@see ReserveRepository::heldForConsumption()}). Each affected reserve's LOCKED
     * remaining is re-derived fresh from the ledger
     * ({@see LedgerRepository::remainingForReserve()}) immediately before it is sliced,
     * never assumed from a stale candidate read. A reserve whose slice does not exhaust
     * its remaining stays `held`; a reserve whose slice exactly exhausts it is marked
     * `status=consumed` ({@see ReserveRepository::markConsumed()}). Walking stops once
     * the liability is fully covered or no `held` candidates remain.
     *
     * @param string $liabilityKind `'chargeback'` or `'refund'` -- selects which
     *     ledger correlation column (`chargeback_uuid`/`refund_uuid`) every posted
     *     `reserve_release` in this call carries alongside `reserve_uuid`.
     * @param string $liabilityUuid the chargeback's or refund's own uuid -- the
     *     correlation value, and part of every posted entry's idempotency key
     *     (`{liabilityKind}:{liabilityUuid}:{sellerUuid}:{reserveUuid}:reserve_release`).
     * @return int total consumed -- always `<= $liability`. Less than `$liability`
     *     means the seller's held reserve was insufficient; the CALLER handles the
     *     shortfall (e.g. lets `available` go negative, design spec §2.6). A reserve is
     *     never over-released: each posted slice is capped at that reserve's own
     *     locked, freshly re-derived remaining amount, so `reserved >= 0` always holds.
     */
    public function consume(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $currency,
        int $liability,
        string $liabilityKind,
        string $liabilityUuid
    ): int {
        $correlationColumn = self::correlationColumn($liabilityKind);

        if ($liability <= 0) {
            return 0;
        }

        // Idempotent short-circuit (see class docblock): a non-zero prior total for
        // this EXACT liability means an earlier, already-committed call already ran
        // the FIFO walk -- reproduce that total verbatim, touching nothing.
        $alreadyConsumed = $this->ledger->consumedForLiability(
            $c,
            $tenant,
            $sellerUuid,
            $currency,
            $correlationColumn,
            $liabilityUuid
        );
        if ($alreadyConsumed > 0) {
            return $alreadyConsumed;
        }

        $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);
        $candidates = $this->reserves->heldForConsumption($c, $tenant, $sellerUuid, $currency);

        $liabilityLeft = $liability;
        $totalConsumed = 0;

        foreach ($candidates as $reserve) {
            if ($liabilityLeft <= 0) {
                break;
            }

            $reserveUuid = (string) $reserve['uuid'];
            $remaining = $this->ledger->remainingForReserve($c, $tenant, $reserveUuid);
            if ($remaining <= 0) {
                // Defensive only: a `status=held` row should always carry a positive
                // derived remainder by construction (see class docblock) -- never post
                // a zero/negative slice either way.
                continue;
            }

            $slice = min($remaining, $liabilityLeft);

            $entry = [
                'account_kind' => 'seller',
                'account_key' => $accountKey,
                'seller_uuid' => $sellerUuid,
                'currency' => $currency,
                'entry_type' => 'reserve_release',
                'amount' => $slice,
                'payout_uuid' => null,
                'reserve_uuid' => $reserveUuid,
                'idempotency_key' =>
                    "{$liabilityKind}:{$liabilityUuid}:{$sellerUuid}:{$reserveUuid}:reserve_release",
            ];
            $entry[$correlationColumn] = $liabilityUuid;

            $this->ledger->post($c, $tenant, $entry);

            if ($slice === $remaining) {
                $this->reserves->markConsumed($c, $tenant, $reserveUuid);
            }

            $liabilityLeft -= $slice;
            $totalConsumed += $slice;
        }

        return $totalConsumed;
    }

    private static function correlationColumn(string $liabilityKind): string
    {
        return match ($liabilityKind) {
            self::CHARGEBACK => 'chargeback_uuid',
            self::REFUND => 'refund_uuid',
            default => throw new \InvalidArgumentException(
                "ReserveConsumptionService::consume(): unsupported liabilityKind '{$liabilityKind}' "
                    . "(expected 'chargeback' or 'refund')."
            ),
        };
    }
}
