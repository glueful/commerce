<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders\Downloads;

use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Uploader\Contracts\BlobAccessContext;
use Glueful\Uploader\Contracts\BlobAccessPolicy;
use Glueful\Uploader\Contracts\BlobAction;

/**
 * Commerce's contributor to the framework's `BlobAccessPolicyRegistry` (design spec
 * §5, verbatim-binding). Registered by {@see \Glueful\Extensions\Commerce\CommerceServiceProvider}
 * under the id `commerce.downloads` -- never bound as the shared `BlobAccessPolicy`
 * contract, so a host application's own primary policy (e.g. Thallo's
 * `TenantBlobPolicy`) is always AND-composed alongside this one via the framework's
 * `CompositeBlobAccessPolicy`.
 *
 * Decision table (spec §5):
 * - A blob referenced by NEITHER a current `commerce_downloads` definition NOR any
 *   `commerce_download_grants` snapshot is NEUTRAL: every action returns true
 *   (no veto). "Referenced" here means row existence, regardless of a grant's
 *   validity (revoked/expired/exhausted grants still snapshot the blob and still
 *   gate VIEW/INFO/SIGN) or a definition's active/inactive status.
 * - VIEW: referenced blobs require `signatureValid` OR the caller being the blob's
 *   creator (`authenticatedUserUuid === blob['created_by']`).
 * - INFO: creator only. The core `UploadController::info()` action NEVER validates
 *   request signatures -- it hardcodes `BlobAccessContext(..., signatureValid: false)`
 *   regardless of the request -- so this policy must not accept a signature here
 *   even though VIEW does (verified against the vendored framework controller).
 * - SIGN: always false for a referenced blob. Core's generic authenticated
 *   signed-URL endpoint must never bypass commerce's own grant-consumption mint
 *   flow (design spec §4.1); creator access remains available through direct
 *   authenticated VIEW.
 * - DELETE: false (blocked) while ANY of the following holds:
 *     1. a `commerce_downloads` row still references the blob (regardless of
 *        active/inactive `status` -- only a full `detach()`, which deletes the
 *        row, releases this), OR
 *     2. any grant snapshot still "retains access": not revoked, not expired,
 *        remaining is null or positive, and not blocked by a full refund
 *        (`grand_total > 0 AND refunded_total >= grand_total` -- the
 *        `grand_total > 0` guard keeps a FREE order from being mistaken for a
 *        fully-refunded one) unless the grant carries an audited
 *        `refund_access_override_at`, OR
 *     3. `last_minted_at + url_ttl` is still in the future for any grant snapshot
 *        (a live signed URL may still be outstanding, independent of that grant's
 *        own revoked/expired/exhausted state).
 *   Operators detach every definition and revoke/exhaust/expire every grant, then
 *   wait out the maximum signed-URL TTL, before the underlying merchandise file
 *   becomes deletable.
 *
 * Every query here is deliberately tenant-AGNOSTIC (no `tenant_uuid` predicate):
 * this policy guards the BLOB globally, not per-tenant -- a blob referenced by ANY
 * tenant's download must stay protected for every tenant. This is the one
 * intentional global read in the download subsystem, correlation-style, matching
 * {@see DownloadGrantRepository::findByTokenHashGlobal()}'s precedent. The DELETE
 * full-refund check practically requires a JOIN to `commerce_orders` (grants don't
 * carry the order's totals); `commerce_orders.uuid` is a global unique index (see
 * migration 004), so joining on `order_uuid = commerce_orders.uuid` alone is
 * unambiguous without also matching `tenant_uuid`.
 */
