<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Routing;

use Glueful\Extensions\Commerce\Http\Admin\AdminAddonController;
use Glueful\Extensions\Commerce\Http\Admin\AdminAttributeController;
use Glueful\Extensions\Commerce\Http\Admin\AdminCategoryController;
use Glueful\Extensions\Commerce\Http\Admin\AdminCustomerController;
use Glueful\Extensions\Commerce\Http\Admin\AdminDiscountController;
use Glueful\Extensions\Commerce\Http\Admin\AdminDownloadController;
use Glueful\Extensions\Commerce\Http\Admin\AdminGrantController;
use Glueful\Extensions\Commerce\Http\Admin\AdminMediaController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderArtifactController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderDraftController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderPaymentLinkController;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminRefundController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReportController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReviewController;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingClassController;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingZoneController;
use Glueful\Extensions\Commerce\Http\Admin\AdminStockController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTagController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTaxRateController;
use Glueful\Routing\Router;

/**
 * The declarative, mountable inventory of the NON-MARKETPLACE admin surface (spec §3).
 *
 * Commerce mounts this catalog at its native location (routes.php, unnamed routes — legacy
 * byte parity); an embedding host mounts the same definitions under its own prefix,
 * middleware, and permission model via {@see AdminMountProfile::restricted()} with an
 * explicit fail-closed allowlist. Controllers, DTO validation, and response envelopes are
 * unchanged — only registration is shared. Marketplace routes NEVER enter this catalog;
 * they remain in routes.php behind the `commerce.marketplace.enabled` flag.
 *
 * Entry order mirrors the pre-catalog routes.php admin group exactly; `mode` transcribes
 * the legacy scope gate (commerce:read → view, commerce:write → manage) and is DECLARED
 * per entry, never inferred. Every entry carries an explicit `kind` classification so no
 * endpoint is mechanically remounted.
 */
final class AdminRouteCatalog
{
    /** @var list<AdminRouteEntry>|null */
    private static ?array $entries = null;

    /** @return list<AdminRouteEntry> */
    public static function entries(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }

