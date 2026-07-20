<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * The v1 code-defined seller-webhook event catalog (design spec §2.3): the
 * exact, deliberately narrow set of event-type slugs a seller endpoint may
 * subscribe to (`commerce_seller_webhook_endpoints.subscribed_events`, §3).
 * An endpoint's declared events MUST be a subset of {@see self::all()} --
 * never a wildcard, never a slug outside this list.
 *
 * `refund.failed`, `order.note_added`, the parent (non-seller) `order.fulfilled`,
 * commission-policy, marketplace-lifecycle, discount, and late-payment events
 * are all deliberately deferred (design spec §2.3/§8 decision 3) until each
 * has a seller-safe semantic and per-seller sanitized projection.
 *
 * A dedicated, standalone catalog -- NOT a method on `SellerRoleAuthority` or
 * `SellerApiKeyCapabilityCatalog` (same "vocabulary in code, decisions in
 * data" split those classes already follow): this answers "which event TYPES
 * exist to subscribe to at all", an orthogonal question from "who may manage
 * webhooks" or "what may a machine credential declare".
 */
final class SellerWebhookEventCatalog
{
    private const ORDER_PLACED = 'order.placed';
    private const ORDER_PAID = 'order.paid';
    private const ORDER_CANCELED = 'order.canceled';
    private const SELLER_ORDER_FULFILLED = 'seller_order.fulfilled';
    private const REFUND_COMPLETED = 'refund.completed';
    private const PAYOUT_RECORDED = 'payout.recorded';
    private const STOCK_ADJUSTED = 'stock.adjusted';
    private const PRODUCT_ADOPTED = 'product.adopted';
    private const PRODUCT_TRANSFERRED = 'product.transferred';

    /** @var list<string> */
    private const CATALOG = [
        self::ORDER_PLACED,
        self::ORDER_PAID,
        self::ORDER_CANCELED,
        self::SELLER_ORDER_FULFILLED,
        self::REFUND_COMPLETED,
        self::PAYOUT_RECORDED,
        self::STOCK_ADJUSTED,
        self::PRODUCT_ADOPTED,
        self::PRODUCT_TRANSFERRED,
    ];

    /** @return list<string> every event-type slug a seller endpoint may ever subscribe to */
    public static function all(): array
    {
        return self::CATALOG;
    }

    /** True only for an exact catalog slug -- never a pattern/wildcard. */
    public static function contains(string $slug): bool
    {
        return in_array($slug, self::CATALOG, true);
    }
}
