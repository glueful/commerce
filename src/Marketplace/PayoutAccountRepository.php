<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\DestinationStatus;

/**
 * CRUD over `commerce_seller_payout_accounts` (design spec §2.7/§3.2, MV4
 * Task 8): one opaque provider destination per (tenant, seller, provider).
 * Readiness is never asserted by this class -- it only persists whatever
 * {@see PayoutAccountService} decides, which itself only ever writes a
 * `readiness_state` that came from `PayoutCollector::inspectDestination()`
 * (sync) or the safe `pending` reset (attach).
 *
 * {@see self::applySyncResult()} is the guarded-update idiom (mirrors
 * {@see PayoutRepository::claimPending()} and {@see SellerRepository::claimRevision()}):
 * a sync's provider-I/O result is only ever applied against the EXACT
 * `(uuid, provider, account_ref)` snapshot it was inspected against. If the
 * account was reattached (new `account_ref`, or switched provider row)
 * between the snapshot read and this call, the WHERE clause matches zero
 * rows and the stale inspection result is silently dropped -- never applied
 * to a destination it was never actually inspecting (design spec §2.7,
 * "inspection I/O never runs under a DB lock ... guarded update only if
 * provider/account_ref still match").
 */
final class PayoutAccountRepository
{
    /** @return array<string,mixed>|null */
    public function findBySellerProvider(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $provider
    ): ?array {
        return db($context)->table('commerce_seller_payout_accounts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->where('provider', '=', $provider)
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_seller_payout_accounts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /**
     * Insert-or-update the opaque `account_ref` for one (tenant, seller,
     * provider) destination. A fresh row starts (and a re-attached existing
     * row is RESET to) `readiness_state = pending` -- design spec §2.7:
     * commerce never asserts readiness itself, and a changed `account_ref`
     * invalidates whatever readiness was previously synced against the OLD
     * ref. `last_synced_at`/`failure_code` are cleared for the same reason.
     * The `uuid` identity is stable across re-attaches of the SAME
     * (tenant, seller, provider) row -- only a fresh row mints a new one.
     *
     * Guards the (tenant_uuid, seller_uuid, provider) unique constraint the
     * same way {@see PayoutService::record()} guards
     * (tenant_uuid, idempotency_key): a concurrent first-attach can win the
     * race between this call's lookup and its own insert, so an insert-time
     * `\PDOException` falls back to the update path against the
     * now-existing row instead of surfacing a spurious failure.
     *
     * @param callable(): string $uuidGenerator
     * @return array<string,mixed> the resulting row
     */
    public function upsert(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $provider,
        string $accountRef,
        callable $uuidGenerator
    ): array {
        $existing = $this->findBySellerProvider($context, $tenant, $sellerUuid, $provider);

        if ($existing === null) {
            $uuid = $uuidGenerator();
            try {
                db($context)->table('commerce_seller_payout_accounts')->insert([
                    'uuid' => $uuid,
                    'tenant_uuid' => $tenant,
                    'seller_uuid' => $sellerUuid,
                    'provider' => $provider,
                    'account_ref' => $accountRef,
                    'readiness_state' => DestinationStatus::PENDING,
                    'last_synced_at' => null,
                    'failure_code' => null,
                ]);

                return $this->findByUuid($context, $tenant, $uuid)
                    ?? throw new \RuntimeException('Payout account row missing immediately after insert.');
            } catch (\PDOException $e) {
                $existing = $this->findBySellerProvider($context, $tenant, $sellerUuid, $provider);
                if ($existing === null) {
                    throw $e;
                }
            }
        }

        $uuid = (string) $existing['uuid'];
        db($context)->table('commerce_seller_payout_accounts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update([
                'account_ref' => $accountRef,
                'readiness_state' => DestinationStatus::PENDING,
                'last_synced_at' => null,
                'failure_code' => null,
                'updated_at' => db($context)->getDriver()->formatDateTime(),
            ]);

        return $this->findByUuid($context, $tenant, $uuid)
            ?? throw new \RuntimeException('Payout account row missing immediately after update.');
    }

    /**
     * Guarded apply of an `inspectDestination()` result (design spec §2.7):
     * updates `readiness_state`/`failure_code`/`last_synced_at` ONLY if the
     * row's CURRENT `provider` AND `account_ref` still equal the snapshot
     * the inspection was actually run against. Returns true iff exactly one
     * row changed; false is a legitimate no-op (the destination was
     * reattached mid-inspection), never an error.
     */
    public function applySyncResult(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $expectedProvider,
        string $expectedAccountRef,
        string $state,
        ?string $failureCode
    ): bool {
        $affected = db($context)->table('commerce_seller_payout_accounts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->where('provider', '=', $expectedProvider)
            ->where('account_ref', '=', $expectedAccountRef)
            ->update([
                'readiness_state' => $state,
                'failure_code' => $failureCode,
                'last_synced_at' => db($context)->getDriver()->formatDateTime(),
                'updated_at' => db($context)->getDriver()->formatDateTime(),
            ]);

        return $affected === 1;
    }

    /**
     * Every payout-account row for one seller, across ALL providers, `ready` included (design
     * spec §6.2, MV4 Task 10) -- the seller's own read-only readiness surface
     * ({@see \Glueful\Extensions\Commerce\Http\Seller\SellerFinancialController}) enumerates
     * every provider a seller has ever attached. Deliberately distinct from
     * {@see self::duePendingOrRestricted()} (a sync-sweep CANDIDATE list, which excludes
     * `ready` on purpose) -- this is a plain readiness READ, never a sweep query.
     *
     * @return list<array<string,mixed>>
     */
    public function forSeller(ApplicationContext $context, string $tenant, string $sellerUuid): array
    {
        return db($context)->table('commerce_seller_payout_accounts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->orderBy('provider', 'ASC')
            ->get();
    }

    /**
     * Sync-sweep candidates (design spec §2.7, Task 8 console command):
     * every account NOT currently `ready` -- `pending` (never successfully
     * synced, or just reset by an attach) and `restricted` (a provider may
     * lift a restriction later, so it stays a sync candidate too). A
     * `ready` account is never swept here; it only leaves `ready` again via
     * a fresh `attach()` (which resets it to `pending`).
     *
     * @return list<array<string,mixed>>
     */
    public function duePendingOrRestricted(
        ApplicationContext $context,
        string $tenant,
        ?string $sellerUuid = null,
        ?string $provider = null
    ): array {
        $query = db($context)->table('commerce_seller_payout_accounts')
            ->where('tenant_uuid', '=', $tenant)
            ->whereIn('readiness_state', [DestinationStatus::PENDING, DestinationStatus::RESTRICTED]);

        if ($sellerUuid !== null) {
            $query->where('seller_uuid', '=', $sellerUuid);
        }
        if ($provider !== null) {
            $query->where('provider', '=', $provider);
        }

        return $query->orderBy('id', 'ASC')->get();
    }
}