        $defs = [
            // key, method, path, controller, action, mode, kind, domain
            // — Products / variants —
            ['products.index', 'GET', '/products', AdminProductController::class, 'index', 'view', 'json', 'products'],
            ['products.store', 'POST', '/products', AdminProductController::class, 'store', 'manage', 'json', 'products'],
            ['products.show', 'GET', '/products/{uuid}', AdminProductController::class, 'show', 'view', 'json', 'products'],
            ['products.update', 'PATCH', '/products/{uuid}', AdminProductController::class, 'update', 'manage', 'json', 'products'],
            ['products.variants.store', 'POST', '/products/{uuid}/variants', AdminProductController::class, 'storeVariant', 'manage', 'json', 'products'],
            ['variants.update', 'PATCH', '/variants/{uuid}', AdminProductController::class, 'updateVariant', 'manage', 'json', 'products'],
            [
                'products.children.index',
                'GET',
                '/products/{uuid}/children',
                AdminProductController::class,
                'childrenForProductIndex',
                'view',
                'json',
                'products',
            ],
            ['products.children.set', 'PUT', '/products/{uuid}/children', AdminProductController::class, 'setChildren', 'manage', 'unusual', 'products'],
            ['products.destroy', 'DELETE', '/products/{uuid}', AdminProductController::class, 'destroy', 'manage', 'json', 'products'],
            ['products.bulk_status', 'POST', '/products/bulk-status', AdminProductController::class, 'bulkStatus', 'manage', 'bulk', 'products'],
            ['variants.bulk_price', 'POST', '/variants/bulk-price', AdminProductController::class, 'bulkPrice', 'manage', 'bulk', 'products'],
            // — Digital downloads —
            ['variants.downloads.index', 'GET', '/variants/{uuid}/downloads', AdminDownloadController::class, 'index', 'view', 'json', 'downloads'],
            ['variants.downloads.attach', 'POST', '/variants/{uuid}/downloads', AdminDownloadController::class, 'attach', 'manage', 'json', 'downloads'],
            ['downloads.update', 'PATCH', '/downloads/{uuid}', AdminDownloadController::class, 'update', 'manage', 'json', 'downloads'],
            ['downloads.detach', 'DELETE', '/downloads/{uuid}', AdminDownloadController::class, 'detach', 'manage', 'json', 'downloads'],
            // — Grants —
            ['grants.revoke', 'POST', '/grants/{uuid}/revoke', AdminGrantController::class, 'revoke', 'manage', 'unusual', 'downloads'],
            ['grants.refund_override.set', 'PUT', '/grants/{uuid}/refund-access-override', AdminGrantController::class, 'setOverride', 'manage', 'unusual', 'downloads'],
            ['grants.refund_override.clear', 'DELETE', '/grants/{uuid}/refund-access-override', AdminGrantController::class, 'clearOverride', 'manage', 'unusual', 'downloads'],
            // — Customers (read-only) —
            ['customers.index', 'GET', '/customers', AdminCustomerController::class, 'index', 'view', 'json', 'customers'],
            ['customers.show', 'GET', '/customers/{key}', AdminCustomerController::class, 'show', 'view', 'json', 'customers'],
            // — Product media —
            [
                'products.media.index',
                'GET',
                '/products/{uuid}/media',
                AdminMediaController::class,
                'forProductIndex',
                'view',
                'json',
                'products',
            ],
            ['products.media.attach', 'POST', '/products/{uuid}/media', AdminMediaController::class, 'attach', 'manage', 'json', 'products'],
            ['products.media.reorder', 'PUT', '/products/{uuid}/media/order', AdminMediaController::class, 'reorder', 'manage', 'unusual', 'products'],
            ['media.update', 'PATCH', '/media/{uuid}', AdminMediaController::class, 'update', 'manage', 'json', 'products'],
            ['media.detach', 'DELETE', '/media/{uuid}', AdminMediaController::class, 'detach', 'manage', 'json', 'products'],
            // — Categories —
            ['categories.index', 'GET', '/categories', AdminCategoryController::class, 'index', 'view', 'json', 'taxonomy'],
            ['categories.show', 'GET', '/categories/{uuid}', AdminCategoryController::class, 'show', 'view', 'json', 'taxonomy'],
            ['categories.store', 'POST', '/categories', AdminCategoryController::class, 'store', 'manage', 'json', 'taxonomy'],
            ['categories.update', 'PATCH', '/categories/{uuid}', AdminCategoryController::class, 'update', 'manage', 'json', 'taxonomy'],
            ['categories.destroy', 'DELETE', '/categories/{uuid}', AdminCategoryController::class, 'destroy', 'manage', 'json', 'taxonomy'],
            [
                'products.categories.index',
                'GET',
                '/products/{uuid}/categories',
                AdminCategoryController::class,
                'forProductIndex',
                'view',
                'json',
                'taxonomy',
            ],
            ['products.categories.set', 'PUT', '/products/{uuid}/categories', AdminCategoryController::class, 'setForProduct', 'manage', 'unusual', 'taxonomy'],
            // — Tags —
            ['tags.index', 'GET', '/tags', AdminTagController::class, 'index', 'view', 'json', 'taxonomy'],
            ['tags.show', 'GET', '/tags/{uuid}', AdminTagController::class, 'show', 'view', 'json', 'taxonomy'],
            ['tags.store', 'POST', '/tags', AdminTagController::class, 'store', 'manage', 'json', 'taxonomy'],
            ['tags.update', 'PATCH', '/tags/{uuid}', AdminTagController::class, 'update', 'manage', 'json', 'taxonomy'],
            ['tags.destroy', 'DELETE', '/tags/{uuid}', AdminTagController::class, 'destroy', 'manage', 'json', 'taxonomy'],
            [
                'products.tags.index',
                'GET',
                '/products/{uuid}/tags',
                AdminTagController::class,
                'forProductIndex',
                'view',
                'json',
                'taxonomy',
            ],
            ['products.tags.set', 'PUT', '/products/{uuid}/tags', AdminTagController::class, 'setForProduct', 'manage', 'unusual', 'taxonomy'],
            // — Attributes —
            ['attributes.index', 'GET', '/attributes', AdminAttributeController::class, 'index', 'view', 'json', 'taxonomy'],
            ['attributes.show', 'GET', '/attributes/{uuid}', AdminAttributeController::class, 'show', 'view', 'json', 'taxonomy'],
            ['attributes.store', 'POST', '/attributes', AdminAttributeController::class, 'store', 'manage', 'json', 'taxonomy'],
            ['attributes.update', 'PATCH', '/attributes/{uuid}', AdminAttributeController::class, 'update', 'manage', 'json', 'taxonomy'],
            ['attributes.destroy', 'DELETE', '/attributes/{uuid}', AdminAttributeController::class, 'destroy', 'manage', 'json', 'taxonomy'],
            ['attributes.values.store', 'POST', '/attributes/{uuid}/values', AdminAttributeController::class, 'storeValue', 'manage', 'json', 'taxonomy'],
            ['attribute_values.update', 'PATCH', '/attribute-values/{uuid}', AdminAttributeController::class, 'updateValue', 'manage', 'json', 'taxonomy'],
            ['attribute_values.destroy', 'DELETE', '/attribute-values/{uuid}', AdminAttributeController::class, 'destroyValue', 'manage', 'json', 'taxonomy'],
            [
                'products.attributes.index',
                'GET',
                '/products/{uuid}/attributes',
                AdminAttributeController::class,
                'forProductIndex',
                'view',
                'json',
                'taxonomy',
            ],
            ['products.attributes.set', 'PUT', '/products/{uuid}/attributes', AdminAttributeController::class, 'setForProduct', 'manage', 'unusual', 'taxonomy'],
            // — Product add-ons —
            ['products.addons.index', 'GET', '/products/{uuid}/addons', AdminAddonController::class, 'index', 'view', 'json', 'products'],
            ['products.addons.store', 'POST', '/products/{uuid}/addons', AdminAddonController::class, 'store', 'manage', 'json', 'products'],
            ['addons.update', 'PATCH', '/addons/{uuid}', AdminAddonController::class, 'update', 'manage', 'json', 'products'],
            ['addons.destroy', 'DELETE', '/addons/{uuid}', AdminAddonController::class, 'destroy', 'manage', 'json', 'products'],
            // — Inventory —
            [
                'products.stock.index',
                'GET',
                '/products/{uuid}/stock',
                AdminProductController::class,
                'stockForProductIndex',
                'view',
                'json',
                'inventory',
            ],
            ['stock.adjust', 'POST', '/stock/{variantUuid}/adjust', AdminStockController::class, 'adjust', 'manage', 'unusual', 'inventory'],
            // — Discounts —
            ['discounts.index', 'GET', '/discounts', AdminDiscountController::class, 'index', 'view', 'json', 'discounts'],
            ['discounts.store', 'POST', '/discounts', AdminDiscountController::class, 'store', 'manage', 'json', 'discounts'],
            ['discounts.show', 'GET', '/discounts/{uuid}', AdminDiscountController::class, 'show', 'view', 'json', 'discounts'],
            ['discounts.update', 'PATCH', '/discounts/{uuid}', AdminDiscountController::class, 'update', 'manage', 'json', 'discounts'],
            ['discounts.destroy', 'DELETE', '/discounts/{uuid}', AdminDiscountController::class, 'destroy', 'manage', 'json', 'discounts'],
            // — Orders —
            // 1.6.0 (composed-editor phase 2): per-product order activity read.
            ['products.orders.index', 'GET', '/products/{uuid}/orders', AdminOrderController::class, 'ordersForProductIndex', 'view', 'json', 'orders'],
            ['orders.index', 'GET', '/orders', AdminOrderController::class, 'index', 'view', 'json', 'orders'],
            ['orders.show', 'GET', '/orders/{uuid}', AdminOrderController::class, 'show', 'view', 'json', 'orders'],
            ['orders.cancel', 'POST', '/orders/{uuid}/cancel', AdminOrderController::class, 'cancel', 'manage', 'unusual', 'orders'],
            ['orders.mark_paid', 'POST', '/orders/{uuid}/mark-paid', AdminOrderController::class, 'markPaid', 'manage', 'unusual', 'orders'],
            ['orders.fulfill', 'POST', '/orders/{uuid}/fulfill', AdminOrderController::class, 'fulfill', 'manage', 'unusual', 'orders'],
            ['orders.refunds.store', 'POST', '/orders/{uuid}/refunds', AdminRefundController::class, 'store', 'manage', 'json', 'orders'],
            ['orders.refunds.index', 'GET', '/orders/{uuid}/refunds', AdminRefundController::class, 'index', 'view', 'json', 'orders'],
            ['orders.notes.store', 'POST', '/orders/{uuid}/notes', AdminOrderController::class, 'addNote', 'manage', 'json', 'orders'],
            ['orders.notes.index', 'GET', '/orders/{uuid}/notes', AdminOrderController::class, 'notes', 'view', 'json', 'orders'],
            ['orders.invoice_data', 'GET', '/orders/{uuid}/invoice-data', AdminOrderController::class, 'invoiceData', 'view', 'unusual', 'orders'],
            // — Payment links (payment-links Task 8, design spec §2.2) —
            // The ONE HTTP owner of mint/revoke/status. All three are MANAGE mode,
            // including the status read: a link's state, expiry, and
            // provider-session exposure are payment-custody facts about how an
            // order may be paid, not ordinary order reading, and §2.2 pins the
            // whole trio to manage. Embedding hosts mount these keys and never
            // redeclare the method/path pairs; a pack's own delivery route lives
            // at a deeper path (`.../payment-link/send`) so no pair collides.
            [
                'orders.payment_link.store',
                'POST',
                '/orders/{uuid}/payment-link',
                AdminOrderPaymentLinkController::class,
                'store',
                'manage',
                'json',
                'orders',
            ],
            [
                'orders.payment_link.destroy',
                'DELETE',
                '/orders/{uuid}/payment-link',
                AdminOrderPaymentLinkController::class,
                'destroy',
                'manage',
                'json',
                'orders',
            ],
            [
                'orders.payment_link.show',
                'GET',
                '/orders/{uuid}/payment-link',
                AdminOrderPaymentLinkController::class,
                'show',
                'manage',
                'json',
                'orders',
            ],
            // — Draft-artifact deletion (cleanup-train Task 5) —
            // The ONLY entry in this catalog that DESTROYS a `commerce_orders`
            // row, and it is legal only for a row the database itself proves
            // never touched money (`order_number IS NULL AND status =
            // 'canceled'`); everything else is a typed 409. `manage` mode
            // naturally. Declared `unusual`, not `json`: the `artifact` segment
            // is a DISCRIMINATOR naming which orders may be deleted at all, not
            // a sub-resource being destroyed, so this does not behave like the
            // ordinary `*.destroy` entries above and must not be remounted as if
            // it did. The literal segment also keeps it unambiguous against
            // every sibling `/orders/{uuid}/...` pair.
            [
                'orders.artifact.destroy',
                'DELETE',
                '/orders/{uuid}/artifact',
                AdminOrderArtifactController::class,
                'destroy',
                'manage',
                'unusual',
                'orders',
            ],
            // — Draft orders (admin-order-creation cycle 2, Tasks 9 and 10, design spec §2.3/§2.5) —
            // `/orders/drafts` is a STATIC path and the router resolves static before
            // dynamic (Router::match()), so it can never be swallowed by the
            // `/orders/{uuid}` entry declared above; every deeper draft path pins a
            // literal `drafts` segment where the sibling order routes pin a literal
            // verb, so no pair is ambiguous either.
            ['orders.drafts.index', 'GET', '/orders/drafts', AdminOrderDraftController::class, 'index', 'view', 'json', 'orders'],
            ['orders.drafts.store', 'POST', '/orders/drafts', AdminOrderDraftController::class, 'store', 'manage', 'json', 'orders'],
            ['orders.drafts.show', 'GET', '/orders/drafts/{uuid}', AdminOrderDraftController::class, 'show', 'view', 'json', 'orders'],
            ['orders.drafts.update', 'PATCH', '/orders/drafts/{uuid}', AdminOrderDraftController::class, 'update', 'manage', 'json', 'orders'],
            ['orders.drafts.cancel', 'POST', '/orders/drafts/{uuid}/cancel', AdminOrderDraftController::class, 'cancel', 'manage', 'unusual', 'orders'],
            [
                'orders.drafts.finalize',
                'POST',
                '/orders/drafts/{uuid}/finalize',
                AdminOrderDraftController::class,
                'finalize',
                'manage',
                'unusual',
                'orders',
            ],
            [
                'orders.drafts.recalculate',
                'POST',
                '/orders/drafts/{uuid}/recalculate',
                AdminOrderDraftController::class,
                'recalculate',
                'manage',
                'unusual',
                'orders',
            ],
            ['orders.drafts.lines.store', 'POST', '/orders/drafts/{uuid}/lines', AdminOrderDraftController::class, 'storeLine', 'manage', 'json', 'orders'],
            [
                'orders.drafts.lines.update',
                'PATCH',
                '/orders/drafts/{uuid}/lines/{lineUuid}',
                AdminOrderDraftController::class,
                'updateLine',
                'manage',
                'json',
                'orders',
            ],
            [
                'orders.drafts.lines.destroy',
                'DELETE',
                '/orders/drafts/{uuid}/lines/{lineUuid}',
                AdminOrderDraftController::class,
                'destroyLine',
                'manage',
                'json',
                'orders',
            ],
            ['refunds.list', 'GET', '/refunds', AdminRefundController::class, 'list', 'view', 'json', 'orders'],
            ['refunds.show', 'GET', '/refunds/{uuid}', AdminRefundController::class, 'show', 'view', 'json', 'orders'],
            // — Reviews —
            ['reviews.index', 'GET', '/reviews', AdminReviewController::class, 'index', 'view', 'json', 'reviews'],
            ['reviews.show', 'GET', '/reviews/{uuid}', AdminReviewController::class, 'show', 'view', 'json', 'reviews'],
            ['reviews.store', 'POST', '/reviews', AdminReviewController::class, 'store', 'manage', 'json', 'reviews'],
            ['reviews.approve', 'POST', '/reviews/{uuid}/approve', AdminReviewController::class, 'approve', 'manage', 'json', 'reviews'],
            ['reviews.spam', 'POST', '/reviews/{uuid}/spam', AdminReviewController::class, 'spam', 'manage', 'json', 'reviews'],
            ['reviews.destroy', 'DELETE', '/reviews/{uuid}', AdminReviewController::class, 'destroy', 'manage', 'json', 'reviews'],
            ['reviews.bulk', 'POST', '/reviews/bulk', AdminReviewController::class, 'bulk', 'manage', 'bulk', 'reviews'],
            // — Shipping zones + methods —
            ['shipping.zones.index', 'GET', '/shipping/zones', AdminShippingZoneController::class, 'index', 'view', 'json', 'shipping'],
            ['shipping.zones.show', 'GET', '/shipping/zones/{uuid}', AdminShippingZoneController::class, 'show', 'view', 'json', 'shipping'],
            ['shipping.zones.store', 'POST', '/shipping/zones', AdminShippingZoneController::class, 'store', 'manage', 'json', 'shipping'],
            ['shipping.zones.update', 'PATCH', '/shipping/zones/{uuid}', AdminShippingZoneController::class, 'update', 'manage', 'json', 'shipping'],
            ['shipping.zones.destroy', 'DELETE', '/shipping/zones/{uuid}', AdminShippingZoneController::class, 'destroy', 'manage', 'json', 'shipping'],
            ['shipping.zones.locations.set', 'PUT', '/shipping/zones/{uuid}/locations', AdminShippingZoneController::class, 'setLocations', 'manage', 'unusual', 'shipping'],
            ['shipping.zones.methods.index', 'GET', '/shipping/zones/{uuid}/methods', AdminShippingZoneController::class, 'indexMethods', 'view', 'json', 'shipping'],
            ['shipping.zones.methods.store', 'POST', '/shipping/zones/{uuid}/methods', AdminShippingZoneController::class, 'storeMethod', 'manage', 'json', 'shipping'],
            ['shipping.methods.show', 'GET', '/shipping/methods/{uuid}', AdminShippingZoneController::class, 'showMethod', 'view', 'json', 'shipping'],
            ['shipping.methods.update', 'PATCH', '/shipping/methods/{uuid}', AdminShippingZoneController::class, 'updateMethod', 'manage', 'json', 'shipping'],
            ['shipping.methods.destroy', 'DELETE', '/shipping/methods/{uuid}', AdminShippingZoneController::class, 'destroyMethod', 'manage', 'json', 'shipping'],
            // — Shipping classes —
            ['shipping.classes.index', 'GET', '/shipping/classes', AdminShippingClassController::class, 'index', 'view', 'json', 'shipping'],
            ['shipping.classes.show', 'GET', '/shipping/classes/{uuid}', AdminShippingClassController::class, 'show', 'view', 'json', 'shipping'],
            ['shipping.classes.store', 'POST', '/shipping/classes', AdminShippingClassController::class, 'store', 'manage', 'json', 'shipping'],
            ['shipping.classes.update', 'PATCH', '/shipping/classes/{uuid}', AdminShippingClassController::class, 'update', 'manage', 'json', 'shipping'],
            ['shipping.classes.destroy', 'DELETE', '/shipping/classes/{uuid}', AdminShippingClassController::class, 'destroy', 'manage', 'json', 'shipping'],
            // — Tax rates —
            ['tax.rates.index', 'GET', '/tax/rates', AdminTaxRateController::class, 'index', 'view', 'json', 'tax'],
            ['tax.rates.show', 'GET', '/tax/rates/{uuid}', AdminTaxRateController::class, 'show', 'view', 'json', 'tax'],
            ['tax.rates.store', 'POST', '/tax/rates', AdminTaxRateController::class, 'store', 'manage', 'json', 'tax'],
            ['tax.rates.update', 'PATCH', '/tax/rates/{uuid}', AdminTaxRateController::class, 'update', 'manage', 'json', 'tax'],
            ['tax.rates.destroy', 'DELETE', '/tax/rates/{uuid}', AdminTaxRateController::class, 'destroy', 'manage', 'json', 'tax'],
            // — Reports —
            ['reports.sales', 'GET', '/reports/sales', AdminReportController::class, 'sales', 'view', 'json', 'reports'],
            ['reports.products', 'GET', '/reports/products', AdminReportController::class, 'products', 'view', 'json', 'reports'],
            ['reports.customers', 'GET', '/reports/customers', AdminReportController::class, 'customers', 'view', 'json', 'reports'],
            ['reports.stock', 'GET', '/reports/stock', AdminReportController::class, 'stock', 'view', 'json', 'reports'],
        ];

