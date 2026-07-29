<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Wishlist;

/**
 * What an import actually did.
 *
 * The caller holds a device-local list it may want to clear afterwards, so it must be told
 * exactly which uuids landed. `overflow` exists so a list that did not fit is preserved by the
 * caller instead of vanishing: the account is capped, but the visitor's saved items are not
 * the framework's to discard.
 */
final class WishlistImportResult
{
    /**
     * @param list<string> $imported    newly added to the account
     * @param list<string> $unavailable dropped: unknown or not buyer-available
     * @param list<string> $overflow    valid, but the account was at its cap
     */
    public function __construct(
        public readonly array $imported,
        public readonly array $unavailable,
        public readonly array $overflow,
    ) {
    }
}
