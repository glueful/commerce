<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * The order this lifecycle write addressed is NOT THERE -- unknown uuid, a
 * cross-tenant uuid (which is the same fact from the caller's side, by design),
 * or a row that vanished under a concurrent deletion.
 *
 * Raised by {@see OrderRepository::transition()}, which used to report the same
 * fact as a bare `\RuntimeException` separable only by matching its message --
 * so every caller logged an ordinary "this uuid does not exist" as a 500. With
 * a type on it, a caller can classify: an operator surface answers its usual
 * non-revealing 404, a background sweep can skip the row, and only a genuinely
 * unexpected failure keeps its 500.
 *
 * Extends `\RuntimeException` -- deliberately NOT the framework's HTTP
 * `NotFoundException` -- for two reasons: this is the ORDERS domain reporting a
 * domain fact, and it keeps the class BC with every `catch (\RuntimeException)`
 * that existed before the type did. Mapping to HTTP stays the caller's job,
 * exactly as it is for {@see DraftConflictException} and every other typed
 * exception in this namespace.
 *
 * `$orderUuid` is carried for logs and for a caller that wants to name the row;
 * the MESSAGE stays the generic 'Order not found.' it has always been, so
 * nothing that reaches a client can turn this into an existence oracle.
 */
final class OrderNotFoundException extends \RuntimeException
{
    public function __construct(
        public readonly string $orderUuid,
        string $message = 'Order not found.',
    ) {
        parent::__construct($message);
    }

    public static function forUuid(string $orderUuid): self
    {
        return new self($orderUuid);
    }
}
