<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

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
     * {@see self::manualHold()}/{@see self::manualRelease()} (design spec §2.8, MV5a Task 15)
     * are the operator emergency-override entry points, layered ON TOP of the rolling-reserve
     * lifecycle above -- neither replaces it. Both are always operator-initiated (caller
     * idempotency key + actor + reason are mandatory) and both post through the SAME
     * `reserve_hold`/`reserve_release` ledger machinery every other path in this class uses --
     * never an untracked raw ledger post -- so every existing derive-from-ledger consumer
     * ({@see LedgerRepository::balanceComponents()}'s `reserved`, {@see LedgerRepository::remainingForReserve()},
     * {@see ReserveConsumptionService::consume()}'s FIFO scan) already understands a manual
     * hold with zero code changes there.
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

    // -----------------------------------------------------------------
    // Manual operator hold/release (design spec §2.8, MV5a Task 15).
    // -----------------------------------------------------------------

    /**
     * Emergency operator reserve hold (design spec §2.8): an audited, always-operator-
     * initiated `commerce_seller_reserves` row with `source_kind=manual`, `seller_order_uuid
     * =NULL`, policy snapshots `0`/`0`, and NO `release_at` (indefinite -- it never enters
     * {@see self::releaseDue()}'s scheduled-release sweep, {@see ReserveRepository::dueForRelease()}'s
     * `whereNotNull('release_at')` filter permanently excludes it). Every field this method
     * accepts is validated BEFORE any write ({@see ValidationException}, `422`): a non-empty
     * `idempotencyKey` of at most 128 characters, a non-empty `actor`, a non-empty `reason`,
     * and `amount > 0`.
     *
     * **Idempotency (design spec §2.8):** the unique `(tenant_uuid, idempotency_key)` pair
     * on `commerce_seller_reserves` IS the reserve-row claim -- {@see ReserveRepository::findManualByIdempotencyKey()}
     * is checked FIRST, before any lock or insert is attempted. An EXACT replay (identical
     * `sellerUuid`/`currency`/`amount`/`reason`) returns the pre-existing row untouched, after
     * re-verifying every one of those fields against it ({@see self::verifyManualIdentity()});
     * a CONFLICTING reuse of the SAME key with ANY of those fields different is an operator
     * idempotency-key MISUSE, never a legitimate replay, and throws
     * {@see ManualReserveConflictException} (`409`) -- mirrors this codebase's existing
     * `IdempotencyConflictException`/`CheckoutConflictException` convention for a
     * caller-supplied key reused with different request content. `actor` is deliberately
     * NOT part of this identity check -- it is audit metadata, not conflict identity, so a
     * legitimate operator retry under a different actor (e.g. after a session/token refresh)
     * is still a clean replay, never a `409`. **A verified replay -- up front here, or
     * race-recovered below -- NEVER re-posts to the ledger**: the row's existence already
     * proves its OWN `reserve_hold` entry committed atomically alongside it on the original
     * call (both branches below insert row + ledger entry inside one transaction), and
     * re-posting under the CURRENT caller's actor would collide `created_by` against the
     * ORIGINAL actor under the same deterministic idempotency key --
     * {@see LedgerRepository}'s `VERIFIED_FIELDS` includes `created_by`, so that would throw
     * a spurious {@see LedgerException} instead of the clean no-op this contract promises.
     *
     * A fresh (non-replay) request opens ONE transaction, claims the seller/currency
     * {@see LedgerAccountLock}, inserts the row via {@see ReserveRepository::insertManualHold()}
     * -- which itself degrades cleanly on a genuine insert race, returning whichever row won
     * -- and re-verifies identity against THAT returned row before ever posting to the ledger.
     * ONLY this call's own successful insert (its own generated `uuid` survived) posts the
     * ledger `reserve_hold` entry; a race-recovered row (a DIFFERENT uuid) already had its
     * own entry posted atomically by whichever transaction actually won the insert, so this
     * call skips posting entirely for it -- a race-losing concurrent duplicate can therefore
     * never post a ledger entry under someone else's reserve. The ledger `reserve_hold` entry
     * always carries `payout_uuid=NULL` and `reserve_uuid` = the row's own `uuid` -- never an
     * untracked raw post -- under the DETERMINISTIC key `manual:{idempotencyKey}:reserve_hold`.
     *
     * @return array<string,mixed> the persisted `commerce_seller_reserves` row (freshly
     *     created, or the pre-existing row on a verified exact replay)
     */
    public function manualHold(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $currency,
        int $amount,
        string $idempotencyKey,
        string $actor,
        string $reason
    ): array {
        $this->validateManualHoldInput($amount, $idempotencyKey, $actor, $reason);

        $existing = $this->reserves->findManualByIdempotencyKey($c, $tenant, $idempotencyKey);
        if ($existing !== null) {
            // A verified replay returns the existing row UNTOUCHED -- no re-post. The
            // row's existence already proves its OWN reserve_hold ledger entry committed
            // atomically alongside it on the original call (both branches of this method
            // insert row + ledger entry inside one transaction), so re-posting here would
            // be redundant at best. It would also be actively WRONG: verifyManualIdentity()
            // deliberately excludes `actor` from the replay identity (a legitimate operator
            // retry under a different actor must never 409), but the ledger's own
            // VERIFIED_FIELDS DOES include `created_by` -- re-posting under the CURRENT
            // caller's actor, under the same deterministic `manual:{key}:reserve_hold` key
            // an earlier call may have committed under a DIFFERENT actor, would
            // deterministically throw a LedgerException instead of the clean no-op replay
            // this contract promises.
            $this->verifyManualIdentity($existing, $sellerUuid, $currency, $amount, $reason);

            return $existing;
        }

        $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);
        $uuid = Utils::generateNanoID();

        return db($c)->transaction(function () use (
            $c,
            $tenant,
            $sellerUuid,
            $currency,
            $amount,
            $idempotencyKey,
            $actor,
            $reason,
            $accountKey,
            $uuid
        ): array {
            $this->lock->claim($c, $tenant, $accountKey, $currency);

            $row = $this->reserves->insertManualHold($c, $tenant, [
                'uuid' => $uuid,
                'seller_uuid' => $sellerUuid,
                'currency' => $currency,
                'amount' => $amount,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor,
                'reason' => $reason,
                'held_at' => db($c)->getDriver()->formatDateTime(),
            ]);

            // insertManualHold() may have returned a RACE WINNER's row (a concurrent
            // request that claimed this same idempotency_key first) rather than this
            // call's own insert -- re-verify identity against it before trusting it as a
            // legitimate replay and posting anything under it.
            $this->verifyManualIdentity($row, $sellerUuid, $currency, $amount, $reason);

            // Only THIS call's own fresh insert (its own generated $uuid survived) still
            // needs its reserve_hold posted. A race-recovered row (a DIFFERENT uuid than
            // the one this call attempted to insert) already had its own reserve_hold
            // posted atomically by the transaction that actually won the insert -- see
            // the up-front branch above for why re-posting it here under (possibly) a
            // different actor would be redundant and unsafe.
            if ((string) $row['uuid'] === $uuid) {
                $this->postManualHoldLedgerEntry($c, $tenant, $row, $actor, $reason);
            }

            return $row;
        });
    }

    private function validateManualHoldInput(
        int $amount,
        string $idempotencyKey,
        string $actor,
        string $reason
    ): void {
        if (trim($idempotencyKey) === '') {
            throw ValidationException::forField(
                'idempotency_key',
                'A non-empty idempotency key is required for a manual reserve hold.'
            );
        }
        if (strlen($idempotencyKey) > 128) {
            throw ValidationException::forField(
                'idempotency_key',
                'idempotency_key must be at most 128 characters.'
            );
        }
        if (trim($actor) === '') {
            throw ValidationException::forField('actor', 'A non-empty operator actor is required.');
        }
        if (trim($reason) === '') {
            throw ValidationException::forField('reason', 'A non-empty reason is required.');
        }
        if ($amount <= 0) {
            throw ValidationException::forField('amount', 'Manual reserve hold amount must be greater than zero.');
        }
    }

    /**
     * The manual-hold replay/conflict arbiter (design spec §2.8): every field a reused
     * `idempotencyKey` must reproduce EXACTLY for this to be a legitimate replay rather than
     * a conflicting reuse. `$row` may be either the pre-existing row a fresh call's own
     * up-front lookup found, or the row {@see ReserveRepository::insertManualHold()} itself
     * returned after recovering from a genuine insert race -- either way, the same identity
     * check applies before any ledger post is attempted under it.
     *
     * @param array<string,mixed> $row
     */
    private function verifyManualIdentity(
        array $row,
        string $sellerUuid,
        string $currency,
        int $amount,
        string $reason
    ): void {
        $matches = (string) $row['seller_uuid'] === $sellerUuid
            && (string) $row['currency'] === $currency
            && (int) $row['amount'] === $amount
            && (string) ($row['reason'] ?? '') === $reason;

        if (!$matches) {
            throw new ManualReserveConflictException(sprintf(
                'Manual reserve hold idempotency key "%s" was already used with a different request.',
                (string) $row['idempotency_key']
            ));
        }
    }

    /** @param array<string,mixed> $row */
    private function postManualHoldLedgerEntry(
        ApplicationContext $c,
        string $tenant,
        array $row,
        string $actor,
        string $reason
    ): void {
        $sellerUuid = (string) $row['seller_uuid'];

        $this->ledger->post($c, $tenant, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => (string) $row['currency'],
            'entry_type' => 'reserve_hold',
            'amount' => -((int) $row['amount']),
            'payout_uuid' => null,
            'reserve_uuid' => (string) $row['uuid'],
            'created_by' => $actor,
            'reason' => $reason,
            'idempotency_key' => 'manual:' . $row['idempotency_key'] . ':reserve_hold',
        ]);
    }

    /**
     * Emergency operator reserve release (design spec §2.8): names ANY reserve --
     * `source_kind=manual` (its intended primary use, releasing an indefinite hold early)
     * or `source_kind=rolling` (an operator releasing a not-yet-due rolling hold early,
     * bypassing {@see self::releaseDue()}'s `release_at` gate entirely) -- and releases its
     * LOCKED remaining amount via the exact same derive-from-ledger logic
     * {@see self::releaseDue()} uses: claim the seller/currency {@see LedgerAccountLock},
     * re-read the row fresh under it, and -- only if still `status=held` -- derive
     * `remaining` via {@see LedgerRepository::remainingForReserve()} and post
     * `reserve_release = +remaining` (`payout_uuid=NULL`, `reserve_uuid` set) before
     * CAS-marking the row `released`. A row already out of `held` (already released by an
     * earlier manual release, the scheduled sweep, or fully consumed) is a legitimate no-op
     * -- this method never double-releases.
     *
     * Unlike {@see self::manualHold()}, there is no caller-supplied amount/currency/reason
     * here to conflict on -- the reserve identity (`$reserveUuid`) IS the idempotency key
     * (`manual:{reserve_uuid}:release`), so a replay of this exact call can only ever be a
     * safe no-op, never a mismatch. The releasing `actor` is recorded on the posted
     * `reserve_release` ledger entry itself (`created_by`) -- not overwritten onto the
     * reserve row's own `created_by`, which stays the hold's ORIGINAL creator (the operator
     * who opened a manual hold, or `NULL` for a rolling hold) for audit purposes.
     *
     * @return int the amount released BY THIS CALL (`0` for a no-op replay or a reserve with
     *     no remaining left to release)
     */
    public function manualRelease(ApplicationContext $c, string $tenant, string $reserveUuid, string $actor): int
    {
        if (trim($actor) === '') {
            throw ValidationException::forField('actor', 'A non-empty operator actor is required.');
        }

        $reserve = $this->reserves->findByUuid($c, $tenant, $reserveUuid);
        if ($reserve === null) {
            throw new NotFoundException('Resource not found.');
        }

        $sellerUuid = (string) $reserve['seller_uuid'];
        $currency = (string) $reserve['currency'];
        $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);

        $release = function () use ($c, $tenant, $reserveUuid, $sellerUuid, $currency, $accountKey, $actor): int {
            $this->lock->claim($c, $tenant, $accountKey, $currency);

            $current = $this->reserves->findByUuid($c, $tenant, $reserveUuid);
            if ($current === null) {
                throw new LedgerException(
                    "Manual reserve release failure: reserve '{$reserveUuid}' not found under lock."
                );
            }

            if ((string) $current['status'] !== 'held') {
                return 0;
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
                    'created_by' => $actor,
                    'idempotency_key' => "manual:{$reserveUuid}:release",
                ]);
            }

            $this->reserves->markReleased($c, $tenant, $reserveUuid);

            return $remaining;
        };

        return db($c)->transaction($release);
    }

    // -----------------------------------------------------------------
    // Operator/seller reads (design spec §6, MV5a Task 16).
    // -----------------------------------------------------------------

    /**
     * Every currently-`held` reserve for this seller/currency, each annotated with its
     * OWN derived `remaining` amount (design spec §6, MV5a Task 16): a partially-consumed
     * hold must report what is actually left, never its original `amount` -- the SAME
     * derive-from-ledger discipline every other balance/remaining read in this package
     * uses ({@see LedgerRepository::remainingForReserve()}, never a second mutable
     * balance). Reuses {@see ReserveRepository::heldForConsumption()}'s own FIFO
     * (earliest-`release_at`-first, indefinite manual holds last) ordering -- this is an
     * UNLOCKED read, exactly like every other read in that method's own docblock; a
     * caller mutating a specific hold (manual release, consumption) always re-derives
     * fresh under its own lock, so a stale read here is never a correctness risk.
     *
     * Shared by BOTH the trusted operator read surface (full row detail) and the
     * seller-safe projection ({@see \Glueful\Extensions\Commerce\Http\Seller\SellerFinancialController}'s
     * own allow-list, amount + release_at ONLY) -- this method itself has no opinion on
     * which fields a caller is allowed to expose.
     *
     * @return list<array<string,mixed>> each row is the full `commerce_seller_reserves`
     *     row (id/uuid/source_kind/amount/status/held_at/release_at/closed_at/created_by/
     *     reason/...) PLUS a `remaining` int key
     */
    public function heldWithRemaining(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $currency
    ): array {
        $rows = $this->reserves->heldForConsumption($c, $tenant, $sellerUuid, $currency);

        return array_map(
            fn (array $row): array => $row + [
                'remaining' => $this->ledger->remainingForReserve($c, $tenant, (string) $row['uuid']),
            ],
            $rows
        );
    }
}
