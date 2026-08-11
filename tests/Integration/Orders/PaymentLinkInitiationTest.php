<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkReturnUrlProvider;
use Glueful\Extensions\Commerce\Orders\Events\PaymentLinkEvents;
use Glueful\Extensions\Commerce\Orders\NestedInitiationTransactionException;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkException;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkService;
use Glueful\Extensions\Commerce\Orders\UnavailablePaymentLinkReturnUrlProvider;
use Glueful\Extensions\Commerce\Payments\OrderPayable;
use Glueful\Extensions\Commerce\Support\HttpsUrl;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * Payment-links Task 7 (design spec §2.2): `PaymentLinkService::initiateByToken()`
 * -- the money path, where a payment-link click becomes a provider checkout
 * session.
 *
 * The three properties every assertion here serves:
 *
 *  1. TWO PHASES, NO PROVIDER I/O UNDER A LOCK. Phase A claims the rate window
 *     and commits; the provider call runs with NO transaction open; Phase B
 *     relocks and rechecks EVERY predicate before a URL is exposed. The
 *     blocking-collector tests prove all three by doing real, committing work
 *     (revoke / cancel / lazy expiry) from INSIDE the provider call.
 *  2. NEVER AN EMPTY OR OPEN REDIRECT. Every way the provider leg can fail --
 *     manual, no URL, an untrusted URL, a throw, no return route -- is a TYPED
 *     refusal, never a URL and never a leaked exception.
 *  3. THE RAW TOKEN GOES NOWHERE. Not into the `PayableReference`, not into its
 *     metadata, not into a stack frame on any throw path.
 */
final class PaymentLinkInitiationTest extends CommerceTestCase
{
    private const TENANT = 'plinittenA01';
    private const OTHER_TENANT = 'plinittenB02';
    private const ORDER = 'plinitorder1';
    private const ACTOR = 'plinitactor1';

    private const CHECKOUT_URL = 'https://psp.example.com/session/abc123';
    private const RETURN_URL = 'https://shop.example.com/pay/return?sig=deadbeef';
    private const CANCEL_URL = 'https://shop.example.com/pay/cancel?sig=deadbeef';

