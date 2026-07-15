<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders\Downloads;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Helpers\Utils;

/**
 * Snapshot-derived, idempotent, self-healing digital-download grant issuance (design
 * spec §3). Reads ONLY the order lines' purchase-time `downloads` snapshots (folded
 * onto `commerce_order_lines`, Task 3) — never the live `commerce_downloads`
 * definition table, so a definition edit or delete after checkout can never alter an
 * already-issued (or yet-to-be-issued) grant.
 *
 * Called from three independent recovery surfaces (all converge on
 * {@see self::ensureGrantsForOrder()} / {@see self::issueAndCollectForOrder()}, both
 * idempotent): the `OrderPaid` mail listener (primary path, needs the raw tokens),
 * every lazy order-authenticated download request (heals a partially- or
 * entirely-missing set on every qualifying request), and the `commerce:downloads:backfill`
 * operator CLI. None of the three wraps the whole operation in a single transaction —
 * each grant row is inserted with its own atomic INSERT, so a mid-order failure leaves a
 * partially-issued order that a subsequent call repairs by only inserting the still-missing
 * tail (design spec §3, "self-healing").
 *
 * Idempotency arbiter: the unique `(order_uuid, download_uuid)` constraint. A duplicate-key
 * `\PDOException` on insert is resolved by re-reading (order, download) — the T2-verified
 * SQLite limitation (custom UNIQUE constraint names are discarded for inline
 * `CREATE TABLE` uniques; see migration 008) makes constraint-name parsing non-portable, so
 * this probe is the only portable way to tell that collision apart from the separately named
 * GLOBAL `token_hash` unique. The two are never conflated: an (order, download) collision
 * reloads and stops (no raw token for this call); a token collision regenerates a fresh
 * token and retries the SAME insert, bounded by {@see self::MAX_TOKEN_ATTEMPTS}.
 */
final class DownloadGrantService
{
    private const QUALIFYING_STATUSES = ['paid', 'fulfilled', 'refunded'];

    /** Bounded retries for a token_hash collision — see class docblock. */
    private const MAX_TOKEN_ATTEMPTS = 3;

    /** @var callable(): array{raw: string, hash: string} */
    private $tokenGenerator;

    /**
     * @param (callable(): array{raw: string, hash: string})|null $tokenGenerator Injectable
     *     seam for tests exercising the token-collision retry path; defaults to the house
     *     160-bit/20-byte generator ({@see TokenHasher::generate()}) already used for cart
     *     and order guest tokens (design spec §4.2).
     */
    public function __construct(
        private OrderRepository $orders,
        private DownloadGrantRepository $grants,
        ?callable $tokenGenerator = null,
    ) {
        $this->tokenGenerator = $tokenGenerator ?? static fn (): array => TokenHasher::generate();
    }

    /**
     * Idempotent issuance without raw tokens — the primitive the two "no new
     * credential" recovery surfaces (lazy order-authenticated access, backfill) use.
     *
     * @param array<string,mixed> $order
     * @return list<array<string,mixed>>
     */
    public function ensureGrantsForOrder(ApplicationContext $c, array $order): array
    {
        return $this->issueAndCollectForOrder($c, $order)['grants'];
    }

    /**
     * Same idempotent issuance, but also collects the raw bearer token for every grant
     * THIS call created — never for grants that already existed. Raw tokens exist only
     * at issuance (design spec §3): the row stores only the hash, so a re-read (or a
     * later call for the same order) can never reproduce them.
     *
     * @param array<string,mixed> $order
     * @return array{grants: list<array<string,mixed>>, raw_tokens: array<string,string>}
     */
    public function issueAndCollectForOrder(ApplicationContext $c, array $order): array
    {
        $tenant = (string) $order['tenant_uuid'];
        $orderUuid = (string) $order['uuid'];

        if (!in_array((string) $order['status'], self::QUALIFYING_STATUSES, true)) {
            return ['grants' => [], 'raw_tokens' => []];
        }

        $specs = $this->deriveSpecs($c, $tenant, $orderUuid);
        if ($specs === []) {
            return ['grants' => $this->grants->findForOrder($c, $tenant, $orderUuid), 'raw_tokens' => []];
        }

        $existingDownloads = $this->existingDownloadUuids($c, $tenant, $orderUuid);

        $rawTokens = [];
        foreach ($specs as $downloadUuid => $spec) {
            if (isset($existingDownloads[$downloadUuid])) {
                continue;
            }

            $result = $this->ensureOne($c, $tenant, $orderUuid, $downloadUuid, $spec);
            if ($result['raw_token'] !== null) {
                $rawTokens[$result['uuid']] = $result['raw_token'];
            }
        }

        return [
            'grants' => $this->grants->findForOrder($c, $tenant, $orderUuid),
            'raw_tokens' => $rawTokens,
        ];
    }

    /**
     * Read-only preview: how many grants issuance would create vs. how many already
     * exist for this order, without writing anything. Powers the backfill CLI's
     * `--dry-run` and its per-order created/skipped report (both modes share this one
     * counting path so the numbers can never drift between dry-run and real runs).
     *
     * @param array<string,mixed> $order
     * @return array{needed: int, existing: int}
     */
    public function previewForOrder(ApplicationContext $c, array $order): array
    {
        if (!in_array((string) $order['status'], self::QUALIFYING_STATUSES, true)) {
            return ['needed' => 0, 'existing' => 0];
        }

        $tenant = (string) $order['tenant_uuid'];
        $orderUuid = (string) $order['uuid'];

        $specs = $this->deriveSpecs($c, $tenant, $orderUuid);
        if ($specs === []) {
            return ['needed' => 0, 'existing' => 0];
        }

        $existingDownloads = $this->existingDownloadUuids($c, $tenant, $orderUuid);
        $existing = 0;
        foreach (array_keys($specs) as $downloadUuid) {
            if (isset($existingDownloads[$downloadUuid])) {
                $existing++;
            }
        }

        return ['needed' => count($specs) - $existing, 'existing' => $existing];
    }

