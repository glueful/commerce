<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;

/**
 * The per-account settlement-posting serialization boundary (design spec
 * §2.6): because balances are DERIVED (a `SUM` over the append-only ledger,
 * §2.9), a payout's available-balance recheck can otherwise race a
 * concurrent posting for the same `(tenant_uuid, account_key, currency)`
 * account. This is the EXACT `MarketplaceWorkspaceLock` (MV1, §4 lock order)
 * idiom, generalized from that class's single-`tenant_uuid` key to this
 * table's compound `(tenant_uuid, account_key, currency)` key -- a minimal
 * (balance-less) anchor row (identity + `revision` only); the claim is an
 * affected-row-checked `revision` bump with savepoint-guarded lazy
 * first-row creation.
 *
 * {@see self::claim()} MUST be called from INSIDE the caller's own open
 * transaction -- the caller's transaction is the unit of work this lock
 * serializes; a claim that is not rolled back with the rest of that unit of
 * work would leave the coordination counter bumped for a posting that never
 * actually happened.
 *
 * Multi-account transactions (a multi-seller payment or refund, or a
 * seller-plus-marketplace refund) MUST claim every account lock they need in
 * sorted `account_key`, then `currency`, order to avoid deadlock (design
 * spec §2.6/§4) -- this class claims exactly one account at a time; ordering
 * multiple claims is the caller's responsibility.
 */
final class LedgerAccountLock
{
    /**
     * Idempotently ensures a `(tenant_uuid, account_key, currency)` anchor
     * row exists, then claims it via an affected-row-checked revision bump.
     * Never returns until the claim has actually succeeded (affected === 1);
     * this table has no delete path, so the retry loop is defensive, not an
     * expected outcome.
     */
    public function claim(ApplicationContext $context, string $tenant, string $accountKey, string $currency): void
    {
        while (true) {
            $this->ensureRow($context, $tenant, $accountKey, $currency);

            $affected = db($context)->table('commerce_ledger_account_locks')->executeModification(
                <<<'SQL'
UPDATE commerce_ledger_account_locks
SET revision = revision + 1
WHERE tenant_uuid = ? AND account_key = ? AND currency = ?
SQL,
                [$tenant, $accountKey, $currency]
            );

            if ($affected === 1) {
                return;
            }
        }
    }

    /**
     * Always attempts the insert directly rather than checking-then-inserting
     * -- the house duplicate-key probe convention (see
     * {@see \Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock::ensureRow()}):
     * a check-then-insert has the IDENTICAL race window this method exists
     * to close.
     *
     * The insert runs inside a NESTED transaction (a SAVEPOINT) -- this
     * method's contract is to always run inside the caller's own open
     * transaction (a bare/top-level call simply makes this the outermost
     * transaction instead, still correct, just not the intended usage).
     * PostgreSQL aborts the ENTIRE enclosing transaction on any failed
     * statement unless that statement was scoped inside its own savepoint;
     * without this nesting a duplicate-account race would poison the
     * caller's whole transaction rather than just this insert attempt.
     *
     * Unlike `MarketplaceWorkspaceLock`, this table has no OTHER unique
     * column (no `uuid`) -- the only unique constraint is
     * `(tenant_uuid, account_key, currency)` itself, so a caught
     * `\PDOException` here has exactly one possible cause: a concurrent (or
     * sequentially earlier) `claim()` already inserted this exact account's
     * row. Re-reading by the full compound key confirms that and swallows
     * the exception; if the row still is not there, the failure is
     * unrelated (e.g. a schema-level constraint violation) and is rethrown.
     */
    private function ensureRow(ApplicationContext $context, string $tenant, string $accountKey, string $currency): void
    {
        try {
            db($context)->transaction(function () use ($context, $tenant, $accountKey, $currency): void {
                db($context)->table('commerce_ledger_account_locks')->insert([
                    'tenant_uuid' => $tenant,
                    'account_key' => $accountKey,
                    'currency' => $currency,
                    'revision' => 0,
                ]);
            });
        } catch (\PDOException $e) {
            if ($this->findRow($context, $tenant, $accountKey, $currency) === null) {
                throw $e;
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function findRow(ApplicationContext $context, string $tenant, string $accountKey, string $currency): ?array
    {
        return db($context)->table('commerce_ledger_account_locks')
            ->where('tenant_uuid', '=', $tenant)
            ->where('account_key', '=', $accountKey)
            ->where('currency', '=', $currency)
            ->first();
    }
}
