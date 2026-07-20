<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\DestinationStatus;
use Glueful\Extensions\Contracts\Payments\PayoutCollector;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;

/**
 * Provider-sourced payout-destination readiness (design spec §2.7, MV4
 * Task 8): Commerce stores no raw bank/KYC/PII data -- only an opaque,
 * provider-owned `account_ref` per (tenant, seller, provider). Readiness is
 * NEVER operator-asserted; the only two things that ever move a row's
 * `readiness_state` are {@see self::attach()} (always resets to `pending`
 * -- a changed/new destination invalidates whatever was previously synced)
 * and {@see self::sync()} (applies whatever
 * `PayoutCollector::inspectDestination()` actually reports). There is
 * deliberately no "mark ready" method.
 *
 * {@see self::requireReady()} is the reserve-time gate
 * {@see PayoutService::execute()} calls BEFORE claiming the ledger account
 * lock, inserting a `commerce_payouts` row, or posting a `reserve_hold` --
 * it never mutates anything, purely a guarded read against already-synced
 * local state (no provider I/O at reserve time).
 */
final class PayoutAccountService
{
    /** @var callable(): string */
    private $uuidGenerator;

    /**
     * @param (callable(): string)|null $uuidGenerator Injectable seam for tests, same
     *     idiom as {@see PayoutService}/{@see SellerService}; defaults to the house
     *     {@see Utils::generateNanoID()} generator.
     * @param ?PayoutCollector $collector soft-bound provider-payout port (design spec
     *     §2.9); required only by {@see self::sync()} -- {@see self::attach()} and
     *     {@see self::requireReady()} never perform provider I/O and work with it null.
     */
    public function __construct(
        private readonly PayoutAccountRepository $accounts,
        ?callable $uuidGenerator = null,
        private readonly ?PayoutCollector $collector = null,
    ) {
        $this->uuidGenerator = $uuidGenerator ?? static fn (): string => Utils::generateNanoID();
    }

    /**
     * Attach (insert or replace) the opaque destination reference for one
     * (tenant, seller, provider). Always lands `readiness_state = pending`
     * (design spec §2.7) -- a fresh reference has never been inspected, so
     * it can never be asserted ready here. `$actorUuid` is accepted for
     * parity with the operator-authority surfaces (design spec §6) that
     * will call this; the table itself carries no per-row actor column.
     *
     * @return array<string,mixed> the resulting `commerce_seller_payout_accounts` row
     * @throws PayoutException a blank provider or account reference (422)
     */
    public function attach(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $provider,
        string $accountRef,
        ?string $actorUuid
    ): array {
        $provider = trim($provider);
        if ($provider === '') {
            throw new PayoutException('A non-empty payout provider is required.');
        }

        $accountRef = trim($accountRef);
        if ($accountRef === '') {
            throw new PayoutException('A non-empty payout account reference is required.');
        }

        return $this->accounts->upsert($context, $tenant, $sellerUuid, $provider, $accountRef, $this->uuidGenerator);
    }

    /**
     * DNS-style readiness sync (design spec §2.7): snapshot
     * `(uuid, provider, account_ref)` from the persisted row -> call
     * `PayoutCollector::inspectDestination()` strictly OUTSIDE any database
     * transaction/lock -> guarded apply
     * ({@see PayoutAccountRepository::applySyncResult()}) against that EXACT
     * snapshot. A concurrent {@see self::attach()} between the snapshot and
     * the apply makes this call's result stale, and the guarded update
     * silently no-ops rather than marking a destination the collector never
     * actually inspected.
     *
     * @return array<string,mixed> the row's state AFTER this sync attempt --
     *     the freshly-applied readiness on success, or the unchanged current
     *     row when a concurrent reattach made the apply a no-op
     * @throws PayoutException no {@see PayoutCollector} is bound (422)
     * @throws NotFoundException no account row exists for (seller, provider)
     */
    public function sync(ApplicationContext $context, string $tenant, string $sellerUuid, string $provider): array
    {
        $collector = $this->collector
            ?? throw new PayoutException('No payout provider is configured for destination sync.');

        $row = $this->accounts->findBySellerProvider($context, $tenant, $sellerUuid, $provider)
            ?? throw new NotFoundException('Resource not found.');

        $uuid = (string) $row['uuid'];
        $accountRef = (string) $row['account_ref'];

        $status = $collector->inspectDestination($context, new PayoutDestination($provider, $accountRef));

        $this->accounts->applySyncResult(
            $context,
            $tenant,
            $uuid,
            $provider,
            $accountRef,
            $status->state,
            $status->failureCode
        );

        return $this->accounts->findByUuid($context, $tenant, $uuid) ?? $row;
    }

    /**
     * The reserve-time readiness gate (design spec §2.7): returns the
     * account row iff it exists AND `readiness_state = ready`. No row
     * (destination never configured) is treated identically to an
     * explicitly non-ready row -- both are "unconfigured/not ready" from
     * the payout saga's perspective. Never performs provider I/O; purely a
     * guarded read of whatever the last {@see self::sync()} recorded.
     *
     * @return array<string,mixed> the ready account row
     * @throws PayoutException no ready destination is configured (422)
     */
    public function requireReady(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $provider
    ): array {
        $row = $this->accounts->findBySellerProvider($context, $tenant, $sellerUuid, $provider);

        if ($row === null || (string) ($row['readiness_state'] ?? '') !== DestinationStatus::READY) {
            throw new PayoutException(sprintf(
                'No ready payout destination is configured for seller %s (provider %s).',
                $sellerUuid,
                $provider
            ));
        }

        return $row;
    }
}