final class CommerceDownloadBlobPolicy implements BlobAccessPolicy
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** @param array<string,mixed> $blob */
    public function authorizeAccess(array $blob, BlobAccessContext $context): bool
    {
        $blobUuid = is_string($blob['uuid'] ?? null) ? $blob['uuid'] : '';
        if ($blobUuid === '' || !$this->isReferenced($blobUuid)) {
            return true;
        }

        return match ($context->action) {
            BlobAction::VIEW => $context->signatureValid || $this->isCreator($blob, $context),
            BlobAction::INFO => $this->isCreator($blob, $context),
            BlobAction::SIGN => false,
            BlobAction::DELETE => !$this->blocksDeletion($blobUuid),
        };
    }

    /** @param array<string,mixed> $blob */
    private function isCreator(array $blob, BlobAccessContext $context): bool
    {
        return $context->authenticatedUserUuid !== null
            && $context->authenticatedUserUuid === ($blob['created_by'] ?? null);
    }

    private function isReferenced(string $blobUuid): bool
    {
        return $this->definitionReferences($blobUuid) || $this->grantReferences($blobUuid);
    }

    private function definitionReferences(string $blobUuid): bool
    {
        return db($this->context)->table('commerce_downloads')
            ->where('blob_uuid', '=', $blobUuid)
            ->count() > 0;
    }

    private function grantReferences(string $blobUuid): bool
    {
        return db($this->context)->table('commerce_download_grants')
            ->where('blob_uuid', '=', $blobUuid)
            ->count() > 0;
    }

    /**
     * Draft isolation (admin-order-creation cycle 2, Task 8): this join is
     * deliberately NOT draft-excluded. A draft can never own a download grant
     * (grants are minted from paid orders only), so the predicate would be a
     * no-op today -- and if a draft-owned grant ever DID exist, dropping its
     * row here would remove a reason to BLOCK deletion, i.e. fail OPEN on a
     * blob still reachable by a live signed URL. A safety gate must not be
     * narrowed by an isolation rule; the exclusion belongs upstream, on the
     * order readers that decide what may become a grant at all.
     */
    private function blocksDeletion(string $blobUuid): bool
    {
        if ($this->definitionReferences($blobUuid)) {
            return true;
        }

        $ttl = CommerceSettings::downloadsUrlTtl($this->context);
        $now = time();

        $rows = db($this->context)->table('commerce_download_grants')
            ->join('commerce_orders', 'commerce_download_grants.order_uuid', '=', 'commerce_orders.uuid')
            ->select([
                'commerce_download_grants.remaining',
                'commerce_download_grants.expires_at',
                'commerce_download_grants.revoked_at',
                'commerce_download_grants.refund_access_override_at',
                'commerce_download_grants.last_minted_at',
                'commerce_orders.grand_total',
                'commerce_orders.refunded_total',
            ])
            ->where('commerce_download_grants.blob_uuid', '=', $blobUuid)
            ->get();

        foreach ($rows as $row) {
            if ($this->grantRetainsAccess($row) || $this->hasLiveUrl($row, $ttl, $now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirrors {@see DownloadGrantRepository::mint()}'s guard predicates (spec §4.1)
     * plus the full-refund gate {@see \Glueful\Extensions\Commerce\Orders\Downloads\DownloadAccessService}
     * applies before minting -- read-only here, so PHP UTC "now" (not a DB round
     * trip) is used for the expiry comparison, the same convention as
     * {@see DownloadGrantRepository::classify()}.
     *
     * @param array<string,mixed> $row
     */
    private function grantRetainsAccess(array $row): bool
    {
        if ($row['revoked_at'] !== null) {
            return false;
        }

        if ($row['expires_at'] !== null && (string) $row['expires_at'] <= gmdate('Y-m-d H:i:s')) {
            return false;
        }

        if ($row['remaining'] !== null && (int) $row['remaining'] <= 0) {
            return false;
        }

        $grandTotal = (int) $row['grand_total'];
        $fullyRefunded = $grandTotal > 0 && (int) $row['refunded_total'] >= $grandTotal;
        if ($fullyRefunded && $row['refund_access_override_at'] === null) {
            return false;
        }

        return true;
    }

    /**
     * A live signed URL protects the blob independently of the grant's own
     * revoked/expired/exhausted state: revocation cannot invalidate a URL already
     * handed out (spec §4.1), so deletion must wait out the same window.
     *
     * @param array<string,mixed> $row
     */
    private function hasLiveUrl(array $row, int $ttl, int $now): bool
    {
        if ($row['last_minted_at'] === null) {
            return false;
        }

        $minted = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            (string) $row['last_minted_at'],
            new \DateTimeZone('UTC')
        );
        if ($minted === false) {
            return false;
        }

        return ($minted->getTimestamp() + $ttl) > $now;
    }
}