    private PaymentLinkRepository $links;
    private OrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links = new PaymentLinkRepository();
        $this->orders = new OrderRepository();
    }

    // =====================================================================
    // Happy path
    // =====================================================================

    public function testInitiationReturnsTheCheckoutUrlAndStampsTheProviderSession(): void
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);

        $result = $service->initiateByToken($this->context, $token, $this->at('09:00:00'));

        self::assertSame(['checkoutUrl' => self::CHECKOUT_URL], $result);
        self::assertSame(1, $collector->calls);

        $row = $this->currentLinkRow();
        self::assertNotNull($row['provider_session_issued_at'], 'Phase B must stamp the exposure');
        self::assertSame(1, (int) $row['initiation_count']);
        self::assertSame('2026-08-11 09:00:00', $this->normalize((string) $row['initiation_window_started_at']));
        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, (string) $row['status']);
    }

    public function testInitiationAuditsTheAttemptWithTheLinkUuidOnly(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service($this->collector($this->ok(self::CHECKOUT_URL)), $this->returnUrls());
        $token = $this->mintToken($service);
        $linkUuid = (string) $this->currentLinkRow()['uuid'];

        $service->initiateByToken($this->context, $token, $this->at('09:00:00'));

        $initiated = array_values(array_filter(
            $this->orders->eventsForOrder($this->context, self::TENANT, self::ORDER),
            static fn (array $event): bool => $event['type'] === PaymentLinkEvents::INITIATED
        ));

        self::assertCount(1, $initiated);
        self::assertSame($linkUuid, $initiated[0]['payload']['link_uuid']);
        self::assertSame('internal', $initiated[0]['visibility']);
        self::assertStringNotContainsString($token, json_encode($initiated, JSON_THROW_ON_ERROR));
    }

    public function testRepeatedClicksEachProduceALiveSessionUntilTheCeiling(): void
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);

        $first = $service->initiateByToken($this->context, $token, $this->at('09:00:00'));
        $second = $service->initiateByToken($this->context, $token, $this->at('09:01:00'));

        self::assertSame($first, $second, 'idempotency is the provider\'s; the engine simply asks again');
        self::assertSame(2, $collector->calls);
        self::assertSame(2, (int) $this->currentLinkRow()['initiation_count']);
        // COALESCE keeps the FIRST exposure instant.
        self::assertSame(
            '2026-08-11 09:00:00',
            $this->normalize((string) $this->currentLinkRow()['provider_session_issued_at'])
        );
    }

    // =====================================================================
    // The rate window is claimed BEFORE provider I/O
    // =====================================================================

    public function testTheWindowCeilingRefusesWithItsOwnCodeAndNeverReachesTheProvider(): void
    {
        $this->seedOrder(self::ORDER);
        $this->context->overrideConfig('commerce.payment_links.initiations_per_hour', 1);
        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);

        $service->initiateByToken($this->context, $token, $this->at('09:00:00'));

        $this->assertRefuses(
            PaymentLinkException::INITIATION_RATE_LIMITED,
            fn () => $service->initiateByToken($this->context, $token, $this->at('09:30:00'))
        );

        self::assertSame(1, $collector->calls, 'the ceiling is enforced BEFORE the provider is called');
        self::assertSame(1, (int) $this->currentLinkRow()['initiation_count'], 'a refused claim never advances');
    }

    public function testTheFixedHourWindowResetsAtTheBoundary(): void
    {
        $this->seedOrder(self::ORDER);
        $this->context->overrideConfig('commerce.payment_links.initiations_per_hour', 1);
        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);

        $service->initiateByToken($this->context, $token, $this->at('09:59:59'));
        $service->initiateByToken($this->context, $token, $this->at('10:00:00'));

        self::assertSame(2, $collector->calls);
        self::assertSame(1, (int) $this->currentLinkRow()['initiation_count'], 'the new window starts at one');
    }

    /**
     * The claim is not merely "before the provider call" in source order -- it is
     * COMMITTED before it. A collector that re-enters `initiateByToken()` from
     * inside its own `initiate()` sees the outer claim ALREADY PERSISTED and is
     * refused by the ceiling. That can only be true if Phase A committed before
     * the provider leg began.
     */
    public function testTheClaimIsCommittedBeforeTheProviderCallBegins(): void
    {
        $this->seedOrder(self::ORDER);
        $this->context->overrideConfig('commerce.payment_links.initiations_per_hour', 1);

        $service = null;
        $token = null;
        $nested = null;

        $collector = $this->collector(function () use (&$service, &$token, &$nested): PaymentInitiation {
            try {
                $service->initiateByToken($this->context, $token, $this->at('09:00:01'));
                $nested = 'succeeded';
            } catch (PaymentLinkException $e) {
                $nested = $e->errorCode;
            }

            return new PaymentInitiation('fakepsp', 'ok', ['checkout_url' => self::CHECKOUT_URL]);
        });

        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);

        $result = $service->initiateByToken($this->context, $token, $this->at('09:00:00'));

        self::assertSame(self::CHECKOUT_URL, $result['checkoutUrl']);
        self::assertSame(
            PaymentLinkException::INITIATION_RATE_LIMITED,
            $nested,
            'the re-entrant attempt must see the outer claim already committed'
        );
        self::assertSame(1, $collector->calls, 'the nested attempt never reached the provider');
    }

    // =====================================================================
    // Provider I/O runs with NO transaction and NO row lock held
    // =====================================================================

    /**
     * The BLOCKING-collector proof. While the provider call is "in flight" the
     * collector does real, committing database work on the very rows the
     * initiation touches. If Phase A were still open -- or held the order/link
     * locks -- this could not commit at all.
     *
     * Then Phase B rechecks every predicate, sees the revocation, and REFUSES:
     * the provider attempt stays server-side and no URL is ever exposed.
     */
    public function testARevokeCommittedDuringProviderIoMakesPhaseBRefuseTheRedirect(): void
    {
        $this->seedOrder(self::ORDER);

        $service = null;
        $observedLevel = null;

        $collector = $this->collector(function () use (&$service, &$observedLevel): PaymentInitiation {
            $observedLevel = db($this->context)->transactionLevel();
            $service->revoke($this->context, self::TENANT, self::ORDER, self::ACTOR, $this->at('09:00:01'));

            return new PaymentInitiation('fakepsp', 'ok', ['checkout_url' => self::CHECKOUT_URL]);
        });

        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);

        $refusal = $this->assertRefuses(
            PaymentLinkException::PAYMENT_LINK_NOT_PAYABLE,
            fn () => $service->initiateByToken($this->context, $token, $this->at('09:00:00'))
        );

        self::assertSame(0, $observedLevel, 'provider I/O must run with NO transaction open');
        self::assertSame(1, $collector->calls, 'the attempt genuinely happened');
        self::assertStringNotContainsString(self::CHECKOUT_URL, $refusal->getMessage(), 'no URL may be exposed');
        self::assertStringNotContainsString('psp.example.com', $refusal->getMessage());

        $row = $this->currentLinkRowByStatus(PaymentLinkRepository::STATUS_REVOKED);
        self::assertNull($row['provider_session_issued_at'], 'a refused Phase B stamps nothing');
    }

    public function testAnOrderCanceledDuringProviderIoMakesPhaseBRefuseTheRedirect(): void
    {
        $this->seedOrder(self::ORDER);

        $collector = $this->collector(function (): PaymentInitiation {
            $this->setOrderStatus(self::ORDER, 'canceled');

            return new PaymentInitiation('fakepsp', 'ok', ['checkout_url' => self::CHECKOUT_URL]);
        });

        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);

        $refusal = $this->assertRefuses(
            PaymentLinkException::PAYMENT_LINK_NOT_PAYABLE,
            fn () => $service->initiateByToken($this->context, $token, $this->at('09:00:00'))
        );

        self::assertStringNotContainsString(self::CHECKOUT_URL, $refusal->getMessage());
        self::assertNull($this->currentLinkRow()['provider_session_issued_at']);
    }

    /**
     * `resolveByToken()` is deliberately UNLOCKED and applies lazy terminal
     * transitions, so an anonymous read can expire a link between the two
     * phases. Phase B's full predicate recheck must treat that as a refusal --
     * this is the race the Task 6 docblock explicitly hands to Task 7.
     */
    public function testAnUnlockedResolveExpiringTheLinkBetweenPhasesMakesPhaseBRefuse(): void
    {
        $this->seedOrder(self::ORDER);

        $service = null;
        $token = null;

        $collector = $this->collector(function () use (&$service, &$token): PaymentInitiation {
            // A concurrent anonymous page load, at a clock past the TTL.
            $service->resolveByToken($this->context, $token, $this->at2('2026-08-13 09:00:00'));

            return new PaymentInitiation('fakepsp', 'ok', ['checkout_url' => self::CHECKOUT_URL]);
        });

        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service, ttlDays: 1);

        $refusal = $this->assertRefuses(
            PaymentLinkException::PAYMENT_LINK_NOT_PAYABLE,
            fn () => $service->initiateByToken($this->context, $token, $this->at('09:00:00'))
        );

        self::assertStringNotContainsString(self::CHECKOUT_URL, $refusal->getMessage());
        self::assertSame(PaymentLinkRepository::STATUS_EXPIRED, (string) $this->currentLinkRow()['status']);
        self::assertNull($this->currentLinkRow()['provider_session_issued_at']);
    }

    /**
     * Review round 1, Important 1. `Connection::transaction()` DELEGATES to an
     * already-active manager, so a nested Phase A would release a savepoint
     * instead of committing, and the caller's transaction would keep the order
     * and link locks for the whole provider call -- silently, with every other
     * assertion in this file still passing. Initiation therefore refuses to run
     * inside a caller's transaction at all.
     */
    public function testInitiationRefusesToRunInsideACallerOwnedTransaction(): void
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);

        $manager = $this->connection->getTransactionManager();
        $manager->begin();

        try {
            $service->initiateByToken($this->context, $token, $this->at('09:00:00'));
            self::fail('an ambient transaction must be refused');
        } catch (NestedInitiationTransactionException $e) {
            self::assertStringNotContainsString($token, $e->getMessage());
            self::assertStringNotContainsString($token, $e->getTraceAsString());
        } finally {
            $manager->rollback();
        }

        self::assertSame(0, $collector->calls, 'nothing may reach the provider');
        $row = $this->currentLinkRow();
        self::assertSame(0, (int) $row['initiation_count'], 'no budget may be consumed');
        self::assertNull($row['provider_session_issued_at']);
    }

    public function testTheTransactionGuardIsNotAPayerFacingState(): void
    {
        self::assertTrue(
            is_subclass_of(NestedInitiationTransactionException::class, \LogicException::class),
            'a caller bug is a LogicException, not a payer-facing domain state'
        );
        self::assertFalse(
            is_subclass_of(NestedInitiationTransactionException::class, PaymentLinkException::class)
        );
    }

    // =====================================================================
    // Phase B rechecks expiry against a FRESH clock
    // =====================================================================

    /**
     * Review round 1, Important 2. Every other predicate is genuinely re-read
     * under lock in Phase B; expiry is different, because its truth lives in the
     * CLOCK rather than in the row. A link whose TTL lapses DURING the provider
     * round trip must not be handed a URL, and its exposure stamp must not be
     * back-dated to before the call.
     *
     * This is the one test here that must use the REAL clock -- an injected
     * `$now` deliberately governs both phases for determinism -- so the link is
     * given a one-second TTL and the collector burns more than that. Note the
     * collector does NOT touch the link row: its status is still `active` at the
     * end, so nothing but the clock can make this refusal happen.
     */
    public function testALinkWhoseTtlLapsesDuringTheProviderCallIsRefusedByPhaseB(): void
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector(static function (): PaymentInitiation {
            usleep(1_500_000);

            return new PaymentInitiation('fakepsp', 'ok', ['checkout_url' => self::CHECKOUT_URL]);
        });
        $service = $this->service($collector, $this->returnUrls());

        $token = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR)['rawToken'];
        // One second of life, measured against the real clock the service reads.
        $this->setLinkExpiry((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 second'));

        $refusal = $this->assertRefuses(
            PaymentLinkException::PAYMENT_LINK_NOT_PAYABLE,
            fn () => $service->initiateByToken($this->context, $token)
        );

        self::assertSame(1, $collector->calls, 'the provider WAS called -- Phase A saw a live link');
        self::assertStringNotContainsString(self::CHECKOUT_URL, $refusal->getMessage());

        $row = $this->currentLinkRow();
        self::assertSame(
            PaymentLinkRepository::STATUS_ACTIVE,
            (string) $row['status'],
            'only the clock moved: nothing transitioned the row, so this cannot pass for another reason'
        );
        self::assertNull($row['provider_session_issued_at'], 'no exposure, and no back-dated stamp');
    }

    public function testAnInjectedClockGovernsBothPhasesSoTestsStayDeterministic(): void
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector(static function (): PaymentInitiation {
            usleep(1_100_000);

            return new PaymentInitiation('fakepsp', 'ok', ['checkout_url' => self::CHECKOUT_URL]);
        });
        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);

        $result = $service->initiateByToken($this->context, $token, $this->at('09:00:00'));

        self::assertSame(self::CHECKOUT_URL, $result['checkoutUrl']);
        // The stamp is the INJECTED instant, not a wall-clock reading taken
        // after the deliberately slow provider call.
        self::assertSame(
            '2026-08-11 09:00:00',
            $this->normalize((string) $this->currentLinkRow()['provider_session_issued_at'])
        );
    }

    // =====================================================================
    // The return-URL seam: resolved and validated BEFORE provider I/O
    // =====================================================================

    public function testTheReturnProviderReceivesTheLinkUuidAndNeverTheToken(): void
    {
        $this->seedOrder(self::ORDER);
        $provider = $this->returnUrls();
        $service = $this->service($this->collector($this->ok(self::CHECKOUT_URL)), $provider);
        $token = $this->mintToken($service);
        $linkUuid = (string) $this->currentLinkRow()['uuid'];

        $service->initiateByToken($this->context, $token, $this->at('09:00:00'));

        self::assertSame([$linkUuid], $provider->seen);
        self::assertStringNotContainsString($token, json_encode($provider->seen, JSON_THROW_ON_ERROR));
    }

    public function testAnUnboundReturnProviderIsTypedUnavailableBeforeAnyProviderCall(): void
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $service = $this->service($collector, null);
        $token = $this->mintToken($service);

        $this->assertRefuses(
            PaymentLinkException::RETURN_URL_UNAVAILABLE,
            fn () => $service->initiateByToken($this->context, $token, $this->at('09:00:00'))
        );

        self::assertSame(0, $collector->calls, 'the provider must not be called without a return route');
        self::assertNull($this->currentLinkRow()['provider_session_issued_at']);
    }

    public function testTheEngineDefaultReturnProviderIsTypedUnavailable(): void
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $service = $this->service($collector, new UnavailablePaymentLinkReturnUrlProvider());
        $token = $this->mintToken($service);

        $this->assertRefuses(
            PaymentLinkException::RETURN_URL_UNAVAILABLE,
            fn () => $service->initiateByToken($this->context, $token, $this->at('09:00:00'))
        );

        self::assertSame(0, $collector->calls);
    }

    /**
     * The strictness is {@see \Glueful\Extensions\Commerce\Support\HttpsUrl}'s
     * and nothing else -- the SAME definition `CheckoutService` uses, so a
     * signed return route with a query string stays legal while anything that
     * is not absolute HTTPS is refused.
     *
     * @dataProvider unusableReturnUrls
     * @param array<string,mixed>|null $urls
     */
    public function testEveryUnusableReturnUrlShapeRefusesBeforeProviderIo(?array $urls): void
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $service = $this->service($collector, $this->returnUrls($urls));
        $token = $this->mintToken($service);

        $this->assertRefuses(
            PaymentLinkException::RETURN_URL_UNAVAILABLE,
            fn () => $service->initiateByToken($this->context, $token, $this->at('09:00:00'))
        );

        self::assertSame(0, $collector->calls, 'validation must precede the provider call');
        self::assertNull($this->currentLinkRow()['provider_session_issued_at']);
    }

    /** @return array<string, array{array<string,mixed>|null}> */
    public static function unusableReturnUrls(): array
    {
        return [
            'null from the provider' => [null],
            'plain http return' => [['return' => 'http://shop.example.com/r', 'cancel' => self::CANCEL_URL]],
            'plain http cancel' => [['return' => self::RETURN_URL, 'cancel' => 'http://shop.example.com/c']],
            'relative return' => [['return' => '/pay/return', 'cancel' => self::CANCEL_URL]],
            'scheme-relative cancel' => [['return' => self::RETURN_URL, 'cancel' => '//shop.example.com/c']],
            'javascript scheme' => [['return' => 'javascript:alert(1)', 'cancel' => self::CANCEL_URL]],
            'empty return' => [['return' => '', 'cancel' => self::CANCEL_URL]],
            'empty cancel' => [['return' => self::RETURN_URL, 'cancel' => '']],
            'missing cancel key' => [['return' => self::RETURN_URL]],
            'missing return key' => [['cancel' => self::CANCEL_URL]],
            'non-string return' => [['return' => 42, 'cancel' => self::CANCEL_URL]],
            'no host' => [['return' => 'https:///nowhere', 'cancel' => self::CANCEL_URL]],
        ];
    }

    public function testAThrowingReturnProviderIsTypedUnavailableAndNeverLeaks(): void
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $provider = new class implements PaymentLinkReturnUrlProvider {
            public function urlsFor(ApplicationContext $context, string $linkUuid): ?array
            {
                throw new \RuntimeException("host blew up composing {$linkUuid}");
            }
        };
        $service = $this->service($collector, $provider);
        $token = $this->mintToken($service);

        $refusal = $this->assertRefuses(
            PaymentLinkException::RETURN_URL_UNAVAILABLE,
            fn () => $service->initiateByToken($this->context, $token, $this->at('09:00:00'))
        );

        self::assertSame(0, $collector->calls);
        self::assertStringNotContainsString('host blew up', $refusal->getMessage());
    }

    // =====================================================================
    // Every typed unavailable outcome of the provider leg
    // =====================================================================

    public function testAManualCollectorOutcomeIsTypedUnavailable(): void
    {
        $this->assertProviderOutcomeRefuses(
            PaymentLinkException::CHECKOUT_MANUAL,
            static fn (): PaymentInitiation => new PaymentInitiation(
                'manual',
                'manual',
                ['instructions' => 'Pay at the counter.']
            )
        );
    }

    /**
     * @dataProvider missingCheckoutUrlPayloads
     * @param array<string,mixed> $payload
     */
    public function testAMissingCheckoutUrlIsTypedUnavailable(array $payload): void
    {
        $this->assertProviderOutcomeRefuses(
            PaymentLinkException::CHECKOUT_URL_MISSING,
            static fn (): PaymentInitiation => new PaymentInitiation('fakepsp', 'ok', $payload)
        );
    }

    /** @return array<string, array{array<string,mixed>}> */
    public static function missingCheckoutUrlPayloads(): array
    {
        return [
            'empty payload' => [[]],
            'empty string url' => [['checkout_url' => '']],
            'null url' => [['checkout_url' => null]],
            'non-string url' => [['checkout_url' => ['https://psp.example.com/x']]],
            'reference only' => [['reference' => 'PSP-REF-1', 'gateway' => 'fakepsp']],
        ];
    }

    /** @dataProvider untrustedCheckoutUrls */
    public function testAnUntrustedCheckoutUrlIsTypedUnavailable(string $url): void
    {
        $this->assertProviderOutcomeRefuses(
            PaymentLinkException::CHECKOUT_URL_UNTRUSTED,
            static fn (): PaymentInitiation => new PaymentInitiation('fakepsp', 'ok', ['checkout_url' => $url])
        );
    }

    /** @return array<string, array{string}> */
    public static function untrustedCheckoutUrls(): array
    {
        return [
            'plain http' => ['http://psp.example.com/session/abc'],
            'scheme relative' => ['//evil.example.com/session/abc'],
            'relative path' => ['/session/abc'],
            'javascript' => ['javascript:alert(document.cookie)'],
            'data uri' => ['data:text/html;base64,PHNjcmlwdD4='],
            'no host' => ['https:///session/abc'],
            'not a url' => ['definitely not a url'],
            // Review round 1, minor 3: absolute HTTPS to a parser, the PSP's
            // domain to a human, and `evil.example.com` to a browser. The one
            // URL here that a payer is actually navigated to, so userinfo is
            // refused in THIS branch (the shared HttpsUrl definition, which the
            // host's own signed return routes use, stays permissive).
            'userinfo phishing' => ['https://psp.example.com@evil.example.com/session/abc'],
            'user only' => ['https://psp.example.com@evil.example.com/x'],
            'user and password' => ['https://user:pass@evil.example.com/session/abc'],
        ];
    }

    public function testTheSharedReturnUrlDefinitionStaysPermissiveAboutUserinfo(): void
    {
        // The complement of the case above: the stricter userinfo rule is scoped
        // to the CHECKOUT url. Tightening HttpsUrl would change what
        // CheckoutService accepts too, which is not this task's call to make.
        self::assertTrue(HttpsUrl::isAbsoluteHttps('https://user@shop.example.com/pay/return?sig=x'));
        self::assertTrue(HttpsUrl::isAbsoluteHttps(self::RETURN_URL));
        self::assertFalse(HttpsUrl::isAbsoluteHttps('http://shop.example.com/pay/return'));
    }

    /**
     * Payvia 2.6's ensure-live can raise TYPED exceptions on a repeat initiate
     * (a renewal that cannot be served). Commerce programs against the CONTRACT,
     * so it catches `\Throwable` and maps every one of those failure modes onto
     * a typed unavailable outcome -- never an exception leak to the payer.
     */
    public function testARenewalUnavailableThrowFromTheCollectorIsTypedUnavailable(): void
    {
        $refusal = $this->assertProviderOutcomeRefuses(
            PaymentLinkException::CHECKOUT_INITIATION_FAILED,
            static function (): PaymentInitiation {
                throw new \DomainException('renewal unavailable for intent int_abc123 at https://psp.example.com/x');
            }
        );

        self::assertStringNotContainsString('renewal unavailable', $refusal->getMessage());
        self::assertStringNotContainsString('psp.example.com', $refusal->getMessage());
        self::assertNull($refusal->getPrevious(), 'a third-party throwable must not ride out attached');
    }

    public function testAnUnrecognisedCollectorStatusIsTypedUnavailable(): void
    {
        $this->assertProviderOutcomeRefuses(
            PaymentLinkException::CHECKOUT_INITIATION_FAILED,
            static fn (): PaymentInitiation => new PaymentInitiation('fakepsp', 'init_failed', [])
        );
    }

    // =====================================================================
    // Phase A refusals: one generic non-payable answer
    // =====================================================================

    public function testAnUnknownOrMalformedTokenIsRefusedWithoutTouchingTheProvider(): void
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $service = $this->service($collector, $this->returnUrls());
        $this->mintToken($service);

        $candidates = ['unknown' => str_repeat('a', 64)] + $this->malformedTokens();
        foreach ($candidates as $label => $candidate) {
            $this->assertRefuses(
                PaymentLinkException::PAYMENT_LINK_NOT_PAYABLE,
                fn () => $service->initiateByToken($this->context, $candidate, $this->at('09:00:00')),
                $label
            );
        }

        self::assertSame(0, $collector->calls);
        self::assertSame(0, (int) $this->currentLinkRow()['initiation_count']);
    }

    public function testACrossTenantTokenIsRefused(): void
    {
        $this->seedOrder(self::ORDER, tenant: self::OTHER_TENANT);
        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $foreign = $this->serviceFor(self::OTHER_TENANT, $collector, $this->returnUrls());
        $token = $foreign->mint(
            $this->context,
            self::OTHER_TENANT,
            self::ORDER,
            null,
            self::ACTOR,
            $this->at('08:00:00')
        )['rawToken'];

        // The SAME token, resolved through a service whose host tenant differs.
        $this->assertRefuses(
            PaymentLinkException::PAYMENT_LINK_NOT_PAYABLE,
            fn () => $this->service($collector, $this->returnUrls())
                ->initiateByToken($this->context, $token, $this->at('09:00:00'))
        );

        self::assertSame(0, $collector->calls);
    }

    /** @dataProvider nonPayableLinkStates */
    public function testANonPayableLinkIsRefusedBeforeAnyProviderCall(string $scenario): void
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service, ttlDays: 1);
        $at = $this->at('09:00:00');

        switch ($scenario) {
            case 'revoked':
                $service->revoke($this->context, self::TENANT, self::ORDER, self::ACTOR, $this->at('08:30:00'));
                break;
            case 'superseded':
                $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:30:00'));
                break;
            case 'expired':
                $at = $this->at2('2026-08-13 08:00:00');
                break;
            default:
                $this->setOrderStatus(self::ORDER, $scenario);
        }

        $this->assertRefuses(
            PaymentLinkException::PAYMENT_LINK_NOT_PAYABLE,
            fn () => $service->initiateByToken($this->context, $token, $at),
            $scenario
        );

        self::assertSame(0, $collector->calls, $scenario);
        foreach ($this->allLinkRows() as $row) {
            self::assertSame(0, (int) $row['initiation_count'], "{$scenario} must not consume budget");
            self::assertNull($row['provider_session_issued_at'], $scenario);
        }
    }

    /** @return array<string, array{string}> */
    public static function nonPayableLinkStates(): array
    {
        return [
            'revoked link' => ['revoked'],
            'superseded link' => ['superseded'],
            'expired link' => ['expired'],
            'paid order' => ['paid'],
            'canceled order' => ['canceled'],
            'refunded order' => ['refunded'],
            'draft order' => ['draft'],
        ];
    }

    // =====================================================================
    // The PayableReference: same shape as an ordinary order, never the token
    // =====================================================================

    public function testThePayableIsTheOrdinaryCommerceOrderShapeWithReturnMetadata(): void
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);
        $linkUuid = (string) $this->currentLinkRow()['uuid'];

        $service->initiateByToken($this->context, $token, $this->at('09:00:00'));

        $payable = $collector->payables[0];
        self::assertInstanceOf(PayableReference::class, $payable);
        self::assertSame(OrderPayable::TYPE, $payable->type);
        self::assertSame(self::ORDER, $payable->id);
        self::assertSame(2874, $payable->amount);
        self::assertSame('USD', $payable->currency);
        self::assertSame('Order ORD-PL-INIT1', $payable->description);

        self::assertSame(
            ['callback_url', 'cancel_url', 'email', 'link_uuid'],
            $this->sortedKeys($payable->metadata)
        );
        self::assertSame(self::RETURN_URL, $payable->metadata['callback_url']);
        self::assertSame(self::CANCEL_URL, $payable->metadata['cancel_url']);
        self::assertSame($linkUuid, $payable->metadata['link_uuid']);
        self::assertSame('buyer@example.com', $payable->metadata['email']);
    }

    /**
     * Review round 1, minor 5. A WALK-IN admin order legitimately has no email
     * at all -- and a payment link is exactly the instrument for collecting on
     * one. The key must therefore be OMITTED, not sent as `''`: payvia reads
     * `metadata['email'] ?? null`, so absent means "no payer email" while an
     * empty string is an invalid address a gateway rejects, turning a
     * supportable order into an undiagnosable `checkout_initiation_failed`.
     *
     * @dataProvider emptyOrderEmails
     */
    public function testAnOrderWithNoEmailOmitsTheKeyRatherThanSendingAnEmptyOne(?string $email): void
    {
        $this->seedOrder(self::ORDER);
        $this->setOrderEmail(self::ORDER, $email);

        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);

        $result = $service->initiateByToken($this->context, $token, $this->at('09:00:00'));

        self::assertSame(self::CHECKOUT_URL, $result['checkoutUrl'], 'an emailless order still initiates');
        $metadata = $collector->payables[0]->metadata;
        self::assertArrayNotHasKey('email', $metadata);
        self::assertSame(['callback_url', 'cancel_url', 'link_uuid'], $this->sortedKeys($metadata));
        self::assertNotNull($this->currentLinkRow()['provider_session_issued_at']);
    }

    /** @return array<string, array{string|null}> */
    public static function emptyOrderEmails(): array
    {
        return [
            'null email' => [null],
            'empty email' => [''],
            'whitespace email' => ['   '],
        ];
    }

    /**
     * The sentinel. Whatever else the payable carries, the BEARER TOKEN (and its
     * hash) must be absent from every corner of it -- a payable is handed to a
     * third-party gateway, stored in its dashboard, and replayed through webhooks.
     */
    public function testThePayableAndItsMetadataNeverCarryTheRawToken(): void
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector($this->ok(self::CHECKOUT_URL));
        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);

        $service->initiateByToken($this->context, $token, $this->at('09:00:00'));

        $payable = $collector->payables[0];
        $serialized = json_encode([
            'type' => $payable->type,
            'id' => $payable->id,
            'amount' => $payable->amount,
            'currency' => $payable->currency,
            'description' => $payable->description,
            'metadata' => $payable->metadata,
        ], JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($token, $serialized, 'the raw token reached the payment provider');
        self::assertStringNotContainsString(hash('sha256', $token), $serialized, 'the token hash reached it');
        self::assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', $serialized);
    }

    // =====================================================================
    // Stack frames are an egress point too (Task 6, review round 1 Important 1)
    // =====================================================================

    /**
     * The Task 6 frame-scrub harness, extended to `initiateByToken()`'s throw
     * paths: the raw token is a PARAMETER here, so it must be overwritten with
     * the redaction sentinel immediately after hashing -- before ANY I/O and
     * before ANY throw.
     *
     * @dataProvider initiationThrowPaths
     */
    public function testNoInitiationThrowPathPutsARawTokenInAStackTrace(string $scenario): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            // CONTROL: with args recorded, a frame holding a token really leaks.
            $control = self::leakyControlFrame(str_repeat('e', 64));
            self::assertStringContainsString(str_repeat('e', 15), $control, 'the harness must observe a leak');

            [$token, $trace] = $this->{'traceFor' . ucfirst($scenario)}();

            self::assertNotSame('', $token);
            self::assertStringNotContainsString($token, $trace, "{$scenario} leaked the whole token");
            self::assertStringNotContainsString(substr($token, 0, 15), $trace, "{$scenario} leaked a prefix");
            self::assertDoesNotMatchRegularExpression('/[a-f0-9]{32,}/', $trace, "{$scenario} trace holds hex");
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /** @return array<string, array{string}> */
    public static function initiationThrowPaths(): array
    {
        return [
            'unknown token refusal' => ['unknownToken'],
            'phase A non-payable refusal' => ['nonPayable'],
            'return url unavailable' => ['returnUnavailable'],
            'collector throwing' => ['collectorThrow'],
            'phase B refusal' => ['phaseBRefusal'],
            'broken connection' => ['brokenConnection'],
        ];
    }

    /** @return array{0: string, 1: string} */
    private function traceForUnknownToken(): array
    {
        $token = str_repeat('a', 64);
        $service = $this->service($this->collector($this->ok(self::CHECKOUT_URL)), $this->returnUrls());

        try {
            $service->initiateByToken($this->context, $token, $this->at('09:00:00'));
        } catch (PaymentLinkException $e) {
            return [$token, $e->getTraceAsString()];
        }

        self::fail('an unknown token must refuse');
    }

    /** @return array{0: string, 1: string} */
    private function traceForNonPayable(): array
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service($this->collector($this->ok(self::CHECKOUT_URL)), $this->returnUrls());
        $token = $this->mintToken($service);
        $this->setOrderStatus(self::ORDER, 'paid');

        try {
            $service->initiateByToken($this->context, $token, $this->at('09:00:00'));
        } catch (PaymentLinkException $e) {
            return [$token, $e->getTraceAsString()];
        }

        self::fail('a paid order must refuse');
    }

    /** @return array{0: string, 1: string} */
    private function traceForReturnUnavailable(): array
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service($this->collector($this->ok(self::CHECKOUT_URL)), null);
        $token = $this->mintToken($service);

        try {
            $service->initiateByToken($this->context, $token, $this->at('09:00:00'));
        } catch (PaymentLinkException $e) {
            return [$token, $e->getTraceAsString()];
        }

        self::fail('an unbound return provider must refuse');
    }

    /** @return array{0: string, 1: string} */
    private function traceForCollectorThrow(): array
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector(static function (): PaymentInitiation {
            throw new \RuntimeException('provider down');
        });
        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);

        try {
            $service->initiateByToken($this->context, $token, $this->at('09:00:00'));
        } catch (PaymentLinkException $e) {
            return [$token, $e->getTraceAsString()];
        }

        self::fail('a throwing collector must refuse');
    }

    /** @return array{0: string, 1: string} */
    private function traceForPhaseBRefusal(): array
    {
        $this->seedOrder(self::ORDER);
        $service = null;
        $collector = $this->collector(function () use (&$service): PaymentInitiation {
            $service->revoke($this->context, self::TENANT, self::ORDER, self::ACTOR, $this->at('09:00:01'));

            return new PaymentInitiation('fakepsp', 'ok', ['checkout_url' => self::CHECKOUT_URL]);
        });
        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);

        try {
            $service->initiateByToken($this->context, $token, $this->at('09:00:00'));
        } catch (PaymentLinkException $e) {
            return [$token, $e->getTraceAsString()];
        }

        self::fail('a revoked link must make Phase B refuse');
    }

    /** @return array{0: string, 1: string} */
    private function traceForBrokenConnection(): array
    {
        $token = str_repeat('b', 64);
        $service = $this->service($this->collector($this->ok(self::CHECKOUT_URL)), $this->returnUrls());

        try {
            $service->initiateByToken($this->contextWithoutDatabase(), $token, $this->at('09:00:00'));
        } catch (\Throwable $e) {
            return [$token, $e->getTraceAsString()];
        }

        self::fail('a well-formed token must reach the (broken) database');
    }

    /** The deliberately-vulnerable shape, for the control assertion. */
    private static function leakyControlFrame(string $secret): string
    {
        try {
            self::throwsWhileHolding($secret);
        } catch (\Throwable $e) {
            return $e->getTraceAsString();
        }

        self::fail('control frame must throw');
    }

    private static function throwsWhileHolding(string $secret): void
    {
        throw new \RuntimeException('control');
    }

    // =====================================================================
    // Contract surface + DI wiring
    // =====================================================================

    public function testInitiateByTokenIsTheOnlyNewSeamThatNamesARawToken(): void
    {
        $method = new \ReflectionMethod(PaymentLinkService::class, 'initiateByToken');
        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $method->getParameters()
        );

        self::assertSame(['context', 'rawToken', 'now'], $names);
    }

    public function testEveryNewErrorCodeIsInTheClosedDiscriminatorDomain(): void
    {
        foreach ([
            PaymentLinkException::PAYMENT_LINK_NOT_PAYABLE,
            PaymentLinkException::INITIATION_RATE_LIMITED,
            PaymentLinkException::RETURN_URL_UNAVAILABLE,
            PaymentLinkException::CHECKOUT_MANUAL,
            PaymentLinkException::CHECKOUT_URL_MISSING,
            PaymentLinkException::CHECKOUT_URL_UNTRUSTED,
            PaymentLinkException::CHECKOUT_INITIATION_FAILED,
        ] as $code) {
            self::assertContains($code, PaymentLinkException::ERROR_CODES, $code);
        }

        self::assertSame(
            count(PaymentLinkException::ERROR_CODES),
            count(array_unique(PaymentLinkException::ERROR_CODES)),
            'the discriminator domain must stay a set'
        );
    }

    public function testTheServiceTakesTheCollectorAndReturnSeamAsCollaborators(): void
    {
        $constructor = (new \ReflectionClass(PaymentLinkService::class))->getConstructor();
        self::assertNotNull($constructor);

        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $constructor->getParameters()
        );

        self::assertContains('collector', $names);
        self::assertContains('returnUrls', $names);
        foreach ($constructor->getParameters() as $parameter) {
            self::assertStringNotContainsStringIgnoringCase('token', $parameter->getName());
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @param callable(): PaymentInitiation $respond */
    private function assertProviderOutcomeRefuses(string $errorCode, callable $respond): PaymentLinkException
    {
        $this->seedOrder(self::ORDER);
        $collector = $this->collector($respond);
        $service = $this->service($collector, $this->returnUrls());
        $token = $this->mintToken($service);

        $refusal = $this->assertRefuses(
            $errorCode,
            fn () => $service->initiateByToken($this->context, $token, $this->at('09:00:00'))
        );

        self::assertSame(1, $collector->calls);
        $row = $this->currentLinkRow();
        self::assertNull($row['provider_session_issued_at'], 'a failed provider leg exposes nothing');
        self::assertSame(1, (int) $row['initiation_count'], 'the budget is still consumed');

        return $refusal;
    }

    /** @return callable(): PaymentInitiation */
    private function ok(string $url): callable
    {
        return static fn (): PaymentInitiation => new PaymentInitiation('fakepsp', 'ok', ['checkout_url' => $url]);
    }

    /** @param callable(): PaymentInitiation $respond */
    private function collector(callable $respond): object
    {
        return new class ($respond) implements PaymentCollector {
            public int $calls = 0;

            /** @var list<PayableReference> */
            public array $payables = [];

            /** @param callable(): PaymentInitiation $respond */
            public function __construct(private $respond)
            {
            }

            public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
            {
                $this->calls++;
                $this->payables[] = $payable;

                return ($this->respond)();
            }
        };
    }

    /** @param array<string,mixed>|null $urls */
    private function returnUrls(?array $urls = null): object
    {
        /** @var array<string,mixed>|null $resolved */
        $resolved = func_num_args() === 0
            ? ['return' => self::RETURN_URL, 'cancel' => self::CANCEL_URL]
            : $urls;

        return new class ($resolved) implements PaymentLinkReturnUrlProvider {
            /** @var list<string> */
            public array $seen = [];

            /** @param array<string,mixed>|null $urls */
            public function __construct(private ?array $urls)
            {
            }

            /** @return array{return: string, cancel: string}|null */
            public function urlsFor(ApplicationContext $context, string $linkUuid): ?array
            {
                $this->seen[] = $linkUuid;

                /** @var array{return: string, cancel: string}|null */
                return $this->urls;
            }
        };
    }

    private function service(
        ?PaymentCollector $collector = null,
        ?PaymentLinkReturnUrlProvider $returnUrls = null
    ): PaymentLinkService {
        return $this->serviceFor(self::TENANT, $collector, $returnUrls);
    }

    private function serviceFor(
        string $tenant,
        ?PaymentCollector $collector = null,
        ?PaymentLinkReturnUrlProvider $returnUrls = null
    ): PaymentLinkService {
        return new PaymentLinkService(
            new OrderRepository(),
            new PaymentLinkRepository(),
            new class ($tenant) implements CurrentTenantResolver {
                public function __construct(private string $tenant)
                {
                }

                public function tenantUuid(ApplicationContext $context): string
                {
                    return $this->tenant;
                }
            },
            null,
            $collector,
            $returnUrls
        );
    }

    private function mintToken(PaymentLinkService $service, ?int $ttlDays = null): string
    {
        return $service->mint(
            $this->context,
            self::TENANT,
            self::ORDER,
            $ttlDays,
            self::ACTOR,
            $this->at('08:00:00')
        )['rawToken'];
    }

    /** @return array<string,mixed> */
    private function currentLinkRow(): array
    {
        return $this->currentLinkRowByStatus(null);
    }

    /** @return array<string,mixed> */
    private function currentLinkRowByStatus(?string $status): array
    {
        $rows = $this->allLinkRows();
        if ($status !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (string) $row['status'] === $status
            ));
        }

        self::assertNotSame([], $rows, 'expected a payment-link row');

        return $rows[count($rows) - 1];
    }

    /** @return list<array<string,mixed>> */
    private function allLinkRows(): array
    {
        return $this->connection->table('commerce_payment_links')->orderBy('id', 'ASC')->get();
    }

    /** @return array<string, string> */
    private function malformedTokens(): array
    {
        return [
            'empty' => '',
            'too short' => str_repeat('a', 63),
            'too long' => str_repeat('a', 65),
            'uppercase' => str_repeat('A', 64),
            'non hex' => str_repeat('g', 64),
            'trailing newline' => str_repeat('a', 64) . "\n",
            'sql-ish' => "' OR 1=1 --" . str_repeat('a', 53),
        ];
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }

    private function normalize(string $stored): string
    {
        return (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-11 ' . $time, new \DateTimeZone('UTC'));
    }

    private function at2(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }

    /** @param callable(): mixed $operation */
    private function assertRefuses(string $errorCode, callable $operation, string $label = ''): PaymentLinkException
    {
        try {
            $operation();
        } catch (PaymentLinkException $e) {
            self::assertSame($errorCode, $e->errorCode, $label);

            return $e;
        }

        self::fail("expected a typed '{$errorCode}' refusal {$label}");
    }

    private function setOrderStatus(string $orderUuid, string $status): void
    {
        $this->connection->table('commerce_orders')
            ->where('uuid', '=', $orderUuid)
            ->update(['status' => $status]);
    }

    private function setOrderEmail(string $orderUuid, ?string $email): void
    {
        $this->connection->table('commerce_orders')
            ->where('uuid', '=', $orderUuid)
            ->update(['email' => $email]);
    }

    /** Rewrites the current link's TTL directly -- the only way to pin it against the REAL clock. */
    private function setLinkExpiry(\DateTimeImmutable $expiresAt): void
    {
        $this->connection->table('commerce_payment_links')
            ->where('uuid', '=', (string) $this->currentLinkRow()['uuid'])
            ->update(['expires_at' => $expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
    }

    private function contextWithoutDatabase(): ApplicationContext
    {
        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->setContainer($container);
        $context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../config/commerce.php');

        return $context;
    }

    private function seedOrder(
        string $orderUuid,
        string $tenant = self::TENANT,
        string $origin = 'admin',
        string $status = 'pending_payment'
    ): void {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $orderUuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-PL-INIT1',
            'status' => $status,
            'origin' => $origin,
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'email' => 'buyer@example.com',
            'user_uuid' => 'plinituser01',
            'guest_token_hash' => str_repeat('c', 64),
            'currency' => 'USD',
            'subtotal' => 2474,
            'discount_total' => 100,
            'shipping_total' => 300,
            'tax_total' => 200,
            'grand_total' => 2874,
        ]);

        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => substr('l' . substr(md5($orderUuid), 0, 11), 0, 12),
            'order_uuid' => $orderUuid,
            'variant_uuid' => 'plinitvar001',
            'product_name' => 'Blue Mug',
            'sku' => 'MUG-BLUE',
            'option_values' => json_encode(['color' => 'blue'], JSON_THROW_ON_ERROR),
            'unit_price' => 1237,
            'quantity' => 2,
            'line_total' => 2474,
        ]);
    }
}
