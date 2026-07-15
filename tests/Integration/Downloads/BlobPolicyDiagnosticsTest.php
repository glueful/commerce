<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Downloads;

use Glueful\Extensions\Commerce\Orders\Downloads\CommerceDownloadBlobPolicy;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Uploader\Contracts\BlobAccessPolicyRegistry;

/**
 * `DiagnosticsReport::build()['blob_policy']` (design spec §5): 'active' only
 * when the framework's `BlobAccessPolicyRegistry` (Task 1's composition seam) is
 * bound in the container AND commerce's own contributor is registered under it;
 * 'unavailable' otherwise -- covering both ways that can happen: the registry
 * class isn't bound at all (a framework build without the Task 1 seam), or it IS
 * bound but commerce never registered into it (boot() never ran / failed before
 * reaching that step).
 */
final class BlobPolicyDiagnosticsTest extends CommerceTestCase
{
    public function testUnavailableWhenRegistryIsNotBoundAtAll(): void
    {
        // CommerceTestCase's fake container has no BlobAccessPolicyRegistry
        // binding by default -- simulating a framework build without Task 1's
        // seam.
        self::assertSame('unavailable', DiagnosticsReport::build($this->context)['blob_policy']);
    }

    public function testUnavailableWhenRegistryIsBoundButContributorNeverRegistered(): void
    {
        $this->bind(BlobAccessPolicyRegistry::class, new BlobAccessPolicyRegistry());

        self::assertSame('unavailable', DiagnosticsReport::build($this->context)['blob_policy']);
    }

    public function testActiveWhenRegistryIsBoundAndContributorIsRegistered(): void
    {
        $registry = new BlobAccessPolicyRegistry();
        $registry->register('commerce.downloads', new CommerceDownloadBlobPolicy($this->context));
        $this->bind(BlobAccessPolicyRegistry::class, $registry);

        self::assertSame('active', DiagnosticsReport::build($this->context)['blob_policy']);
    }
}
