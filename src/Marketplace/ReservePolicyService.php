<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Operator-only rolling-reserve-policy authority with a durable,
 * append-only audit trail (design spec §2.1, MV5a Task 6) -- the MV3
 * {@see CommissionPolicyService} pattern, mirrored for a DIFFERENT policy
 * shape (two independent integers, `reserve_bps`/`reserve_days`, rather
 * than commission's kind/bps/fixed tuple) and a DIFFERENT audit table
 * ({@see ReservePolicyEventRepository} / `commerce_reserve_policy_events`,
 * never `commerce_commission_policy_events`).
 *
 * The policy has exactly two levels: a workspace default
 * (`commerce_marketplace_settings.reserve_bps`/`reserve_days`, NOT NULL,
 * `0` default) and a per-seller override (`commerce_sellers.reserve_bps`/
 * `reserve_days`, nullable). There is no product level and no config
 * fallback -- unlike commission policy, resolution is total by
 * construction (the workspace columns can never be null), so there is no
 * separate pure resolver class for this policy.
 *
 * {@see self::setWorkspace()}/{@see self::setSeller()} share one shape:
 * check the actor FIRST ({@see self::guardActor()}), then validate the
 * proposed policy ({@see self::validateBps()}/{@see self::validateDays()})
 * BEFORE opening any transaction -- invalid input never claims a row --
 * then, in ONE transaction, claim the subject row (the SAME
 * claim-then-re-read primitive every other subject-scoped mutation in
 * this codebase uses: {@see SellerRepository::claimRevision()},
 * {@see MarketplaceWorkspaceLock::claim()}), read its CURRENT reserve
 * columns as `before_policy`, write the new reserve columns, and append
 * the audit row. A failure appending the audit row rolls back the policy
 * change too -- there is no such thing as an unaudited reserve-policy
 * change.
 *
 * **Resolution** ({@see self::resolve()}) is read-only -- it never claims
 * or mutates anything. Per-seller override wins; a NULL per-seller value
 * INHERITS the workspace default; an explicit `0` per-seller value
 * DISABLES the reserve for that field WITHOUT inheriting (design spec
 * §2.1). `reserve_bps` and `reserve_days` resolve INDEPENDENTLY of each
 * other -- a seller may override one field and inherit the other.
 *
 * `actor_uuid` is mandatory on every mutation: a null/blank actor is
 * rejected with a 422 {@see ValidationException} BEFORE anything is
 * claimed or validated, mirroring {@see CommissionPolicyService}'s actor
 * guard.
 */
final class ReservePolicyService
{
    private const MIN_BPS = 0;
    private const MAX_BPS = 10000;

    /** @var callable(): string */
    private $uuidGenerator;

    /**
     * @param (callable(): string)|null $uuidGenerator Injectable seam for tests forcing
     *     an audit-insert-time uuid collision (mirrors
     *     {@see CommissionPolicyService}'s identical convention) -- proves the
     *     policy write and the audit insert are atomic. Defaults to the house
     *     {@see Utils::generateNanoID()} generator.
     */
    public function __construct(
        private SellerRepository $sellers,
        private MarketplaceWorkspaceLock $workspaceLock,
        private ReservePolicyEventRepository $events,
        ?callable $uuidGenerator = null,
    ) {
        $this->uuidGenerator = $uuidGenerator ?? static fn (): string => Utils::generateNanoID();
    }

    /**
     * @return array{reserve_bps:int, reserve_days:int}
     */
    public function resolve(ApplicationContext $c, string $tenant, string $sellerUuid): array
    {
        $seller = $this->sellers->findByUuid($c, $tenant, $sellerUuid);
        if ($seller === null) {
            throw new NotFoundException('Resource not found.');
        }

        $workspace = $this->readWorkspacePolicy($c, $tenant);
        $override = $this->extractPolicy($seller);

        return [
            'reserve_bps' => $override['reserve_bps'] ?? $workspace['reserve_bps'],
            'reserve_days' => $override['reserve_days'] ?? $workspace['reserve_days'],
        ];
    }

    public function setWorkspace(
        ApplicationContext $c,
        string $tenant,
        int $bps,
        int $days,
        ?string $actorUuid = null
    ): void {
        $this->commit($c, $tenant, 'workspace', $tenant, $bps, $days, $actorUuid);
    }

    public function setSeller(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        ?int $bps,
        ?int $days,
        ?string $actorUuid = null
    ): void {
        $this->commit($c, $tenant, 'seller', $sellerUuid, $bps, $days, $actorUuid);
    }

    private function commit(
        ApplicationContext $c,
        string $tenant,
        string $subjectKind,
        string $subjectUuid,
        ?int $bps,
        ?int $days,
        ?string $actorUuid
    ): void {
        $this->guardActor($actorUuid);

        if ($bps !== null) {
            $this->validateBps($bps);
        }
        if ($days !== null) {
            $this->validateDays($days);
        }

        $after = ['reserve_bps' => $bps, 'reserve_days' => $days];

        $before = null;
        db($c)->transaction(function () use (
            $c,
            $tenant,
            $subjectKind,
            $subjectUuid,
            $after,
            $actorUuid,
            &$before
        ): void {
            $before = $this->claimAndReadCurrentPolicy($c, $tenant, $subjectKind, $subjectUuid);

            $this->applyPolicy($c, $tenant, $subjectKind, $subjectUuid, $after);

            $this->events->insert($c, $tenant, [
                'uuid' => ($this->uuidGenerator)(),
                'subject_kind' => $subjectKind,
                'subject_uuid' => $subjectUuid,
                'actor_uuid' => $actorUuid,
                'before_policy' => $before,
                'after_policy' => $after,
            ]);
        });
    }

    private function guardActor(?string $actorUuid): void
    {
        if ($actorUuid === null || trim($actorUuid) === '') {
            throw ValidationException::forField(
                'actor_uuid',
                'actor_uuid is required to change reserve policy.'
            );
        }
    }

    private function validateBps(int $bps): void
    {
        if ($bps < self::MIN_BPS || $bps > self::MAX_BPS) {
            throw ValidationException::forField(
                'reserve_bps',
                'reserve_bps must be between 0 and 10000 inclusive.'
            );
        }
    }

    private function validateDays(int $days): void
    {
        if ($days < 0) {
            throw ValidationException::forField(
                'reserve_days',
                'reserve_days must be non-negative.'
            );
        }
    }

    /** @return array{reserve_bps:?int, reserve_days:?int} */
    private function claimAndReadCurrentPolicy(
        ApplicationContext $c,
        string $tenant,
        string $subjectKind,
        string $subjectUuid
    ): array {
        return match ($subjectKind) {
            'seller' => $this->claimAndReadSeller($c, $tenant, $subjectUuid),
            'workspace' => $this->claimAndReadWorkspace($c, $tenant),
            default => throw new \InvalidArgumentException("Unknown reserve subject_kind '{$subjectKind}'."),
        };
    }

    /** @return array{reserve_bps:?int, reserve_days:?int} */
    private function claimAndReadSeller(ApplicationContext $c, string $tenant, string $uuid): array
    {
        if (!$this->sellers->claimRevision($c, $tenant, $uuid)) {
            throw new NotFoundException('Resource not found.');
        }

        $seller = $this->sellers->findByUuid($c, $tenant, $uuid);
        if ($seller === null) {
            throw new NotFoundException('Resource not found.');
        }

        return $this->extractPolicy($seller);
    }

    /** @return array{reserve_bps:?int, reserve_days:?int} */
    private function claimAndReadWorkspace(ApplicationContext $c, string $tenant): array
    {
        $this->workspaceLock->claim($c, $tenant);

        return $this->readWorkspacePolicy($c, $tenant);
    }

    /**
     * Read-only workspace policy lookup, shared by {@see self::resolve()}
     * (no claim) and {@see self::claimAndReadWorkspace()} (claimed by the
     * caller just before this runs). A tenant that has never activated
     * marketplace mode -- and so has no `commerce_marketplace_settings`
     * row at all -- resolves to the folded column default (design spec
     * §3.1): `0`/`0`, never an error.
     *
     * @return array{reserve_bps:int, reserve_days:int}
     */
    private function readWorkspacePolicy(ApplicationContext $c, string $tenant): array
    {
        $row = db($c)->table('commerce_marketplace_settings')->where('tenant_uuid', '=', $tenant)->first();
        if ($row === null) {
            return ['reserve_bps' => 0, 'reserve_days' => 0];
        }

        return [
            'reserve_bps' => (int) $row['reserve_bps'],
            'reserve_days' => (int) $row['reserve_days'],
        ];
    }

    /** @param array{reserve_bps:?int, reserve_days:?int} $policy */
    private function applyPolicy(
        ApplicationContext $c,
        string $tenant,
        string $subjectKind,
        string $subjectUuid,
        array $policy
    ): void {
        $set = [
            'reserve_bps' => $policy['reserve_bps'],
            'reserve_days' => $policy['reserve_days'],
        ];

        if ($subjectKind === 'seller') {
            $this->sellers->update($c, $tenant, $subjectUuid, $set);
            return;
        }

        db($c)->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', $tenant)
            ->update($set + ['updated_at' => db($c)->getDriver()->formatDateTime()]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{reserve_bps:?int, reserve_days:?int}
     */
    private function extractPolicy(array $row): array
    {
        return [
            'reserve_bps' => $row['reserve_bps'] !== null ? (int) $row['reserve_bps'] : null,
            'reserve_days' => $row['reserve_days'] !== null ? (int) $row['reserve_days'] : null,
        ];
    }
}
