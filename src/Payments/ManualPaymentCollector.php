<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Payments;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;

final class ManualPaymentCollector implements PaymentCollector
{
    public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
    {
        return new PaymentInitiation('manual', 'manual', [
            'instructions' => 'Payment is collected manually; an operator will mark this order paid.',
        ]);
    }
}
