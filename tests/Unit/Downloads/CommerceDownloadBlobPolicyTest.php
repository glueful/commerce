<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Downloads;

use Glueful\Extensions\Commerce\Orders\Downloads\CommerceDownloadBlobPolicy;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Uploader\Contracts\BlobAccessContext;
use Glueful\Uploader\Contracts\BlobAction;

/**
 * Decision-table coverage for {@see CommerceDownloadBlobPolicy} (design spec §5,
 * verbatim-binding). Direct construction against the SQLite harness -- no HTTP, no
 * framework composite.
 *
 * The decision table has two independent axes:
 *  - VIEW/INFO/SIGN depend ONLY on whether the blob is "referenced" (a
 *    `commerce_downloads` row OR a `commerce_download_grants` row exists for it,
 *    regardless of a grant's validity or a definition's active/inactive status)
 *    plus the caller's signature/creator credentials. This mechanism is identical
 *    no matter WHICH kind of row makes the blob referenced, so it is exercised
 *    fully once via a definition-referenced blob and once via a grant-referenced
 *    (active) blob, then spot-checked (worst case: neither credential) against
 *    every other grant validity state to prove "referenced" never degrades to
 *    "valid" for these three actions.
 *  - DELETE depends on the FULL validity picture (definition presence, grant
 *    retained-access, live-signed-URL window) and is exercised for every row of
 *    that matrix independently, since that is where the real behavioral
 *    differences live.
 */
final class CommerceDownloadBlobPolicyTest extends CommerceTestCase
{
    private const CREATOR = 'creator0001';
    private const OTHER_USER = 'someoneuuid1';
    private const DEF_UUID = 'def0000001';

    // -----------------------------------------------------------------
    // Unreferenced: neutral for every action
    // -----------------------------------------------------------------

    public function testUnreferencedBlobIsNeutralForEveryAction(): void
    {
        $blob = $this->blob('blobunref001');

        foreach ([BlobAction::VIEW, BlobAction::INFO, BlobAction::SIGN, BlobAction::DELETE] as $action) {
            self::assertTrue(
                $this->policy()->authorizeAccess($blob, $this->ctx($action, self::OTHER_USER, false)),
                "Unreferenced blob must be neutral for {$action->value}"
            );
        }
    }

    // -----------------------------------------------------------------
    // Definition-referenced: full VIEW/INFO/SIGN/DELETE matrix
    // -----------------------------------------------------------------

