<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;

/**
 * Shared after-commit dispatch primitive for {@see StorefrontCatalogChanged}
 * (design spec §9), injected as an OPTIONAL, APPENDED collaborator into
 * every storefront-visible catalog/inventory mutation site -- the single
 * place that owns "how" the event reaches listeners, so every call site
 * only ever decides "when" and "what reason". Dependency-free and stateless
 * -- safe to share, safe to construct directly in tests that don't go
 * through {@see \Glueful\Extensions\Commerce\CommerceServiceProvider}.
 *
 * Registers via `db($context)->afterCommit()` from INSIDE the caller's own
 * transaction, mirroring {@see CatalogService}'s `dispatch()` idiom for
 * {@see \Glueful\Extensions\Commerce\Events\ProductDeleted}: a rolled-back
 * transaction discards the callback entirely (never fires). Every
 * inventoried write path in this codebase calls this method from inside an
 * already-open `db($context)->transaction()`, but a hypothetical txn-less
 * caller stays safe too -- `TransactionManager::afterCommit()` already runs
 * the callback immediately, fault-isolated (catches + logs), when no
 * transaction is active, so this class never needs its own transaction-
 * presence branch.
 *
 * {@see EventService} is soft-resolved fresh on every fire (never cached),
 * the SAME fault-isolation idiom every other event dispatch in this
 * codebase uses: a listener exception never threatens the already-committed
 * write it describes.
 */
final class StorefrontCatalogChangeDispatcher
{
    public function dispatch(
        ApplicationContext $context,
        string $tenantUuid,
        ?string $productUuid,
        string $reason
    ): void {
        db($context)->afterCommit(function () use ($context, $tenantUuid, $productUuid, $reason): void {
            $this->fire($context, new StorefrontCatalogChanged($tenantUuid, $reason, $productUuid));
        });
    }

    private function fire(ApplicationContext $context, object $event): void
    {
        $container = container($context);
        if ($container->has(EventService::class)) {
            $container->get(EventService::class)->dispatch($event);
        }
    }
}
