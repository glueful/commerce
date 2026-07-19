<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Events\PayoutRecorded;
use Glueful\Extensions\Contracts\Payments\PayoutCollector;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;
use Glueful\Extensions\Contracts\Payments\PayoutResult;
use Glueful\Extensions\Contracts\Payments\PayoutStatusResult;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;

/**
 * Atomic manual operator payout (design spec §2.10): a Commerce-local record
 * that an operator confirmed funds already moved to a seller OUTSIDE this
 * system (no provider integration -- that is MV4). {@see self::record()}
 * validates up front, then -- for a fresh (non-replay) request -- runs ONE
 * transaction: claim the seller account lock ({@see LedgerAccountLock},
 * design spec §2.6) -> RECHECK {@see SellerBalanceService::available()}
 * UNDER that lock (the balance-safety guarantee this class exists for -- a
 * concurrent posting to the SAME account cannot race the check, because both
 * go through the SAME lock) -> refuse (`422` {@see PayoutException}) if the
 * amount exceeds it -> insert the `commerce_payouts` row -> post the matching
 * `payout_debit` ledger entry. Any throw inside that transaction (including
 * the balance refusal) rolls back EVERYTHING claimed/written so far -- there
 * is no partial-payout state.
 *
 * Duplicate idempotency (a replayed `idempotencyKey`, whether found by the
 * up-front lookup or discovered via a caught `\PDOException` on the
 * `(tenant_uuid, idempotency_key)` unique constraint after a concurrent
 * winner) is a VERIFY, never a second row: every immutable request field
 * (`seller_uuid`/`currency`/`amount`/`external_ref`/`note`/`created_by`) must
 * match the existing `commerce_payouts` row exactly, AND the matching
 * `payout_debit` ledger entry must itself verify -- delegated straight to
 * {@see LedgerRepository::post()}'s own duplicate-key verify (calling it
 * again with the SAME deterministic idempotency key is GUARANTEED to hit
 * that table's unique-constraint path, since the original transaction
 * already posted it): it either silently no-ops (every ledger field
 * matches) or throws {@see LedgerException}. Any mismatch, anywhere, is an
 * integrity failure ({@see LedgerException}, `500`) -- never silently
 * ignored, never a new row.
 *
 * {@see self::execute()} (design spec §2.3, MV4 Task 7) is the separate
 * provider-payout saga -- reserve -> execute -> finalize -- mirroring
 * {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundService}'s gateway
 * saga: RESERVE (the destination-readiness gate, {@see PayoutAccountService::requireReady()},
 * design spec §2.7, Task 8 -- claim the account lock, re-read `available`
 * under it, insert a `pending`/`method=provider` row, post `reserve_hold`)
 * commits BEFORE any provider I/O; EXECUTE calls `PayoutCollector::transfer()`
 * strictly outside any transaction; FINALIZE claims the payout out of
 * `pending` via {@see PayoutRepository::claimPending()} (the
 * single-finalizer-wins CAS) and applies the result. `$collector` and
 * `$payoutAccounts` are APPENDED OPTIONAL collaborators (soft-bound, same
 * idiom as `RefundService`'s `$collector`) -- every pre-Task-7
 * direct-construction call site (including
 * {@see \Glueful\Extensions\Commerce\Tests\Integration\Marketplace\PayoutTest})
 * stays source-compatible; `record()` (the MV3 manual path) never reads
 * either.
 *
 * MV5b Task 6 (design spec §2.7) adds a REQUIRED {@see SellerRepository}
 * dependency and, with it, a suspended-seller payout freeze -- NEW payout
 * creation only, never in-flight continuation. Inside BOTH {@see self::record()}'s
 * and {@see self::reserve()}'s transaction, the order is strict: **claim the
 * seller revision ({@see SellerRepository::claimRevision()}) -> re-read the
 * seller -> [record() only: repeat the idempotency lookup and return a
 * verified replay if found, even for a non-active seller] -> refuse a
 * non-active seller -> THEN claim the seller/currency ledger account lock**.
 * `claimRevision()` is the SAME primitive {@see SellerService::suspend()}/
 * `reactivate()` claims for the identical seller row, so a new-payout
 * transaction and a concurrent suspend()/reactivate() are strictly
 * serialized against one another -- never interleaved: whichever commits
 * first is authoritative, and since the revision claim happens BEFORE the
 * account lock, a suspended seller is refused (or, for a batch derive-in-lock
 * candidate, silently skipped) WITHOUT ever claiming that lock or posting
 * anything. A `seller_uuid` with NO matching `commerce_sellers` row --
 * `claimRevision()` affects zero rows -- is untracked by the marketplace
 * lifecycle and is never gated by this check, which keeps every pre-MV5b
 * caller that never registered a seller entity (raw ledger-account payouts,
 * marketplace-off paths) byte-identical. `retry()`/`reconcile()` never enter
 * this gate at all -- an already-reserved/committed payout continues
 * regardless of the seller's CURRENT status (design spec §2.7: suspension is
 * prospective, never cancels or strands in-flight money).
 */
final class PayoutService
{
    /** @var callable(): string */
    private $uuidGenerator;

    /**
     * @param (callable(): string)|null $uuidGenerator Injectable seam for tests forcing a
     *     specific payout `uuid` (e.g. to pre-seed a mismatched ledger row under the exact
     *     idempotency key a call will compute, forcing a mid-transaction
     *     {@see LedgerException} that proves atomicity); defaults to the house
     *     {@see Utils::generateNanoID()} generator, the same idiom
     *     {@see MarketplaceWorkspaceLock} already establishes.
     * @param SellerRepository $sellers REQUIRED (MV5b Task 6, design spec §2.7) -- the shared
     *     revision-claim serialization primitive against a concurrent suspend()/reactivate(),
     *     and the source of the seller's CURRENT status for the new-payout freeze gate. Placed
     *     BEFORE the optional seams below (never hidden behind a container lookup or a
     *     nullable fallback): every direct constructor call site, test or production, must
     *     supply one.
     * @param ?PayoutCollector $collector soft-bound provider-payout port (design spec §2.9);
     *     null keeps `execute()` unavailable (422) while `record()`/ledger semantics stay
     *     fully functional.
     * @param ?PayoutAccountService $payoutAccounts soft-bound destination-readiness gate
     *     (design spec §2.7, Task 8); null keeps `execute()` unavailable (422), same as a
     *     null `$collector` -- there is no way to gate readiness without it.
     */
    public function __construct(
        private readonly PayoutRepository $payouts,
        private readonly LedgerRepository $ledger,
        private readonly LedgerAccountLock $lock,
        private readonly SellerBalanceService $balances,
        private readonly SellerRepository $sellers,
        ?callable $uuidGenerator = null,
        private readonly ?PayoutCollector $collector = null,
        private readonly ?PayoutAccountService $payoutAccounts = null,
    ) {
        $this->uuidGenerator = $uuidGenerator ?? static fn (): string => Utils::generateNanoID();
    }

