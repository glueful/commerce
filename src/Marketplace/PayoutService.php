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
                $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);
                $this->lock->claim($context, $tenant, $accountKey, $currency);

                // The balance-safety recheck (design spec §2.6/§2.10): re-derived UNDER the
                // lock just claimed above, so a concurrent posting to this SAME account cannot
                // land between an earlier, unlocked read and this refusal check.
                $available = $this->balances->available($context, $tenant, $sellerUuid, $currency);
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

        $payout = $this->reserve($context, $tenant, $sellerUuid, $currency, $amount, $actorUuid, $account);

        return $this->callAndFinalize($context, $tenant, $collector, $payout);
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
     * seller account lock, RE-READ `available` UNDER it, refuse (422, no hold posted) if the
     * amount exceeds it -- but the row is inserted `pending`/`method=provider` instead of
     * `paid`/`method=manual`, and this commits with NO network I/O: the collector call only
     * ever happens after this transaction returns. `last_attempt_at` and the initial
     * `next_reconcile_at` watchdog are stamped here so a process death between this commit and
     * FINALIZE is recoverable by a reconcile sweep (Task 9) rather than stranding the hold.
     *
     * @param array<string,mixed> $account the ALREADY-gated ready account
     *     ({@see self::execute()}'s {@see PayoutAccountService::requireReady()} call) -- its
     *     `provider`/`account_ref` are copied verbatim onto the payout row (design spec §2.7's
     *     in-flight-destination snapshot rule); this method performs no readiness lookup of
     *     its own.
     * @return array<string,mixed> the freshly-inserted commerce_payouts row
     */
    private function reserve(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $currency,
        int $amount,
        ?string $actorUuid,
        array $account
    ): array {
        return db($context)->transaction(function () use (
            $context,
            $tenant,
            $sellerUuid,
            $currency,
            $amount,
            $actorUuid,
            $account
        ): array {
            $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);
            $this->lock->claim($context, $tenant, $accountKey, $currency);

            // The balance-safety recheck (design spec §2.3/§2.6): re-derived UNDER the lock
            // just claimed above, so a concurrent posting to this SAME account cannot land
            // between an earlier, unlocked read and this refusal check.
            $available = $this->balances->available($context, $tenant, $sellerUuid, $currency);
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

            if (!$this->payouts->claimPending($context, $tenant, $payoutUuid, $to, $set)) {
                // Already finalized (or re-armed, for a pending->pending transition) by a
                // concurrent finalizer -- idempotent no-op, never re-posted. Re-fetches through
                // a distinct method (not a second literal findByUuid() call) so PHPStan does not
                // (incorrectly) assume this returns the exact same result as the read at the top
                // of this closure -- claimPending()'s own UPDATE, or a concurrent finalizer, may
                // have changed the row in between.
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
        });
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
