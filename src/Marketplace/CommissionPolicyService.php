<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Events\CommissionPolicyChanged;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Operator-only commission-policy authority with a durable, append-only
 * audit trail (design spec §2.3, MV3 Task 4). Setting commission policy on a
 * product, seller, or workspace is platform-operator-only; every mutation
 * writes a {@see CommissionPolicyEventRepository} row in the SAME
 * transaction as the policy write itself -- a failure appending the audit
 * row rolls back the policy change too. There is no such thing as an
 * unaudited commission-policy change.
 *
 * {@see self::setProduct()}/{@see self::setSeller()}/{@see self::setWorkspace()}
 * share one shape: validate the proposed policy
 * ({@see CommissionPolicyResolver::validate()}, Task 2) BEFORE opening any
 * transaction -- invalid input never claims a row -- then, in ONE
 * transaction, claim the subject row (the SAME claim-then-re-read primitive
 * every other subject-scoped mutation in this codebase uses:
 * {@see ProductRepository::claimCatalogRevision()},
 * {@see SellerRepository::claimRevision()},
 * {@see MarketplaceWorkspaceLock::claim()}), read its CURRENT commission
 * columns as `before_policy`, write the new commission columns, and append
 * the audit row.
 *
 * An OPTIONAL {@see CommissionPolicyChanged} event is soft-dispatched AFTER
 * commit -- the {@see MarketplaceActivationService} dispatch-after-transaction
 * idiom -- and is never the audit authority: a failed or unbound dispatch
 * can never undo or hide the already-committed audit row.
 *
 * `actor_uuid` is mandatory: the audit column is `NOT NULL` (design spec
 * §3.4). A null/blank actor is rejected with a 422 {@see ValidationException}
 * BEFORE anything is claimed or validated, mirroring the "non-null operator
 * actor" requirement §2.10 pins for manual payouts.
 */
final class CommissionPolicyService
{
    /** @var callable(): string */
    private $uuidGenerator;

    /**
     * @param (callable(): string)|null $uuidGenerator Injectable seam for tests forcing
     *     an audit-insert-time uuid collision (mirrors {@see SellerService}/
     *     {@see MarketplaceWorkspaceLock}'s identical convention) -- proves the policy
     *     write and the audit insert are atomic. Defaults to the house
     *     {@see Utils::generateNanoID()} generator.
     */
    public function __construct(
        private ProductRepository $products,
        private SellerRepository $sellers,
        private MarketplaceWorkspaceLock $workspaceLock,
        private CommissionPolicyEventRepository $events,
        ?callable $uuidGenerator = null,
    ) {
        $this->uuidGenerator = $uuidGenerator ?? static fn (): string => Utils::generateNanoID();
    }

    /** @param array{kind?:?string,bps?:?int,fixed?:?int} $policy */
    public function setProduct(
        ApplicationContext $c,
        string $tenant,
        string $subjectUuid,
        array $policy,
        ?string $actorUuid = null
    ): void {
        $this->commit($c, $tenant, 'product', $subjectUuid, $policy, $actorUuid);
    }

    /** @param array{kind?:?string,bps?:?int,fixed?:?int} $policy */
    public function setSeller(
        ApplicationContext $c,
        string $tenant,
        string $subjectUuid,
        array $policy,
        ?string $actorUuid = null
    ): void {
        $this->commit($c, $tenant, 'seller', $subjectUuid, $policy, $actorUuid);
    }

    /**
     * A workspace has exactly one `commerce_marketplace_settings` row per
     * tenant (design spec §3.1's `unique('tenant_uuid')`) -- there is no
     * separate "workspace uuid" a caller could supply, so $subjectUuid is
     * expected to be the SAME tenant identity `$tenant` already carries; the
     * audit row simply records it under `subject_kind='workspace'`. The
     * settings row is lazily created (savepoint-guarded) if this tenant has
     * never activated marketplace mode, so a never-activated workspace can
     * still receive its first commission-policy write here.
     *
     * @param array{kind?:?string,bps?:?int,fixed?:?int} $policy
     */
    public function setWorkspace(
        ApplicationContext $c,
        string $tenant,
        string $subjectUuid,
        array $policy,
        ?string $actorUuid = null
    ): void {
        $this->commit($c, $tenant, 'workspace', $subjectUuid, $policy, $actorUuid);
    }

    /** @param array{kind?:?string,bps?:?int,fixed?:?int} $policy */
    private function commit(
        ApplicationContext $c,
        string $tenant,
        string $subjectKind,
        string $subjectUuid,
        array $policy,
        ?string $actorUuid
    ): void {
        if ($actorUuid === null || trim($actorUuid) === '') {
            throw ValidationException::forField(
                'actor_uuid',
                'actor_uuid is required to change commission policy.'
            );
        }

        $after = $this->normalizePolicy($policy);
        CommissionPolicyResolver::validate($after['kind'], $after['bps'], $after['fixed']);

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

        $this->dispatch($c, new CommissionPolicyChanged([
            'tenant_uuid' => $tenant,
            'subject_kind' => $subjectKind,
            'subject_uuid' => $subjectUuid,
            'actor_uuid' => $actorUuid,
            'before' => $before,
            'after' => $after,
        ]));
    }

