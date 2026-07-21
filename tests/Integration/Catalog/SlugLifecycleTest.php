<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\SlugLifecycleAuthority;
use Glueful\Extensions\Commerce\Catalog\StorefrontCatalogChangeDispatcher;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Events\ProductSlugChanged;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Validation\ValidationException;

/**
 * Commerce-Slice-2 Task 1: {@see SlugLifecycleAuthority} soft consumption
 * (design spec §4) -- create/rename byte-parity when unbound, in-transaction
 * claim ordering and rollback semantics when bound, and the after-commit
 * {@see ProductSlugChanged} rename-only dispatch.
 */
final class SlugLifecycleTest extends CommerceTestCase
{
    public function testUnboundAuthorityCreateAndRenameStayByteIdentical(): void
    {
        $product = $this->catalog()->createProduct($this->context, $this->productInput('unbound-a'));

        $this->catalog()->updateProduct($this->context, $product['uuid'], ['slug' => 'unbound-b']);

        $row = $this->connection->table('commerce_products')->where('uuid', '=', $product['uuid'])->first();
        self::assertNotNull($row);
        self::assertSame('unbound-b', $row['slug']);
    }

    public function testPrepareCreateIsInvokedInsideTheCreateTransactionBeforeTheInsertWithThePregeneratedUuid(): void
    {
        $spy = new SlugAuthoritySpy();
        $this->bind(SlugLifecycleAuthority::class, $spy);

        $product = $this->catalog()->createProduct($this->context, $this->productInput('create-spy'));

        self::assertCount(1, $spy->createCalls);
        self::assertSame('create-spy', $spy->createCalls[0]['slug']);
        self::assertSame($product['uuid'], $spy->createCalls[0]['productUuid']);
        // The row must not exist yet at the moment prepareCreate() ran -- proof the
        // authority is called BEFORE the insert, not after.
        self::assertTrue($spy->createCalls[0]['productRowAbsentAtCallTime']);
        self::assertGreaterThan(0, $spy->createCalls[0]['transactionLevel']);
    }

    public function testPrepareRenameIsInvokedInsideTheRenameTransactionBeforeTheProductUpdate(): void
    {
        $spy = new SlugAuthoritySpy();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('rename-spy-old'));
        $this->bind(SlugLifecycleAuthority::class, $spy);

        $this->catalog()->updateProduct($this->context, $product['uuid'], ['slug' => 'rename-spy-new']);

