<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\AddonSnapshot;
use Glueful\Extensions\Commerce\Contracts\LineTaxCalculator;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Events\OrderPlaced;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Orders\Events\DraftOrderEvents;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Pricing\Totals;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Commerce\Tax\DiscountAllocation;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Events\EventService;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * THE FINALIZATION AUTHORITY (admin-order-creation cycle 2, Task 10, design
 * spec §2.5): the one and only code path that turns an advisory admin DRAFT
 * into a real order. Design Ruling 2 names what that means -- finalize, and
 * only finalize, decides current prices/discounts/shipping/tax/totals, claims
 * stock, allocates the order number, performs the `draft -> pending_payment`
 * transition, and owns idempotency and concurrent-finalization protection.
 *
 * Everything else in the draft surface is deliberately FORGIVING (a draft is an
 * operator's scratch work: {@see DraftOrderService::recalculate()} accepts drift
 * rather than failing, {@see DraftOrderService::classify()} degrades an
 * unresolvable line to neutral defaults, and a stale shipping method or discount
 * code is silently cleared on an unrelated line edit). That design only holds
 * because THIS class refuses. If finalize does not check something, nothing
 * does.
 *
 * ## The shape of one finalize
 *
 * 1. **Input contract, before anything else.** `X-Idempotency-Key` must match
 *    {@see self::IDEMPOTENCY_KEY_PATTERN} and `expected_revision` must be a
 *    non-negative integer. Both are validated BEFORE the order lookup and before
 *    the ledger is touched at all, so a malformed request can never create an
 *    attempt row and can never be used to probe which uuids exist.
 * 2. **Read-only tenant-scoped preflight.** Unknown and cross-tenant uuids get
 *    the same non-revealing 404, with ZERO attempt-ledger writes. This is
 *    CONTAINMENT ONLY -- every invariant it happens to observe is re-read under
 *    lock inside the transaction, so nothing here is authority. Note what it
 *    does NOT check: status. A finalized order must stay reachable for an
 *    idempotent REPLAY, so "not a draft" is resolved inside the transaction as a
 *    typed conflict, after replay resolution -- never hidden behind a 404 the way
 *    the mutation endpoints hide one.
 * 3. **One transaction**, in this order: lock the order row; claim/replay the
 *    attempt BEFORE any mutation; verify status/revision/currency; verify the
 *    fulfillment-mode contract; re-resolve every line against CURRENT catalog
 *    state; recompute money; claim stock; allocate the number; replace the
 *    advisory snapshots in place; record movements; consume the discount; stamp
 *    the order's finalization fields; run the DEDICATED `draft ->
 *    pending_payment` compare-and-set; complete the attempt; write the audit
 *    row.
 * 4. **`OrderPlaced` after commit only**, mirroring
 *    {@see CheckoutService::placeOrder()} (CheckoutService.php:231): a rollback
 *    dispatches zero, a fresh finalize exactly one, a replay none.
 *
 * ## Why the claim comes second, and the state checks third
 *
 * The attempt claim runs immediately after the row lock and before ANY business
 * mutation, so a fresh claim is rolled back by every later failure -- there is
 * no window in which a pending claim outlives the work it was claiming. Resolving
 * a REPLAY before the status/revision/currency checks is equally deliberate
 * (design spec §2.5.2): after a successful finalize the order is no longer a
 * draft and its `draft_revision` is whatever it was, so a replay of the ORIGINAL
 * request must not be re-judged against the post-finalize state. It resolves
 * straight to the finalized order.
 *
 * ## Why the number is allocated late, and only once
 *
 * {@see OrderNumberGenerator::next()} is savepoint-isolated (Task 4) precisely so
 * it can run inside this transaction without a caught unique violation poisoning
 * it on PostgreSQL. It is called EXACTLY once per finalize, after every check
 * that can refuse -- so an abandoned or refused finalize consumes no number
 * (design Ruling 8), and no loop can accumulate savepoints in one long
 * transaction.
 */
final class DraftFinalizationService
{
    /**
     * The `X-Idempotency-Key` contract, verbatim from design spec §2.5.1.
     * Deliberately a CHARACTER-CLASS allowlist rather than a length check plus
     * sanitization: the key is a primary-key component of
     * `commerce_order_draft_attempts`, and the SPA's own key is a
     * `crypto.randomUUID()` (which fits comfortably), so anything outside this
     * class is a client bug worth reporting rather than something to normalize.
     */
    public const IDEMPOTENCY_KEY_PATTERN = '/\A[A-Za-z0-9._:-]{16,191}\z/';

    /**
     * Per-line conflict reasons ADDITIONAL to {@see DraftLineEligibility::REASONS}
     * (`digital`, `marketplace`, `unavailable`, which this class reports with the
     * SAME strings the mutation surface and the admin product search already
     * publish -- one vocabulary, three surfaces).
     *
     * `drift` is the finalize-only one: the line still resolves and is still
     * eligible, but its authoritative price or its current add-on definitions no
     * longer match the advisory snapshot the operator quoted from.
     * `stock` is likewise finalize-only -- a draft claims no stock, so this can
     * only ever be discovered here.
     */
    public const LINE_DRIFT = 'drift';
    public const LINE_STOCK = 'stock';

    public function __construct(
        private OrderRepository $orders,
        private DraftAttemptRepository $attempts,
        private PurchasableLineResolver $lines,
        private PricingEngine $pricing,
        private ShippingRateProvider $shipping,
        private TaxCalculator $tax,
        private DiscountRepository $discounts,
        private DiscountService $discountService,
        private StockRepository $stock,
        private OrderNumberGenerator $numbers,
        private CurrentTenantResolver $tenants,
        private ?MarketplaceMode $marketplace = null,
    ) {
        $this->marketplace ??= new MarketplaceMode();
    }

    /**
     * @param mixed $idempotencyKey the RAW `X-Idempotency-Key` header value
     *     (`null` when absent) -- validated here rather than in the controller so
     *     the "validate before lookup" ordering is a service invariant that a
     *     second caller could not accidentally reorder.
     * @param mixed $expectedRevision the RAW request field
     * @return array{order: array<string,mixed>, lines: list<array<string,mixed>>, replay: bool}
     */
    public function finalize(
        ApplicationContext $context,
        string $uuid,
        mixed $idempotencyKey,
        mixed $expectedRevision
    ): array {
        $key = $this->assertIdempotencyKey($idempotencyKey);
        $expected = $this->assertExpectedRevision($expectedRevision);

        $tenant = $this->tenants->tenantUuid($context);

        // Containment-only preflight (design spec §2.5): unknown and cross-tenant
        // uuids are indistinguishable, and neither reaches the ledger.
        if ($this->orders->findByUuid($context, $tenant, $uuid, true) === null) {
            throw new NotFoundException('Resource not found.');
        }

        $fingerprint = self::fingerprint($uuid, $expected);

        /** @var array{order: array<string,mixed>, lines: list<array<string,mixed>>, replay: bool} $result */
        $result = db($context)->transaction(
            fn (): array => $this->run($context, $tenant, $uuid, $key, $fingerprint, $expected)
        );

        // After-commit dispatch, matching CheckoutService.php:231 semantics: a
        // throw above never reaches this line (zero dispatch on rollback), a
        // replay resolves to an order a PRIOR call already announced, and a fresh
        // finalize announces exactly once. The payload is the finalized RAW row
        // -- listeners and webhook fan-out read internal columns -- which now
        // carries `origin = 'admin'`.
        if (!$result['replay']) {
            $this->dispatch($context, new OrderPlaced($result['order']));
        }

        return $result;
    }

    /**
     * SHA-256 over the canonical `{order_uuid, expected_revision}` pair (design
     * spec §2.5.1). Canonical = fixed key order, JSON-encoded, so the digest is
     * stable across PHP versions and never depends on array insertion order.
     *
     * The pair is exactly what makes the SPA's key discipline work: one key per
     * `(draft_uuid, expected_revision)`, retained across ambiguous failures and
     * rotated when the revision changes. Reusing a key for a different draft, or
     * for the same draft at a different revision, is therefore a deterministic
     * {@see DraftConflictException::idempotencyKeyReuse()} rather than a silent
     * cross-request replay.
     */
    public static function fingerprint(string $orderUuid, int $expectedRevision): string
    {
        return hash('sha256', json_encode(
            ['order_uuid' => $orderUuid, 'expected_revision' => $expectedRevision],
            JSON_THROW_ON_ERROR
        ));
    }

    // -----------------------------------------------------------------
    // the transaction
    // -----------------------------------------------------------------

    /**
     * @return array{order: array<string,mixed>, lines: list<array<string,mixed>>, replay: bool}
     */
    private function run(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $key,
        string $fingerprint,
        int $expected
    ): array {
        // 1. Lock. Different keys for the SAME draft serialize here.
        $order = $this->orders->findByUuidForUpdate($context, $tenant, $uuid, true);
        if ($order === null) {
            throw new NotFoundException('Resource not found.');
        }

        // 2. Claim/replay, BEFORE any stock or business mutation.
        $claim = $this->attempts->claimOrReplay($context, $tenant, $key, $fingerprint, $uuid);
        if ($claim['state'] === 'fingerprint_mismatch') {
            throw DraftConflictException::idempotencyKeyReuse();
        }
        if ($claim['state'] === 'replay' && (string) $claim['attempt']['status'] === 'completed') {
            return $this->replay($context, $tenant, (string) $claim['attempt']['order_uuid']);
        }
        // A REPLAY of a still-`pending` attempt is the crash-window case: the
        // claim committed but the work did not (or the process died before
        // completing it). We hold the row lock and the fingerprint matches, so
        // this attempt is ours to finish -- re-execute against it rather than
        // stranding the operator behind a permanently pending key.
        $attemptId = (int) $claim['attempt']['id'];

        // 3. State: status, revision, currency -- each a distinct typed conflict.
        $status = (string) $order['status'];
        if ($status !== OrderScope::DRAFT) {
            throw DraftConflictException::notDraft($status);
        }
        if ((int) $order['draft_revision'] !== $expected) {
            throw DraftConflictException::staleRevision();
        }
        $storeCurrency = CommerceSettings::currency($context);
        $draftCurrency = (string) $order['currency'];
        if ($draftCurrency !== $storeCurrency) {
            throw DraftConflictException::currencyChanged($draftCurrency, $storeCurrency);
        }

        // 4. The fulfillment-mode contract (design Ruling 5's POSITIVE half,
        //    which the mutation surface deliberately defers to here).
        $this->assertFulfillable($order);

        // 5. Re-resolve every line against CURRENT catalog state.
        $stored = $this->orders->linesForOrder($context, $tenant, $uuid);
        if ($stored === []) {
            throw ValidationException::forField('lines', 'A draft order needs at least one line to be finalized.');
        }
        $resolved = $this->reresolve($context, $tenant, $stored);

        // 6. Money, recomputed from the AUTHORITATIVE lines; the draft's stored
        //    advisory totals are discarded entirely.
        $priced = $this->pricedLines($resolved);
        $discount = $this->resolveDiscount($context, $tenant, $order, $priced);
        $quote = $this->resolveShipping($context, $order, $priced);
        $preTax = $this->pricing->price($priced, $discount, $quote, null);
        $shippingAddress = $this->shippingAddress($order);
        $taxQuote = $this->resolveTax($context, $priced, $discount, $preTax, $shippingAddress);
        $totals = $this->pricing->price($priced, $discount, $quote, $taxQuote);

        // 7. Stock, claimed atomically per tracked line.
        $this->claimStock($context, $tenant, $resolved);

        // 8. The number: exactly one allocation, after every refusing check.
        $number = $this->numbers->next($context, $tenant);

        // 9. Authoritative snapshots replace the advisory ones IN PLACE (stable
        //    line uuids -- no duplicate insertion), then the movement rows.
        foreach ($resolved as $entry) {
            db($context)->table('commerce_order_lines')
                ->where('uuid', '=', $entry['line_uuid'])
                ->where('order_uuid', '=', $uuid)
                ->update($this->lineChanges($entry['resolved']));
        }
        foreach ($resolved as $entry) {
            if (!$this->stock->isTracked($context, $tenant, $entry['resolved']->variantUuid)) {
                continue;
            }
            $this->stock->recordMovement(
                $context,
                $tenant,
                $entry['resolved']->variantUuid,
                -$entry['resolved']->quantity,
                'order',
                $uuid
            );
        }

        // 10. Discount consumption (design spec §2.5.7's anonymous identity rule).
        if ($discount !== null) {
            $this->consumeDiscount($context, $order, $uuid, $discount);
        }

        // 11. Stamp the finalization fields, then run the DEDICATED transition.
        //     Both writes -- and every write above -- share this ONE transaction:
        //     there is no commit between the stamping and the compare-and-set, so
        //     an order can never be observed as `pending_payment` without its
        //     number, totals, and `placed_at` (Task 8's documented obligation).
        $this->stampFinalizedFields($context, $tenant, $uuid, $number, $totals, $quote, $discount);
        $this->orders->finalizeDraftTransition($context, $tenant, $uuid);

        // 12. The attempt is bound to the order it just finalized, in this same
        //     transaction -- the claim can never commit separately from the work.
        $this->attempts->complete($context, $attemptId);
        $this->orders->recordEvent($context, $uuid, DraftOrderEvents::FINALIZED, ['number' => $number]);

        $finalized = $this->orders->findByUuid($context, $tenant, $uuid);
        if ($finalized === null) {
            throw new \RuntimeException('Finalized order could not be reloaded.');
        }

        return [
            'order' => $finalized,
            'lines' => $this->orders->linesForOrder($context, $tenant, $uuid),
            'replay' => false,
        ];
    }

    /**
     * A completed attempt resolves to the order it finalized, with NO
     * re-execution of any kind. The lookup deliberately keeps `includeDrafts`
     * true: the stored order should be `pending_payment` by now, but reading it
     * through the draft-inclusive finder means a corrupted ledger surfaces as a
     * loud reload failure rather than as a silent 404.
     *
     * @return array{order: array<string,mixed>, lines: list<array<string,mixed>>, replay: bool}
     */
    private function replay(ApplicationContext $context, string $tenant, string $orderUuid): array
    {
        $order = $this->orders->findByUuid($context, $tenant, $orderUuid, true);
        if ($order === null) {
            throw new \RuntimeException('Replayed finalize attempt order could not be reloaded.');
        }

        return [
            'order' => $order,
            'lines' => $this->orders->linesForOrder($context, $tenant, $orderUuid),
            'replay' => true,
        ];
    }

    // -----------------------------------------------------------------
    // line re-resolution
    // -----------------------------------------------------------------

    /**
     * Re-resolve every stored line through
     * {@see PurchasableLineResolver::resolveSelections()} -- the CURRENT-definition
     * entry point, deliberately NOT the persisted-snapshot one storefront checkout
     * uses. That asymmetry IS the feature (design spec §2.4): a cart line's
     * persisted snapshot must never reprice, while a draft line's advisory
     * snapshot must be re-judged against reality before it becomes an order.
     *
     * Every offending line is collected before ANY of them is reported, so an
     * operator fixing a multi-line draft sees the whole list at once instead of
     * peeling it one refused finalize at a time.
     *
     * @param list<array<string,mixed>> $stored
     * @return list<array{line_uuid: string, resolved: ResolvedLine}>
     */
    private function reresolve(ApplicationContext $context, string $tenant, array $stored): array
    {
        $partitioning = $this->partitioning($context, $tenant);
        $resolved = [];
        $conflicts = [];

        foreach ($stored as $line) {
            $lineUuid = (string) $line['uuid'];
            $variantUuid = (string) $line['variant_uuid'];
            $snapshot = is_array($line['addons'] ?? null) ? $line['addons'] : [];

            try {
                $line0 = $this->lines->resolveSelections(
                    $context,
                    $tenant,
                    $variantUuid,
                    (int) $line['quantity'],
                    $this->selectionsFromSnapshot($snapshot)
                );
            } catch (ValidationException $e) {
                // A `variant_uuid`-keyed failure is exactly the set the mutation
                // surface reports as `unavailable`, so it keeps that word. Anything
                // else (an `addons`-keyed failure -- a definition deleted,
                // deactivated, or made required since the line was drafted) is
                // definition drift by another name.
                $conflicts[] = $this->conflictRow(
                    $lineUuid,
                    $line,
                    $e->firstError('variant_uuid') !== null
                        ? DraftLineEligibility::UNAVAILABLE
                        : self::LINE_DRIFT,
                    null
                );
                continue;
            }

            $reason = DraftLineEligibility::forResolvedLine($line0, $partitioning);
            if ($reason !== null) {
                $conflicts[] = $this->conflictRow($lineUuid, $line, $reason, $line0);
                continue;
            }

            if ($this->hasDrifted($line, $line0)) {
                $conflicts[] = $this->conflictRow($lineUuid, $line, self::LINE_DRIFT, $line0);
                continue;
            }

            $resolved[] = ['line_uuid' => $lineUuid, 'resolved' => $line0];
        }

        if ($conflicts !== []) {
            throw DraftConflictException::lineConflicts($conflicts);
        }

        return $resolved;
    }

    /**
     * Has this line's authoritative resolution moved away from what the operator
     * quoted? Two facts, and deliberately only two:
     *
     *  - `unit_price` -- the money. A changed variant price (or a changed add-on
     *    `price_delta`) lands here.
     *  - the canonical add-on snapshot HASH -- the definitions. This catches a
     *    definition edit that does NOT move the price (a renamed add-on, a
     *    relabelled `select` choice), which would otherwise let an order ship
     *    carrying a description the operator never saw.
     *
     * `sku`/`product_name`/`option_values` are deliberately NOT drift: they are
     * display facts the finalize REPLACES with current values anyway (step 9), so
     * refusing on them would block a sale over a typo fix with no money or
     * fulfilment consequence.
     *
     * @param array<string,mixed> $stored
     */
    private function hasDrifted(array $stored, ResolvedLine $resolved): bool
    {
        if ((int) $stored['unit_price'] !== $resolved->unitPrice) {
            return true;
        }

        $storedSnapshot = is_array($stored['addons'] ?? null) ? $stored['addons'] : [];

        return AddonSnapshot::hash($storedSnapshot) !== $resolved->addonsHash;
    }

    /**
     * @param array<string,mixed> $stored
     * @return array<string,mixed>
     */
    private function conflictRow(string $lineUuid, array $stored, string $reason, ?ResolvedLine $resolved): array
    {
        return [
            'line_uuid' => $lineUuid,
            'variant_uuid' => (string) $stored['variant_uuid'],
            'sku' => (string) ($stored['sku'] ?? ''),
            'product_name' => (string) ($stored['product_name'] ?? ''),
            'quantity' => (int) $stored['quantity'],
            'reason' => $reason,
            'unit_price' => (int) $stored['unit_price'],
            'current_unit_price' => $resolved?->unitPrice,
        ];
    }

    /**
     * The ORDER-level marketplace decision, composed per call exactly as
     * {@see CheckoutService::placeOrder()} and {@see DraftOrderService} compose it
     * -- config-only master switch first (so a non-marketplace install runs zero
     * `commerce_marketplace_settings` queries), then the workspace activation.
     * Never memoized: this service is registered `shared`, so a memo would be
     * cross-tenant wrong, not merely stale.
     */
    private function partitioning(ApplicationContext $context, string $tenant): bool
    {
        return $this->marketplace->installEnabled($context)
            && $this->marketplace->activeFor($context, $tenant);
    }

    // -----------------------------------------------------------------
    // money
    // -----------------------------------------------------------------

    /**
     * The AUTHORITATIVE priced lines, shaped for {@see PricingEngine},
     * {@see ShippingRateProvider}, {@see DiscountAllocation}, and the tax
     * calculator. Unlike {@see DraftOrderService}'s advisory equivalent, every
     * value here comes from the fresh resolution -- including the money -- so no
     * stored column can influence the total.
     *
     * @param list<array{line_uuid: string, resolved: ResolvedLine}> $resolved
     * @return list<array<string,mixed>>
     */
    private function pricedLines(array $resolved): array
    {
        return array_map(static function (array $entry): array {
            $line = $entry['resolved'];

            return [
                'line_uuid' => $entry['line_uuid'],
                'product_uuid' => $line->productUuid,
                'variant_uuid' => $line->variantUuid,
                'unit_price' => $line->unitPrice,
                'quantity' => $line->quantity,
                'type' => $line->type,
                'shipping_class' => $line->shippingClass,
                'tax_class' => $line->taxClass,
                'sku' => $line->sku,
                'product_name' => $line->productName,
                'addons' => $line->addons,
            ];
        }, $resolved);
    }

    /**
     * The draft's stored shipping selection, re-quoted LIVE and STRICTLY. An
     * `in_store` order has none by construction (design Ruling 5). A `delivery`
     * order whose chosen method no longer appears in a live quote for its current
     * lines and address is a typed conflict, never a silent fallback to another
     * method or to free shipping -- the amount the operator quoted at the counter
     * is the amount that must be charged, or the sale stops.
     *
     * @param array<string,mixed> $order
     * @param list<array<string,mixed>> $lines
     */
    private function resolveShipping(ApplicationContext $context, array $order, array $lines): ?ShippingQuote
    {
        if ((string) $order['fulfillment_mode'] !== DraftOrderService::MODE_DELIVERY) {
            return null;
        }

        $methodId = (string) $order['shipping_method'];
        foreach ($this->shipping->quote($context, $lines, $this->shippingAddress($order)) as $option) {
            if ($option->id === $methodId) {
                return $option;
            }
        }

        throw DraftConflictException::shippingUnavailable($methodId);
    }

    /**
     * Mirrors {@see CheckoutService::resolveTax()} exactly: a
     * {@see LineTaxCalculator} gets per-line post-discount detail, anything else
     * gets the aggregate call.
     *
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed>|null $discount
     * @param array<string,mixed> $shippingAddress
     */
    private function resolveTax(
        ApplicationContext $context,
        array $lines,
        ?array $discount,
        Totals $preTax,
        array $shippingAddress
    ): TaxQuote {
        if (!$this->tax instanceof LineTaxCalculator) {
            return $this->tax->quote($context, $preTax->grandTotal, $shippingAddress);
        }

        return $this->tax->quoteDetailed(
            $context,
            DiscountAllocation::taxableLines($lines, $discount, $preTax->discountTotal),
            $preTax->shippingTotal,
            $shippingAddress
        );
    }

    /**
     * The draft's stored discount code, revalidated STRICTLY against the
     * authoritative lines. Where a draft mutation would silently clear a code
     * that has since become unusable, finalize refuses: a vanished, inactive,
     * expired, or inapplicable code changes what the customer pays, and quietly
     * charging them full price at the counter is worse than stopping.
     *
     * The ANONYMOUS `once_per_buyer` rule (design spec §2.5.7) is a 422 rather
     * than a conflict: it is not a state race, it is a request that can never
     * succeed as drafted -- the operator must attach an email or an account, or
     * drop the code.
     *
     * @param array<string,mixed> $order
     * @param list<array<string,mixed>> $lines
     * @return array<string,mixed>|null
     */
    private function resolveDiscount(
        ApplicationContext $context,
        string $tenant,
        array $order,
        array $lines
    ): ?array {
        $code = isset($order['discount_code']) ? (string) $order['discount_code'] : '';
        if ($code === '') {
            return null;
        }

        $discount = $this->discounts->findByCode($context, $tenant, $code);
        if ($discount === null) {
            throw DraftConflictException::discountUnusable(
                "Discount code '{$code}' is no longer valid; remove it and retry."
            );
        }

        if ((int) ($discount['once_per_buyer'] ?? 0) === 1 && $this->buyerIdentity($order) === null) {
            throw ValidationException::forField(
                'discount_code',
                'This discount is limited to one use per buyer, so the order needs a customer email or account.'
            );
        }

        try {
            $this->discountService->validateForCart(
                $context,
                $discount,
                $this->pricing->discountableBase($lines, null),
                $lines
            );
        } catch (ValidationException $e) {
            throw DraftConflictException::discountUnusable(
                $e->firstError('discount_code') ?? 'This discount can no longer be applied to this order.'
            );
        }

        return $discount;
    }

    /**
     * Consumption, with the ANONYMOUS identity rule (design spec §2.5.7): an
     * ordinary discount on a walk-in with no email and no account is keyed by the
     * ORDER's own uuid -- never by an empty string, which would silently collapse
     * every anonymous redemption into one shared identity. A `once_per_buyer`
     * discount never reaches here without a real identity
     * ({@see self::resolveDiscount()} already refused).
     *
     * An exhausted usage counter surfaces from
     * {@see DiscountService::consume()} as a `discount_code` validation failure;
     * it is a genuine race (someone else spent the last use between validation and
     * consumption), so it is re-typed as the discount CONFLICT rather than
     * reported as bad input.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $discount
     */
    private function consumeDiscount(
        ApplicationContext $context,
        array $order,
        string $orderUuid,
        array $discount
    ): void {
        try {
            $this->discountService->consume(
                $context,
                $discount,
                $orderUuid,
                $this->buyerIdentity($order) ?? $orderUuid
            );
        } catch (ValidationException $e) {
            throw DraftConflictException::discountUnusable(
                $e->firstError('discount_code') ?? 'This discount can no longer be applied to this order.'
            );
        }
    }

    /**
     * The order's buyer identity for discount purposes, or `null` when the
     * walk-in is fully anonymous. `user_uuid` wins over `email` exactly as
     * {@see DiscountService::buyerIdentity()} decides it for checkout -- this
     * method only adds the "neither is present" case checkout can never reach.
     *
     * @param array<string,mixed> $order
     */
    private function buyerIdentity(array $order): ?string
    {
        $userUuid = isset($order['user_uuid']) ? trim((string) $order['user_uuid']) : '';
        $email = isset($order['email']) ? trim((string) $order['email']) : '';
        if ($userUuid === '' && $email === '') {
            return null;
        }

        return DiscountService::buyerIdentity($userUuid === '' ? null : $userUuid, $email);
    }

    // -----------------------------------------------------------------
    // stock
    // -----------------------------------------------------------------

    /**
     * One atomic {@see StockRepository::decrement()} per TRACKED line, exactly as
     * {@see CheckoutService::placeOrderAttempt()} claims it. Untracked variants
     * are skipped, not failed -- "untracked" means the merchant asked us not to
     * count it.
     *
     * Unlike checkout (which throws {@see InsufficientStockException} on the first
     * short line), every short line is collected and reported together: an
     * operator at a counter needs the whole picture to decide what to remove, and
     * the transaction rolls every already-applied decrement back regardless.
     *
     * @param list<array{line_uuid: string, resolved: ResolvedLine}> $resolved
     */
    private function claimStock(ApplicationContext $context, string $tenant, array $resolved): void
    {
        $conflicts = [];

        foreach ($resolved as $entry) {
            $line = $entry['resolved'];
            if (!$this->stock->isTracked($context, $tenant, $line->variantUuid)) {
                continue;
            }
            if ($this->stock->decrement($context, $tenant, $line->variantUuid, $line->quantity)) {
                continue;
            }

            $conflicts[] = [
                'line_uuid' => $entry['line_uuid'],
                'variant_uuid' => $line->variantUuid,
                'sku' => $line->sku,
                'product_name' => $line->productName,
                'quantity' => $line->quantity,
                'reason' => self::LINE_STOCK,
                'available' => $this->stock->quantity($context, $tenant, $line->variantUuid),
            ];
        }

        if ($conflicts !== []) {
            throw DraftConflictException::lineConflicts($conflicts);
        }
    }

    // -----------------------------------------------------------------
    // persistence
    // -----------------------------------------------------------------

    /**
     * The authoritative line snapshot. Written over the SAME row (design spec
     * §2.5.7: "same stable line UUIDs -- no duplicate insertion"), which is
     * exactly why {@see DraftOrderService::updateLine()} rewrites rows in place
     * rather than replacing them.
     *
     * `seller_uuid`, `discount_amount`, `tax_amount`, and every `commission_*`
     * column stay untouched: they are marketplace/partition attribution, and a
     * finalized admin order can never be partitioned this cycle -- a marketplace
     * line is refused above.
     *
     * @return array<string,mixed>
     */
    private function lineChanges(ResolvedLine $resolved): array
    {
        return [
            'product_name' => $resolved->productName,
            'sku' => $resolved->sku,
            'option_values' => json_encode($resolved->optionValues, JSON_THROW_ON_ERROR),
            'unit_price' => $resolved->unitPrice,
            'quantity' => $resolved->quantity,
            'line_total' => $resolved->unitPrice * $resolved->quantity,
            'addons' => $resolved->addons === [] ? null : json_encode($resolved->addons, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * The finalization stamp: the fields that only exist once a draft becomes an
     * order, plus the recomputed money. `WHERE ... AND status = 'draft'` keeps it
     * a guarded write even though the row lock already serializes us -- a
     * defence-in-depth predicate identical to the one
     * {@see OrderRepository::finalizeDraftTransition()} then applies.
     *
     * Deliberately NOT written here: `origin` (already `admin` from create),
     * `fulfillment_mode` (the operator's own choice), `guest_token_hash` (an
     * admin order mints no credential -- design Ruling 4), `draft_revision` (a
     * finalize is not an edit), and the customer identity block (drafted, not
     * derived).
     *
     * @param array<string,mixed>|null $discount
     */
    private function stampFinalizedFields(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $number,
        Totals $totals,
        ?ShippingQuote $quote,
        ?array $discount
    ): void {
        $isDraft = OrderScope::isDraftSql();
        $affected = db($context)->table('commerce_orders')->executeModification(
            <<<SQL
UPDATE commerce_orders
SET order_number = ?, placed_at = ?, subtotal = ?, discount_total = ?, shipping_total = ?,
    tax_total = ?, grand_total = ?, shipping_method = ?, discount_code = ?, updated_at = ?
WHERE tenant_uuid = ? AND uuid = ? AND {$isDraft}
SQL,
            [
                $number,
                gmdate('Y-m-d H:i:s'),
                $totals->subtotal,
                $totals->discountTotal,
                $totals->shippingTotal,
                $totals->taxTotal,
                $totals->grandTotal,
                $quote?->id,
                $discount['code'] ?? null,
                db($context)->getDriver()->formatDateTime(),
                $tenant,
                $uuid,
            ]
        );

        if ($affected !== 1) {
            throw new \DomainException('Draft is no longer finalizable; re-read the draft and retry.');
        }
    }

    // -----------------------------------------------------------------
    // input + state assertions
    // -----------------------------------------------------------------

    private function assertIdempotencyKey(mixed $raw): string
    {
        if (!is_string($raw) || preg_match(self::IDEMPOTENCY_KEY_PATTERN, $raw) !== 1) {
            throw ValidationException::forField(
                'idempotency_key',
                'X-Idempotency-Key must be 16-191 characters of A-Z, a-z, 0-9, dot, underscore, colon, or hyphen.'
            );
        }

        return $raw;
    }

    /**
     * `expected_revision` is REQUIRED here, unlike every draft MUTATION (where it
     * is an optional staleness assertion). Finalize is the irreversible step: the
     * operator must state which version of the draft they are turning into an
     * order, so a concurrent edit they never saw cannot be sold.
     */
    private function assertExpectedRevision(mixed $raw): int
    {
        if (is_int($raw) && $raw >= 0) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }

        throw ValidationException::forField(
            'expected_revision',
            'expected_revision is required and must be a non-negative integer.'
        );
    }

    /**
     * Design Ruling 5's POSITIVE half, enforced HERE and nowhere else (see
     * {@see DraftOrderService::update()}'s docblock for why the mutation surface
     * deliberately leaves it to this method): a `delivery` order must actually
     * have somewhere to deliver to, and a server-quoted method to deliver by,
     * before it becomes an order.
     *
     * `country` is the required address field because it is the one the engine's
     * own delivery machinery genuinely consults -- shipping-zone matching
     * ({@see \Glueful\Extensions\Commerce\Shipping\ConfigShippingRateProvider},
     * {@see \Glueful\Extensions\Commerce\Shipping\ZoneMatcher}) and tax-rate
     * resolution ({@see \Glueful\Extensions\Commerce\Tax\DbTaxCalculator}) both
     * key on it, and an absent country silently yields "no zone, no tax", i.e. a
     * cheaper order than the customer owes. The rest of an address is free-form
     * on the storefront path too (checkout accepts `addresses` as a loose array),
     * so inventing extra required keys here would refuse orders storefront
     * checkout accepts -- a divergence the walk-in flow has no business creating.
     *
     * `in_store` asserts nothing: it carries no address and no method by
     * construction, and the mutation surface already refuses to store either.
     *
     * @param array<string,mixed> $order
     */
    private function assertFulfillable(array $order): void
    {
        if ((string) $order['fulfillment_mode'] !== DraftOrderService::MODE_DELIVERY) {
            return;
        }

        $shipping = $this->shippingAddress($order);
        if (trim((string) ($shipping['country'] ?? '')) === '') {
            throw ValidationException::forField(
                'addresses',
                'A delivery order needs a shipping address with a country before it can be finalized.'
            );
        }

        if (!is_string($order['shipping_method'] ?? null) || trim((string) $order['shipping_method']) === '') {
            throw ValidationException::forField(
                'shipping_method',
                'A delivery order needs a quoted shipping method before it can be finalized.'
            );
        }
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function shippingAddress(array $order): array
    {
        $addresses = is_array($order['addresses'] ?? null) ? $order['addresses'] : [];

        return is_array($addresses['shipping'] ?? null) ? $addresses['shipping'] : [];
    }

    /**
     * Reconstruct RAW selections from a persisted canonical snapshot so the
     * re-resolution runs them back through the CURRENT-definition path -- the
     * same reconstruction {@see DraftOrderService::selectionsFromSnapshot()}
     * performs, and the reason add-on drift is visible at all.
     *
     * @param list<array<string,mixed>>|array<string,mixed> $snapshot
     * @return list<array<string,mixed>>
     */
    private function selectionsFromSnapshot(array $snapshot): array
    {
        $selections = [];
        foreach ($snapshot as $entry) {
            if (!is_array($entry) || !isset($entry['addon_uuid'])) {
                continue;
            }
            $selection = ['addon_uuid' => (string) $entry['addon_uuid']];
            if (isset($entry['choice_key'])) {
                $selection['choice_key'] = (string) $entry['choice_key'];
            }
            if (array_key_exists('value', $entry)) {
                $selection['value'] = $entry['value'];
            }
            $selections[] = $selection;
        }

        return $selections;
    }

    private function dispatch(ApplicationContext $context, object $event): void
    {
        $container = container($context);
        if ($container->has(EventService::class)) {
            $container->get(EventService::class)->dispatch($event);
        }
    }
}
