<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;

/**
 * The shared per-workspace serialization boundary (design spec §4 lock order:
 * "workspace-settings claim first" -- every marketplace mutation flow claims
 * this before any seller or product claim). Used by mode transitions
 * (activate/deactivate, Task 3) and by EVERY product create once the install
 * master switch is on (Task 3): claiming this row before reading mode or
 * catalog state is what makes activation-vs-create deterministic rather than
 * merely test-observed.
 *
 * {@see self::claim()} MUST be called from INSIDE the caller's own open
 * transaction -- the caller's transaction is the unit of work this lock
 * serializes; a claim that is not rolled back with the rest of that unit of
 * work would leave the coordination counter bumped for a mutation that never
 * actually happened.
 */
final class MarketplaceWorkspaceLock
{
    /** @var callable(): string */
    private $uuidGenerator;

    /**
     * @param (callable(): string)|null $uuidGenerator Injectable seam for tests forcing a
     *     `uuid`-collision (an UNRELATED failure, see {@see self::ensureRow()}'s docblock);
     *     defaults to the house {@see Utils::generateNanoID()} generator.
     */
    public function __construct(?callable $uuidGenerator = null)
    {
        $this->uuidGenerator = $uuidGenerator ?? static fn (): string => Utils::generateNanoID();
    }

    /**
     * Idempotently ensures a `disabled` settings row exists for the tenant,
     * then claims it via an affected-row-checked revision bump. Never
     * returns until the claim has actually succeeded (affected === 1); if
     * the claimed row somehow is not there anymore (this table has no
     * delete path today, so this is a defensive loop, not an expected
     * outcome) it re-ensures and retries rather than silently no-op'ing.
     */
    public function claim(ApplicationContext $context, string $tenant): void
    {
        while (true) {
            $this->ensureRow($context, $tenant);

            $affected = db($context)->table('commerce_marketplace_settings')->executeModification(
                <<<'SQL'
UPDATE commerce_marketplace_settings SET revision = revision + 1 WHERE tenant_uuid = ?
SQL,
                [$tenant]
            );

            if ($affected === 1) {
                return;
            }
        }
    }

    /**
     * Always attempts the insert directly rather than checking-then-inserting
     * -- the house duplicate-key probe convention (see
     * {@see \Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantService::ensureOne()}):
     * a check-then-insert has the IDENTICAL race window this method exists to
     * close (another connection can insert between the check and the insert),
     * so the check would only move the problem, never remove it.
     *
     * The insert runs inside a NESTED transaction -- a SAVEPOINT, since
     * {@see self::claim()}'s contract is to always run inside the caller's
     * own open transaction (a bare/top-level call simply makes this the
     * outermost transaction instead, which is still correct, just not the
     * intended usage). PostgreSQL aborts the ENTIRE enclosing transaction on
     * any failed statement unless that statement was scoped inside its own
     * savepoint; without this nesting a duplicate-tenant race would poison
     * the caller's whole transaction rather than just this insert attempt.
     *
     * On a caught `\PDOException`, the nested `transaction()` call has
     * ALREADY rolled back to the savepoint before this catch runs
     * ({@see \Glueful\Database\Transaction\TransactionManager::transaction()}
     * calls `rollback()` before rethrowing on any non-deadlock exception), so
     * the caller's outer transaction is guaranteed usable by the time this
     * method re-reads. Re-reading by `tenant_uuid` (never by parsing the
     * driver's constraint name -- SQLite discards inline UNIQUE constraint
     * names, same limitation documented for `commerce_download_grants`)
     * classifies the failure: a row now present means the verified
     * tenant-unique conflict lost the race (a concurrent, or sequentially
     * earlier, `claim()` already inserted it) and is swallowed; still no row
     * means the failure is unrelated to the tenant-unique constraint (e.g. a
     * `uuid` collision against a DIFFERENT tenant's row) and is rethrown.
     */
    private function ensureRow(ApplicationContext $context, string $tenant): void
    {
        try {
            db($context)->transaction(function () use ($context, $tenant): void {
                db($context)->table('commerce_marketplace_settings')->insert([
                    'uuid' => ($this->uuidGenerator)(),
                    'tenant_uuid' => $tenant,
                    'status' => 'disabled',
                    'revision' => 0,
                ]);
            });
        } catch (\PDOException $e) {
            if ($this->findRow($context, $tenant) === null) {
                throw $e;
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function findRow(ApplicationContext $context, string $tenant): ?array
    {
        return db($context)->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', $tenant)
            ->first();
    }
}
