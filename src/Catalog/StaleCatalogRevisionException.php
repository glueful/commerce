<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

/**
 * Single-page product editor plan, Task A1/A5: thrown by a future guarded
 * write path when {@see ProductRepository::claimCatalogRevisionExpecting()}
 * returns `'stale'` -- the caller's `expected_revision` snapshot no longer
 * matches the product's current `catalog_revision` because another request
 * modified it first. Defined here only; NOT thrown anywhere in this task --
 * Task A5 wires it into the guarded replacement mutations with the message
 * 'Product was modified by another request.', which their HTTP layer maps to
 * a 409 (never the 404 an unknown/cross-tenant/tombstoned product gets).
 */
final class StaleCatalogRevisionException extends \DomainException
{
}
