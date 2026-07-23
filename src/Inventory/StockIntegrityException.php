<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Inventory;

/**
 * Thrown when a product's variant set includes one or more variants with no
 * matching `commerce_stock` row (single-page product editor plan, Task A4;
 * Global Constraints: "A missing commerce_stock row is an integrity
 * failure, never synthetic untracked/zero stock; the read fails loudly and
 * diagnostics report the drift"). Every LIVE variant is supposed to gain its
 * stock row at creation time via {@see StockRepository::ensureRow()} -- this
 * is a genuine server-side data-integrity failure, not a client mistake, so
 * it is deliberately a plain \RuntimeException rather than a
 * Glueful\Http\Exceptions client exception: it is left to bubble to the
 * framework's default 500-class handler instead of being mapped to any 4xx
 * response. {@see \Glueful\Extensions\Commerce\Support\DiagnosticsReport}
 * reports the same drift proactively, cross-tenant, via
 * {@see StockRepository::variantsMissingStock()}.
 */
final class StockIntegrityException extends \RuntimeException
{
}
