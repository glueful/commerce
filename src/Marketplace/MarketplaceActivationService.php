<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Events\MarketplaceActivated;
use Glueful\Extensions\Commerce\Events\MarketplaceDeactivated;
use Glueful\Extensions\Commerce\Support\UtcNowSql;
use Glueful\Validation\ValidationException;

/**
 * Per-workspace activation (design spec §2.2/§2.3/§4 lock order).
 *
 * {@see self::activate()}'s transaction: claim {@see MarketplaceWorkspaceLock}
 * FIRST (workspace-settings claim first, same as
 * {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::createProduct()}'s
 * attributed-create path -- this shared claim is what makes activation-vs-
 * create deterministic: whichever transaction claims the workspace row
 * first serializes against the other) -> if a `$defaultSellerUuid` was
 * given, claim + validate it, then claim every currently-unassigned live
 * product's `catalog_revision` (sorted by uuid, design spec §4) and
 * bulk-adopt them all to it -> the adoption gate: any live product still
 * lacking a seller after that blocks activation with a 409
 * {@see MarketplaceActivationException} carrying the exact remaining count
 * -> the settings row flips to `active` (+`default_seller_uuid`/
 * `activated_by`/`activated_at`; the workspace claim already bumped
 * `revision`).
 *
 * {@see self::deactivate()} is fail-closed and NON-DESTRUCTIVE (design spec
 * §2.3): claim the workspace, flip `status` to `disabled`. Seller rows,
 * memberships, and every product's `seller_uuid` are left completely
 * untouched -- re-activation re-runs the SAME adoption gate and restores
 * seller-member access.
 *
 * The matching {@see MarketplaceActivated}/{@see MarketplaceDeactivated}
 * audit event dispatches AFTER commit, mirroring
 * {@see \Glueful\Extensions\Commerce\Orders\CheckoutService}'s
 * dispatch-after-transaction convention.
 */
final class MarketplaceActivationService
{
    /**
     * No {@see MarketplaceMode} collaborator: activation/deactivation ARE
     * the mechanism that flips what `MarketplaceMode::activeFor()` reads,
     * and the install-switch gate applies only to route registration and
     * `CatalogService::createProduct()`'s policy -- these are OPERATOR
     * FOUNDATION surfaces (design spec §2.3), not gated on install/active
     * state themselves.
     */
    public function __construct(
        private MarketplaceWorkspaceLock $workspaceLock,
        private SellerRepository $sellers,
        private ProductRepository $products,
    ) {
    }

    /** @return array<string,mixed> the settings row */
    public function activate(
        ApplicationContext $c,
        string $tenant,
        ?string $defaultSellerUuid = null,
        ?string $actor = null
    ): array {
        $settings = db($c)->transaction(function () use ($c, $tenant, $defaultSellerUuid, $actor): array {
            $this->workspaceLock->claim($c, $tenant);

            $resolvedDefault = $defaultSellerUuid === null
                ? null
                : $this->claimAndValidateDefaultSeller($c, $tenant, $defaultSellerUuid);

            if ($resolvedDefault !== null) {
                foreach ($this->products->liveUnassignedUuids($c, $tenant) as $uuid) {
                    $this->products->claimCatalogRevision($c, $tenant, $uuid);
                }
                $this->products->bulkAdoptUnassigned($c, $tenant, $resolvedDefault);
            }

            $remaining = $this->products->unassignedCount($c, $tenant);
            if ($remaining > 0) {
                throw new MarketplaceActivationException($remaining);
            }

            // UtcNowSql, not formatDateTime() (PHP-process tz + clock skew) and not
            // bare CURRENT_TIMESTAMP (non-UTC pgsql sessions) -- same rationale as
            // ProductRepository::markDeleted()/SellerRepository::claimRevision().
            // `activated_at` is a forensic audit stamp only; reused for
            // `updated_at` on the same row/write, matching the house one-`$utcNow`-
            // per-write idiom.
            $utcNow = UtcNowSql::expression(db($c)->getDriverName());
            db($c)->table('commerce_marketplace_settings')->executeModification(
                <<<SQL
UPDATE commerce_marketplace_settings
SET status = 'active', default_seller_uuid = ?, activated_by = ?, activated_at = {$utcNow}, updated_at = {$utcNow}
WHERE tenant_uuid = ?
SQL,
                [$resolvedDefault, $actor, $tenant]
            );

            return $this->requireSettingsRow($c, $tenant);
        });

        $this->dispatch($c, new MarketplaceActivated([
            'tenant_uuid' => $tenant,
            'default_seller_uuid' => $defaultSellerUuid,
            'actor' => $actor,
        ]));

        return $settings;
    }

    /** @return array<string,mixed> the settings row */
    public function deactivate(ApplicationContext $c, string $tenant, ?string $actor = null): array
    {
        $settings = db($c)->transaction(function () use ($c, $tenant): array {
            $this->workspaceLock->claim($c, $tenant);

            db($c)->table('commerce_marketplace_settings')
                ->where('tenant_uuid', '=', $tenant)
                ->update([
                    'status' => 'disabled',
                    'updated_at' => db($c)->getDriver()->formatDateTime(),
                ]);

            return $this->requireSettingsRow($c, $tenant);
        });

        $this->dispatch($c, new MarketplaceDeactivated(['tenant_uuid' => $tenant, 'actor' => $actor]));

        return $settings;
    }

    /**
     * Claims and validates the optional default adoption seller (design
     * spec §2.2): "validates that seller AFTER its claim". Same
     * classification as {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::claimAndValidateAttributionSeller()}
     * -- unknown/cross-tenant is 422, suspended/closed is 409.
     */
    private function claimAndValidateDefaultSeller(ApplicationContext $c, string $tenant, string $sellerUuid): string
    {
        $this->sellers->claimRevision($c, $tenant, $sellerUuid);

        $seller = $this->sellers->findByUuid($c, $tenant, $sellerUuid);
        if ($seller === null) {
            throw ValidationException::forField(
                'default_seller_uuid',
                'default_seller_uuid must reference an existing seller in this tenant.'
            );
        }
        if ((string) $seller['status'] !== 'active') {
            throw new SellerAttributionException(
                "Seller is '{$seller['status']}'; it cannot be the default adoption seller."
            );
        }

        return $sellerUuid;
    }

    /** @return array<string,mixed> */
    private function requireSettingsRow(ApplicationContext $c, string $tenant): array
    {
        $row = db($c)->table('commerce_marketplace_settings')->where('tenant_uuid', '=', $tenant)->first();
        if ($row === null) {
            throw new \RuntimeException('Marketplace settings row could not be reloaded.');
        }

        return $row;
    }

    private function dispatch(ApplicationContext $context, object $event): void
    {
        $container = container($context);
        if ($container->has(EventService::class)) {
            $container->get(EventService::class)->dispatch($event);
        }
    }
}
