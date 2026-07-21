<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Events\ProductDeleted;
use Glueful\Extensions\Commerce\Events\ProductSlugChanged;
use Glueful\Extensions\Commerce\Events\StorefrontCatalogChanged;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyService;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyResolver;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\SellerAttributionException;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Support\Money;
use Glueful\Extensions\Commerce\Support\OpenVocabularySlug;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Product/variant CRUD plus product-type gating and grouped-product children.
 *
 * Type widening (physical|digital|external|grouped) is validated BEFORE variant
 * validation on every create path: external/grouped products are NOT purchasable
 * (variant creation is rejected for them, both the initial `variants` array on
 * createProduct() and createVariant()/storeVariant), so the type decision must be
 * made first or the pre-existing unconditional "at least one variant" rule would
 * wrongly reject them. external additionally requires a validated http/https
 * `metadata.external_url` at the point its type is set (create, or an update that
 * sets type to external) AND on every subsequent update that touches `metadata`
 * while the product's EFFECTIVE type (the incoming `type` key if present, else
 * the current stored type) is external — including a metadata-only PATCH that
 * carries no `type` key at all, so an already-external product can never be left
 * with a stripped/invalid `external_url` by a partial update.
 *
 * Type is immutable once it would strand data: a type-changing update is rejected
 * whenever the product has variants, a children relationship in EITHER direction
 * (parent or child), or a cart/order line referencing one of its variants —
 * checked independently, not merely inferred from variant presence, so the guard
 * stays correct if a future change ever decouples them.
 *
 * Children set-list (setProductChildren) follows the same claim-then-re-read
 * discipline as CategoryService::setProductCategories / AttributeService::
 * setProductAttributes: claims the parent PRODUCT first (`catalog_revision` — a
 * failed claim is a non-revealing 404, the URL's primary resource), then the union
 * of every currently-attached and proposed child uuid (sorted) via the SAME
 * `catalog_revision` claim primitive (children are themselves `commerce_products`
 * rows). Only after every claim succeeds does the mutation re-read the parent's
 * type and each proposed child's fresh type/tenant — a proposed child that failed
 * to claim, or whose fresh type is not physical/digital, is a 422 on the field,
 * never a crash or a stale-state attach.
 *
 * **Marketplace attribution policy (design spec §2.2/§2.7, MV1 Task 3).**
 * `$marketplaceMode`/`$workspaceLock`/`$sellers` are APPENDED OPTIONAL
 * collaborators so every pre-MV1 direct-construction call site (tests
 * included) stays source-compatible. They are wired together by
 * `CommerceServiceProvider` in production; the null default exists ONLY for
 * legacy direct construction. Whenever ANY of the three is null,
 * {@see self::createProduct()} behaves EXACTLY as master-off, regardless of
 * config -- there is no partial/degraded marketplace mode. See
 * {@see self::createProduct()} for the full policy.
 */
final class CatalogService
{
    private const PURCHASABLE_TYPES = ['physical', 'digital'];

    public function __construct(
        private ProductRepository $products,
        private VariantRepository $variants,
        private CurrentTenantResolver $tenants,
        private ?StockRepository $stock = null,
        private ?ProductChildrenRepository $children = null,
        private ?ShippingClassRepository $shippingClasses = null,
        private ?MarketplaceMode $marketplaceMode = null,
        private ?MarketplaceWorkspaceLock $workspaceLock = null,
        private ?SellerRepository $sellers = null,
        private ?CommissionPolicyService $commissionPolicy = null,
        private ?StorefrontCatalogChangeDispatcher $catalogEvents = null,
    ) {
        $this->stock ??= new StockRepository();
        $this->children ??= new ProductChildrenRepository();
        $this->shippingClasses ??= new ShippingClassRepository();
    }

    /**
     * MASTER-OFF FAST PATH (design spec §2.2/§4): `installEnabled()` --
     * config only, zero database reads -- is checked FIRST, before any other
     * work. OFF (or any marketplace collaborator missing): $sellerUuid MUST
     * be null (422 otherwise) and the pre-MV1 path runs with ZERO
     * marketplace-table queries -- byte-identical to a pre-MV1 install.
     *
     * ON: the SAME transaction that inserts the product FIRST claims
     * {@see MarketplaceWorkspaceLock} (design spec §4 lock order --
     * "workspace-settings claim first"), THEN reads `activeFor()`. An ACTIVE
     * workspace REQUIRES $sellerUuid (422 when null); an INACTIVE workspace
     * REQUIRES it be null (422 otherwise -- operators use the dedicated
     * {@see \Glueful\Extensions\Commerce\Marketplace\SellerAttributionService}
     * adoption operation instead). An attributed create claims the target
     * seller and validates it exists, is in-tenant, and is `active` (422 for
     * unknown/cross-tenant, {@see SellerAttributionException} / 409 for
     * suspended/closed) BEFORE the product insert. Workspace claim, seller
     * claim, and product insert all participate in ONE transaction -- see
     * {@see self::resolveCreateAttribution()}.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createProduct(ApplicationContext $context, array $input, ?string $sellerUuid = null): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $installEnabled = $this->marketplaceMode !== null
            && $this->workspaceLock !== null
            && $this->sellers !== null
            && $this->marketplaceMode->installEnabled($context);

        if (!$installEnabled && $sellerUuid !== null) {
            throw ValidationException::forField(
                'seller_uuid',
                'seller_uuid cannot be set: marketplace mode is not installed.'
            );
        }

        $storeCurrency = $this->storeCurrency($context);

        // Type is decided and validated BEFORE variants: it governs whether
        // validateVariants() even runs (see class docblock).
        $type = (string) ($input['type'] ?? 'physical');
        $this->assertValidType($type);
        if ($type === 'external') {
            $this->assertExternalMetadata($input['metadata'] ?? null);
        }

        $status = (string) ($input['status'] ?? 'draft');
        $this->assertValidStatus($status);

        $variants = $this->planCreationVariants($context, $tenant, $type, $input['variants'] ?? [], $storeCurrency);
        $slug = $this->requiredString($input, 'slug');
        $taxClass = $this->normalizeTaxClass($input['tax_class'] ?? null);

        // Including-deleted (design spec Layer 6 §2): a tombstone keeps
        // reserving its slug, so colliding with one is the same slug-in-use 422
        // as colliding with a live product -- never a raw unique-constraint error.
        if ($this->products->findIncludingDeletedBySlug($context, $tenant, $slug) !== null) {
            throw ValidationException::forField('slug', 'Slug already in use.');
        }

        $productUuid = Utils::generateNanoID();

        $created = [];
        db($context)->transaction(
            function () use (
                $context,
                $tenant,
                $productUuid,
                $slug,
                $input,
                $type,
                $status,
                $taxClass,
                $variants,
                $storeCurrency,
                $installEnabled,
                $sellerUuid,
                &$created
            ): void {
                $resolvedSellerUuid = $installEnabled
                    ? $this->resolveCreateAttribution($context, $tenant, $sellerUuid)
                    : null;

                // Slice-2 Task 1 (design spec §4): the pack-owned slug
                // reservation authority is soft-consumed -- Commerce never
                // binds an implementation -- and, when bound, MUST run
                // inside THIS SAME transaction, before the product row
                // itself is inserted below, so an authority throw rolls
                // back the whole create (variants/stock included).
                $authority = $this->slugAuthority($context);
                if ($authority !== null) {
                    $authority->prepareCreate($context, $tenant, $productUuid, $slug);
                }

                $this->products->insert($context, [
                    'uuid' => $productUuid,
                    'tenant_uuid' => $tenant,
                    'slug' => $slug,
                    'name' => $this->requiredString($input, 'name'),
                    'description' => $input['description'] ?? null,
                    'type' => $type,
                    'status' => $status,
                    'options' => $input['options'] ?? null,
                    'metadata' => $input['metadata'] ?? null,
                    'tax_class' => $taxClass,
                    'seller_uuid' => $resolvedSellerUuid,
                ]);

                $this->claimAndInsertCreatedVariants(
                    $context,
                    $tenant,
                    $productUuid,
                    $variants,
                    $storeCurrency,
                    $type,
                    $created
                );

                $this->catalogEvents?->dispatch(
                    $context,
                    $tenant,
                    $productUuid,
                    StorefrontCatalogChanged::REASON_PRODUCT_CREATED
                );
            }
        );

        $product = $this->products->findLiveByUuid($context, $tenant, $productUuid);
        if ($product === null) {
            throw new \RuntimeException('Created product could not be reloaded.');
        }

        $product['variants'] = $this->shippingClasses->attachResolvedSlugs(
            $context,
            $tenant,
            array_values(array_filter($created))
        );

        return $product;
    }

    /**
     * The marketplace attribution decision for a create (design spec §2.2/
     * §4 lock order), reached ONLY when the install master switch is on --
     * see {@see self::createProduct()}. MUST run as the FIRST statement
     * inside the SAME transaction as the product insert: claiming
     * {@see MarketplaceWorkspaceLock} BEFORE reading `activeFor()` is what
     * makes activation-vs-create deterministic (a create commits first and
     * is seen by activation, or activation commits first and this create
     * must supply attribution -- design spec §2.2).
     *
     * ACTIVE requires $sellerUuid (422 when null, "operators use the
     * dedicated adoption operation" for an INACTIVE workspace instead);
     * INACTIVE requires it be null (422 otherwise).
     */
    private function resolveCreateAttribution(
        ApplicationContext $context,
        string $tenant,
        ?string $sellerUuid
    ): ?string {
        $this->workspaceLock->claim($context, $tenant);

        if ($this->marketplaceMode->activeFor($context, $tenant)) {
            if ($sellerUuid === null) {
                throw ValidationException::forField(
                    'seller_uuid',
                    'seller_uuid is required: this workspace has activated marketplace mode.'
                );
            }

            return $this->claimAndValidateAttributionSeller($context, $tenant, $sellerUuid);
        }

        if ($sellerUuid !== null) {
            throw ValidationException::forField(
                'seller_uuid',
                'seller_uuid cannot be set while marketplace mode is inactive; '
                    . 'use the seller adoption operation instead.'
            );
        }

        return null;
    }

    /**
     * Claims and validates the target seller for an attributed create
     * (design spec §2.7/§4 lock order): an unknown or cross-tenant
     * seller_uuid is a 422 {@see ValidationException} (the same
     * "referenced resource" classification {@see self::claimShippingClassesForCreate()}
     * uses); a `suspended`/`closed` seller is a 409 {@see SellerAttributionException}.
     * MUST run after {@see MarketplaceWorkspaceLock::claim()} and before the
     * product insert, in the SAME transaction (design spec §4 lock order).
     */
    private function claimAndValidateAttributionSeller(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid
    ): string {
        $this->sellers->claimRevision($context, $tenant, $sellerUuid);

        $seller = $this->sellers->findByUuid($context, $tenant, $sellerUuid);
        if ($seller === null) {
            throw ValidationException::forField(
                'seller_uuid',
                'seller_uuid must reference an existing seller in this tenant.'
            );
        }

        if ((string) $seller['status'] !== 'active') {
            throw new SellerAttributionException(
                "Seller is '{$seller['status']}'; products cannot be attributed to it."
            );
        }

        return $sellerUuid;
    }

    /**
     * Claims every distinct shipping class the batch of $variants references
     * (see {@see self::claimShippingClassesForCreate()}) and inserts each
     * variant row, all inside the SAME transaction createProduct() opened --
     * extracted purely to keep that transaction's closure body under the
     * house line-length limit.
     *
     * @param list<array<string,mixed>> $variants
     * @param list<array<string,mixed>|null> $created
     */
    private function claimAndInsertCreatedVariants(
        ApplicationContext $context,
        string $tenant,
        string $productUuid,
        array $variants,
        string $storeCurrency,
        string $type,
        array &$created
    ): void {
        $this->claimShippingClassesForCreate($context, $tenant, array_map(
            static fn (array $variant): ?string => $variant['shipping_class_uuid'] ?? null,
            $variants
        ));

        foreach ($variants as $position => $variant) {
            $variantUuid = Utils::generateNanoID();
            $this->variants->insert($context, [
                'uuid' => $variantUuid,
                'tenant_uuid' => $tenant,
                'product_uuid' => $productUuid,
                'sku' => (string) $variant['sku'],
                'option_values' => $variant['option_values'] ?? [],
                'price' => (int) $variant['price'],
                'compare_at_price' => $variant['compare_at_price'] ?? null,
                'currency' => $storeCurrency,
                'position' => $position,
                'status' => (string) ($variant['status'] ?? 'active'),
                'shipping_class_uuid' => $variant['shipping_class_uuid'] ?? null,
            ]);

            $this->stock->ensureRow($context, $tenant, $variantUuid, $type === 'physical');

            $created[] = $this->variants->findByUuid($context, $tenant, $variantUuid);
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createVariant(ApplicationContext $context, string $productUuid, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $storeCurrency = $this->storeCurrency($context);
        $product = $this->products->findLiveByUuid($context, $tenant, $productUuid);
        if ($product === null) {
            throw ValidationException::forField('product_uuid', 'Product not found.');
        }

        $type = (string) ($product['type'] ?? 'physical');
        if (!in_array($type, self::PURCHASABLE_TYPES, true)) {
            throw ValidationException::forField(
                'product_uuid',
                "Cannot add variants to a '{$type}' product."
            );
        }

        $variants = $this->validateVariants($context, $tenant, [$input], $storeCurrency);
        $variant = $variants[0];
        $variantUuid = Utils::generateNanoID();

        db($context)->transaction(
            function () use ($context, $tenant, $productUuid, $variant, $variantUuid, $storeCurrency): void {
                if (!$this->products->claimCatalogRevision($context, $tenant, $productUuid)) {
                    throw ValidationException::forField('product_uuid', 'Product not found.');
                }

                $product = $this->products->findLiveByUuid($context, $tenant, $productUuid);
                if ($product === null) {
                    throw ValidationException::forField('product_uuid', 'Product not found.');
                }
                $type = (string) ($product['type'] ?? 'physical');
                if (!in_array($type, self::PURCHASABLE_TYPES, true)) {
                    throw ValidationException::forField(
                        'product_uuid',
                        "Cannot add variants to a '{$type}' product."
                    );
                }

                $this->claimShippingClassesForCreate($context, $tenant, [$variant['shipping_class_uuid'] ?? null]);

                $this->variants->insert($context, [
                    'uuid' => $variantUuid,
                    'tenant_uuid' => $tenant,
                    'product_uuid' => $productUuid,
                    'sku' => (string) $variant['sku'],
                    'option_values' => $variant['option_values'] ?? [],
                    'price' => (int) $variant['price'],
                    'compare_at_price' => $variant['compare_at_price'] ?? null,
                    'currency' => $storeCurrency,
                    'position' => count($this->variants->forProduct($context, $tenant, $productUuid)),
                    'status' => (string) ($variant['status'] ?? 'active'),
                    'shipping_class_uuid' => $variant['shipping_class_uuid'] ?? null,
                ]);

                $this->stock->ensureRow($context, $tenant, $variantUuid, $type === 'physical');

                $this->catalogEvents?->dispatch(
                    $context,
                    $tenant,
                    $productUuid,
                    StorefrontCatalogChanged::REASON_VARIANT_CHANGED
                );
            }
        );

        $created = $this->variants->findByUuid($context, $tenant, $variantUuid);
        if ($created === null) {
            throw new \RuntimeException('Created variant could not be reloaded.');
        }

        return $this->shippingClasses->attachResolvedSlug($context, $tenant, $created);
    }

    /**
     * Every patch uses the guarded product primitive: one transaction, one
     * `catalog_revision` claim, one live re-read, and one final write. This keeps
     * ordinary metadata edits from racing product deletion as well as serializing
     * status and type changes against the same row claim used by bulk operations.
     *
     * Ordinary update NEVER touches `seller_uuid` (design spec §2.7): a
     * `seller_uuid` key present ANYWHERE in $changes is rejected with 422 at
     * THIS layer -- the backstop for any caller that reaches here with the
     * key still set, mirroring
     * {@see \Glueful\Extensions\Commerce\Marketplace\SellerService::update()}'s
     * `slug`-immutability guard. This check runs before any claim/query, so
     * it costs nothing extra on the master-off fast path. Both HTTP entry
     * points ({@see \Glueful\Extensions\Commerce\Http\Admin\AdminProductController::update()}
     * and {@see \Glueful\Extensions\Commerce\Http\Seller\SellerCatalogController::update()})
     * silently drop a body-supplied `seller_uuid` BEFORE calling this method
     * -- a full-object read-modify-write PATCH (GET echoing the column back
     * unchanged) must round-trip cleanly, not 422 -- so this guard is only
     * ever tripped by a caller that bypasses both controllers. Attribution is
     * reached only via the create policy or the dedicated
     * {@see \Glueful\Extensions\Commerce\Marketplace\SellerAttributionService}
     * adoption/transfer operation.
     *
     * Commission policy (design spec §2.3, MV3 Task 4): $changes touching ANY
     * of {@see CommissionPolicyResolver::FIELDS} is routed through the
     * injected {@see CommissionPolicyService} (validated, applied, AND
     * audited in its own transaction) BEFORE the remaining, non-commission
     * fields (if any) run through the ordinary guarded patch below. Operator
     * only -- there is no `$sellerUuid` parameter here at all, so this path
     * is reached exclusively by the platform admin product-update surface;
     * {@see self::updateSellerProduct()} rejects commission fields outright
     * before ever delegating here.
     *
     * @param array<string,mixed> $changes
     */
    public function updateProduct(
        ApplicationContext $context,
        string $productUuid,
        array $changes,
        ?string $actorUuid = null
    ): void {
        if (array_key_exists('seller_uuid', $changes)) {
            throw ValidationException::forField(
                'seller_uuid',
                'seller_uuid cannot be changed via update; use the marketplace adoption/transfer operation instead.'
            );
        }

        $tenant = $this->tenants->tenantUuid($context);

        $commission = CommissionPolicyResolver::extractFromChanges($changes);
        if ($commission !== null) {
            if ($this->commissionPolicy === null) {
                throw ValidationException::forField(
                    'commission_kind',
                    'Commission policy management is not available.'
                );
            }
            $this->commissionPolicy->setProduct($context, $tenant, $productUuid, $commission, $actorUuid);
        }

        $remaining = CommissionPolicyResolver::withoutFields($changes);
        if ($commission === null || $remaining !== []) {
            $this->applyGuardedProductPatch($context, $tenant, $productUuid, $remaining);
        }
    }

    /**
     * Seller-scoped product read (design spec §2.8, MV1 Task 4): tenant AND
     * seller are both part of the read predicate at the SERVICE layer -- a
     * live product that belongs to a DIFFERENT seller is the exact same
     * non-revealing 404 {@see NotFoundException} as a product that doesn't
     * exist at all, never a distinguishable "wrong seller" response.
     *
     * @return array<string,mixed>
     */
    public function sellerProduct(ApplicationContext $context, string $sellerUuid, string $productUuid): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $product = $this->requireSellerOwnedProduct($context, $tenant, $sellerUuid, $productUuid);

        $product['variants'] = $this->shippingClasses->attachResolvedSlugs(
            $context,
            $tenant,
            $this->variants->forProduct($context, $tenant, $productUuid)
        );

        return $product;
    }

    /**
     * Seller-scoped product listing (design spec §2.8, MV1 Task 4): the
     * `seller_uuid` predicate is baked into {@see ProductRepository::paginatedForSeller()}'s
     * count and row queries -- a seller can never see another seller's
     * products through this read, regardless of filters.
     *
     * @param array<string,mixed> $filters 'status'/'type' (exact) and/or 'q' (literal substring on name)
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function listSellerProducts(
        ApplicationContext $context,
        string $sellerUuid,
        array $filters,
        int $page,
        int $perPage
    ): array {
        $tenant = $this->tenants->tenantUuid($context);

        return $this->products->paginatedForSeller($context, $tenant, $sellerUuid, $filters, $page, $perPage);
    }

    /**
     * Seller-scoped product update (design spec §2.8, MV1 Task 4): confirms
     * $productUuid is LIVE and owned by $sellerUuid at the SERVICE layer
     * BEFORE delegating to {@see self::updateProduct()}'s existing guarded
     * patch (which already rejects any `seller_uuid` key present anywhere in
     * $changes). A wrong-seller product uuid is the same non-revealing 404
     * as an unknown one -- never a 403 that would confirm the product exists
     * under a different seller.
     *
     * Commission-field rejection backstop (design spec §2.3, MV3 Task 4):
     * sellers can NEVER set their own commission policy. `SellerCatalogController::update()`
     * already inspects the raw request body and rejects a commission field
     * with a field-specific 422 before it ever reaches this method -- this
     * is the service-level backstop for any caller that bypasses that
     * controller, checked BEFORE the ownership claim so it costs nothing
     * extra on the ordinary (no commission field) path.
     *
     * @param array<string,mixed> $changes
     */
    public function updateSellerProduct(
        ApplicationContext $context,
        string $sellerUuid,
        string $productUuid,
        array $changes
    ): void {
        $this->rejectCommissionFields($changes);

        $tenant = $this->tenants->tenantUuid($context);
        $this->requireSellerOwnedProduct($context, $tenant, $sellerUuid, $productUuid);

        $this->updateProduct($context, $productUuid, $changes);
    }

    /**
     * @param array<string,mixed> $changes
     */
    private function rejectCommissionFields(array $changes): void
    {
        foreach (CommissionPolicyResolver::FIELDS as $field) {
            if (array_key_exists($field, $changes)) {
                throw ValidationException::forField(
                    $field,
                    'Sellers cannot set commission policy; only platform operators may change it.'
                );
            }
        }
    }

    /**
     * Seller-scoped variant create (design spec §2.8, MV1 Task 4): the
     * variant is reached ONLY through its parent product's seller-scoped
     * ownership check -- there is no standalone seller-scoped variant route.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createSellerVariant(
        ApplicationContext $context,
        string $sellerUuid,
        string $productUuid,
        array $input
    ): array {
        $tenant = $this->tenants->tenantUuid($context);
        $this->requireSellerOwnedProduct($context, $tenant, $sellerUuid, $productUuid);

        return $this->createVariant($context, $productUuid, $input);
    }

    /**
     * The shared seller-ownership read {@see self::sellerProduct()},
     * {@see self::updateSellerProduct()}, and {@see self::createSellerVariant()}
     * all funnel through: live + tenant + seller in one predicate.
     *
     * @return array<string,mixed>
     */
    private function requireSellerOwnedProduct(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $productUuid
    ): array {
        $product = $this->products->findLiveByUuid($context, $tenant, $productUuid);
        if ($product === null || (string) ($product['seller_uuid'] ?? '') !== $sellerUuid) {
            throw new NotFoundException('Resource not found.');
        }

        return $product;
    }

    /**
     * Guarded setter (design spec Layer 6 §2/Task 2): delegates to the EXACT
     * SAME whole-patch primitive an ordinary `status`-bearing
     * {@see self::updateProduct()} call reaches, so a bulk status write and a
     * single-resource PATCH can never race each other with different
     * serialization discipline. Never calls updateProduct() itself -- that
     * would double-claim.
     */
    public function setProductStatus(ApplicationContext $context, string $uuid, string $status): void
    {
        $tenant = $this->tenants->tenantUuid($context);
        $this->applyGuardedProductPatch($context, $tenant, $uuid, ['status' => $status]);
    }

    /**
     * Compositional guarded product-patch primitive (design spec Layer 6 §2/
     * Task 2), reached whenever $changes carries a `status` key: ONE
     * transaction that claims `catalog_revision` ONCE (the same row-lock
     * primitive every other product mutation uses -- serializes against a
     * concurrent soft delete or another guarded patch on the SAME row),
     * `findLiveByUuid` re-reads ONCE, validates the ENTIRE patch against that
     * fresh row via {@see self::applyProductPatch()}, then applies ONE final
     * update. A failure at any validation step rolls back the whole
     * transaction -- including the claim -- leaving every field unchanged.
     *
     * @param array<string,mixed> $changes
     */
    private function applyGuardedProductPatch(
        ApplicationContext $context,
        string $tenant,
        string $productUuid,
        array $changes
    ): void {
        db($context)->transaction(function () use ($context, $tenant, $productUuid, $changes): void {
            if (!$this->products->claimCatalogRevision($context, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->products->findLiveByUuid($context, $tenant, $productUuid);
            if ($current === null) {
                throw new NotFoundException('Resource not found.');
            }

            $this->applyProductPatch($context, $tenant, $productUuid, $changes, $current);
        });
    }

    /**
     * Validates $changes against the freshly-read $current row and applies ONE
     * final write -- the shared body BOTH product-patch entry points
     * ({@see self::updateProduct()}'s plain path and
     * {@see self::applyGuardedProductPatch()}) funnel through, so validation
     * and the eventual write can never drift between a guarded and a plain
     * patch.
     *
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $current
     */
    private function applyProductPatch(
        ApplicationContext $context,
        string $tenant,
        string $productUuid,
        array $changes,
        array $current
    ): void {
        /** @var array{0:string,1:string}|null $slugRename [oldSlug, newSlug] -- only set on an actual rename */
        $slugRename = null;

        if (isset($changes['slug'])) {
            // Including-deleted (design spec Layer 6 §2): a tombstone keeps
            // reserving its slug, so a rename colliding with one is the same
            // slug-in-use 422 as colliding with a live product.
            $newSlug = (string) $changes['slug'];
            $existing = $this->products->findIncludingDeletedBySlug($context, $tenant, $newSlug);
            if ($existing !== null && ($existing['uuid'] ?? null) !== $productUuid) {
                throw ValidationException::forField('slug', 'Slug already in use.');
            }

            $oldSlug = (string) ($current['slug'] ?? '');
            if ($newSlug !== $oldSlug) {
                // Slice-2 Task 1 (design spec §4): the pack-owned slug
                // reservation authority is soft-consumed -- Commerce never
                // binds an implementation -- and, when bound, MUST run
                // inside THIS SAME transaction, before the product row
                // itself is updated below, so an authority throw rolls back
                // the whole rename together with everything else this
                // patch touches.
                $authority = $this->slugAuthority($context);
                if ($authority !== null) {
                    $authority->prepareRename($context, $tenant, $productUuid, $oldSlug, $newSlug);
                }

                $slugRename = [$oldSlug, $newSlug];
            }
        }

        if (array_key_exists('status', $changes)) {
            $this->assertValidStatus((string) $changes['status']);
        }

        $touchesType = array_key_exists('type', $changes);
        $touchesMetadata = array_key_exists('metadata', $changes);

        if ($touchesType) {
            $newType = (string) $changes['type'];
            $this->assertValidType($newType);

            $currentType = (string) ($current['type'] ?? 'physical');
            if ($newType !== $currentType && $this->productHasStrandableReferences($context, $tenant, $productUuid)) {
                throw ValidationException::forField(
                    'type',
                    'type cannot change while the product has variants, children, or cart/order references.'
                );
            }
        }

        // Re-validate `metadata.external_url` whenever the update touches
        // `metadata` AND the product's EFFECTIVE type (the incoming `type` key,
        // else the current stored type) is `external` -- covers both a
        // type-change landing on external AND a metadata-only PATCH against an
        // already-external product (the bug this guard closes: such a PATCH
        // could otherwise strip/corrupt `external_url` with no validation at
        // all, since the old code only ever checked this inside the `type`-key
        // branch).
        if ($touchesType || $touchesMetadata) {
            $effectiveType = $touchesType ? (string) $changes['type'] : (string) ($current['type'] ?? 'physical');
            if ($effectiveType === 'external') {
                $metadata = $touchesMetadata ? $changes['metadata'] : ($current['metadata'] ?? null);
                $this->assertExternalMetadata($metadata);
            }
        }

        // tax_class (spec §5): explicit null CLEARS (stored as null -> "standard"
        // at the calculator); an omitted key PRESERVES the current value. Only a
        // non-null value is run through the open-vocabulary normalizer.
        if (array_key_exists('tax_class', $changes)) {
            $changes['tax_class'] = $this->normalizeTaxClass($changes['tax_class']);
        }

        $this->products->update($context, $tenant, $productUuid, $changes);

        if ($slugRename !== null) {
            [$oldSlug, $newSlug] = $slugRename;
            db($context)->afterCommit(function () use ($context, $tenant, $productUuid, $oldSlug, $newSlug): void {
                $this->dispatch($context, new ProductSlugChanged($tenant, $productUuid, $oldSlug, $newSlug));
            });
        }

        $reason = array_key_exists('status', $changes)
            ? StorefrontCatalogChanged::REASON_PRODUCT_STATUS_CHANGED
            : StorefrontCatalogChanged::REASON_PRODUCT_UPDATED;
        $this->catalogEvents?->dispatch($context, $tenant, $productUuid, $reason);
    }

    /**
     * Soft delete (design spec Layer 6 §2): claim `catalog_revision` (the SAME
     * row-lock primitive every other product-scoped mutation uses), post-claim
     * confirm the product is still live, then tombstone it via
     * {@see ProductRepository::markDeleted()}'s affected-row-checked DB-time
     * write -- all in ONE transaction. Variant/stock/media rows are left
     * completely untouched; the tombstoned row keeps reserving its slug (create/
     * rename uniqueness checks deliberately use `findIncludingDeletedBySlug()`).
     * An unknown/cross-tenant product, a losing concurrent racer, and a repeat
     * delete all observe the same non-revealing 404. There is no restore.
     *
     * Commerce-Slice-1 Task 2: once `markDeleted()` succeeds, `ProductDeleted`
     * is registered via `db($context)->afterCommit(...)` from INSIDE this same
     * transaction -- mirroring {@see \Glueful\Extensions\Commerce\Orders\OrderPaymentService::markPaid()}'s
     * `OrderPaid` convention -- so it fires exactly once, only after the
     * successful OUTERMOST commit, even when this call participates in a
     * caller-owned transaction (a savepoint here). Any of the three 404 paths
     * above throws BEFORE this registration is reached, so an unknown/cross-
     * tenant uuid, a repeat delete, and a losing claim racer all dispatch
     * nothing; a caller-forced rollback of an outer transaction discards the
     * promoted callback the same way (see `TransactionManager::rollback()`).
     */
    public function deleteProduct(ApplicationContext $context, string $uuid): void
    {
        $tenant = $this->tenants->tenantUuid($context);

        db($context)->transaction(function () use ($context, $tenant, $uuid): void {
            if (!$this->products->claimCatalogRevision($context, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }

            if ($this->products->findLiveByUuid($context, $tenant, $uuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            if (!$this->products->markDeleted($context, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }

            db($context)->afterCommit(function () use ($context, $tenant, $uuid): void {
                $this->dispatch($context, new ProductDeleted($tenant, $uuid));
            });

            $this->catalogEvents?->dispatch($context, $tenant, $uuid, StorefrontCatalogChanged::REASON_PRODUCT_DELETED);
        });
    }

    /**
     * Fault-isolated event dispatch (design spec/house idiom, mirrored from
     * {@see \Glueful\Extensions\Commerce\Orders\OrderPaymentService::dispatch()}):
     * soft-resolves {@see EventService} and calls the ordinary `dispatch()` --
     * NOT `dispatchOrFail()` -- so a listener failure never threatens the
     * already-committed tombstone; reconciliation is the backstop, per the
     * Task 2 brief.
     */
    private function dispatch(ApplicationContext $context, object $event): void
    {
        $container = container($context);
        if ($container->has(EventService::class)) {
            $container->get(EventService::class)->dispatch($event);
        }
    }

    /**
     * Soft consumption (design spec §4, Slice-2 Task 1): Commerce never
     * binds an implementation of {@see SlugLifecycleAuthority} -- a
     * container-has check, mirroring {@see self::dispatch()}'s
     * {@see EventService} resolution, checked fresh on every call rather
     * than cached at construction -- so an unbound install never even
     * attempts to resolve it and create()/rename() stay byte-identical to
     * pre-Task-1 behavior.
     */
    private function slugAuthority(ApplicationContext $context): ?SlugLifecycleAuthority
    {
        $container = container($context);
        if (!$container->has(SlugLifecycleAuthority::class)) {
            return null;
        }

        $authority = $container->get(SlugLifecycleAuthority::class);

        return $authority instanceof SlugLifecycleAuthority ? $authority : null;
    }

    /**
     * Idempotent ordered set-list replace for a grouped product's children. See
     * class docblock for the full claim/re-read discipline. Currently-attached
     * children no longer proposed are simply detached; submitting the same
     * ordered list twice produces the same resulting content (wholesale replace,
     * not a row-by-row diff — mirrors AttributeService::setProductAttributes()).
     *
     * @param list<string> $childUuids ordered
     * @return list<array<string,mixed>> ordered child product rows
     */
    public function setProductChildren(ApplicationContext $context, string $productUuid, array $childUuids): array
    {
        $tenant = $this->tenants->tenantUuid($context);

        return db($context)->transaction(function () use ($context, $tenant, $productUuid, $childUuids): array {
            if (!$this->products->claimCatalogRevision($context, $tenant, $productUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $parent = $this->products->findLiveByUuid($context, $tenant, $productUuid);
            if ($parent === null) {
                throw new NotFoundException('Resource not found.');
            }
            if (($parent['type'] ?? null) !== 'grouped') {
                throw ValidationException::forField('type', 'Only grouped products can have children.');
            }

            $proposed = $this->normalizeUuidList($childUuids, 'child_uuids');
            foreach ($proposed as $index => $childUuid) {
                if ($childUuid === $productUuid) {
                    throw ValidationException::forField(
                        "child_uuids.{$index}",
                        'A product cannot be its own child.'
                    );
                }
            }

            // Pre-claim snapshot purely to discover which child rows join the
            // claim set below -- never trusted for the physical/digital decision,
            // which only happens after every claim succeeds and each proposed
            // child is re-read fresh (see class docblock).
            $current = $this->children->childUuidsForProduct($context, $productUuid);

            $union = array_values(array_unique(array_merge($current, $proposed)));
            sort($union);

            $claimed = [];
            foreach ($union as $childUuid) {
                $claimed[$childUuid] = $this->products->claimCatalogRevision($context, $tenant, $childUuid);
            }

            foreach ($proposed as $index => $childUuid) {
                if (!($claimed[$childUuid] ?? false)) {
                    throw ValidationException::forField(
                        "child_uuids.{$index}",
                        'child_uuids must reference existing products in this tenant.'
                    );
                }

                $child = $this->products->findLiveByUuid($context, $tenant, $childUuid);
                if ($child === null) {
                    throw ValidationException::forField(
                        "child_uuids.{$index}",
                        'child_uuids must reference existing products in this tenant.'
                    );
                }

                $childType = (string) ($child['type'] ?? 'physical');
                if (!in_array($childType, self::PURCHASABLE_TYPES, true)) {
                    throw ValidationException::forField(
                        "child_uuids.{$index}",
                        "child_uuids must reference physical or digital products (got '{$childType}')."
                    );
                }
            }

            $this->children->replaceChildren($context, $productUuid, $proposed);

            // No dedicated reason exists for a children-set-list change in the
            // closed vocabulary (design spec §9) -- it changes the PARENT
            // product's own storefront representation, so it rides
            // `product.updated` scoped to the parent, the same reason a
            // plain field patch uses.
            $this->catalogEvents?->dispatch(
                $context,
                $tenant,
                $productUuid,
                StorefrontCatalogChanged::REASON_PRODUCT_UPDATED
            );

            return $this->children->childProductsForProduct($context, $tenant, $productUuid);
        });
    }

    /**
     * Every patch claims and live re-reads the parent product before writing the
     * variant. Shipping-class changes additionally use the shared class claim.
     *
     * @param array<string,mixed> $changes
     */
    public function updateVariant(ApplicationContext $context, string $variantUuid, array $changes): void
    {
        $tenant = $this->tenants->tenantUuid($context);
        $storeCurrency = $this->storeCurrency($context);

        if (isset($changes['currency']) && $changes['currency'] !== $storeCurrency) {
            throw ValidationException::forField(
                'currency',
                "Variant currency must equal the store currency ({$storeCurrency})."
            );
        }
        if (isset($changes['price']) && (!is_int($changes['price']) || $changes['price'] < 0)) {
            throw ValidationException::forField('price', 'Price must be a non-negative integer (minor units).');
        }

        if (isset($changes['sku'])) {
            $existing = $this->variants->findBySku($context, $tenant, (string) $changes['sku']);
            if ($existing !== null && ($existing['uuid'] ?? null) !== $variantUuid) {
                throw ValidationException::forField('sku', 'SKU already in use.');
            }
        }

        $this->applyGuardedVariantPatch($context, $tenant, $variantUuid, $changes);
    }

    /**
     * Guarded setter (design spec Layer 6 §2/Task 2): delegates to the EXACT
     * SAME whole-patch primitive an ordinary `price`-bearing
     * {@see self::updateVariant()} call reaches, so a bulk price write and a
     * single-resource PATCH always serialize against the SAME parent-product
     * lock. Never calls updateVariant() itself -- that would double-claim.
     */
    public function setVariantPrice(ApplicationContext $context, string $variantUuid, int $price): void
    {
        if ($price < 0) {
            throw ValidationException::forField('price', 'Price must be a non-negative integer (minor units).');
        }

        $tenant = $this->tenants->tenantUuid($context);
        $this->applyGuardedVariantPatch($context, $tenant, $variantUuid, ['price' => $price]);
    }

    /**
     * Compositional guarded variant-patch primitive (design spec Layer 6 §2/
     * Task 2), used for every variant patch: ONE transaction that resolves variant->product
     * (a pre-claim read, never trusted for a decision), claims the PARENT
     * product's `catalog_revision` ONCE, live re-reads BOTH the parent product
     * (closing the tombstone gap the pre-Task-1 §6 protocol didn't check) and
     * the variant, preserves the §6 sorted current/proposed shipping-class
     * claim protocol (affected-row-checked revision bumps on
     * `commerce_shipping_classes` -- see
     * {@see \Glueful\Extensions\Commerce\Shipping\ShippingClassService}'s class
     * docblock for the full class-delete-vs-variant-assign race analysis) when
     * `shipping_class_uuid` is present, validates the ENTIRE patch, then
     * applies ONE final variant update. A `null` proposed shipping class CLEARS
     * the assignment.
     *
     * @param array<string,mixed> $changes
     */
    private function applyGuardedVariantPatch(
        ApplicationContext $context,
        string $tenant,
        string $variantUuid,
        array $changes
    ): void {
        $hasClassChange = array_key_exists('shipping_class_uuid', $changes);
        $proposedClass = $changes['shipping_class_uuid'] ?? null;
        if ($hasClassChange && $proposedClass !== null && (!is_string($proposedClass) || trim($proposedClass) === '')) {
            throw ValidationException::forField(
                'shipping_class_uuid',
                'shipping_class_uuid must be a non-empty string or null.'
            );
        }

        db($context)->transaction(
            function () use ($context, $tenant, $variantUuid, $changes, $hasClassChange, $proposedClass): void {
                $variant = $this->variants->findByUuid($context, $tenant, $variantUuid);
                if ($variant === null) {
                    throw new NotFoundException('Resource not found.');
                }
                $productUuid = (string) $variant['product_uuid'];

                if (!$this->products->claimCatalogRevision($context, $tenant, $productUuid)) {
                    throw new NotFoundException('Resource not found.');
                }

                if ($this->products->findLiveByUuid($context, $tenant, $productUuid) === null) {
                    throw new NotFoundException('Resource not found.');
                }

                // Post-claim re-read (design spec Layer 6 §2/Task 2): never trust
                // the pre-claim snapshot above for the shipping-class "current"
                // decision below -- a concurrent writer may have committed a
                // different assignment while this transaction waited on the
                // product-row lock.
                $variant = $this->variants->findByUuid($context, $tenant, $variantUuid);
                if ($variant === null) {
                    throw new NotFoundException('Resource not found.');
                }

                if ($hasClassChange) {
                    $current = $variant['shipping_class_uuid'] ?? null;
                    $claimSet = array_values(array_unique(array_filter(
                        [$current, $proposedClass],
                        static fn (mixed $uuid): bool => $uuid !== null
                    )));
                    sort($claimSet);

                    foreach ($claimSet as $classUuid) {
                        $claimed = $this->shippingClasses->claimRevision($context, $tenant, (string) $classUuid);
                        if (!$claimed && $classUuid === $proposedClass) {
                            throw ValidationException::forField(
                                'shipping_class_uuid',
                                'shipping_class_uuid must reference an existing shipping class in this tenant.'
                            );
                        }
                    }

                    // Post-claim re-read: the claim already proved the proposed
                    // class existed in-tenant at claim time, but this mirrors the
                    // house discipline of never deciding on a pre-claim snapshot
                    // alone.
                    if (
                        $proposedClass !== null
                        && $this->shippingClasses->findByUuid($context, $tenant, $proposedClass) === null
                    ) {
                        throw ValidationException::forField(
                            'shipping_class_uuid',
                            'shipping_class_uuid must reference an existing shipping class in this tenant.'
                        );
                    }
                }

                $this->variants->update($context, $tenant, $variantUuid, $changes);

                $this->catalogEvents?->dispatch(
                    $context,
                    $tenant,
                    $productUuid,
                    StorefrontCatalogChanged::REASON_VARIANT_CHANGED
                );
            }
        );
    }

    /**
     * Symmetric CREATE-path counterpart to {@see self::updateVariantShippingClass()}'s
     * shared-claim protocol (reviewer-mandated hardening, T3 follow-up): claims
     * every distinct shipping-class uuid a variant CREATE proposes to reference
     * (sorted, affected-row-checked via
     * {@see \Glueful\Extensions\Commerce\Shipping\ShippingClassRepository::claimRevision()})
     * and re-validates existence post-claim. `validateVariants()`'s plain
     * `findByUuid` existence check alone leaves a TOCTOU gap against a
     * concurrent class DELETE landing between validation and insert -- claiming
     * here closes it, so create/update/delete all serialize against the SAME
     * class row (see {@see \Glueful\Extensions\Commerce\Shipping\ShippingClassService}'s
     * class docblock for the full race analysis). MUST run inside the SAME
     * transaction as the variant insert(s) that reference the claimed class(es);
     * claiming without an atomic insert alongside it would not serialize
     * against anything.
     *
     * @param list<string|null> $classUuids
     */
    private function claimShippingClassesForCreate(ApplicationContext $context, string $tenant, array $classUuids): void
    {
        $claimSet = array_values(array_unique(array_filter(
            $classUuids,
            static fn (mixed $uuid): bool => $uuid !== null
        )));
        sort($claimSet);

        foreach ($claimSet as $classUuid) {
            $claimed = $this->shippingClasses->claimRevision($context, $tenant, (string) $classUuid);
            if (!$claimed || $this->shippingClasses->findByUuid($context, $tenant, (string) $classUuid) === null) {
                throw ValidationException::forField(
                    'shipping_class_uuid',
                    'shipping_class_uuid must reference an existing shipping class in this tenant.'
                );
            }
        }
    }

    private function storeCurrency(ApplicationContext $context): string
    {
        $currency = (string) config($context, 'commerce.currency', 'USD');
        Money::assertValidCurrency($currency);

        return $currency;
    }

    /**
     * @param mixed $raw
     * @return list<array<string,mixed>>
     */
    private function validateVariants(
        ApplicationContext $context,
        string $tenant,
        mixed $raw,
        string $storeCurrency,
    ): array {
        if (!is_array($raw) || $raw === []) {
            throw ValidationException::forField('variants', 'A product needs at least one variant.');
        }

        $variants = array_values($raw);
        $seenSkus = [];
        foreach ($variants as $index => $variant) {
            if (!is_array($variant)) {
                throw ValidationException::forField("variants.{$index}", 'Variant must be an object.');
            }

            if (($variant['currency'] ?? $storeCurrency) !== $storeCurrency) {
                throw ValidationException::forField(
                    "variants.{$index}.currency",
                    "Variant currency must equal the store currency ({$storeCurrency})."
                );
            }

            if (!is_int($variant['price'] ?? null) || $variant['price'] < 0) {
                throw ValidationException::forField(
                    "variants.{$index}.price",
                    'Price must be a non-negative integer (minor units).'
                );
            }

            $sku = (string) ($variant['sku'] ?? '');
            if ($sku === '') {
                throw ValidationException::forField("variants.{$index}.sku", 'SKU is required.');
            }
            if (isset($seenSkus[$sku])) {
                throw ValidationException::forField("variants.{$index}.sku", 'SKU is duplicated in this request.');
            }
            $seenSkus[$sku] = true;
            if ($this->variants->findBySku($context, $tenant, $sku) !== null) {
                throw ValidationException::forField("variants.{$index}.sku", 'SKU already in use.');
            }

            $classUuid = $variant['shipping_class_uuid'] ?? null;
            $classFound = $classUuid === null
                || $this->shippingClasses->findByUuid($context, $tenant, (string) $classUuid) !== null;
            if (!$classFound) {
                throw ValidationException::forField(
                    "variants.{$index}.shipping_class_uuid",
                    'shipping_class_uuid must reference an existing shipping class in this tenant.'
                );
            }
        }

        /** @var list<array<string,mixed>> $variants */
        return $variants;
    }

    /**
     * Zero variants is accepted ONLY for external/grouped products (they are not
     * purchasable — variant creation is rejected for them outright); physical/
     * digital retain the pre-existing at-least-one-variant rule enforced by
     * validateVariants().
     *
     * @param mixed $raw
     * @return list<array<string,mixed>>
     */
    private function planCreationVariants(
        ApplicationContext $context,
        string $tenant,
        string $type,
        mixed $raw,
        string $storeCurrency,
    ): array {
        if (!in_array($type, self::PURCHASABLE_TYPES, true)) {
            if (is_array($raw) && $raw !== []) {
                throw ValidationException::forField(
                    'variants',
                    "'{$type}' products cannot have variants."
                );
            }

            return [];
        }

        return $this->validateVariants($context, $tenant, $raw, $storeCurrency);
    }

    /**
     * tax_class (spec §5): null preserves/means "standard"; a non-null value
     * must match the open-vocabulary rule shared with shipping-class slugs and
     * tax-rate classes (an unmatched-but-well-formed class is allowed and simply
     * taxes at 0 -- existence is never enforced here).
     */
    private function normalizeTaxClass(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return OpenVocabularySlug::normalize((string) $value, 'tax_class');
    }

    private function assertValidType(string $type): void
    {
        if (!ProductType::isValid($type)) {
            throw ValidationException::forField(
                'type',
                'type must be one of: ' . implode(', ', ProductType::all()) . '.'
            );
        }
    }

    private function assertValidStatus(string $status): void
    {
        if (!ProductStatus::isValid($status)) {
            throw ValidationException::forField(
                'status',
                'status must be one of: ' . implode(', ', ProductStatus::all()) . '.'
            );
        }
    }

    /** @param mixed $metadata */
    private function assertExternalMetadata(mixed $metadata): void
    {
        $metadata = is_array($metadata) ? $metadata : [];

        $url = $metadata['external_url'] ?? null;
        if (!is_string($url) || trim($url) === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::forField(
                'metadata.external_url',
                'metadata.external_url is required and must be a valid URL for external products.'
            );
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::forField(
                'metadata.external_url',
                'metadata.external_url must use the http or https scheme.'
            );
        }

        $buttonLabel = $metadata['button_label'] ?? null;
        if ($buttonLabel !== null && !is_string($buttonLabel)) {
            throw ValidationException::forField(
                'metadata.button_label',
                'metadata.button_label must be a string.'
            );
        }
    }

    /**
     * True when changing $productUuid's type would strand data: it has variants,
     * a children relationship in either direction, or a cart/order line
     * referencing one of its variants. Each condition is checked independently
     * (not merely inferred from variant presence) per the class docblock.
     */
    private function productHasStrandableReferences(
        ApplicationContext $context,
        string $tenant,
        string $productUuid
    ): bool {
        $variants = $this->variants->forProduct($context, $tenant, $productUuid);
        $hasVariants = $variants !== [];

        $hasChildRelationship = $this->children->isParentAnywhere($context, $productUuid)
            || $this->children->isChildAnywhere($context, $productUuid);

        $variantUuids = array_map(static fn (array $variant): string => (string) $variant['uuid'], $variants);
        $hasCartOrOrderReference = $variantUuids !== [] && (
            $this->hasLineReference($context, 'commerce_cart_lines', $variantUuids)
            || $this->hasLineReference($context, 'commerce_order_lines', $variantUuids)
        );

        return $hasVariants || $hasChildRelationship || $hasCartOrOrderReference;
    }

    /** @param list<string> $variantUuids */
    private function hasLineReference(ApplicationContext $context, string $table, array $variantUuids): bool
    {
        return db($context)->table($table)->whereIn('variant_uuid', $variantUuids)->count() > 0;
    }

    /**
     * @param mixed $raw
     * @return list<string> unique, trimmed, non-empty uuids (order preserved)
     */
    private function normalizeUuidList(mixed $raw, string $field): array
    {
        if (!is_array($raw)) {
            throw ValidationException::forField($field, "{$field} must be an array of uuids.");
        }

        $result = [];
        foreach ($raw as $index => $value) {
            if (!is_string($value) || trim($value) === '') {
                throw ValidationException::forField($field, "{$field}.{$index} must be a non-empty string.");
            }
            $result[] = trim($value);
        }

        return array_values(array_unique($result));
    }

    /** @param array<string,mixed> $input */
    private function requiredString(array $input, string $field): string
    {
        $value = (string) ($input[$field] ?? '');
        if ($value === '') {
            throw ValidationException::forField($field, ucfirst($field) . ' is required.');
        }

        return $value;
    }
}
