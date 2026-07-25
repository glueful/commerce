<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\SellerFinancialReportQuery;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Reports\ReportWindow;
use Glueful\Extensions\Commerce\Reports\SellerFinancialReportRepository;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Operator marketplace financial surfaces (design spec §6.1, MV3 Task 11):
 * the marketplace account's own balance across every currency it holds, plus
 * any seller's balance/financial report -- an operator is trusted to read
 * EVERY seller's figures, unlike the seller-scoped
 * {@see \Glueful\Extensions\Commerce\Http\Seller\SellerFinancialController},
 * which is restricted to the caller's own seller via `commerce_seller`
 * middleware. `commerce:read`-gated (see routes.php), marketplace-enabled-
 * only, mirroring every other `/commerce/admin/marketplace/*` route.
 *
 * `sellerReport()` shares {@see SellerFinancialReportRepository} and its
 * {@see SellerFinancialReportRepository::foldSeries()} folding/net-derivation
 * helper with the seller-scoped report endpoint -- the SAME windowed
 * gross/commission/refunds/reversed/net figures, never a second formula.
 */
final class AdminMarketplaceFinancialController
{
    public function __construct(
        private ApplicationContext $context,
        private ?SellerBalanceService $balances = null,
        private ?LedgerRepository $ledger = null,
        private ?SellerFinancialReportRepository $reports = null,
        private ?SellerRepository $sellers = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->balances ??= app($context, SellerBalanceService::class);
        $this->ledger ??= app($context, LedgerRepository::class);
        $this->reports ??= app($context, SellerFinancialReportRepository::class);
        $this->sellers ??= app($context, SellerRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    #[ApiOperation(
        summary: "The marketplace account's financial summary, across every currency it holds",
        tags: ['Commerce Admin', 'Marketplace']
    )]
    #[ApiResponse(200, description: 'Marketplace financial summary retrieved')]
    public function marketplaceSummary(Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $currencies = $this->ledger->currenciesForAccount(
            $this->context,
            $tenant,
            LedgerRepository::MARKETPLACE_ACCOUNT_KEY
        );

        $balances = array_map(
            fn (string $currency): array => ['currency' => $currency]
                + $this->balances->marketplaceBalance($this->context, $tenant, $currency),
            $currencies
        );

        return Response::success(['balances' => $balances], 'Marketplace financial summary retrieved');
    }

    #[ApiOperation(
        summary: "Any seller's balance and its §2.9 components (operator)",
        tags: ['Commerce Admin', 'Marketplace']
    )]
    #[ApiResponse(200, description: 'Seller balance retrieved')]
    #[ApiResponse(404, description: 'Unknown seller')]
    public function sellerBalance(Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $this->requireSeller($tenant, $uuid);

        $currencies = $this->balances->currencies($this->context, $tenant, $uuid);
        $balances = array_map(
            fn (string $currency): array => ['currency' => $currency]
                + $this->balances->balance($this->context, $tenant, $uuid, $currency),
            $currencies
        );

        return Response::success(['balances' => $balances], 'Seller balance retrieved');
    }

    #[ApiOperation(
        summary: "Any seller's financial report over a date window (operator)",
        tags: ['Commerce Admin', 'Marketplace']
    )]
    #[ApiResponse(200, description: 'Seller financial report retrieved')]
    #[ApiResponse(404, description: 'Unknown seller')]
    public function sellerReport(SellerFinancialReportQuery $query, Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $this->requireSeller($tenant, $uuid);

        $accountKey = LedgerRepository::accountKeyForSeller($uuid);
        $currency = $query->currency ?? $this->defaultCurrency($tenant, $uuid);
        $window = ReportWindow::fromDates($query->from, $query->to, $query->group ?? 'day');

        $dayRows = $this->reports->reportByDay($this->context, $tenant, $accountKey, $currency, $window);
        ['series' => $series, 'summary' => $summary] = SellerFinancialReportRepository::foldSeries($dayRows, $window);

        $balance = $this->balances->balance($this->context, $tenant, $uuid, $currency);
        $summary['balance_minor'] = $balance['available'];

        return Response::success([
            'seller_uuid' => $uuid,
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

    private function requireSeller(string $tenant, string $sellerUuid): void
    {
        if ($this->sellers->findByUuid($this->context, $tenant, $sellerUuid) === null) {
            throw new NotFoundException('Resource not found.');
        }
    }

    private function defaultCurrency(string $tenant, string $sellerUuid): string
    {
        $currencies = $this->balances->currencies($this->context, $tenant, $sellerUuid);

        return $currencies[0] ?? CommerceSettings::currency($this->context);
    }
}
