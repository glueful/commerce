<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Downloads;

use Glueful\Extensions\Commerce\Orders\Downloads\CommerceDownloadBlobPolicy;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Uploader\Contracts\BlobAccessContext;
use Glueful\Uploader\Contracts\BlobAccessPolicy;
use Glueful\Uploader\Contracts\BlobAccessPolicyRegistry;
use Glueful\Uploader\Contracts\BlobAction;
use Glueful\Uploader\Contracts\CompositeBlobAccessPolicy;
use Glueful\Uploader\Contracts\NullBlobAccessPolicy;

/**
 * Integration coverage through the REAL framework composition seam (design spec
 * §5, Task 1's vendored `BlobAccessPolicyRegistry` / `CompositeBlobAccessPolicy`
 * classes -- not fakes or stubs of either). Proves commerce's own contributor
 * combines with a host application's primary policy under AND/veto semantics in
 * BOTH directions, and exercises the VIEW decision end-to-end through the same
 * composite `UploadController` is handed.
 *
 * Full `UploadController` construction was evaluated and found impractical in
 * this lightweight SQLite harness: `FileUploader::__construct()` unconditionally
 * resolves `BlobRepository` off an AMBIENT/global container accessor
 * (`$this->getContainer()`), which only exists after a real
 * `Framework::create()->boot()` cycle. `CommerceTestCase` deliberately never
 * boots the framework -- it hands services a hand-rolled `ContainerInterface`
 * directly to `ApplicationContext` -- so instantiating `FileUploader` (a
 * dependency `UploadController` requires) throws here. Reconstructing a full
 * boot harness just for this suite would duplicate the framework's OWN Task 1
 * coverage (`tests/Unit/Container/Providers/StorageProviderBlobAccessPolicyTest.php`
 * in `glueful/framework`), which already exhaustively proves `UploadController`
 * receives and honors the live composite via the real DI container. This suite
 * instead drives `CompositeBlobAccessPolicy` directly with a real
 * `BlobAccessContext` carrying the exact `signatureValid` boolean
 * `UploadController::show()` would have computed via its own
 * `hasValidSignature()` before ever consulting the policy -- the same
 * authorization decision, one layer below the HTTP plumbing.
 */
final class BlobPolicyIntegrationTest extends CommerceTestCase
{
    private const CREATOR = 'creator0001';
    private const OTHER_USER = 'someoneuuid1';

    // -----------------------------------------------------------------
    // Combined denial, both directions
    // -----------------------------------------------------------------

    public function testHostPrimaryDenialWinsEvenWhenCommerceContributorIsNeutral(): void
    {
        // Unreferenced blob: commerce's own contributor is neutral (allows) for
        // it, but a denying host primary must still veto the whole composite.
        $composite = $this->composite(primaryAllows: false);
        $blob = ['uuid' => 'blobhost0001', 'created_by' => self::CREATOR];

        self::assertFalse(
            $composite->authorizeAccess(
                $blob,
                new BlobAccessContext(BlobAction::VIEW, self::CREATOR, true)
            ),
            'host primary must veto even when the blob is not a commerce download at all'
        );
    }

    public function testCommerceContributorDenialWinsEvenWhenHostPrimaryAllows(): void
    {
        // Grant-referenced blob viewed by neither the creator nor a valid
        // signature: commerce's own contributor denies; a fully permissive
        // host primary must not override that veto.
        $this->insertOrder('ordhost00001');
        $this->insertGrant('blobhost0002', 'ordhost00001', 'granthost001', remaining: 5);
        $composite = $this->composite(primaryAllows: true);
        $blob = ['uuid' => 'blobhost0002', 'created_by' => self::CREATOR];

        self::assertFalse(
            $composite->authorizeAccess(
                $blob,
                new BlobAccessContext(BlobAction::VIEW, self::OTHER_USER, false)
            ),
            'commerce contributor must veto even when the host primary allows everything'
        );
    }

    public function testBothPrimaryAndContributorAllowingProducesOverallAllow(): void
    {
        $composite = $this->composite(primaryAllows: true);
        $blob = ['uuid' => 'blobhost0003', 'created_by' => self::CREATOR];

        self::assertTrue(
            $composite->authorizeAccess($blob, new BlobAccessContext(BlobAction::VIEW, null, false))
        );
    }

    // -----------------------------------------------------------------
    // End-to-end VIEW decision (see class docblock for the UploadController
    // construction tradeoff)
    // -----------------------------------------------------------------

    public function testGrantReferencedBlobViewDeniedUnsignedThenAllowedWithValidSignature(): void
    {
        $this->insertOrder('ordview00001');
        $this->insertGrant('blobview0001', 'ordview00001', 'grantview001', remaining: 5);
        $composite = $this->composite(primaryAllows: true);
        $blob = ['uuid' => 'blobview0001', 'created_by' => self::CREATOR];

        self::assertFalse(
            $composite->authorizeAccess(
                $blob,
                new BlobAccessContext(BlobAction::VIEW, self::OTHER_USER, false)
            ),
            'an unsigned, non-creator request must be denied (mirrors a direct blob GET returning 404)'
        );

        self::assertTrue(
            $composite->authorizeAccess(
                $blob,
                new BlobAccessContext(BlobAction::VIEW, self::OTHER_USER, true)
            ),
            'a valid signature must allow VIEW even for a non-creator (mirrors a valid signed blob URL)'
        );
    }

    public function testGrantReferencedBlobSignIsAlwaysDeniedThroughTheRealComposite(): void
    {
        // Core's generic signed-URL endpoint must never bypass commerce's own
        // grant-consumption mint flow (design spec §4.1/§5) -- proven through
        // the real composite, not just the isolated policy unit test.
        $this->insertOrder('ordsign00001');
        $this->insertGrant('blobsign0001', 'ordsign00001', 'grantsign001', remaining: 5);
        $composite = $this->composite(primaryAllows: true);
        $blob = ['uuid' => 'blobsign0001', 'created_by' => self::CREATOR];

        self::assertFalse(
            $composite->authorizeAccess(
                $blob,
                new BlobAccessContext(BlobAction::SIGN, self::CREATOR, false)
            )
        );
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function composite(bool $primaryAllows): CompositeBlobAccessPolicy
    {
        $registry = new BlobAccessPolicyRegistry();
        $registry->register('commerce.downloads', new CommerceDownloadBlobPolicy($this->context));

        $primary = $primaryAllows
            ? new NullBlobAccessPolicy()
            : new class implements BlobAccessPolicy {
                public function authorizeAccess(array $blob, BlobAccessContext $context): bool
                {
                    return false;
                }
            };

        return new CompositeBlobAccessPolicy($primary, $registry);
    }

    private function insertOrder(string $uuid, int $grandTotal = 1000, int $refundedTotal = 0): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => $grandTotal,
            'grand_total' => $grandTotal,
            'refunded_total' => $refundedTotal,
        ]);
    }

    private function insertGrant(string $blobUuid, string $orderUuid, string $uuid, ?int $remaining = null): void
    {
        $this->connection->table('commerce_download_grants')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'order_uuid' => $orderUuid,
            'download_uuid' => 'def0000001',
            'blob_uuid' => $blobUuid,
            'name' => 'File.pdf',
            'token_hash' => hash('sha256', $uuid),
            'remaining' => $remaining,
        ]);
    }
}