    /** @return array<string,true> download_uuid => true, for O(1) membership checks */
    private function existingDownloadUuids(ApplicationContext $c, string $tenant, string $orderUuid): array
    {
        $existing = [];
        foreach ($this->grants->findForOrder($c, $tenant, $orderUuid) as $grant) {
            $existing[(string) $grant['download_uuid']] = true;
        }

        return $existing;
    }

    /**
     * Groups the order's digital line snapshots by download_uuid, summing purchased
     * quantity across EVERY matching line — including add-on-distinct lines for the
     * same variant, which the checkout snapshot (Task 3) gives identical `downloads`
     * entries since it is derived from the variant, not the add-on-hashed line. A
     * physical line's `downloads` is `null` (skip); a digital line's `downloads` is a
     * (possibly empty) list.
     *
     * @return array<string, array{blob_uuid: string, name: string, remaining: int|null, expires_at: string|null}>
     *     keyed by download_uuid
     */
    private function deriveSpecs(ApplicationContext $c, string $tenant, string $orderUuid): array
    {
        $lines = $this->orders->linesForOrder($c, $tenant, $orderUuid);

        /** @var array<string, array{blob_uuid: string, name: string, download_limit: int|null, expiry_days: int|null, quantity: int}> $accumulated */
        $accumulated = [];
        foreach ($lines as $line) {
            $downloads = $line['downloads'] ?? null;
            if (!is_array($downloads) || $downloads === []) {
                continue;
            }

            $quantity = (int) $line['quantity'];
            foreach ($downloads as $entry) {
                $downloadUuid = (string) $entry['download_uuid'];
                if (!isset($accumulated[$downloadUuid])) {
                    $accumulated[$downloadUuid] = [
                        'blob_uuid' => (string) $entry['blob_uuid'],
                        'name' => (string) $entry['name'],
                        'download_limit' => $entry['download_limit'] !== null ? (int) $entry['download_limit'] : null,
                        'expiry_days' => $entry['expiry_days'] !== null ? (int) $entry['expiry_days'] : null,
                        'quantity' => 0,
                    ];
                }
                $accumulated[$downloadUuid]['quantity'] += $quantity;
            }
        }

        $specs = [];
        $now = time();
        foreach ($accumulated as $downloadUuid => $entry) {
            $specs[$downloadUuid] = [
                'blob_uuid' => $entry['blob_uuid'],
                'name' => $entry['name'],
                'remaining' => $this->computeRemaining($downloadUuid, $entry['download_limit'], $entry['quantity']),
                'expires_at' => $entry['expiry_days'] !== null
                    ? gmdate('Y-m-d H:i:s', $now + ($entry['expiry_days'] * 86400))
                    : null,
            ];
        }

        return $specs;
    }

    /** null download_limit => unlimited => null remaining. Overflow-checked otherwise. */
    private function computeRemaining(string $downloadUuid, ?int $limit, int $quantity): ?int
    {
        if ($limit === null) {
            return null;
        }

        if ($limit !== 0 && $quantity !== 0 && $limit > intdiv(PHP_INT_MAX, $quantity)) {
            throw new DownloadGrantOverflowException($downloadUuid, $limit, $quantity);
        }

        return $limit * $quantity;
    }

    /**
     * @param array{blob_uuid: string, name: string, remaining: int|null, expires_at: string|null} $spec
     * @return array{uuid: string, raw_token: string|null} raw_token is null when an
     *     already-existing row was reloaded rather than created by this call
     */
    private function ensureOne(
        ApplicationContext $c,
        string $tenant,
        string $orderUuid,
        string $downloadUuid,
        array $spec
    ): array {
        $attempts = 0;

        while (true) {
            $attempts++;
            $token = ($this->tokenGenerator)();
            $uuid = Utils::generateNanoID();

            try {
                $this->grants->insert($c, [
                    'uuid' => $uuid,
                    'tenant_uuid' => $tenant,
                    'order_uuid' => $orderUuid,
                    'download_uuid' => $downloadUuid,
                    'blob_uuid' => $spec['blob_uuid'],
                    'name' => $spec['name'],
                    'token_hash' => $token['hash'],
                    'remaining' => $spec['remaining'],
                    'expires_at' => $spec['expires_at'],
                ]);

                return ['uuid' => $uuid, 'raw_token' => $token['raw']];
            } catch (\PDOException $e) {
                // Portable duplicate-key classification (see class docblock): probe by
                // (order, download) rather than parsing the driver's constraint name.
                $reloaded = $this->grants->findByOrderAndDownload($c, $tenant, $orderUuid, $downloadUuid);
                if ($reloaded !== null) {
                    // uniq_grant_order_download lost the race -- a concurrent caller
                    // already issued this grant. Never conflated with a token collision.
                    return ['uuid' => (string) $reloaded['uuid'], 'raw_token' => null];
                }

                // (order_uuid, download_uuid) is still free, so the failure can only be
                // the separately named global uniq_grant_token_hash. Regenerate and
                // retry the SAME insert, bounded.
                if ($attempts >= self::MAX_TOKEN_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }
}
