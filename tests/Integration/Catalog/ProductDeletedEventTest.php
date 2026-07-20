<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Events\ProductDeleted;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Exceptions\Client\NotFoundException;

/**
 * Commerce-Slice-1 Task 2: `CatalogService::deleteProduct()`'s `ProductDeleted`
 * after-commit dispatch, mirroring the house convention proven by
 * `Orders\OrderFulfilledDispatchTest` for `OrderFulfilled`/`OrderPaid`.
 */
final class ProductDeletedEventTest extends CommerceTestCase
{
    public function testDeletingALiveProductDispatchesExactlyOneProductDeletedAfterCommit(): void
    {
        $captured = $this->bindEventCapture();
        $product = $this->seedActiveProduct('delevtlive01', 'del-evt-live');

        $this->catalog()->deleteProduct($this->context, $product['uuid']);

        self::assertCount(1, $captured->events);
        self::assertInstanceOf(ProductDeleted::class, $captured->events[0]);
        self::assertSame('', $captured->events[0]->tenantUuid);
        self::assertSame($product['uuid'], $captured->events[0]->productUuid);

        // Proves the dispatch happened strictly AFTER the outermost commit,
        // not mid-transaction: TransactionManager::commit() resets the
        // level to 0 BEFORE it runs the promoted commit callbacks (see
        // Database\Transaction\TransactionManager::commit()), so a listener
        // observing level 0 could only have run post-commit.
        self::assertSame(0, $captured->transactionLevelsAtDispatch[0]);
    }

    public function testDeletingACrossTenantProductRaisesNotFoundAndDispatchesNoEvent(): void
    {
        $captured = $this->bindEventCapture();
        $product = $this->seedActiveProduct('delevtxtn001', 'del-evt-xtn', tenant: 'othertenant1');

        try {
            // Default catalog() below is scoped to tenant '' via SentinelTenantResolver.
            $this->catalog()->deleteProduct($this->context, $product['uuid']);
            self::fail('Expected NotFoundException for a cross-tenant product.');
        } catch (NotFoundException $e) {
            // expected
        }

        self::assertCount(0, $captured->events);
    }

    public function testDeletingAnUnknownProductRaisesNotFoundAndDispatchesNoEvent(): void
    {
        $captured = $this->bindEventCapture();

        try {
            $this->catalog()->deleteProduct($this->context, 'no-such-product');
            self::fail('Expected NotFoundException for an unknown product.');
        } catch (NotFoundException $e) {
            // expected
        }

        self::assertCount(0, $captured->events);
    }

    public function testRepeatDeleteRaisesNotFoundAndDispatchesNoSecondEvent(): void
    {
        $captured = $this->bindEventCapture();
        $product = $this->seedActiveProduct('delevtrpt001', 'del-evt-repeat');

        $this->catalog()->deleteProduct($this->context, $product['uuid']);
        self::assertCount(1, $captured->events);

        try {
            $this->catalog()->deleteProduct($this->context, $product['uuid']);
            self::fail('Expected NotFoundException on the repeat delete.');
        } catch (NotFoundException $e) {
            // expected
        }

        self::assertCount(1, $captured->events, 'a repeat (losing) delete must not dispatch a second event');
    }

    /**
     * Genuine proof the dispatch only fires on the OUTERMOST commit: `deleteProduct()`'s
     * own transaction is entered while an OUTER transaction (opened directly via the
     * real `TransactionManager`) is already active, so `deleteProduct()`'s transaction
     * becomes a savepoint -- its `afterCommit()` registration is PROMOTED to the outer
     * level per `TransactionManager::commit()`, never fired on its own savepoint release.
     * Rolling back the OUTER transaction discards the promoted callback entirely (see
     * `TransactionManager::rollback()`'s `clearCallbacks()` on the level-1 branch) --
     * this is the real framework transaction machinery, not a mock or a stubbed claim
     * failure, so it proves both that the tombstone itself was undone AND that no event
     * survived the rollback.
     */
    public function testForcedRollbackOfAnOuterTransactionUndoesTheDeleteAndDispatchesNoEvent(): void
    {
        $captured = $this->bindEventCapture();
        $product = $this->seedActiveProduct('delevtroll01', 'del-evt-rollback');

        $tx = db($this->context)->getTransactionManager();
        $tx->begin();
        $this->catalog()->deleteProduct($this->context, $product['uuid']);
        // Sanity: the promoted afterCommit callback has NOT fired yet -- still
        // inside the (now two-level) outer transaction.
        self::assertCount(0, $captured->events);
        $tx->rollback();

        self::assertCount(0, $captured->events, 'a rolled-back outer transaction must dispatch no event at all');
        self::assertNotNull(
            (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']),
            'the outer rollback must undo the tombstone too, proving this is a genuine rollback'
        );
    }

    /**
     * Binds a real EventService into the test container and returns a capture object whose
     * `events` list is appended to (in dispatch order) as ProductDeleted fires. An object is
     * used (rather than an array by reference) since PHP copies arrays on return.
     */
    private function bindEventCapture(): object
    {
        $context = $this->context;
        $capture = new class {
            /** @var list<object> */
            public array $events = [];
            /** @var list<int> */
            public array $transactionLevelsAtDispatch = [];
        };
        $listeners = new ListenerProvider();
        $eventService = new EventService(new EventDispatcher($listeners), $listeners);
        $eventService->addListener(ProductDeleted::class, function (ProductDeleted $e) use ($capture, $context): void {
            $capture->events[] = $e;
            $capture->transactionLevelsAtDispatch[] = db($context)->transactionLevel();
        });
        $this->bind(EventService::class, $eventService);

        return $capture;
    }

    /** @return array<string,mixed> */
    private function seedActiveProduct(string $uuid, string $slug, string $tenant = ''): array
    {
        return $this->catalog($tenant)->createProduct($this->context, [
            'slug' => $slug,
            'name' => $uuid,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper($uuid),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
    }

    private function catalog(string $tenant = ''): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant),
            new StockRepository(),
            new ProductChildrenRepository()
        );
    }

    private function fixedTenant(string $tenant): CurrentTenantResolver
    {
        return new class ($tenant) implements CurrentTenantResolver {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
    }
}