        self::assertCount(1, $spy->renameCalls);
        self::assertSame($product['uuid'], $spy->renameCalls[0]['productUuid']);
        self::assertSame('rename-spy-old', $spy->renameCalls[0]['old']);
        self::assertSame('rename-spy-new', $spy->renameCalls[0]['new']);
        // The stored slug must still be the OLD value at call time -- proof the
        // authority is called BEFORE the product update, not after.
        self::assertSame('rename-spy-old', $spy->renameCalls[0]['storedSlugAtCallTime']);
        self::assertGreaterThan(0, $spy->renameCalls[0]['transactionLevel']);
    }

    public function testAuthorityThrowOnCreateRollsBackTheWholeTransaction(): void
    {
        $this->bind(SlugLifecycleAuthority::class, new ThrowingSlugAuthority());

        try {
            $this->catalog()->createProduct($this->context, $this->productInput('rollback-create'));
            self::fail('Expected the authority to reject the create.');
        } catch (ValidationException) {
            self::assertSame(0, $this->connection->table('commerce_products')->count());
            self::assertSame(0, $this->connection->table('commerce_variants')->count());
            self::assertSame(0, $this->connection->table('commerce_stock')->count());
        }
    }

    public function testAuthorityThrowOnRenameRollsBackTheWholeTransaction(): void
    {
        $product = $this->catalog()->createProduct($this->context, $this->productInput('rollback-rename-old'));
        $this->bind(SlugLifecycleAuthority::class, new ThrowingSlugAuthority());

        try {
            $this->catalog()->updateProduct($this->context, $product['uuid'], [
                'slug' => 'rollback-rename-new',
                'name' => 'Renamed name that must also roll back',
            ]);
            self::fail('Expected the authority to reject the rename.');
        } catch (ValidationException) {
            $row = $this->connection->table('commerce_products')->where('uuid', '=', $product['uuid'])->first();
            self::assertNotNull($row);
            self::assertSame('rollback-rename-old', $row['slug']);
            self::assertNotSame('Renamed name that must also roll back', $row['name']);
        }
    }

    public function testRenameDispatchesExactlyOneAfterCommitProductSlugChanged(): void
    {
        $captured = $this->bindProductSlugChangedCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('dispatch-old'));
        self::assertCount(0, $captured->events, 'create must never dispatch ProductSlugChanged');

        $this->catalog()->updateProduct($this->context, $product['uuid'], ['slug' => 'dispatch-new']);

        self::assertCount(1, $captured->events);
        self::assertInstanceOf(ProductSlugChanged::class, $captured->events[0]);
        self::assertSame('', $captured->events[0]->tenantUuid);
        self::assertSame($product['uuid'], $captured->events[0]->productUuid);
        self::assertSame('dispatch-old', $captured->events[0]->oldSlug);
        self::assertSame('dispatch-new', $captured->events[0]->newSlug);
        // Proves the dispatch happened strictly AFTER the outermost commit (see
        // ProductDeletedEventTest's identical proof for the house convention).
        self::assertSame(0, $captured->transactionLevelsAtDispatch[0]);
    }

    public function testNonSlugPatchDispatchesNoProductSlugChanged(): void
    {
        $captured = $this->bindProductSlugChangedCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('nonslug-patch'));

        $this->catalog()->updateProduct($this->context, $product['uuid'], ['name' => 'A new name']);

        self::assertCount(0, $captured->events);
    }

    public function testSameSlugResubmittedDispatchesNoProductSlugChangedAndSkipsTheAuthority(): void
    {
        $captured = $this->bindProductSlugChangedCapture();
        $spy = new SlugAuthoritySpy();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('same-slug'));
        $this->bind(SlugLifecycleAuthority::class, $spy);

        $this->catalog()->updateProduct($this->context, $product['uuid'], ['slug' => 'same-slug']);

        self::assertCount(0, $captured->events);
        self::assertCount(0, $spy->renameCalls, 'a same-value slug patch is not a rename; the authority must not be claimed for it');
    }

    public function testRolledBackOuterTransactionDispatchesNoProductSlugChanged(): void
    {
        $captured = $this->bindProductSlugChangedCapture();
        $product = $this->catalog()->createProduct($this->context, $this->productInput('outer-rollback-old'));

        $tx = db($this->context)->getTransactionManager();
        $tx->begin();
        $this->catalog()->updateProduct($this->context, $product['uuid'], ['slug' => 'outer-rollback-new']);
        self::assertCount(0, $captured->events, 'still inside the outer transaction -- must not have fired yet');
        $tx->rollback();

        self::assertCount(0, $captured->events);
        $row = $this->connection->table('commerce_products')->where('uuid', '=', $product['uuid'])->first();
        self::assertNotNull($row);
        self::assertSame('outer-rollback-old', $row['slug']);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function productInput(string $slug, array $overrides = []): array
    {
        return array_merge([
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '_', $slug)),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ], $overrides);
    }

    private function catalog(): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository(),
            new ProductChildrenRepository(),
            null,
            null,
            null,
            null,
            null,
            new StorefrontCatalogChangeDispatcher()
        );
    }

    private function bindProductSlugChangedCapture(): object
    {
        $context = $this->context;
        $capture = new class {
            /** @var list<ProductSlugChanged> */
            public array $events = [];
            /** @var list<int> */
            public array $transactionLevelsAtDispatch = [];
        };
        $listeners = new ListenerProvider();
        $eventService = new EventService(new EventDispatcher($listeners), $listeners);
        $eventService->addListener(
            ProductSlugChanged::class,
            function (ProductSlugChanged $e) use ($capture, $context): void {
                $capture->events[] = $e;
                $capture->transactionLevelsAtDispatch[] = db($context)->transactionLevel();
            }
        );
        $this->bind(EventService::class, $eventService);

        return $capture;
    }
}

/**
 * Records every prepareCreate()/prepareRename() call along with proof it ran
 * BEFORE the corresponding write (the row is absent/stale at call time) and
 * INSIDE a transaction (transactionLevel() > 0).
 */
final class SlugAuthoritySpy implements SlugLifecycleAuthority
{
    /** @var list<array{slug: string, productUuid: string, productRowAbsentAtCallTime: bool, transactionLevel: int}> */
    public array $createCalls = [];

    /** @var list<array{productUuid: string, old: string, new: string, storedSlugAtCallTime: string, transactionLevel: int}> */
    public array $renameCalls = [];

    public function prepareCreate(ApplicationContext $c, string $tenant, string $productUuid, string $slug): void
    {
        $row = db($c)->table('commerce_products')->where('uuid', '=', $productUuid)->first();
        $this->createCalls[] = [
            'slug' => $slug,
            'productUuid' => $productUuid,
            'productRowAbsentAtCallTime' => $row === null,
            'transactionLevel' => db($c)->transactionLevel(),
        ];
    }

    public function prepareRename(
        ApplicationContext $c,
        string $tenant,
        string $productUuid,
        string $old,
        string $new
    ): void {
        $row = db($c)->table('commerce_products')->where('uuid', '=', $productUuid)->first();
        $this->renameCalls[] = [
            'productUuid' => $productUuid,
            'old' => $old,
            'new' => $new,
            'storedSlugAtCallTime' => $row !== null ? (string) $row['slug'] : '',
            'transactionLevel' => db($c)->transactionLevel(),
        ];
    }
}

/** Simulates a reserved slug: every call is rejected with a 422-shaped ValidationException. */
final class ThrowingSlugAuthority implements SlugLifecycleAuthority
{
    public function prepareCreate(ApplicationContext $c, string $tenant, string $productUuid, string $slug): void
    {
        throw ValidationException::forField('slug', 'Slug already reserved.');
    }

    public function prepareRename(
        ApplicationContext $c,
        string $tenant,
        string $productUuid,
        string $old,
        string $new
    ): void {
        throw ValidationException::forField('slug', 'Slug already reserved.');
    }
}
