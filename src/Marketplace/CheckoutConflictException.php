<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * The controlled marketplace checkout conflict (design spec §2.7): thrown by
 * {@see \Glueful\Extensions\Commerce\Orders\CheckoutService}'s claim protocol
 * when EITHER a participating seller is not `active` (immediate -- retrying
 * cannot fix it) OR a second ownership drift is observed after the single
 * automatic retry has already been spent. `$errorCode` is the stable machine
 * code ('checkout_conflict') a caller maps to an HTTP 409, mirroring the
 * `['code' => ...]` convention {@see \Glueful\Extensions\Commerce\Http\Storefront\OrderController}
 * and {@see \Glueful\Extensions\Commerce\Http\Storefront\DownloadLinkController}
 * already use for a non-exception result -- carried here as a readonly
 * property (the {@see \Glueful\Extensions\Commerce\Marketplace\SellerAllocationException}
 * "readonly-property-carries-the-detail" convention) since this is thrown,
 * not returned. Named `$errorCode`, NOT `$code`: the built-in `\Exception`
 * already declares a non-readonly, non-string `$code` property, and a
 * promoted readonly property of the same name is a PHP fatal error
 * (incompatible redeclaration), not a harmless shadow.
 */
final class CheckoutConflictException extends \DomainException
{
    public function __construct(string $message, public readonly string $errorCode = 'checkout_conflict')
    {
        parent::__construct($message);
    }
}
