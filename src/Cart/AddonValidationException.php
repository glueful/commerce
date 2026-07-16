<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Cart;

/**
 * Thrown by {@see AddonSnapshot::build()} for every validation-matrix violation
 * (unknown/duplicate addon_uuid, missing required addon, invalid choice_key,
 * checkbox non-boolean, text empty/over 500 chars) and for the internal
 * `variantPrice + Σdeltas >= 0` invariant. Also thrown by
 * {@see \Glueful\Extensions\Commerce\Cart\CartService::pricedLines()} when a
 * PERSISTED snapshot defensively computes a negative unit price (fail closed).
 *
 * A `\DomainException`, not the framework's `ValidationException` -- this class
 * stays pure/framework-agnostic (see AddonSnapshot's class docblock). Callers that
 * need an HTTP-shaped 422 (e.g. `CartService::addLine()`) catch this and translate
 * it into `ValidationException::forField('addons', ...)` at the point of use.
 */
final class AddonValidationException extends \DomainException
{
}
