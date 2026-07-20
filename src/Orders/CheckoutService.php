<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Contracts\LineTaxCalculator;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Events\OrderPlaced;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\CheckoutConflictException;
use Glueful\Extensions\Commerce\Marketplace\CommissionCalculator;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyResolver;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\OwnershipDriftException;
use Glueful\Extensions\Commerce\Marketplace\SellerAllocationCalculator;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookOutboxPublisher;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxBreakdown;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Pricing\Totals;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tax\DiscountAllocation;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Events\EventService;
use Glueful\Helpers\Utils;
use Glueful\Validation\ValidationException;

/**
 * **Marketplace shared checkout (design spec §2.7, MV2).** `$marketplaceMode`/
 * `$sellers`/`$marketplaceProducts`/`$sellerOrders` are APPENDED OPTIONAL
 * collaborators -- mirroring {@see \Glueful\Extensions\Commerce\Catalog\CatalogService}'s
 * identical convention -- so every pre-MV2 direct-construction call site
 * (tests included) stays source-compatible. Whenever ANY of the four is
 * null, or `$marketplaceMode->installEnabled()` is false, `placeOrder()`
 * behaves EXACTLY as pre-MV2 and executes ZERO seller-table queries; there
 * is no partial/degraded marketplace mode. `$afterOwnershipSnapshotHook` is
 * a test-only injectable seam (same convention as
 * {@see \Glueful\Extensions\Commerce\Marketplace\SellerAttributionService}'s
 * `$afterSnapshotHook`) invoked immediately after the pre-claim ownership
 * snapshot, before the seller/product claim set is locked.
 */
final class CheckoutService
{
    /** @var callable(ApplicationContext,string,list<string>):void */
    private $afterOwnershipSnapshotHook;

    /**
     * @param (callable(ApplicationContext,string,list<string>):void)|null $afterOwnershipSnapshotHook
     */
    public function __construct(
        private CartService $carts,
        private DiscountRepository $discounts,
        private DiscountService $discountService,
        private StockRepository $stock,
        private PricingEngine $pricing,
        private ShippingRateProvider $shipping,
        private TaxCalculator $tax,
        private OrderNumberGenerator $numbers,
        private OrderRepository $orders,
        private DownloadRepository $downloads,
        private PaymentCollector $collector,
        private CurrentTenantResolver $tenants,
        private ?MarketplaceMode $marketplaceMode = null,
        private ?SellerRepository $sellers = null,
        private ?ProductRepository $marketplaceProducts = null,
        private ?SellerOrderRepository $sellerOrders = null,
        ?callable $afterOwnershipSnapshotHook = null,
        private ?SellerWebhookOutboxPublisher $webhooks = null,
    ) {
        $this->afterOwnershipSnapshotHook = $afterOwnershipSnapshotHook ?? static function (
            ApplicationContext $context,
            string $tenant,
            array $productUuids
        ): void {
        };
    }

    /**
     * @param array<string,mixed> $cart
     * @param array<string,mixed> $shippingAddress
     * @return array{
     *   totals: \Glueful\Extensions\Commerce\Pricing\Totals,
     *   shipping_options: list<ShippingQuote>,
     *   lines: list<array<string,mixed>>
     * }
     */
    public function quote(
        ApplicationContext $context,
        array $cart,
        array $shippingAddress,
        ?string $shippingMethodId,
    ): array {
        $tenant = $this->tenants->tenantUuid($context);
        $lines = $this->carts->pricedLines($context, $cart);
        $discount = $this->discountForCart($context, $tenant, $cart);
        $shippingOptions = $this->shipping->quote($context, $lines, $shippingAddress);
        $shippingQuote = $this->selectShipping($shippingOptions, $shippingMethodId);
        $preTax = $this->pricing->price($lines, $discount, $shippingQuote, null);
        $tax = $this->resolveTax($context, $lines, $discount, $preTax, $shippingAddress);

        return [
            'totals' => $this->pricing->price($lines, $discount, $shippingQuote, $tax),
            'shipping_options' => $shippingOptions,
            'lines' => $lines,
        ];
    }

