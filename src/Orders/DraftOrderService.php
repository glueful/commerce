<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\LineTaxCalculator;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Orders\Events\DraftOrderEvents;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Commerce\Support\EmailNormalizer;
use Glueful\Extensions\Commerce\Tax\DiscountAllocation;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Every draft-order mechanic (admin-order-creation cycle 2, Task 9, design spec
 * §2.3): create, customer/mode/shipping/discount update, line add/update/
 * remove, the explicit `recalculate` drift-acceptance operation, and cancel.
 * {@see \Glueful\Extensions\Commerce\Http\Admin\AdminOrderDraftController} stays
 * thin -- it reads input, catches three typed exceptions, and projects.
 *
 * FIVE invariants this class exists to hold:
 *
 * 1. **Revision custody.** EVERY customer/line/shipping/mode mutation goes
 *    through {@see self::compareAndSet()}: one UPDATE that both writes the
 *    change and increments `draft_revision`, guarded by `WHERE draft_revision =
 *    <expected> AND status = 'draft'`. Zero affected rows is a typed 409
 *    ({@see DraftConflictException::staleRevision()}) whether the caller sent a
 *    stale `expected_revision` or simply lost a race. Every mutation runs
 *    inside ONE transaction, so a failed CAS rolls back the line insert/delete
 *    that preceded it -- a rejected mutation leaves the draft byte-identical.
 *    `expected_revision` is OPTIONAL: absent, the CAS uses the revision read at
 *    the start of the operation (still a genuine CAS against a concurrent
 *    writer); present, it is the client's own staleness assertion. Finalize
 *    (Task 10) requires it explicitly -- that is its own contract, not this one.
 *
 * 2. **Money is never client-supplied.** No request field can write
 *    `subtotal`/`discount_total`/`shipping_total`/`tax_total`/`grand_total`;
 *    they are recomputed server-side on every mutation from the stored line
 *    snapshots, the live shipping QUOTE (a method id, never an amount), and the
 *    tax calculator -- the same {@see PricingEngine} composition
 *    {@see CheckoutService} uses.
 *
 * 3. **Advisory, not authoritative.** A draft's stored line snapshots and
 *    totals are advisory: they are what the operator last saw. Only
 *    {@see self::recalculate()} refreshes the snapshots to current catalog
 *    values -- an ordinary line mutation never reprices the OTHER lines, which
 *    is exactly what makes `recalculate` the meaningful "accept the drift"
 *    action and lets Task 10's finalize report drift instead of silently
 *    absorbing it. (Classification facts a line row cannot store -- product
 *    type, shipping class, tax class -- ARE read live on every totals
 *    computation, via {@see self::classify()}; they steer quoting, never price.)
 *
 * 4. **Closed eligibility, one authority.** Digital and marketplace-partitioned
 *    purchasables are typed rejections at mutation time via
 *    {@see DraftLineEligibility} -- the SAME class, and therefore the same
 *    closed reason strings, the admin product search publishes per row. The
 *    marketplace input is the ORDER-level composition
 *    (`installEnabled() && activeFor()`), composed per call exactly as
 *    `CheckoutService::placeOrder()` composes it ({@see self::partitioning()}).
 *
 * 5. **Identity is never invented.** A fully anonymous walk-in draft is valid:
 *    no placeholder email, no guest credential, no implicit account link. Phone
 *    is contact information only ({@see DraftPhone}); `user_uuid` is the only
 *    account-history authority and must resolve to an ACTIVE user.
 */
final class DraftOrderService
{
    /**
     * ONE neutral message for BOTH an unknown uuid and an inactive user
     * (design spec §2.3). The two must be indistinguishable to the caller:
     * distinguishing them would turn this endpoint into a user-existence
     * oracle, which is precisely what "the engine endpoint accepts a known
     * uuid under its existing order-manage authority and does not expose a
     * search surface" rules out. A missing/unbound user provider fails closed
     * onto the same message.
     */
    public const UNATTACHABLE_USER_MESSAGE = 'The selected user could not be attached to this draft.';

    /** The closed `fulfillment_mode` vocabulary (design Ruling 5). */
    public const MODES = ['in_store', 'delivery'];

    public const MODE_IN_STORE = 'in_store';
    public const MODE_DELIVERY = 'delivery';

    /**
     * The ONLY `commerce_orders` columns {@see self::compareAndSet()} will
     * write. Not a stylistic list: the CAS interpolates column names into raw
     * SQL (values stay bound), so this allowlist is what makes that safe, and
     * it also fails closed against a future field accidentally becoming
     * operator-writable.
     */
    private const MUTABLE_COLUMNS = [
        'email',
        'user_uuid',
        'customer_name',
        'phone_normalized',
        'phone_display',
        'fulfillment_mode',
        'addresses',
        'shipping_method',
        'discount_code',
        'subtotal',
        'discount_total',
        'shipping_total',
        'tax_total',
        'grand_total',
    ];

    private const MAX_CUSTOMER_NAME_LENGTH = 255;

    public function __construct(
        private OrderRepository $orders,
        private PurchasableLineResolver $lines,
        private PricingEngine $pricing,
        private ShippingRateProvider $shipping,
        private TaxCalculator $tax,
        private DiscountRepository $discounts,
        private DiscountService $discountService,
        private DraftCleanupService $cleanup,
        private CurrentTenantResolver $tenants,
        private ?MarketplaceMode $marketplace = null,
        private ?UserProviderInterface $users = null,
    ) {
        $this->marketplace ??= new MarketplaceMode();
    }

    // -----------------------------------------------------------------
    // reads
    // -----------------------------------------------------------------

