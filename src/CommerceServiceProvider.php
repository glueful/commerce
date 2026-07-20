<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Encryption\EncryptionService;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AddonService;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeService;
use Glueful\Extensions\Commerce\Catalog\CatalogReader;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\DownloadService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepositoryCatalogReader;
use Glueful\Extensions\Commerce\Catalog\ReviewRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewService;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\TagService;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Cart\CartPruner;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Customers\AddressBookRepository;
use Glueful\Extensions\Commerce\Customers\AddressBookService;
use Glueful\Extensions\Commerce\Customers\CustomerAggregationRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Events\Listeners\ProviderChargebackListener;
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Extensions\Commerce\Events\OrderNoteAdded;
use Glueful\Extensions\Commerce\Events\OrderPaid;
use Glueful\Extensions\Commerce\Events\OrderPlaced;
use Glueful\Extensions\Commerce\Events\RefundCompleted;
use Glueful\Extensions\Commerce\Http\Admin\AdminAddonController;
use Glueful\Extensions\Commerce\Http\Admin\AdminAttributeController;
use Glueful\Extensions\Commerce\Http\Admin\AdminCategoryController;
use Glueful\Extensions\Commerce\Http\Admin\AdminCustomerController;
use Glueful\Extensions\Commerce\Http\Admin\AdminDiscountController;
use Glueful\Extensions\Commerce\Http\Admin\AdminDownloadController;
use Glueful\Extensions\Commerce\Http\Admin\AdminGrantController;
use Glueful\Extensions\Commerce\Http\Admin\AdminMarketplaceFinancialController;
use Glueful\Extensions\Commerce\Http\Admin\AdminMediaController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminPayoutController;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminRefundController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReportController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReserveController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReviewController;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingClassController;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingZoneController;
use Glueful\Extensions\Commerce\Http\Admin\AdminStockController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTagController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTaxRateController;
use Glueful\Extensions\Commerce\Http\Admin\MarketplaceAdminController;
use Glueful\Extensions\Commerce\Http\Middleware\InteractiveSessionMiddleware;
use Glueful\Extensions\Commerce\Http\Middleware\SellerMemberMiddleware;
use Glueful\Extensions\Commerce\Http\Seller\SellerApiKeyController;
use Glueful\Extensions\Commerce\Http\Seller\SellerCatalogController;
use Glueful\Extensions\Commerce\Http\Seller\SellerFinancialController;
use Glueful\Extensions\Commerce\Http\Seller\SellerInventoryController;
use Glueful\Extensions\Commerce\Http\Seller\SellerMembershipController;
use Glueful\Extensions\Commerce\Http\Seller\SellerOrderController;
use Glueful\Extensions\Commerce\Http\Seller\SellerWebhookController;
use Glueful\Extensions\Commerce\Http\Storefront\AccountAddressController;
use Glueful\Extensions\Commerce\Http\Storefront\CartController;
use Glueful\Extensions\Commerce\Http\Storefront\CategoryController;
use Glueful\Extensions\Commerce\Http\Storefront\CheckoutController;
use Glueful\Extensions\Commerce\Http\Storefront\DownloadLinkController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Http\Storefront\ReviewController;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Invoices\SellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Mail\CommerceMailer;
use Glueful\Extensions\Commerce\Mail\NotificationCommerceMailer;
use Glueful\Extensions\Commerce\Mail\OrderMailListener;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentService;
use Glueful\Extensions\Commerce\Marketplace\ChargebackRepository;
use Glueful\Extensions\Commerce\Marketplace\ChargebackService;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyEventRepository;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyService;
use Glueful\Extensions\Commerce\Marketplace\Contracts\SellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerPostingService;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceActivationService;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceRefundGuard;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountService;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\ReconciliationService;
use Glueful\Extensions\Commerce\Marketplace\ReserveConsumptionService;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyEventRepository;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyService;
use Glueful\Extensions\Commerce\Marketplace\ReserveRepository;
use Glueful\Extensions\Commerce\Marketplace\ReserveService;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyAuthorizer;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyScopeValidator;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyService;
use Glueful\Extensions\Commerce\Marketplace\SellerAttributionService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookOutboxPublisher;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookPayloadProjector;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookSecretService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\Downloads\CommerceDownloadBlobPolicy;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadAccessService;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantService;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadUrlSigner;
use Glueful\Extensions\Commerce\Orders\ExpiryService;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Payments\OrderPaymentConfirmationHandler;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Reports\CustomerReportRepository;
use Glueful\Extensions\Commerce\Reports\ProductSalesReportRepository;
use Glueful\Extensions\Commerce\Reports\SalesReportRepository;
use Glueful\Extensions\Commerce\Reports\SellerFinancialReportRepository;
use Glueful\Extensions\Commerce\Reports\StockReportRepository;
use Glueful\Extensions\Commerce\Shipping\ConfigShippingRateProvider;
use Glueful\Extensions\Commerce\Shipping\DbShippingRateProvider;
use Glueful\Extensions\Commerce\Shipping\DelegatingShippingRateProvider;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingClassService;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneService;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantPurge;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Extensions\Commerce\Tenancy\FailClosedTenantResolver;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Commerce\Tax\DbTaxCalculator;
use Glueful\Extensions\Commerce\Tax\DelegatingTaxCalculator;
use Glueful\Extensions\Commerce\Tax\FlatRateTaxCalculator;
use Glueful\Extensions\Commerce\Tax\TaxRateRepository;
use Glueful\Extensions\Commerce\Tax\TaxRateService;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmationHandler;
use Glueful\Extensions\Contracts\Payments\PayoutCollector;
use Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent;
use Glueful\Extensions\Contracts\Payments\RefundCollector;
use Glueful\Extensions\ServiceProvider;
use Glueful\Http\Client;
use Glueful\Http\Security\SafeOutboundTargetResolver;
use Glueful\Repository\BlobRepository;
use Glueful\Uploader\Contracts\BlobAccessPolicyRegistry;
use Glueful\Uploader\Contracts\BlobPublicUrlProvider;
use Psr\Container\ContainerInterface;

final class CommerceServiceProvider extends ServiceProvider
{
    private static ?string $cachedVersion = null;

    public static function composerVersion(): string
    {
        if (self::$cachedVersion === null) {
            $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
            self::$cachedVersion = (string) ($composer['extra']['glueful']['version'] ?? '0.0.0');
        }

        return self::$cachedVersion;
    }

