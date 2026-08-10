<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

/**
 * A TYPED 422 rejection of a draft line mutation (admin-order-creation cycle 2,
 * Task 9, design spec §2.3/Ruling 9), carrying one of
 * {@see DraftLineEligibility}'s CLOSED reasons.
 *
 * This is deliberately NOT a plain `ValidationException`: the SPA already knows
 * the reason vocabulary (the admin product search publishes the same strings on
 * every row as `admin_draft_ineligible_reason`), so the rejection must be
 * branchable rather than a human string it would have to pattern-match. The
 * reason travels on the wire as `error.details.reason`.
 *
 * Rejection happens BEFORE any write, so a rejected line mutation leaves the
 * draft -- and its `draft_revision` -- completely untouched.
 */
final class DraftLineRejectedException extends \RuntimeException
{
    /** @param string $reason one of {@see DraftLineEligibility::REASONS} */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function forReason(string $reason, ?string $detail = null): self
    {
        $message = match ($reason) {
            DraftLineEligibility::DIGITAL =>
                'Digital products cannot be added to an admin draft order.',
            DraftLineEligibility::MARKETPLACE =>
                'Marketplace seller products cannot be added to an admin draft order.',
            default => $detail ?? 'This product is not available to add to a draft order.',
        };

        return new self($reason, $message);
    }
}