    /**
     * The ONLY draft-inclusive listing in the engine (design spec §2.2): it
     * opts into {@see OrderRepository::paginatedFor()}'s `includeDrafts` AND
     * pins `status = draft`, so it returns drafts and nothing else. The
     * ordinary orders listing stays closed even when filtered by
     * `status=draft`.
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function paginate(ApplicationContext $context, int $page, int $perPage): array
    {
        return $this->orders->paginatedFor(
            $context,
            $this->tenants->tenantUuid($context),
            ['status' => OrderScope::DRAFT],
            $page,
            $perPage,
            true
        );
    }

    /** @return array{order: array<string,mixed>, lines: list<array<string,mixed>>} */
    public function find(ApplicationContext $context, string $uuid): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $this->loadDraft($context, $tenant, $uuid);

        return $this->present($context, $tenant, $uuid);
    }

    // -----------------------------------------------------------------
    // writes
    // -----------------------------------------------------------------

    /**
     * Creates a draft. Everything is optional: the default is a fully
     * ANONYMOUS `in_store` draft (design Ruling 4) -- `email`, `user_uuid`,
     * `customer_name`, and both phone columns stay NULL, no placeholder email
     * is invented, and `guest_token_hash` stays NULL so the row grants no
     * guest access.
     *
     * `origin` and `fulfillment_mode` are written EXPLICITLY, never left to the
     * standing column defaults migration 022 kept for legacy backfill -- the
     * same discipline {@see CheckoutService}'s own insert follows, pinned by a
     * defeat-the-default test.
     *
     * @param array<string,mixed> $input
     * @return array{order: array<string,mixed>, lines: list<array<string,mixed>>}
     */
    public function create(ApplicationContext $context, array $input, ?string $actorUuid = null): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $mode = array_key_exists('fulfillment_mode', $input)
            ? $this->assertMode($input['fulfillment_mode'])
            : self::MODE_IN_STORE;
        $customer = $this->customerChanges($input);
        $uuid = Utils::generateNanoID();

        return db($context)->transaction(function () use ($context, $tenant, $uuid, $mode, $customer, $actorUuid) {
            $this->orders->insert($context, array_merge([
                'uuid' => $uuid,
                'tenant_uuid' => $tenant,
                'order_number' => null,
                'status' => OrderScope::DRAFT,
                'email' => null,
                'user_uuid' => null,
                'guest_token_hash' => null,
                'customer_name' => null,
                'phone_normalized' => null,
                'phone_display' => null,
                'currency' => CommerceSettings::currency($context),
                'subtotal' => 0,
                'discount_total' => 0,
                'shipping_total' => 0,
                'tax_total' => 0,
                'grand_total' => 0,
                'origin' => 'admin',
                'fulfillment_mode' => $mode,
                'draft_revision' => 0,
            ], $customer));

            // An audit ROW, never a dispatched lifecycle event -- see
            // {@see DraftOrderEvents}. A draft wakes no mailer, no webhook
            // outbox, and no marketplace listener.
            $this->orders->recordEvent($context, $uuid, DraftOrderEvents::CREATED, [], $actorUuid);

            return $this->present($context, $tenant, $uuid);
        });
    }

    /**
     * The customer/mode/address/shipping/discount mutation.
     *
     * MODE SWITCH SEMANTICS (design Ruling 5, server-enforced): `in_store`
     * carries no addresses, no shipping method, and `shipping_total = 0`;
     * supplying either while `in_store` is a 422 rather than a silently
     * ignored field, and switching `delivery -> in_store` CLEARS the stored
     * addresses and shipping selection and recalculates in the same write.
     * `shipping_method` is a METHOD ID that must appear in a LIVE quote for
     * this draft's current lines and address -- an amount is never accepted
     * from a client, and an unavailable id is a 422.
     *
     * !! RULING 5's OTHER HALF -- "`delivery` REQUIRES address fields" -- IS
     * !! DELIBERATELY *NOT* ENFORCED HERE. FINALIZE IS THE ENFORCING SURFACE.
     * A draft is scratch work an operator builds in whatever order suits the
     * counter: choosing `delivery` before the customer has read out their
     * address must not be rejected, and a half-filled address must not block
     * adding the next line. So this path enforces only the NEGATIVE half of the
     * rule (nothing address- or shipping-shaped may exist while `in_store`) and
     * leaves the POSITIVE half -- a `delivery` order must actually have the
     * required address fields before it becomes an order -- to the one surface
     * that turns a draft into an order. Nothing between here and there checks
     * it, so if finalize does not, nothing does.
     *
     * TODO(Task 10): finalize preflight must refuse delivery drafts without
     * required address fields.
     *
     * @param array<string,mixed> $input
     * @return array{order: array<string,mixed>, lines: list<array<string,mixed>>}
     */
    public function update(ApplicationContext $context, string $uuid, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($context);

        return db($context)->transaction(function () use ($context, $tenant, $uuid, $input): array {
            $draft = $this->loadDraft($context, $tenant, $uuid);
            $expected = $this->expectedRevision($input, $draft);
            $changes = $this->customerChanges($input, $draft);

            $mode = array_key_exists('fulfillment_mode', $input)
                ? $this->assertMode($input['fulfillment_mode'])
                : (string) $draft['fulfillment_mode'];
            $changes['fulfillment_mode'] = $mode;

            $addresses = is_array($draft['addresses'] ?? null) ? $draft['addresses'] : null;
            if (array_key_exists('addresses', $input)) {
                if ($mode !== self::MODE_DELIVERY) {
                    throw ValidationException::forField(
                        'addresses',
                        'addresses apply to delivery drafts only.'
                    );
                }
                $supplied = $input['addresses'];
                if ($supplied !== null && !is_array($supplied)) {
                    throw ValidationException::forField('addresses', 'addresses must be an object.');
                }
                $addresses = $supplied;
            }

            $method = isset($draft['shipping_method']) ? (string) $draft['shipping_method'] : null;
            $methodSupplied = array_key_exists('shipping_method', $input);
            if ($methodSupplied) {
                if ($mode !== self::MODE_DELIVERY) {
                    throw ValidationException::forField(
                        'shipping_method',
                        'shipping_method applies to delivery drafts only.'
                    );
                }
                $raw = $input['shipping_method'];
                $method = $this->nullableString($raw, 'shipping_method');
            }

            // The delivery -> in_store clearing rule, applied AFTER the
            // supplied-field guards above so an operator switching modes in the
            // same request still gets an honest rejection for a contradictory
            // field rather than a silent drop.
            if ($mode === self::MODE_IN_STORE) {
                $addresses = null;
                $method = null;
            }

            $code = isset($draft['discount_code']) ? (string) $draft['discount_code'] : null;
            $codeSupplied = array_key_exists('discount_code', $input);
            if ($codeSupplied) {
                $code = $this->nullableString($input['discount_code'], 'discount_code');
            }

            $state = [
                'fulfillment_mode' => $mode,
                'addresses' => $addresses,
                'shipping_method' => $method,
                'discount_code' => $code,
                'email' => array_key_exists('email', $changes)
                    ? $changes['email']
                    : ($draft['email'] ?? null),
                'user_uuid' => array_key_exists('user_uuid', $changes)
                    ? $changes['user_uuid']
                    : ($draft['user_uuid'] ?? null),
            ];

            return $this->commit($context, $tenant, $uuid, $expected, $changes, $state, $methodSupplied, $codeSupplied);
        });
    }

    /**
     * @param array<string,mixed> $input `variant_uuid`, `quantity`, optional
     *     `addons` (raw selections), optional `expected_revision`
     * @return array{order: array<string,mixed>, lines: list<array<string,mixed>>}
     */
    public function addLine(ApplicationContext $context, string $uuid, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($context);

        return db($context)->transaction(function () use ($context, $tenant, $uuid, $input): array {
            $draft = $this->loadDraft($context, $tenant, $uuid);
            $expected = $this->expectedRevision($input, $draft);

            $variantUuid = $this->requiredString($input['variant_uuid'] ?? null, 'variant_uuid');
            $quantity = $this->assertQuantity($input['quantity'] ?? null);
            $selections = $this->assertSelections($input['addons'] ?? []);

            $resolved = $this->resolveEligible($context, $tenant, $variantUuid, $quantity, $selections);

            db($context)->table('commerce_order_lines')->insert(
                $this->lineRow(Utils::generateNanoID(), $uuid, $resolved)
            );

            return $this->commitState($context, $tenant, $uuid, $draft, $expected);
        });
    }

    /**
     * @param array<string,mixed> $input optional `quantity`, optional `addons`,
     *     optional `expected_revision`
     * @return array{order: array<string,mixed>, lines: list<array<string,mixed>>}
     */
    public function updateLine(ApplicationContext $context, string $uuid, string $lineUuid, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($context);

        return db($context)->transaction(function () use ($context, $tenant, $uuid, $lineUuid, $input): array {
            $draft = $this->loadDraft($context, $tenant, $uuid);
            $expected = $this->expectedRevision($input, $draft);
            $line = $this->loadLine($context, $tenant, $uuid, $lineUuid);

            $quantity = array_key_exists('quantity', $input)
                ? $this->assertQuantity($input['quantity'])
                : (int) $line['quantity'];
            $selections = array_key_exists('addons', $input)
                ? $this->assertSelections($input['addons'])
                : $this->selectionsFromSnapshot($line['addons'] ?? []);

            $resolved = $this->resolveEligible(
                $context,
                $tenant,
                (string) $line['variant_uuid'],
                $quantity,
                $selections
            );

            // The line UUID is STABLE across every mutation (design spec §2.3):
            // an update rewrites the existing row rather than replacing it, so a
            // client holding a line reference never has it invalidated by an
            // edit -- and Task 10's finalize can replace advisory snapshots with
            // authoritative ones in place, with no duplicate insertion.
            db($context)->table('commerce_order_lines')
                ->where('uuid', '=', $lineUuid)
                ->where('order_uuid', '=', $uuid)
                ->update($this->lineChanges($resolved));

            return $this->commitState($context, $tenant, $uuid, $draft, $expected);
        });
    }

    /**
     * @param array<string,mixed> $input optional `expected_revision`
     * @return array{order: array<string,mixed>, lines: list<array<string,mixed>>}
     */
    public function removeLine(ApplicationContext $context, string $uuid, string $lineUuid, array $input = []): array
    {
        $tenant = $this->tenants->tenantUuid($context);

        return db($context)->transaction(function () use ($context, $tenant, $uuid, $lineUuid, $input): array {
            $draft = $this->loadDraft($context, $tenant, $uuid);
            $expected = $this->expectedRevision($input, $draft);
            $this->loadLine($context, $tenant, $uuid, $lineUuid);

            db($context)->table('commerce_order_lines')
                ->where('uuid', '=', $lineUuid)
                ->where('order_uuid', '=', $uuid)
                ->delete();

            return $this->commitState($context, $tenant, $uuid, $draft, $expected);
        });
    }

    /**
     * THE explicit drift-acceptance operation (design spec §2.3): re-resolves
     * every line through {@see PurchasableLineResolver::resolveSelections()}
     * against CURRENT catalog state -- live variant price, current active addon
     * definitions, current product name/sku/option values -- replaces each
     * advisory snapshot with the result, re-quotes shipping, re-checks the
     * discount, recomputes totals, and CAS-increments the revision. This is how
     * a price-drift finalize conflict is cleared.
     *
     * It is deliberately FORGIVING where an ordinary update is strict: a line
     * whose variant/product no longer resolves keeps its last snapshot rather
     * than failing the whole call, and a shipping method or discount code that
     * has vanished is CLEARED rather than raising a 422. Accepting drift is the
     * entire purpose of the operation -- refusing to run because the catalog
     * moved would leave the operator with no way forward at all. Finalize
     * (Task 10) remains the authority that refuses to turn an unresolvable
     * draft into an order.
     *
     * @param array<string,mixed> $input optional `expected_revision`
     * @return array{order: array<string,mixed>, lines: list<array<string,mixed>>}
     */
    public function recalculate(ApplicationContext $context, string $uuid, array $input = []): array
    {
        $tenant = $this->tenants->tenantUuid($context);

        return db($context)->transaction(function () use ($context, $tenant, $uuid, $input): array {
            $draft = $this->loadDraft($context, $tenant, $uuid);
            $expected = $this->expectedRevision($input, $draft);

            foreach ($this->orders->linesForOrder($context, $tenant, $uuid) as $line) {
                try {
                    $resolved = $this->lines->resolveSelections(
                        $context,
                        $tenant,
                        (string) $line['variant_uuid'],
                        (int) $line['quantity'],
                        $this->selectionsFromSnapshot($line['addons'] ?? [])
                    );
                } catch (ValidationException) {
                    continue;
                }

                db($context)->table('commerce_order_lines')
                    ->where('uuid', '=', (string) $line['uuid'])
                    ->where('order_uuid', '=', $uuid)
                    ->update($this->lineChanges($resolved));
            }

            return $this->commitState($context, $tenant, $uuid, $draft, $expected);
        });
    }

    /**
     * Explicit operator cancellation. Routes through the SHARED mechanic
     * {@see DraftCleanupService::cancelDraft()} the TTL sweep also uses -- one
     * idempotent compare-and-set plus one {@see DraftOrderEvents::CANCELED}
     * audit row, and nothing else. There must not be two implementations of
     * "cancel a draft" that could drift, and this path must never dispatch an
     * `OrderCanceled` (no mail, no seller webhook, no marketplace fan-out for
     * an order no customer ever placed).
     *
     * @return array{order: array<string,mixed>, lines: list<array<string,mixed>>}
     */
    public function cancel(ApplicationContext $context, string $uuid, ?string $actorUuid = null): array
    {
        $tenant = $this->tenants->tenantUuid($context);
        $this->loadDraft($context, $tenant, $uuid);

        if (!$this->cleanup->cancelDraft($context, $tenant, $uuid, DraftOrderEvents::CANCELED, $actorUuid)) {
            // Lost the CAS to a concurrent cancel/expiry sweep: the draft is
            // already gone, which is the same non-revealing 404 an unknown uuid
            // gets.
            throw new NotFoundException('Resource not found.');
        }

        return $this->present($context, $tenant, $uuid);
    }

    // -----------------------------------------------------------------
    // eligibility
    // -----------------------------------------------------------------

    /**
     * Resolve ONE line through the shared resolver and apply the closed draft
     * eligibility check to the result.
     *
     * The resolver owns availability and purchasable-type: it throws a
     * `variant_uuid`-keyed {@see ValidationException} for an unknown variant, a
     * product that is not buyer-available, and an `external`/`grouped` type.
     * Those are translated to the SAME `unavailable` reason the admin product
     * search publishes, so the two surfaces speak one vocabulary. An
     * `addons`-keyed failure is a genuine input error and passes through as an
     * ordinary 422.
     *
     * `digital` and `marketplace` are NOT the resolver's business (a digital
     * variant is perfectly purchasable through storefront checkout) -- they are
     * draft-specific rejections decided here, from the resolved line's own
     * `type`/`sellerUuid` plus the order-level partitioning decision.
     *
     * @param list<array<string,mixed>> $selections
     */
    private function resolveEligible(
        ApplicationContext $context,
        string $tenant,
        string $variantUuid,
        int $quantity,
        array $selections
    ): ResolvedLine {
        try {
            $resolved = $this->lines->resolveSelections($context, $tenant, $variantUuid, $quantity, $selections);
        } catch (ValidationException $e) {
            $variantError = $e->firstError('variant_uuid');
            if ($variantError !== null) {
                throw DraftLineRejectedException::forReason(DraftLineEligibility::UNAVAILABLE, $variantError);
            }

            throw $e;
        }

        $reason = DraftLineEligibility::forResolvedLine($resolved, $this->partitioning($context, $tenant));
        if ($reason !== null) {
            throw DraftLineRejectedException::forReason($reason);
        }

        return $resolved;
    }

    /**
     * The ORDER-level marketplace decision, composed exactly as
     * {@see CheckoutService::placeOrder()} composes it (CheckoutService.php:328):
     * the config-only `installEnabled()` master switch FIRST -- so a
     * non-marketplace install runs ZERO `commerce_marketplace_settings` queries
     * -- and only then the workspace's own `activeFor()` activation.
     *
     * Computed PER CALL, deliberately NOT memoized on this instance. Two
     * independent reasons, either of which alone would rule a cache out:
     *  - {@see MarketplaceMode::installEnabled()}'s own contract is that
     *    behavioral consumers re-check per call, so a live settings-screen
     *    toggle takes effect immediately; `CheckoutService` never caches it
     *    either.
     *  - this service is registered `shared` in the container, so an instance
     *    can outlive one tenant's request in any long-lived process -- a memo
     *    here would not merely be stale, it would be CROSS-TENANT wrong, since
     *    `activeFor()` is tenant-scoped while the memo would not be.
     * The composition is two cheap calls (the first is zero-query and
     * short-circuits the second), so there is nothing to buy back.
     */
    private function partitioning(ApplicationContext $context, string $tenant): bool
    {
        return $this->marketplace->installEnabled($context)
            && $this->marketplace->activeFor($context, $tenant);
    }

    // -----------------------------------------------------------------
    // totals + CAS
    // -----------------------------------------------------------------

    /**
     * Recompute totals from the draft's CURRENT stored state (no field
     * changes), then CAS. Used by every line mutation and by `recalculate()`,
     * all of which change lines rather than the customer/mode block.
     *
     * @param array<string,mixed> $draft
     * @return array{order: array<string,mixed>, lines: list<array<string,mixed>>}
     */
    private function commitState(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        array $draft,
        int $expected
    ): array {
        return $this->commit($context, $tenant, $uuid, $expected, [], [
            'fulfillment_mode' => (string) $draft['fulfillment_mode'],
            'addresses' => is_array($draft['addresses'] ?? null) ? $draft['addresses'] : null,
            'shipping_method' => isset($draft['shipping_method']) ? (string) $draft['shipping_method'] : null,
            'discount_code' => isset($draft['discount_code']) ? (string) $draft['discount_code'] : null,
            'email' => $draft['email'] ?? null,
            'user_uuid' => $draft['user_uuid'] ?? null,
        ], false, false);
    }

    /**
     * The ONE write path for every draft mutation: recompute money from the
     * desired state, merge it with the caller's field changes, and apply the
     * whole thing in a single revision-CAS UPDATE.
     *
     * `$strictShipping`/`$strictDiscount` say whether the caller EXPLICITLY
     * supplied that field in this request. Explicit input is validated
     * strictly (an unavailable method id or an invalid code is a 422 and
     * nothing is written); inherited state is treated forgivingly (a method or
     * code that has since vanished is cleared) so an unrelated line mutation
     * never fails because the catalog moved underneath the draft.
     *
     * @param array<string,mixed> $changes already-validated field changes
     * @param array<string,mixed> $state desired mode/addresses/method/code/identity
     * @return array{order: array<string,mixed>, lines: list<array<string,mixed>>}
     */
    private function commit(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        int $expected,
        array $changes,
        array $state,
        bool $strictShipping,
        bool $strictDiscount
    ): array {
        $lines = $this->pricedLines($context, $tenant, $uuid);

        $code = $state['discount_code'];
        $discount = null;
        if (is_string($code) && $code !== '') {
            $discount = $this->resolveDiscount($context, $tenant, $code, $lines, $state, $strictDiscount);
            if ($discount === null) {
                $code = null;
            }
        }

        $method = $state['shipping_method'];
        $quote = null;
        if ($state['fulfillment_mode'] === self::MODE_DELIVERY && is_string($method) && $method !== '') {
            $quote = $this->selectQuote($context, $lines, $state['addresses'], $method, $strictShipping);
            if ($quote === null) {
                $method = null;
            }
        } else {
            $method = $state['fulfillment_mode'] === self::MODE_DELIVERY ? $method : null;
        }

        $shippingAddress = is_array($state['addresses']['shipping'] ?? null) ? $state['addresses']['shipping'] : [];
        $preTax = $this->pricing->price($lines, $discount, $quote, null);
        $taxQuote = $this->resolveTax($context, $lines, $discount, $preTax, $shippingAddress);
        $totals = $this->pricing->price($lines, $discount, $quote, $taxQuote);

        $changes['fulfillment_mode'] = $state['fulfillment_mode'];
        $changes['addresses'] = is_array($state['addresses']) && $state['addresses'] !== []
            ? json_encode($state['addresses'], JSON_THROW_ON_ERROR)
            : null;
        $changes['shipping_method'] = $method;
        $changes['discount_code'] = $code;
        $changes['subtotal'] = $totals->subtotal;
        $changes['discount_total'] = $totals->discountTotal;
        $changes['shipping_total'] = $totals->shippingTotal;
        $changes['tax_total'] = $totals->taxTotal;
        $changes['grand_total'] = $totals->grandTotal;

        $this->compareAndSet($context, $tenant, $uuid, $expected, $changes);

        return $this->present($context, $tenant, $uuid);
    }

    /**
     * The revision compare-and-set. Column names are interpolated from
     * {@see self::MUTABLE_COLUMNS} (an allowlist, asserted here) while every
     * value stays bound. `AND status = 'draft'` is part of the predicate so a
     * draft canceled/finalized concurrently also fails the CAS rather than
     * being silently mutated after it stopped being a draft.
     *
     * @param array<string,mixed> $changes
     */
    private function compareAndSet(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        int $expected,
        array $changes
    ): void {
        $sets = [];
        $params = [];
        foreach ($changes as $column => $value) {
            if (!in_array($column, self::MUTABLE_COLUMNS, true)) {
                throw new \LogicException("Draft column '{$column}' is not operator-writable.");
            }
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }

        $sets[] = 'draft_revision = draft_revision + 1';
        $sets[] = 'updated_at = ?';
        $params[] = db($context)->getDriver()->formatDateTime();
        $params[] = $tenant;
        $params[] = $uuid;
        $params[] = $expected;

        $isDraft = OrderScope::isDraftSql();
        $affected = db($context)->table('commerce_orders')->executeModification(
            'UPDATE commerce_orders SET ' . implode(', ', $sets)
            . " WHERE tenant_uuid = ? AND uuid = ? AND draft_revision = ? AND {$isDraft}",
            $params
        );

        if ($affected !== 1) {
            throw DraftConflictException::staleRevision();
        }
    }

    /**
     * The draft's stored line snapshots, shaped for {@see PricingEngine},
     * {@see ShippingRateProvider}, and {@see DiscountAllocation}.
     *
     * MONEY comes from the STORED row and only from there -- this is what makes
     * a draft's totals advisory and `recalculate()` meaningful. The three facts
     * a `commerce_order_lines` row cannot carry (`product_uuid` for
     * product-scoped discounts, `type` for digital-only shipping suppression,
     * `shipping_class`/`tax_class` for rate tables) are read live through
     * {@see self::classify()}; they steer QUOTING, never price.
     *
     * @return list<array<string,mixed>>
     */
    private function pricedLines(ApplicationContext $context, string $tenant, string $uuid): array
    {
        $priced = [];
        foreach ($this->orders->linesForOrder($context, $tenant, $uuid) as $line) {
            $classification = $this->classify($context, $tenant, $line);
            $priced[] = [
                'line_uuid' => (string) $line['uuid'],
                'product_uuid' => $classification['product_uuid'],
                'variant_uuid' => (string) $line['variant_uuid'],
                'unit_price' => (int) $line['unit_price'],
                'quantity' => (int) $line['quantity'],
                'type' => $classification['type'],
                'shipping_class' => $classification['shipping_class'],
                'tax_class' => $classification['tax_class'],
                'sku' => (string) $line['sku'],
                'product_name' => (string) $line['product_name'],
                'addons' => is_array($line['addons'] ?? null) ? $line['addons'] : [],
            ];
        }

        return $priced;
    }

    /**
     * Live classification for ONE stored line. A line whose variant/product no
     * longer resolves degrades to the neutral defaults rather than failing the
     * whole totals computation -- a draft is scratch work, and Task 10's
     * finalize is the authority that refuses unresolvable lines.
     *
     * @param array<string,mixed> $line
     * @return array{product_uuid: string, type: string, shipping_class: string|null, tax_class: string}
     */
    private function classify(ApplicationContext $context, string $tenant, array $line): array
    {
        try {
            $resolved = $this->lines->resolveSelections(
                $context,
                $tenant,
                (string) $line['variant_uuid'],
                (int) $line['quantity'],
                $this->selectionsFromSnapshot($line['addons'] ?? [])
            );
        } catch (ValidationException) {
            return ['product_uuid' => '', 'type' => 'physical', 'shipping_class' => null, 'tax_class' => 'standard'];
        }

        return [
            'product_uuid' => $resolved->productUuid,
            'type' => $resolved->type,
            'shipping_class' => $resolved->shippingClass,
            'tax_class' => $resolved->taxClass,
        ];
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed>|null $addresses
     */
    private function selectQuote(
        ApplicationContext $context,
        array $lines,
        ?array $addresses,
        string $methodId,
        bool $strict
    ): ?ShippingQuote {
        $shippingAddress = is_array($addresses['shipping'] ?? null) ? $addresses['shipping'] : [];
        foreach ($this->shipping->quote($context, $lines, $shippingAddress) as $option) {
            if ($option->id === $methodId) {
                return $option;
            }
        }

        if ($strict) {
            throw ValidationException::forField('shipping_method', 'Shipping method is not available.');
        }

        return null;
    }

    /**
     * Mirrors {@see CheckoutService::resolveTax()} exactly: a
     * {@see LineTaxCalculator} gets per-line post-discount detail, anything
     * else gets the aggregate call.
     *
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed>|null $discount
     * @param array<string,mixed> $shippingAddress
     */
    private function resolveTax(
        ApplicationContext $context,
        array $lines,
        ?array $discount,
        \Glueful\Extensions\Commerce\Pricing\Totals $preTax,
        array $shippingAddress
    ): TaxQuote {
        if (!$this->tax instanceof LineTaxCalculator) {
            return $this->tax->quote($context, $preTax->grandTotal, $shippingAddress);
        }

        return $this->tax->quoteDetailed(
            $context,
            DiscountAllocation::taxableLines($lines, $discount, $preTax->discountTotal),
            $preTax->shippingTotal,
            $shippingAddress
        );
    }

    /**
     * Validate a discount code WITHOUT consuming it (design spec §2.3): the
     * usage counter and the redemption row belong to finalize alone, so a draft
     * that is edited fifty times still burns nothing.
     *
     * ANONYMOUS `once_per_buyer` (design spec §2.5.7): a discount limited to
     * one use per buyer needs a buyer to key on. A draft with neither
     * `user_uuid` nor `email` has none, so it is a 422 at APPLICATION time (and
     * again at finalize) -- never accepted now and surprisingly rejected later.
     *
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $state
     * @return array<string,mixed>|null null means "clear the code" (non-strict only)
     */
    private function resolveDiscount(
        ApplicationContext $context,
        string $tenant,
        string $code,
        array $lines,
        array $state,
        bool $strict
    ): ?array {
        $discount = $this->discounts->findByCode($context, $tenant, $code);
        if ($discount === null) {
            if ($strict) {
                throw ValidationException::forField('discount_code', 'Discount code is not valid.');
            }

            return null;
        }

        $user = isset($state['user_uuid']) ? (string) $state['user_uuid'] : '';
        $email = isset($state['email']) ? (string) $state['email'] : '';
        if ((int) ($discount['once_per_buyer'] ?? 0) === 1 && $user === '' && $email === '') {
            if ($strict) {
                throw ValidationException::forField(
                    'discount_code',
                    'This discount is limited to one use per buyer, so the draft needs a customer email or account.'
                );
            }

            return null;
        }

        try {
            $this->discountService->validateForCart(
                $context,
                $discount,
                $this->pricing->discountableBase($lines, null),
                $lines
            );
        } catch (ValidationException $e) {
            if ($strict) {
                throw $e;
            }

            return null;
        }

        return $discount;
    }

    // -----------------------------------------------------------------
    // input normalization
    // -----------------------------------------------------------------

    /**
     * The customer block: `email`, `phone`, `customer_name`, `user_uuid`. Every
     * field is PRESENCE-sensitive (`array_key_exists`, never `isset`) so an
     * explicit `null` clears and an absent key leaves the stored value alone --
     * the two are genuinely different requests.
     *
     * USER ATTACHMENT (design spec §2.3): `user_uuid` must resolve to an ACTIVE
     * user through the framework's `UserProviderInterface`. "Active" follows the
     * framework's own convention (`AdminPermissionMiddleware`): a null status
     * means the store has no opinion and is allowed; any other explicit value
     * must be `active`. Unknown and inactive both raise ONE neutral 422. No
     * provider bound fails closed onto the same 422 -- a draft is never linked
     * to an account this engine could not verify.
     *
     * THE EMAIL/ACCOUNT AGREEMENT GUARD IS EFFECTIVE-STATE, NOT PER-REQUEST.
     * Comparing only the two fields that arrived TOGETHER would leave a trivial
     * two-request bypass: `PATCH {user_uuid}` then `PATCH {email: <foreign>}`
     * (or the reverse) would persist a linked account sitting beside an email
     * that account does not own -- exactly the state §2.3 forbids, and the state
     * that would send a finalize confirmation to an address the account owner
     * never gave. So whenever EITHER field is in play, the guard resolves the
     * EFFECTIVE pair (this request's change if present, otherwise the draft's
     * stored value) and enforces the 409 against that. Clearing either side to
     * null is always allowed -- an unlinked draft with an email, and a linked
     * draft with no email, are both legitimate states.
     *
     * A stored `user_uuid` that no longer resolves (or is no longer active)
     * fails CLOSED when an email is in play: the engine cannot confirm the
     * address belongs to the account, so it refuses rather than persisting an
     * unverifiable pairing. The remedy is to detach or re-attach the user.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $draft the CURRENT stored row; null on
     *     create, where every stored value is null by construction, so the
     *     effective pair is exactly this request's own changes
     * @return array<string,mixed>
     */
    private function customerChanges(array $input, ?array $draft = null): array
    {
        $changes = [];
        $identity = null;

        if (array_key_exists('user_uuid', $input)) {
            $raw = $input['user_uuid'];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $changes['user_uuid'] = null;
            } else {
                if (!is_string($raw)) {
                    throw ValidationException::forField('user_uuid', self::UNATTACHABLE_USER_MESSAGE);
                }
                $identity = $this->users?->findByUuid(trim($raw));
                if ($identity === null || !$this->isActive($identity)) {
                    throw ValidationException::forField('user_uuid', self::UNATTACHABLE_USER_MESSAGE);
                }
                $changes['user_uuid'] = trim($raw);
            }
        }

        if (array_key_exists('email', $input)) {
            $raw = $input['email'];
            if ($raw === null || (is_scalar($raw) && trim((string) $raw) === '')) {
                $changes['email'] = null;
            } else {
                if (!is_scalar($raw)) {
                    throw ValidationException::forField('email', 'email must be a valid email address.');
                }
                $email = trim((string) $raw);
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw ValidationException::forField('email', 'email must be a valid email address.');
                }
                $changes['email'] = $email;
            }
        }

        if (array_key_exists('user_uuid', $input) || array_key_exists('email', $input)) {
            $this->assertIdentityAgreement($changes, $draft, $identity);
        }

        if (array_key_exists('customer_name', $input)) {
            $raw = $input['customer_name'];
            if ($raw === null || (is_scalar($raw) && trim((string) $raw) === '')) {
                $changes['customer_name'] = null;
            } else {
                if (!is_scalar($raw)) {
                    throw ValidationException::forField('customer_name', 'customer_name must be a string.');
                }
                $name = trim((string) $raw);
                if (mb_strlen($name) > self::MAX_CUSTOMER_NAME_LENGTH) {
                    throw ValidationException::forField(
                        'customer_name',
                        'customer_name must be at most ' . self::MAX_CUSTOMER_NAME_LENGTH . ' characters.'
                    );
                }
                $changes['customer_name'] = $name;
            }
        }

        if (array_key_exists('phone', $input)) {
            // Both columns are written in the SAME UPDATE, so clearing is
            // atomic -- there is no observable state where one is set and the
            // other is not.
            [$normalized, $display] = DraftPhone::parse($input['phone']);
            $changes['phone_normalized'] = $normalized;
            $changes['phone_display'] = $display;
        }

        return $changes;
    }

    /**
     * The EFFECTIVE-state email/account agreement check -- see
     * {@see self::customerChanges()}'s docblock for why it is effective-state
     * rather than per-request.
     *
     * `$resolved` is the identity this request already looked up (when the
     * request itself supplied `user_uuid`), passed in purely so a
     * user-and-email request never calls the provider twice.
     *
     * @param array<string,mixed> $changes this request's already-validated changes
     * @param array<string,mixed>|null $draft the current stored row
     */
    private function assertIdentityAgreement(array $changes, ?array $draft, ?UserIdentity $resolved): void
    {
        $userUuid = array_key_exists('user_uuid', $changes)
            ? $changes['user_uuid']
            : ($draft['user_uuid'] ?? null);
        $email = array_key_exists('email', $changes)
            ? $changes['email']
            : ($draft['email'] ?? null);

        // Either side absent = nothing to disagree about. This is what keeps
        // "clear the email", "detach the user", and every anonymous walk-in
        // draft legal.
        if (!is_string($userUuid) || $userUuid === '' || !is_string($email) || $email === '') {
            return;
        }

        $identity = $resolved !== null && $resolved->uuid() === $userUuid
            ? $resolved
            : $this->users?->findByUuid($userUuid);

        if ($identity === null || !$this->isActive($identity)) {
            throw DraftConflictException::userEmailMismatch();
        }

        $accountEmail = $identity->email();
        if (
            $accountEmail === null
            || EmailNormalizer::normalize($accountEmail) !== EmailNormalizer::normalize($email)
        ) {
            throw DraftConflictException::userEmailMismatch();
        }
    }

    /** The framework's own "active" convention -- see the class docblock. */
    private function isActive(UserIdentity $identity): bool
    {
        $status = $identity->status();

        return $status === null || $status === 'active';
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $draft */
    private function expectedRevision(array $input, array $draft): int
    {
        if (!array_key_exists('expected_revision', $input) || $input['expected_revision'] === null) {
            return (int) $draft['draft_revision'];
        }

        $raw = $input['expected_revision'];
        if (is_int($raw) && $raw >= 0) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }

        throw ValidationException::forField('expected_revision', 'expected_revision must be a non-negative integer.');
    }

    private function assertMode(mixed $raw): string
    {
        $mode = is_scalar($raw) ? (string) $raw : '';
        if (!in_array($mode, self::MODES, true)) {
            throw ValidationException::forField(
                'fulfillment_mode',
                'fulfillment_mode must be one of: ' . implode(', ', self::MODES) . '.'
            );
        }

        return $mode;
    }

    private function assertQuantity(mixed $raw): int
    {
        if (is_int($raw) && $raw >= 1) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw) && (int) $raw >= 1) {
            return (int) $raw;
        }

        throw ValidationException::forField('quantity', 'quantity must be a positive integer.');
    }

    /** @return list<array<string,mixed>> */
    private function assertSelections(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw ValidationException::forField('addons', 'addons must be a list of selections.');
        }
        foreach ($raw as $selection) {
            if (!is_array($selection)) {
                throw ValidationException::forField('addons', 'addons must be a list of selections.');
            }
        }

        /** @var list<array<string,mixed>> $raw */
        return $raw;
    }

    private function requiredString(mixed $raw, string $field): string
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw ValidationException::forField($field, "{$field} is required.");
        }

        return trim($raw);
    }

    private function nullableString(mixed $raw, string $field): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (!is_scalar($raw)) {
            throw ValidationException::forField($field, "{$field} must be a string.");
        }
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    /**
     * Reconstruct RAW addon selections from a persisted canonical snapshot, so
     * a re-resolution runs the snapshot back through
     * {@see PurchasableLineResolver::resolveSelections()}'s current-definition
     * path -- which is exactly how addon drift becomes visible instead of being
     * silently preserved.
     *
     * @return list<array<string,mixed>>
     */
    private function selectionsFromSnapshot(mixed $snapshot): array
    {
        if (!is_array($snapshot)) {
            return [];
        }

        $selections = [];
        foreach ($snapshot as $entry) {
            if (!is_array($entry) || !isset($entry['addon_uuid'])) {
                continue;
            }
            $selection = ['addon_uuid' => (string) $entry['addon_uuid']];
            if (isset($entry['choice_key'])) {
                $selection['choice_key'] = (string) $entry['choice_key'];
            }
            if (array_key_exists('value', $entry)) {
                $selection['value'] = $entry['value'];
            }
            $selections[] = $selection;
        }

        return $selections;
    }

    // -----------------------------------------------------------------
    // persistence helpers
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function lineRow(string $lineUuid, string $orderUuid, ResolvedLine $resolved): array
    {
        return array_merge([
            'uuid' => $lineUuid,
            'order_uuid' => $orderUuid,
            'variant_uuid' => $resolved->variantUuid,
        ], $this->lineChanges($resolved));
    }

    /**
     * The advisory snapshot columns a draft line owns. `seller_uuid`,
     * `discount_amount`, `tax_amount`, and every `commission_*` column are
     * deliberately untouched: they are checkout/marketplace attribution written
     * by {@see OrderRepository::insert()}'s partitioned path, and a draft can
     * never carry a marketplace line in the first place ({@see
     * DraftLineEligibility::MARKETPLACE}).
     *
     * @return array<string,mixed>
     */
    private function lineChanges(ResolvedLine $resolved): array
    {
        return [
            'product_name' => $resolved->productName,
            'sku' => $resolved->sku,
            'option_values' => json_encode($resolved->optionValues, JSON_THROW_ON_ERROR),
            'unit_price' => $resolved->unitPrice,
            'quantity' => $resolved->quantity,
            'line_total' => $resolved->unitPrice * $resolved->quantity,
            'addons' => $resolved->addons === [] ? null : json_encode($resolved->addons, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * The ONE draft lookup. `includeDrafts: true` is the explicit opt-in Task 8
     * built; the status recheck means a finalized or canceled order reaching a
     * draft endpoint gets the SAME non-revealing 404 an unknown or cross-tenant
     * uuid gets.
     *
     * @return array<string,mixed>
     */
    private function loadDraft(ApplicationContext $context, string $tenant, string $uuid): array
    {
        $order = $this->orders->findByUuid($context, $tenant, $uuid, true);
        if ($order === null || (string) $order['status'] !== OrderScope::DRAFT) {
            throw new NotFoundException('Resource not found.');
        }

        return $order;
    }

    /** @return array<string,mixed> */
    private function loadLine(ApplicationContext $context, string $tenant, string $uuid, string $lineUuid): array
    {
        foreach ($this->orders->linesForOrder($context, $tenant, $uuid) as $line) {
            if ((string) $line['uuid'] === $lineUuid) {
                return $line;
            }
        }

        throw new NotFoundException('Resource not found.');
    }

    /** @return array{order: array<string,mixed>, lines: list<array<string,mixed>>} */
    private function present(ApplicationContext $context, string $tenant, string $uuid): array
    {
        $order = $this->orders->findByUuid($context, $tenant, $uuid, true);
        if ($order === null) {
            throw new NotFoundException('Resource not found.');
        }

        return ['order' => $order, 'lines' => $this->orders->linesForOrder($context, $tenant, $uuid)];
    }
}
