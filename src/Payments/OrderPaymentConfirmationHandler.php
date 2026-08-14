<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Payments;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmation;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmationHandler;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

final class OrderPaymentConfirmationHandler implements PaymentConfirmationHandler
{
    public function __construct(
        private OrderRepository $orders,
        private OrderPaymentService $payments,
        private CurrentTenantResolver $tenants,
    ) {
    }

    public function supports(string $payableType): bool
    {
        return $payableType === OrderPayable::TYPE;
    }

    public function confirmed(
        ApplicationContext $context,
        PayableReference $payable,
        PaymentConfirmation $confirmation
    ): void {
        if (!$this->supports($payable->type)) {
            return;
        }

        $tenant = $this->tenants->tenantUuid($context);
        $order = $this->orders->findByUuid($context, $tenant, $payable->id);
        if ($order === null) {
            return;
        }

        if (
            $confirmation->amount !== (int) $order['grand_total']
            || $confirmation->currency !== (string) $order['currency']
        ) {
            $this->orders->recordEvent($context, (string) $order['uuid'], 'payment_amount_mismatch', [
                'expected' => [
                    'amount' => (int) $order['grand_total'],
                    'currency' => (string) $order['currency'],
                ],
                'actual' => [
                    'amount' => $confirmation->amount,
                    'currency' => $confirmation->currency,
                ],
                'reference' => $confirmation->reference,
            ]);
            return;
        }

        // The status read above and the settlement below are two observations of
        // a moving world: a second settlement path (the admin mark-paid endpoint,
        // a retried or duplicate webhook delivery) can win the
        // `pending_payment -> paid` compare-and-set in between. That loser used
        // to escape as a bare 500 -- the provider then retried, read `paid`, and
        // took the late-payment branch below anyway.
        //
        // `markPaid()` now answers that race idempotently and REPORTS it, so
        // this handler reaches the identical destination directly: a conceded
        // settlement falls through to `rejectLatePayment()`, which is precisely
        // what this method would have done had its status read landed a moment
        // later. One real-world situation, one outcome, whatever the timing --
        // and a late provider payment that may need refunding stays as
        // discoverable as it has always been.
        if (($order['status'] ?? '') === 'pending_payment') {
            if ($this->payments->markPaid($context, $tenant, (string) $order['uuid'])) {
                return;
            }
        }

        $this->payments->rejectLatePayment($context, $tenant, (string) $order['uuid'], [
            'reference' => $confirmation->reference,
            'status' => $confirmation->status,
            'amount' => $confirmation->amount,
            'currency' => $confirmation->currency,
        ]);
    }
}
