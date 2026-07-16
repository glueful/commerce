<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders\Downloads;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Http\Exceptions\Client\NotFoundException;

/**
 * The atomic mint chain (design spec §4.1, verbatim-binding) shared by BOTH access
 * paths: order-authenticated (`OrderController::downloadUrl()`) and the email deep
 * link (`DownloadLinkController`). One transaction:
 *
 *  1. Claim the order via the shared `OrderRepository::claimOrderFinancialMutation()`
 *     row (the same primitive refunds use) -- unknown/cross-tenant order => 404.
 *  2. Re-read the order's refund totals under that claim.
 *  3. Compute `blocked_by_full_refund` from the POST-CLAIM totals
 *     (`grand_total > 0 AND refunded_total >= grand_total`) unless the grant
 *     carries an audited override. The `grand_total > 0` guard keeps a FREE ($0
 *     grand_total) order from being confused with a fully-refunded one -- `0 >=
 *     0` would otherwise be trivially true and permanently block a free order's
 *     downloads. The claim serializes every concurrent mint/refund-completion for
 *     this order, so this PHP-side gate -- evaluated immediately after the claimed
 *     re-read, inside the same transaction -- is race-safe (see
 *     {@see DownloadGrantRepository::mint()}'s docblock). When blocked, the guarded
 *     UPDATE is never attempted at all.
 *  4. Build the signed URL ({@see DownloadUrlSigner}) -- PURE work. A signing or
 *     configuration failure throws {@see DownloadSigningException} here, BEFORE any
 *     grant mutation, which rolls back the WHOLE transaction (including step 1's
 *     claim bump): a signing failure consumes nothing.
 *  5. Attempt the ONE guarded grant UPDATE ({@see DownloadGrantRepository::mint()}).
 *     Exactly one affected row => success, return the prebuilt URL. Zero rows =>
 *     classify why ({@see DownloadGrantRepository::classify()}) and report a coded
 *     410 reason.
 */
final class DownloadAccessService
{
    public function __construct(
        private OrderRepository $orders,
        private DownloadGrantRepository $grants,
        private DownloadUrlSigner $signer,
    ) {
    }

    /**
     * @return array{ok: true, url: string, expires_in: int}|array{ok: false, code: string}
     * @throws NotFoundException unknown order, cross-tenant order, or a grant uuid
     *     that doesn't exist (or doesn't belong to this order) -- never revealing
     *     which, to avoid leaking grant existence across orders.
     * @throws DownloadSigningException see class docblock, step 4.
     */
    public function mint(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        string $grantUuid,
        string $requestBase
    ): array {
        return db($context)->transaction(
            function () use ($context, $tenant, $orderUuid, $grantUuid, $requestBase): array {
                if (!$this->orders->claimOrderFinancialMutation($context, $tenant, $orderUuid)) {
                    throw new NotFoundException('Resource not found.');
                }

                $order = $this->orders->findByUuid($context, $tenant, $orderUuid);
                if ($order === null) {
                    throw new NotFoundException('Resource not found.');
                }

                $grant = $this->grants->findByUuid($context, $tenant, $grantUuid);
                if ($grant === null || (string) $grant['order_uuid'] !== $orderUuid) {
                    throw new NotFoundException('Resource not found.');
                }

                $grandTotal = (int) $order['grand_total'];
                $fullyRefunded = $grandTotal > 0 && (int) $order['refunded_total'] >= $grandTotal;
                if ($fullyRefunded && $grant['refund_access_override_at'] === null) {
                    return ['ok' => false, 'code' => 'blocked_by_full_refund'];
                }

                $signed = $this->signer->sign($context, (string) $grant['blob_uuid'], $requestBase);

                $minted = $this->grants->mint($context, $tenant, $orderUuid, $grantUuid);
                if ($minted) {
                    return ['ok' => true, 'url' => $signed['url'], 'expires_in' => $signed['expires_in']];
                }

                $code = $this->grants->classify($context, $tenant, $grantUuid) ?? 'exhausted';

                return ['ok' => false, 'code' => $code];
            }
        );
    }
}