    /**
     * Marketplace transfer-safe claim protocol (design spec §2.7): a
     * partitioned checkout gets ONE automatic retry of the ENTIRE placement
     * flow (cart claim, stock, order insert, seller-order writes -- every
     * write the transaction made) when the first attempt observes ownership
     * drift, from a completely fresh snapshot. A second drift, or a
     * participating seller that is not `active` (never retried -- retrying
     * cannot fix it), surfaces as {@see CheckoutConflictException} (409
     * `checkout_conflict`). The non-partitioned path (`installEnabled()`
     * false, or any marketplace collaborator missing) makes exactly ONE
     * attempt, byte-identical to pre-MV2, with zero seller-table reads.
     *
     * @param array{email: string, user_uuid?: string|null} $buyer
     * @param array<string,mixed> $addresses
     * @return array{order: array<string,mixed>, guest_token: string, payment: array<string,mixed>}
     */
    public function placeOrder(
        ApplicationContext $context,
        string $rawCartToken,
        array $buyer,
        array $addresses,
        ?string $shippingMethodId,
    ): array {
        $cart = $this->carts->byToken($context, $rawCartToken);
        if ($cart === null) {
            throw ValidationException::forField('cart', 'Cart not found or no longer active.');
        }

        $tenant = $this->tenants->tenantUuid($context);
        $storeCurrency = (string) config($context, 'commerce.currency', 'USD');
        $guestToken = TokenHasher::generate();

        // MASTER-OFF FAST PATH: installEnabled() is config-only (zero DB
        // queries) -- while it's false (or any marketplace collaborator is
        // missing), $partitioned is always false and the retry loop below
        // runs exactly once, touching zero seller tables.
        $marketplaceInstalled = $this->marketplaceMode !== null
            && $this->sellers !== null
            && $this->marketplaceProducts !== null
            && $this->sellerOrders !== null
            && $this->marketplaceMode->installEnabled($context);
        $maxAttempts = $marketplaceInstalled ? 2 : 1;

        $order = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $order = db($context)->transaction(function () use (
                    $context,
                    $tenant,
                    $cart,
                    $buyer,
                    $guestToken,
                    $addresses,
                    $storeCurrency,
                    $shippingMethodId,
                    $marketplaceInstalled
                ): array {
                    return $this->placeOrderAttempt(
                        $context,
                        $tenant,
                        $cart,
                        $buyer,
                        $guestToken,
                        $addresses,
                        $storeCurrency,
                        $shippingMethodId,
                        $marketplaceInstalled
                    );
                });
                break;
            } catch (OwnershipDriftException) {
                if ($attempt >= $maxAttempts) {
                    throw new CheckoutConflictException(
                        'Checkout conflict: seller ownership changed while placing this order. Please retry.'
                    );
                }
                // Fall through and retry the ENTIRE placement flow once more,
                // from a fresh snapshot -- the failed attempt's transaction
                // (cart claim included) has already been rolled back.
            }
        }

        /** @var array<string,mixed> $order */
        $this->dispatch($context, new OrderPlaced($order));
        $payment = $this->initiatePayment($context, $order);

        return ['order' => $order, 'guest_token' => $guestToken['raw'], 'payment' => $payment];
    }

    /**
     * ONE complete placement attempt, run inside `placeOrder()`'s
     * transaction. Identical to the pre-MV2 flow except for the marketplace
     * partition block (only reached when `$marketplaceInstalled`): throws
     * {@see OwnershipDriftException} or {@see CheckoutConflictException}
     * from inside the transaction on a claim-protocol failure, which rolls
     * back every write this attempt made.
     *
     * @param array{email: string, user_uuid?: string|null} $buyer
     * @param array{hash: string, raw: string} $guestToken
     * @param array<string,mixed> $cart
     * @param array<string,mixed> $addresses
     * @return array<string,mixed>
     */
    private function placeOrderAttempt(
        ApplicationContext $context,
        string $tenant,
        array $cart,
        array $buyer,
        array $guestToken,
        array $addresses,
        string $storeCurrency,
        ?string $shippingMethodId,
        bool $marketplaceInstalled
    ): array {
        // The lifecycle claim is the checkout idempotency point. Every cart mutation
        // claims the same row before writing, so the lines below are a stable snapshot.
        $cart = $this->carts->claimForCheckout($context, $cart);

        $lines = $this->carts->pricedLines($context, $cart);
        if ($lines === []) {
            throw ValidationException::forField('cart', 'Cart is empty.');
        }
        $lines = $this->withDownloadSnapshots($context, $tenant, $lines);

        foreach ($lines as $index => $line) {
            if (($line['currency'] ?? $storeCurrency) !== $storeCurrency) {
                throw ValidationException::forField(
                    "lines.{$index}",
                    'Variant currency no longer matches the store currency.'
                );
            }
        }

        $discount = $this->discountForCart($context, $tenant, $cart);
        if ($discount !== null) {
            $subtotal = $this->pricing->discountableBase($lines, null);
            $this->discountService->validateForCart($context, $discount, $subtotal, $lines);
        }

        $shippingAddress = is_array($addresses['shipping'] ?? null) ? $addresses['shipping'] : [];
        $shippingQuote = $this->resolveShipping($context, $lines, $shippingAddress, $shippingMethodId);
        $preTax = $this->pricing->price($lines, $discount, $shippingQuote, null);
        $taxQuote = $this->resolveTax($context, $lines, $discount, $preTax, $shippingAddress);
        $totals = $this->pricing->price($lines, $discount, $shippingQuote, $taxQuote);
        $buyerIdentity = DiscountService::buyerIdentity(
            $buyer['user_uuid'] ?? null,
            (string) $buyer['email']
        );

        // Marketplace partition (design spec §2.7): gated on activeFor(), which
        // is only ever queried when $marketplaceInstalled -- the master-off
        // path above never reaches this branch at all.
        $partitioned = $marketplaceInstalled && $this->marketplaceMode->activeFor($context, $tenant);
        $sellerAllocations = [];
        $sellerRows = [];
        if ($partitioned) {
            [$lines, $sellerAllocations, $sellerRows] = $this->partitionCheckout(
                $context,
                $tenant,
                $lines,
                $discount,
                $totals,
                $taxQuote->breakdown
            );
        }

        foreach ($lines as $line) {
            if (!$this->stock->isTracked($context, $tenant, (string) $line['variant_uuid'])) {
                continue;
            }
            $decremented = $this->stock->decrement(
                $context,
                $tenant,
                (string) $line['variant_uuid'],
                (int) $line['quantity']
            );
            if (!$decremented) {
                throw new InsufficientStockException((string) $line['variant_uuid'], (string) $line['sku']);
            }
        }

        $orderUuid = Utils::generateNanoID();
        $number = $this->numbers->next($context, $tenant);
        foreach ($lines as $line) {
            if ($this->stock->isTracked($context, $tenant, (string) $line['variant_uuid'])) {
                $this->stock->recordMovement(
                    $context,
                    $tenant,
                    (string) $line['variant_uuid'],
                    -(int) $line['quantity'],
                    'order',
                    $orderUuid
                );
            }
        }

        if ($discount !== null) {
            $this->discountService->consume($context, $discount, $orderUuid, $buyerIdentity);
        }

        $this->orders->insert($context, [
            'uuid' => $orderUuid,
            'tenant_uuid' => $tenant,
            'order_number' => $number,
            'status' => 'pending_payment',
            'email' => (string) $buyer['email'],
            'user_uuid' => $buyer['user_uuid'] ?? null,
            'guest_token_hash' => $guestToken['hash'],
            'currency' => $storeCurrency,
            'subtotal' => $totals->subtotal,
            'discount_total' => $totals->discountTotal,
            'shipping_total' => $totals->shippingTotal,
            'tax_total' => $totals->taxTotal,
            'grand_total' => $totals->grandTotal,
            'discount_code' => $discount['code'] ?? null,
            'shipping_method' => $shippingQuote?->id,
            'addresses' => $addresses,
            'placed_at' => gmdate('Y-m-d H:i:s'),
            'marketplace_partitioned' => $partitioned,
        ], $lines);
        $this->orders->recordEvent($context, $orderUuid, 'placed', ['number' => $number]);

        if ($partitioned) {
            $this->writeSellerOrders(
                $context,
                $tenant,
                $orderUuid,
                $number,
                $storeCurrency,
                $sellerAllocations,
                $sellerRows
            );
            $this->captureOrderPlaced($context, $tenant, $orderUuid, $number, $storeCurrency, $lines, $sellerRows);
        }

        $order = $this->orders->findByUuid($context, $tenant, $orderUuid);
        if ($order === null) {
            throw new \RuntimeException('Created order could not be reloaded.');
        }

        return $order;
    }

    /**
     * The full marketplace claim protocol + per-seller allocation for one
     * partitioned checkout attempt (design spec §2.7). Runs inside the
     * SAME transaction as the rest of `placeOrderAttempt()`: a claim
     * failure here rolls back everything the attempt has done so far.
     *
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed>|null $discount
     * @return array{
     *     0: list<array<string,mixed>>,
     *     1: array<string,array{
     *         subtotal:int,
     *         allocated_discount:int,
     *         allocated_shipping_discount:int,
     *         allocated_shipping:int,
     *         allocated_tax:int,
     *         attributed_total:int,
     *         tax_attribution_method:string,
     *         commission_amount:int
     *     }>,
     *     2: array<string,array<string,mixed>>
     * } [attributed lines (each carrying its resolved commission_* snapshot,
     *   design spec §2.4), seller allocations (ascending seller_uuid, each
     *   carrying its lines' summed commission_amount), seller rows keyed by
     *   seller_uuid]
     */
    private function partitionCheckout(
        ApplicationContext $context,
        string $tenant,
        array $lines,
        ?array $discount,
        Totals $totals,
        ?TaxBreakdown $breakdown
    ): array {
        ['map' => $productSellerMap, 'sellers' => $sellerRows] =
            $this->claimMarketplaceOwnership($context, $tenant, $lines);

        $discountKind = $this->discountKind($discount);
        $lines = $this->attachMarketplaceLineAttribution(
            $lines,
            $productSellerMap,
            $discount,
            $discountKind,
            $totals->discountTotal,
            $breakdown
        );

        $lines = $this->attachCommissionSnapshot($context, $tenant, $lines, $sellerRows);

        $calculatorLines = array_map(
            static fn (array $line): array => [
                'line_uuid' => (string) $line['line_uuid'],
                'seller_uuid' => (string) $line['seller_uuid'],
                'line_total' => (int) $line['unit_price'] * (int) $line['quantity'],
                'discount_amount' => (int) $line['discount_amount'],
                'tax_amount' => (int) $line['tax_amount'],
            ],
            $lines
        );

        // SellerAllocationException (a §2.5 reconciliation breach) is
        // deliberately left to propagate -- it aborts the whole transaction
        // and is never caught/downgraded to a controlled conflict.
        $allocations = SellerAllocationCalculator::allocate(
            $calculatorLines,
            [
                'subtotal' => $totals->subtotal,
                'discount_total' => $totals->discountTotal,
                'shipping_total' => $totals->shippingTotal,
                'tax_total' => $totals->taxTotal,
                'grand_total' => $totals->grandTotal,
            ],
            $discountKind,
            $breakdown
        );

        $allocations = $this->attachCommissionTotals($lines, $allocations);

        return [$lines, $allocations, $sellerRows];
    }

    /**
     * Commission snapshot (design spec §2.4, MV3): resolves and snapshots the
     * per-line commission policy product -> seller -> workspace -> config,
     * immutably, onto every partitioned line, OVERWRITING the raw product-level
     * `commission_kind`/`commission_bps`/`commission_fixed` `CartService::pricedLines()`
     * attached to each line with the RESOLVED policy's values -- plus the new
     * `commission_source`/`commission_basis`/`commission_amount` keys. The
     * workspace-settings row ({@see MarketplaceMode::settingsRowFor()}) and the
     * config tail are each read/resolved ONCE for the whole checkout, outside
     * the loop, never once per line. `commission_basis` is computed from THIS
     * line's `line_total` and its already-attached `discount_amount` (design
     * spec §2.1) -- shipping/tax stay outside the basis.
     *
     * @param list<array<string,mixed>> $lines
     * @param array<string,array<string,mixed>> $sellerRows keyed by seller_uuid
     * @return list<array<string,mixed>>
     */
    private function attachCommissionSnapshot(
        ApplicationContext $context,
        string $tenant,
        array $lines,
        array $sellerRows
    ): array {
        $settingsRow = $this->marketplaceMode->settingsRowFor($context, $tenant);
        $configPolicy = (array) config($context, 'commerce.marketplace.commission', []);

        $workspaceLevel = $this->commissionLevel($settingsRow ?? []);
        $configLevel = [
            'kind' => isset($configPolicy['kind']) ? (string) $configPolicy['kind'] : null,
            'bps' => isset($configPolicy['bps']) ? (int) $configPolicy['bps'] : null,
            'fixed' => isset($configPolicy['fixed']) ? (int) $configPolicy['fixed'] : null,
        ];

        foreach ($lines as $index => $line) {
            $sellerUuid = (string) $line['seller_uuid'];
            $productLevel = $this->commissionLevel($line);
            $sellerLevel = $this->commissionLevel($sellerRows[$sellerUuid] ?? []);

            $policy = CommissionPolicyResolver::resolve([$productLevel, $sellerLevel, $workspaceLevel, $configLevel]);
            $lineTotal = (int) $line['unit_price'] * (int) $line['quantity'];
            $commission = CommissionCalculator::lineCommission(
                $lineTotal,
                (int) ($line['discount_amount'] ?? 0),
                $policy
            );

            $lines[$index]['commission_source'] = $policy['source'];
            $lines[$index]['commission_kind'] = $policy['kind'];
            $lines[$index]['commission_bps'] = $policy['bps'];
            $lines[$index]['commission_fixed'] = $policy['fixed'];
            $lines[$index]['commission_basis'] = $commission['commission_basis'];
            $lines[$index]['commission_amount'] = $commission['commission_amount'];
        }

        return $lines;
    }

    /** @param array<string,mixed> $row @return array{kind:?string,bps:?int,fixed:?int} */
    private function commissionLevel(array $row): array
    {
        return [
            'kind' => isset($row['commission_kind']) ? (string) $row['commission_kind'] : null,
            'bps' => isset($row['commission_bps']) ? (int) $row['commission_bps'] : null,
            'fixed' => isset($row['commission_fixed']) ? (int) $row['commission_fixed'] : null,
        ];
    }

    /**
     * Sums each seller's already-snapshotted lines' `commission_amount` onto
     * its allocation row (design spec §2.1: seller-order `commission_amount`
     * is the EXACT sum of its lines'). Reuses {@see CommissionCalculator::perSeller()}
     * rather than `LargestRemainder` -- this is a straight per-line sum, not an
     * allocation of an order-level total.
     *
     * @param list<array<string,mixed>> $lines
     * @param array<string,array<string,mixed>> $allocations keyed by seller_uuid
     * @return array<string,array<string,mixed>>
     */
    private function attachCommissionTotals(array $lines, array $allocations): array
    {
        $bySeller = CommissionCalculator::perSeller(array_map(
            static fn (array $line): array => [
                'seller_uuid' => (string) $line['seller_uuid'],
                'commission_amount' => (int) $line['commission_amount'],
            ],
            $lines
        ));

        foreach ($allocations as $sellerUuid => $alloc) {
            $allocations[$sellerUuid]['commission_amount'] = $bySeller[$sellerUuid] ?? 0;
        }

        return $allocations;
    }

    /**
     * Design spec §2.7 claim protocol, verbatim: (1) snapshot every
     * participating product's `seller_uuid` in ONE query; (2) claim the
     * distinct participating sellers in ascending-UUID order; (3) claim the
     * participating products in ascending-UUID order; (4) re-read; (5) a
     * changed `seller_uuid` is drift ({@see OwnershipDriftException}, caught
     * ONLY by `placeOrder()`'s retry loop); a participating seller that is
     * not `active` is an immediate {@see CheckoutConflictException} (never
     * retried). Every product reaching this call already resolved live via
     * `CartService::pricedLines()`, so a null seller on the re-read is a
     * guarded integrity error (design spec §2.7 NB: every product in an
     * active workspace has a non-null seller) -- never silently proceeded
     * past.
     *
     * @param list<array<string,mixed>> $lines
     * @return array{map: array<string,string>, sellers: array<string,array<string,mixed>>}
     */
    private function claimMarketplaceOwnership(ApplicationContext $context, string $tenant, array $lines): array
    {
        $productUuids = array_values(array_unique(array_map(
            static fn (array $line): string => (string) $line['product_uuid'],
            $lines
        )));
        sort($productUuids, SORT_STRING);

        $snapshot = $this->marketplaceProducts->sellerSnapshot($context, $tenant, $productUuids);

        ($this->afterOwnershipSnapshotHook)($context, $tenant, $productUuids);

        $sellerUuids = array_values(array_unique(array_filter(
            $snapshot,
            static fn (?string $sellerUuid): bool => $sellerUuid !== null
        )));
        sort($sellerUuids, SORT_STRING);

        foreach ($sellerUuids as $sellerUuid) {
            if (!$this->sellers->claimRevision($context, $tenant, $sellerUuid)) {
                throw new \RuntimeException(
                    "Marketplace checkout integrity error: seller '{$sellerUuid}' could not be claimed."
                );
            }
        }

        foreach ($productUuids as $productUuid) {
            if (!$this->marketplaceProducts->claimCatalogRevision($context, $tenant, $productUuid)) {
                throw new \RuntimeException(
                    "Marketplace checkout integrity error: product '{$productUuid}' could not be claimed."
                );
            }
        }

        $reread = $this->marketplaceProducts->sellerSnapshot($context, $tenant, $productUuids);

        foreach ($productUuids as $productUuid) {
            $before = $snapshot[$productUuid] ?? null;
            $after = $reread[$productUuid] ?? null;

            if ($before !== $after) {
                throw new OwnershipDriftException(
                    "Marketplace checkout ownership drift detected for product '{$productUuid}'."
                );
            }

            if ($after === null) {
                throw new \RuntimeException(
                    "Marketplace checkout integrity error: product '{$productUuid}' has no seller attribution."
                );
            }
        }

        $sellerRows = [];
        foreach ($sellerUuids as $sellerUuid) {
            $seller = $this->sellers->findByUuid($context, $tenant, $sellerUuid);
            if ($seller === null) {
                throw new \RuntimeException(
                    "Marketplace checkout integrity error: seller '{$sellerUuid}' claimed but not found."
                );
            }
            if ((string) $seller['status'] !== 'active') {
                throw new CheckoutConflictException(
                    "Checkout conflict: seller '{$seller['name']}' is not accepting orders."
                );
            }
            $sellerRows[$sellerUuid] = $seller;
        }

        return ['map' => $reread, 'sellers' => $sellerRows];
    }

    /**
     * Discount kind (design spec §2.2): `percentage`/`fixed` allocate
     * per-line (`value`); `free_shipping` allocates per-seller instead;
     * no discount is `none`.
     *
     * @param array<string,mixed>|null $discount
     */
    private function discountKind(?array $discount): string
    {
        if ($discount === null) {
            return 'none';
        }

        return (string) ($discount['type'] ?? '') === 'free_shipping' ? 'free_shipping' : 'value';
    }

    /**
     * Attaches each line's immutable `seller_uuid` snapshot plus its
     * allocated `discount_amount`/`tax_amount` (design spec §2.2/§2.4,
     * carried forward from the Task 4 review): a value discount allocates
     * `discount_total` per line via {@see DiscountAllocation::allocate()};
     * free-shipping/none leave every line's `discount_amount` at 0. Tax is
     * populated from the breakdown's per-line map when one was produced
     * (`line_detailed`), else 0 for every line (`aggregate_allocated`) --
     * BEFORE the calculator is ever called, which trusts this as the
     * authoritative per-line merchandise tax.
     *
     * @param list<array<string,mixed>> $lines
     * @param array<string,string> $productSellerMap
     * @param array<string,mixed>|null $discount
     * @return list<array<string,mixed>>
     */
    private function attachMarketplaceLineAttribution(
        array $lines,
        array $productSellerMap,
        ?array $discount,
        string $discountKind,
        int $discountTotal,
        ?TaxBreakdown $breakdown
    ): array {
        $discountByLine = $discountKind === 'value'
            ? DiscountAllocation::allocate($lines, $discount, $discountTotal)
            : [];
        $taxByLine = $breakdown?->taxByLine() ?? [];

        foreach ($lines as $index => $line) {
            $productUuid = (string) $line['product_uuid'];
            $lineUuid = (string) $line['line_uuid'];
            $sellerUuid = $productSellerMap[$productUuid] ?? null;

            if ($sellerUuid === null) {
                throw new \RuntimeException(
                    "Marketplace checkout integrity error: line '{$lineUuid}' has no seller attribution."
                );
            }

            $lines[$index]['seller_uuid'] = $sellerUuid;
            $lines[$index]['discount_amount'] = $discountByLine[$lineUuid] ?? 0;
            $lines[$index]['tax_amount'] = $taxByLine[$lineUuid] ?? 0;
        }

        return $lines;
    }

    /**
     * Builds each `commerce_seller_orders` row's content fields (structural
     * fields -- uuid/partition_number/seller_reference/timestamps/lifecycle
     * defaults -- are {@see SellerOrderRepository::insertForOrder()}'s own
     * responsibility) and writes them in one call.
     *
     * @param array<string,array{
     *     subtotal:int,
     *     allocated_discount:int,
     *     allocated_shipping_discount:int,
     *     allocated_shipping:int,
     *     allocated_tax:int,
     *     attributed_total:int,
     *     tax_attribution_method:string,
     *     commission_amount:int
     * }> $sellerAllocations keyed by seller_uuid
     * @param array<string,array<string,mixed>> $sellerRows keyed by seller_uuid
     */
    private function writeSellerOrders(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        string $orderNumber,
        string $currency,
        array $sellerAllocations,
        array $sellerRows
    ): void {
        $sellerOrderRows = [];
        foreach ($sellerAllocations as $sellerUuid => $alloc) {
            $sellerOrderRows[] = [
                'order_uuid' => $orderUuid,
                'order_number' => $orderNumber,
                'seller_uuid' => $sellerUuid,
                'seller_name_snapshot' => (string) $sellerRows[$sellerUuid]['name'],
                'currency' => $currency,
                'subtotal' => $alloc['subtotal'],
                'allocated_discount' => $alloc['allocated_discount'],
                'allocated_shipping_discount' => $alloc['allocated_shipping_discount'],
                'allocated_shipping' => $alloc['allocated_shipping'],
                'allocated_tax' => $alloc['allocated_tax'],
                'attributed_total' => $alloc['attributed_total'],
                'tax_attribution_method' => $alloc['tax_attribution_method'],
                'commission_amount' => $alloc['commission_amount'],
            ];
        }

        $this->sellerOrders->insertForOrder($context, $tenant, $sellerOrderRows);
    }

    /**
     * `order.placed` outbox capture (MV5c-2 Task 4, design spec §2.4): called
     * INSIDE this same transaction, immediately after {@see self::writeSellerOrders()}
     * commits its rows so this reads the just-persisted `commerce_seller_orders`
     * children back (their own `uuid`/`seller_reference`, never re-derived).
     * `$sellerRows` is the EXACT claim set {@see self::claimMarketplaceOwnership()}
     * already claimed (sorted, ascending seller_uuid) earlier in THIS transaction --
     * passed through as `claimed_sellers` so the publisher reuses those claims
     * rather than re-claiming (design spec §2.4's shared-claim-helper rule). Checkout
     * never claims a {@see \Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock}
     * at all, so there is no lock-order constraint on where in this method this call
     * lands, unlike the payment/refund/payout capture sites.
     *
     * @param list<array<string,mixed>> $lines post-partition lines, each carrying its
     *     own `seller_uuid` (design spec §2.7 attribution)
     * @param array<string,array<string,mixed>> $sellerRows keyed by seller_uuid
     */
    private function captureOrderPlaced(
        ApplicationContext $context,
        string $tenant,
        string $orderUuid,
        string $orderNumber,
        string $currency,
        array $lines,
        array $sellerRows
    ): void {
        if ($this->webhooks === null || $this->sellerOrders === null) {
            return;
        }

        $sellerOrderRows = $this->sellerOrders->forOrder($context, $tenant, $orderUuid);
        if ($sellerOrderRows === []) {
            return;
        }

        $linesBySeller = [];
        foreach ($lines as $line) {
            $sellerUuid = (string) ($line['seller_uuid'] ?? '');
            if ($sellerUuid === '') {
                continue;
            }
            $linesBySeller[$sellerUuid][] = [
                'sku' => (string) ($line['sku'] ?? ''),
                'product_name' => (string) ($line['product_name'] ?? ''),
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unit_price' => (int) ($line['unit_price'] ?? 0),
            ];
        }

        $data = [];
        foreach ($sellerOrderRows as $row) {
            $sellerUuid = (string) $row['seller_uuid'];
            $data[$sellerUuid] = [
                'order_uuid' => $orderUuid,
                'order_number' => $orderNumber,
                'currency' => $currency,
                'occurred_at' => (string) ($row['created_at'] ?? ''),
                'seller_order_uuid' => (string) $row['uuid'],
                'seller_reference' => (string) $row['seller_reference'],
                'subtotal' => (int) $row['subtotal'],
                'allocated_discount' => (int) $row['allocated_discount'],
                'allocated_shipping' => (int) $row['allocated_shipping'],
                'allocated_tax' => (int) $row['allocated_tax'],
                'attributed_total' => (int) $row['attributed_total'],
                'commission_amount' => (int) ($row['commission_amount'] ?? 0),
                'lines' => $linesBySeller[$sellerUuid] ?? [],
            ];
        }

        $this->webhooks->capture($context, $tenant, 'order.placed', [
            'data' => $data,
            'claimed_sellers' => array_keys($sellerRows),
            'source_ref' => $orderUuid,
        ]);
    }

    /** @param array<string,mixed> $order @return array<string,mixed> */
    public function retryPayment(ApplicationContext $context, array $order): array
    {
        if (($order['status'] ?? '') !== 'pending_payment') {
            throw ValidationException::forField('order', 'Payment can only be retried for pending orders.');
        }

        return $this->initiatePayment($context, $order);
    }

    /** @param array<string,mixed> $order @return array<string,mixed> */
    private function initiatePayment(ApplicationContext $context, array $order): array
    {
        $payable = new PayableReference(
            'commerce_order',
            (string) $order['uuid'],
            (int) $order['grand_total'],
            (string) $order['currency'],
            'Order ' . (string) $order['order_number']
        );

        try {
            $initiation = $this->collector->initiate($context, $payable);
            $this->orders->recordEvent($context, (string) $order['uuid'], 'payment_initiated', [
                'provider' => $initiation->provider,
            ]);

            return [
                'status' => $initiation->status,
                'provider' => $initiation->provider,
                'payload' => $initiation->payload,
            ];
        } catch (\Throwable $e) {
            $this->orders->recordEvent($context, (string) $order['uuid'], 'payment_init_failed', [
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'init_failed', 'retryable' => true];
        }
    }

    /**
     * Purchase-time entitlement snapshot (design spec §2): for each line whose
     * product type is `digital`, snapshots the variant's ACTIVE download
     * definitions (ordered by position) into a `downloads` key --
     * `[{download_uuid, blob_uuid, name, download_limit, expiry_days}]`, an
     * empty array when the digital variant currently has none. Every
     * non-digital line gets `downloads => null`. Read fresh at order-line
     * building time so later definition edits/deletes never alter an
     * already-placed order's snapshot -- {@see OrderRepository} persists this
     * verbatim, exactly like the add-on snapshot.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function withDownloadSnapshots(ApplicationContext $context, string $tenant, array $lines): array
    {
        foreach ($lines as $index => $line) {
            if ((string) ($line['type'] ?? 'physical') !== 'digital') {
                $lines[$index]['downloads'] = null;
                continue;
            }

            $definitions = $this->downloads->activeForVariant($context, $tenant, (string) $line['variant_uuid']);
            $lines[$index]['downloads'] = array_values(array_map(
                static fn (array $d): array => [
                    'download_uuid' => (string) $d['uuid'],
                    'blob_uuid' => (string) $d['blob_uuid'],
                    'name' => (string) $d['name'],
                    'download_limit' => $d['download_limit'] !== null ? (int) $d['download_limit'] : null,
                    'expiry_days' => $d['expiry_days'] !== null ? (int) $d['expiry_days'] : null,
                ],
                $definitions
            ));
        }

        return $lines;
    }

    /** @param array<string,mixed> $cart @return array<string,mixed>|null */
    private function discountForCart(ApplicationContext $context, string $tenant, array $cart): ?array
    {
        if (!is_string($cart['discount_code'] ?? null) || $cart['discount_code'] === '') {
            return null;
        }

        return $this->discounts->findByCode($context, $tenant, (string) $cart['discount_code']);
    }

    /**
     * Optional-contract dispatch (design spec §4/§5): when the bound
     * `TaxCalculator` ALSO implements `LineTaxCalculator`, builds the
     * per-line detailed input (post-discount extended taxable amounts via
     * {@see DiscountAllocation}, plus each line's resolved tax class) and
     * calls `quoteDetailed()` with `$preTax->shippingTotal` -- the EFFECTIVE
     * post-discount shipping amount ({@see PricingEngine::price()} already
     * zeroes this for a `free_shipping` discount), never the originally
     * selected shipping quote's raw amount. A legacy `TaxCalculator` gets the
     * existing aggregate call byte-identically.
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

        $taxableLines = DiscountAllocation::taxableLines($lines, $discount, $preTax->discountTotal);

        return $this->tax->quoteDetailed($context, $taxableLines, $preTax->shippingTotal, $shippingAddress);
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $shippingAddress
     */
    private function resolveShipping(
        ApplicationContext $context,
        array $lines,
        array $shippingAddress,
        ?string $shippingMethodId,
    ): ?ShippingQuote {
        return $this->selectShipping(
            $this->shipping->quote($context, $lines, $shippingAddress),
            $shippingMethodId
        );
    }

    /** @param list<ShippingQuote> $options */
    private function selectShipping(array $options, ?string $shippingMethodId): ?ShippingQuote
    {
        if ($options === []) {
            return null;
        }

        if ($shippingMethodId === null) {
            return $options[0];
        }

        foreach ($options as $option) {
            if ($option->id === $shippingMethodId) {
                return $option;
            }
        }

        throw ValidationException::forField('shipping_method', 'Shipping method is not available.');
    }

    private function dispatch(ApplicationContext $context, object $event): void
    {
        $container = container($context);
        if ($container->has(EventService::class)) {
            $container->get(EventService::class)->dispatch($event);
        }
    }
}