    /**
     * Commerce binds nothing under shared contract ids. Factories that need the
     * tenant resolver or payment collector resolve the shared contract if bound,
     * otherwise construct an inline fallback.
     *
     * @return array<string, mixed>
     */
    public static function services(): array
    {
        return [
            ProductRepository::class => [
                'class' => ProductRepository::class,
                'shared' => true,
            ],
            CatalogReader::class => [
                'class' => ProductRepositoryCatalogReader::class,
                'shared' => true,
                'autowire' => true,
            ],
            VariantRepository::class => [
                'class' => VariantRepository::class,
                'shared' => true,
            ],
            ProductChildrenRepository::class => [
                'class' => ProductChildrenRepository::class,
                'shared' => true,
            ],
            CatalogService::class => [
                'factory' => [self::class, 'makeCatalogService'],
                'shared' => true,
            ],
            ProductMediaRepository::class => [
                'class' => ProductMediaRepository::class,
                'shared' => true,
            ],
            ProductMediaService::class => [
                'factory' => [self::class, 'makeProductMediaService'],
                'shared' => true,
            ],
            DownloadRepository::class => [
                'class' => DownloadRepository::class,
                'shared' => true,
            ],
            DownloadService::class => [
                'factory' => [self::class, 'makeDownloadService'],
                'shared' => true,
            ],
            CategoryRepository::class => [
                'class' => CategoryRepository::class,
                'shared' => true,
            ],
            CategoryService::class => [
                'factory' => [self::class, 'makeCategoryService'],
                'shared' => true,
            ],
            TagRepository::class => [
                'class' => TagRepository::class,
                'shared' => true,
            ],
            TagService::class => [
                'factory' => [self::class, 'makeTagService'],
                'shared' => true,
            ],
            AttributeRepository::class => [
                'class' => AttributeRepository::class,
                'shared' => true,
            ],
            AttributeService::class => [
                'factory' => [self::class, 'makeAttributeService'],
                'shared' => true,
            ],
            AddonRepository::class => [
                'class' => AddonRepository::class,
                'shared' => true,
            ],
            AddonService::class => [
                'factory' => [self::class, 'makeAddonService'],
                'shared' => true,
            ],
            ReviewRepository::class => [
                'class' => ReviewRepository::class,
                'shared' => true,
            ],
            ReviewService::class => [
                'factory' => [self::class, 'makeReviewService'],
                'shared' => true,
            ],
            StockRepository::class => [
                'class' => StockRepository::class,
                'shared' => true,
            ],
            InventoryService::class => [
                'factory' => [self::class, 'makeInventoryService'],
                'shared' => true,
            ],
            DiscountRepository::class => [
                'class' => DiscountRepository::class,
                'shared' => true,
            ],
            DiscountService::class => [
                'factory' => [self::class, 'makeDiscountService'],
                'shared' => true,
            ],
            PricingEngine::class => [
                'class' => PricingEngine::class,
                'shared' => true,
            ],
            CartRepository::class => [
                'class' => CartRepository::class,
                'shared' => true,
            ],
            CartService::class => [
                'factory' => [self::class, 'makeCartService'],
                'shared' => true,
            ],
            CartPruner::class => [
                'class' => CartPruner::class,
                'shared' => true,
            ],
            TaxCalculator::class => [
                'factory' => [self::class, 'makeTaxCalculator'],
                'shared' => true,
            ],
            ShippingRateProvider::class => [
                'factory' => [self::class, 'makeShippingRateProvider'],
                'shared' => true,
            ],
            OrderNumberGenerator::class => [
                'class' => OrderNumberGenerator::class,
                'shared' => true,
            ],
            OrderRepository::class => [
                'class' => OrderRepository::class,
                'shared' => true,
            ],
            CheckoutService::class => [
                'factory' => [self::class, 'makeCheckoutService'],
                'shared' => true,
            ],
            OrderPaymentService::class => [
                'class' => OrderPaymentService::class,
                'shared' => true,
                'autowire' => true,
            ],
            RefundRepository::class => [
                'class' => RefundRepository::class,
                'shared' => true,
            ],
            RefundService::class => [
                'factory' => [self::class, 'makeRefundService'],
                'shared' => true,
            ],
            DownloadGrantRepository::class => [
                'class' => DownloadGrantRepository::class,
                'shared' => true,
            ],
            DownloadGrantService::class => [
                'factory' => [self::class, 'makeDownloadGrantService'],
                'shared' => true,
            ],
            DownloadUrlSigner::class => [
                'factory' => [self::class, 'makeDownloadUrlSigner'],
                'shared' => true,
            ],
            DownloadAccessService::class => [
                'factory' => [self::class, 'makeDownloadAccessService'],
                'shared' => true,
            ],
            CommerceDownloadBlobPolicy::class => [
                'factory' => [self::class, 'makeCommerceDownloadBlobPolicy'],
                'shared' => true,
            ],
            SellerIdentityProvider::class => [
                'class' => ConfigSellerIdentityProvider::class,
                'shared' => true,
            ],
            CommerceMailer::class => [
                'class' => NotificationCommerceMailer::class,
                'shared' => true,
            ],
            OrderMailListener::class => [
                'factory' => [self::class, 'makeOrderMailListener'],
                'shared' => true,
            ],
            TenantAdopter::class => [
                'class' => TenantAdopter::class,
                'shared' => true,
            ],
            CommerceTenantPurge::class => [
                'class' => CommerceTenantPurge::class,
                'shared' => true,
            ],
            MarketplaceMode::class => [
                'class' => MarketplaceMode::class,
                'shared' => true,
            ],
            MarketplaceWorkspaceLock::class => [
                'class' => MarketplaceWorkspaceLock::class,
                'shared' => true,
            ],
            SellerRepository::class => [
                'class' => SellerRepository::class,
                'shared' => true,
            ],
            SellerMembershipRepository::class => [
                'class' => SellerMembershipRepository::class,
                'shared' => true,
            ],
            SellerLifecycleEventRepository::class => [
                'class' => SellerLifecycleEventRepository::class,
                'shared' => true,
            ],
            SellerApiKeyRepository::class => [
                'class' => SellerApiKeyRepository::class,
                'shared' => true,
            ],
            SellerApiKeyScopeValidator::class => [
                'factory' => [self::class, 'makeSellerApiKeyScopeValidator'],
                'shared' => true,
            ],
            SellerApiKeyService::class => [
                'factory' => [self::class, 'makeSellerApiKeyService'],
                'shared' => true,
            ],
            SellerApiKeyAuthorizer::class => [
                'factory' => [self::class, 'makeSellerApiKeyAuthorizer'],
                'shared' => true,
            ],
            SellerWebhookEndpointRepository::class => [
                'class' => SellerWebhookEndpointRepository::class,
                'shared' => true,
            ],
            SellerWebhookDeliveryRepository::class => [
                'class' => SellerWebhookDeliveryRepository::class,
                'shared' => true,
            ],
            SellerWebhookSecretService::class => [
                'factory' => [self::class, 'makeSellerWebhookSecretService'],
                'shared' => true,
            ],
            SellerWebhookEndpointService::class => [
                'factory' => [self::class, 'makeSellerWebhookEndpointService'],
                'shared' => true,
            ],
            SellerWebhookEventRepository::class => [
                'class' => SellerWebhookEventRepository::class,
                'shared' => true,
            ],
            SellerWebhookPayloadProjector::class => [
                'class' => SellerWebhookPayloadProjector::class,
                'shared' => true,
            ],
            SellerWebhookOutboxPublisher::class => [
                'factory' => [self::class, 'makeSellerWebhookOutboxPublisher'],
                'shared' => true,
            ],
            SellerWebhookDeliveryService::class => [
                'factory' => [self::class, 'makeSellerWebhookDeliveryService'],
                'shared' => true,
            ],
            SellerOrderRepository::class => [
                'class' => SellerOrderRepository::class,
                'shared' => true,
            ],
            SellerOrderPaymentConfirmation::class => [
                'class' => SellerOrderPaymentConfirmation::class,
                'shared' => true,
            ],
            LedgerRepository::class => [
                'class' => LedgerRepository::class,
                'shared' => true,
            ],
            LedgerAccountLock::class => [
                'class' => LedgerAccountLock::class,
                'shared' => true,
            ],
            LedgerPostingService::class => [
                'class' => LedgerPostingService::class,
                'shared' => true,
                'autowire' => true,
            ],
            SellerBalanceService::class => [
                'class' => SellerBalanceService::class,
                'shared' => true,
                'autowire' => true,
            ],
            PayoutRepository::class => [
                'class' => PayoutRepository::class,
                'shared' => true,
            ],
            PayoutAccountRepository::class => [
                'class' => PayoutAccountRepository::class,
                'shared' => true,
            ],
            PayoutAccountService::class => [
                'factory' => [self::class, 'makePayoutAccountService'],
                'shared' => true,
            ],
            PayoutService::class => [
                'factory' => [self::class, 'makePayoutService'],
                'shared' => true,
            ],
            AdjustmentService::class => [
                'class' => AdjustmentService::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReconciliationService::class => [
                'class' => ReconciliationService::class,
                'shared' => true,
            ],
            AdminPayoutController::class => [
                'factory' => [self::class, 'makeAdminPayoutController'],
                'shared' => true,
            ],
            MarketplaceRefundGuard::class => [
                'class' => MarketplaceRefundGuard::class,
                'shared' => true,
                'autowire' => true,
            ],
            SellerOrderFulfillmentService::class => [
                'factory' => [self::class, 'makeSellerOrderFulfillmentService'],
                'shared' => true,
            ],
            SellerOrderService::class => [
                'factory' => [self::class, 'makeSellerOrderService'],
                'shared' => true,
            ],
            SellerRoleAuthority::class => [
                'class' => FixedSellerRoleAuthority::class,
                'shared' => true,
            ],
            CommissionPolicyEventRepository::class => [
                'class' => CommissionPolicyEventRepository::class,
                'shared' => true,
            ],
            CommissionPolicyService::class => [
                'factory' => [self::class, 'makeCommissionPolicyService'],
                'shared' => true,
            ],
            ReservePolicyEventRepository::class => [
                'class' => ReservePolicyEventRepository::class,
                'shared' => true,
            ],
            ReservePolicyService::class => [
                'factory' => [self::class, 'makeReservePolicyService'],
                'shared' => true,
            ],
            ReserveRepository::class => [
                'class' => ReserveRepository::class,
                'shared' => true,
            ],
            ReserveService::class => [
                'class' => ReserveService::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReserveConsumptionService::class => [
                'class' => ReserveConsumptionService::class,
                'shared' => true,
                'autowire' => true,
            ],
            ChargebackRepository::class => [
                'class' => ChargebackRepository::class,
                'shared' => true,
            ],
            ChargebackService::class => [
                'class' => ChargebackService::class,
                'shared' => true,
                'autowire' => true,
            ],
            ProviderChargebackListener::class => [
                'factory' => [self::class, 'makeProviderChargebackListener'],
                'shared' => true,
            ],
            AdminReserveController::class => [
                'factory' => [self::class, 'makeAdminReserveController'],
                'shared' => true,
            ],
            SellerService::class => [
                'factory' => [self::class, 'makeSellerService'],
                'shared' => true,
            ],
            SellerMembershipService::class => [
                'factory' => [self::class, 'makeSellerMembershipService'],
                'shared' => true,
            ],
            SellerAttributionService::class => [
                'factory' => [self::class, 'makeSellerAttributionService'],
                'shared' => true,
            ],
            MarketplaceActivationService::class => [
                'factory' => [self::class, 'makeMarketplaceActivationService'],
                'shared' => true,
            ],
            MarketplaceAdminController::class => [
                'factory' => [self::class, 'makeMarketplaceAdminController'],
                'shared' => true,
            ],
            'commerce_seller' => [
                'factory' => [self::class, 'makeSellerMemberMiddleware'],
                'shared' => true,
            ],
            SellerMemberMiddleware::class => [
                'factory' => [self::class, 'makeSellerMemberMiddleware'],
                'shared' => true,
            ],
            'interactive_session' => [
                'factory' => [self::class, 'makeInteractiveSessionMiddleware'],
                'shared' => true,
            ],
            InteractiveSessionMiddleware::class => [
                'factory' => [self::class, 'makeInteractiveSessionMiddleware'],
                'shared' => true,
            ],
            SellerApiKeyController::class => [
                'factory' => [self::class, 'makeSellerApiKeyController'],
                'shared' => true,
            ],
            SellerWebhookController::class => [
                'factory' => [self::class, 'makeSellerWebhookController'],
                'shared' => true,
            ],
            SellerCatalogController::class => [
                'factory' => [self::class, 'makeSellerCatalogController'],
                'shared' => true,
            ],
            SellerInventoryController::class => [
                'factory' => [self::class, 'makeSellerInventoryController'],
                'shared' => true,
            ],
            SellerMembershipController::class => [
                'factory' => [self::class, 'makeSellerMembershipController'],
                'shared' => true,
            ],
            SellerOrderController::class => [
                'factory' => [self::class, 'makeSellerOrderController'],
                'shared' => true,
            ],
            ExpiryService::class => [
                'factory' => [self::class, 'makeExpiryService'],
                'shared' => true,
            ],
            OrderPaymentConfirmationHandler::class => [
                'factory' => [self::class, 'makeOrderPaymentConfirmationHandler'],
                'shared' => true,
                'tags' => [PaymentConfirmationHandler::CONTAINER_TAG],
            ],
            ProductController::class => [
                'factory' => [self::class, 'makeProductController'],
                'shared' => true,
            ],
            CategoryController::class => [
                'factory' => [self::class, 'makeCategoryController'],
                'shared' => true,
            ],
            ReviewController::class => [
                'factory' => [self::class, 'makeReviewController'],
                'shared' => true,
            ],
            CartController::class => [
                'factory' => [self::class, 'makeCartController'],
                'shared' => true,
            ],
            CheckoutController::class => [
                'factory' => [self::class, 'makeCheckoutController'],
                'shared' => true,
            ],
            OrderController::class => [
                'factory' => [self::class, 'makeOrderController'],
                'shared' => true,
            ],
            DownloadLinkController::class => [
                'factory' => [self::class, 'makeDownloadLinkController'],
                'shared' => true,
            ],
            AdminProductController::class => [
                'factory' => [self::class, 'makeAdminProductController'],
                'shared' => true,
            ],
            AdminStockController::class => [
                'factory' => [self::class, 'makeAdminStockController'],
                'shared' => true,
            ],
            AdminDiscountController::class => [
                'factory' => [self::class, 'makeAdminDiscountController'],
                'shared' => true,
            ],
            AdminOrderController::class => [
                'factory' => [self::class, 'makeAdminOrderController'],
                'shared' => true,
            ],
            AdminRefundController::class => [
                'factory' => [self::class, 'makeAdminRefundController'],
                'shared' => true,
            ],
            AdminMediaController::class => [
                'factory' => [self::class, 'makeAdminMediaController'],
                'shared' => true,
            ],
            AdminDownloadController::class => [
                'factory' => [self::class, 'makeAdminDownloadController'],
                'shared' => true,
            ],
            AdminCategoryController::class => [
                'factory' => [self::class, 'makeAdminCategoryController'],
                'shared' => true,
            ],
            AdminTagController::class => [
                'factory' => [self::class, 'makeAdminTagController'],
                'shared' => true,
            ],
            AdminAttributeController::class => [
                'factory' => [self::class, 'makeAdminAttributeController'],
                'shared' => true,
            ],
            AdminAddonController::class => [
                'factory' => [self::class, 'makeAdminAddonController'],
                'shared' => true,
            ],
            AdminReviewController::class => [
                'factory' => [self::class, 'makeAdminReviewController'],
                'shared' => true,
            ],
            AdminGrantController::class => [
                'factory' => [self::class, 'makeAdminGrantController'],
                'shared' => true,
            ],
            CustomerAggregationRepository::class => [
                'class' => CustomerAggregationRepository::class,
                'shared' => true,
            ],
            AdminCustomerController::class => [
                'factory' => [self::class, 'makeAdminCustomerController'],
                'shared' => true,
            ],
            SalesReportRepository::class => [
                'class' => SalesReportRepository::class,
                'shared' => true,
            ],
            ProductSalesReportRepository::class => [
                'class' => ProductSalesReportRepository::class,
                'shared' => true,
            ],
            CustomerReportRepository::class => [
                'class' => CustomerReportRepository::class,
                'shared' => true,
            ],
            StockReportRepository::class => [
                'class' => StockReportRepository::class,
                'shared' => true,
            ],
            AdminReportController::class => [
                'factory' => [self::class, 'makeAdminReportController'],
                'shared' => true,
            ],
            SellerFinancialReportRepository::class => [
                'class' => SellerFinancialReportRepository::class,
                'shared' => true,
            ],
            SellerFinancialController::class => [
                'factory' => [self::class, 'makeSellerFinancialController'],
                'shared' => true,
            ],
            AdminMarketplaceFinancialController::class => [
                'factory' => [self::class, 'makeAdminMarketplaceFinancialController'],
                'shared' => true,
            ],
            AddressBookRepository::class => [
                'class' => AddressBookRepository::class,
                'shared' => true,
            ],
            AddressBookService::class => [
                'factory' => [self::class, 'makeAddressBookService'],
                'shared' => true,
            ],
            AccountAddressController::class => [
                'factory' => [self::class, 'makeAccountAddressController'],
                'shared' => true,
            ],
            ShippingZoneRepository::class => [
                'class' => ShippingZoneRepository::class,
                'shared' => true,
            ],
            ShippingZoneService::class => [
                'factory' => [self::class, 'makeShippingZoneService'],
                'shared' => true,
            ],
            AdminShippingZoneController::class => [
                'factory' => [self::class, 'makeAdminShippingZoneController'],
                'shared' => true,
            ],
            ShippingClassRepository::class => [
                'class' => ShippingClassRepository::class,
                'shared' => true,
            ],
            ShippingClassService::class => [
                'factory' => [self::class, 'makeShippingClassService'],
                'shared' => true,
            ],
            AdminShippingClassController::class => [
                'factory' => [self::class, 'makeAdminShippingClassController'],
                'shared' => true,
            ],
            TaxRateRepository::class => [
                'class' => TaxRateRepository::class,
                'shared' => true,
            ],
            TaxRateService::class => [
                'factory' => [self::class, 'makeTaxRateService'],
                'shared' => true,
            ],
            AdminTaxRateController::class => [
                'factory' => [self::class, 'makeAdminTaxRateController'],
                'shared' => true,
            ],
        ];
    }

    public static function makeCatalogService(ContainerInterface $container): CatalogService
    {
        return new CatalogService(
            $container->get(ProductRepository::class),
            $container->get(VariantRepository::class),
            self::tenantResolver($container),
            $container->get(StockRepository::class),
            $container->get(ProductChildrenRepository::class),
            $container->get(ShippingClassRepository::class),
            $container->get(MarketplaceMode::class),
            $container->get(MarketplaceWorkspaceLock::class),
            $container->get(SellerRepository::class),
            $container->get(CommissionPolicyService::class)
        );
    }

    public static function makeCommissionPolicyService(ContainerInterface $container): CommissionPolicyService
    {
        return new CommissionPolicyService(
            $container->get(ProductRepository::class),
            $container->get(SellerRepository::class),
            $container->get(MarketplaceWorkspaceLock::class),
            $container->get(CommissionPolicyEventRepository::class)
        );
    }

    public static function makeReservePolicyService(ContainerInterface $container): ReservePolicyService
    {
        return new ReservePolicyService(
            $container->get(SellerRepository::class),
            $container->get(MarketplaceWorkspaceLock::class),
            $container->get(ReservePolicyEventRepository::class)
        );
    }

    public static function makePayoutService(ContainerInterface $container): PayoutService
    {
        return new PayoutService(
            $container->get(PayoutRepository::class),
            $container->get(LedgerRepository::class),
            $container->get(LedgerAccountLock::class),
            $container->get(SellerBalanceService::class),
            $container->get(SellerRepository::class),
            null,
            self::makePayoutCollector($container),
            $container->get(PayoutAccountService::class),
            $container->get(SellerWebhookOutboxPublisher::class)
        );
    }

    public static function makePayoutAccountService(ContainerInterface $container): PayoutAccountService
    {
        return new PayoutAccountService(
            $container->get(PayoutAccountRepository::class),
            null,
            self::makePayoutCollector($container)
        );
    }

    /**
     * Soft-resolved, same pattern as {@see self::makeRefundCollector()}: the provider-payout
     * port (design spec §2.9) is provider-injected by the host; with nothing bound, provider
     * payouts stay unavailable (422) while manual payouts + ledger semantics keep working.
     */
    private static function makePayoutCollector(ContainerInterface $container): ?PayoutCollector
    {
        if (!$container->has(PayoutCollector::class)) {
            return null;
        }

        $collector = $container->get(PayoutCollector::class);
        if (!$collector instanceof PayoutCollector) {
            throw new \RuntimeException('Configured payout collector does not implement PayoutCollector.');
        }

        return $collector;
    }

    public static function makeProductMediaService(ContainerInterface $container): ProductMediaService
    {
        return new ProductMediaService(
            $container->get(ProductRepository::class),
            $container->get(VariantRepository::class),
            $container->get(ProductMediaRepository::class),
            self::tenantResolver($container),
            self::makeBlobRepository($container)
        );
    }

    /**
     * Soft-resolved: `glueful/framework`'s blob subsystem is always present as a
     * class, but only bound in the container when uploads are enabled. Media attach
     * fails 422 (not a crash) when this returns null.
     */
    private static function makeBlobRepository(ContainerInterface $container): ?BlobRepository
    {
        if (!$container->has(BlobRepository::class)) {
            return null;
        }

        $blobs = $container->get(BlobRepository::class);

        return $blobs instanceof BlobRepository ? $blobs : null;
    }

    public static function makeDownloadService(ContainerInterface $container): DownloadService
    {
        return new DownloadService(
            $container->get(ProductRepository::class),
            $container->get(VariantRepository::class),
            $container->get(DownloadRepository::class),
            self::tenantResolver($container),
            self::makeBlobRepository($container)
        );
    }

    public static function makeCategoryService(ContainerInterface $container): CategoryService
    {
        return new CategoryService(
            $container->get(CategoryRepository::class),
            $container->get(ProductRepository::class),
            self::tenantResolver($container),
            self::makeBlobRepository($container)
        );
    }

    public static function makeTagService(ContainerInterface $container): TagService
    {
        return new TagService(
            $container->get(TagRepository::class),
            $container->get(ProductRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeAttributeService(ContainerInterface $container): AttributeService
    {
        return new AttributeService(
            $container->get(AttributeRepository::class),
            $container->get(ProductRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeReviewService(ContainerInterface $container): ReviewService
    {
        return new ReviewService(
            $container->get(ReviewRepository::class),
            $container->get(ProductRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeInventoryService(ContainerInterface $container): InventoryService
    {
        return new InventoryService(
            $container->get(StockRepository::class),
            self::tenantResolver($container),
            $container->get(VariantRepository::class),
            $container->get(ProductRepository::class),
            $container->get(SellerRepository::class),
            $container->get(SellerWebhookOutboxPublisher::class),
            $container->get(MarketplaceMode::class)
        );
    }

    public static function makeDiscountService(ContainerInterface $container): DiscountService
    {
        return new DiscountService(
            $container->get(DiscountRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeCartService(ContainerInterface $container): CartService
    {
        return new CartService(
            $container->get(CartRepository::class),
            $container->get(VariantRepository::class),
            $container->get(ProductRepository::class),
            $container->get(StockRepository::class),
            $container->get(DiscountRepository::class),
            $container->get(PricingEngine::class),
            self::tenantResolver($container),
            $container->get(AddonRepository::class),
            $container->get(ShippingClassRepository::class)
        );
    }

    public static function makeAddonService(ContainerInterface $container): AddonService
    {
        return new AddonService(
            $container->get(AddonRepository::class),
            $container->get(ProductRepository::class),
            self::tenantResolver($container)
        );
    }

    /**
     * The `ShippingRateProvider::class` default (design spec §4): a thin
     * delegator that routes Db-vs-config per quote. Not registered as a
     * standalone service in its own right -- {@see DbShippingRateProvider} and
     * {@see ConfigShippingRateProvider} are only ever composed here, mirroring
     * {@see self::makeTenantResolver()}'s "construct the concrete decorator
     * inline" style rather than adding extra DI surface for internals nothing
     * else consumes. An app that rebinds `ShippingRateProvider::class`
     * replaces this whole chain outright (its DI definition wins).
     */
    public static function makeShippingRateProvider(ContainerInterface $container): ShippingRateProvider
    {
        $db = new DbShippingRateProvider(
            $container->get(ShippingZoneRepository::class),
            self::tenantResolver($container)
        );

        return new DelegatingShippingRateProvider($db, new ConfigShippingRateProvider());
    }

    /**
     * The `TaxCalculator::class` default (design spec §4): a thin delegator
     * that routes Db-vs-flat-rate per quote, mirroring
     * {@see self::makeShippingRateProvider()}'s "construct the concrete
     * decorator inline" style. An app that rebinds `TaxCalculator::class`
     * replaces this whole chain outright (its DI definition wins).
     */
    public static function makeTaxCalculator(ContainerInterface $container): TaxCalculator
    {
        $db = new DbTaxCalculator(
            $container->get(TaxRateRepository::class),
            self::tenantResolver($container)
        );

        return new DelegatingTaxCalculator($db, new FlatRateTaxCalculator());
    }

    public static function makeCheckoutService(ContainerInterface $container): CheckoutService
    {
        return new CheckoutService(
            $container->get(CartService::class),
            $container->get(DiscountRepository::class),
            $container->get(DiscountService::class),
            $container->get(StockRepository::class),
            $container->get(PricingEngine::class),
            $container->get(ShippingRateProvider::class),
            $container->get(TaxCalculator::class),
            $container->get(OrderNumberGenerator::class),
            $container->get(OrderRepository::class),
            $container->get(DownloadRepository::class),
            self::makePaymentCollector($container),
            self::tenantResolver($container),
            $container->get(MarketplaceMode::class),
            $container->get(SellerRepository::class),
            $container->get(ProductRepository::class),
            $container->get(SellerOrderRepository::class),
            webhooks: $container->get(SellerWebhookOutboxPublisher::class)
        );
    }

    public static function makePaymentCollector(ContainerInterface $container): PaymentCollector
    {
        $collector = $container->has(PaymentCollector::class)
            ? $container->get(PaymentCollector::class)
            : new ManualPaymentCollector();

        if (!$collector instanceof PaymentCollector) {
            throw new \RuntimeException('Configured payment collector does not implement PaymentCollector.');
        }

        return $collector;
    }

    /**
     * A bound {@see CommerceTenantResolution} host seam always wins, regardless
     * of `commerce.tenancy.enabled` -- checked first, before the sentinel/shared
     * selection below. The adapter re-reads the seam on every `tenantUuid()`
     * call (never captures/latches a value), so a host that mutates its
     * resolution logic at runtime is reflected immediately. When the seam is
     * NOT bound, the branches below are the original 1.2.x selection,
     * unchanged, for byte-for-byte compatibility with existing installs.
     */
    public static function makeTenantResolver(
        ContainerInterface $container,
        ApplicationContext $context
    ): CurrentTenantResolver {
        if ($container->has(CommerceTenantResolution::class)) {
            $seam = $container->get(CommerceTenantResolution::class);
            if (!$seam instanceof CommerceTenantResolution) {
                throw new \RuntimeException(
                    'Configured CommerceTenantResolution seam does not implement CommerceTenantResolution.'
                );
            }

            return new class ($seam) implements CurrentTenantResolver {
                public function __construct(private readonly CommerceTenantResolution $seam)
                {
                }

                public function tenantUuid(ApplicationContext $context): string
                {
                    return $this->seam->tenantUuid($context);
                }
            };
        }

        if (!(bool) config($context, 'commerce.tenancy.enabled', false)) {
            return new SentinelTenantResolver();
        }

        if (!$container->has(CurrentTenantResolver::class)) {
            throw new \RuntimeException(
                'commerce.tenancy.enabled requires a bound CurrentTenantResolver (install glueful/tenancy).'
            );
        }

        $tenantResolver = $container->get(CurrentTenantResolver::class);
        if (!$tenantResolver instanceof CurrentTenantResolver) {
            throw new \RuntimeException('Configured tenant resolver does not implement CurrentTenantResolver.');
        }

        return new FailClosedTenantResolver($tenantResolver);
    }

    public static function makeRefundService(ContainerInterface $container): RefundService
    {
        return new RefundService(
            $container->get(OrderRepository::class),
            $container->get(RefundRepository::class),
            $container->get(StockRepository::class),
            self::tenantResolver($container),
            self::makeRefundCollector($container),
            $container->get(MarketplaceRefundGuard::class),
            $container->get(LedgerPostingService::class),
            $container->get(SellerRepository::class),
            $container->get(SellerWebhookOutboxPublisher::class)
        );
    }

    public static function makeDownloadGrantService(ContainerInterface $container): DownloadGrantService
    {
        return new DownloadGrantService(
            $container->get(OrderRepository::class),
            $container->get(DownloadGrantRepository::class)
        );
    }

    /**
     * Soft-resolved, same pattern as {@see self::makeBlobRepository()}: an optional
     * host-provided {@see BlobPublicUrlProvider} is used when bound, otherwise the
     * signer falls back to the request's own scheme/host (design spec §4.1).
     */
    public static function makeDownloadUrlSigner(ContainerInterface $container): DownloadUrlSigner
    {
        $publicUrlProvider = null;
        if ($container->has(BlobPublicUrlProvider::class)) {
            $resolved = $container->get(BlobPublicUrlProvider::class);
            $publicUrlProvider = $resolved instanceof BlobPublicUrlProvider ? $resolved : null;
        }

        return new DownloadUrlSigner(self::makeBlobRepository($container), $publicUrlProvider);
    }

    public static function makeDownloadAccessService(ContainerInterface $container): DownloadAccessService
    {
        return new DownloadAccessService(
            $container->get(OrderRepository::class),
            $container->get(DownloadGrantRepository::class),
            $container->get(DownloadUrlSigner::class)
        );
    }

    public static function makeCommerceDownloadBlobPolicy(ContainerInterface $container): CommerceDownloadBlobPolicy
    {
        return new CommerceDownloadBlobPolicy($container->get(ApplicationContext::class));
    }

    private static function makeRefundCollector(ContainerInterface $container): ?RefundCollector
    {
        if (!$container->has(RefundCollector::class)) {
            return null;
        }

        $collector = $container->get(RefundCollector::class);
        if (!$collector instanceof RefundCollector) {
            throw new \RuntimeException('Configured refund collector does not implement RefundCollector.');
        }

        return $collector;
    }

    public static function makeExpiryService(ContainerInterface $container): ExpiryService
    {
        return new ExpiryService(
            $container->get(OrderRepository::class),
            $container->get(StockRepository::class),
            self::tenantResolver($container),
            $container->get(SellerOrderRepository::class),
            $container->get(SellerWebhookOutboxPublisher::class)
        );
    }

    public static function makeOrderPaymentConfirmationHandler(
        ContainerInterface $container
    ): OrderPaymentConfirmationHandler {
        return new OrderPaymentConfirmationHandler(
            $container->get(OrderRepository::class),
            $container->get(OrderPaymentService::class),
            self::tenantResolver($container)
        );
    }

    public static function makeProductController(ContainerInterface $container): ProductController
    {
        return new ProductController(
            $container->get(ApplicationContext::class),
            $container->get(ProductRepository::class),
            $container->get(VariantRepository::class),
            self::tenantResolver($container),
            $container->get(ProductMediaRepository::class),
            $container->get(CategoryRepository::class),
            $container->get(TagRepository::class),
            $container->get(AttributeRepository::class),
            $container->get(ProductChildrenRepository::class),
            $container->get(AddonRepository::class),
            $container->get(ShippingClassRepository::class)
        );
    }

    public static function makeCategoryController(ContainerInterface $container): CategoryController
    {
        return new CategoryController(
            $container->get(ApplicationContext::class),
            $container->get(CategoryRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeReviewController(ContainerInterface $container): ReviewController
    {
        return new ReviewController(
            $container->get(ApplicationContext::class),
            $container->get(ReviewService::class)
        );
    }

    public static function makeCartController(ContainerInterface $container): CartController
    {
        return new CartController(
            $container->get(ApplicationContext::class),
            $container->get(CartService::class)
        );
    }

    public static function makeCheckoutController(ContainerInterface $container): CheckoutController
    {
        return new CheckoutController(
            $container->get(ApplicationContext::class),
            $container->get(CartService::class),
            $container->get(CheckoutService::class),
            $container->get(AddressBookRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeOrderController(ContainerInterface $container): OrderController
    {
        return new OrderController(
            $container->get(ApplicationContext::class),
            $container->get(OrderRepository::class),
            $container->get(CheckoutService::class),
            self::tenantResolver($container),
            $container->get(RefundRepository::class)
        );
    }

    public static function makeDownloadLinkController(ContainerInterface $container): DownloadLinkController
    {
        return new DownloadLinkController(
            $container->get(ApplicationContext::class),
            $container->get(DownloadGrantRepository::class),
            $container->get(DownloadAccessService::class)
        );
    }

    public static function makeAdminProductController(ContainerInterface $container): AdminProductController
    {
        return new AdminProductController(
            $container->get(ApplicationContext::class),
            $container->get(CatalogService::class),
            $container->get(ProductRepository::class),
            $container->get(VariantRepository::class),
            self::tenantResolver($container),
            $container->get(ShippingClassRepository::class)
        );
    }

    public static function makeAdminStockController(ContainerInterface $container): AdminStockController
    {
        return new AdminStockController(
            $container->get(ApplicationContext::class),
            $container->get(InventoryService::class)
        );
    }

    public static function makeAdminDiscountController(ContainerInterface $container): AdminDiscountController
    {
        return new AdminDiscountController(
            $container->get(ApplicationContext::class),
            $container->get(DiscountRepository::class),
            self::tenantResolver($container),
            $container->get(DiscountService::class)
        );
    }

    public static function makeAdminOrderController(ContainerInterface $container): AdminOrderController
    {
        return new AdminOrderController(
            $container->get(ApplicationContext::class),
            $container->get(OrderRepository::class),
            $container->get(StockRepository::class),
            $container->get(OrderPaymentService::class),
            self::tenantResolver($container),
            $container->get(RefundRepository::class),
            $container->get(SellerIdentityProvider::class),
            $container->get(SellerOrderRepository::class),
            $container->get(SellerOrderFulfillmentService::class),
            $container->get(SellerWebhookOutboxPublisher::class)
        );
    }

    public static function makeAdminRefundController(ContainerInterface $container): AdminRefundController
    {
        return new AdminRefundController(
            $container->get(ApplicationContext::class),
            $container->get(OrderRepository::class),
            $container->get(RefundRepository::class),
            $container->get(RefundService::class),
            self::tenantResolver($container)
        );
    }

    public static function makeAdminPayoutController(ContainerInterface $container): AdminPayoutController
    {
        return new AdminPayoutController(
            $container->get(ApplicationContext::class),
            $container->get(PayoutService::class),
            $container->get(AdjustmentService::class),
            self::tenantResolver($container),
            $container->get(PayoutAccountService::class)
        );
    }

    public static function makeProviderChargebackListener(ContainerInterface $container): ProviderChargebackListener
    {
        return new ProviderChargebackListener(
            $container->get(ApplicationContext::class),
            $container->get(ChargebackService::class)
        );
    }

    public static function makeAdminReserveController(ContainerInterface $container): AdminReserveController
    {
        return new AdminReserveController(
            $container->get(ApplicationContext::class),
            $container->get(ReservePolicyService::class),
            $container->get(ChargebackService::class),
            $container->get(ReserveService::class),
            $container->get(AdjustmentService::class),
            $container->get(SellerBalanceService::class),
            $container->get(SellerRepository::class),
            $container->get(MarketplaceMode::class),
            self::tenantResolver($container)
        );
    }

    public static function makeAdminMediaController(ContainerInterface $container): AdminMediaController
    {
        return new AdminMediaController(
            $container->get(ApplicationContext::class),
            $container->get(ProductMediaService::class)
        );
    }

    public static function makeAdminDownloadController(ContainerInterface $container): AdminDownloadController
    {
        return new AdminDownloadController(
            $container->get(ApplicationContext::class),
            $container->get(DownloadService::class)
        );
    }

    public static function makeAdminCategoryController(ContainerInterface $container): AdminCategoryController
    {
        return new AdminCategoryController(
            $container->get(ApplicationContext::class),
            $container->get(CategoryService::class)
        );
    }

    public static function makeAdminTagController(ContainerInterface $container): AdminTagController
    {
        return new AdminTagController(
            $container->get(ApplicationContext::class),
            $container->get(TagService::class)
        );
    }

    public static function makeAdminAttributeController(ContainerInterface $container): AdminAttributeController
    {
        return new AdminAttributeController(
            $container->get(ApplicationContext::class),
            $container->get(AttributeService::class)
        );
    }

    public static function makeAdminAddonController(ContainerInterface $container): AdminAddonController
    {
        return new AdminAddonController(
            $container->get(ApplicationContext::class),
            $container->get(AddonService::class)
        );
    }

    public static function makeAdminReviewController(ContainerInterface $container): AdminReviewController
    {
        return new AdminReviewController(
            $container->get(ApplicationContext::class),
            $container->get(ReviewService::class)
        );
    }

    public static function makeAdminGrantController(ContainerInterface $container): AdminGrantController
    {
        return new AdminGrantController(
            $container->get(ApplicationContext::class),
            $container->get(DownloadGrantRepository::class),
            $container->get(OrderRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeAdminCustomerController(ContainerInterface $container): AdminCustomerController
    {
        return new AdminCustomerController(
            $container->get(ApplicationContext::class),
            $container->get(CustomerAggregationRepository::class),
            $container->get(OrderRepository::class),
            self::tenantResolver($container),
            self::makeUserProvider($container),
            $container->get(AddressBookRepository::class)
        );
    }

    public static function makeAdminReportController(ContainerInterface $container): AdminReportController
    {
        return new AdminReportController(
            $container->get(ApplicationContext::class),
            $container->get(SalesReportRepository::class),
            self::tenantResolver($container),
            $container->get(ProductSalesReportRepository::class),
            $container->get(CustomerReportRepository::class),
            $container->get(StockReportRepository::class)
        );
    }

    public static function makeAdminMarketplaceFinancialController(
        ContainerInterface $container
    ): AdminMarketplaceFinancialController {
        return new AdminMarketplaceFinancialController(
            $container->get(ApplicationContext::class),
            $container->get(SellerBalanceService::class),
            $container->get(LedgerRepository::class),
            $container->get(SellerFinancialReportRepository::class),
            $container->get(SellerRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeAddressBookService(ContainerInterface $container): AddressBookService
    {
        return new AddressBookService(
            $container->get(AddressBookRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeAccountAddressController(ContainerInterface $container): AccountAddressController
    {
        return new AccountAddressController(
            $container->get(ApplicationContext::class),
            $container->get(AddressBookService::class)
        );
    }

    public static function makeShippingZoneService(ContainerInterface $container): ShippingZoneService
    {
        return new ShippingZoneService(
            $container->get(ShippingZoneRepository::class),
            $container->get(ShippingClassRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeAdminShippingZoneController(
        ContainerInterface $container
    ): AdminShippingZoneController {
        return new AdminShippingZoneController(
            $container->get(ApplicationContext::class),
            $container->get(ShippingZoneService::class)
        );
    }

    public static function makeShippingClassService(ContainerInterface $container): ShippingClassService
    {
        return new ShippingClassService(
            $container->get(ShippingClassRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeAdminShippingClassController(
        ContainerInterface $container
    ): AdminShippingClassController {
        return new AdminShippingClassController(
            $container->get(ApplicationContext::class),
            $container->get(ShippingClassService::class)
        );
    }

    public static function makeTaxRateService(ContainerInterface $container): TaxRateService
    {
        return new TaxRateService(
            $container->get(TaxRateRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeAdminTaxRateController(ContainerInterface $container): AdminTaxRateController
    {
        return new AdminTaxRateController(
            $container->get(ApplicationContext::class),
            $container->get(TaxRateService::class)
        );
    }

    public static function makeSellerService(ContainerInterface $container): SellerService
    {
        return new SellerService(
            $container->get(SellerRepository::class),
            $container->get(SellerMembershipRepository::class),
            $container->get(SellerLifecycleEventRepository::class),
            null,
            $container->get(CommissionPolicyService::class),
            $container->get(SellerWebhookEndpointRepository::class),
            $container->get(SellerWebhookDeliveryRepository::class)
        );
    }

    public static function makeSellerMembershipService(ContainerInterface $container): SellerMembershipService
    {
        return new SellerMembershipService(
            $container->get(SellerRepository::class),
            $container->get(SellerMembershipRepository::class),
            $container->get(SellerRoleAuthority::class)
        );
    }

    public static function makeSellerApiKeyScopeValidator(ContainerInterface $container): SellerApiKeyScopeValidator
    {
        return new SellerApiKeyScopeValidator($container->get(SellerRoleAuthority::class));
    }

    public static function makeSellerApiKeyService(ContainerInterface $container): SellerApiKeyService
    {
        return new SellerApiKeyService(
            $container->get(SellerRepository::class),
            $container->get(SellerMembershipRepository::class),
            $container->get(SellerApiKeyRepository::class),
            $container->get(SellerRoleAuthority::class),
            $container->get(SellerApiKeyScopeValidator::class)
        );
    }

    public static function makeSellerApiKeyAuthorizer(ContainerInterface $container): SellerApiKeyAuthorizer
    {
        return new SellerApiKeyAuthorizer($container->get(SellerApiKeyRepository::class));
    }

    public static function makeSellerWebhookSecretService(ContainerInterface $container): SellerWebhookSecretService
    {
        return new SellerWebhookSecretService(
            $container->get(SellerWebhookEndpointRepository::class),
            $container->get(EncryptionService::class)
        );
    }

    public static function makeSellerWebhookEndpointService(
        ContainerInterface $container
    ): SellerWebhookEndpointService {
        return new SellerWebhookEndpointService(
            $container->get(SellerRepository::class),
            $container->get(SellerMembershipRepository::class),
            $container->get(SellerWebhookEndpointRepository::class),
            $container->get(SellerWebhookDeliveryRepository::class),
            $container->get(SellerRoleAuthority::class),
            $container->get(SellerWebhookSecretService::class),
            new SafeOutboundTargetResolver()
        );
    }

    public static function makeSellerAttributionService(ContainerInterface $container): SellerAttributionService
    {
        return new SellerAttributionService(
            $container->get(MarketplaceWorkspaceLock::class),
            $container->get(SellerRepository::class),
            $container->get(ProductRepository::class),
            null,
            $container->get(SellerWebhookOutboxPublisher::class)
        );
    }

    /**
     * MV5c-2 Task 4 (design spec §2.4): the shared publisher every real v1
     * state-change transaction captures through. Every collaborator here is
     * itself either shared elsewhere in this container ({@see SellerRepository},
     * {@see SellerWebhookEndpointRepository}, {@see SellerWebhookDeliveryRepository})
     * or newly registered above ({@see SellerWebhookEventRepository},
     * {@see SellerWebhookPayloadProjector}) -- {@see MarketplaceMode} is a
     * stateless config-reader constructed inline, mirroring
     * {@see self::makeTenantResolver()}'s own "no extra DI surface for a
     * dependency-free internal" convention.
     */
    public static function makeSellerWebhookOutboxPublisher(ContainerInterface $container): SellerWebhookOutboxPublisher
    {
        return new SellerWebhookOutboxPublisher(
            new MarketplaceMode(),
            $container->get(SellerRepository::class),
            $container->get(SellerWebhookEndpointRepository::class),
            $container->get(SellerWebhookEventRepository::class),
            $container->get(SellerWebhookDeliveryRepository::class),
            $container->get(SellerWebhookPayloadProjector::class)
        );
    }

    public static function makeSellerWebhookDeliveryService(ContainerInterface $container): SellerWebhookDeliveryService
    {
        return new SellerWebhookDeliveryService(
            $container->get(SellerRepository::class),
            $container->get(SellerWebhookEndpointRepository::class),
            $container->get(SellerWebhookDeliveryRepository::class),
            $container->get(SellerWebhookEventRepository::class),
            $container->get(SellerWebhookSecretService::class),
            $container->get(Client::class)
        );
    }

    public static function makeMarketplaceActivationService(
        ContainerInterface $container
    ): MarketplaceActivationService {
        return new MarketplaceActivationService(
            $container->get(MarketplaceWorkspaceLock::class),
            $container->get(SellerRepository::class),
            $container->get(ProductRepository::class)
        );
    }

    public static function makeMarketplaceAdminController(ContainerInterface $container): MarketplaceAdminController
    {
        return new MarketplaceAdminController(
            $container->get(ApplicationContext::class),
            $container->get(SellerService::class),
            $container->get(SellerMembershipService::class),
            self::tenantResolver($container),
            $container->get(MarketplaceActivationService::class),
            $container->get(SellerAttributionService::class),
            $container->get(CommissionPolicyService::class),
            $container->get(MarketplaceMode::class),
            $container->get(SellerLifecycleEventRepository::class)
        );
    }

    public static function makeSellerMemberMiddleware(ContainerInterface $container): SellerMemberMiddleware
    {
        return new SellerMemberMiddleware(
            $container->get(ApplicationContext::class),
            $container->get(SellerRepository::class),
            $container->get(SellerMembershipRepository::class),
            $container->get(SellerRoleAuthority::class),
            $container->get(MarketplaceMode::class),
            self::tenantResolver($container),
            $container->get(SellerApiKeyAuthorizer::class)
        );
    }

    public static function makeInteractiveSessionMiddleware(ContainerInterface $container): InteractiveSessionMiddleware
    {
        return new InteractiveSessionMiddleware();
    }

    public static function makeSellerApiKeyController(ContainerInterface $container): SellerApiKeyController
    {
        return new SellerApiKeyController(
            $container->get(ApplicationContext::class),
            $container->get(SellerApiKeyService::class),
            $container->get(SellerApiKeyRepository::class),
            self::tenantResolver($container)
        );
    }

    /**
     * MV5c-2 Task 7 (design spec §2.10/§5): a REAL {@see SellerWebhookController}
     * wired against the REAL {@see SellerWebhookEndpointService}/
     * {@see SellerWebhookEndpointRepository}/{@see SellerWebhookDeliveryRepository}/
     * {@see SellerWebhookDeliveryService} stack -- every collaborator is already
     * shared elsewhere in this container -- mirroring
     * {@see self::makeSellerApiKeyController()}'s identical shape.
     */
    public static function makeSellerWebhookController(ContainerInterface $container): SellerWebhookController
    {
        return new SellerWebhookController(
            $container->get(ApplicationContext::class),
            $container->get(SellerWebhookEndpointService::class),
            $container->get(SellerWebhookEndpointRepository::class),
            $container->get(SellerWebhookDeliveryRepository::class),
            $container->get(SellerWebhookDeliveryService::class),
            self::tenantResolver($container)
        );
    }

    public static function makeSellerCatalogController(ContainerInterface $container): SellerCatalogController
    {
        return new SellerCatalogController(
            $container->get(ApplicationContext::class),
            $container->get(CatalogService::class)
        );
    }

    public static function makeSellerInventoryController(ContainerInterface $container): SellerInventoryController
    {
        return new SellerInventoryController(
            $container->get(ApplicationContext::class),
            $container->get(InventoryService::class)
        );
    }

    public static function makeSellerMembershipController(ContainerInterface $container): SellerMembershipController
    {
        return new SellerMembershipController(
            $container->get(ApplicationContext::class),
            $container->get(SellerMembershipService::class),
            $container->get(SellerMembershipRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeSellerOrderFulfillmentService(
        ContainerInterface $container
    ): SellerOrderFulfillmentService {
        return new SellerOrderFulfillmentService(
            $container->get(OrderRepository::class),
            $container->get(SellerOrderRepository::class),
            $container->get(SellerRepository::class),
            $container->get(SellerWebhookOutboxPublisher::class)
        );
    }

    public static function makeSellerOrderService(ContainerInterface $container): SellerOrderService
    {
        return new SellerOrderService(
            $container->get(SellerOrderRepository::class),
            $container->get(OrderRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeSellerOrderController(ContainerInterface $container): SellerOrderController
    {
        return new SellerOrderController(
            $container->get(ApplicationContext::class),
            $container->get(SellerOrderService::class),
            $container->get(SellerOrderFulfillmentService::class),
            self::tenantResolver($container)
        );
    }

    public static function makeSellerFinancialController(ContainerInterface $container): SellerFinancialController
    {
        return new SellerFinancialController(
            $container->get(ApplicationContext::class),
            $container->get(SellerFinancialReportRepository::class),
            $container->get(SellerBalanceService::class),
            $container->get(PayoutRepository::class),
            $container->get(MarketplaceMode::class),
            self::tenantResolver($container),
            $container->get(PayoutAccountRepository::class),
            $container->get(ReserveService::class)
        );
    }

    /**
     * Soft-resolved, same pattern as {@see self::makeBlobRepository()}: bound
     * unconditionally in production (core's `NullUserProvider` fallback), but
     * NOT bound at all in this test suite's lightweight containers unless a
     * test explicitly injects one -- both cases must degrade to "no
     * enrichment", never a crash.
     */
    private static function makeUserProvider(ContainerInterface $container): ?UserProviderInterface
    {
        if (!$container->has(UserProviderInterface::class)) {
            return null;
        }

        $resolved = $container->get(UserProviderInterface::class);

        return $resolved instanceof UserProviderInterface ? $resolved : null;
    }

    public static function makeOrderMailListener(ContainerInterface $container): OrderMailListener
    {
        return new OrderMailListener(
            $container->get(ApplicationContext::class),
            $container->get(CommerceMailer::class),
            $container->get(DownloadGrantService::class)
        );
    }

    public function getDescription(): string
    {
        return 'Commerce primitives: products, carts, orders, inventory, discounts, checkout, payments.';
    }

    public function getName(): string
    {
        return 'Commerce';
    }

    public function getVersion(): string
    {
        return self::composerVersion();
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('commerce', require __DIR__ . '/../config/commerce.php');
    }

    public function boot(ApplicationContext $context): void
    {
        try {
            $container = container($context);
            if ($container->has(TenantTableRegistry::class)) {
                $registry = $container->get(TenantTableRegistry::class);
                if ($registry instanceof TenantTableRegistry) {
                    $registry->register(\Glueful\Extensions\Commerce\Support\DiagnosticsReport::tenantTables());
                }
            }
        } catch (\Throwable $e) {
            error_log('[Commerce] Failed to register tenant tables: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e;
            }
        }

        try {
            $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        } catch (\Throwable $e) {
            error_log('[Commerce] Failed to load routes: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e;
            }
        }

        try {
            $this->loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::DEPENDENT, 'glueful/commerce');
        } catch (\Throwable $e) {
            error_log('[Commerce] Failed to register migrations: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e;
            }
        }

        try {
            $container = container($context);
            if ($container->has(EventService::class)) {
                $events = $container->get(EventService::class);
                if ($events instanceof EventService) {
                    $listener = $container->get(OrderMailListener::class);
                    $events->addListener(OrderPlaced::class, [$listener, 'onOrderPlaced']);
                    $events->addListener(OrderPaid::class, [$listener, 'onOrderPaid']);
                    $events->addListener(OrderFulfilled::class, [$listener, 'onOrderFulfilled']);
                    $events->addListener(RefundCompleted::class, [$listener, 'onRefundCompleted']);
                    $events->addListener(OrderNoteAdded::class, [$listener, 'onOrderNoteAdded']);

                    // MV5a Task 16 (design spec §2.4/§2.11/§5): the chargeback listener is
                    // added directly to EventService here too -- the extension has no
                    // config/events.php, mirroring the OrderMailListener block above exactly.
                    // Payvia dispatches ProviderChargebackEvent through the STRICT
                    // EventService::dispatchOrFail() (framework 1.71.0), never the
                    // fault-isolated dispatch() the mail events above use -- this
                    // registration is deliberately on the SAME ListenerProvider either way,
                    // since dispatchOrFail() only changes how the dispatcher walks the SAME
                    // listener list, not where listeners are registered.
                    $chargebackListener = $container->get(ProviderChargebackListener::class);
                    $events->addListener(
                        ProviderChargebackEvent::class,
                        [$chargebackListener, 'onProviderChargeback']
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log('[Commerce] Failed to register mail listeners: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e;
            }
        }

        try {
            $container = container($context);
            // Framework composition seam (design spec §5, Task 1): register
            // commerce's blob backstop as a NAMED contributor to the shared
            // BlobAccessPolicyRegistry -- never bind the shared BlobAccessPolicy
            // contract itself, so a host application's own primary policy (e.g.
            // Thallo's TenantBlobPolicy) always stays AND-composed alongside this
            // one. Guarded by has(): a framework build without Task 1's seam
            // simply has no BlobAccessPolicyRegistry bound, so this becomes a
            // no-op and DiagnosticsReport reports 'unavailable'.
            //
            // Also guarded by the registry's OWN has('commerce.downloads') (T6
            // review finding): BlobAccessPolicyRegistry::register() throws
            // \LogicException on a duplicate id, and a second boot() against a
            // registry instance that survives across boots (e.g. a re-boot in
            // the same process) would otherwise crash outside a 'production'
            // APP_ENV. Registration is idempotent: the first boot wins, later
            // boots are no-ops against an already-registered id.
            if ($container->has(BlobAccessPolicyRegistry::class)) {
                $registry = $container->get(BlobAccessPolicyRegistry::class);
                if ($registry instanceof BlobAccessPolicyRegistry && !$registry->has('commerce.downloads')) {
                    $registry->register('commerce.downloads', $container->get(CommerceDownloadBlobPolicy::class));
                }
            }
        } catch (\Throwable $e) {
            error_log('[Commerce] Failed to register blob access policy: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e;
            }
        }

        try {
            $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
                'slug' => 'commerce',
                'name' => $this->getName(),
                'version' => $this->getVersion(),
                'description' => $this->getDescription(),
            ]);
        } catch (\Throwable $e) {
            error_log('[Commerce] Failed to register extension metadata: ' . $e->getMessage());
        }

        try {
            $this->discoverCommands('Glueful\\Extensions\\Commerce\\Console', __DIR__ . '/Console');
        } catch (\Throwable $e) {
            error_log('[Commerce] Failed to discover commands: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e;
            }
        }
    }

    private function bootEnv(): string
    {
        return (string) ($_ENV['APP_ENV'] ?? (getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production'));
    }

    private static function tenantResolver(ContainerInterface $container): CurrentTenantResolver
    {
        $context = $container->get(ApplicationContext::class);
        if (!$context instanceof ApplicationContext) {
            throw new \RuntimeException('ApplicationContext service is required to resolve commerce tenancy.');
        }

        return self::makeTenantResolver($container, $context);
    }
}
