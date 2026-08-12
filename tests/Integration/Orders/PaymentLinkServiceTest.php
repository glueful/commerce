<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\CommerceServiceProvider;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkPublicUrlProvider;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkReturnUrlProvider;
use Glueful\Extensions\Commerce\Orders\Events\PaymentLinkEvents;
use Glueful\Extensions\Commerce\Orders\LinkView;
use Glueful\Extensions\Commerce\Orders\PaymentLinkAdminView;
use Glueful\Extensions\Commerce\Orders\PaymentLinkException;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkService;
use Glueful\Extensions\Commerce\Orders\UnavailablePaymentLinkPublicUrlProvider;
use Glueful\Extensions\Commerce\Orders\UnavailablePaymentLinkReturnUrlProvider;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Psr\Container\ContainerInterface;

/**
 * Payment-links Task 6 (design spec §2.2): `PaymentLinkService` -- mint/revoke/
 * resolve, the authenticated current-token seam, the public-URL host seam, and
 * the two closed egress views.
 *
 * The single property every other assertion here serves: the RAW token has
 * exactly TWO engine egress points -- {@see PaymentLinkService::mint()}'s return
 * value and, embedded once, {@see PaymentLinkService::mintPublic()}'s URL. It
 * must never reach a table, an audit payload, an exception message, or any
 * other method's output.
 *
 * Every clock is INJECTED, never `time()`, so TTL boundaries are asserted
 * exactly with no tolerance window -- the same discipline as
 * `Orders\PaymentLinkRepositoryTest`.
 */
final class PaymentLinkServiceTest extends CommerceTestCase
{
    private const TENANT = 'plsvctenA001';
    private const OTHER_TENANT = 'plsvctenB002';
    private const ORDER = 'plsvcorder01';
    private const ACTOR = 'plsvcactor01';

