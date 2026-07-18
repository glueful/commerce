<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown by {@see CommissionPolicyResolver::validate()} for every invalid
 * commission-policy shape (design spec §2.2): `commission_kind`/`commission_bps`/
 * `commission_fixed` must be either all null (inherit the next precedence
 * level) or a valid concrete policy -- `percentage` with `commission_bps` in
 * 0..10000 and `commission_fixed` null, or `fixed` with a non-negative
 * `commission_fixed` and `commission_bps` null. Also thrown by
 * {@see CommissionPolicyResolver::resolve()} when the config tail (the
 * final, non-inheritable level in the product -> seller -> workspace ->
 * config precedence chain) is itself all-null, which would make policy
 * resolution non-total.
 *
 * A `\DomainException` -- an operator-input validation error, not a runtime
 * integrity failure -- so the policy setter can surface it as a 422,
 * mirroring {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundValidationException}.
 * Distinct from the calculator's hard-reconciliation guardrail, which is a
 * `\RuntimeException` per house convention.
 */
final class CommissionPolicyException extends \DomainException
{
}
