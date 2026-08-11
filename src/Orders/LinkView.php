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
 *
 * ## Two shapes: full, and state-only
 *
 * A REVOKED link resolves to the STATE-ONLY shape ({@see self::redacted()}):
 * revocation exists to answer a leak, so it must stop the leaker reading the
 * order's commercial content, not merely stop them paying. Every other state --
 * including expired, consumed, and a canceled or refunded order -- resolves in
 * full. See {@see self::redacted()} for why that asymmetry is the right one.
 */
final readonly class LinkView
{
    /**
     * The FULL view. `$contentRedacted` is false here and there is no way to
     * construct a redacted view through this constructor -- see
     * {@see self::redacted()}.
     *
     * @param list<array{name: string, quantity: int}> $lines
     */
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
        public bool $contentRedacted = false,
    ) {
    }

    /**
     * The STATE-ONLY view, for a REVOKED link (review round 1, Important 2).
     *
     * The primary reason an operator revokes a link is that it LEAKED. If a
     * revoked link kept resolving in full, revocation would stop the leaker
     * paying while leaving them free to keep reading the order number, the line
     * names and quantities, and every total -- which is most of what the page was
     * ever worth to an attacker. So revocation redacts: state and expiry only.
     *
     * The asymmetry with the other terminal states is deliberate, not an
     * oversight. `expired`, `consumed`, and a canceled/refunded order all keep
     * resolving with full content, because those links are presumed to be in the
     * hands of the person they were sent to, and that person needs to understand
     * what happened to the bill. Only revocation carries the "this credential is
     * compromised" signal.
     *
     * A NAMED CONSTRUCTOR plus one explicit boolean, rather than making a dozen
     * fields nullable: callers branch on `content_redacted`, and the redacted
     * shape simply OMITS the commercial keys from {@see self::toArray()} rather
     * than publishing zeroes an integrator could render as a real 0.00 order.
     */
    public static function redacted(
        string $orderStatus,
        string $linkStatus,
        string $expiresAt,
        bool $providerSessionIssued,
    ): self {
        return new self(
            orderNumber: '',
            lines: [],
            currency: '',
            subtotal: 0,
            discountTotal: 0,
            shippingTotal: 0,
            taxTotal: 0,
            grandTotal: 0,
            orderStatus: $orderStatus,
            linkStatus: $linkStatus,
            expiresAt: $expiresAt,
            providerSessionIssued: $providerSessionIssued,
            contentRedacted: true,
        );
    }

    /**
     * The four STATE keys are always present; the four COMMERCIAL keys
     * (`order_number`, `line_items`, `currency`, `totals`) are present only when
     * `content_redacted` is false. Absent rather than empty, so an integrator
     * cannot mistake a redacted view for a genuine zero-total order -- and so a
     * template that forgets to check the flag fails visibly instead of rendering
     * a convincing blank bill.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $state = [
            'order_status' => $this->orderStatus,
            'link_status' => $this->linkStatus,
            'expires_at' => $this->expiresAt,
            'provider_session_issued' => $this->providerSessionIssued,
            'content_redacted' => $this->contentRedacted,
        ];

        if ($this->contentRedacted) {
            return $state;
        }

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
        ] + $state;
    }
}
