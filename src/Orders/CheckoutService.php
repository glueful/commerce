<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Contracts\LineTaxCalculator;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Events\OrderPlaced;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
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

final class CheckoutService
{
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
    ) {
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

        $order = db($context)->transaction(function () use (
            $context,
            $tenant,
            $cart,
            $buyer,
            $guestToken,
            $addresses,
            $storeCurrency,
            $shippingMethodId
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
            ], $lines);
            $this->orders->recordEvent($context, $orderUuid, 'placed', ['number' => $number]);

            $order = $this->orders->findByUuid($context, $tenant, $orderUuid);
            if ($order === null) {
                throw new \RuntimeException('Created order could not be reloaded.');
            }

            return $order;
        });

        $this->dispatch($context, new OrderPlaced($order));
        $payment = $this->initiatePayment($context, $order);

        return ['order' => $order, 'guest_token' => $guestToken['raw'], 'payment' => $payment];
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
