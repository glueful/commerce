<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AddonService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Events\OrderPlaced;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderDraftController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Orders\DraftAttemptRepository;
use Glueful\Extensions\Commerce\Orders\DraftCleanupService;
use Glueful\Extensions\Commerce\Orders\DraftConflictException;
use Glueful\Extensions\Commerce\Orders\DraftFinalizationService;
use Glueful\Extensions\Commerce\Orders\DraftLineEligibility;
use Glueful\Extensions\Commerce\Orders\DraftOrderService;
use Glueful\Extensions\Commerce\Orders\Events\DraftOrderEvents;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PurchasableLineResolver;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Admin-order-creation cycle 2, Task 10 (design spec §2.5): the FINALIZATION
 * AUTHORITY -- the one surface that turns an advisory draft into a real order.
 *
 * Every assertion here is a contract:
 *  - malformed `X-Idempotency-Key`/`expected_revision` are 422 BEFORE any order
 *    lookup or ledger access (proven by a 422 for a uuid that does not exist);
 *  - an unknown/cross-tenant uuid is a non-revealing 404 with ZERO attempt rows;
 *  - the whole finalize is ONE transaction: a failure at any step leaves an
 *    EDITABLE draft with no order number consumed, no stock claimed, no
 *    movement, no attempt row, and no dispatched event;
 *  - the idempotency triple (replay / fingerprint mismatch / same key on a
 *    different draft) resolves before the state checks, so the original
 *    revision stays replayable after finalization;
 *  - drift, stock, digital, marketplace, and unavailability are TYPED per-line
 *    conflicts -- the draft is never silently repriced;
 *  - `OrderPlaced` dispatches only after commit: rollback zero, fresh exactly
 *    one, replay none.
 */
final class DraftFinalizationTest extends CommerceTestCase
{
    private const TENANT = '';
    private const KEY = 'finalize-key-0000001';

    // -----------------------------------------------------------------
    // 1. input contract -- 422 BEFORE lookup or ledger access
    // -----------------------------------------------------------------

    /** @return list<array{0:mixed}> */
    public static function malformedKeyProvider(): array
    {
        return [
            'missing' => [null],
            'blank' => [''],
            'too short (15)' => [str_repeat('a', 15)],
            'too long (192)' => [str_repeat('a', 192)],
            'illegal space' => ['abcdefgh ijklmnopq'],
            'illegal slash' => ['abcdefgh/ijklmnopq'],
            'illegal unicode' => ['abcdefghijklmnopq\u{00e9}'],
        ];
    }

    /**
     * The uuid deliberately does NOT exist: a 422 here proves the key check
     * runs BEFORE the order lookup (an ordered-after check would 404 instead).
     *
     * @dataProvider malformedKeyProvider
     */
    public function testMalformedIdempotencyKeyIs422BeforeAnyLookupOrLedgerAccess(mixed $key): void
    {
        $response = $this->finalize('nosuchdraft1', $key, 0);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('idempotency_key', $this->json($response)['error']['details']);
        self::assertSame(0, $this->attemptCount());
    }

    /** @return list<array{0:mixed}> */
    public static function malformedRevisionProvider(): array
    {
        return [
            'missing' => [null],
            'negative' => [-1],
            'non numeric string' => ['later'],
            'float' => [1.5],
            'boolean' => [true],
            'array' => [[0]],
        ];
    }

    /** @dataProvider malformedRevisionProvider */
    public function testMalformedExpectedRevisionIs422BeforeAnyLookupOrLedgerAccess(mixed $revision): void
    {
        $response = $this->finalize('nosuchdraft1', self::KEY, $revision);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('expected_revision', $this->json($response)['error']['details']);
        self::assertSame(0, $this->attemptCount());
    }

    public function testAWellFormedKeyOfExactlyTheBoundaryLengthsIsAccepted(): void
    {
        foreach ([16, 191] as $length) {
            $uuid = $this->draftWithLine();
            $response = $this->finalize($uuid, str_repeat('k', $length), 1);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        }
    }

    // -----------------------------------------------------------------
    // 2. tenant-safe preflight -- non-revealing 404, zero attempt writes
    // -----------------------------------------------------------------

    public function testUnknownUuidIsANonRevealing404WithZeroAttemptRows(): void
    {
        try {
            $this->finalize('nosuchdraft1', self::KEY, 0);
            self::fail('an unknown uuid must 404');
        } catch (NotFoundException $e) {
            self::assertSame('Resource not found.', $e->getMessage());
        }

        self::assertSame(0, $this->attemptCount());
    }

