<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * The CLOSED admin-side projection of one payment link (payment-links Task 6,
 * design spec §2.2): everything an authenticated operator surface may learn
 * about a link, and nothing else.
 *
 * Four fields, fixed:
 *  - `linkUuid` -- the link's opaque identity. This, never the token, is what
 *    later calls (revoke, status, the return-URL seam) address a link by.
 *  - `status` -- one of {@see PaymentLinkRepository::STATUSES}, already carrying
 *    any lazy transition the read applied (a past-TTL link reads `expired`, a
 *    paid order's link reads `consumed`), so the operator never sees a state the
 *    customer's own page would contradict.
 *  - `expiresAt` -- UTC `Y-m-d H:i:s`, the same canonical form the table stores.
 *  - `providerSessionIssued` -- whether a provider checkout session was EVER
 *    exposed for this link. This is the fact the expiry/cancel guard acts on
 *    (§2.2), so the operator sees it before deciding to cancel an order.
 *
 * DELIBERATELY ABSENT: the raw token, the token hash, the order uuid, the tenant
 * uuid, the initiation counter, and the creating actor. The token and its hash
 * because custody forbids it; the rest because the caller already knows the
 * order it asked about and has no use for internal ids. `PaymentLinkServiceTest`
 * pins the serialized key set exactly, so a field cannot be added here without
 * being re-reviewed.
 */
final readonly class PaymentLinkAdminView
{
    public function __construct(
        public string $linkUuid,
        public string $status,
        public string $expiresAt,
        public bool $providerSessionIssued,
    ) {
    }

    /**
     * @return array{
     *     link_uuid: string,
     *     status: string,
     *     expires_at: string,
     *     provider_session_issued: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'link_uuid' => $this->linkUuid,
            'status' => $this->status,
            'expires_at' => $this->expiresAt,
            'provider_session_issued' => $this->providerSessionIssued,
        ];
    }
}