        return self::$entries = array_map(
            static fn (array $d): AdminRouteEntry => new AdminRouteEntry(...$d),
            $defs,
        );
    }

    /**
     * Register the profile's selection of catalog entries on the router. A restricted
     * profile's allowlist is resolved key-by-key — an unknown key throws rather than
     * silently mounting nothing.
     */
    public static function mount(Router $router, AdminMountProfile $profile): void
    {
        $entries = self::entries();

        if ($profile->allowlist !== null) {
            $byKey = [];
            foreach ($entries as $entry) {
                $byKey[$entry->key] = $entry;
            }
            $entries = array_map(
                static function (string $key) use ($byKey): AdminRouteEntry {
                    return $byKey[$key]
                        ?? throw new \RuntimeException("Unknown admin catalog key in allowlist: {$key}");
                },
                $profile->allowlist,
            );
        }

        $router->group(
            ['prefix' => $profile->prefix, 'middleware' => $profile->middleware],
            static function (Router $r) use ($entries, $profile): void {
                foreach ($entries as $entry) {
                    $route = $r->{strtolower($entry->method)}($entry->path, [$entry->controller, $entry->action])
                        ->middleware($profile->modeMiddleware[$entry->mode]);
                    if ($profile->routeNamePrefix !== '') {
                        $route->name($profile->routeNamePrefix . $entry->key);
                    }
                }
            },
        );
    }
}
