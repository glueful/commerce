<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Events\PayoutRecorded;
use Glueful\Helpers\Utils;

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
     */
    public function __construct(
        private readonly PayoutRepository $payouts,
        private readonly LedgerRepository $ledger,
        private readonly LedgerAccountLock $lock,
        private readonly SellerBalanceService $balances,
        ?callable $uuidGenerator = null,
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
