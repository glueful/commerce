<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Seller;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\SellerFinancialReportQuery;
use Glueful\Extensions\Commerce\Http\DTOs\SellerPayoutListQuery;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyResolver;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Reports\ReportWindow;
use Glueful\Extensions\Commerce\Reports\SellerFinancialReportRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Seller-scoped financial surfaces (design spec §6.2, MV3 Task 11):
 * windowed financial report, live balance + components, payouts, and the
 * effective commission policy -- ALL read-only, own seller only. Every
 * route runs behind `commerce_seller:commerce.seller.{reports,payouts}.read`
 * (see routes.php) -- the SAME seller-membership + capability gate
 * {@see SellerOrderController} uses, so a cross-seller/unknown/non-
 * partitioned-workspace request never reaches a handler here at all; there
 * is no additional seller-existence check to duplicate (design spec §6.3:
 * cross-seller reads are a non-revealing 404 at the middleware layer).
 *
 * `commissionPolicy()` reads the seller's commission columns off the
 * `commerce_seller` request attribute {@see SellerMemberMiddleware} already
 * attaches on a successful pass -- never a second `SellerRepository::findByUuid()`
 * query -- mirroring the middleware's own "downstream controllers never
 * need to re-query it" contract.
 */
final class SellerFinancialController
{
    public function __construct(
        private ApplicationContext $context,
        private ?SellerFinancialReportRepository $reports = null,
        private ?SellerBalanceService $balances = null,
        private ?PayoutRepository $payouts = null,
        private ?MarketplaceMode $marketplaceMode = null,
        private ?CurrentTenantResolver $tenants = null,
        private ?PayoutAccountRepository $payoutAccounts = null,
    ) {
        $this->reports ??= app($context, SellerFinancialReportRepository::class);
        $this->balances ??= app($context, SellerBalanceService::class);
        $this->payouts ??= app($context, PayoutRepository::class);
        $this->marketplaceMode ??= app($context, MarketplaceMode::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        $this->payoutAccounts ??= app($context, PayoutAccountRepository::class);
    }

    #[ApiOperation(summary: "Seller's own financial report over a date window", tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Seller financial report retrieved')]
    #[ApiResponse(404, description: 'Unknown seller, no active membership, or workspace not activated')]
    public function report(SellerFinancialReportQuery $query, Request $request, string $sellerUuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);
        $currency = $query->currency ?? $this->defaultCurrency($tenant, $sellerUuid);
        $window = ReportWindow::fromDates($query->from, $query->to, $query->group ?? 'day');

        $dayRows = $this->reports->reportByDay($this->context, $tenant, $accountKey, $currency, $window);
        ['series' => $series, 'summary' => $summary] = SellerFinancialReportRepository::foldSeries($dayRows, $window);

        $balance = $this->balances->balance($this->context, $tenant, $sellerUuid, $currency);
        $summary['balance_minor'] = $balance['available'];

        return Response::success([
            'currency' => $currency,
            'window' => [
                'from' => $window->fromDate(),
                'to' => $window->toDate(),
                'group' => $window->group(),
            ],
            'summary' => $summary,
            'series' => $series,
        ], 'Seller financial report retrieved');
    }

