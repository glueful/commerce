<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

/**
 * Thrown by CategoryService when a create-with-parent or reparent mutation's
 * post-claim re-read of the (new) parent's ancestor chain finds it no longer
 * matches the pre-transaction snapshot the claim set was built from -- i.e. the
 * tree's shape changed concurrently between the snapshot and the claim (see
 * CategoryService's class docblock). Retryable: on retry a fresh snapshot
 * reflects the now-current tree, and the claim set will match reality.
 */
final class ConcurrentCatalogMutationException extends \DomainException
{
}