    /** @return array<string,mixed> the commerce_payouts row */
    public function record(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $currency,
        int $amount,
        string $idempotencyKey,
        string $externalRef,
        ?string $note,
        string $actorUuid
    ): array {
        if ($amount <= 0) {
            throw new PayoutException('Payout amount must be greater than zero.');
        }
        if (trim($externalRef) === '') {
            throw new PayoutException('A non-empty external reference is required.');
        }
        if (trim($actorUuid) === '') {
            throw new PayoutException('A non-empty operator actor is required.');
        }

        $existing = $this->payouts->findByIdempotencyKey($context, $tenant, $idempotencyKey);
        if ($existing !== null) {
            return $this->verifyReplay(
                $context,
                $tenant,
                $existing,
                $sellerUuid,
                $currency,
                $amount,
                $externalRef,
                $note,
                $actorUuid
            );
        }

        try {
            return db($context)->transaction(function () use (
                $context,
                $tenant,
                $sellerUuid,
                $currency,
                $amount,
                $idempotencyKey,
                $externalRef,
                $note,
                $actorUuid
            ): array {
                // MV5b Task 6 (design spec §2.7): claim the seller revision FIRST -- strictly
                // before the account lock below -- the SAME primitive SellerService::suspend()/
                // reactivate() claims for this exact seller row, so this transaction and a
                // concurrent lifecycle transition are strictly serialized (never interleaved).
                // A seller_uuid with no commerce_sellers row (claimRevision() affects zero rows)
                // is untracked by the marketplace lifecycle and is never gated below -- every
                // pre-MV5b caller with no registered seller entity stays byte-identical.
                $this->sellers->claimRevision($context, $tenant, $sellerUuid);
                $seller = $this->sellers->findByUuid($context, $tenant, $sellerUuid);

                // The concurrent preflight-miss/suspension/replay race (design spec §2.7): this
                // call's OWN up-front findByIdempotencyKey() (above, outside this transaction)
                // may have missed because the request that actually WON this exact idempotency
                // key had not committed yet. Repeating the lookup now -- AFTER the revision claim
                // just serialized against any concurrent suspend() -- catches that already-
                // committed row and returns its VERIFIED replay even when the seller is now
                // non-active: an already-paid manual payout is honored no matter when suspension
                // lands, never re-refused as a fresh-payout lifecycle error.
                $existingInTxn = $this->payouts->findByIdempotencyKey($context, $tenant, $idempotencyKey);
                if ($existingInTxn !== null) {
                    return $this->verifyReplay(
                        $context,
                        $tenant,
                        $existingInTxn,
                        $sellerUuid,
                        $currency,
                        $amount,
                        $externalRef,
                        $note,
                        $actorUuid
                    );
                }

                if ($seller !== null && (string) $seller['status'] !== 'active') {
                    throw new PayoutException(sprintf(
                        "Payout refused: seller %s is '%s', not active; new payouts are frozen while a "
                            . 'seller is not active.',
                        $sellerUuid,
                        (string) $seller['status']
                    ));
                }

                $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);
                $this->lock->claim($context, $tenant, $accountKey, $currency);

                // The balance-safety recheck (design spec §2.6/§2.10, MV5a §2.7 Task 13):
                // re-derived UNDER the lock just claimed above, so a concurrent posting to
                // this SAME account cannot land between an earlier, unlocked read and this
                // refusal check. `balance()` (not `available()`) is called so the MV5a §2.7
                // debt gate below reads `debt` off the SAME single grouped scan -- no extra
                // round-trip.
                $balance = $this->balances->balance($context, $tenant, $sellerUuid, $currency);
                $available = $balance['available'];
                $this->refuseIfInDebt($sellerUuid, $currency, $balance['debt']);
                if ($amount > $available) {
                    throw new PayoutException(sprintf(
                        'Payout amount %d exceeds available balance %d for seller %s (%s).',
                        $amount,
                        $available,
                        $sellerUuid,
                        $currency
                    ));
                }

                $payoutUuid = ($this->uuidGenerator)();
                $row = [
                    'uuid' => $payoutUuid,
                    'tenant_uuid' => $tenant,
                    'seller_uuid' => $sellerUuid,
                    'currency' => $currency,
                    'amount' => $amount,
                    'external_ref' => $externalRef,
                    'note' => $note,
                    'created_by' => $actorUuid,
                    'idempotency_key' => $idempotencyKey,
                    // MV4 folded columns (design spec §3.1): written explicitly rather than
                    // relying on the schema's manual-shaped defaults, so a provider row (which
                    // this same table now also holds) can never be confused with a manual one.
                    'method' => 'manual',
                    'status' => 'paid',
                    'completed_at' => db($context)->getDriver()->formatDateTime(),
                ];
                $this->payouts->insert($context, $row);

                $this->ledger->post($context, $tenant, [
                    'account_kind' => 'seller',
                    'account_key' => $accountKey,
                    'seller_uuid' => $sellerUuid,
                    'currency' => $currency,
                    'entry_type' => 'payout_debit',
                    'amount' => -$amount,
                    'payout_uuid' => $payoutUuid,
                    'created_by' => $actorUuid,
                    'idempotency_key' => "{$payoutUuid}:{$sellerUuid}:payout_debit",
                ]);

                db($context)->afterCommit(function () use ($context, $row): void {
                    $this->dispatch($context, new PayoutRecorded($row));
                });

                return $row;
            });
        } catch (\PDOException $e) {
            // Unique (tenant_uuid, idempotency_key) backstop: a concurrent same-key request won
            // the race between this call's up-front lookup and its own insert. Only classify
            // this as an idempotent replay if the key is now genuinely present; otherwise the
            // failure is unrelated and must not be swallowed.
            $existing = $this->payouts->findByIdempotencyKey($context, $tenant, $idempotencyKey);
            if ($existing === null) {
                throw $e;
            }

            return $this->verifyReplay(
                $context,
                $tenant,
                $existing,
                $sellerUuid,
                $currency,
                $amount,
                $externalRef,
                $note,
                $actorUuid
            );
        }
    }

    /**
     * @param array<string,mixed> $existing the pre-existing commerce_payouts row
     * @return array<string,mixed> $existing, unchanged -- an idempotent replay never mutates
     *     anything
     */
    private function verifyReplay(
        ApplicationContext $context,
        string $tenant,
        array $existing,
        string $sellerUuid,
        string $currency,
        int $amount,
        string $externalRef,
        ?string $note,
        string $actorUuid
    ): array {
        $expected = [
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'amount' => $amount,
            'external_ref' => $externalRef,
            'note' => $note,
            'created_by' => $actorUuid,
        ];

        foreach ($expected as $field => $value) {
            $existingValue = $existing[$field] ?? null;
            $matches = $field === 'amount'
                ? (int) $existingValue === (int) $value
                : self::normalize($existingValue) === self::normalize($value);

            if (!$matches) {
                $key = (string) $existing['idempotency_key'];
                throw new LedgerException(
                    "Payout integrity failure (tenant {$tenant}, idempotency_key {$key}): "
                        . "field \"{$field}\" mismatch on replay -- "
                        . 'existing=' . var_export($existingValue, true) . ', '
                        . 'replayed=' . var_export($value, true) . '.'
                );
            }
        }

        $payoutUuid = (string) $existing['uuid'];
        $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);

        // Delegates the ledger-entry half of the verify straight to LedgerRepository::post()'s
        // own duplicate-key logic (design spec §2.5): this idempotency_key was already used by
        // the original transaction, so this call is GUARANTEED to hit the unique-constraint
        // verify path internally -- it either silently no-ops (every ledger field matches) or
        // throws LedgerException (mismatch). Never inserts a second entry.
        $this->ledger->post($context, $tenant, [
            'account_kind' => 'seller',
            'account_key' => $accountKey,
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'entry_type' => 'payout_debit',
            'amount' => -$amount,
            'payout_uuid' => $payoutUuid,
            'created_by' => $actorUuid,
            'idempotency_key' => "{$payoutUuid}:{$sellerUuid}:payout_debit",
        ]);

        return $existing;
    }

    // -----------------------------------------------------------------
    // Provider-payout saga (design spec §2.3, MV4 Task 7): reserve -> execute -> finalize.
    // -----------------------------------------------------------------

    /**
     * Attempt `n`'s deterministic idempotency key (design spec §2.5) -- Commerce's canonical
     * key for both `PayoutCollector::transfer()` and any `status()`/reconcile call about that
     * SAME attempt. A new attempt key (`n+1`) is minted only after a CONFIRMED
     * `RETRYABLE_FAILURE`; never under `UNKNOWN`/`PENDING`.
     */
    public static function attemptKey(string $payoutUuid, int $attempt): string
    {
        return "{$payoutUuid}:attempt:{$attempt}";
    }

    /**
     * The full saga for one seller/currency/amount: RESERVE (one txn, no I/O) -> EXECUTE
     * (`PayoutCollector::transfer()`, strictly outside any transaction) -> FINALIZE (one txn,
     * no I/O, single-finalizer CAS). Unlike {@see self::record()}, this takes no caller-supplied
     * idempotency key -- each call reserves and attempts a genuinely new payout (subject to the
     * available-balance check); replaying an in-flight/failed attempt is `retry()`'s job
     * (Task 9), not this method's.
     *
     * @return array<string,mixed> the commerce_payouts row after finalize applied its outcome
     * @throws PayoutException no bound {@see PayoutCollector}/{@see PayoutAccountService}
     *     (422, before reserving), a non-positive amount, no `ready` payout destination for
     *     the configured provider (422, design spec §2.7, Task 8 -- checked BEFORE any lock
     *     claim, row insert, or hold post), or the reserve-time available-balance refusal
     *     (422).
     * @throws PayoutOutcomeUnknownException the transfer outcome is ambiguous ({@see
     *     PayoutResult::UNKNOWN} or a transport throw) -- the hold stays and only a
     *     reconcile (Task 9) may resolve it.
     */
    public function execute(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $currency,
        int $amount,
        ?string $actorUuid
    ): array {
        if ($amount <= 0) {
            throw new PayoutException('Payout amount must be greater than zero.');
        }

        [$collector, $account] = $this->requireCollectorAndReadyAccount($context, $tenant, $sellerUuid);

        $payout = $this->reserve($context, $tenant, $sellerUuid, $currency, $amount, $actorUuid, $account)
            // An explicit, already-validated-positive amount only ever refuses via the
            // available-balance PayoutException above -- reserve() returning null (the
            // derive-in-lock skip signal, Task 9) is unreachable on this path.
            ?? throw new \LogicException('reserve() unexpectedly skipped an explicit-amount request.');

        return $this->callAndFinalize($context, $tenant, $collector, $payout);
    }

    /**
     * Task 9 batch counterpart to {@see self::execute()} (design spec §2.6): the SAME
     * readiness gate + shared reserve step, but the payout amount is not known up front
     * -- {@see self::reserve()}'s derive-in-lock signal (a `null` `$amount`) resolves it
     * from the seller/currency account's `available` balance AS OF the moment the
     * account lock is claimed, capped by the optional per-currency `maximums` config and
     * refused (never inserted) below the per-currency `minimums` floor. This is what lets
     * concurrent batch workers serialize on the account lock instead of needing a
     * separate candidate lease (design spec §2.6's "makes concurrent batch workers
     * serialize without a separate candidate lease").
     *
     * @return array<string,mixed>|null the finalized payout row, or null when the
     *     candidate was skipped (the locked `available` was non-positive or below the
     *     configured per-currency minimum) -- a legitimate no-op, not an error
     * @throws PayoutException no bound collector/readiness gate, or no `ready` payout
     *     destination for the configured provider (422, before any lock claim)
     * @throws PayoutOutcomeUnknownException the transfer outcome is ambiguous -- same as
     *     {@see self::execute()}
     */
    public function executeBatch(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $currency,
        ?string $actorUuid
    ): ?array {
        [$collector, $account] = $this->requireCollectorAndReadyAccount($context, $tenant, $sellerUuid);

        $payout = $this->reserve($context, $tenant, $sellerUuid, $currency, null, $actorUuid, $account);
        if ($payout === null) {
            return null;
        }

        return $this->callAndFinalize($context, $tenant, $collector, $payout);
    }

    /**
     * Task 9: claim-and-execute the next retry attempt for one payout (design spec §2.6) --
     * the SAME CAS ({@see PayoutRepository::claimRetryableForAttempt()}: the guarded
     * `failed+retryable+attempt_count<max` -> `pending` transition that increments
     * `attempt_count`, clears `next_attempt_at`, stamps `last_attempt_at`, and re-arms
     * `next_reconcile_at` BEFORE any provider I/O) immediately followed by
     * {@see self::callAndFinalize()} for the newly-claimed attempt. A crash between the
     * claim and finalize is recovered by a reconcile sweep via that SAME watchdog, never
     * a second blind retry.
     *
     * `$ignoreDueTime` (design spec §2.6: "the operator retry uses the same claim but may
     * ignore the due time; neither path may exceed max_attempts") is threaded straight into
     * that CAS's own bypass. {@see \Glueful\Extensions\Commerce\Http\Admin\AdminPayoutController::retryPayout()}
     * (the operator single-payout retry) passes `true` -- an operator retrying NOW is the
     * whole point of a manual retry, it should never have to wait out the backoff window.
     * {@see \Glueful\Extensions\Commerce\Console\PayoutsRetrySweepCommand} (the scheduled
     * sweep) keeps the default `false`, so it stays due-gated exactly as before -- every OTHER
     * guard (`status='failed'`, `retryable=true`, `attempt_count < max_attempts`) applies
     * unconditionally on BOTH paths.
     *
     * @return array<string,mixed>|null the finalized payout row, or null when nothing was
     *     claimed (already claimed by a concurrent sweep, exhausted, not retryable, or --
     *     when `$ignoreDueTime` is false -- not yet due) -- a legitimate no-op, not an error
     * @throws PayoutException no bound {@see PayoutCollector}
     * @throws PayoutOutcomeUnknownException the new attempt's outcome is ambiguous --
     *     same as {@see self::execute()}
     */
    public function retry(
        ApplicationContext $context,
        string $tenant,
        string $payoutUuid,
        bool $ignoreDueTime = false
    ): ?array {
        $collector = $this->collector
            ?? throw new PayoutException('No payout provider is configured for provider payouts.');
        $maxAttempts = (int) config($context, 'commerce.marketplace.payouts.max_attempts', 5);

        $claimed = $this->payouts->claimRetryableForAttempt(
            $context,
            $tenant,
            $payoutUuid,
            $maxAttempts,
            $ignoreDueTime
        );
        if ($claimed === null) {
            return null;
        }

        return $this->callAndFinalize($context, $tenant, $collector, $claimed);
    }

    /**
     * The soft-bound collector + reserve-time readiness gate {@see self::execute()} and
     * {@see self::executeBatch()} both require BEFORE claiming any lock, inserting any
     * row, or posting any hold (design spec §2.7) -- factored out so both entry points
     * apply the exact same refusal order.
     *
     * @return array{0: PayoutCollector, 1: array<string,mixed>}
     * @throws PayoutException no bound collector/readiness gate, or no `ready` payout
     *     destination for the configured provider
     */
    private function requireCollectorAndReadyAccount(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid
    ): array {
        $collector = $this->collector
            ?? throw new PayoutException('No payout provider is configured for provider payouts.');
        $payoutAccounts = $this->payoutAccounts
            ?? throw new PayoutException('No payout destination readiness gate is configured.');

        // The readiness gate (design spec §2.7, Task 8): a guarded READ of already-synced
        // local state, no provider I/O -- runs BEFORE claiming the ledger account lock,
        // inserting the payout row, or posting the reserve hold. The returned account's
        // `account_ref` is snapshotted straight onto the payout row in reserve() below, so a
        // LATER attach()/sync() against this same (seller, provider) can never retarget an
        // already-reserved payout.
        $provider = $this->defaultProvider($context);
        $account = $payoutAccounts->requireReady($context, $tenant, $sellerUuid, $provider);

        return [$collector, $account];
    }

    /**
     * The provider a payout targets when `execute()` takes no explicit provider argument
     * (design spec §2.9's single-default-provider posture, mirrored from the pre-Task-8
     * stub's own fallback so existing default-provider configuration keeps resolving the
     * SAME provider id it always did): `commerce.marketplace.payouts.default_provider`, or
     * the literal `'default'` when unconfigured.
     */
    private function defaultProvider(ApplicationContext $context): string
    {
        $provider = (string) (config($context, 'commerce.marketplace.payouts.default_provider', '') ?? '');

        return $provider !== '' ? $provider : 'default';
    }

    /**
     * Saga step 1: RESERVE. Same balance-safety order as {@see self::record()} -- claim the
     * seller account lock, RE-READ `available` UNDER it -- but the row is inserted
     * `pending`/`method=provider` instead of `paid`/`method=manual`, and this commits with NO
     * network I/O: the collector call only ever happens after this transaction returns.
     * `last_attempt_at` and the initial `next_reconcile_at` watchdog are stamped here so a
     * process death between this commit and FINALIZE is recoverable by a reconcile sweep
     * (Task 9) rather than stranding the hold.
     *
     * `$amount` carries the Task 9 derive-in-lock signal: an explicit positive amount (the
     * {@see self::execute()} operator path) refuses (422, no hold posted) if it exceeds the
     * locked `available`, exactly as before; `null` (the {@see self::executeBatch()} path)
     * DERIVES the amount from that SAME locked `available` instead of checking a caller
     * amount against it -- capped by the optional per-currency `maximums` config, and the
     * candidate is SKIPPED (no row, no hold, returns null) when the locked value is below the
     * per-currency `minimums` floor or non-positive. This is what makes concurrent batch
     * workers serialize on the account lock rather than needing a separate candidate lease
     * (design spec §2.6): the SECOND worker to claim the SAME lock re-reads an `available`
     * already drained by the FIRST worker's hold, and derives a smaller amount (or skips).
     *
     * @param array<string,mixed> $account the ALREADY-gated ready account
     *     ({@see self::requireCollectorAndReadyAccount()}'s
     *     {@see PayoutAccountService::requireReady()} call) -- its `provider`/`account_ref`
     *     are copied verbatim onto the payout row (design spec §2.7's in-flight-destination
     *     snapshot rule); this method performs no readiness lookup of its own.
     * @return array<string,mixed>|null the freshly-inserted commerce_payouts row, or null
     *     ONLY for a `null` (derive-in-lock) `$amount` whose locked value was skipped
     */
    private function reserve(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $currency,
        ?int $amount,
        ?string $actorUuid,
        array $account
    ): ?array {
        return db($context)->transaction(function () use (
            $context,
            $tenant,
            $sellerUuid,
            $currency,
            $amount,
            $actorUuid,
            $account
        ): ?array {
            // MV5b Task 6 (design spec §2.7): same revision-claim-first serialization as
            // record() -- strictly before the account lock below. A non-active seller is
            // refused (an explicit provider payout, $amount !== null) or silently SKIPPED
            // (the batch derive-in-lock path, $amount === null -- an independent-per-candidate
            // skip, exactly like the debt/below-minimum skips further down, never a failure).
            // A seller_uuid with no commerce_sellers row is untracked and never gated, same as
            // record().
            $this->sellers->claimRevision($context, $tenant, $sellerUuid);
            $seller = $this->sellers->findByUuid($context, $tenant, $sellerUuid);

            if ($seller !== null && (string) $seller['status'] !== 'active') {
                if ($amount === null) {
                    return null;
                }

                throw new PayoutException(sprintf(
                    "Payout refused: seller %s is '%s', not active; new payouts are frozen while a "
                        . 'seller is not active.',
                    $sellerUuid,
                    (string) $seller['status']
                ));
            }

            $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);
            $this->lock->claim($context, $tenant, $accountKey, $currency);

            // The balance-safety recheck (design spec §2.3/§2.6, MV5a §2.7 Task 13):
            // re-derived UNDER the lock just claimed above, so a concurrent posting to this
            // SAME account cannot land between an earlier, unlocked read and this
            // refusal/derivation. `balance()` (not `available()`) is called so the MV5a §2.7
            // debt gate reads `debt` off the SAME single grouped scan -- no extra round-trip
            // -- and, critically, off the LOCKED balance rather than the unlocked batch
            // candidate hint ({@see LedgerRepository::positiveAvailableCandidates()}).
            $balance = $this->balances->balance($context, $tenant, $sellerUuid, $currency);
            $available = $balance['available'];
            $debt = $balance['debt'];

            if ($amount === null) {
                // Batch derive-in-lock path (MV5a design spec §2.7, Task 13): a seller in
                // debt is SKIPPED (null, no row/hold posted) -- the SAME independent-per-
                // candidate discipline {@see self::deriveBatchAmount()} already applies to a
                // non-positive/below-minimum locked available.
                if ($debt > 0) {
                    return null;
                }
                $resolved = $this->deriveBatchAmount($context, $currency, $available);
                if ($resolved === null) {
                    return null;
                }
                $amount = $resolved;
            } else {
                $this->refuseIfInDebt($sellerUuid, $currency, $debt);
                if ($amount > $available) {
                    throw new PayoutException(sprintf(
                        'Payout amount %d exceeds available balance %d for seller %s (%s).',
                        $amount,
                        $available,
                        $sellerUuid,
                        $currency
                    ));
                }
            }

            $payoutUuid = ($this->uuidGenerator)();
            $now = db($context)->getDriver()->formatDateTime();

            $row = [
                'uuid' => $payoutUuid,
                'tenant_uuid' => $tenant,
                'seller_uuid' => $sellerUuid,
                'currency' => $currency,
                'amount' => $amount,
                'external_ref' => null,
                'note' => null,
                'created_by' => $actorUuid,
                // §3.1: a provider row has no caller-supplied idempotency key (unlike
                // record()'s), so the payout's own uuid IS its idempotency identity.
                'idempotency_key' => $payoutUuid,
                'status' => 'pending',
                'method' => 'provider',
                'provider' => (string) $account['provider'],
                'destination_ref' => (string) $account['account_ref'],
                'retryable' => false,
                'attempt_count' => 1,
                'last_attempt_at' => $now,
                'next_reconcile_at' => $this->reconcileWatchdog($context, 'pending'),
            ];
            $this->payouts->insert($context, $row);

            $this->ledger->post($context, $tenant, [
                'account_kind' => 'seller',
                'account_key' => $accountKey,
                'seller_uuid' => $sellerUuid,
                'currency' => $currency,
                'entry_type' => 'reserve_hold',
                'amount' => -$amount,
                'payout_uuid' => $payoutUuid,
                'created_by' => $actorUuid,
                'idempotency_key' => "{$payoutUuid}:reserve_hold",
            ]);

            return $row;
        });
    }

    /**
     * The MV5a design spec §2.7 debt gate (Task 13), shared by {@see self::record()}'s
     * manual path and {@see self::reserve()}'s explicit-amount (operator `execute()`) path: refuses
     * (`422` {@see PayoutException}, mirroring the capacity-check refusal right below each
     * call site) whenever the seller's LOCKED `debt` component is positive. This is
     * ADDITIVE to -- never a replacement for -- the pre-existing MV4 `amount > available`
     * capacity guard: a positive requested amount with a locked `available >= amount`
     * already implies `available > 0` (hence `debt == 0`, since `debt = max(0, -available)`),
     * so this explicit check exists to make the invariant "never create a payout while in
     * debt" self-documenting and independently enforced, not to change behavior on the
     * capacity check's own refusal path.
     */
    private function refuseIfInDebt(string $sellerUuid, string $currency, int $debt): void
    {
        if ($debt > 0) {
            throw new PayoutException(sprintf(
                'Payout refused: seller %s (%s) carries outstanding debt %d; debt must be '
                    . 'cleared before a new payout may be created.',
                $sellerUuid,
                $currency,
                $debt
            ));
        }
    }

    /**
     * Task 9 batch amount derivation (design spec §2.6), called ONLY from inside
     * {@see self::reserve()}'s account-lock-protected transaction, against the `$available`
     * value that SAME lock just made safe to read: the full locked balance, optionally capped
     * by `commerce.marketplace.payouts.maximums.{$currency}` (absent/zero means uncapped --
     * the batch uses the full locked available balance), and refused (returns null -- the
     * candidate is skipped, never inserted) when the resulting value is non-positive or below
     * `commerce.marketplace.payouts.minimums.{$currency}`.
     */
    private function deriveBatchAmount(ApplicationContext $context, string $currency, int $available): ?int
    {
        $maximum = (int) (config($context, "commerce.marketplace.payouts.maximums.{$currency}", 0) ?? 0);
        $derived = $maximum > 0 ? min($available, $maximum) : $available;

        $minimum = (int) (config($context, "commerce.marketplace.payouts.minimums.{$currency}", 0) ?? 0);

        return ($derived > 0 && $derived >= $minimum) ? $derived : null;
    }

    /**
     * Saga step 2: EXECUTE. Strictly outside any transaction -- the attempt-1 idempotency key
     * (design spec §2.5) is passed to `PayoutCollector::transfer()`. A throw is treated
     * identically to a returned `PayoutResult::UNKNOWN` (design spec §2.3): the outcome is
     * ambiguous, never a blind retry.
     *
     * @param array<string,mixed> $payout
     * @return array<string,mixed>
     */
    private function callAndFinalize(
        ApplicationContext $context,
        string $tenant,
        PayoutCollector $collector,
        array $payout
    ): array {
        $payoutUuid = (string) $payout['uuid'];
        $destination = new PayoutDestination((string) $payout['provider'], (string) $payout['destination_ref']);
        $request = new PayoutRequest(
            (int) $payout['amount'],
            (string) $payout['currency'],
            self::attemptKey($payoutUuid, (int) $payout['attempt_count']),
            null
        );

        try {
            $result = $collector->transfer($context, $destination, $request);
        } catch (\Throwable $e) {
            return $this->finalizeUnknown($context, $tenant, $payoutUuid, $e->getMessage());
        }

        if ($result->status === PayoutResult::UNKNOWN) {
            return $this->finalizeUnknown(
                $context,
                $tenant,
                $payoutUuid,
                $result->failureReason ?? 'Collector returned an unknown outcome.'
            );
        }

        return $this->finalize($context, $tenant, $payoutUuid, $result);
    }

    /**
     * Saga step 3: FINALIZE. Claims the payout out of `pending` via
     * {@see PayoutRepository::claimPending()} (single-finalizer-wins CAS) BEFORE claiming the
     * seller account lock or posting anything -- a concurrent/duplicate finalize call for the
     * same terminal transition is an idempotent no-op that returns the already-applied row
     * without re-posting. Only the winner claims the account lock, and only when the outcome
     * actually posts money.
     *
     * @return array<string,mixed>
     */
    private function finalize(
        ApplicationContext $context,
        string $tenant,
        string $payoutUuid,
        PayoutResult $result
    ): array {
        return db($context)->transaction(function () use ($context, $tenant, $payoutUuid, $result): array {
            $current = $this->payouts->findByUuid($context, $tenant, $payoutUuid)
                ?? throw new NotFoundException('Resource not found.');

            [$to, $set, $posts] = $this->planTransition($context, $current, $result);

            return $this->applyPendingTransition($context, $tenant, $current, $to, $set, $posts);
        });
    }

    /**
     * Shared CAS-guarded apply for a `pending` -> `$to` transition plan -- extracted from
     * {@see self::finalize()} so Task 9's {@see self::reconcile()} (resolving a still-`pending`
     * attempt) can apply the EXACT same single-finalizer-wins primitive without duplicating it.
     * Claims the payout out of `pending` via {@see PayoutRepository::claimPending()} BEFORE
     * claiming the seller account lock or posting anything; only when the CAS actually wins AND
     * `$posts !== []` does it claim the lock and post. A lost CAS (already finalized, or
     * re-armed for a pending->pending transition, by a concurrent caller) is an idempotent
     * no-op that returns the row's current state, never re-posted.
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $set
     * @param list<array<string,mixed>> $posts
     * @return array<string,mixed>
     */
    private function applyPendingTransition(
        ApplicationContext $context,
        string $tenant,
        array $current,
        string $to,
        array $set,
        array $posts
    ): array {
        $payoutUuid = (string) $current['uuid'];

        if (!$this->payouts->claimPending($context, $tenant, $payoutUuid, $to, $set)) {
            // Re-fetches through a distinct method (not a second literal findByUuid() call) so
            // PHPStan does not (incorrectly) assume this returns the exact same result as the
            // read the caller already performed -- claimPending()'s own UPDATE, or a concurrent
            // finalizer/reconciler, may have changed the row in between.
            return $this->refetch($context, $tenant, $payoutUuid) ?? $current;
        }

        if ($posts !== []) {
            $accountKey = LedgerRepository::accountKeyForSeller((string) $current['seller_uuid']);
            $this->lock->claim($context, $tenant, $accountKey, (string) $current['currency']);
            foreach ($posts as $post) {
                $this->ledger->post($context, $tenant, $post);
            }
        }

        return array_merge($current, $set, ['status' => $to]);
    }

    // -----------------------------------------------------------------
    // Task 9: reconcile (design spec §2.6/§2.8) -- resolves a still-pending attempt via
    // PayoutCollector::status(), and discovers/applies provider-reported reversals on an
    // already-paid payout.
    // -----------------------------------------------------------------

    /**
     * Calls `PayoutCollector::status()` OUTSIDE any transaction for `$payoutRow`'s CURRENT
     * attempt, then applies a closed state map in a finalize-style transaction (the seller
     * account lock is claimed BEFORE any money post). `$payoutRow['status']` AT SELECTION TIME
     * gates what this call is even allowed to do (design spec §2.6):
     *  - `pending` (an unresolved attempt): resolves PAID/PENDING/RETRYABLE_FAILURE/
     *    TERMINAL_FAILURE/UNKNOWN exactly as {@see self::finalize()} would for that SAME
     *    attempt (reuses {@see self::planTransition()}) -- `REVERSED` is impossible here (a
     *    payout cannot be reversed before it is paid) and is recorded as an integrity finding
     *    instead, with no money change.
     *  - `paid` (the reversal cadence, §2.8): only a continued PAID (possibly carrying a NEW
     *    cumulative `reversedAmount`) or a full REVERSED is actionable. A cumulative
     *    `reversedAmount` greater than the persisted `reversed_total` posts ONLY the unseen
     *    delta as `payout_reversal` and updates `reversed_total` in the SAME transaction;
     *    `reversedAmount === amount` reaches `status=reversed`, otherwise the row stays `paid`.
     *    A provider regression to PENDING/RETRYABLE_FAILURE/TERMINAL_FAILURE, a `reversedAmount`
     *    that goes BACKWARDS, or one that exceeds the payout's own `amount`, is an integrity
     *    finding -- NEVER a ledger change, NEVER a release.
     *  - already terminal (`failed`/`reversed`): a no-op; nothing left to reconcile.
     * A `status()` call that itself throws leaves the row untouched and simply re-arms
     * `next_reconcile_at` for a later sweep -- it never fabricates a state.
     *
     * @param array<string,mixed> $payoutRow the row a sweep (or direct caller) selected
     * @return array<string,mixed> the row's state after this reconcile attempt
     * @throws PayoutException no bound {@see PayoutCollector}
     */
    public function reconcile(ApplicationContext $context, string $tenant, array $payoutRow): array
    {
        $collector = $this->collector
            ?? throw new PayoutException('No payout provider is configured for provider payouts.');

        $payoutUuid = (string) $payoutRow['uuid'];
        $destination = new PayoutDestination((string) $payoutRow['provider'], (string) $payoutRow['destination_ref']);
        $attemptCount = max(1, (int) $payoutRow['attempt_count']);
        $attemptKey = self::attemptKey($payoutUuid, $attemptCount);

        try {
            $result = $collector->status($context, $destination, $attemptKey);
        } catch (\Throwable) {
            $kind = (string) $payoutRow['status'] === 'paid' ? 'paid' : 'pending';
            $this->payouts->scheduleReconcile(
                $context,
                $tenant,
                $payoutUuid,
                $this->reconcileWatchdog($context, $kind)
            );

            return $this->refetch($context, $tenant, $payoutUuid) ?? $payoutRow;
        }

        return $this->applyReconcile($context, $tenant, $payoutUuid, $result);
    }

    /** @return array<string,mixed> */
    private function applyReconcile(
        ApplicationContext $context,
        string $tenant,
        string $payoutUuid,
        PayoutStatusResult $result
    ): array {
        return db($context)->transaction(function () use ($context, $tenant, $payoutUuid, $result): array {
            $current = $this->payouts->findByUuid($context, $tenant, $payoutUuid)
                ?? throw new NotFoundException('Resource not found.');

            return match ((string) $current['status']) {
                'pending' => $this->reconcilePendingAttempt($context, $tenant, $current, $result),
                'paid' => $this->reconcilePaidPayout($context, $tenant, $current, $result),
                // Already terminal (failed/reversed) -- nothing left to reconcile.
                default => $this->reconcileTerminalRow($current, $result),
            };
        });
    }

    /**
     * Reconcile branch for a still-`pending` attempt (design spec §2.3/§2.6): every status
     * except `REVERSED`/`UNKNOWN` reuses {@see self::planTransition()} verbatim (the SAME map
     * `finalize()` would apply for the same attempt) and {@see self::applyPendingTransition()}
     * for the CAS/posting. `REVERSED` is impossible on an unresolved attempt -- a payout can't
     * be reversed before it is paid -- so it is an integrity finding (no money change) instead.
     * `UNKNOWN` is a legitimate, still-ambiguous provider answer (NOT an integrity violation):
     * the hold stays, the row stays `pending`, the attempt never advances, and
     * `next_reconcile_at` is simply re-armed for a later sweep -- handled as its own explicit
     * branch (mirroring {@see self::finalizeUnknown()}'s watchdog cadence) rather than falling
     * into {@see self::planTransition()}'s `default => throw` arm, which has no case for it and
     * would otherwise escape this call: since {@see self::reconcile()}'s try/catch wraps only
     * the `status()` call, an uncaught throw here would skip re-arming the watchdog entirely,
     * leaving the row perpetually due and hot-looping every subsequent reconcile sweep.
     *
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    private function reconcilePendingAttempt(
        ApplicationContext $context,
        string $tenant,
        array $current,
        PayoutStatusResult $result
    ): array {
        if ($result->status === PayoutStatusResult::REVERSED) {
            $this->logIntegrityFinding(
                $current,
                'Provider reported REVERSED for a payout that is still pending (attempt unresolved).'
            );

            return $this->applyPendingTransition(
                $context,
                $tenant,
                $current,
                'pending',
                ['next_reconcile_at' => $this->reconcileWatchdog($context, 'pending')],
                []
            );
        }

        if ($result->status === PayoutStatusResult::UNKNOWN) {
            return $this->applyPendingTransition(
                $context,
                $tenant,
                $current,
                'pending',
                ['next_reconcile_at' => $this->reconcileWatchdog($context, 'pending')],
                []
            );
        }

        $payoutResult = new PayoutResult(
            $result->status,
            $result->providerRef,
            $result->failureCode,
            $result->failureReason
        );
        [$to, $set, $posts] = $this->planTransition($context, $current, $payoutResult);

        return $this->applyPendingTransition($context, $tenant, $current, $to, $set, $posts);
    }

    /**
     * Reconcile branch for an already-`paid` payout (design spec §2.8): only a continued PAID
     * (possibly carrying a NEW cumulative `reversedAmount`) or REVERSED is actionable -- any
     * other reported status is a provider regression and an integrity finding. When a post IS
     * warranted, the seller account lock is claimed BEFORE re-reading `reversed_total`/`status`
     * fresh (the SAME "claim then re-read" discipline {@see self::reserve()}/`record()` use) --
     * `$current`'s IMMUTABLE identity fields (amount/seller/currency/created_by) are safe to use
     * as read before the lock; only the MUTABLE fields need the fresh, lock-protected read.
     *
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    private function reconcilePaidPayout(
        ApplicationContext $context,
        string $tenant,
        array $current,
        PayoutStatusResult $result
    ): array {
        $payoutUuid = (string) $current['uuid'];
        $amount = (int) $current['amount'];

        if (!in_array($result->status, [PayoutStatusResult::PAID, PayoutStatusResult::REVERSED], true)) {
            $this->logIntegrityFinding($current, sprintf(
                "Provider reported status '%s' for payout %s, which Commerce already recorded as paid.",
                $result->status,
                $payoutUuid
            ));
            $this->payouts->scheduleReconcile(
                $context,
                $tenant,
                $payoutUuid,
                $this->reconcileWatchdog($context, 'paid')
            );

            return $this->refetch($context, $tenant, $payoutUuid) ?? $current;
        }

        $accountKey = LedgerRepository::accountKeyForSeller((string) $current['seller_uuid']);
        $currency = (string) $current['currency'];
        $this->lock->claim($context, $tenant, $accountKey, $currency);

        // Fresh, lock-protected read (design spec §2.6): only NOW is `reversed_total`/`status`
        // guaranteed to reflect every reversal already applied by an earlier reconcile.
        $fresh = $this->payouts->findByUuid($context, $tenant, $payoutUuid)
            ?? throw new NotFoundException('Resource not found.');
        if ((string) $fresh['status'] !== 'paid') {
            // Resolved by a concurrent reconcile while this call waited for the lock.
            return $fresh;
        }

        $reversedTotal = (int) $fresh['reversed_total'];
        $reversedAmount = $result->reversedAmount;

        if ($reversedAmount < $reversedTotal || $reversedAmount > $amount) {
            $this->logIntegrityFinding($current, sprintf(
                'Reported cumulative reversedAmount %d is invalid for payout %s '
                    . '(recorded reversed_total %d, amount %d).',
                $reversedAmount,
                $payoutUuid,
                $reversedTotal,
                $amount
            ));
            $this->payouts->scheduleReconcile(
                $context,
                $tenant,
                $payoutUuid,
                $this->reconcileWatchdog($context, 'paid')
            );

            return $this->refetch($context, $tenant, $payoutUuid) ?? $fresh;
        }

        if ($reversedAmount === $reversedTotal) {
            // Nothing new -- a replay of an already-applied reversal, or a plain continued PAID
            // observation with no reversal reported. Re-arm the paid cadence and return.
            $this->payouts->scheduleReconcile(
                $context,
                $tenant,
                $payoutUuid,
                $this->reconcileWatchdog($context, 'paid')
            );

            return $this->refetch($context, $tenant, $payoutUuid) ?? $fresh;
        }

        // A genuinely NEW cumulative reversedAmount -- post only the unseen delta (design spec
        // §2.6/§2.8): POSITIVE (it returns money to the seller balance, offsetting the earlier
        // `payout_debit = -amount`; see LedgerRepository::balanceComponents()'s
        // `paid_out = -(Σ payout_debit + Σ payout_reversal)` -- a positive payout_reversal is
        // what makes paid_out DECREASE). The idempotency key carries the CUMULATIVE
        // target-after value (not the delta), so a replayed identical observation verifies
        // against the SAME row instead of double-posting.
        $delta = $reversedAmount - $reversedTotal;
        $to = $reversedAmount === $amount ? 'reversed' : 'paid';
        $set = [
            'reversed_total' => $reversedAmount,
            'next_reconcile_at' => $to === 'reversed' ? null : $this->reconcileWatchdog($context, 'paid'),
        ];

        $this->ledger->post($context, $tenant, [
            'account_kind' => 'seller',
            'account_key' => $accountKey,
            'seller_uuid' => (string) $current['seller_uuid'],
            'currency' => $currency,
            'entry_type' => 'payout_reversal',
            'amount' => $delta,
            'payout_uuid' => $payoutUuid,
            'created_by' => $current['created_by'] ?? null,
            'idempotency_key' => "{$payoutUuid}:payout_reversal:{$reversedAmount}",
        ]);

        // Consumes applyReversal()'s CAS return, symmetric with every other single-finalizer
        // call site in this class (e.g. applyPendingTransition()'s claimPending() check): a
        // lost CAS (the row's status/reversed_total already moved since the lock-protected read
        // above -- in practice only a concurrent reconcile that raced past the account lock
        // this method already holds) is a benign concurrent-winner, never an error, so this
        // never throws. It just means THIS call did not win the mutation -- the optimistic
        // `$set` merge below must not be returned as if it had, since that would misreport a
        // status/reversed_total this call never actually applied; the fresh row (whatever the
        // winner left) is returned instead. The `payout_reversal` ledger post above already
        // happened either way, guarded by its own idempotency key -- a lost CAS here never
        // risks a double post, only which caller's return value reflects the DB.
        $applied = $this->payouts->applyReversal($context, $tenant, $payoutUuid, $reversedTotal, $to, $set);

        return $applied
            ? array_merge($fresh, $set, ['status' => $to])
            : ($this->refetch($context, $tenant, $payoutUuid) ?? $fresh);
    }

    /**
     * Reconcile branch for an already-terminal row (`failed`/`reversed`, design spec §2.6) --
     * nothing left to reconcile; the retry sweep owns `failed+retryable` rows and a fully
     * `reversed` row has no further money to move. A reported observation that regresses an
     * already-`reversed` row (a different status, or a `reversedAmount` no longer matching the
     * recorded `amount`) is logged as an integrity finding -- never a ledger change.
     *
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    private function reconcileTerminalRow(array $current, PayoutStatusResult $result): array
    {
        if ((string) $current['status'] === 'reversed') {
            $stillFullyReversed = $result->status === PayoutStatusResult::REVERSED
                && $result->reversedAmount === (int) $current['amount'];
            if (!$stillFullyReversed) {
                $this->logIntegrityFinding($current, sprintf(
                    "Provider reported status '%s' (reversedAmount %d) for payout %s, already fully reversed.",
                    $result->status,
                    $result->reversedAmount,
                    (string) $current['uuid']
                ));
            }
        }

        return $current;
    }

    /**
     * @param array<string,mixed> $current
     */
    private function logIntegrityFinding(array $current, string $message): void
    {
        error_log(sprintf(
            '[Commerce][PayoutService] Reconciliation integrity finding for payout %s (seller %s): %s',
            (string) $current['uuid'],
            (string) $current['seller_uuid'],
            $message
        ));
    }

    /**
     * Builds the `[$to, $set, $posts]` plan for one `PayoutResult` against the CURRENT row
     * (design spec §2.3). `$posts` is empty for every non-money-moving outcome
     * (PENDING, and a RETRYABLE_FAILURE with attempts remaining) -- {@see self::finalize()}
     * only claims the account lock when `$posts !== []`. `UNKNOWN` never reaches here (handled
     * by {@see self::finalizeUnknown()} before the CAS transaction even opens).
     *
     * @param array<string,mixed> $current
     * @return array{0: string, 1: array<string,mixed>, 2: list<array<string,mixed>>}
     */
    private function planTransition(ApplicationContext $context, array $current, PayoutResult $result): array
    {
        $payoutUuid = (string) $current['uuid'];
        $sellerUuid = (string) $current['seller_uuid'];
        $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);
        $currency = (string) $current['currency'];
        $amount = (int) $current['amount'];
        $createdBy = $current['created_by'] ?? null;

        return match ($result->status) {
            PayoutResult::PAID => [
                'paid',
                [
                    'provider_ref' => $result->providerRef,
                    'completed_at' => db($context)->getDriver()->formatDateTime(),
                    'failure_code' => null,
                    'failure_reason' => null,
                    'retryable' => false,
                    'next_attempt_at' => null,
                    'next_reconcile_at' => $this->reconcileWatchdog($context, 'paid'),
                ],
                [
                    $this->ledgerEntry(
                        $accountKey,
                        $sellerUuid,
                        $currency,
                        'reserve_release',
                        $amount,
                        $payoutUuid,
                        "{$payoutUuid}:reserve_release",
                        $createdBy
                    ),
                    $this->ledgerEntry(
                        $accountKey,
                        $sellerUuid,
                        $currency,
                        'payout_debit',
                        -$amount,
                        $payoutUuid,
                        "{$payoutUuid}:payout_debit",
                        $createdBy
                    ),
                ],
            ],
            PayoutResult::PENDING => [
                'pending',
                ['next_reconcile_at' => $this->reconcileWatchdog($context, 'pending')],
                [],
            ],
            PayoutResult::RETRYABLE_FAILURE => $this->planRetryableFailure($context, $current, $result),
            PayoutResult::TERMINAL_FAILURE => [
                'failed',
                [
                    'failure_code' => $result->failureCode,
                    'failure_reason' => $result->failureReason,
                    'retryable' => false,
                    'next_attempt_at' => null,
                    'next_reconcile_at' => null,
                ],
                [
                    $this->ledgerEntry(
                        $accountKey,
                        $sellerUuid,
                        $currency,
                        'reserve_release',
                        $amount,
                        $payoutUuid,
                        "{$payoutUuid}:reserve_release",
                        $createdBy
                    ),
                ],
            ],
            default => throw new \LogicException("Unhandled payout result status '{$result->status}'."),
        };
    }

    /**
     * RETRYABLE_FAILURE (design spec §2.3): if attempts remain (`attempt_count < max_attempts`),
     * the hold STAYS and `next_attempt_at` is scheduled WITHOUT incrementing `attempt_count`
     * (incrementing happens only on re-claim, {@see PayoutRepository::claimRetryableForAttempt()}).
     * On the final allowed attempt, terminalize instead: release the hold so nothing is
     * stranded.
     *
     * @param array<string,mixed> $current
     * @return array{0: string, 1: array<string,mixed>, 2: list<array<string,mixed>>}
     */
    private function planRetryableFailure(ApplicationContext $context, array $current, PayoutResult $result): array
    {
        $attemptCount = (int) $current['attempt_count'];
        $maxAttempts = (int) config($context, 'commerce.marketplace.payouts.max_attempts', 5);

        if ($attemptCount < $maxAttempts) {
            return [
                'failed',
                [
                    'failure_code' => $result->failureCode,
                    'failure_reason' => $result->failureReason,
                    'retryable' => true,
                    'next_attempt_at' => $this->backoffAt($context, $attemptCount),
                    'next_reconcile_at' => $this->reconcileWatchdog($context, 'pending'),
                ],
                [],
            ];
        }

        $payoutUuid = (string) $current['uuid'];
        $sellerUuid = (string) $current['seller_uuid'];

        return [
            'failed',
            [
                'failure_code' => $result->failureCode,
                'failure_reason' => $result->failureReason,
                'retryable' => false,
                'next_attempt_at' => null,
                'next_reconcile_at' => null,
            ],
            [
                $this->ledgerEntry(
                    LedgerRepository::accountKeyForSeller($sellerUuid),
                    $sellerUuid,
                    (string) $current['currency'],
                    'reserve_release',
                    (int) $current['amount'],
                    $payoutUuid,
                    "{$payoutUuid}:reserve_release",
                    $current['created_by'] ?? null
                ),
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function refetch(ApplicationContext $context, string $tenant, string $payoutUuid): ?array
    {
        return $this->payouts->findByUuid($context, $tenant, $payoutUuid);
    }

    /** @return array<string,mixed> */
    private function ledgerEntry(
        string $accountKey,
        string $sellerUuid,
        string $currency,
        string $entryType,
        int $amount,
        string $payoutUuid,
        string $idempotencyKey,
        mixed $createdBy
    ): array {
        return [
            'account_kind' => 'seller',
            'account_key' => $accountKey,
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'entry_type' => $entryType,
            'amount' => $amount,
            'payout_uuid' => $payoutUuid,
            'created_by' => $createdBy,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /**
     * UNKNOWN outcome (design spec §2.3: a returned `PayoutResult::UNKNOWN` or a transport
     * throw from `transfer()`) -- deliberately NOT run inside {@see self::finalize()}'s CAS
     * transaction, since `status` never changes here (the hold stays, `pending`). Records the
     * reason and re-arms `next_reconcile_at` so a reconcile sweep (Task 9) revisits it, then
     * raises so the caller knows this call did not resolve the payout.
     */
    private function finalizeUnknown(
        ApplicationContext $context,
        string $tenant,
        string $payoutUuid,
        string $reason
    ): never {
        $this->payouts->markUnresolved(
            $context,
            $tenant,
            $payoutUuid,
            $reason,
            $this->reconcileWatchdog($context, 'pending')
        );

        throw new PayoutOutcomeUnknownException('Payout outcome unknown; reconcile before retrying.');
    }

    /** `next_reconcile_at` watchdog: `pending_reconcile_interval` while unresolved, the slower `paid_reconcile_interval` once paid. */
    private function reconcileWatchdog(ApplicationContext $context, string $kind): string
    {
        $key = $kind === 'paid'
            ? 'commerce.marketplace.payouts.paid_reconcile_interval'
            : 'commerce.marketplace.payouts.pending_reconcile_interval';
        $default = $kind === 'paid' ? 86400 : 300;
        $seconds = (int) config($context, $key, $default);

        return gmdate('Y-m-d H:i:s', time() + max(0, $seconds));
    }

    /** Exponential backoff (base * multiplier^(attempt-1), capped): drives `next_attempt_at`. */
    private function backoffAt(ApplicationContext $context, int $attemptCount): string
    {
        $base = (int) config($context, 'commerce.marketplace.payouts.backoff.base_seconds', 60);
        $multiplier = (int) config($context, 'commerce.marketplace.payouts.backoff.multiplier', 2);
        $max = (int) config($context, 'commerce.marketplace.payouts.backoff.max_seconds', 3600);

        $delay = (int) min($base * ($multiplier ** max(0, $attemptCount - 1)), $max);

        return gmdate('Y-m-d H:i:s', time() + max(0, $delay));
    }

    private static function normalize(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function dispatch(ApplicationContext $context, object $event): void
    {
        $container = container($context);
        if ($container->has(EventService::class)) {
            $container->get(EventService::class)->dispatch($event);
        }
    }
}
