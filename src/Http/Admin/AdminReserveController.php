<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\AttributeChargebackLinesData;
use Glueful\Extensions\Commerce\Http\DTOs\ForgiveDebtData;
use Glueful\Extensions\Commerce\Http\DTOs\IngestChargebackData;
use Glueful\Extensions\Commerce\Http\DTOs\ManualReserveHoldData;
use Glueful\Extensions\Commerce\Http\DTOs\SetSellerReservePolicyData;
use Glueful\Extensions\Commerce\Http\DTOs\SetWorkspaceReservePolicyData;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentException;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentService;
use Glueful\Extensions\Commerce\Marketplace\ChargebackAttributionException;
use Glueful\Extensions\Commerce\Marketplace\ChargebackService;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\ManualReserveConflictException;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyService;
use Glueful\Extensions\Commerce\Marketplace\ReserveService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The FULL operator surface for risk reserves, chargebacks, and negative-balance/debt
 * (design spec §2.1/§2.4/§2.5/§2.8/§6, MV5a Task 16): set workspace/per-seller reserve
 * policy, ingest a chargeback event (the SAME {@see ChargebackService::ingest()} entry
 * point {@see \Glueful\Extensions\Commerce\Events\Listeners\ProviderChargebackListener}
 * uses), supply attribution lines for a partial chargeback, emergency manual reserve
 * hold/release, audited debt forgiveness, and a read of any seller's reserves + debt.
 * `commerce:write`-gated (mutations) / `commerce:read`-gated (the reserves read),
 * marketplace-enabled-only (see `routes.php`) -- mirrors {@see AdminPayoutController}'s
 * exact composition.
 *
 * **Tenant binding (design spec §6, load-bearing).** Every mutation derives its tenant
 * from {@see CurrentTenantResolver::tenantUuid()} (the AUTHENTICATED admin profile) --
 * NEVER a request-body field. A seller/reserve/chargeback TARGET either comes from the
 * route path (`{uuid}` -- {@see self::updateSellerPolicy()}, {@see self::forgiveDebt()},
 * {@see self::sellerReserves()}) or, where the target is a body field
 * ({@see self::manualHold()}'s `seller_uuid`), is explicitly re-verified to belong to
 * the resolved tenant via {@see self::requireSeller()} BEFORE any write -- a cross-tenant
 * `seller_uuid` is a non-revealing `404`, never silently posted against the wrong
 * tenant's data. Every underlying repository call is itself `tenant_uuid`-scoped
 * (`SellerRepository::claimRevision()`, `ReserveRepository::findByUuid()`,
 * `ChargebackRepository::findByUuid()`, `OrderRepository::findByUuid()` inside
 * `ChargebackService::resolve()`) -- so even where this controller has no dedicated
 * guard, the underlying service can never read or write another tenant's row.
 *
 * **Idempotency (design spec §2.8).** {@see self::manualHold()} and
 * {@see self::forgiveDebt()} -- the two money-moving operator actions with no OTHER
 * caller-supplied uniqueness -- REQUIRE the HTTP `Idempotency-Key` header, checked here
 * BEFORE either delegate is ever called, mirroring
 * {@see AdminRefundController::store()}'s exact convention.
 *
 * **Exception mapping.** {@see ManualReserveConflictException} (a plain
 * `\DomainException`, idempotency-key reuse with different content) -> `409`;
 * {@see ChargebackAttributionException}/{@see AdjustmentException} (caller-input
 * rejections) -> `422`; a malformed normalized chargeback event
 * (`\InvalidArgumentException` from {@see ProviderChargebackEvent}/{@see PayableReference}'s
 * own constructors) -> `422`. {@see \Glueful\Extensions\Commerce\Marketplace\ChargebackIntegrityException}
 * is DELIBERATELY never caught here (a `\RuntimeException`, "should never happen" —
 * mirrors {@see \Glueful\Extensions\Commerce\Marketplace\LedgerException}'s identical
 * discipline) and a framework {@see \Glueful\Validation\ValidationException}/
 * {@see NotFoundException} already map to `422`/`404` automatically.
 *
 * **No operator "reverse a chargeback" route exists anywhere on this controller**
 * (design spec §2.10/§6) -- reversals are provider-reported only, delivered exclusively
 * through {@see ProviderChargebackListener}.
 */
final class AdminReserveController
{
    use ResolvesActor;

    private const MAX_IDEMPOTENCY_KEY_LENGTH = 128;

    public function __construct(
        private ApplicationContext $context,
        private ?ReservePolicyService $reservePolicy = null,
        private ?ChargebackService $chargebacks = null,
        private ?ReserveService $reserves = null,
        private ?AdjustmentService $adjustments = null,
        private ?SellerBalanceService $balances = null,
        private ?SellerRepository $sellers = null,
        private ?MarketplaceMode $marketplaceMode = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->reservePolicy ??= app($context, ReservePolicyService::class);
        $this->chargebacks ??= app($context, ChargebackService::class);
        $this->reserves ??= app($context, ReserveService::class);
        $this->adjustments ??= app($context, AdjustmentService::class);
        $this->balances ??= app($context, SellerBalanceService::class);
        $this->sellers ??= app($context, SellerRepository::class);
        $this->marketplaceMode ??= app($context, MarketplaceMode::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    // -----------------------------------------------------------------
    // Reserve policy (design spec §2.1, Task 6).
    // -----------------------------------------------------------------

    #[ApiOperation(
        summary: 'Set the workspace default rolling-reserve policy',
        tags: ['Commerce Admin', 'Marketplace']
    )]
    #[ApiResponse(200, description: 'Workspace reserve policy updated')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function updateWorkspacePolicy(SetWorkspaceReservePolicyData $input, Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);

        $this->reservePolicy->setWorkspace(
            $this->context,
            $tenant,
            $input->reserve_bps,
            $input->reserve_days,
            $this->actorUuid($request)
        );

        $settings = $this->marketplaceMode->settingsRowFor($this->context, $tenant);

        return Response::success($settings, 'Workspace reserve policy updated');
    }

    #[ApiOperation(summary: "Set a seller's per-seller reserve override", tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Seller reserve policy updated')]
    #[ApiResponse(404, description: 'Unknown seller')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function updateSellerPolicy(SetSellerReservePolicyData $input, Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);

        // ReservePolicyService::setSeller() -> claimRevision() is itself tenant-scoped
        // and throws a 404 NotFoundException for an unknown/cross-tenant seller uuid --
        // no separate guard needed here.
        $this->reservePolicy->setSeller(
            $this->context,
            $tenant,
            $uuid,
            $input->reserve_bps,
            $input->reserve_days,
            $this->actorUuid($request)
        );

        $seller = $this->sellers->findByUuid($this->context, $tenant, $uuid);

        return Response::success([
            'seller_uuid' => $uuid,
            'reserve_bps' => $seller['reserve_bps'] ?? null,
            'reserve_days' => $seller['reserve_days'] ?? null,
        ], 'Seller reserve policy updated');
    }

    // -----------------------------------------------------------------
    // Chargeback ingestion + partial attribution (design spec §2.4/§2.5).
    // -----------------------------------------------------------------

    /**
     * The SAME `ChargebackService::ingest()` entry point
     * {@see \Glueful\Extensions\Commerce\Events\Listeners\ProviderChargebackListener}
     * uses -- for an operator/system-supplied NORMALIZED event (e.g. a manual repair, or
     * a source outside the provider-webhook path). `tenantUuid` on the constructed event
     * is ALWAYS the resolved tenant (design spec §6 tenant binding) -- there is no
     * `tenant`/`tenant_uuid` body field at all, so a caller has no way to select a
     * different tenant's order even by construction.
     */
    #[ApiOperation(summary: 'Ingest a normalized provider chargeback event', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Chargeback ingested')]
    #[ApiResponse(422, description: 'Malformed chargeback event')]
    public function ingestChargeback(IngestChargebackData $input, Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);

        try {
            $payable = new PayableReference(
                $input->payable_type,
                $input->payable_id,
                $input->payable_amount,
                $input->payable_currency ?? $input->currency,
            );

            $event = new ProviderChargebackEvent(
                $tenant,
                $input->provider,
                $input->provider_event_id,
                $input->payment_reference,
                $payable,
                $input->amount,
                $input->currency,
                $input->reason_code,
                $input->occurred_at,
                $input->kind,
                $input->related_event_id,
            );
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['chargeback' => $e->getMessage()]);
        }

        $result = $this->chargebacks->ingest($this->context, $event);

        return Response::success($result, 'Chargeback ingested');
    }

    #[ApiOperation(
        summary: 'Supply attribution lines for a partial (awaiting_attribution) chargeback',
        tags: ['Commerce Admin', 'Marketplace']
    )]
    #[ApiResponse(200, description: 'Chargeback attribution posted')]
    #[ApiResponse(422, description: 'Validation failed or attribution rejected')]
    public function attributeChargeback(AttributeChargebackLinesData $input, Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);

        try {
            $lines = $this->validateLines($input->lines);
            $result = $this->chargebacks->attributeAndPost($this->context, $tenant, $uuid, $lines);
        } catch (ChargebackAttributionException $e) {
            return Response::validation(['attribution' => $e->getMessage()]);
        }

        return Response::success($result, 'Chargeback attribution posted');
    }

    /**
     * Shape-checks each raw line element before it reaches
     * {@see ChargebackService::attributeAndPost()} -- mirrors
     * {@see AdminRefundController::validateLines()}'s identical discipline (nested-DTO
     * support for arbitrary request arrays is pending).
     *
     * @return list<array{order_line_uuid:string, amount:int}>
     */
    private function validateLines(?array $lines): array
    {
        if ($lines === null || $lines === []) {
            throw new ChargebackAttributionException('At least one attribution line is required.');
        }

        $result = [];
        foreach ($lines as $index => $line) {
            if (
                !is_array($line)
                || !isset($line['order_line_uuid'], $line['amount'])
                || !is_string($line['order_line_uuid'])
                || !is_int($line['amount'])
            ) {
                throw new ChargebackAttributionException(
                    "lines.{$index}: must include order_line_uuid (string) and amount (int)."
                );
            }

            $result[] = ['order_line_uuid' => $line['order_line_uuid'], 'amount' => $line['amount']];
        }

        return $result;
    }

    // -----------------------------------------------------------------
    // Manual reserve hold/release + debt forgiveness (design spec §2.8, Task 15).
    // -----------------------------------------------------------------

    #[ApiOperation(
        summary: 'Create an emergency manual reserve hold for a seller',
        tags: ['Commerce Admin', 'Marketplace']
    )]
    #[ApiResponse(200, description: 'Reserve hold created')]
    #[ApiResponse(404, description: 'Unknown seller')]
    #[ApiResponse(409, description: 'Idempotency key reused with different request content')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function manualHold(ManualReserveHoldData $input, Request $request): Response
    {
        $key = $this->requireIdempotencyKey($request);
        if ($key === null) {
            return Response::validation([
                'idempotency_key' => 'A non-empty Idempotency-Key header (max 128 chars) is required.',
            ]);
        }

        $tenant = $this->tenants->tenantUuid($this->context);
        $this->requireSeller($tenant, $input->seller_uuid);

        try {
            $row = $this->reserves->manualHold(
                $this->context,
                $tenant,
                $input->seller_uuid,
                $input->currency,
                $input->amount,
                $key,
                $this->actorUuid($request) ?? '',
                $input->reason
            );
        } catch (ManualReserveConflictException $e) {
            return Response::error($e->getMessage(), 409);
        }

        return Response::success($row, 'Reserve hold created');
    }

    #[ApiOperation(
        summary: 'Release a reserve hold (manual or rolling) early',
        tags: ['Commerce Admin', 'Marketplace']
    )]
    #[ApiResponse(200, description: 'Reserve released')]
    #[ApiResponse(404, description: 'Unknown reserve')]
    public function manualRelease(Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);

        $released = $this->reserves->manualRelease($this->context, $tenant, $uuid, $this->actorUuid($request) ?? '');

        return Response::success(
            ['reserve_uuid' => $uuid, 'released_amount' => $released],
            'Reserve released'
        );
    }

    #[ApiOperation(
        summary: 'Post an audited debt-forgiveness credit for a seller',
        tags: ['Commerce Admin', 'Marketplace']
    )]
    #[ApiResponse(200, description: 'Debt forgiveness posted')]
    #[ApiResponse(404, description: 'Unknown seller')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function forgiveDebt(ForgiveDebtData $input, Request $request, string $uuid): Response
    {
        $key = $this->requireIdempotencyKey($request);
        if ($key === null) {
            return Response::validation([
                'idempotency_key' => 'A non-empty Idempotency-Key header (max 128 chars) is required.',
            ]);
        }

        if ($input->amount <= 0) {
            return Response::validation(['amount' => 'Debt forgiveness amount must be greater than zero.']);
        }

        $tenant = $this->tenants->tenantUuid($this->context);
        $this->requireSeller($tenant, $uuid);

        $accountKey = LedgerRepository::accountKeyForSeller($uuid);

        try {
            $this->adjustments->post(
                $this->context,
                $tenant,
                $accountKey,
                $input->currency,
                $input->amount,
                $input->reason,
                $key,
                $this->actorUuid($request) ?? ''
            );
        } catch (AdjustmentException $e) {
            return Response::validation(['adjustment' => $e->getMessage()]);
        }

        $balance = $this->balances->balance($this->context, $tenant, $uuid, $input->currency);

        return Response::success($balance, 'Debt forgiveness posted');
    }

    // -----------------------------------------------------------------
    // Operator read: any seller's reserves + debt (design spec §6).
    // -----------------------------------------------------------------

    #[ApiOperation(summary: "Any seller's reserve holds and debt (operator)", tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Seller reserves retrieved')]
    #[ApiResponse(404, description: 'Unknown seller')]
    public function sellerReserves(Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $this->requireSeller($tenant, $uuid);

        $currencies = $this->balances->currencies($this->context, $tenant, $uuid);

        $balances = array_map(
            fn (string $currency): array => ['currency' => $currency]
                + $this->balances->balance($this->context, $tenant, $uuid, $currency),
            $currencies
        );

        $holds = [];
        foreach ($currencies as $currency) {
            foreach ($this->reserves->heldWithRemaining($this->context, $tenant, $uuid, $currency) as $row) {
                $holds[] = $this->reserveProjection($row);
            }
        }

        return Response::success(['balances' => $balances, 'reserves' => $holds], 'Seller reserves retrieved');
    }

    /**
     * Trusted operator projection -- every field of a `commerce_seller_reserves` row is
     * safe for an operator (unlike the seller-facing allow-list,
     * {@see \Glueful\Extensions\Commerce\Http\Seller\SellerFinancialController}'s own
     * SANITIZED projection), plus the row's DERIVED `remaining` amount.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function reserveProjection(array $row): array
    {
        return [
            'uuid' => (string) $row['uuid'],
            'source_kind' => (string) $row['source_kind'],
            'currency' => (string) $row['currency'],
            'amount' => (int) $row['amount'],
            'remaining' => (int) $row['remaining'],
            'status' => (string) $row['status'],
            'seller_order_uuid' => $row['seller_order_uuid'],
            'held_at' => $row['held_at'],
            'release_at' => $row['release_at'],
            'closed_at' => $row['closed_at'],
            'created_by' => $row['created_by'],
            'reason' => $row['reason'],
        ];
    }

    // -----------------------------------------------------------------
    // Shared guards.
    // -----------------------------------------------------------------

    /**
     * Tenant-binding guard (design spec §6, load-bearing): a `seller_uuid`/`{uuid}`
     * target that does not belong to the RESOLVED tenant is a non-revealing `404` --
     * never silently posted against, or read from, the wrong tenant's data.
     */
    private function requireSeller(string $tenant, string $sellerUuid): void
    {
        if ($this->sellers->findByUuid($this->context, $tenant, $sellerUuid) === null) {
            throw new NotFoundException('Resource not found.');
        }
    }

    /**
     * The `Idempotency-Key` HEADER guard shared by {@see self::manualHold()} and
     * {@see self::forgiveDebt()} -- mirrors {@see AdminRefundController::store()}'s exact
     * convention. Returns the trimmed key, or `null` when missing/too long (the caller
     * returns a `422` before either delegate is ever called).
     */
    private function requireIdempotencyKey(Request $request): ?string
    {
        $key = trim((string) $request->headers->get('Idempotency-Key', ''));

        return ($key === '' || strlen($key) > self::MAX_IDEMPOTENCY_KEY_LENGTH) ? null : $key;
    }
}
