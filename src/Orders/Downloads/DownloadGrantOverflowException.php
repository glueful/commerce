<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders\Downloads;

/**
 * `remaining = download_limit * Σquantity` (design spec §3) overflowed PHP's native
 * int range. Both operands come from trusted, already-persisted state (a purchase-time
 * snapshot and summed order-line quantities), so this signals corrupt/adversarial data
 * rather than a normal business condition — issuance for the whole order aborts rather
 * than silently wrapping or truncating the entitlement count.
 */
final class DownloadGrantOverflowException extends \OverflowException
{
    public function __construct(
        public readonly string $downloadUuid,
        public readonly int $limit,
        public readonly int $quantity,
    ) {
        parent::__construct(
            "Download grant remaining overflow for download {$downloadUuid}: "
            . "limit ({$limit}) x quantity ({$quantity}) exceeds PHP_INT_MAX."
        );
    }
}
