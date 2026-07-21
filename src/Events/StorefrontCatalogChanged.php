<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Broad storefront cache-invalidation signal (design spec §9), dispatched
 * AFTER commit by {@see \Glueful\Extensions\Commerce\Catalog\StorefrontCatalogChangeDispatcher}
 * from every storefront-visible catalog/inventory write path: product
 * create/update/status/delete, variant/price changes, stock changes
 * (including checkout decrement and refund/cancel/expiry restock), media,
 * category, tag, attribute, and add-on changes.
 *
 * `$reason` is a CLOSED vocabulary (see the `REASON_*` constants) -- the
 * constructor throws {@see \InvalidArgumentException} on anything else, so a
 * typo or a future ad-hoc reason string can never reach a listener silently.
 *
 * `$productUuid` is nullable: broad taxonomy changes (a category/tag/
 * attribute DEFINITION edit, for example) can affect arbitrary storefront
 * grids/archives with no single owning product, so a null value is a
 * legitimate, expected signal -- not a defect. Product review and download-
 * entitlement mutations are excluded (design spec §9): v1 does not project
 * them publicly, so no reason exists for either.
 */
final class StorefrontCatalogChanged extends BaseEvent
{
    public const REASON_PRODUCT_CREATED = 'product.created';
    public const REASON_PRODUCT_UPDATED = 'product.updated';
    public const REASON_PRODUCT_STATUS_CHANGED = 'product.status_changed';
    public const REASON_PRODUCT_DELETED = 'product.deleted';
    public const REASON_VARIANT_CHANGED = 'variant.changed';
    public const REASON_STOCK_CHANGED = 'stock.changed';
    public const REASON_MEDIA_CHANGED = 'media.changed';
    public const REASON_CATEGORY_CHANGED = 'category.changed';
    public const REASON_TAG_CHANGED = 'tag.changed';
    public const REASON_ATTRIBUTE_CHANGED = 'attribute.changed';
    public const REASON_ADDON_CHANGED = 'addon.changed';

    private const REASONS = [
        self::REASON_PRODUCT_CREATED,
        self::REASON_PRODUCT_UPDATED,
        self::REASON_PRODUCT_STATUS_CHANGED,
        self::REASON_PRODUCT_DELETED,
        self::REASON_VARIANT_CHANGED,
        self::REASON_STOCK_CHANGED,
        self::REASON_MEDIA_CHANGED,
        self::REASON_CATEGORY_CHANGED,
        self::REASON_TAG_CHANGED,
        self::REASON_ATTRIBUTE_CHANGED,
        self::REASON_ADDON_CHANGED,
    ];

    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $reason,
        public readonly ?string $productUuid = null,
    ) {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException("Unknown StorefrontCatalogChanged reason '{$reason}'.");
        }

        parent::__construct();
    }
}
