<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * The CLOSED PUBLIC projection of a payment link (payment-links Task 6, design
 * spec §2.2) -- what an UNAUTHENTICATED bearer of a link token is allowed to
 * see, and nothing else.
 *
 * This is the engine's highest-consequence egress surface. Whoever holds the URL
 * holds this view: a forwarded WhatsApp message, a shared screen, a browser
 * history entry on a shared machine. So the contents are an ALLOWLIST, decided
 * by "what does a payer need in order to recognise this bill and decide to pay
 * it", not by "what does the order row happen to contain":
 *
 *  - `orderNumber` -- the reference the payer can quote back. Not the order's
 *    uuid, which is an internal key other surfaces address rows by.
 *  - `lines` -- name and quantity per line. NOT sku, not variant uuid, not per-
 *    line prices (the totals below are the number that matters), not addons.
 *  - the five totals + `currency` -- the amount being asked for.
 *  - `orderStatus` / `linkStatus` -- the honest state pair. A canceled or
 *    refunded order says so (§2.2: "resolve returns honest state"), rather than
 *    presenting a payable-looking page for an order that cannot be paid.
 *  - `expiresAt` -- UTC `Y-m-d H:i:s`.
 *  - `providerSessionIssued` -- whether a checkout session was ever exposed.
 *
 * EXCLUDED ENGINE-SIDE, verbatim from §2.2: store identity, email, phone,
 * addresses, user uuid, notes, internal ids, the token, and the token hash. The
 * exclusions are not incidental -- a payment link is frequently forwarded, and
 * every one of those fields would turn a forwarded URL into a disclosure of
 * someone else's personal data. Thallo's page view adds STORE identity from its
 * own settings authority (it is the store's own name, not the buyer's data);
 * the engine still refuses to source it, because the engine has no such
 * authority.
 *
 * `PaymentLinkServiceTest` pins both the exact serialized key set AND an
 * exclusion set asserted over the serialized shape, so a future field carrying
 * (say) the buyer's email fails even if nobody thought to name it.
 */
final readonly class LinkView
{
    /** @param list<array{name: string, quantity: int}> $lines */
    public function __construct(
        public string $orderNumber,
        public array $lines,
        public string $currency,
        public int $subtotal,
        public int $discountTotal,
        public int $shippingTotal,
        public int $taxTotal,
        public int $grandTotal,
        public string $orderStatus,
        public string $linkStatus,
        public string $expiresAt,
        public bool $providerSessionIssued,
    ) {
    }

    /**
     * @return array{
     *     order_number: string,
     *     line_items: list<array{name: string, quantity: int}>,
     *     currency: string,
     *     totals: array{
     *         subtotal: int,
     *         discount_total: int,
     *         shipping_total: int,
     *         tax_total: int,
     *         grand_total: int
     *     },
     *     order_status: string,
     *     link_status: string,
     *     expires_at: string,
     *     provider_session_issued: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'order_number' => $this->orderNumber,
            'line_items' => $this->lines,
            'currency' => $this->currency,
            'totals' => [
                'subtotal' => $this->subtotal,
                'discount_total' => $this->discountTotal,
                'shipping_total' => $this->shippingTotal,
                'tax_total' => $this->taxTotal,
                'grand_total' => $this->grandTotal,
            ],
            'order_status' => $this->orderStatus,
            'link_status' => $this->linkStatus,
            'expires_at' => $this->expiresAt,
            'provider_session_issued' => $this->providerSessionIssued,
        ];
    }
}
