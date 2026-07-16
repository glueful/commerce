<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders\Downloads;

/**
 * Thrown by {@see DownloadUrlSigner} when the signed URL cannot be built: the
 * snapshotted blob row is unresolvable, the blob subsystem isn't bound, or
 * `SignedUrl` itself refuses (no configured signing secret). Building the URL is
 * PURE work (design spec §4.1) that runs strictly BEFORE the guarded grant UPDATE,
 * so this exception is always thrown before any mint is consumed -- when it
 * propagates out of {@see DownloadAccessService::mint()}'s transaction, the whole
 * transaction (including the order's financial-mutation claim bump) rolls back.
 */
final class DownloadSigningException extends \RuntimeException
{
}
