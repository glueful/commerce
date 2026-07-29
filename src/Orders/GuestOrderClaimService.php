<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\EmailNormalizer;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;

/**
 * Customer-safe guest-order claiming (accounts design spec §10).
 *
 * {@see OrderRepository::linkGuestToUser()} is race-safe but unguarded -- it stamps any unowned
 * order. That is right for the operator CLI and wrong for anything a visitor can drive, so this
 * service supplies the proofs a customer-initiated claim needs.
 *
 * A SERVICE, deliberately not an endpoint. Commerce cannot establish that an email is verified:
 * the post-auth `user` attribute carries no email under JWT authentication, and the users
 * extension drops `email_verified_at` when building its identity. The calling application owns
 * that context and passes an email it has actually verified.
 *
 * `claim()` requires BOTH proofs: the guest credential from checkout AND a normalized email
 * match. Email alone is not ownership -- verification proves current mailbox control, and
 * addresses get recycled, shared and mistyped. The token alone is not enough either: receipts
 * get forwarded.
 *
 * Every failure raises the SAME non-revealing 404, so this cannot be used to probe which order
 * numbers exist. Re-claiming an order you already own succeeds as a no-op.
 */
final class GuestOrderClaimService
{
    /** Orders claimed per historical-import call. Run it again to continue. */
    public const HISTORICAL_IMPORT_LIMIT = 100;

    public function __construct(
        private OrderRepository $orders,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /**
     * @return array<string,mixed> the claimed order row (guest_token_hash stripped)
     * @throws NotFoundException On any failure, always with the same message.
     */
    public function claim(
        ApplicationContext $context,
        string $userUuid,
        string $verifiedEmail,
        string $orderNumber,
        string $guestToken,
    ): array {
        $tenant = $this->tenants->tenantUuid($context);
        $order = $this->orders->findByNumber($context, $tenant, $orderNumber);
        if ($order === null || $userUuid === '') {
            throw $this->notFound();
        }

        $storedHash = (string) ($order['guest_token_hash'] ?? '');
        if ($guestToken === '' || $storedHash === '' || !hash_equals($storedHash, TokenHasher::hash($guestToken))) {
            throw $this->notFound();
        }

        $orderEmail = EmailNormalizer::normalize((string) ($order['email'] ?? ''));
        if ($orderEmail === '' || $orderEmail !== EmailNormalizer::normalize($verifiedEmail)) {
            throw $this->notFound();
        }

        $owner = $order['user_uuid'] ?? null;
        if (is_string($owner) && $owner !== '') {
            // Already owned: by this caller it is a no-op success, by anyone else it is
            // indistinguishable from an order that does not exist.
            if ($owner !== $userUuid) {
                throw $this->notFound();
            }

            return $this->projection($order);
        }

        // Race-safe: stamps only while user_uuid IS NULL. Losing the race means somebody else
        // got there first, which is exactly the "owned by another user" case.
        if (!$this->orders->linkGuestToUser($context, $tenant, (string) $order['uuid'], $userUuid)) {
            $current = $this->orders->findByNumber($context, $tenant, $orderNumber);
            if ($current !== null && ($current['user_uuid'] ?? null) === $userUuid) {
                return $this->projection($current);
            }

            throw $this->notFound();
        }

        $order['user_uuid'] = $userUuid;

        return $this->projection($order);
    }

    /**
     * Explicit historical import: claim every unowned order in this tenant whose normalized
     * email matches the caller's VERIFIED email.
     *
     * Deliberately weaker than {@see claim()} -- there is no guest credential, because a visitor
     * claiming months-old orders no longer holds one. That is exactly why this must be an
     * explicit, confirmed, audited action in the calling application and never an automatic
     * login side effect: a recycled or mistyped address would otherwise hand over a stranger's
     * shipping addresses, downloads and purchase history.
     *
     * @return list<string> order numbers actually claimed by this call
     */
    public function claimAllByVerifiedEmail(
        ApplicationContext $context,
        string $userUuid,
        string $verifiedEmail,
    ): array {
        $normalized = EmailNormalizer::normalize($verifiedEmail);
        if ($normalized === '' || $userUuid === '') {
            return [];
        }

        $tenant = $this->tenants->tenantUuid($context);
        // The `email_normalized` filter is already scoped to `user_uuid IS NULL`, so this can
        // never return an order that belongs to somebody else.
        $result = $this->orders->paginatedFor(
            $context,
            $tenant,
            ['email_normalized' => $normalized],
            1,
            self::HISTORICAL_IMPORT_LIMIT,
        );

        $claimed = [];
        foreach ($result['items'] as $order) {
            if ($this->orders->linkGuestToUser($context, $tenant, (string) $order['uuid'], $userUuid)) {
                $claimed[] = (string) $order['order_number'];
            }
        }

        return $claimed;
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function projection(array $order): array
    {
        unset($order['guest_token_hash']);

        return $order;
    }

    private function notFound(): NotFoundException
    {
        return new NotFoundException('Resource not found.');
    }
}