    public function testDefinitionReferencedViewAllowedBySignatureAlone(): void
    {
        $blob = $this->blob('blobdef00001');
        $this->insertDefinition('blobdef00001');

        self::assertTrue(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::VIEW, null, true))
        );
    }

    public function testDefinitionReferencedViewAllowedByCreatorAlone(): void
    {
        $blob = $this->blob('blobdef00002');
        $this->insertDefinition('blobdef00002');

        self::assertTrue(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::VIEW, self::CREATOR, false))
        );
    }

    public function testDefinitionReferencedViewDeniedWithNeitherSignatureNorCreator(): void
    {
        $blob = $this->blob('blobdef00003');
        $this->insertDefinition('blobdef00003');

        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::VIEW, self::OTHER_USER, false))
        );
    }

    public function testDefinitionReferencedInfoAllowedForCreatorRegardlessOfSignatureFlag(): void
    {
        $blob = $this->blob('blobdef00004');
        $this->insertDefinition('blobdef00004');

        // Core's info() action hardcodes signatureValid=false (verified against
        // UploadController::info()), but the policy must not depend on that
        // detail either way -- creator access must not accidentally require it.
        self::assertTrue(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::INFO, self::CREATOR, false))
        );
        self::assertTrue(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::INFO, self::CREATOR, true))
        );
    }

    public function testDefinitionReferencedInfoDeniedForNonCreatorEvenWithValidSignature(): void
    {
        // The INFO finding: unlike VIEW, a valid signature never substitutes for
        // creator identity on INFO, because core's info() route never validates
        // a signature in the first place (it always passes signatureValid=false
        // to the policy) -- so honoring signatureValid=true here would be
        // trusting a flag core itself never lets be true for this action.
        $blob = $this->blob('blobdef00005');
        $this->insertDefinition('blobdef00005');

        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::INFO, self::OTHER_USER, true))
        );
    }

    public function testDefinitionReferencedInfoDeniedWithNeitherSignatureNorCreator(): void
    {
        $blob = $this->blob('blobdef00006');
        $this->insertDefinition('blobdef00006');

        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::INFO, self::OTHER_USER, false))
        );
    }

    public function testDefinitionReferencedSignAlwaysDeniedRegardlessOfCredentials(): void
    {
        $blob = $this->blob('blobdef00007');
        $this->insertDefinition('blobdef00007');

        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::SIGN, self::CREATOR, true))
        );
        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::SIGN, self::OTHER_USER, false))
        );
    }

    public function testDefinitionReferencedBlocksDelete(): void
    {
        $blob = $this->blob('blobdef00008');
        $this->insertDefinition('blobdef00008');

        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::DELETE, null, false))
        );
    }

    // -----------------------------------------------------------------
    // Grant-referenced, active/live: same VIEW/INFO/SIGN gating; blocks DELETE
    // -----------------------------------------------------------------

    public function testGrantActiveReferencedGatesViewSameAsDefinition(): void
    {
        $blob = $this->blob('blobgra00001');
        $this->insertOrder('ordgra000001');
        $this->insertGrant('blobgra00001', 'ordgra000001', 'grantgra0001', remaining: 5);

        self::assertTrue(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::VIEW, null, true)),
            'signature alone must allow VIEW'
        );
        self::assertTrue(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::VIEW, self::CREATOR, false)),
            'creator alone must allow VIEW'
        );
        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::VIEW, self::OTHER_USER, false)),
            'neither credential must deny VIEW'
        );
    }

    public function testGrantActiveReferencedGatesInfoSameAsDefinition(): void
    {
        $blob = $this->blob('blobgra00002');
        $this->insertOrder('ordgra000002');
        $this->insertGrant('blobgra00002', 'ordgra000002', 'grantgra0002', remaining: 5);

        self::assertTrue(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::INFO, self::CREATOR, false))
        );
        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::INFO, self::OTHER_USER, true)),
            'INFO must ignore signature even for a grant-referenced blob'
        );
    }

    public function testGrantActiveReferencedSignAlwaysDenied(): void
    {
        $blob = $this->blob('blobgra00003');
        $this->insertOrder('ordgra000003');
        $this->insertGrant('blobgra00003', 'ordgra000003', 'grantgra0003', remaining: 5);

        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::SIGN, self::CREATOR, true))
        );
    }

    public function testGrantActiveRetainsAccessBlocksDelete(): void
    {
        $blob = $this->blob('blobgra00004');
        $this->insertOrder('ordgra000004');
        $this->insertGrant('blobgra00004', 'ordgra000004', 'grantgra0004', remaining: 5);

        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::DELETE, null, false))
        );
    }

    // -----------------------------------------------------------------
    // Grant validity states: "referenced" persists for VIEW/INFO/SIGN even when
    // the grant itself is no longer valid; DELETE differs per state.
    // -----------------------------------------------------------------

    public function testGrantRevokedStillGatesViewButAllowsDelete(): void
    {
        $blob = $this->blob('blobgrv00001');
        $this->insertOrder('ordgrv000001');
        $this->insertGrant(
            'blobgrv00001',
            'ordgrv000001',
            'grantgrv0001',
            remaining: 5,
            revokedAt: gmdate('Y-m-d H:i:s')
        );

        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::VIEW, self::OTHER_USER, false)),
            'a revoked grant snapshot still references the blob and must still gate VIEW'
        );
        self::assertTrue(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::DELETE, null, false)),
            'a revoked grant no longer retains access, so it must not block DELETE'
        );
    }

    public function testGrantExpiredAllowsDelete(): void
    {
        $blob = $this->blob('blobgrx00001');
        $this->insertOrder('ordgrx000001');
        $this->insertGrant(
            'blobgrx00001',
            'ordgrx000001',
            'grantgrx0001',
            remaining: 5,
            expiresAt: '2000-01-01 00:00:00'
        );

        self::assertTrue(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::DELETE, null, false))
        );
    }

    public function testGrantExhaustedAllowsDelete(): void
    {
        $blob = $this->blob('blobgrh00001');
        $this->insertOrder('ordgrh000001');
        $this->insertGrant('blobgrh00001', 'ordgrh000001', 'grantgrh0001', remaining: 0);

        self::assertTrue(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::DELETE, null, false))
        );
    }

    public function testGrantFullyRefundedWithoutOverrideAllowsDelete(): void
    {
        $blob = $this->blob('blobgrf00001');
        $this->insertOrder('ordgrf000001', grandTotal: 1000, refundedTotal: 1000);
        $this->insertGrant('blobgrf00001', 'ordgrf000001', 'grantgrf0001', remaining: 5);

        self::assertTrue(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::DELETE, null, false))
        );
    }

    /**
     * ESCALATED bug (controller finding): `grantRetainsAccess()`'s full-refund
     * predicate was `refunded_total >= grand_total`, trivially true for a FREE
     * ($0 grand_total) order's grant (0 >= 0) -- so a live, valid grant on a
     * free digital-product order was wrongly treated as NOT retaining access,
     * which would have let `blocksDeletion()` allow deleting the still-in-use
     * blob out from under the customer. Requiring grand_total > 0 fixes this:
     * "nothing to refund" must not be confused with "fully refunded".
     */
    public function testGrantOnFreeOrderWithZeroGrandTotalRetainsAccessAndBlocksDelete(): void
    {
        $blob = $this->blob('blobfre00001');
        $this->insertOrder('ordfre000001', grandTotal: 0, refundedTotal: 0);
        $this->insertGrant('blobfre00001', 'ordfre000001', 'grantfre0001', remaining: 5);

        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::DELETE, null, false)),
            'a live grant on a free ($0 grand_total) order must retain access and block DELETE'
        );
    }

    public function testGrantFullyRefundedWithOverrideBlocksDelete(): void
    {
        $blob = $this->blob('blobgro00001');
        $this->insertOrder('ordgro000001', grandTotal: 1000, refundedTotal: 1000);
        $this->insertGrant(
            'blobgro00001',
            'ordgro000001',
            'grantgro0001',
            remaining: 5,
            overrideAt: gmdate('Y-m-d H:i:s')
        );

        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::DELETE, null, false))
        );
    }

    public function testGrantPartiallyRefundedStillRetainsAccessAndBlocksDelete(): void
    {
        $blob = $this->blob('blobgrp00001');
        $this->insertOrder('ordgrp000001', grandTotal: 1000, refundedTotal: 400);
        $this->insertGrant('blobgrp00001', 'ordgrp000001', 'grantgrp0001', remaining: 5);

        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::DELETE, null, false))
        );
    }

    // -----------------------------------------------------------------
    // Recently-minted signed URL: blocks DELETE independent of grant validity
    // -----------------------------------------------------------------

    public function testRecentlyMintedUrlBlocksDeleteEvenWhenGrantOtherwiseExpired(): void
    {
        $blob = $this->blob('blobgrm00001');
        $this->insertOrder('ordgrm000001');
        $this->insertGrant(
            'blobgrm00001',
            'ordgrm000001',
            'grantgrm0001',
            remaining: 5,
            expiresAt: '2000-01-01 00:00:00',
            lastMintedAt: $this->secondsAgo(60)
        );

        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::DELETE, null, false)),
            'a still-live signed URL must block deletion even though the grant itself is expired'
        );
    }

    public function testStaleMintedUrlPastTtlAllowsDeleteWhenGrantOtherwiseExpired(): void
    {
        $blob = $this->blob('blobgrs00001');
        $ttl = $this->ttl();
        $this->insertOrder('ordgrs000001');
        $this->insertGrant(
            'blobgrs00001',
            'ordgrs000001',
            'grantgrs0001',
            remaining: 5,
            expiresAt: '2000-01-01 00:00:00',
            lastMintedAt: $this->secondsAgo($ttl + 100)
        );

        self::assertTrue(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::DELETE, null, false))
        );
    }

    // -----------------------------------------------------------------
    // Combination + tenant-agnostic global read
    // -----------------------------------------------------------------

    public function testDefinitionAloneBlocksDeleteEvenWhenEveryGrantHasCleared(): void
    {
        $blob = $this->blob('blobcmb00001');
        $this->insertDefinition('blobcmb00001', 'defcmb000001');
        $this->insertOrder('ordcmb000001');
        $this->insertGrant(
            'blobcmb00001',
            'ordcmb000001',
            'grantcmb0001',
            remaining: 5,
            revokedAt: gmdate('Y-m-d H:i:s'),
            downloadUuid: 'defcmb000001'
        );

        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::DELETE, null, false)),
            'the definition row alone must keep blocking DELETE regardless of grant state'
        );
    }

    public function testPolicyReadsAreTenantAgnosticAcrossTenants(): void
    {
        // The commerce test harness's default context resolves to the empty
        // ('') sentinel tenant, but the definition below is stamped under a
        // DIFFERENT tenant entirely. The policy must still see it: it guards
        // the blob globally by design (spec §5), never scoping by tenant_uuid.
        $blob = $this->blob('blobten00001');
        $this->insertDefinition('blobten00001', 'deften000001', tenant: 'tenantB01234');

        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::VIEW, self::OTHER_USER, false)),
            'a definition under another tenant must still gate this blob globally'
        );
        self::assertFalse(
            $this->policy()->authorizeAccess($blob, $this->ctx(BlobAction::DELETE, null, false))
        );
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function policy(): CommerceDownloadBlobPolicy
    {
        return new CommerceDownloadBlobPolicy($this->context);
    }

    /** @return array<string,mixed> */
    private function blob(string $uuid, ?string $createdBy = self::CREATOR): array
    {
        return [
            'uuid' => $uuid,
            'created_by' => $createdBy,
            'visibility' => 'private',
            'status' => 'active',
        ];
    }

    private function ctx(BlobAction $action, ?string $userUuid, bool $signatureValid): BlobAccessContext
    {
        return new BlobAccessContext($action, $userUuid, $signatureValid);
    }

    private function ttl(): int
    {
        return (int) config($this->context, 'commerce.downloads.url_ttl', 300);
    }

    private function secondsAgo(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }

    private function insertDefinition(
        string $blobUuid,
        string $uuid = self::DEF_UUID,
        string $tenant = ''
    ): void {
        $this->connection->table('commerce_downloads')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'variant_uuid' => 'variant0001',
            'blob_uuid' => $blobUuid,
            'name' => 'File.pdf',
            'download_limit' => null,
            'expiry_days' => null,
            'position' => 0,
            'status' => 'active',
        ]);
    }

    private function insertOrder(
        string $uuid,
        int $grandTotal = 1000,
        int $refundedTotal = 0,
        string $tenant = ''
    ): void {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
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

    private function insertGrant(
        string $blobUuid,
        string $orderUuid,
        string $uuid,
        ?int $remaining = null,
        ?string $expiresAt = null,
        ?string $revokedAt = null,
        ?string $overrideAt = null,
        ?string $lastMintedAt = null,
        string $downloadUuid = self::DEF_UUID,
        string $tenant = ''
    ): string {
        $this->connection->table('commerce_download_grants')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'download_uuid' => $downloadUuid,
            'blob_uuid' => $blobUuid,
            'name' => 'File.pdf',
            'token_hash' => hash('sha256', $uuid),
            'remaining' => $remaining,
            'expires_at' => $expiresAt,
            'mint_count' => 0,
            'last_minted_at' => $lastMintedAt,
            'revoked_at' => $revokedAt,
            'refund_access_override_at' => $overrideAt,
        ]);

        return $uuid;
    }
}
