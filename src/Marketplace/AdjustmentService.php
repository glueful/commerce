<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Operator ledger adjustments (design spec §2.10): a manual, signed,
 * mandatory-reason correction posted directly to an account -- the ONLY
 * `entry_type` that may be negative or positive by request. Unlike
 * {@see PayoutService}, there is deliberately NO available-balance check:
 * "adjustments are corrections" (§2.10) and may legitimately drive a
 * balance negative, so the account lock here exists purely for §2.6's
 * general balance-affecting-posting serialization discipline, not a
 * check-then-act guarantee this class needs for its own logic.
 *
 * {@see self::post()} validates up front (non-zero amount, non-empty reason,
 * non-empty actor, a recognized `accountKey`), then -- inside ONE transaction
 * -- claims the account lock ({@see LedgerAccountLock}) and posts a single
 * `adjustment` entry under the DETERMINISTIC key
 * `adjustment:{accountKey}:{idempotencyKey}`. There is no separate
 * "existing found" branch here (unlike {@see PayoutService}, which owns a
 * second table with its own uniqueness): {@see LedgerRepository::post()}'s
 * OWN duplicate-key verify already IS the full idempotent-replay contract --
 * a matching replay silently no-ops, a mismatched replay throws
 * {@see LedgerException} -- so this class always just attempts the post and
 * lets that logic decide. Ledger rows are append-only: a correction is
 * always a NEW, opposite-signed adjustment, never an edit or delete of a
 * prior one.
 */
final class AdjustmentService
{
    private const ACCOUNT_KEY_SELLER_PREFIX = 'seller:';

    public function __construct(
        private readonly LedgerRepository $ledger,
        private readonly LedgerAccountLock $lock,
    ) {
    }

    public function post(
        ApplicationContext $context,
        string $tenant,
        string $accountKey,
        string $currency,
        int $signedAmount,
        string $reason,
        string $idempotencyKey,
        string $actorUuid
    ): void {
        if ($signedAmount === 0) {
            throw new AdjustmentException('Adjustment amount must be non-zero.');
        }
        if (trim($reason) === '') {
            throw new AdjustmentException('A non-empty reason is required.');
        }
        if (trim($actorUuid) === '') {
            throw new AdjustmentException('A non-empty operator actor is required.');
        }

        [$accountKind, $sellerUuid] = self::deriveIdentity($accountKey);

        db($context)->transaction(function () use (
            $context,
            $tenant,
            $accountKey,
            $accountKind,
            $sellerUuid,
            $currency,
            $signedAmount,
            $reason,
            $idempotencyKey,
            $actorUuid
        ): void {
            $this->lock->claim($context, $tenant, $accountKey, $currency);

            $this->ledger->post($context, $tenant, [
                'account_kind' => $accountKind,
                'account_key' => $accountKey,
                'seller_uuid' => $sellerUuid,
                'currency' => $currency,
                'entry_type' => 'adjustment',
                'amount' => $signedAmount,
                'reason' => $reason,
                'created_by' => $actorUuid,
                'idempotency_key' => "adjustment:{$accountKey}:{$idempotencyKey}",
            ]);
        });
    }

    /**
     * The account-identity invariant (§2.5), derived from the canonical `accountKey` rather
     * than re-validated against it: `marketplace` -> `['marketplace', null]`;
     * `seller:{uuid}` -> `['seller', $uuid]`. Anything else is a caller error -- an
     * unrecognized account key is a `422` ({@see AdjustmentException}), not silently
     * defaulted to either account.
     *
     * @return array{0:string,1:?string}
     */
    private static function deriveIdentity(string $accountKey): array
    {
        if ($accountKey === LedgerRepository::MARKETPLACE_ACCOUNT_KEY) {
            return ['marketplace', null];
        }

        $prefixLength = strlen(self::ACCOUNT_KEY_SELLER_PREFIX);
        if (str_starts_with($accountKey, self::ACCOUNT_KEY_SELLER_PREFIX) && strlen($accountKey) > $prefixLength) {
            return ['seller', substr($accountKey, $prefixLength)];
        }

        throw new AdjustmentException("Unrecognized account key: \"{$accountKey}\".");
    }
}
