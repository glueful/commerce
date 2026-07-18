<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\AttachPayoutAccountData;
use Glueful\Extensions\Commerce\Http\DTOs\ExecutePayoutData;
use Glueful\Extensions\Commerce\Http\DTOs\PostAdjustmentData;
use Glueful\Extensions\Commerce\Http\DTOs\RecordPayoutData;
use Glueful\Extensions\Commerce\Http\DTOs\SyncPayoutAccountData;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentException;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentService;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountService;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
use Glueful\Extensions\Commerce\Marketplace\PayoutOutcomeUnknownException;
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
        private ?PayoutAccountService $payoutAccounts = null,
    ) {
        $this->payouts ??= app($context, PayoutService::class);
        $this->adjustments ??= app($context, AdjustmentService::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        $this->payoutAccounts ??= app($context, PayoutAccountService::class);
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

    /**
     * `POST /marketplace/payouts/execute` (design spec §2.3/§2.6, MV4 Task 10): the operator
     * single-seller provider-payout saga -- reserve -> execute -> finalize
     * ({@see PayoutService::execute()}). Deliberately NOT batch (design spec §2.6: "operator
     * HTTP is single-seller only" -- batches are CLI-only). A non-ready destination or an
     * amount exceeding the seller's available balance is a `422`
     * {@see PayoutException} -- caught here explicitly, since `PayoutException` is a plain
     * `\DomainException` with no framework HTTP mapping (would otherwise fall through to the
     * generic 500 handler). An ambiguous transfer outcome
     * ({@see PayoutOutcomeUnknownException}) mirrors {@see AdminRefundController::store()}'s
     * own `RefundOutcomeUnknownException` handling -- `503`, never a 500 or a silently-lost
     * exception.
     */
    #[ApiOperation(summary: 'Execute a provider payout for one seller', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Payout executed')]
    #[ApiResponse(
        422,
        description: 'No ready payout destination, no provider configured, or amount exceeds available balance'
    )]
    #[ApiResponse(503, description: 'Payout outcome unknown; reconcile before retrying')]
    public function executePayout(ExecutePayoutData $input, Request $request): Response
    {
        try {
            $payout = $this->payouts->execute(
                $this->context,
                $this->tenants->tenantUuid($this->context),
                $input->seller_uuid,
                $input->currency,
                $input->amount,
                $this->actorUuid($request)
            );

            return Response::success($payout, 'Payout executed');
        } catch (PayoutException $e) {
            return Response::validation(['payout' => $e->getMessage()]);
        } catch (PayoutOutcomeUnknownException $e) {
            return Response::error($e->getMessage(), 503);
        }
    }

    /**
     * `POST /marketplace/payouts/{uuid}/retry` (design spec §2.6, MV4 Task 10): retries ONLY a
     * payout that is currently `failed AND retryable AND attempt_count < max_attempts` --
     * {@see PayoutService::retry()} uses the SAME guarded CAS
     * ({@see \Glueful\Extensions\Commerce\Marketplace\PayoutRepository::claimRetryableForAttempt()})
     * the retry sweep itself uses, but passes `ignoreDueTime: true` (design spec §2.6: "the
     * operator retry uses the same claim but may ignore the due time") -- an operator retrying a
     * specific payout is asking to retry NOW, not to wait out the backoff window the scheduled
     * sweep still respects. Every OTHER guard stays unconditional: a `null` return (already
     * claimed by a concurrent sweep, exhausted, or not retryable/not failed at all) is a `422` --
     * it NEVER resurrects a terminal row; the caller must record/execute a fresh payout instead.
     */
    #[ApiOperation(summary: 'Retry a specific failed, retryable payout', tags: ['Commerce Admin', 'Marketplace'])]
    #[ApiResponse(200, description: 'Payout retried')]
    #[ApiResponse(422, description: 'The payout is not currently retryable -- create a new payout instead')]
    #[ApiResponse(503, description: 'Payout outcome unknown; reconcile before retrying')]
    public function retryPayout(Request $request, string $uuid): Response
    {
        try {
            $result = $this->payouts->retry(
                $this->context,
                $this->tenants->tenantUuid($this->context),
                $uuid,
                ignoreDueTime: true
            );
        } catch (PayoutException $e) {
            return Response::validation(['payout' => $e->getMessage()]);
        } catch (PayoutOutcomeUnknownException $e) {
            return Response::error($e->getMessage(), 503);
        }

        if ($result === null) {
            return Response::validation([
                'payout' => 'This payout is not currently retryable (terminal, exhausted, or already claimed '
                    . 'by a concurrent retry) -- create a new payout instead.',
            ]);
        }

        return Response::success($result, 'Payout retried');
    }

    /**
     * `POST /marketplace/payouts/accounts` (design spec §2.7, MV4 Task 10): attach (insert or
     * replace) a seller's opaque provider destination reference
     * ({@see PayoutAccountService::attach()}) -- always lands `readiness_state = pending`; there
     * is no operator "mark ready" here or anywhere else.
     */
    #[ApiOperation(
        summary: "Attach a seller's opaque payout-provider account reference",
        tags: ['Commerce Admin', 'Marketplace']
    )]
    #[ApiResponse(200, description: 'Payout account attached')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function attachPayoutAccount(AttachPayoutAccountData $input, Request $request): Response
    {
        try {
            $account = $this->payoutAccounts->attach(
                $this->context,
                $this->tenants->tenantUuid($this->context),
                $input->seller_uuid,
                $input->provider,
                $input->account_ref,
                $this->actorUuid($request)
            );

            return Response::success($account, 'Payout account attached');
        } catch (PayoutException $e) {
            return Response::validation(['account' => $e->getMessage()]);
        }
    }

    /**
     * `POST /marketplace/payouts/accounts/sync` (design spec §2.7, MV4 Task 10): a DNS-style
     * readiness sync trigger -- {@see PayoutAccountService::sync()} calls
     * `PayoutCollector::inspectDestination()` and applies whatever the provider reports.
     * Readiness is NEVER operator-asserted; this endpoint only ever forwards the provider's own
     * answer. A missing account row (never attached for this seller/provider) is a plain `404`
     * -- {@see \Glueful\Http\Exceptions\Client\NotFoundException} already maps there via the
     * framework's own exception handler, so it is never caught here.
     */
    #[ApiOperation(
        summary: "Sync a seller's payout-account readiness from the provider",
        tags: ['Commerce Admin', 'Marketplace']
    )]
    #[ApiResponse(200, description: 'Payout account synced')]
    #[ApiResponse(404, description: 'No payout account is attached for this seller/provider')]
    #[ApiResponse(422, description: 'No payout provider is configured')]
    public function syncPayoutAccount(SyncPayoutAccountData $input, Request $request): Response
    {
        try {
            $account = $this->payoutAccounts->sync(
                $this->context,
                $this->tenants->tenantUuid($this->context),
                $input->seller_uuid,
                $input->provider
            );

            return Response::success($account, 'Payout account synced');
        } catch (PayoutException $e) {
            return Response::validation(['account' => $e->getMessage()]);
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