    public function testCrossTenantUuidIsANonRevealing404WithZeroAttemptRows(): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'foreigndraft',
            'tenant_uuid' => 'othertenant1',
            'order_number' => null,
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'origin' => 'admin',
            'fulfillment_mode' => 'in_store',
        ]);

        try {
            $this->finalize('foreigndraft', self::KEY, 0);
            self::fail('a cross-tenant uuid must 404');
        } catch (NotFoundException $e) {
            self::assertSame('Resource not found.', $e->getMessage());
        }

        self::assertSame(0, $this->attemptCount());
        self::assertSame('draft', (string) $this->orderRow('foreigndraft')['status']);
    }

    // -----------------------------------------------------------------
    // 3. happy path
    // -----------------------------------------------------------------

    public function testFinalizeTurnsTheDraftIntoAPendingPaymentOrderWithOneOfEverything(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-FIN-1', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 2);

        $response = $this->finalize($uuid, self::KEY, 1);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $order = $this->json($response)['data'];
        self::assertSame('pending_payment', $order['status']);
        self::assertSame('ORD-000001', $order['order_number']);
        self::assertSame('admin', $order['origin']);
        self::assertSame(2000, (int) $order['grand_total']);
        self::assertNotNull($order['placed_at']);
        // The draft's optimistic-concurrency counter never reaches the
        // finalized-order wire.
        self::assertArrayNotHasKey('draft_revision', $order);

        $row = $this->orderRow($uuid);
        self::assertSame('pending_payment', (string) $row['status']);
        self::assertSame('ORD-000001', (string) $row['order_number']);
        self::assertNull($row['guest_token_hash'], 'an admin order never mints a guest credential');
        self::assertSame(2000, (int) $row['subtotal']);

        // Stock claimed exactly once, with its movement.
        self::assertSame(98, (new StockRepository())->quantity($this->context, self::TENANT, $variantUuid));
        self::assertSame(1, $this->movementCount($uuid));

        // The attempt ledger holds exactly one COMPLETED row for this key.
        $attempt = $this->attemptRow(self::KEY);
        self::assertSame('completed', (string) $attempt['status']);
        self::assertSame($uuid, (string) $attempt['order_uuid']);
        self::assertNotNull($attempt['completed_at']);

        // Audit trail: the draft's own rows, then the shared lifecycle row, then
        // the finalization row.
        self::assertSame(
            [DraftOrderEvents::CREATED, 'status:pending_payment', DraftOrderEvents::FINALIZED],
            $this->eventTypesFor($uuid)
        );

        self::assertSame(1, $this->placedDispatches);
    }

    public function testFinalizeReplacesAdvisorySnapshotsInPlaceKeepingTheStableLineUuid(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-FIN-2', 1000);
        $uuid = $this->createDraft();
        $lineUuid = $this->addLine($uuid, $variantUuid, 1);

        $this->finalize($uuid, self::KEY, 1);

        $lines = $this->linesFor($uuid);
        self::assertCount(1, $lines, 'finalize replaces snapshots in place, never inserting a duplicate');
        self::assertSame($lineUuid, (string) $lines[0]['uuid']);
        self::assertSame(1000, (int) $lines[0]['unit_price']);
        self::assertSame(1000, (int) $lines[0]['line_total']);
    }

    public function testADeliveryDraftFinalizesWithItsQuotedShippingMethod(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-FIN-3', 1000);
        $uuid = $this->createDraft(['fulfillment_mode' => 'delivery']);
        $this->addLine($uuid, $variantUuid, 1);
        $this->update($uuid, [
            'addresses' => ['shipping' => ['country' => 'US', 'line1' => '1 Main St']],
            'shipping_method' => 'std',
        ]);

        $order = $this->json($this->finalize($uuid, self::KEY, 2))['data'];

        self::assertSame('pending_payment', $order['status']);
        self::assertSame('std', $order['shipping_method']);
        self::assertSame(500, (int) $order['shipping_total']);
        self::assertSame(1500, (int) $order['grand_total']);
    }

    public function testAnEmptyDraftCannotBecomeAnOrder(): void
    {
        $uuid = $this->createDraft();

        $response = $this->finalize($uuid, self::KEY, 0);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('lines', $this->json($response)['error']['details']);
        self::assertSame('draft', (string) $this->orderRow($uuid)['status']);
        self::assertSame(0, $this->attemptCount());
    }

    // -----------------------------------------------------------------
    // 4. the idempotency triple
    // -----------------------------------------------------------------

    public function testSameKeyAndFingerprintReplaysTheFinalizedOrderWithoutReExecuting(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-FIN-4', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 3);

        $first = $this->json($this->finalize($uuid, self::KEY, 1))['data'];
        $second = $this->json($this->finalize($uuid, self::KEY, 1))['data'];

        self::assertSame($first['order_number'], $second['order_number']);
        self::assertSame('pending_payment', $second['status']);

        // Nothing ran twice: one number, one stock claim, one movement, one
        // lifecycle row, one attempt row, one dispatch.
        self::assertSame(97, (new StockRepository())->quantity($this->context, self::TENANT, $variantUuid));
        self::assertSame(1, $this->movementCount($uuid));
        self::assertSame(1, $this->attemptCount());
        self::assertSame(
            [DraftOrderEvents::CREATED, 'status:pending_payment', DraftOrderEvents::FINALIZED],
            $this->eventTypesFor($uuid)
        );
        self::assertSame(1, $this->placedDispatches, 'a replay must never redispatch OrderPlaced');
    }

    public function testSameKeyWithADifferentExpectedRevisionIsAnIdempotencyConflict(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-FIN-5', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);
        $this->finalize($uuid, self::KEY, 1);

        $response = $this->finalize($uuid, self::KEY, 2);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            DraftConflictException::IDEMPOTENCY_KEY,
            $this->json($response)['error']['details']['conflict']
        );
        self::assertSame(1, $this->attemptCount());
    }

    public function testTheSameKeyOnADifferentDraftIsAnIdempotencyConflictAndTouchesNeitherDraft(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-FIN-6', 1000);
        $first = $this->createDraft();
        $this->addLine($first, $variantUuid, 1);
        $second = $this->createDraft();
        $this->addLine($second, $variantUuid, 1);

        $this->finalize($first, self::KEY, 1);
        $response = $this->finalize($second, self::KEY, 1);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            DraftConflictException::IDEMPOTENCY_KEY,
            $this->json($response)['error']['details']['conflict']
        );
        self::assertSame('draft', (string) $this->orderRow($second)['status']);
        self::assertNull($this->orderRow($second)['order_number']);
        self::assertSame(1, $this->attemptCount());
    }

    public function testAFreshKeyAgainstAnAlreadyFinalizedOrderIsATypedStatusConflict(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-FIN-7', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);
        $this->finalize($uuid, self::KEY, 1);

        $response = $this->finalize($uuid, 'a-completely-different-key', 1);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            DraftConflictException::NOT_DRAFT,
            $this->json($response)['error']['details']['conflict']
        );
        // The losing claim rolled back with the rest of its transaction.
        self::assertSame(1, $this->attemptCount());
    }

    // -----------------------------------------------------------------
    // 5. state conflicts
    // -----------------------------------------------------------------

    public function testAStaleExpectedRevisionIsATypedRevisionConflict(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-FIN-8', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);

        $response = $this->finalize($uuid, self::KEY, 0);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            DraftConflictException::STALE_REVISION,
            $this->json($response)['error']['details']['conflict']
        );
        self::assertSame('draft', (string) $this->orderRow($uuid)['status']);
        self::assertSame(0, $this->attemptCount(), 'the pending claim rolls back with the transaction');
        self::assertSame(0, $this->placedDispatches);
    }

    public function testAStoreCurrencyChangeIsATypedCurrencyConflict(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-FIN-9', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);

        $this->context->overrideConfig('commerce.currency', 'EUR');
        $response = $this->finalize($uuid, self::KEY, 1);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            DraftConflictException::CURRENCY,
            $this->json($response)['error']['details']['conflict']
        );
        self::assertSame('draft', (string) $this->orderRow($uuid)['status']);
        self::assertSame(0, $this->attemptCount());
    }

    // -----------------------------------------------------------------
    // 6. per-line conflicts
    // -----------------------------------------------------------------

    public function testVariantPriceDriftIsATypedPerLineDriftConflict(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-DRIFT-1', 1000);
        $uuid = $this->createDraft();
        $lineUuid = $this->addLine($uuid, $variantUuid, 1);

        $this->connection->table('commerce_variants')->where('uuid', '=', $variantUuid)->update(['price' => 1200]);

        $response = $this->finalize($uuid, self::KEY, 1);

        self::assertSame(409, $response->getStatusCode());
        $details = $this->json($response)['error']['details'];
        self::assertSame(DraftConflictException::LINE_CONFLICTS, $details['conflict']);
        self::assertCount(1, $details['lines']);
        self::assertSame($lineUuid, $details['lines'][0]['line_uuid']);
        self::assertSame(DraftFinalizationService::LINE_DRIFT, $details['lines'][0]['reason']);
        self::assertSame(1000, (int) $details['lines'][0]['unit_price']);
        self::assertSame(1200, (int) $details['lines'][0]['current_unit_price']);

        // Nothing moved: the draft is still editable, and `recalculate` is the
        // documented way forward.
        self::assertSame('draft', (string) $this->orderRow($uuid)['status']);
        self::assertSame(0, $this->attemptCount());
        self::assertSame(100, (new StockRepository())->quantity($this->context, self::TENANT, $variantUuid));
    }

    public function testRecalculateClearsAPriceDriftConflictAndTheDraftThenFinalizes(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-DRIFT-2', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);
        $this->connection->table('commerce_variants')->where('uuid', '=', $variantUuid)->update(['price' => 1200]);

        self::assertSame(409, $this->finalize($uuid, self::KEY, 1)->getStatusCode());

        $recalculated = $this->json($this->controller()->recalculate($this->request([]), $uuid))['data'];
        self::assertSame(1200, (int) $recalculated['grand_total']);

        $order = $this->json($this->finalize($uuid, self::KEY, 2))['data'];
        self::assertSame('pending_payment', $order['status']);
        self::assertSame(1200, (int) $order['grand_total']);
    }

    public function testCurrentAddonDefinitionDriftIsATypedPerLineDriftConflict(): void
    {
        $productUuid = null;
        $variantUuid = $this->seedPhysicalProduct('SKU-DRIFT-3', 1000, productUuid: $productUuid);
        $addonUuid = $this->seedCheckboxAddon((string) $productUuid, 250);

        $uuid = $this->createDraft();
        $lineUuid = $this->addLine($uuid, $variantUuid, 1, [
            ['addon_uuid' => $addonUuid, 'value' => true],
        ]);
        self::assertSame(1250, (int) $this->linesFor($uuid)[0]['unit_price']);

        // A pure DISPLAY edit: the price is untouched, so this is addon-definition
        // drift and nothing else -- the canonical snapshot hash still changes.
        $this->connection->table('commerce_product_addons')
            ->where('uuid', '=', $addonUuid)
            ->update(['name' => 'Gift wrap (premium)']);

        $response = $this->finalize($uuid, self::KEY, 1);

        self::assertSame(409, $response->getStatusCode());
        $details = $this->json($response)['error']['details'];
        self::assertSame(DraftConflictException::LINE_CONFLICTS, $details['conflict']);
        self::assertSame($lineUuid, $details['lines'][0]['line_uuid']);
        self::assertSame(DraftFinalizationService::LINE_DRIFT, $details['lines'][0]['reason']);
        // Price parity proves this conflict is driven by the definition snapshot,
        // not by the money.
        self::assertSame(1250, (int) $details['lines'][0]['current_unit_price']);
    }

    public function testAVanishedProductIsATypedPerLineUnavailableConflict(): void
    {
        $productUuid = null;
        $variantUuid = $this->seedPhysicalProduct('SKU-DRIFT-4', 1000, productUuid: $productUuid);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);

        // Soft-deleted: `findBuyerAvailableByUuid()`'s own predicate, which is
        // exactly what the resolver consults.
        $this->connection->table('commerce_products')
            ->where('uuid', '=', (string) $productUuid)
            ->update(['deleted_at' => gmdate('Y-m-d H:i:s')]);

        $details = $this->json($this->finalize($uuid, self::KEY, 1))['error']['details'];

        self::assertSame(DraftConflictException::LINE_CONFLICTS, $details['conflict']);
        self::assertSame(DraftLineEligibility::UNAVAILABLE, $details['lines'][0]['reason']);
        self::assertNull($details['lines'][0]['current_unit_price']);
    }

    public function testAProductThatBecameDigitalIsATypedPerLineDigitalConflict(): void
    {
        $productUuid = null;
        $variantUuid = $this->seedPhysicalProduct('SKU-DRIFT-5', 1000, productUuid: $productUuid);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);

        $this->connection->table('commerce_products')
            ->where('uuid', '=', (string) $productUuid)
            ->update(['type' => 'digital']);

        $details = $this->json($this->finalize($uuid, self::KEY, 1))['error']['details'];

        self::assertSame(DraftConflictException::LINE_CONFLICTS, $details['conflict']);
        self::assertSame(DraftLineEligibility::DIGITAL, $details['lines'][0]['reason']);
    }

    public function testAProductThatJoinedAnActiveMarketplaceIsATypedPerLineMarketplaceConflict(): void
    {
        $productUuid = null;
        $variantUuid = $this->seedPhysicalProduct('SKU-DRIFT-6', 1000, productUuid: $productUuid);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);

        $this->seedActiveSeller('sellerfin001');
        $this->connection->table('commerce_products')
            ->where('uuid', '=', (string) $productUuid)
            ->update(['seller_uuid' => 'sellerfin001']);
        $this->activateMarketplace();

        $details = $this->json($this->finalize($uuid, self::KEY, 1))['error']['details'];

        self::assertSame(DraftConflictException::LINE_CONFLICTS, $details['conflict']);
        self::assertSame(DraftLineEligibility::MARKETPLACE, $details['lines'][0]['reason']);
    }

    public function testInsufficientStockIsATypedPerLineStockConflictAndClaimsNothing(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-STOCK-1', 1000);
        $uuid = $this->createDraft();
        $lineUuid = $this->addLine($uuid, $variantUuid, 5);
        $this->setStock($variantUuid, 2);

        $response = $this->finalize($uuid, self::KEY, 1);

        self::assertSame(409, $response->getStatusCode());
        $details = $this->json($response)['error']['details'];
        self::assertSame(DraftConflictException::LINE_CONFLICTS, $details['conflict']);
        self::assertSame($lineUuid, $details['lines'][0]['line_uuid']);
        self::assertSame(DraftFinalizationService::LINE_STOCK, $details['lines'][0]['reason']);

        self::assertSame(2, (new StockRepository())->quantity($this->context, self::TENANT, $variantUuid));
        self::assertSame(0, $this->movementCount($uuid));
        self::assertSame('draft', (string) $this->orderRow($uuid)['status']);
        self::assertSame(0, $this->attemptCount());
    }

    public function testAnUntrackedVariantFinalizesWithoutAnyStockClaim(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-STOCK-2', 1000, tracked: false);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 4);

        self::assertSame(200, $this->finalize($uuid, self::KEY, 1)->getStatusCode());
        self::assertSame(0, $this->movementCount($uuid));
    }

    /**
     * Review fix (Important 1). A variant's `currency` column is
     * operator-editable and single-store parity is documented as an invariant
     * rather than re-enforced on every edit, so a variant can be re-denominated
     * USD -> EUR while its numeric `price` is untouched. That line then produces
     * NO drift signal at all -- same `unit_price`, same add-on hash -- and the
     * ORDER-level currency check still passes, because the draft's own snapshot
     * still says USD. Only a per-line check stands between this and an order
     * charging EUR minor units as USD, which is exactly the refusal
     * `CheckoutService::placeOrderAttempt()` already makes for a cart line.
     */
    public function testAReDenominatedVariantIsATypedPerLineCurrencyConflict(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-CUR-1', 1000);
        $uuid = $this->createDraft();
        $lineUuid = $this->addLine($uuid, $variantUuid, 2);

        $this->connection->table('commerce_variants')
            ->where('uuid', '=', $variantUuid)
            ->update(['currency' => 'EUR']);

        $response = $this->finalize($uuid, self::KEY, 1);

        self::assertSame(409, $response->getStatusCode());
        $details = $this->json($response)['error']['details'];
        self::assertSame(DraftConflictException::LINE_CONFLICTS, $details['conflict']);
        self::assertCount(1, $details['lines']);
        self::assertSame($lineUuid, $details['lines'][0]['line_uuid']);
        self::assertSame(DraftFinalizationService::LINE_CURRENCY, $details['lines'][0]['reason']);
        self::assertSame('EUR', $details['lines'][0]['currency']);
        // Proof this is invisible to every other check: the price never moved, so
        // drift reports nothing, and the ORDER's own currency still matches the
        // store's.
        self::assertSame(1000, (int) $details['lines'][0]['unit_price']);
        self::assertSame(1000, (int) $details['lines'][0]['current_unit_price']);
        self::assertSame('USD', (string) $this->orderRow($uuid)['currency']);

        // Rollback is clean and the draft stays editable.
        self::assertSame('draft', (string) $this->orderRow($uuid)['status']);
        self::assertNull($this->orderRow($uuid)['order_number']);
        self::assertSame(1, (int) $this->orderRow($uuid)['draft_revision']);
        self::assertSame(100, (new StockRepository())->quantity($this->context, self::TENANT, $variantUuid));
        self::assertSame(0, $this->movementCount($uuid));
        self::assertSame(0, $this->attemptCount());
        self::assertSame([DraftOrderEvents::CREATED], $this->eventTypesFor($uuid));
        self::assertSame(0, $this->placedDispatches);
        $this->update($uuid, ['customer_name' => 'Ada Lovelace']);
    }

    /**
     * Review fix (Important 2). `findByUuidForUpdate()` degrades to an UNLOCKED
     * read on any driver without `FOR UPDATE` -- SQLite, i.e. this very lane --
     * so two finalizers there really can both clear the status check and race to
     * the compare-and-set. The loser must land on the SAME typed `not_draft`
     * conflict an early discovery produces, never on the bare
     * `\DomainException` the repository throws (which `guard()` does not catch,
     * and which would reach the operator as a raw 500).
     *
     * The race is simulated deterministically rather than threaded: the row is
     * transitioned out of `draft` from inside the finalize transaction, at the
     * tax-quote seam -- after the status check, before the compare-and-set --
     * which is precisely the window a real concurrent winner occupies.
     */
    public function testLosingTheFinalizeCompareAndSetIsATypedConflictNotAnUncaughtDomainException(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-RACE-1', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);

        $this->duringFinalize(function () use ($uuid): void {
            $this->connection->table('commerce_orders')
                ->where('uuid', '=', $uuid)
                ->update(['status' => 'canceled']);
        });

        $response = $this->finalize($uuid, self::KEY, 1);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            DraftConflictException::NOT_DRAFT,
            $this->json($response)['error']['details']['conflict']
        );

        // Everything the losing attempt did rolled back with it -- including the
        // simulated winner's own write, which shared this transaction.
        $row = $this->orderRow($uuid);
        self::assertSame('draft', (string) $row['status']);
        self::assertNull($row['order_number']);
        self::assertSame(0, $this->attemptCount());
        self::assertSame(0, $this->movementCount($uuid));
        self::assertSame(0, $this->connection->table('commerce_sequences')->count());
        self::assertSame(0, $this->placedDispatches);
    }

    /**
     * Minor fold (3): the POSITIVE add-on path. An add-on line whose definition
     * has NOT been touched must finalize cleanly, carrying its price delta into
     * the authoritative snapshot. Without this, a regression that made every
     * add-on line read as drift -- for instance a hash computed over a
     * differently-shaped snapshot on the two sides -- would leave every add-on
     * draft permanently unfinalizable, and the drift tests above would all still
     * pass.
     */
    public function testAnUntouchedAddonLineFinalizesAndKeepsItsPricedSnapshot(): void
    {
        $productUuid = null;
        $variantUuid = $this->seedPhysicalProduct('SKU-ADDON-OK', 1000, productUuid: $productUuid);
        $addonUuid = $this->seedCheckboxAddon((string) $productUuid, 250);

        $uuid = $this->createDraft();
        $lineUuid = $this->addLine($uuid, $variantUuid, 2, [
            ['addon_uuid' => $addonUuid, 'value' => true],
        ]);

        $response = $this->finalize($uuid, self::KEY, 1);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $order = $this->json($response)['data'];
        self::assertSame('pending_payment', $order['status']);
        self::assertSame(2500, (int) $order['grand_total']);

        $lines = $this->linesFor($uuid);
        self::assertCount(1, $lines);
        self::assertSame($lineUuid, (string) $lines[0]['uuid']);
        self::assertSame(1250, (int) $lines[0]['unit_price']);
        self::assertSame(2500, (int) $lines[0]['line_total']);

        $addons = json_decode((string) $lines[0]['addons'], true);
        self::assertIsArray($addons);
        self::assertCount(1, $addons);
        self::assertSame($addonUuid, (string) $addons[0]['addon_uuid']);
        self::assertSame(250, (int) $addons[0]['price_delta']);

        // And the wire echoes the sanitized snapshot, never the definition uuid.
        self::assertSame('Gift wrap', $order['lines'][0]['addons'][0]['name']);
        self::assertArrayNotHasKey('addon_uuid', $order['lines'][0]['addons'][0]);
    }

    // -----------------------------------------------------------------
    // 7. delivery address preflight (design Ruling 5's positive half)
    // -----------------------------------------------------------------

    public function testADeliveryDraftWithoutARequiredShippingAddressIsRefused(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-ADDR-1', 1000);
        $uuid = $this->createDraft(['fulfillment_mode' => 'delivery']);
        $this->addLine($uuid, $variantUuid, 1);

        $response = $this->finalize($uuid, self::KEY, 1);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('addresses', $this->json($response)['error']['details']);
        self::assertSame('draft', (string) $this->orderRow($uuid)['status']);
        self::assertSame(0, $this->attemptCount());
    }

    public function testADeliveryDraftWithAnAddressButNoCountryIsRefused(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-ADDR-2', 1000);
        $uuid = $this->createDraft(['fulfillment_mode' => 'delivery']);
        $this->addLine($uuid, $variantUuid, 1);
        $this->update($uuid, ['addresses' => ['shipping' => ['line1' => '1 Main St']]]);

        $response = $this->finalize($uuid, self::KEY, 2);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('addresses', $this->json($response)['error']['details']);
    }

    public function testADeliveryDraftWithoutAShippingMethodIsRefused(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-ADDR-3', 1000);
        $uuid = $this->createDraft(['fulfillment_mode' => 'delivery']);
        $this->addLine($uuid, $variantUuid, 1);
        $this->update($uuid, ['addresses' => ['shipping' => ['country' => 'US']]]);

        $response = $this->finalize($uuid, self::KEY, 2);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('shipping_method', $this->json($response)['error']['details']);
    }

    public function testAnInStoreDraftNeedsNoAddressAtAll(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-ADDR-4', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);

        $order = $this->json($this->finalize($uuid, self::KEY, 1))['data'];

        self::assertSame('pending_payment', $order['status']);
        self::assertNull($order['addresses']);
        self::assertSame(0, (int) $order['shipping_total']);
    }

    // -----------------------------------------------------------------
    // 8. vanished shipping method / discount
    // -----------------------------------------------------------------

    public function testAShippingMethodThatNoLongerQuotesIsATypedConflict(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-SHIP-1', 1000);
        $uuid = $this->createDraft(['fulfillment_mode' => 'delivery']);
        $this->addLine($uuid, $variantUuid, 1);
        $this->update($uuid, [
            'addresses' => ['shipping' => ['country' => 'US']],
            'shipping_method' => 'std',
        ]);

        $this->shippingQuotes = [];
        $response = $this->finalize($uuid, self::KEY, 2);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            DraftConflictException::SHIPPING_METHOD,
            $this->json($response)['error']['details']['conflict']
        );
        self::assertSame('draft', (string) $this->orderRow($uuid)['status']);
        self::assertSame(0, $this->attemptCount());
    }

    public function testADiscountCodeThatHasVanishedIsATypedConflict(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-DISC-1', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);
        $this->seedDiscount('SAVE10', 'percentage', 1000);
        $this->update($uuid, ['discount_code' => 'SAVE10']);

        $this->connection->table('commerce_discounts')->where('code', '=', 'SAVE10')->delete();
        $response = $this->finalize($uuid, self::KEY, 2);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            DraftConflictException::DISCOUNT,
            $this->json($response)['error']['details']['conflict']
        );
        self::assertSame('draft', (string) $this->orderRow($uuid)['status']);
    }

    // -----------------------------------------------------------------
    // 9. discount consumption + anonymous identity
    // -----------------------------------------------------------------

    public function testAnOrdinaryDiscountIsConsumedUnderTheOrderUuidForAnAnonymousWalkIn(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-DISC-2', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);
        $this->seedDiscount('SAVE10', 'percentage', 1000);
        $this->update($uuid, ['discount_code' => 'SAVE10']);

        $order = $this->json($this->finalize($uuid, self::KEY, 2))['data'];

        self::assertSame(900, (int) $order['grand_total']);
        $redemption = $this->connection->table('commerce_discount_redemptions')
            ->where('order_uuid', '=', $uuid)
            ->first();
        self::assertIsArray($redemption);
        self::assertSame($uuid, (string) $redemption['buyer_identity']);
        self::assertSame($uuid, (string) $redemption['buyer_key']);
        self::assertSame(
            1,
            (int) $this->connection->table('commerce_discounts')->where('code', '=', 'SAVE10')->first()['usage_count']
        );
    }

    public function testAOncePerBuyerDiscountOnAnAnonymousDraftIsRefusedAtFinalize(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-DISC-3', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);
        $this->seedDiscount('ONCE', 'percentage', 1000, oncePerBuyer: true);
        // The code is applied while the draft HAS an email, then the identity is
        // detached out from under it. The mutation surface clears a code it can no
        // longer validate, so the ownerless pairing is written directly -- which is
        // precisely the state a concurrent detach (or a restored backup) can leave
        // behind, and exactly what finalize must refuse rather than consume.
        $this->update($uuid, ['email' => 'ada@example.com', 'discount_code' => 'ONCE']);
        $this->connection->table('commerce_orders')
            ->where('uuid', '=', $uuid)
            ->update(['email' => null]);

        $response = $this->finalize($uuid, self::KEY, 2);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('discount_code', $this->json($response)['error']['details']);
        self::assertSame('draft', (string) $this->orderRow($uuid)['status']);
        self::assertSame(0, $this->attemptCount());
        self::assertSame(
            0,
            (int) $this->connection->table('commerce_discounts')->where('code', '=', 'ONCE')->first()['usage_count']
        );
    }

    public function testAOncePerBuyerDiscountKeyedByEmailIsConsumedUnderThatIdentity(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-DISC-4', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);
        $this->seedDiscount('ONCE', 'percentage', 1000, oncePerBuyer: true);
        $this->update($uuid, ['email' => 'Ada@Example.com', 'discount_code' => 'ONCE']);

        self::assertSame(200, $this->finalize($uuid, self::KEY, 2)->getStatusCode());

        $redemption = $this->connection->table('commerce_discount_redemptions')
            ->where('order_uuid', '=', $uuid)
            ->first();
        self::assertIsArray($redemption);
        self::assertSame('ada@example.com', (string) $redemption['buyer_identity']);
        self::assertSame('ada@example.com', (string) $redemption['buyer_key']);
    }

    // -----------------------------------------------------------------
    // 10. rollback leaves an editable draft with zero side effects
    // -----------------------------------------------------------------

    /**
     * The failure is forced at the LAST possible business step (an exhausted
     * discount, refused inside `DiscountService::consume()`), which is reached
     * only AFTER the stock claim, the order-number allocation, the snapshot
     * replacement, and the movement rows. Every one of those must roll back --
     * including the sequence increment, which is what makes Ruling 8's
     * "abandoned drafts never consume order numbers" true rather than hopeful.
     */
    public function testAFailureAtTheLastStepRollsBackEverythingAndLeavesAnEditableDraft(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-ROLL-1', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 2);
        $this->seedDiscount('EXHAUSTED', 'percentage', 1000);
        $this->update($uuid, ['discount_code' => 'EXHAUSTED']);
        $this->connection->table('commerce_discounts')
            ->where('code', '=', 'EXHAUSTED')
            ->update(['usage_limit' => 0]);

        $response = $this->finalize($uuid, self::KEY, 2);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());

        $row = $this->orderRow($uuid);
        self::assertSame('draft', (string) $row['status']);
        self::assertNull($row['order_number']);
        self::assertNull($row['placed_at']);
        self::assertSame(2, (int) $row['draft_revision'], 'a refused finalize never bumps the revision');

        self::assertSame(100, (new StockRepository())->quantity($this->context, self::TENANT, $variantUuid));
        self::assertSame(0, $this->movementCount($uuid));
        self::assertSame(0, $this->attemptCount(), 'the pending claim rolled back with everything else');
        self::assertSame([DraftOrderEvents::CREATED], $this->eventTypesFor($uuid));
        self::assertSame(0, $this->placedDispatches);
        self::assertSame(
            0,
            $this->connection->table('commerce_sequences')->count(),
            'a failed finalize must not consume an order number'
        );

        // Still editable, and the very next successful finalize takes the FIRST
        // order number -- no gap.
        $this->connection->table('commerce_discounts')
            ->where('code', '=', 'EXHAUSTED')
            ->update(['usage_limit' => null]);
        $this->update($uuid, ['customer_name' => 'Ada Lovelace']);
        $order = $this->json($this->finalize($uuid, 'a-second-idempotency-key', 3))['data'];
        self::assertSame('ORD-000001', $order['order_number']);
        self::assertSame('Ada Lovelace', $order['customer_name']);
    }

    // -----------------------------------------------------------------
    // 11. after-commit dispatch triple + payload compatibility
    // -----------------------------------------------------------------

    public function testOrderPlacedDispatchesOnceOnFreshFinalizeNeverOnRollbackOrReplay(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-EVT-1', 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);

        // rollback => zero
        $this->finalize($uuid, self::KEY, 0);
        self::assertSame(0, $this->placedDispatches);

        // fresh => exactly one
        $this->finalize($uuid, self::KEY, 1);
        self::assertSame(1, $this->placedDispatches);

        // replay => none
        $this->finalize($uuid, self::KEY, 1);
        self::assertSame(1, $this->placedDispatches);
    }

    public function testOrderPlacedCarriesTheFinalizedRawRowIncludingAdminOrigin(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-EVT-2', 1000);
        $uuid = $this->createDraft(['customer_name' => 'Ada Lovelace']);
        $this->addLine($uuid, $variantUuid, 1);

        $this->finalize($uuid, self::KEY, 1);

        self::assertCount(1, $this->placedPayloads);
        $payload = $this->placedPayloads[0];
        self::assertSame('admin', (string) $payload['origin']);
        self::assertSame('pending_payment', (string) $payload['status']);
        self::assertSame('ORD-000001', (string) $payload['order_number']);
        self::assertSame('Ada Lovelace', (string) $payload['customer_name']);
        self::assertSame(1000, (int) $payload['grand_total']);
        // The RAW row, exactly as every pre-existing OrderPlaced consumer expects.
        foreach (['uuid', 'tenant_uuid', 'currency', 'placed_at', 'guest_token_hash'] as $field) {
            self::assertArrayHasKey($field, $payload, "OrderPlaced payload must carry {$field}");
        }
    }

    // -----------------------------------------------------------------
    // 12. real-PostgreSQL lane: two concurrent finalizers
    // -----------------------------------------------------------------

    /**
     * Design spec §2.5.6/Ruling 8: transactional numbering is CLAIMED only
     * because the allocator is savepoint-isolated (Task 4). This is that claim's
     * precondition test, driven through a REAL finalize rather than through
     * `OrderNumberGenerator` alone.
     *
     * Connection B (subprocess) finalizes its own draft inside its own
     * transaction and holds it open/uncommitted; connection A (this test) then
     * finalizes a DIFFERENT draft. Both are the tenant's first order, so A's
     * `next()` sees no `commerce_sequences` row (B's insert is invisible),
     * attempts its own insert, and blocks on the unique index until B commits --
     * at which point A's insert conflicts. Pre-Task-4 that aborted A's ambient
     * PostgreSQL transaction outright and the whole finalize died; post-fix the
     * conflict rolls back to A's savepoint only, A falls back to the increment,
     * and A commits a COMPLETE finalize with a DISTINCT order number.
     */
    public function testTwoConcurrentFinalizersGetDistinctNumbersWithoutPoisoningEitherTransaction(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove concurrent finalize numbering.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'finrace0001';

        $this->purgePgsql($connectionA, $tenant);

        try {
            $variantUuid = $this->seedPgsqlPurchasable($connectionA, $tenant);
            $this->seedPgsqlDraft($connectionA, $tenant, 'finraceord1', 'finracelin1', $variantUuid);
            $this->seedPgsqlDraft($connectionA, $tenant, 'finraceord2', 'finracelin2', $variantUuid);

            $handle = $this->launchRaceChild($pgConfig, 'hold_finalize', [
                'tenant' => $tenant,
                'orderUuid' => 'finraceord2',
                'idempotencyKey' => 'race-key-bbbbbbbbbbbb',
                'expectedRevision' => 0,
                'sleepMs' => 600,
            ]);

            usleep(250_000);

            // A's OWN already-open ambient transaction -- the exact shape a real
            // finalize leaves the allocator in, and the one the savepoint fix must
            // never leave aborted.
            $connectionA->getTransactionManager()->begin();
            try {
                $mine = $this->pgsqlFinalizationService($tenant)->finalize(
                    $contextA,
                    'finraceord1',
                    'race-key-aaaaaaaaaaaa',
                    0
                );
                $connectionA->getTransactionManager()->commit();
            } catch (\Throwable $e) {
                // Never leave the ambient transaction open: the cleanup below runs on
                // this same connection and would otherwise roll itself back with it.
                try {
                    $connectionA->getTransactionManager()->rollback();
                } catch (\Throwable) {
                    // deliberately ignored -- never mask the real failure
                }
                throw $e;
            }

            $childResult = $this->collectRaceChild($handle);
            self::assertTrue($childResult['ok'] ?? false, (string) json_encode($childResult));

            $mineNumber = (string) $mine['order']['order_number'];
            $theirNumber = (string) $childResult['orderNumber'];
            self::assertNotSame($mineNumber, $theirNumber, 'two concurrent finalizers must not share a number');
            // B took the sequence row first (it started ~250ms earlier and held it
            // uncommitted), so A is necessarily the side that hit the unique
            // violation and fell back -- pinning the exact pair, rather than merely
            // "distinct", is what stops this passing vacuously if the two ever
            // stopped overlapping.
            self::assertSame('ORD-000001', $theirNumber, 'the holding finalizer takes the first number');
            self::assertSame('ORD-000002', $mineNumber, 'the contending finalizer survives and takes the second');

            // A's ambient transaction was never poisoned: it committed a COMPLETE
            // finalize, lines and all.
            $row = $connectionA->table('commerce_orders')
                ->where('tenant_uuid', '=', $tenant)
                ->where('uuid', '=', 'finraceord1')
                ->first();
            self::assertIsArray($row);
            self::assertSame('pending_payment', (string) $row['status']);
            self::assertSame($mineNumber, (string) $row['order_number']);
        } finally {
            $this->purgePgsql($connectionA, $tenant);
        }
    }

    // =================================================================
    // fixtures
    // =================================================================

    /** @var list<ShippingQuote> */
    private array $shippingQuotes;

    private int $placedDispatches = 0;

    /** @var list<array<string,mixed>> */
    private array $placedPayloads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shippingQuotes = [new ShippingQuote('std', 'Standard', 500)];
        $this->placedDispatches = 0;
        $this->placedPayloads = [];

        $listeners = new ListenerProvider();
        $eventService = new EventService(new EventDispatcher($listeners), $listeners);
        $eventService->addListener(OrderPlaced::class, function (OrderPlaced $event): void {
            $this->placedDispatches++;
            $this->placedPayloads[] = $event->order;
        });
        $this->bind(EventService::class, $eventService);
    }

    // --- request helpers -------------------------------------------------

    private function finalize(string $uuid, mixed $key, mixed $expectedRevision): HttpResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (is_string($key)) {
            $server['HTTP_X_IDEMPOTENCY_KEY'] = $key;
        }

        $body = [];
        if ($expectedRevision !== null) {
            $body['expected_revision'] = $expectedRevision;
        }

        return $this->controller()->finalize(
            Request::create(
                '/commerce/admin/orders/drafts/' . $uuid . '/finalize',
                'POST',
                [],
                [],
                [],
                $server,
                json_encode($body, JSON_THROW_ON_ERROR)
            ),
            $uuid
        );
    }

    /** @param array<string,mixed> $body */
    private function request(array $body): Request
    {
        return Request::create(
            '/commerce/admin/orders/drafts',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    /** @param array<string,mixed> $body */
    private function createDraft(array $body = []): string
    {
        $response = $this->controller()->store($this->request($body));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return (string) $this->json($response)['data']['uuid'];
    }

    /** @param list<array<string,mixed>> $addons */
    private function addLine(string $uuid, string $variantUuid, int $quantity, array $addons = []): string
    {
        $body = ['variant_uuid' => $variantUuid, 'quantity' => $quantity];
        if ($addons !== []) {
            $body['addons'] = $addons;
        }
        $response = $this->controller()->storeLine($this->request($body), $uuid);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $lines = $this->json($response)['data']['lines'];

        return (string) $lines[count($lines) - 1]['uuid'];
    }

    /** @param array<string,mixed> $body */
    private function update(string $uuid, array $body): void
    {
        $response = $this->controller()->update($this->request($body), $uuid);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    private function controller(): AdminOrderDraftController
    {
        return new AdminOrderDraftController($this->context, $this->draftService(), $this->finalizationService());
    }

    private function draftService(): DraftOrderService
    {
        $tenants = new SentinelTenantResolver();
        $discounts = new DiscountRepository();

        return new DraftOrderService(
            new OrderRepository(),
            $this->resolver(),
            new PricingEngine(),
            $this->shippingProvider(),
            $this->taxCalculator(),
            $discounts,
            new DiscountService($discounts, $tenants),
            new DraftCleanupService(new OrderRepository(), $tenants),
            $tenants,
            new MarketplaceMode()
        );
    }

    private function finalizationService(): DraftFinalizationService
    {
        $tenants = new SentinelTenantResolver();
        $discounts = new DiscountRepository();

        return new DraftFinalizationService(
            new OrderRepository(),
            new DraftAttemptRepository(),
            $this->resolver(),
            new PricingEngine(),
            $this->shippingProvider(),
            $this->taxCalculator(),
            $discounts,
            new DiscountService($discounts, $tenants),
            new StockRepository(),
            new OrderNumberGenerator(),
            $tenants,
            new MarketplaceMode()
        );
    }

    private function resolver(): PurchasableLineResolver
    {
        return new PurchasableLineResolver(
            new VariantRepository(),
            new ProductRepository(),
            new AddonRepository(),
            new ShippingClassRepository()
        );
    }

    private function shippingProvider(): ShippingRateProvider
    {
        return new class ($this) implements ShippingRateProvider {
            public function __construct(private DraftFinalizationTest $test)
            {
            }

            public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
            {
                return $this->test->currentShippingQuotes();
            }
        };
    }

    /** @return list<ShippingQuote> */
    public function currentShippingQuotes(): array
    {
        return $this->shippingQuotes;
    }

    /**
     * Zero-rate, plus a one-shot IN-TRANSACTION seam. The tax calculator is
     * consulted on every finalize, after the status/revision checks and before
     * the stock claim, the number allocation, and the two compare-and-sets --
     * which makes it the one honest place to simulate "something else changed
     * this row after we loaded it" on a single connection.
     */
    private function taxCalculator(): TaxCalculator
    {
        return new class ($this) implements TaxCalculator {
            public function __construct(private DraftFinalizationTest $test)
            {
            }

            public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
            {
                $this->test->runDuringFinalizeOnce();

                return new TaxQuote(0);
            }
        };
    }

    /** @var (callable(): void)|null */
    private $duringFinalize = null;

    /** @param callable(): void $hook */
    private function duringFinalize(callable $hook): void
    {
        $this->duringFinalize = $hook;
    }

    public function runDuringFinalizeOnce(): void
    {
        $hook = $this->duringFinalize;
        if ($hook === null) {
            return;
        }
        $this->duringFinalize = null;
        $hook();
    }

    // --- seed helpers ----------------------------------------------------

    private function catalog(): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository(),
            null,
            new ShippingClassRepository()
        );
    }

    private function seedPhysicalProduct(
        string $sku,
        int $price,
        bool $tracked = true,
        ?string &$productUuid = null
    ): string {
        $product = $this->catalog()->createProduct($this->context, [
            'slug' => strtolower($sku),
            'name' => $sku,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => $sku,
                'option_values' => [],
                'price' => $price,
                'currency' => 'USD',
            ]],
        ]);
        $productUuid = (string) $product['uuid'];
        $variantUuid = (string) $product['variants'][0]['uuid'];

        if ($tracked) {
            (new StockRepository())->increment($this->context, self::TENANT, $variantUuid, 100);
        } else {
            $this->connection->table('commerce_stock')
                ->where('variant_uuid', '=', $variantUuid)
                ->update(['tracked' => false]);
        }

        return $variantUuid;
    }

    private function setStock(string $variantUuid, int $quantity): void
    {
        $this->connection->table('commerce_stock')
            ->where('variant_uuid', '=', $variantUuid)
            ->update(['quantity' => $quantity, 'tracked' => true]);
    }

    private function seedCheckboxAddon(string $productUuid, int $priceDelta): string
    {
        $addon = (new AddonService(new AddonRepository(), new ProductRepository(), new SentinelTenantResolver()))
            ->create($this->context, $productUuid, [
                'name' => 'Gift wrap',
                'field_type' => 'checkbox',
                'required' => false,
                'price_delta' => $priceDelta,
            ]);

        return (string) $addon['uuid'];
    }

    private function seedActiveSeller(string $uuid): void
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'slug' => 'seller-' . $uuid,
            'name' => 'Seller ' . $uuid,
            'status' => 'active',
        ]);
    }

    private function activateMarketplace(): void
    {
        $this->context->overrideConfig('commerce.marketplace.enabled', true);
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsettings1',
            'tenant_uuid' => self::TENANT,
            'status' => 'active',
        ]);
    }

    private function seedDiscount(string $code, string $type, int $value, bool $oncePerBuyer = false): void
    {
        $this->connection->table('commerce_discounts')->insert([
            'uuid' => 'dsc' . substr(md5($code), 0, 9),
            'tenant_uuid' => self::TENANT,
            'code' => $code,
            'type' => $type,
            'value' => $value,
            'status' => 'active',
            'once_per_buyer' => $oncePerBuyer,
            'usage_count' => 0,
        ]);
    }

    private function draftWithLine(): string
    {
        static $seq = 0;
        $seq++;
        $variantUuid = $this->seedPhysicalProduct('SKU-BOUND-' . $seq, 1000);
        $uuid = $this->createDraft();
        $this->addLine($uuid, $variantUuid, 1);

        return $uuid;
    }

    // --- read helpers ----------------------------------------------------

    /** @return array<string,mixed> */
    private function orderRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $uuid)->first();
        self::assertIsArray($row);

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function linesFor(string $uuid): array
    {
        return $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', $uuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /** @return list<string> */
    private function eventTypesFor(string $uuid): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['type'],
            $this->connection->table('commerce_order_events')
                ->where('order_uuid', '=', $uuid)
                ->orderBy('id', 'ASC')
                ->get()
        );
    }

    private function attemptCount(): int
    {
        return $this->connection->table('commerce_order_draft_attempts')->count();
    }

    /** @return array<string,mixed> */
    private function attemptRow(string $key): array
    {
        $row = $this->connection->table('commerce_order_draft_attempts')
            ->where('idempotency_key', '=', $key)
            ->first();
        self::assertIsArray($row);

        return $row;
    }

    private function movementCount(string $orderUuid): int
    {
        return $this->connection->table('commerce_stock_movements')
            ->where('reference_uuid', '=', $orderUuid)
            ->count();
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    // --- pgsql lane ------------------------------------------------------
    // (gating/fixture-width/self-healing discipline mirrors
    //  Orders\DraftFinalizeTransitionPgsqlTest exactly.)

    /** @return array<string,mixed> */
    private function pgConfig(): array
    {
        return [
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'glueful_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
            ],
            'pooling' => ['enabled' => false],
        ];
    }

    /** @param array<string,mixed> $pgConfig */
    private function migratedConnection(array $pgConfig): Connection
    {
        $connection = new Connection($pgConfig);
        $schema = $connection->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        return $connection;
    }

    private function pgsqlContext(Connection $connection): ApplicationContext
    {
        $container = new class ($connection) implements ContainerInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database' || $id === Connection::class;
            }
        };

        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->setContainer($container);
        $context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../config/commerce.php');

        return $context;
    }

    private function pgsqlFinalizationService(string $tenant): DraftFinalizationService
    {
        // A FIXED tenant resolver, not the sentinel: the pgsql lane runs against a
        // shared database, so its fixtures live under their own tenant and every
        // purge above is scoped to it.
        $tenants = new class ($tenant) implements CurrentTenantResolver {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
        $discounts = new DiscountRepository();

        return new DraftFinalizationService(
            new OrderRepository(),
            new DraftAttemptRepository(),
            $this->resolver(),
            new PricingEngine(),
            new \Glueful\Extensions\Commerce\Shipping\ConfigShippingRateProvider(),
            new \Glueful\Extensions\Commerce\Tax\FlatRateTaxCalculator(),
            $discounts,
            new DiscountService($discounts, $tenants),
            new StockRepository(),
            new OrderNumberGenerator(),
            $tenants,
            new MarketplaceMode()
        );
    }

    private function seedPgsqlPurchasable(Connection $connection, string $tenant): string
    {
        $connection->table('commerce_products')->insert([
            'uuid' => 'finraceprd1',
            'tenant_uuid' => $tenant,
            'slug' => 'finrace-product',
            'name' => 'Finalize Race Product',
            'type' => 'physical',
            'status' => 'active',
        ]);
        $connection->table('commerce_variants')->insert([
            'uuid' => 'finracevar1',
            'tenant_uuid' => $tenant,
            'product_uuid' => 'finraceprd1',
            'sku' => 'SKU-FINRACE',
            'option_values' => '[]',
            'price' => 1000,
            'currency' => 'USD',
        ]);

        return 'finracevar1';
    }

    private function seedPgsqlDraft(
        Connection $connection,
        string $tenant,
        string $orderUuid,
        string $lineUuid,
        string $variantUuid
    ): void {
        $connection->table('commerce_orders')->insert([
            'uuid' => $orderUuid,
            'tenant_uuid' => $tenant,
            'order_number' => null,
            'status' => 'draft',
            'email' => null,
            'guest_token_hash' => null,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'origin' => 'admin',
            'fulfillment_mode' => 'in_store',
            'draft_revision' => 0,
        ]);
        $connection->table('commerce_order_lines')->insert([
            'uuid' => $lineUuid,
            'order_uuid' => $orderUuid,
            'variant_uuid' => $variantUuid,
            'product_name' => 'Finalize Race Product',
            'sku' => 'SKU-FINRACE',
            'option_values' => '[]',
            'unit_price' => 1000,
            'quantity' => 1,
            'line_total' => 1000,
        ]);
    }

    private function purgePgsql(Connection $connection, string $tenant): void
    {
        foreach (['finraceord1', 'finraceord2'] as $orderUuid) {
            $connection->table('commerce_order_events')->where('order_uuid', '=', $orderUuid)->delete();
            $connection->table('commerce_order_lines')->where('order_uuid', '=', $orderUuid)->delete();
        }
        $connection->table('commerce_order_draft_attempts')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_sequences')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_variants')->where('tenant_uuid', '=', $tenant)->delete();
        // `commerce_products` is soft-deletable, so `delete()` would leave the row
        // (and its globally-unique uuid) behind and poison the NEXT run's seed.
        $connection->table('commerce_products')->where('tenant_uuid', '=', $tenant)->forceDelete();
    }

    /**
     * @param array<string,mixed> $pgConfig
     * @param array<string,mixed> $args
     * @return array{0: resource, 1: array<int,resource>}
     */
    private function launchRaceChild(array $pgConfig, string $action, array $args): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/draft_finalization_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $action,
                json_encode($args, JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int,resource>} $handle
     * @return array<string,mixed>
     */
    private function collectRaceChild(array $handle): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "subprocess produced no parseable result. stderr: {$stderr}");

        return $result;
    }
}
