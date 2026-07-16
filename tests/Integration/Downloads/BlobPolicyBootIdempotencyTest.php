<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Downloads;

use Glueful\Extensions\Commerce\CommerceServiceProvider;
use Glueful\Extensions\Commerce\Orders\Downloads\CommerceDownloadBlobPolicy;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Uploader\Contracts\BlobAccessPolicyRegistry;

/**
 * T6 review finding (Low): `CommerceServiceProvider::boot()` registered
 * `'commerce.downloads'` into the shared `BlobAccessPolicyRegistry` unguarded --
 * a second `boot()` call against a registry instance that survives the first
 * (e.g. a re-boot in the same process, as several test/dev harnesses do) throws
 * `\LogicException` from `BlobAccessPolicyRegistry::register()`, which is never
 * caught non-fatally: `boot()`'s catch block for this step only swallows the
 * exception in a 'production' `APP_ENV` (see `bootEnv()`); anywhere else it
 * rethrows. This pins `boot()` being safe to call twice against the SAME
 * registry instance, both in a non-production env (where the bug would
 * previously have propagated and crashed boot) and by asserting the registry
 * still resolves the contributor afterward.
 */
final class BlobPolicyBootIdempotencyTest extends CommerceTestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['APP_ENV']);
        parent::tearDown();
    }

    public function testSecondBootAgainstASurvivingRegistryDoesNotThrow(): void
    {
        // Force bootEnv() away from its 'production' default so a regression
        // (the unguarded register() call) propagates as a thrown exception
        // here instead of being silently swallowed by boot()'s own catch block.
        $_ENV['APP_ENV'] = 'testing';

        $registry = new BlobAccessPolicyRegistry();
        $this->bind(BlobAccessPolicyRegistry::class, $registry);
        $this->bind(CommerceDownloadBlobPolicy::class, new CommerceDownloadBlobPolicy($this->context));

        $provider = new CommerceServiceProvider($this->contextContainer());

        $provider->boot($this->context);
        $provider->boot($this->context);

        self::assertTrue($registry->has('commerce.downloads'));
        self::assertInstanceOf(CommerceDownloadBlobPolicy::class, $registry->all()['commerce.downloads']);
    }
}