    /** @return array{kind:?string,bps:?int,fixed:?int} */
    private function claimAndReadCurrentPolicy(
        ApplicationContext $c,
        string $tenant,
        string $subjectKind,
        string $subjectUuid
    ): array {
        return match ($subjectKind) {
            'product' => $this->extractPolicy($this->claimAndReadProduct($c, $tenant, $subjectUuid)),
            'seller' => $this->extractPolicy($this->claimAndReadSeller($c, $tenant, $subjectUuid)),
            'workspace' => $this->extractPolicy($this->claimAndReadWorkspace($c, $tenant)),
            default => throw new \InvalidArgumentException("Unknown commission subject_kind '{$subjectKind}'."),
        };
    }

    /** @return array<string,mixed> */
    private function claimAndReadProduct(ApplicationContext $c, string $tenant, string $uuid): array
    {
        if (!$this->products->claimCatalogRevision($c, $tenant, $uuid)) {
            throw new NotFoundException('Resource not found.');
        }

        $product = $this->products->findLiveByUuid($c, $tenant, $uuid);
        if ($product === null) {
            throw new NotFoundException('Resource not found.');
        }

        return $product;
    }

    /** @return array<string,mixed> */
    private function claimAndReadSeller(ApplicationContext $c, string $tenant, string $uuid): array
    {
        if (!$this->sellers->claimRevision($c, $tenant, $uuid)) {
            throw new NotFoundException('Resource not found.');
        }

        $seller = $this->sellers->findByUuid($c, $tenant, $uuid);
        if ($seller === null) {
            throw new NotFoundException('Resource not found.');
        }

        return $seller;
    }

    /** @return array<string,mixed> */
    private function claimAndReadWorkspace(ApplicationContext $c, string $tenant): array
    {
        $this->workspaceLock->claim($c, $tenant);

        $row = db($c)->table('commerce_marketplace_settings')->where('tenant_uuid', '=', $tenant)->first();
        if ($row === null) {
            throw new \RuntimeException('Marketplace settings row could not be reloaded.');
        }

        return $row;
    }

    /** @param array{kind:?string,bps:?int,fixed:?int} $policy */
    private function applyPolicy(
        ApplicationContext $c,
        string $tenant,
        string $subjectKind,
        string $subjectUuid,
        array $policy
    ): void {
        $set = [
            'commission_kind' => $policy['kind'],
            'commission_bps' => $policy['bps'],
            'commission_fixed' => $policy['fixed'],
        ];

        if ($subjectKind === 'product') {
            $this->products->update($c, $tenant, $subjectUuid, $set);
            return;
        }

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
     * @return array{kind:?string,bps:?int,fixed:?int}
     */
    private function extractPolicy(array $row): array
    {
        return [
            'kind' => $row['commission_kind'] !== null ? (string) $row['commission_kind'] : null,
            'bps' => $row['commission_bps'] !== null ? (int) $row['commission_bps'] : null,
            'fixed' => $row['commission_fixed'] !== null ? (int) $row['commission_fixed'] : null,
        ];
    }

    /**
     * @param array{kind?:?string,bps?:?int,fixed?:?int} $policy
     * @return array{kind:?string,bps:?int,fixed:?int}
     */
    private function normalizePolicy(array $policy): array
    {
        return [
            'kind' => array_key_exists('kind', $policy) && $policy['kind'] !== null
                ? (string) $policy['kind']
                : null,
            'bps' => array_key_exists('bps', $policy) && $policy['bps'] !== null
                ? (int) $policy['bps']
                : null,
            'fixed' => array_key_exists('fixed', $policy) && $policy['fixed'] !== null
                ? (int) $policy['fixed']
                : null,
        ];
    }

    private function dispatch(ApplicationContext $context, object $event): void
    {
        // CommissionPolicyChanged is an OPTIONAL after-commit signal (§2.3), NOT
        // the audit authority -- the durable commerce_commission_policy_events row
        // already committed. A throwing/bound listener must never turn a successful,
        // audited policy change into a 500 for the operator, so listener failures
        // are swallowed here (the audit trail is unaffected either way).
        try {
            $container = container($context);
            if ($container->has(EventService::class)) {
                $container->get(EventService::class)->dispatch($event);
            }
        } catch (\Throwable) {
            // Intentionally ignored: post-commit notification is best-effort.
        }
    }
}