    #[ApiOperation(
        summary: "Seller's own balance and its §2.9 components, per currency",
        tags: ['Commerce Seller']
    )]
    #[ApiResponse(200, description: 'Seller balance retrieved')]
    #[ApiResponse(404, description: 'Unknown seller, no active membership, or workspace not activated')]
    public function balance(Request $request, string $sellerUuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $currencies = $this->balances->currencies($this->context, $tenant, $sellerUuid);

        $balances = array_map(
            fn (string $currency): array => ['currency' => $currency]
                + $this->balances->balance($this->context, $tenant, $sellerUuid, $currency),
            $currencies
        );

        return Response::success(['balances' => $balances], 'Seller balance retrieved');
    }

    #[ApiOperation(summary: "Seller's own payouts", tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Seller payouts retrieved')]
    #[ApiResponse(404, description: 'Unknown seller, no active membership, or workspace not activated')]
    public function payouts(SellerPayoutListQuery $query, Request $request, string $sellerUuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $rows = $this->payouts->forSeller($this->context, $tenant, $sellerUuid, $query->currency);

        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $total = count($rows);
        $items = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return Response::paginated(
            array_map(fn (array $row): array => $this->payoutProjection($row), $items),
            $total,
            $page,
            $perPage,
            null,
            'Seller payouts retrieved'
        );
    }

    /**
     * `GET /{sellerUuid}/payouts/accounts` (design spec §6.2/§2.7, MV4 Task 10): the seller's
     * own payout-DESTINATION readiness, per provider -- a strict field-by-field allowlist of
     * `provider`/`readiness_state`/`last_synced_at` ONLY. NEVER the opaque `account_ref`
     * (provider-owned, never seller-facing) and NEVER a failure code/reason (operator-only
     * detail) -- the readiness state alone is enough for a seller to know whether payouts can
     * run. No mutation surface exists here or anywhere on this controller.
     */
    #[ApiOperation(
        summary: "Seller's own payout-account readiness, per provider",
        tags: ['Commerce Seller']
    )]
    #[ApiResponse(200, description: 'Payout account readiness retrieved')]
    #[ApiResponse(404, description: 'Unknown seller, no active membership, or workspace not activated')]
    public function payoutAccounts(Request $request, string $sellerUuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $rows = $this->payoutAccounts->forSeller($this->context, $tenant, $sellerUuid);

        return Response::success(
            array_map(fn (array $row): array => self::payoutAccountProjection($row), $rows),
            'Payout account readiness retrieved'
        );
    }

    #[ApiOperation(
        summary: "Seller's own effective (resolved) commission policy",
        tags: ['Commerce Seller']
    )]
    #[ApiResponse(200, description: 'Seller commission policy retrieved')]
    #[ApiResponse(404, description: 'Unknown seller, no active membership, or workspace not activated')]
    public function commissionPolicy(Request $request, string $sellerUuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);

        $sellerRow = $request->attributes->get('commerce_seller');
        $seller = is_array($sellerRow) ? $sellerRow : [];

        $workspaceRow = $this->marketplaceMode->settingsRowFor($this->context, $tenant);
        $configPolicy = (array) config($this->context, 'commerce.marketplace.commission', []);

        // No product context on this seller-level surface (design spec §6.2:
        // "expose the effective CURRENT policy here" -- snapshotted per-order-line
        // policy already lives on the seller's own order lines) -- the product
        // level is always empty, so resolution walks seller -> workspace -> config.
        $productLevel = ['kind' => null, 'bps' => null, 'fixed' => null];
        $sellerLevel = self::commissionLevel($seller);
        $workspaceLevel = self::commissionLevel($workspaceRow ?? []);
        $configLevel = [
            'kind' => isset($configPolicy['kind']) ? (string) $configPolicy['kind'] : null,
            'bps' => isset($configPolicy['bps']) ? (int) $configPolicy['bps'] : null,
            'fixed' => isset($configPolicy['fixed']) ? (int) $configPolicy['fixed'] : null,
        ];

        $policy = CommissionPolicyResolver::resolve([$productLevel, $sellerLevel, $workspaceLevel, $configLevel]);

        return Response::success($policy, 'Seller commission policy retrieved');
    }

    /** @param array<string,mixed> $row @return array{kind:?string,bps:?int,fixed:?int} */
    private static function commissionLevel(array $row): array
    {
        return [
            'kind' => isset($row['commission_kind']) ? (string) $row['commission_kind'] : null,
            'bps' => isset($row['commission_bps']) ? (int) $row['commission_bps'] : null,
            'fixed' => isset($row['commission_fixed']) ? (int) $row['commission_fixed'] : null,
        ];
    }

    private function defaultCurrency(string $tenant, string $sellerUuid): string
    {
        $currencies = $this->balances->currencies($this->context, $tenant, $sellerUuid);

        return $currencies[0] ?? (string) config($this->context, 'commerce.currency', 'USD');
    }

    /**
     * A fixed, closed dictionary from a `commerce_payouts.failure_code` to a stable
     * human-readable message (design spec §6.2, MV4 Task 10) -- NEVER the raw provider
     * `failure_reason` text, which may carry provider-internal detail (or, in principle, a
     * poison string) that must never reach a seller. An unrecognized code (including anything
     * that isn't one of Commerce's own known saga codes) falls back to the generic
     * `payout_failed` code + message below via {@see self::sanitizedFailureCode()}/
     * {@see self::sanitizedFailureMessage()} -- an unmapped code is sanitized away, never
     * passed through verbatim.
     */
    private const FAILURE_MESSAGES = [
        'insufficient_funds' => 'The payout could not be completed due to insufficient provider funds.',
        'card_declined' => 'The destination declined the transfer.',
        'account_closed' => 'The destination account is closed.',
        'action_required' => 'The destination requires additional action before this payout can complete.',
        'attempt_not_started' => 'The payout attempt has not yet reached the provider.',
    ];

    private const DEFAULT_FAILURE_MESSAGE = 'The payout could not be completed.';

    /**
     * Field-by-field allowlist -- never a raw `commerce_payouts` row spread. Excludes the
     * internal `id`/`tenant_uuid`/`seller_uuid` (redundant -- the seller already knows it's
     * their own), `idempotency_key` (internal correctness plumbing, never seller-facing), and
     * (MV4, design spec §6.2/§2.7/§2.8) the provider-payout internals: raw `failure_reason`,
     * `destination_ref`. `status`/`provider`/`provider_ref` plus a SANITIZED `failure_code`/
     * human failure message are new for MV4; the pre-existing manual-payout fields
     * (`external_ref`/`note`/`created_by`) keep their exact prior shape -- a provider row simply
     * renders `external_ref`/`created_by` as `null` (now nullable at the schema level, design
     * spec §3.1) rather than crashing.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function payoutProjection(array $row): array
    {
        $failureCode = isset($row['failure_code']) && $row['failure_code'] !== null
            ? (string) $row['failure_code']
            : null;

        return [
            'uuid' => (string) $row['uuid'],
            'currency' => (string) $row['currency'],
            'amount' => (int) $row['amount'],
            'external_ref' => $row['external_ref'] !== null ? (string) $row['external_ref'] : null,
            'note' => $row['note'],
            'created_by' => $row['created_by'],
            'created_at' => $row['created_at'],
            'status' => (string) ($row['status'] ?? 'paid'),
            'provider' => $row['provider'] !== null ? (string) $row['provider'] : null,
            'provider_ref' => $row['provider_ref'] !== null ? (string) $row['provider_ref'] : null,
            'failure_code' => self::sanitizedFailureCode($failureCode),
            'failure_message' => self::sanitizedFailureMessage($failureCode),
        ];
    }

    private static function sanitizedFailureCode(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return array_key_exists($code, self::FAILURE_MESSAGES) ? $code : 'payout_failed';
    }

    private static function sanitizedFailureMessage(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::FAILURE_MESSAGES[$code] ?? self::DEFAULT_FAILURE_MESSAGE;
    }

    /**
     * Field-by-field allowlist for a seller's own payout-DESTINATION readiness (design spec
     * §6.2/§2.7): `provider`/`readiness_state`/`last_synced_at` ONLY -- never the opaque
     * `account_ref` (provider-owned, would let a seller see/compare raw provider account
     * identifiers) and never `failure_code` (operator-only detail; the readiness state alone
     * tells the seller whether payouts can run).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function payoutAccountProjection(array $row): array
    {
        return [
            'provider' => (string) $row['provider'],
            'readiness_state' => (string) $row['readiness_state'],
            'last_synced_at' => $row['last_synced_at'],
        ];
    }
}
