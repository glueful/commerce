<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Payments;

/**
 * The one Commerce payable type. Every payment/refund/chargeback call site that
 * needs to identify "this payable is a Commerce order" -- as opposed to some
 * other extension's own payable kind -- consumes {@see self::TYPE} instead of
 * re-declaring the `'commerce_order'` string literal in its own scope.
 */
final class OrderPayable
{
    public const TYPE = 'commerce_order';
}
