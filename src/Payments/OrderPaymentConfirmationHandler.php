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

        if (($order['status'] ?? '') === 'pending_payment') {
            $this->payments->markPaid($context, $tenant, (string) $order['uuid']);
            return;
        }

        $this->payments->rejectLatePayment($context, $tenant, (string) $order['uuid'], [
            'reference' => $confirmation->reference,
            'status' => $confirmation->status,
            'amount' => $confirmation->amount,
            'currency' => $confirmation->currency,
        ]);
    }
}
