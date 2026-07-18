<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\PostAdjustmentData;
use Glueful\Extensions\Commerce\Http\DTOs\RecordPayoutData;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentException;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentService;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Operator settlement mutations (design spec §2.10/§6.1): manual payout
 * recording and ledger adjustments. Both are `commerce:write`-gated,
 * marketplace-enabled-only surfaces (see `routes.php`) -- neither has any
 * meaning while the install master switch is off, since no seller account
 * could ever have a ledger balance.
 */
final class AdminPayoutController
{
    use ResolvesActor;

    public function __construct(
        private ApplicationContext $context,
        private ?PayoutService $payouts = null,
        private ?AdjustmentService $adjustments = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->payouts ??= app($context, PayoutService::class);
        $this->adjustments ??= app($context, AdjustmentService::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    #[ApiOperation(summary: 'Record a manual seller payout', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Payout recorded')]
    #[ApiResponse(422, description: 'Validation failed or amount exceeds available balance')]
    public function storePayout(RecordPayoutData $input, Request $request): Response
    {
        try {
            $payout = $this->payouts->record(
                $this->context,
                $this->tenants->tenantUuid($this->context),
                $input->seller_uuid,
                $input->currency,
                $input->amount,
                $input->idempotency_key,
                $input->external_ref,
                $input->note,
                $this->actorUuid($request) ?? ''
            );

            return Response::success($payout, 'Payout recorded');
        } catch (PayoutException $e) {
            return Response::validation(['payout' => $e->getMessage()]);
        }
    }

    #[ApiOperation(summary: 'Post an operator ledger adjustment', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Adjustment posted')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function storeAdjustment(PostAdjustmentData $input, Request $request): Response
    {
        $accountKey = $this->resolveAccountKey($input);
        if ($accountKey === null) {
            return Response::validation([
                'account' => 'Provide either a seller_uuid or account="marketplace".',
            ]);
        }

        try {
            $this->adjustments->post(
                $this->context,
                $this->tenants->tenantUuid($this->context),
                $accountKey,
                $input->currency,
                $input->amount,
                $input->reason,
                $input->idempotency_key,
                $this->actorUuid($request) ?? ''
            );

            return Response::success(['account_key' => $accountKey], 'Adjustment posted');
        } catch (AdjustmentException $e) {
            return Response::validation(['adjustment' => $e->getMessage()]);
        }
    }

    private function resolveAccountKey(PostAdjustmentData $input): ?string
    {
        if ($input->seller_uuid !== null && $input->seller_uuid !== '') {
            return LedgerRepository::accountKeyForSeller($input->seller_uuid);
        }

        if ($input->account === LedgerRepository::MARKETPLACE_ACCOUNT_KEY) {
            return LedgerRepository::MARKETPLACE_ACCOUNT_KEY;
        }

        return null;
    }
}
