<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\StaleCatalogRevisionException;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\DTOs\AdminProductListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\BulkPriceData;
use Glueful\Extensions\Commerce\Http\DTOs\BulkStatusData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateProductData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateVariantData;
use Glueful\Extensions\Commerce\Http\DTOs\ProductVariantData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductChildrenData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateProductData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateVariantData;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyException;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Orders\DraftLineEligibility;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminProductController
{
    use ReadsAdminInput;
    use ResolvesActor;

    public function __construct(
        private ApplicationContext $context,
        private ?CatalogService $catalog = null,
        private ?ProductRepository $products = null,
        private ?VariantRepository $variants = null,
        private ?CurrentTenantResolver $tenants = null,
        private ?ShippingClassRepository $shippingClasses = null,
        // Appended (1.6.0): only the list summary reads stock from this controller.
        private ?StockRepository $stockRepository = null,
        // Appended (admin-order-creation cycle 2, Task 9): the ORDER-level marketplace
        // decision behind `admin_draft_ineligible_reason`'s `marketplace` case.
        private ?MarketplaceMode $marketplace = null,
    ) {
        $this->catalog ??= app($context, CatalogService::class);
        $this->products ??= app($context, ProductRepository::class);
        $this->variants ??= app($context, VariantRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        $this->shippingClasses ??= new ShippingClassRepository();
        // Direct construction (like $shippingClasses above), NOT app(): this repository is a
        // plain reader here and container-resolving it would make every caller that constructs
        // this controller without a bound StockRepository fail at construction time.
        $this->stockRepository ??= new StockRepository();
        // Same reasoning as $stockRepository above: zero-collaborator direct construction
        // keeps every pre-existing positional-arg call site working unchanged, and
        // `installEnabled()` is config-only so a non-marketplace install pays nothing.
        $this->marketplace ??= new MarketplaceMode();
    }

    #[ApiOperation(summary: 'List products', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Products retrieved')]
    public function index(AdminProductListQuery $query, Request $request): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $tenant = $this->tenants->tenantUuid($this->context);
        $result = $this->products->paginatedForAdmin(
            $this->context,
            $tenant,
            array_filter(
                ['status' => $query->status, 'type' => $query->type, 'q' => $query->q],
                static fn (mixed $value): bool => $value !== null
            ),
            $page,
            $perPage
        );

        return Response::paginated(
            $this->withDraftEligibility($tenant, $this->withListSummary($tenant, $result['items'])),
            $result['total'],
            $page,
            $perPage,
            null,
            'Products retrieved'
        );
    }

    /**
     * The AUTHORITATIVE draft-eligibility surface (admin-order-creation cycle 2,
     * Task 9, design spec §2.3): two additive keys per row,
     * `admin_draft_eligible: bool` and the nullable closed
     * `admin_draft_ineligible_reason` (`digital | marketplace | unavailable`).
     *
     * ONE PATH, not two agreeing implementations: both this projection and the
     * draft line endpoint's own rejection call {@see DraftLineEligibility}, so the
     * SPA can disable an ineligible search result before any mutation and the
     * write authority independently rechecks with the identical vocabulary. There
     * is no client-side reconstruction and no "try the line endpoint and see"
     * discovery fallback.
     *
     * TWO reads for a WHOLE page, never one per row:
     *  - the ORDER-level marketplace decision (`installEnabled() && activeFor()`)
     *    is computed ONCE, exactly as `CheckoutService::placeOrder()` does it, and
     *    the config-only master switch short-circuits it entirely on a
     *    non-marketplace install;
     *  - buyer availability's only page-visible failure mode -- a product owned by
     *    a NON-ACTIVE seller, which is precisely
     *    `ProductRepository::BUYER_SELLER_ACTIVE_SQL`'s predicate -- is resolved
     *    with ONE batched `commerce_sellers` read, and only when the master switch
     *    is on and the page actually contains seller-owned rows. (Tombstoned rows
     *    are already excluded by `paginatedForAdmin()`, and product `status` is
     *    deliberately NOT part of buyer availability -- see
     *    `ProductRepository::findBuyerAvailableByUuid()` -- so a `draft`-status
     *    product stays addable here exactly as it is addable to a cart.)
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function withDraftEligibility(string $tenant, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $installed = $this->marketplace->installEnabled($this->context);
        $partitioning = $installed && $this->marketplace->activeFor($this->context, $tenant);
        $activeSellers = $installed ? $this->activeSellerUuids($tenant, $items) : null;

        return array_map(static function (array $row) use ($activeSellers, $partitioning): array {
            $sellerUuid = isset($row['seller_uuid']) ? (string) $row['seller_uuid'] : '';
            $buyerAvailable = $activeSellers === null
                || $sellerUuid === ''
                || isset($activeSellers[$sellerUuid]);
            $reason = DraftLineEligibility::forProductRow($row, $buyerAvailable, $partitioning);

            return $row + [
                'admin_draft_eligible' => $reason === null,
                'admin_draft_ineligible_reason' => $reason,
            ];
        }, $items);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,true> active seller uuids present on this page
     */
    private function activeSellerUuids(string $tenant, array $items): array
    {
        $sellerUuids = [];
        foreach ($items as $row) {
            $sellerUuid = isset($row['seller_uuid']) ? (string) $row['seller_uuid'] : '';
            if ($sellerUuid !== '') {
                $sellerUuids[$sellerUuid] = true;
            }
        }
        if ($sellerUuids === []) {
            return [];
        }

        $rows = db($this->context)->table('commerce_sellers')
            ->select(['uuid'])
            ->where('tenant_uuid', '=', $tenant)
            ->where('status', '=', 'active')
            ->whereIn('uuid', array_keys($sellerUuids))
            ->get();

        $active = [];
        foreach ($rows as $row) {
            $active[(string) $row['uuid']] = true;
        }

        return $active;
    }

    /**
     * Attaches the price/stock summary an admin catalog list needs (1.6.0) using TWO batched
     * reads for the WHOLE page -- {@see VariantRepository::forProducts()} and
     * {@see StockRepository::stockProjectionsForProducts()} -- never one pair per row.
     *
     * Six additive keys per row; nothing existing is reshaped:
     * `variant_count`, `price_from`, `price_to`, `currency`, `stock_quantity`, `stock_tracked`.
     *
     * Price spans ALL variants, not just active ones: this is the merchant's own catalog view
     * ("what did I price this at?"), and the row's own `status` column already answers
     * sellability. `price_from` equals `price_to` for a single-variant product, so a caller can
     * render one amount or a range without inspecting variants itself.
     *
     * Stock honesty (Global Constraints: never fabricate quantities). Unlike the per-product
     * `products.stock.index` read -- which throws {@see StockIntegrityException} on a missing
     * `commerce_stock` row -- a BROWSING list must not 500 an entire catalog page because one
     * variant drifted, so absence reports as `stock_quantity: null` and
     * {@see \Glueful\Extensions\Commerce\Support\DiagnosticsReport}'s `variants_missing_stock`
     * remains the loud channel. `null` means "untracked or unknown", NEVER zero: one variant
     * missing its row makes the whole product's quantity unknown rather than a silently-short sum.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function withListSummary(string $tenant, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $productUuids = array_map(static fn (array $row): string => (string) $row['uuid'], $items);
        $variantsByProduct = $this->variants->forProducts($this->context, $tenant, $productUuids);
        $stockByProduct = $this->stockRepository->stockProjectionsForProducts(
            $this->context,
            $tenant,
            $productUuids
        );

        return array_map(static function (array $row) use ($variantsByProduct, $stockByProduct): array {
            $uuid = (string) $row['uuid'];
            $variants = $variantsByProduct[$uuid] ?? [];
            $stockRows = $stockByProduct[$uuid] ?? [];

            $prices = array_map(static fn (array $v): int => (int) ($v['price'] ?? 0), $variants);
            $cheapest = $prices === [] ? null : min($prices);
            $currency = null;
            foreach ($variants as $variant) {
                if ((int) ($variant['price'] ?? 0) === $cheapest && isset($variant['currency'])) {
                    $currency = (string) $variant['currency'];
                    break;
                }
            }

            $tracked = false;
            $anyMissing = false;
            $quantity = 0;
            foreach ($stockRows as $stockRow) {
                if ($stockRow['tracked'] === null) {
                    $anyMissing = true;
                    continue;
                }
                if ($stockRow['tracked'] === true) {
                    $tracked = true;
                    $quantity += (int) ($stockRow['quantity'] ?? 0);
                }
            }

            return $row + [
                'variant_count' => count($variants),
                'price_from' => $cheapest,
                'price_to' => $prices === [] ? null : max($prices),
                'currency' => $currency,
                'stock_quantity' => ($tracked && !$anyMissing) ? $quantity : null,
                'stock_tracked' => $tracked,
            ];
        }, $items);
    }

    #[ApiOperation(summary: 'Get a product', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Product retrieved')]
    #[ApiResponse(404, description: 'Product not found')]
    public function show(Request $request, string $uuid): Response
    {
        return Response::success($this->product($uuid), 'Product retrieved');
    }

    #[ApiOperation(summary: 'Create a product', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Product created')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(CreateProductData $input, Request $request): Response
    {
        try {
            return Response::created(
                $this->catalog->createProduct($this->context, $this->createProductPayload($input)),
                'Product created'
            );
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update a product', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateProductData::class)]
    #[ApiResponse(200, description: 'Product updated')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $uuid): Response
    {
        try {
            // A body-supplied `seller_uuid` (e.g. echoed back from a prior GET --
            // every admin product payload carries the column since migration 011,
            // marketplace switch or not) is silently dropped, never rejected,
            // mirroring {@see \Glueful\Extensions\Commerce\Http\Seller\SellerCatalogController::update()}.
            // Attribution only ever moves via the dedicated platform
            // adoption/transfer operation; CatalogService::updateProduct()'s
            // unconditional 422 guard stays intact as the backstop for any
            // caller that reaches it with the key still present.
            //
            // A commission_kind/bps/fixed field (design spec §2.3, MV3 Task 4) is
            // operator-only and IS allowed here -- CatalogService::updateProduct()
            // routes it through CommissionPolicyService for validation + a
            // durable audit row, using the resolved actor below.
            $changes = $this->input($request);
            unset($changes['seller_uuid']);

            $this->catalog->updateProduct($this->context, $uuid, $changes, $this->actorUuid($request));

            return Response::success($this->product($uuid), 'Product updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (CommissionPolicyException $e) {
            return Response::validation(['commission' => $e->getMessage()]);
        }
    }

    #[ApiOperation(summary: 'Delete a product', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Product deleted')]
    #[ApiResponse(404, description: 'Product not found')]
    public function destroy(Request $request, string $uuid): Response
    {
        $this->catalog->deleteProduct($this->context, $uuid);

        return Response::noContent();
    }

    #[ApiOperation(summary: 'Create a product variant', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Variant created')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function storeVariant(CreateVariantData $input, Request $request, string $uuid): Response
    {
        try {
            return Response::created(
                $this->catalog->createVariant($this->context, $uuid, $this->variantPayload($input)),
                'Variant created'
            );
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update a product variant', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateVariantData::class)]
    #[ApiResponse(200, description: 'Variant updated')]
    #[ApiResponse(404, description: 'Variant not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function updateVariant(Request $request, string $uuid): Response
    {
        try {
            $this->catalog->updateVariant($this->context, $uuid, $this->input($request));
            $tenant = $this->tenants->tenantUuid($this->context);
            $variant = $this->variants->findByUuid($this->context, $tenant, $uuid);
            if ($variant === null) {
                throw new NotFoundException('Resource not found.');
            }
            $variant = $this->shippingClasses->attachResolvedSlug($this->context, $tenant, $variant);

            return Response::success($variant, 'Variant updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Bulk update product status', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Bulk product status update processed')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function bulkStatus(BulkStatusData $input, Request $request): Response
    {
        $applied = [];
        $failed = [];

        foreach ($input->uuids as $uuid) {
            try {
                $this->catalog->setProductStatus($this->context, $uuid, $input->status);
                $applied[] = $uuid;
            } catch (NotFoundException) {
                $failed[] = ['uuid' => $uuid, 'reason' => 'not_found'];
            }
        }

        return Response::success(
            ['applied' => $applied, 'failed' => $failed],
            'Bulk product status update processed'
        );
    }

    #[ApiOperation(summary: 'Bulk update variant prices', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Bulk variant price update processed')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function bulkPrice(BulkPriceData $input, Request $request): Response
    {
        $applied = [];
        $failed = [];

        foreach ($input->items as $item) {
            try {
                $this->catalog->setVariantPrice($this->context, $item->uuid, $item->price);
                $applied[] = $item->uuid;
            } catch (NotFoundException) {
                $failed[] = ['uuid' => $item->uuid, 'reason' => 'not_found'];
            }
        }

        return Response::success(
            ['applied' => $applied, 'failed' => $failed],
            'Bulk variant price update processed'
        );
    }

    #[ApiOperation(summary: 'Set the children attached to a grouped product', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Product children updated')]
    #[ApiResponse(404, description: 'Product not found')]
    #[ApiResponse(409, description: 'Product was modified by another request')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function setChildren(SetProductChildrenData $input, Request $request, string $uuid): Response
    {
        try {
            $children = $this->catalog->setProductChildren(
                $this->context,
                $uuid,
                $input->child_uuids ?? [],
                $input->expected_revision
            );

            return Response::success($children, 'Product children updated');
        } catch (StaleCatalogRevisionException $e) {
            return Response::error($e->getMessage(), 409);
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'List the children attached to a grouped product', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Product children retrieved')]
    #[ApiResponse(404, description: 'Product not found')]
    public function childrenForProductIndex(Request $request, string $uuid): Response
    {
        return Response::success(
            $this->catalog->childrenForProduct($this->context, $uuid),
            'Product children retrieved'
        );
    }

    /**
     * Deliberately uncaught: a {@see \Glueful\Extensions\Commerce\Inventory\StockIntegrityException}
     * from {@see CatalogService::stockForProduct()} must bubble to the
     * framework's default 500-class handler, never be mapped to a 4xx (Global
     * Constraints: "the read fails loudly").
     */
    #[ApiOperation(summary: 'List the stock levels for a product\'s variants', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Product stock retrieved')]
    #[ApiResponse(404, description: 'Product not found')]
    public function stockForProductIndex(Request $request, string $uuid): Response
    {
        return Response::success(
            $this->catalog->stockForProduct($this->context, $uuid),
            'Product stock retrieved'
        );
    }

    /** @return array<string,mixed> */
    private function product(string $uuid): array
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $product = $this->products->findLiveByUuid($this->context, $tenant, $uuid);
        if ($product === null) {
            throw new NotFoundException('Resource not found.');
        }
        $product['variants'] = $this->shippingClasses->attachResolvedSlugs(
            $this->context,
            $tenant,
            $this->variants->forProduct($this->context, $tenant, $uuid)
        );

        return $product;
    }

    /** @return array<string,mixed> */
    private function createProductPayload(CreateProductData $input): array
    {
        return array_filter([
            'slug' => $input->slug,
            'name' => $input->name,
            'description' => $input->description,
            'type' => $input->type,
            'status' => $input->status,
            'options' => $input->options,
            'metadata' => $input->metadata,
            'tax_class' => $input->tax_class,
            'variants' => array_map(
                fn (ProductVariantData $variant): array => $this->productVariantPayload($variant),
                $input->variants
            ),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string,mixed> */
    private function variantPayload(CreateVariantData $input): array
    {
        return array_filter([
            'sku' => $input->sku,
            'option_values' => $input->option_values,
            'price' => $input->price,
            'compare_at_price' => $input->compare_at_price,
            'currency' => $input->currency,
            'status' => $input->status,
            'shipping_class_uuid' => $input->shipping_class_uuid,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string,mixed> */
    private function productVariantPayload(ProductVariantData $input): array
    {
        return array_filter([
            'sku' => $input->sku,
            'option_values' => $input->option_values,
            'price' => $input->price,
            'compare_at_price' => $input->compare_at_price,
            'currency' => $input->currency,
            'status' => $input->status,
            'shipping_class_uuid' => $input->shipping_class_uuid,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
