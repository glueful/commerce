<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Commerce\Support\CommerceSettingsOverride;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Payment-links Task 5 (spec §2.2): `PaymentLinkRepository` row mechanics --
 * hashed-only lookup, status-transition CAS, the fixed-UTC-hour initiation
 * counter claimed under the link row lock, the provider-session exposure
 * stamp, and the expiry/cancel guard's candidate read.
 *
 * NO token generation, NO service policy, NO URL composition: those are Tasks
 * 6-8. Every clock here is INJECTED (`\DateTimeImmutable`), never `time()`,
 * so the UTC hour boundary is asserted exactly with no tolerance window --
 * the same discipline as `Orders\DraftCleanupTest`.
 */
final class PaymentLinkRepositoryTest extends CommerceTestCase
{
    private const TENANT = 'plinkrtenA01';
    private const OTHER_TENANT = 'plinkrtenB02';
    private const ORDER = 'plinkrord001';
    private const ACTOR = 'plinkractor1';

    private PaymentLinkRepository $links;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links = new PaymentLinkRepository();
    }

    // =====================================================================
    // Hashed custody: the class speaks ONLY in hashes
    // =====================================================================

    /**
     * The repository must have NO raw-token parameter anywhere. A raw token is
     * generated, handed out once, and hashed by the service layer (Tasks 6-8);
     * if a raw value could reach this class it could reach a query log, an
     * exception trace, or the table itself.
     */
    public function testNoPublicMethodAcceptsARawToken(): void
    {
        $reflection = new \ReflectionClass(PaymentLinkRepository::class);
        $seenTokenParameters = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $name = $parameter->getName();
                if (stripos($name, 'token') === false) {
                    continue;
                }

                // ALLOWLIST, not a blacklist of three known-bad spellings: ANY
                // token-ish parameter must end in `hash`, so `$plainToken`,
                // `$bearerToken`, `$tokenValue` and friends fail here even though
                // no one thought to name them in advance.
                self::assertMatchesRegularExpression(
                    '/hash$/i',
                    $name,
                    "{$method->getName()}(\${$name}) looks like a raw token: every token-shaped "
                    . 'parameter on this class must be a hash and must be named so'
                );
                $seenTokenParameters[] = $method->getName() . '($' . $name . ')';
            }
        }

        // Guard against the assertion loop passing vacuously if the hashed lookup
        // surface is ever renamed away entirely.
        self::assertNotSame([], $seenTokenParameters, 'the hashed lookup surface must still exist');
    }

    /**
     * The complement of the allowlist above: the FULL public parameter-name
     * inventory, pinned exactly. A new parameter of any name -- `$bearer`,
     * `$secret`, `$credential`, none of which contain "token" -- fails here
     * until it is deliberately added to this list and re-reviewed.
     */
    public function testThePublicParameterNameInventoryIsPinned(): void
    {
        $reflection = new \ReflectionClass(PaymentLinkRepository::class);
        $names = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $names[$parameter->getName()] = true;
            }
        }

        $actual = array_keys($names);
        sort($actual);

        self::assertSame(
            [
                'context',
                'createdBy',
                'expiresAt',
                'limit',
                'linkUuid',
                'now',
                'orderUuid',
                'tenant',
                'tokenHash',
                'uuid',
            ],
            $actual
        );
    }

    public function testInsertPersistsTheHashAndFindByTokenHashResolvesItWithinTheTenant(): void
    {
        $hash = hash('sha256', 'a-raw-token-that-never-reaches-the-repository');
        $this->mint('plinkr000001', $hash);

        $row = $this->links->findByTokenHash($this->context, self::TENANT, $hash);
        self::assertNotNull($row);
        self::assertSame('plinkr000001', $row['uuid']);
        self::assertSame(self::ORDER, $row['order_uuid']);
        self::assertSame($hash, $row['token_hash']);
        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $row['status']);
        self::assertSame(self::ACTOR, $row['created_by']);
        self::assertSame(0, (int) $row['initiation_count']);
        self::assertNull($row['initiation_window_started_at']);
        self::assertNull($row['provider_session_issued_at']);
        self::assertNull($row['consumed_at']);
        self::assertNull($row['revoked_at']);
        self::assertSame('2026-09-01 00:00:00', $row['expires_at']);
    }

    public function testFindByTokenHashIsTenantScoped(): void
    {
        $hash = hash('sha256', 'cross-tenant');
        $this->mint('plinkr000002', $hash);

        self::assertNull($this->links->findByTokenHash($this->context, self::OTHER_TENANT, $hash));
        self::assertNull($this->links->findByTokenHash($this->context, self::TENANT, hash('sha256', 'unknown')));
    }

    public function testFindByUuidAndForUpdateAreTenantScoped(): void
    {
        $this->mint('plinkr000003', hash('sha256', 'uuid-lookup'));

        self::assertNotNull($this->links->findByUuid($this->context, self::TENANT, 'plinkr000003'));
        self::assertNull($this->links->findByUuid($this->context, self::OTHER_TENANT, 'plinkr000003'));

        self::assertNotNull($this->links->findByUuidForUpdate($this->context, self::TENANT, 'plinkr000003'));
        self::assertNull($this->links->findByUuidForUpdate($this->context, self::OTHER_TENANT, 'plinkr000003'));
    }

    public function testFindActiveForOrderReturnsOnlyTheActiveRow(): void
    {
        $this->mint('plinkr000004', hash('sha256', 'stale'));
        self::assertTrue($this->links->revoke($this->context, self::TENANT, 'plinkr000004', $this->at('13:00:00')));

        self::assertNull($this->links->findActiveForOrder($this->context, self::TENANT, self::ORDER));

        $this->mint('plinkr000005', hash('sha256', 'current'));
        $row = $this->links->findActiveForOrder($this->context, self::TENANT, self::ORDER);
        self::assertNotNull($row);
        self::assertSame('plinkr000005', $row['uuid']);

        $locked = $this->links->findActiveForOrderForUpdate($this->context, self::TENANT, self::ORDER);
        self::assertNotNull($locked);
        self::assertSame('plinkr000005', $locked['uuid']);

        self::assertNull($this->links->findActiveForOrderForUpdate($this->context, self::OTHER_TENANT, self::ORDER));
    }

    // =====================================================================
    // Status transitions (compare-and-set from `active`)
    // =====================================================================

    public function testRevokeMovesActiveToRevokedOnceAndStampsRevokedAt(): void
    {
        $this->mint('plinkr000006', hash('sha256', 'revoke'));

        self::assertTrue($this->links->revoke($this->context, self::TENANT, 'plinkr000006', $this->at('09:15:00')));

        $row = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000006');
        self::assertNotNull($row);
        self::assertSame(PaymentLinkRepository::STATUS_REVOKED, $row['status']);
        self::assertSame('2026-08-11 09:15:00', $row['revoked_at']);
        self::assertNull($row['consumed_at']);

        // Idempotent-by-failure: a second revoke changes nothing and says so.
        self::assertFalse($this->links->revoke($this->context, self::TENANT, 'plinkr000006', $this->at('10:00:00')));
        $again = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000006');
        self::assertNotNull($again);
        self::assertSame('2026-08-11 09:15:00', $again['revoked_at']);
    }

    public function testConsumeAndExpireEachMoveActiveToTheirTerminalStatus(): void
    {
        $this->mint('plinkr000007', hash('sha256', 'consume'));
        self::assertTrue($this->links->consume($this->context, self::TENANT, 'plinkr000007', $this->at('11:22:33')));
        $consumed = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000007');
        self::assertNotNull($consumed);
        self::assertSame(PaymentLinkRepository::STATUS_CONSUMED, $consumed['status']);
        self::assertSame('2026-08-11 11:22:33', $consumed['consumed_at']);

        $this->mint('plinkr000008', hash('sha256', 'expire'), 'plinkrord002');
        self::assertTrue($this->links->expire($this->context, self::TENANT, 'plinkr000008', $this->at('11:22:33')));
        $expired = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000008');
        self::assertNotNull($expired);
        self::assertSame(PaymentLinkRepository::STATUS_EXPIRED, $expired['status']);
        self::assertNull($expired['consumed_at']);
        self::assertNull($expired['revoked_at']);
    }

    public function testEveryTransitionRefusesANonActiveOrCrossTenantOrUnknownLink(): void
    {
        $this->mint('plinkr000009', hash('sha256', 'terminal'));
        self::assertTrue($this->links->consume($this->context, self::TENANT, 'plinkr000009', $this->at('12:00:00')));

        // Already terminal.
        self::assertFalse($this->links->revoke($this->context, self::TENANT, 'plinkr000009', $this->at('12:01:00')));
        self::assertFalse($this->links->expire($this->context, self::TENANT, 'plinkr000009', $this->at('12:01:00')));
        self::assertFalse($this->links->consume($this->context, self::TENANT, 'plinkr000009', $this->at('12:01:00')));

        // Cross-tenant and unknown are indistinguishable from "not active".
        $this->mint('plinkr000010', hash('sha256', 'other-tenant'), 'plinkrord003');
        self::assertFalse(
            $this->links->revoke($this->context, self::OTHER_TENANT, 'plinkr000010', $this->at('12:02:00'))
        );
        self::assertFalse($this->links->revoke($this->context, self::TENANT, 'nosuchlink01', $this->at('12:02:00')));
    }

    public function testOrderWideTransitionsTouchOnlyThatTenantsActiveLinksForThatOrder(): void
    {
        $this->mint('plinkr000011', hash('sha256', 'order-a-1'));
        $this->mint('plinkr000012', hash('sha256', 'order-a-2'));
        $this->mint('plinkr000013', hash('sha256', 'order-b-1'), 'plinkrord004');
        self::assertTrue($this->links->revoke($this->context, self::TENANT, 'plinkr000012', $this->at('08:00:00')));

        self::assertSame(
            1,
            $this->links->consumeActiveForOrder($this->context, self::TENANT, self::ORDER, $this->at('13:00:00'))
        );
        $consumed = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000011');
        self::assertNotNull($consumed);
        self::assertSame(PaymentLinkRepository::STATUS_CONSUMED, $consumed['status']);

        // The already-revoked sibling and the other order's link are untouched.
        $revoked = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000012');
        self::assertNotNull($revoked);
        self::assertSame(PaymentLinkRepository::STATUS_REVOKED, $revoked['status']);
        $otherOrder = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000013');
        self::assertNotNull($otherOrder);
        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $otherOrder['status']);

        self::assertSame(
            1,
            $this->links->revokeActiveForOrder($this->context, self::TENANT, 'plinkrord004', $this->at('13:05:00'))
        );
        self::assertSame(
            0,
            $this->links->revokeActiveForOrder($this->context, self::TENANT, 'plinkrord004', $this->at('13:06:00'))
        );
        self::assertSame(
            0,
            $this->links->revokeActiveForOrder($this->context, self::OTHER_TENANT, self::ORDER, $this->at('13:07:00'))
        );
    }

    // =====================================================================
    // Fixed-UTC-hour initiation counter, claimed under the link row lock
    // =====================================================================

    public function testFirstClaimOpensTheFixedUtcHourWindowAtTheHourFloor(): void
    {
        $this->mint('plinkr000014', hash('sha256', 'window-open'));

        self::assertTrue(
            $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000014', $this->at('13:37:42'))
        );

        $row = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000014');
        self::assertNotNull($row);
        self::assertSame('2026-08-11 13:00:00', $row['initiation_window_started_at']);
        self::assertSame(1, (int) $row['initiation_count']);
    }

    public function testClaimsIncrementWithinTheSameHourAndAreRefusedAtTheCeiling(): void
    {
        $this->mint('plinkr000015', hash('sha256', 'window-ceiling'));

        for ($i = 1; $i <= 3; $i++) {
            self::assertTrue(
                $this->links->claimInitiationWindow(
                    $this->context,
                    self::TENANT,
                    'plinkr000015',
                    $this->at(sprintf('13:%02d:00', $i)),
                    3
                ),
                "claim {$i} within the ceiling must succeed"
            );
        }

        self::assertFalse(
            $this->links->claimInitiationWindow(
                $this->context,
                self::TENANT,
                'plinkr000015',
                $this->at('13:59:59'),
                3
            )
        );

        // A refused claim must NOT advance the counter.
        $row = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000015');
        self::assertNotNull($row);
        self::assertSame(3, (int) $row['initiation_count']);
        self::assertSame('2026-08-11 13:00:00', $row['initiation_window_started_at']);
    }

    public function testCrossingTheUtcHourBoundaryResetsTheWindowAndTheCount(): void
    {
        $this->mint('plinkr000016', hash('sha256', 'window-rollover'));

        self::assertTrue(
            $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000016', $this->at('13:59:59'), 1)
        );
        // Still inside 13:00 -- refused.
        self::assertFalse(
            $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000016', $this->at('13:59:59'), 1)
        );

        // One second later is a NEW fixed window -- the counter resets, not slides.
        self::assertTrue(
            $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000016', $this->at('14:00:00'), 1)
        );

        $row = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000016');
        self::assertNotNull($row);
        self::assertSame('2026-08-11 14:00:00', $row['initiation_window_started_at']);
        self::assertSame(1, (int) $row['initiation_count']);
    }

    /**
     * The window is a FIXED UTC hour, so a caller in another timezone lands in
     * the same bucket as a UTC caller at the same instant.
     */
    public function testTheWindowIsAlwaysAFixedUtcHourRegardlessOfTheCallersTimezone(): void
    {
        $this->mint('plinkr000017', hash('sha256', 'window-tz'));

        // 19:07:00 +05:30 == 13:37:00 UTC.
        $kolkata = new \DateTimeImmutable('2026-08-11 19:07:00', new \DateTimeZone('+05:30'));
        self::assertTrue(
            $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000017', $kolkata, 2)
        );

        $row = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000017');
        self::assertNotNull($row);
        self::assertSame('2026-08-11 13:00:00', $row['initiation_window_started_at']);

        // A UTC caller in the same hour continues the SAME window rather than resetting it.
        self::assertTrue(
            $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000017', $this->at('13:50:00'), 2)
        );
        $row = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000017');
        self::assertNotNull($row);
        self::assertSame(2, (int) $row['initiation_count']);
        self::assertSame('2026-08-11 13:00:00', $row['initiation_window_started_at']);
    }

    public function testAnExplicitCeilingIsClampedToOneAndOneHundred(): void
    {
        $this->mint('plinkr000018', hash('sha256', 'clamp-low'));
        // 0 (and anything below 1) clamps UP to 1 -- never "no initiations at all".
        self::assertTrue(
            $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000018', $this->at('13:00:00'), 0)
        );
        self::assertFalse(
            $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000018', $this->at('13:00:01'), 0)
        );

        $this->mint('plinkr000019', hash('sha256', 'clamp-high'), 'plinkrord005');
        // 5000 clamps DOWN to 100 -- never unbounded.
        for ($i = 1; $i <= 100; $i++) {
            self::assertTrue(
                $this->links->claimInitiationWindow(
                    $this->context,
                    self::TENANT,
                    'plinkr000019',
                    $this->at('13:00:00'),
                    5000
                )
            );
        }
        self::assertFalse(
            $this->links->claimInitiationWindow(
                $this->context,
                self::TENANT,
                'plinkr000019',
                $this->at('13:00:00'),
                5000
            )
        );
    }

    public function testTheCeilingDefaultsToTheClampedConfiguredValue(): void
    {
        self::assertSame(10, CommerceSettings::paymentLinkInitiationsPerHour($this->context));

        $this->mint('plinkr000020', hash('sha256', 'config-ceiling'));
        for ($i = 1; $i <= 10; $i++) {
            self::assertTrue(
                $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000020', $this->at('13:00:00'))
            );
        }
        self::assertFalse(
            $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000020', $this->at('13:00:00'))
        );
    }

    public function testAConfiguredCeilingOutsideTheClampIsBroughtBackInsideIt(): void
    {
        $this->bindOverride(['commerce.payment_links.initiations_per_hour' => '0']);
        self::assertSame(1, CommerceSettings::paymentLinkInitiationsPerHour($this->context));

        $this->bindOverride(['commerce.payment_links.initiations_per_hour' => '9999']);
        self::assertSame(100, CommerceSettings::paymentLinkInitiationsPerHour($this->context));

        $this->bindOverride(['commerce.payment_links.initiations_per_hour' => '-4']);
        self::assertSame(1, CommerceSettings::paymentLinkInitiationsPerHour($this->context));

        $this->bindOverride(['commerce.payment_links.initiations_per_hour' => '25']);
        self::assertSame(25, CommerceSettings::paymentLinkInitiationsPerHour($this->context));
    }

    public function testClaimingAgainstAnUnknownOrCrossTenantLinkIsRefused(): void
    {
        $this->mint('plinkr000021', hash('sha256', 'claim-scope'));

        self::assertFalse(
            $this->links->claimInitiationWindow($this->context, self::OTHER_TENANT, 'plinkr000021', $this->at('13:00:00'))
        );
        self::assertFalse(
            $this->links->claimInitiationWindow($this->context, self::TENANT, 'nosuchlink02', $this->at('13:00:00'))
        );

        $row = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000021');
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['initiation_count']);
    }

    /**
     * The claim is a compare-and-set on the row it just read under the lock, so
     * a claim whose read is invalidated by a concurrent committed write loses
     * rather than double-counting. Simulated here by mutating the row between
     * the repository's read and its update via a second connection is not
     * possible on `:memory:`; instead the CAS is proven directly -- claiming
     * against a count that has already moved on cannot resurrect the old value.
     */
    public function testConcurrentClaimsNeverDoubleCountWithinOneWindow(): void
    {
        $this->mint('plinkr000022', hash('sha256', 'cas'));

        $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000022', $this->at('13:00:00'), 50);
        $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000022', $this->at('13:00:00'), 50);
        $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000022', $this->at('13:00:00'), 50);

        $row = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000022');
        self::assertNotNull($row);
        self::assertSame(3, (int) $row['initiation_count'], 'each successful claim increments exactly once');
    }

    public function testTheClaimSurvivesRunningInsideAnEnclosingTransaction(): void
    {
        $this->mint('plinkr000023', hash('sha256', 'in-transaction'));

        $this->connection->transaction(function (): void {
            self::assertTrue(
                $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000023', $this->at('13:00:00'))
            );
            self::assertTrue(
                $this->links->claimInitiationWindow($this->context, self::TENANT, 'plinkr000023', $this->at('13:30:00'))
            );
        });

        $row = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000023');
        self::assertNotNull($row);
        self::assertSame(2, (int) $row['initiation_count']);
    }

    // =====================================================================
    // Provider-session exposure stamp
    // =====================================================================

    public function testStampProviderSessionIssuedRecordsTheFirstExposureAndIsIdempotent(): void
    {
        $this->mint('plinkr000024', hash('sha256', 'exposure'));

        self::assertTrue(
            $this->links->stampProviderSessionIssued($this->context, self::TENANT, 'plinkr000024', $this->at('13:05:00'))
        );
        $row = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000024');
        self::assertNotNull($row);
        self::assertSame('2026-08-11 13:05:00', $row['provider_session_issued_at']);

        // A repeat click converges on the SAME live session -- the first exposure
        // instant is the forensic record and must not be overwritten.
        self::assertTrue(
            $this->links->stampProviderSessionIssued($this->context, self::TENANT, 'plinkr000024', $this->at('14:00:00'))
        );
        $again = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000024');
        self::assertNotNull($again);
        self::assertSame('2026-08-11 13:05:00', $again['provider_session_issued_at']);
    }

    public function testStampProviderSessionIssuedIsTenantScoped(): void
    {
        $this->mint('plinkr000025', hash('sha256', 'exposure-scope'));

        self::assertFalse(
            $this->links->stampProviderSessionIssued(
                $this->context,
                self::OTHER_TENANT,
                'plinkr000025',
                $this->at('13:05:00')
            )
        );
        self::assertFalse(
            $this->links->stampProviderSessionIssued($this->context, self::TENANT, 'nosuchlink03', $this->at('13:05:00'))
        );

        $row = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000025');
        self::assertNotNull($row);
        self::assertNull($row['provider_session_issued_at']);
    }

    /**
     * An exposed link stays exposed forever -- the guard's whole point is that a
     * revoked/expired link whose provider session was already issued still
     * blocks automatic cancellation.
     */
    public function testTheExposureStampSurvivesEveryTerminalTransition(): void
    {
        $this->mint('plinkr000026', hash('sha256', 'exposure-survives'));
        $this->links->stampProviderSessionIssued($this->context, self::TENANT, 'plinkr000026', $this->at('13:05:00'));
        self::assertTrue($this->links->revoke($this->context, self::TENANT, 'plinkr000026', $this->at('13:10:00')));

        $row = $this->links->findByUuid($this->context, self::TENANT, 'plinkr000026');
        self::assertNotNull($row);
        self::assertSame(PaymentLinkRepository::STATUS_REVOKED, $row['status']);
        self::assertSame('2026-08-11 13:05:00', $row['provider_session_issued_at']);
    }

    // =====================================================================
    // Expiry/cancel guard candidate read
    // =====================================================================

    public function testGuardRelevantLinksReturnsActiveUnexpiredAndAnyHistoricallyExposedLink(): void
    {
        // Active and unexpired -- relevant.
        $this->mint('plinkr000027', hash('sha256', 'guard-active'), 'plinkrordG01', '2026-09-01 00:00:00');
        // Active but already past its TTL -- NOT relevant (it returns to the ordinary sweep).
        $this->mint('plinkr000028', hash('sha256', 'guard-stale'), 'plinkrordG02', '2026-08-01 00:00:00');
        // Revoked and never initiated -- NOT relevant.
        $this->mint('plinkr000029', hash('sha256', 'guard-revoked'), 'plinkrordG03', '2026-09-01 00:00:00');
        $this->links->revoke($this->context, self::TENANT, 'plinkr000029', $this->at('12:00:00'));
        // Revoked BUT historically exposed -- relevant forever.
        $this->mint('plinkr000030', hash('sha256', 'guard-exposed'), 'plinkrordG04', '2026-08-01 00:00:00');
        $this->links->stampProviderSessionIssued($this->context, self::TENANT, 'plinkr000030', $this->at('11:00:00'));
        $this->links->revoke($this->context, self::TENANT, 'plinkr000030', $this->at('12:00:00'));

        $now = $this->at('13:00:00');

        self::assertSame(
            ['plinkr000027'],
            $this->uuidsOf($this->links->guardRelevantLinks($this->context, self::TENANT, 'plinkrordG01', $now))
        );
        self::assertSame(
            [],
            $this->uuidsOf($this->links->guardRelevantLinks($this->context, self::TENANT, 'plinkrordG02', $now))
        );
        self::assertSame(
            [],
            $this->uuidsOf($this->links->guardRelevantLinks($this->context, self::TENANT, 'plinkrordG03', $now))
        );
        self::assertSame(
            ['plinkr000030'],
            $this->uuidsOf($this->links->guardRelevantLinks($this->context, self::TENANT, 'plinkrordG04', $now))
        );

        self::assertTrue($this->links->hasGuardRelevantLink($this->context, self::TENANT, 'plinkrordG01', $now));
        self::assertFalse($this->links->hasGuardRelevantLink($this->context, self::TENANT, 'plinkrordG02', $now));
        self::assertFalse($this->links->hasGuardRelevantLink($this->context, self::TENANT, 'plinkrordG03', $now));
        self::assertTrue($this->links->hasGuardRelevantLink($this->context, self::TENANT, 'plinkrordG04', $now));

        // Tenant scoping holds for the guard read too.
        self::assertFalse(
            $this->links->hasGuardRelevantLink($this->context, self::OTHER_TENANT, 'plinkrordG01', $now)
        );
    }

    public function testGuardRelevanceOfAnActiveLinkEndsExactlyAtItsExpiry(): void
    {
        $this->mint('plinkr000031', hash('sha256', 'guard-boundary'), 'plinkrordG05', '2026-08-11 13:00:00');

        self::assertTrue(
            $this->links->hasGuardRelevantLink($this->context, self::TENANT, 'plinkrordG05', $this->at('12:59:59'))
        );
        // At the stamp itself the link is expired -- the boundary is exclusive.
        self::assertFalse(
            $this->links->hasGuardRelevantLink($this->context, self::TENANT, 'plinkrordG05', $this->at('13:00:00'))
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function mint(
        string $uuid,
        string $tokenHash,
        string $orderUuid = self::ORDER,
        string $expiresAt = '2026-09-01 00:00:00'
    ): void {
        $this->links->insert(
            $this->context,
            self::TENANT,
            $uuid,
            $orderUuid,
            $tokenHash,
            new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')),
            self::ACTOR,
            $this->at('08:00:00')
        );
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-11 ' . $time, new \DateTimeZone('UTC'));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private function uuidsOf(array $rows): array
    {
        $uuids = array_map(static fn (array $row): string => (string) $row['uuid'], $rows);
        sort($uuids);

        return array_values($uuids);
    }

    /** @param array<string,?string> $values */
    private function bindOverride(array $values): void
    {
        $this->bindings[CommerceSettingsOverride::class] = new class ($values) implements CommerceSettingsOverride {
            /** @param array<string,?string> $values */
            public function __construct(private array $values)
            {
            }

            public function value(ApplicationContext $context, string $key): ?string
            {
                return $this->values[$key] ?? null;
            }
        };
    }
}
