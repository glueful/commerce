<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders\Downloads;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\UtcNowSql;

/**
 * `commerce_download_grants` persistence. Every read here except
 * {@see self::findByTokenHashGlobal()} is tenant-scoped by a hard predicate — the
 * table carries its own `tenant_uuid` column (stamped from the issuing order, see
 * {@see DownloadGrantService}), so no join through `commerce_orders` is required.
 */
final class DownloadGrantRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_download_grants')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_download_grants')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /** @return list<array<string,mixed>> every grant issued for the order, tenant-scoped */
    public function findForOrder(ApplicationContext $context, string $tenant, string $orderUuid): array
    {
        return db($context)->table('commerce_download_grants')
            ->where('tenant_uuid', '=', $tenant)
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /**
     * The idempotency-key read: backs both the pre-insert fast path (skip issuance
     * for a download that already has a grant) and the post-conflict probe
     * {@see DownloadGrantService} runs after a duplicate-key `\PDOException` — SQLite
     * discards the `uniq_grant_order_download` constraint name inline (verified,
     * migration 008 docblock), so re-reading by (order, download) is the only portable
     * way to confirm THIS unique lost the race rather than the separately named
     * `uniq_grant_token_hash` one.
     *
     * @return array<string,mixed>|null
     */
    public function findByOrderAndDownload(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        string $downloadUuid
    ): ?array {
        return db($context)->table('commerce_download_grants')
            ->where('tenant_uuid', '=', $tenant)
            ->where('order_uuid', '=', $orderUuid)
            ->where('download_uuid', '=', $downloadUuid)
            ->first();
    }

    /**
     * Correlation-style GLOBAL lookup (payvia precedent) — the ONLY query on this
     * repository that is intentionally NOT tenant-scoped. The email deep-link (design
     * spec §4.2, Task 5) arrives tenantless: a raw bearer token, hashed, with no tenant
     * predicate available at all, because the caller does not know which tenant issued
     * it. `token_hash` is a GLOBAL unique for exactly this reason (migration 008). The
     * caller MUST treat the returned row's own `tenant_uuid` column as authoritative for
     * every subsequent read/write in that request — never re-derive or trust an
     * ambient/request tenant once a grant has been located this way.
     *
     * @return array<string,mixed>|null
     */
    public function findByTokenHashGlobal(ApplicationContext $context, string $tokenHash): ?array
    {
        return db($context)->table('commerce_download_grants')
            ->where('token_hash', '=', $tokenHash)
            ->first();
    }

    /**
     * The atomic mint primitive (design spec §4.1, verbatim): one affected-row-checked
     * UPDATE, guarded on every consumption predicate at once, using DATABASE time (not
     * PHP time) for the expiry comparison. The DB-time expression is
     * {@see \Glueful\Extensions\Commerce\Support\UtcNowSql::expression()}, not the bare
     * `CURRENT_TIMESTAMP` keyword: that keyword is UTC by definition on SQLite and MySQL,
     * but on PostgreSQL it is a `timestamptz` that gets implicitly cast to the SESSION's
     * local timezone when compared against/assigned to the naive `timestamp` columns
     * here (`expires_at`, `last_minted_at`) -- under a non-UTC PostgreSQL session that
     * silently shifts the comparison by the session's UTC offset, failing an expiry
     * check OPEN. `UtcNowSql::expression()` pins the value to UTC per driver so the
     * comparison is correct regardless of session timezone. Embedding the comparison in
     * the UPDATE's own WHERE avoids ANY PHP-vs-DB clock skew and keeps the whole guard
     * atomic with the mutation.
     *
     * The "not fully refunded" predicate is deliberately NOT part of this SQL: the
     * caller ({@see \Glueful\Extensions\Commerce\Orders\Downloads\DownloadAccessService})
     * re-reads the order's refund totals immediately after claiming the shared
     * `claimOrderFinancialMutation()` row lock, inside the SAME transaction this UPDATE
     * runs in -- that claim already serializes every concurrent mint/refund-completion
     * for this order, so a PHP-side gate evaluated right after the re-read is race-safe
     * and this method is never called at all when that gate blocks the mint.
     *
     * Returns true iff exactly one row was affected (the mint is authorized and
     * consumed); zero rows means SOME guard failed and the caller must classify why via
     * {@see self::classify()}.
     */
    public function mint(ApplicationContext $context, string $tenant, string $orderUuid, string $uuid): bool
    {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        $affected = db($context)->table('commerce_download_grants')->executeModification(
            <<<SQL
UPDATE commerce_download_grants
SET mint_count = mint_count + 1,
    last_minted_at = {$utcNow},
    remaining = CASE WHEN remaining IS NULL THEN NULL ELSE remaining - 1 END
WHERE tenant_uuid = ?
  AND order_uuid = ?
  AND uuid = ?
  AND revoked_at IS NULL
  AND (expires_at IS NULL OR expires_at > {$utcNow})
  AND (remaining IS NULL OR remaining > 0)
SQL,
            [$tenant, $orderUuid, $uuid]
        );

        return $affected === 1;
    }

    /**
     * Read-only classification for a mint that affected zero rows (design spec §4.1):
     * distinguishes revoked/expired/exhausted so the caller can answer a coded 410.
     * "Blocked by full refund" is never returned here -- the caller's PHP-side gate
     * (see {@see self::mint()}'s docblock) short-circuits BEFORE attempting the guarded
     * UPDATE at all in that case, so this method is never reached for it. Priority order
     * when multiple guards independently fail (e.g. a grant both revoked and expired):
     * revoked (an explicit operator kill-switch) outranks expired, which outranks
     * exhausted. Uses PHP UTC "now" (not another DB round-trip) for the expiry
     * comparison, matching the UTC convention `expires_at` was written with — this is a
     * read-only tie-break choosing which 410 CODE to report after the guarded UPDATE
     * already authoritatively denied the mint using true DB time, so the negligible
     * clock-skew window here cannot mis-authorize anything.
     *
     * @return string|null one of 'revoked'|'expired'|'exhausted', or null if the grant
     *     is missing (already deleted) or none of the three known guards explain the
     *     zero-row result (defensive fallback for the caller).
     */
    public function classify(ApplicationContext $context, string $tenant, string $uuid): ?string
    {
        $grant = $this->findByUuid($context, $tenant, $uuid);
        if ($grant === null) {
            return null;
        }

        if ($grant['revoked_at'] !== null) {
            return 'revoked';
        }

        if ($grant['expires_at'] !== null && (string) $grant['expires_at'] <= gmdate('Y-m-d H:i:s')) {
            return 'expired';
        }

        if ($grant['remaining'] !== null && (int) $grant['remaining'] <= 0) {
            return 'exhausted';
        }

        return null;
    }
}