    private PaymentLinkRepository $links;
    private OrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links = new PaymentLinkRepository();
        $this->orders = new OrderRepository();
    }

    // =====================================================================
    // Token custody: shape, unpredictability, hash-only persistence
    // =====================================================================

    public function testTheMintedTokenIsSixtyFourLowercaseHexAndUnpredictable(): void
    {
        $this->seedOrder(self::ORDER);

        $first = $this->service()->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        $second = $this->service()->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:01:00'));

        foreach ([$first['rawToken'], $second['rawToken']] as $token) {
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
        }
        self::assertNotSame($first['rawToken'], $second['rawToken']);
    }

    public function testMintPersistsTheHashAndNeverTheRawToken(): void
    {
        $this->seedOrder(self::ORDER);

        $minted = $this->service()->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        $row = $this->links->findByUuid($this->context, self::TENANT, $minted['link']->linkUuid);

        self::assertNotNull($row);
        self::assertSame(hash('sha256', $minted['rawToken']), $row['token_hash']);
        self::assertStringNotContainsString($minted['rawToken'], json_encode($row, JSON_THROW_ON_ERROR));
        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $row['status']);
        self::assertSame(self::ACTOR, $row['created_by']);
    }

    public function testMintReturnsTheAdminViewAlongsideTheOneTimeToken(): void
    {
        $this->seedOrder(self::ORDER);

        $minted = $this->service()->mint($this->context, self::TENANT, self::ORDER, 5, self::ACTOR, $this->at('08:00:00'));

        self::assertSame(['rawToken', 'link'], array_keys($minted));
        self::assertInstanceOf(PaymentLinkAdminView::class, $minted['link']);
        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $minted['link']->status);
        self::assertSame('2026-08-16 08:00:00', $minted['link']->expiresAt);
        self::assertFalse($minted['link']->providerSessionIssued);
    }

    // =====================================================================
    // Mint eligibility: tenant-owned + admin origin + pending_payment
    // =====================================================================

    public function testMintRefusesAnUnknownOrder(): void
    {
        $this->assertRefuses(
            PaymentLinkException::ORDER_NOT_FOUND,
            fn () => $this->service()->mint($this->context, self::TENANT, 'plsvcnosuch1', null, self::ACTOR, $this->at('08:00:00'))
        );
    }

    public function testMintRefusesACrossTenantOrder(): void
    {
        $this->seedOrder(self::ORDER, tenant: self::OTHER_TENANT);

        $this->assertRefuses(
            PaymentLinkException::ORDER_NOT_FOUND,
            fn () => $this->service()->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'))
        );
    }

    public function testMintRefusesAStorefrontOriginOrder(): void
    {
        $this->seedOrder(self::ORDER, origin: 'storefront');

        $this->assertRefuses(
            PaymentLinkException::ORDER_NOT_ADMIN_ORIGIN,
            fn () => $this->service()->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'))
        );
    }

    /** @dataProvider ineligibleStatuses */
    public function testMintRefusesAnOrderThatIsNotPendingPayment(string $status): void
    {
        $this->seedOrder(self::ORDER, status: $status);

        $this->assertRefuses(
            PaymentLinkException::ORDER_NOT_PENDING_PAYMENT,
            fn () => $this->service()->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'))
        );
    }

    /** @return array<string, array{string}> */
    public static function ineligibleStatuses(): array
    {
        return [
            'paid' => ['paid'],
            'canceled' => ['canceled'],
            'refunded' => ['refunded'],
            'draft' => ['draft'],
        ];
    }

    public function testARefusedMintCreatesNoLinkRow(): void
    {
        $this->seedOrder(self::ORDER, status: 'paid');

        try {
            $this->service()->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
            self::fail('an ineligible order must refuse');
        } catch (PaymentLinkException) {
            // expected
        }

        self::assertSame([], $this->allLinkRows());
    }

    // =====================================================================
    // TTL clamp 1..30, default from `commerce.payment_links.ttl_days`
    // =====================================================================

    public function testTtlDefaultsToTheConfiguredSevenDays(): void
    {
        $this->seedOrder(self::ORDER);

        $minted = $this->service()->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));

        self::assertSame('2026-08-18 08:00:00', $minted['link']->expiresAt);
    }

    /** @dataProvider ttlClamps */
    public function testTheRequestedTtlIsClampedIntoOneToThirtyDays(?int $requested, string $expiresAt): void
    {
        $this->seedOrder(self::ORDER);

        $minted = $this->service()->mint($this->context, self::TENANT, self::ORDER, $requested, self::ACTOR, $this->at('08:00:00'));

        self::assertSame($expiresAt, $minted['link']->expiresAt);
    }

    /** @return array<string, array{int|null, string}> */
    public static function ttlClamps(): array
    {
        return [
            'zero clamps up to one' => [0, '2026-08-12 08:00:00'],
            'negative clamps up to one' => [-9, '2026-08-12 08:00:00'],
            'one is honoured' => [1, '2026-08-12 08:00:00'],
            'thirty is honoured' => [30, '2026-09-10 08:00:00'],
            'thirty-one clamps down' => [31, '2026-09-10 08:00:00'],
            'absurd clamps down' => [100000, '2026-09-10 08:00:00'],
        ];
    }

    public function testAConfiguredDefaultOutsideTheRangeIsClampedToo(): void
    {
        $this->seedOrder(self::ORDER);
        $this->context->overrideConfig('commerce.payment_links.ttl_days', 900);

        $minted = $this->service()->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));

        self::assertSame('2026-09-10 08:00:00', $minted['link']->expiresAt);
    }

    // =====================================================================
    // Ruling 7: one active link per order
    // =====================================================================

    public function testRegenerateRevokesThePriorLinkAndLeavesExactlyOneActive(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();

        $first = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        $second = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('09:00:00'));

        $rows = $this->allLinkRows();
        self::assertCount(2, $rows);

        $byUuid = [];
        foreach ($rows as $row) {
            $byUuid[(string) $row['uuid']] = (string) $row['status'];
        }
        self::assertSame(PaymentLinkRepository::STATUS_REVOKED, $byUuid[$first['link']->linkUuid]);
        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $byUuid[$second['link']->linkUuid]);

        // The superseded token still RESOLVES -- but to the STATE-ONLY shape, so
        // the page can say "this link was replaced" without the holder of a
        // superseded (possibly leaked) token continuing to read the order.
        $stale = $service->resolveByToken($this->context, $first['rawToken'], $this->at('09:01:00'));
        self::assertInstanceOf(LinkView::class, $stale);
        self::assertSame(PaymentLinkRepository::STATUS_REVOKED, $stale->linkStatus);
        self::assertTrue($stale->contentRedacted);

        $current = $service->resolveByToken($this->context, $second['rawToken'], $this->at('09:01:00'));
        self::assertInstanceOf(LinkView::class, $current);
        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $current->linkStatus);
        self::assertFalse($current->contentRedacted);
    }

    public function testRevokeRevokesTheActiveLinkAndIsIdempotent(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $minted = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));

        $service->revoke($this->context, self::TENANT, self::ORDER, self::ACTOR, $this->at('09:00:00'));
        $service->revoke($this->context, self::TENANT, self::ORDER, self::ACTOR, $this->at('09:05:00'));

        $row = $this->links->findByUuid($this->context, self::TENANT, $minted['link']->linkUuid);
        self::assertNotNull($row);
        self::assertSame(PaymentLinkRepository::STATUS_REVOKED, $row['status']);

        $view = $service->resolveByToken($this->context, $minted['rawToken'], $this->at('09:10:00'));
        self::assertInstanceOf(LinkView::class, $view);
        self::assertSame(PaymentLinkRepository::STATUS_REVOKED, $view->linkStatus, 'a revoked link reports its state');
        self::assertTrue($view->contentRedacted, 'and stops disclosing the order');

        // Idempotent: the second revoke records nothing extra.
        $revocations = array_filter(
            $this->orders->eventsForOrder($this->context, self::TENANT, self::ORDER),
            static fn (array $event): bool => $event['type'] === PaymentLinkEvents::REVOKED
        );
        self::assertCount(1, $revocations);
    }

    public function testRevokeRefusesAnUnknownOrCrossTenantOrder(): void
    {
        $this->seedOrder(self::ORDER, tenant: self::OTHER_TENANT);

        $this->assertRefuses(
            PaymentLinkException::ORDER_NOT_FOUND,
            fn () => $this->service()->revoke($this->context, self::TENANT, self::ORDER, self::ACTOR, $this->at('09:00:00'))
        );
    }

    // =====================================================================
    // resolveByToken: shape gate, generic null, lazy transitions
    // =====================================================================

    public function testResolveReturnsTheLinkViewForTheCurrentToken(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $minted = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));

        $view = $service->resolveByToken($this->context, $minted['rawToken'], $this->at('09:00:00'));

        self::assertInstanceOf(LinkView::class, $view);
        self::assertSame('ORD-PL-0001', $view->orderNumber);
        self::assertSame('USD', $view->currency);
        self::assertSame('pending_payment', $view->orderStatus);
        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $view->linkStatus);
        self::assertSame('2026-08-18 08:00:00', $view->expiresAt);
        self::assertFalse($view->providerSessionIssued);
        self::assertSame(2874, $view->grandTotal);
        self::assertSame([['name' => 'Blue Mug', 'quantity' => 2]], $view->lines);
    }

    public function testResolveIsOneGenericNullForUnknownMalformedAndCrossTenantTokens(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));

        // A well-formed token of another tenant's link.
        $this->seedOrder('plsvcorderX', tenant: self::OTHER_TENANT);
        $foreign = $this->serviceFor(self::OTHER_TENANT)
            ->mint($this->context, self::OTHER_TENANT, 'plsvcorderX', null, self::ACTOR, $this->at('08:00:00'));

        $unknown = str_repeat('a', 64);
        self::assertNull($service->resolveByToken($this->context, $unknown, $this->at('09:00:00')));
        self::assertNull($service->resolveByToken($this->context, $foreign['rawToken'], $this->at('09:00:00')));

        foreach ($this->malformedTokens() as $label => $malformed) {
            self::assertNull($service->resolveByToken($this->context, $malformed, $this->at('09:00:00')), $label);
        }
    }

    /**
     * The shape gate runs BEFORE any database work: with a context whose
     * container cannot produce a connection, a malformed token still answers
     * null while a well-formed one blows up trying to reach the table. That
     * asymmetry is the proof -- a gate applied after the lookup would make both
     * throw, and a gate that also short-circuited well-formed unknowns would
     * make both return null.
     */
    public function testTheShapeGateRunsBeforeAnyDatabaseWork(): void
    {
        $broken = $this->contextWithoutDatabase();
        $service = $this->service();

        foreach ($this->malformedTokens() as $label => $malformed) {
            self::assertNull($service->resolveByToken($broken, $malformed, $this->at('09:00:00')), $label);
        }

        $this->expectException(\RuntimeException::class);
        $service->resolveByToken($broken, str_repeat('a', 64), $this->at('09:00:00'));
    }

    public function testResolveLazilyExpiresAPastTtlLink(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $minted = $service->mint($this->context, self::TENANT, self::ORDER, 1, self::ACTOR, $this->at('08:00:00'));

        $view = $service->resolveByToken($this->context, $minted['rawToken'], $this->at2('2026-08-12 08:00:01'));

        self::assertInstanceOf(LinkView::class, $view);
        self::assertSame(PaymentLinkRepository::STATUS_EXPIRED, $view->linkStatus);

        $row = $this->links->findByUuid($this->context, self::TENANT, $minted['link']->linkUuid);
        self::assertNotNull($row);
        self::assertSame(PaymentLinkRepository::STATUS_EXPIRED, $row['status'], 'the lazy transition must persist');
    }

    public function testResolveLazilyConsumesTheLinkWhenTheOrderIsPaid(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $minted = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));

        $this->setOrderStatus(self::ORDER, 'paid');
        $view = $service->resolveByToken($this->context, $minted['rawToken'], $this->at('09:00:00'));

        self::assertInstanceOf(LinkView::class, $view);
        self::assertSame('paid', $view->orderStatus);
        self::assertSame(PaymentLinkRepository::STATUS_CONSUMED, $view->linkStatus);

        $row = $this->links->findByUuid($this->context, self::TENANT, $minted['link']->linkUuid);
        self::assertNotNull($row);
        self::assertSame(PaymentLinkRepository::STATUS_CONSUMED, $row['status']);
    }

    public function testAPaidOrderConsumesRatherThanExpiresEvenPastTtl(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $minted = $service->mint($this->context, self::TENANT, self::ORDER, 1, self::ACTOR, $this->at('08:00:00'));
        $this->setOrderStatus(self::ORDER, 'paid');

        $view = $service->resolveByToken($this->context, $minted['rawToken'], $this->at2('2026-08-20 08:00:00'));

        self::assertInstanceOf(LinkView::class, $view);
        self::assertSame(PaymentLinkRepository::STATUS_CONSUMED, $view->linkStatus);
    }

    /** @dataProvider terminalOrderStatuses */
    public function testResolveReportsAnHonestStateForACanceledOrRefundedOrder(string $status): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $minted = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));

        $this->setOrderStatus(self::ORDER, $status);
        $view = $service->resolveByToken($this->context, $minted['rawToken'], $this->at('09:00:00'));

        self::assertInstanceOf(LinkView::class, $view);
        self::assertSame($status, $view->orderStatus, 'the order state is reported honestly, never masked');
        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $view->linkStatus);
    }

    /** @return array<string, array{string}> */
    public static function terminalOrderStatuses(): array
    {
        return ['canceled' => ['canceled'], 'refunded' => ['refunded']];
    }

    /**
     * Review round 1, Important 2. The primary reason to revoke is a LEAKED
     * link, so revocation must stop the leaker reading the order -- not merely
     * stop them paying. A revoked link resolves to state and expiry only, and
     * its serialized shape contains none of the order's commercial strings.
     */
    public function testARevokedLinkResolvesToStateOnlyAndDisclosesNoOrderContent(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $minted = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        $service->revoke($this->context, self::TENANT, self::ORDER, self::ACTOR, $this->at('08:30:00'));

        $view = $service->resolveByToken($this->context, $minted['rawToken'], $this->at('09:00:00'));
        self::assertInstanceOf(LinkView::class, $view);

        // State survives; content does not.
        self::assertTrue($view->contentRedacted);
        self::assertSame(PaymentLinkRepository::STATUS_REVOKED, $view->linkStatus);
        self::assertSame('pending_payment', $view->orderStatus);
        self::assertSame('2026-08-18 08:00:00', $view->expiresAt);

        $payload = $view->toArray();
        self::assertSame(
            ['content_redacted', 'expires_at', 'link_status', 'order_status', 'provider_session_issued'],
            $this->sortedKeys($payload),
            'the commercial keys are ABSENT, not zeroed -- a zero total could be rendered as a real bill'
        );

        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([
            'ORD-PL-0001',
            'Blue Mug',
            'MUG-BLUE',
            'plsvcvar0001',
            'USD',
            '2874',
            '2474',
            '1237',
        ] as $commercial) {
            self::assertStringNotContainsString(
                $commercial,
                $serialized,
                "a revoked link must not keep disclosing: {$commercial}"
            );
        }
    }

    /**
     * The complement of the redaction rule, and the reason it is not simply
     * "every terminal state redacts": expired and consumed links are presumed to
     * be in the hands of the person they were SENT to, who needs to understand
     * what happened to their bill. Only revocation carries a compromise signal.
     *
     * @dataProvider fullyDisclosingTerminalStates
     */
    public function testEveryOtherTerminalStateKeepsResolvingInFull(string $scenario): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $ttl = $scenario === 'expired' ? 1 : null;
        $minted = $service->mint($this->context, self::TENANT, self::ORDER, $ttl, self::ACTOR, $this->at('08:00:00'));

        $at = $this->at('09:00:00');
        if ($scenario === 'expired') {
            $at = $this->at2('2026-08-20 08:00:00');
        } else {
            $this->setOrderStatus(self::ORDER, $scenario === 'consumed' ? 'paid' : $scenario);
        }

        $view = $service->resolveByToken($this->context, $minted['rawToken'], $at);

        self::assertInstanceOf(LinkView::class, $view);
        self::assertFalse($view->contentRedacted, "{$scenario} must keep disclosing");
        self::assertSame('ORD-PL-0001', $view->orderNumber);
        self::assertSame([['name' => 'Blue Mug', 'quantity' => 2]], $view->lines);
        self::assertSame(2874, $view->grandTotal);
        self::assertArrayHasKey('totals', $view->toArray());
    }

    /** @return array<string, array{string}> */
    public static function fullyDisclosingTerminalStates(): array
    {
        return [
            'expired' => ['expired'],
            'consumed' => ['consumed'],
            'canceled order' => ['canceled'],
            'refunded order' => ['refunded'],
        ];
    }

    public function testResolveExposesTheProviderSessionFlag(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $minted = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        $this->links->stampProviderSessionIssued($this->context, self::TENANT, $minted['link']->linkUuid, $this->at('08:30:00'));

        $view = $service->resolveByToken($this->context, $minted['rawToken'], $this->at('09:00:00'));

        self::assertInstanceOf(LinkView::class, $view);
        self::assertTrue($view->providerSessionIssued);
    }

    // =====================================================================
    // matchCurrentToken: the authenticated 404 / 409 split
    // =====================================================================

    public function testMatchCurrentTokenReturnsTheAdminViewForTheCurrentToken(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $minted = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));

        $view = $service->matchCurrentToken($this->context, self::TENANT, self::ORDER, $minted['rawToken'], $this->at('09:00:00'));

        self::assertInstanceOf(PaymentLinkAdminView::class, $view);
        self::assertSame($minted['link']->linkUuid, $view->linkUuid);
        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $view->status);
        self::assertSame('2026-08-18 08:00:00', $view->expiresAt);
        self::assertFalse($view->providerSessionIssued);
    }

    public function testMatchCurrentTokenIsNullForAnUnknownOrCrossTenantOrder(): void
    {
        $this->seedOrder(self::ORDER, tenant: self::OTHER_TENANT);
        $service = $this->serviceFor(self::OTHER_TENANT);
        $minted = $service->mint($this->context, self::OTHER_TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));

        // The order (and its very real, very current token) belong to another tenant: 404, not 409.
        self::assertNull(
            $this->service()->matchCurrentToken($this->context, self::TENANT, self::ORDER, $minted['rawToken'], $this->at('09:00:00'))
        );
        self::assertNull(
            $this->service()->matchCurrentToken($this->context, self::TENANT, 'plsvcnosuch1', str_repeat('b', 64), $this->at('09:00:00'))
        );
    }

    public function testMatchCurrentTokenRejectsAStaleTokenWithPaymentLinkChanged(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $stale = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('09:00:00'));

        $this->assertRefuses(
            PaymentLinkException::LINK_CHANGED,
            fn () => $service->matchCurrentToken($this->context, self::TENANT, self::ORDER, $stale['rawToken'], $this->at('09:30:00'))
        );
    }

    public function testMatchCurrentTokenRejectsAnUnrelatedTenantsTokenOnAWellOwnedOrder(): void
    {
        $this->seedOrder(self::ORDER);
        $this->seedOrder('plsvcorderX', tenant: self::OTHER_TENANT);
        $service = $this->service();
        $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        $foreign = $this->serviceFor(self::OTHER_TENANT)
            ->mint($this->context, self::OTHER_TENANT, 'plsvcorderX', null, self::ACTOR, $this->at('08:00:00'));

        $this->assertRefuses(
            PaymentLinkException::LINK_CHANGED,
            fn () => $service->matchCurrentToken($this->context, self::TENANT, self::ORDER, $foreign['rawToken'], $this->at('09:00:00'))
        );
    }

    public function testMatchCurrentTokenRejectsAnOrderThatHasNoActiveLink(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $minted = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        $service->revoke($this->context, self::TENANT, self::ORDER, self::ACTOR, $this->at('08:30:00'));

        $this->assertRefuses(
            PaymentLinkException::LINK_CHANGED,
            fn () => $service->matchCurrentToken($this->context, self::TENANT, self::ORDER, $minted['rawToken'], $this->at('09:00:00'))
        );
    }

    public function testMatchCurrentTokenShapeGatesBeforeAnyDatabaseWork(): void
    {
        $broken = $this->contextWithoutDatabase();
        $service = $this->service();

        foreach ($this->malformedTokens() as $label => $malformed) {
            try {
                $service->matchCurrentToken($broken, self::TENANT, self::ORDER, $malformed, $this->at('09:00:00'));
                self::fail("a malformed token must be refused: {$label}");
            } catch (PaymentLinkException $e) {
                self::assertSame(PaymentLinkException::LINK_CHANGED, $e->errorCode, $label);
            }
        }
    }

    public function testMatchCurrentTokenReportsTheLazyStateOfThePastTtlCurrentLink(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $minted = $service->mint($this->context, self::TENANT, self::ORDER, 1, self::ACTOR, $this->at('08:00:00'));

        $view = $service->matchCurrentToken($this->context, self::TENANT, self::ORDER, $minted['rawToken'], $this->at2('2026-08-13 08:00:00'));

        self::assertInstanceOf(PaymentLinkAdminView::class, $view);
        self::assertSame(PaymentLinkRepository::STATUS_EXPIRED, $view->status);
    }

    // =====================================================================
    // mintPublic: URL composed and validated BEFORE persistence
    // =====================================================================

    public function testMintPublicComposesTheUrlAndPersistsTheSameLink(): void
    {
        $this->seedOrder(self::ORDER);
        $provider = $this->urlProvider(static fn (string $token): string => "https://shop.example.com/pay/{$token}");
        $service = $this->service($provider);

        $result = $service->mintPublic($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));

        self::assertSame(['url', 'link'], array_keys($result));
        self::assertInstanceOf(PaymentLinkAdminView::class, $result['link']);
        self::assertNotNull($provider->seen);
        self::assertSame("https://shop.example.com/pay/{$provider->seen}", $result['url']);

        // The URL's token is the one that was actually persisted, hashed.
        $row = $this->links->findByUuid($this->context, self::TENANT, $result['link']->linkUuid);
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $provider->seen), $row['token_hash']);
        self::assertNotNull($service->resolveByToken($this->context, $provider->seen, $this->at('09:00:00')));
    }

    public function testMintPublicWithNoBoundProviderIsTypedUnavailableAndCreatesNoLink(): void
    {
        $this->seedOrder(self::ORDER);

        try {
            $this->service()->mintPublic($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
            self::fail('an unbound public-URL provider must be typed unavailable');
        } catch (PaymentLinkException $e) {
            self::assertSame(PaymentLinkException::PUBLIC_URL_UNAVAILABLE, $e->errorCode);
        }

        self::assertSame([], $this->allLinkRows(), 'no link row may exist when the URL cannot be composed');
    }

    public function testTheEngineDefaultProviderIsUnavailableSoGenericHostsFailExplicitly(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service(new UnavailablePaymentLinkPublicUrlProvider());

        try {
            $service->mintPublic($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
            self::fail('the engine default provider must be typed unavailable');
        } catch (PaymentLinkException $e) {
            self::assertSame(PaymentLinkException::PUBLIC_URL_UNAVAILABLE, $e->errorCode);
        }

        self::assertNull((new UnavailablePaymentLinkPublicUrlProvider())->urlFor($this->context, str_repeat('a', 64)));
        self::assertNull((new UnavailablePaymentLinkReturnUrlProvider())->urlsFor($this->context, 'plsvclink001'));
        self::assertSame([], $this->allLinkRows());
    }

    /** @dataProvider invalidPublicUrls */
    public function testMintPublicRejectsEveryInvalidUrlShapeBeforePersistence(string $template): void
    {
        $this->seedOrder(self::ORDER);
        $provider = $this->urlProvider(
            static fn (string $token): string => str_replace('{token}', $token, $template)
        );

        try {
            $this->service($provider)->mintPublic($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
            self::fail("this URL shape must be refused: {$template}");
        } catch (PaymentLinkException $e) {
            self::assertSame(PaymentLinkException::PUBLIC_URL_UNAVAILABLE, $e->errorCode);
        }

        self::assertSame([], $this->allLinkRows(), "a refused URL must leave no link row: {$template}");
    }

    /** @return array<string, array{string}> */
    public static function invalidPublicUrls(): array
    {
        return [
            'plain http' => ['http://shop.example.com/pay/{token}'],
            'no scheme' => ['//shop.example.com/pay/{token}'],
            'relative' => ['/pay/{token}'],
            'userinfo' => ['https://user:pass@shop.example.com/pay/{token}'],
            'user only' => ['https://user@shop.example.com/pay/{token}'],
            'explicit port' => ['https://shop.example.com:8443/pay/{token}'],
            'query string' => ['https://shop.example.com/pay/{token}?utm=1'],
            'fragment' => ['https://shop.example.com/pay/{token}#top'],
            'token not final segment' => ['https://shop.example.com/pay/{token}/confirm'],
            'token twice' => ['https://shop.example.com/{token}/pay/{token}'],
            'token absent' => ['https://shop.example.com/pay/nothing'],
            'trailing slash after token' => ['https://shop.example.com/pay/{token}/'],
            'no path at all' => ['https://shop.example.com'],
            'empty string' => [''],
            'not a url' => ['definitely not a url {token}'],
        ];
    }

    /**
     * Review round 1, minor 3 -- the ONE externally observable proof that URL
     * composition and validation happen BEFORE the mint transaction opens.
     *
     * The order is PAID (so the transaction would refuse with
     * `order_not_pending_payment`) AND the provider's URL is invalid (so the
     * pre-transaction check refuses with `public_url_unavailable`). Whichever
     * code comes back names which check ran FIRST. Nothing else about the
     * observable behaviour distinguishes the two orderings.
     */
    public function testMintPublicValidatesTheUrlBeforeItEverLooksAtTheOrder(): void
    {
        $this->seedOrder(self::ORDER, status: 'paid');
        $provider = $this->urlProvider(static fn (string $token): string => "http://shop.example.com/pay/{$token}");

        $this->assertRefuses(
            PaymentLinkException::PUBLIC_URL_UNAVAILABLE,
            fn () => $this->service($provider)
                ->mintPublic($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'))
        );

        self::assertSame([], $this->allLinkRows());
    }

    public function testAProviderThatThrowsBecomesTypedUnavailableAndCreatesNoLink(): void
    {
        $this->seedOrder(self::ORDER);
        $provider = new class implements PaymentLinkPublicUrlProvider {
            public function urlFor(ApplicationContext $context, string $rawToken): ?string
            {
                throw new \RuntimeException("boom {$rawToken}");
            }
        };

        try {
            $this->service($provider)->mintPublic($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
            self::fail('a throwing provider must become a typed unavailable outcome');
        } catch (PaymentLinkException $e) {
            self::assertSame(PaymentLinkException::PUBLIC_URL_UNAVAILABLE, $e->errorCode);
        }

        self::assertSame([], $this->allLinkRows());
    }

    /**
     * The unavailable message must never echo the URL it refused -- that URL
     * carries the raw token, and exception messages reach logs and error
     * responses.
     */
    public function testTheUnavailableMessageNeverEchoesTheTokenOrTheUrl(): void
    {
        $this->seedOrder(self::ORDER);
        $provider = $this->urlProvider(static fn (string $token): string => "http://shop.example.com/pay/{$token}");

        try {
            $this->service($provider)->mintPublic($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
            self::fail('plain http must be refused');
        } catch (PaymentLinkException $e) {
            self::assertNotNull($provider->seen);
            self::assertStringNotContainsString($provider->seen, $e->getMessage());
            self::assertStringNotContainsString('shop.example.com', $e->getMessage());
        }
    }

    // =====================================================================
    // Closed egress views
    // =====================================================================

    public function testTheLinkViewCarriesOnlyTheAllowedKeys(): void
    {
        $view = $this->resolvedView();

        self::assertSame(
            [
                'content_redacted',
                'currency',
                'expires_at',
                'line_items',
                'link_status',
                'order_number',
                'order_status',
                'provider_session_issued',
                'totals',
            ],
            $this->sortedKeys($view->toArray())
        );
        self::assertFalse($view->toArray()['content_redacted']);
        self::assertSame(
            ['discount_total', 'grand_total', 'shipping_total', 'subtotal', 'tax_total'],
            $this->sortedKeys($view->toArray()['totals'])
        );
        self::assertSame(['name', 'quantity'], $this->sortedKeys($view->toArray()['line_items'][0]));
    }

    /**
     * The exclusion set, asserted over the SERIALIZED shape rather than over a
     * hand-listed set of properties: a future field that happened to carry the
     * buyer's email would fail here even if nobody thought to name it.
     */
    public function testTheLinkViewExcludesEveryIdentityAndSecretValue(): void
    {
        $view = $this->resolvedView();
        $serialized = json_encode($view->toArray(), JSON_THROW_ON_ERROR);

        foreach ([
            'buyer@example.com',
            '+233200000000',
            '12 Placeholder Road',
            'plsvcuser001',
            'a private operator note',
            self::ORDER,
            self::TENANT,
            self::ACTOR,
            'Example Store',
            // Per-line internals: the payer gets name + quantity, nothing else.
            'MUG-BLUE',
            'plsvcvar0001',
            '1237',
            // The storefront's own bearer credential for this order.
            str_repeat('c', 64),
        ] as $excluded) {
            self::assertStringNotContainsString($excluded, $serialized, "LinkView must not carry: {$excluded}");
        }

        foreach ([
            'token', 'hash', 'email', 'phone', 'address', 'user_uuid', 'note',
            'tenant', 'uuid', 'id', 'sku', 'variant_uuid', 'unit_price',
            'guest_token_hash',
        ] as $key) {
            self::assertArrayNotHasKey($key, $view->toArray());
        }
    }

    public function testTheAdminViewCarriesOnlyTheAllowedKeys(): void
    {
        $this->seedOrder(self::ORDER);
        $minted = $this->service()->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        $payload = $minted['link']->toArray();

        self::assertSame(
            ['expires_at', 'link_uuid', 'provider_session_issued', 'status'],
            $this->sortedKeys($payload)
        );
        self::assertStringNotContainsString($minted['rawToken'], json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(
            hash('sha256', $minted['rawToken']),
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }

    // =====================================================================
    // Raw-token egress ratchet
    // =====================================================================

    /**
     * The whole custody rule in one test: after a full mint/resolve/match/revoke
     * cycle the raw token appears in exactly two places -- `mint()`'s return and
     * `mintPublic()`'s URL -- and in NO table, NO audit payload, NO view and NO
     * exception message.
     */
    public function testTheRawTokenNeverReachesATableAnAuditRowOrAnyOtherOutput(): void
    {
        $this->seedOrder(self::ORDER);
        $provider = $this->urlProvider(static fn (string $token): string => "https://shop.example.com/pay/{$token}");
        $service = $this->service($provider);

        $minted = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        $token = $minted['rawToken'];
        $hash = hash('sha256', $token);

        $resolved = $service->resolveByToken($this->context, $token, $this->at('08:10:00'));
        self::assertInstanceOf(LinkView::class, $resolved);
        $matched = $service->matchCurrentToken($this->context, self::TENANT, self::ORDER, $token, $this->at('08:20:00'));
        self::assertInstanceOf(PaymentLinkAdminView::class, $matched);

        $published = $service->mintPublic($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:30:00'));
        self::assertNotNull($provider->seen);
        self::assertSame(1, substr_count($published['url'], $provider->seen), 'the URL embeds the token exactly once');
        self::assertArrayNotHasKey('rawToken', $published);
        self::assertArrayNotHasKey('token', $published);

        $service->revoke($this->context, self::TENANT, self::ORDER, self::ACTOR, $this->at('08:40:00'));

        foreach (['commerce_payment_links', 'commerce_order_events', 'commerce_orders', 'commerce_order_lines'] as $table) {
            $dump = json_encode($this->connection->table($table)->get(), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($token, $dump, "raw token leaked into {$table}");
            self::assertStringNotContainsString($provider->seen, $dump, "raw token leaked into {$table}");
        }

        // Every OTHER method's output is token-free (and hash-free).
        foreach ([
            'resolveByToken' => $resolved->toArray(),
            'matchCurrentToken' => $matched->toArray(),
            'mint.link' => $minted['link']->toArray(),
            'mintPublic.link' => $published['link']->toArray(),
        ] as $label => $payload) {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($token, $encoded, "{$label} leaked the raw token");
            self::assertStringNotContainsString($provider->seen, $encoded, "{$label} leaked the raw token");
            self::assertStringNotContainsString($hash, $encoded, "{$label} leaked the token hash");
        }
    }

    /**
     * Review round 1, Important 1: STACK FRAMES ARE AN EGRESS POINT.
     *
     * PHP records call arguments in exception backtraces unless
     * `zend.exception_ignore_args` is On, and the framework's handler writes
     * `getTraceAsString()` into the error log. So a throwable raised while a raw
     * token sits in a live frame's argument list leaks a bearer credential with
     * no application code involved.
     *
     * This test forces the ini setting to the DANGEROUS value (args recorded),
     * proves the harness is meaningful with a control frame that genuinely
     * leaks, and then asserts that none of the four throw paths through this
     * service put a token in a trace.
     *
     * @dataProvider throwPathsThatMustNotLeak
     */
    public function testNoThrowPathPutsARawTokenInAStackTrace(string $scenario): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            // CONTROL: with args recorded, a frame that keeps a token as a
            // parameter really does leak it. Without this the assertions below
            // could pass because the ini flag silently defeated the test.
            $control = self::leakyControlFrame(str_repeat('e', 64));
            self::assertStringContainsString(
                str_repeat('e', 15),
                $control,
                'the harness must be able to observe an argument leak at all'
            );

            [$token, $trace] = $this->{'traceFor' . ucfirst($scenario)}();

            self::assertNotSame('', $token);
            self::assertStringNotContainsString($token, $trace, "{$scenario} leaked the whole token");
            // The framework truncates trace arguments to 15 characters, so a
            // PREFIX is the realistic leak shape -- assert against that too.
            self::assertStringNotContainsString(
                substr($token, 0, 15),
                $trace,
                "{$scenario} leaked a token prefix"
            );
            // Nothing token-shaped at all, however it got there.
            self::assertDoesNotMatchRegularExpression('/[a-f0-9]{32,}/', $trace, "{$scenario} trace holds hex");
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /** @return array<string, array{string}> */
    public static function throwPathsThatMustNotLeak(): array
    {
        return [
            'mint refusal inside the transaction' => ['mintRefusal'],
            'resolve hitting a broken connection' => ['resolveFailure'],
            'stale token on matchCurrentToken' => ['staleMatch'],
            'invalid public url' => ['invalidPublicUrl'],
        ];
    }

    /** The deliberately-vulnerable shape, for the control assertion above. */
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

    /** @return array{0: string, 1: string} */
    private function traceForMintRefusal(): array
    {
        // A paid order refuses INSIDE persistMint's transaction. The token is
        // generated in mint()'s frame, so the test cannot see it -- which is why
        // this case asserts on the "no hex at all" rule.
        $this->seedOrder(self::ORDER, status: 'paid');

        try {
            $this->service()->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        } catch (PaymentLinkException $e) {
            return [str_repeat('f', 64), $e->getTraceAsString()];
        }

        self::fail('a paid order must refuse');
    }

    /** @return array{0: string, 1: string} */
    private function traceForResolveFailure(): array
    {
        $token = str_repeat('a', 64);

        try {
            $this->service()->resolveByToken($this->contextWithoutDatabase(), $token, $this->at('09:00:00'));
        } catch (\Throwable $e) {
            return [$token, $e->getTraceAsString()];
        }

        self::fail('a well-formed token must reach the (broken) database');
    }

    /** @return array{0: string, 1: string} */
    private function traceForStaleMatch(): array
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $stale = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('09:00:00'));

        try {
            $service->matchCurrentToken(
                $this->context,
                self::TENANT,
                self::ORDER,
                $stale['rawToken'],
                $this->at('09:30:00')
            );
        } catch (PaymentLinkException $e) {
            return [$stale['rawToken'], $e->getTraceAsString()];
        }

        self::fail('a stale token must refuse');
    }

    /** @return array{0: string, 1: string} */
    private function traceForInvalidPublicUrl(): array
    {
        $this->seedOrder(self::ORDER);
        $provider = $this->urlProvider(static fn (string $token): string => "http://shop.example.com/pay/{$token}");

        try {
            $this->service($provider)
                ->mintPublic($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        } catch (PaymentLinkException $e) {
            self::assertNotNull($provider->seen);

            return [$provider->seen, $e->getTraceAsString()];
        }

        self::fail('plain http must refuse');
    }

    /**
     * The structural half of Important 1: the mint transaction can never hold a
     * raw token, because the private method that OPENS it does not accept one.
     */
    public function testThePrivateMintPathAcceptsOnlyAHash(): void
    {
        $method = new \ReflectionMethod(PaymentLinkService::class, 'persistMint');
        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $method->getParameters()
        );

        self::assertContains('tokenHash', $names);
        foreach ($names as $name) {
            self::assertStringNotContainsStringIgnoringCase(
                'rawtoken',
                $name,
                'the mint transaction must never receive a raw token'
            );
        }
    }

    /**
     * The complement of the runtime ratchet: the SIGNATURE surface. A raw token
     * may only ever be an INPUT, and only on the three seams that genuinely
     * receive one from a caller -- `resolveByToken()`, `matchCurrentToken()`,
     * and Task 7's `initiateByToken()`. Every one of them hashes it and
     * overwrites the parameter with the redaction sentinel before any I/O.
     */
    public function testOnlyTheDocumentedMethodsSpeakOfARawTokenAtAll(): void
    {
        $reflection = new \ReflectionClass(PaymentLinkService::class);
        $seen = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                if (stripos($parameter->getName(), 'token') !== false) {
                    $seen[] = $method->getName();
                }
            }
        }
        sort($seen);

        self::assertSame(
            ['initiateByToken', 'matchCurrentToken', 'resolveByToken'],
            array_values(array_unique($seen))
        );
    }

    // =====================================================================
    // Audit trail
    // =====================================================================

    public function testMintAndRevokeRecordOrderEventsCarryingOnlyTheLinkUuid(): void
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();

        $first = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        $second = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('09:00:00'));
        $service->revoke($this->context, self::TENANT, self::ORDER, self::ACTOR, $this->at('10:00:00'));

        $events = $this->orders->eventsForOrder($this->context, self::TENANT, self::ORDER);
        $types = array_map(static fn (array $event): string => (string) $event['type'], $events);

        self::assertSame(
            [
                PaymentLinkEvents::MINTED,
                PaymentLinkEvents::REVOKED,
                PaymentLinkEvents::MINTED,
                PaymentLinkEvents::REVOKED,
            ],
            $types,
            'a regenerate revokes the prior link and both facts are audited'
        );

        foreach ($events as $event) {
            self::assertSame(self::ACTOR, $event['actor_uuid']);
            self::assertSame('internal', $event['visibility']);
            self::assertNotNull($event['created_at']);
            self::assertSame(['link_uuid'], array_keys((array) $event['payload']));
        }

        self::assertSame($first['link']->linkUuid, $events[0]['payload']['link_uuid']);
        self::assertSame($first['link']->linkUuid, $events[1]['payload']['link_uuid']);
        self::assertSame($second['link']->linkUuid, $events[2]['payload']['link_uuid']);
        self::assertSame($second['link']->linkUuid, $events[3]['payload']['link_uuid']);
    }

    // =====================================================================
    // The return-URL seam (contract + default binding only; Task 7 consumes it)
    // =====================================================================

    public function testTheReturnUrlSeamReceivesALinkUuidAndNeverARawToken(): void
    {
        $method = new \ReflectionMethod(PaymentLinkReturnUrlProvider::class, 'urlsFor');
        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $method->getParameters()
        );

        self::assertSame(['context', 'linkUuid'], $names);
        foreach ($names as $name) {
            self::assertStringNotContainsStringIgnoringCase('token', $name);
        }
    }

    public function testThePublicUrlSeamIsTheOnlyContractThatEverSeesARawToken(): void
    {
        $method = new \ReflectionMethod(PaymentLinkPublicUrlProvider::class, 'urlFor');
        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $method->getParameters()
        );

        self::assertSame(['context', 'rawToken'], $names);
    }

    // =====================================================================
    // DI wiring
    // =====================================================================

    public function testTheEngineBindsDefaultUnavailableProvidersAndTheService(): void
    {
        $services = CommerceServiceProvider::services();

        foreach ([
            PaymentLinkPublicUrlProvider::class => UnavailablePaymentLinkPublicUrlProvider::class,
            PaymentLinkReturnUrlProvider::class => UnavailablePaymentLinkReturnUrlProvider::class,
        ] as $id => $default) {
            self::assertIsArray($services[$id] ?? null, "Missing default binding: {$id}");
            self::assertSame($default, $services[$id]['class'] ?? null);
            self::assertTrue($services[$id]['shared']);
        }

        self::assertIsArray($services[PaymentLinkService::class] ?? null);
        self::assertTrue($services[PaymentLinkService::class]['shared']);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function service(?PaymentLinkPublicUrlProvider $publicUrls = null): PaymentLinkService
    {
        return $this->serviceFor(self::TENANT, $publicUrls);
    }

    private function serviceFor(string $tenant, ?PaymentLinkPublicUrlProvider $publicUrls = null): PaymentLinkService
    {
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
            $publicUrls
        );
    }

    /** @param callable(string): string $compose */
    private function urlProvider(callable $compose): object
    {
        return new class ($compose) implements PaymentLinkPublicUrlProvider {
            public ?string $seen = null;

            /** @param callable(string): string $compose */
            public function __construct(private $compose)
            {
            }

            public function urlFor(ApplicationContext $context, string $rawToken): ?string
            {
                $this->seen = $rawToken;

                return ($this->compose)($rawToken);
            }
        };
    }

    private function resolvedView(): LinkView
    {
        $this->seedOrder(self::ORDER);
        $service = $this->service();
        $minted = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'));
        $view = $service->resolveByToken($this->context, $minted['rawToken'], $this->at('09:00:00'));
        self::assertInstanceOf(LinkView::class, $view);

        return $view;
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
            'leading space' => ' ' . str_repeat('a', 63),
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

    /** @return list<array<string,mixed>> */
    private function allLinkRows(): array
    {
        return $this->connection->table('commerce_payment_links')->orderBy('id', 'ASC')->get();
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-11 ' . $time, new \DateTimeZone('UTC'));
    }

    private function at2(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }

    /**
     * The typed refusal assertion: the CLOSED discriminator is what callers
     * branch on, so it is asserted through the exception object rather than
     * through a message a later rewrite could change.
     *
     * @param callable(): mixed $operation
     */
    private function assertRefuses(string $errorCode, callable $operation): PaymentLinkException
    {
        try {
            $operation();
        } catch (PaymentLinkException $e) {
            self::assertSame($errorCode, $e->errorCode);

            return $e;
        }

        self::fail("expected a typed '{$errorCode}' refusal");
    }

    private function setOrderStatus(string $orderUuid, string $status): void
    {
        $this->connection->table('commerce_orders')
            ->where('uuid', '=', $orderUuid)
            ->update(['status' => $status]);
    }

    private function contextWithoutDatabase(): ApplicationContext
    {
        $container = new class implements ContainerInterface {
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
            'order_number' => 'ORD-PL-0001',
            'status' => $status,
            'origin' => $origin,
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'email' => 'buyer@example.com',
            'user_uuid' => 'plsvcuser001',
            'phone_display' => '+233200000000',
            'customer_name' => 'Example Store',
            'guest_token_hash' => str_repeat('c', 64),
            'currency' => 'USD',
            'subtotal' => 2474,
            'discount_total' => 100,
            'shipping_total' => 300,
            'tax_total' => 200,
            'grand_total' => 2874,
            'addresses' => json_encode(['shipping' => ['line1' => '12 Placeholder Road']], JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['note' => 'a private operator note'], JSON_THROW_ON_ERROR),
        ]);

        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => substr('l' . substr(md5($orderUuid), 0, 11), 0, 12),
            'order_uuid' => $orderUuid,
            'variant_uuid' => 'plsvcvar0001',
            'product_name' => 'Blue Mug',
            'sku' => 'MUG-BLUE',
            'option_values' => json_encode(['color' => 'blue'], JSON_THROW_ON_ERROR),
            // A distinctive unit price: it is EXCLUDED from LinkView, and a
            // round number would hide inside the totals that are included.
            'unit_price' => 1237,
            'quantity' => 2,
            'line_total' => 2474,
        ]);
    }
}
