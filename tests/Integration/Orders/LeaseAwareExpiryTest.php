<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\ExpiryService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkService;
use Glueful\Extensions\Commerce\Orders\PaymentSessionExposureGuard;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkReturnUrlProvider;

/**
 * Payment-links Task 8 (design spec §2.2): LEASE-AWARE expiry.
 *
 * `ExpiryService` gains prefilters for the two classes of link that matter --
 * an ACTIVE UNEXPIRED link and ANY link that has ever issued a provider session
 * -- but the prefilters are NEVER the authority. Inside each per-order
 * transaction the order is locked, reloaded, and put to
 * {@see PaymentSessionExposureGuard} before a single unit of stock moves.
 *
 * The two race tests here are the point of the whole design, and they are
 * constructed so that the PREFILTER CANNOT POSSIBLY HAVE SEEN the link: the
 * candidate query runs, the test then mints (or initiates) inside the sweep
 * window, and the order still survives. Each asserts the order WAS a candidate,
 * so a passing test cannot be explained by the prefilter quietly doing the work.
 */
final class LeaseAwareExpiryTest extends CommerceTestCase
{
    private const TENANT = '';
    private const ACTOR = 'expactor0001';
    private const CHECKOUT_URL = 'https://checkout.example.com/session/abc';

    private PaymentLinkRepository $links;
    private OrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links = new PaymentLinkRepository();
        $this->orders = new OrderRepository();
    }

    // =====================================================================
    // Prefilters
    // =====================================================================

    public function testAnOrderWithAnActiveUnexpiredLinkIsNotEvenACandidate(): void
    {
        $this->seedStaleOrder('expordr00001');
        $this->seedLink('explink00001', 'expordr00001', expiresAt: '2026-08-18 08:00:00');

        $seen = [];
        $expired = $this->service(candidates: $seen)->expireStale($this->context, $this->at('09:00:00'));

        self::assertSame(0, $expired);
        self::assertSame([], $seen, 'the active-unexpired prefilter must exclude it');
        self::assertSame('pending_payment', $this->statusOf('expordr00001'));
        self::assertSame(4, $this->stock());
    }

    /** @dataProvider everyLinkStatus */
    public function testAnOrderWithAnIssuedSessionIsNotACandidateWhateverTheLinkStatus(string $status): void
    {
        $this->seedStaleOrder('expordr00002');
        $this->seedLink(
            'explink00002',
            'expordr00002',
            // LAPSED, so only the issued-session prefilter can exclude it.
            expiresAt: '2026-08-11 08:30:00',
            status: $status,
            issuedAt: '2026-08-11 08:20:00'
        );

        $seen = [];
        $expired = $this->service(candidates: $seen)->expireStale($this->context, $this->at('09:00:00'));

        self::assertSame(0, $expired, $status);
        self::assertSame([], $seen, $status);
        self::assertSame('pending_payment', $this->statusOf('expordr00002'), $status);
        self::assertSame(4, $this->stock(), 'a blocked order never releases its reservation');
    }

    /** @return array<string, array{string}> */
    public static function everyLinkStatus(): array
    {
        return [
            'active' => [PaymentLinkRepository::STATUS_ACTIVE],
            'revoked' => [PaymentLinkRepository::STATUS_REVOKED],
            'expired' => [PaymentLinkRepository::STATUS_EXPIRED],
            'consumed' => [PaymentLinkRepository::STATUS_CONSUMED],
        ];
    }

    public function testAnUninitiatedExpiredLinkReturnsTheOrderToTheOrdinarySweep(): void
    {
        $this->seedStaleOrder('expordr00003');
        $this->seedLink('explink00003', 'expordr00003', expiresAt: '2026-08-11 08:30:00');

        $seen = [];
        $expired = $this->service(candidates: $seen)->expireStale($this->context, $this->at('09:00:00'));

        self::assertSame(1, $expired);
        self::assertSame(['expordr00003'], $seen);
        self::assertSame('canceled', $this->statusOf('expordr00003'));
        self::assertSame(6, $this->stock(), 'the ordinary sweep still releases stock');
    }

    public function testAnUninitiatedRevokedLinkReturnsTheOrderToTheOrdinarySweep(): void
    {
        $this->seedStaleOrder('expordr00004');
        $this->seedLink(
            'explink00004',
            'expordr00004',
            expiresAt: '2026-08-18 08:00:00',
            status: PaymentLinkRepository::STATUS_REVOKED
        );

        $expired = $this->service()->expireStale($this->context, $this->at('09:00:00'));

        self::assertSame(1, $expired);
        self::assertSame('canceled', $this->statusOf('expordr00004'));
    }

    // =====================================================================
    // The races the prefilter CANNOT close
    // =====================================================================

    /**
     * MINT vs SWEEP. The candidate query has already run and named this order.
     * A mint commits before the per-order transaction opens. The in-transaction
     * guard is the ONLY thing that can save the order -- and it does.
     */
    public function testAMintInsideTheSweepWindowSavesTheOrderThroughTheTransactionalRecheck(): void
    {
        $this->seedStaleOrder('expordr00005');

        $seen = [];
        $service = $this->service(candidates: $seen, afterCandidates: function () use (&$seen): void {
            // Proven a candidate BEFORE the link exists: the prefilter is, by
            // construction, insufficient here.
            self::assertSame(['expordr00005'], $seen);
            self::assertSame([], $this->allLinkRows());

            $this->paymentLinks()->mint(
                $this->context,
                self::TENANT,
                'expordr00005',
                null,
                self::ACTOR,
                $this->at('09:00:00')
            );
        });

        $expired = $service->expireStale($this->context, $this->at('09:00:00'));

        self::assertSame(0, $expired, 'the transactional recheck must refuse to cancel a freshly minted lease');
        self::assertSame('pending_payment', $this->statusOf('expordr00005'));
        self::assertSame(4, $this->stock(), 'no stock may move once the guard blocks');
    }

    /**
     * INITIATE vs SWEEP, at the TTL boundary -- the one moment where a link can
     * be admitted by the prefilter and still start a real checkout.
     *
     * The link lapses at exactly 09:00:00. The sweep's own clock reads 09:00:00,
     * so the exclusive `expires_at > now` prefilter admits the order. A payer
     * whose request began a second earlier is still inside the TTL, initiates,
     * and stamps `provider_session_issued_at`. Only the in-transaction guard can
     * see that stamp -- the candidate query already ran.
     */
    public function testAnInitiationInsideTheSweepWindowSavesTheOrderThroughTheTransactionalRecheck(): void
    {
        $this->seedStaleOrder('expordr00006');
        $token = null;

        $seen = [];
        $service = $this->service(candidates: $seen, afterCandidates: function () use (&$seen, &$token): void {
            self::assertSame(['expordr00006'], $seen, 'the order was admitted by the prefilter');
            self::assertNull($this->currentLinkRow()['provider_session_issued_at']);

            $this->paymentLinks()->initiateByToken($this->context, (string) $token, $this->at('08:59:59'));
        });

        $token = $this->paymentLinks()->mint(
            $this->context,
            self::TENANT,
            'expordr00006',
            null,
            self::ACTOR,
            $this->at('08:00:00')
        )['rawToken'];
        // The TTL boundary: still payable a second before the sweep's own clock,
        // already outside the sweep's exclusive `expires_at > now` prefilter.
        $this->connection->table('commerce_payment_links')
            ->where('order_uuid', '=', 'expordr00006')
            ->update(['expires_at' => '2026-08-11 09:00:00']);

        $expired = $service->expireStale($this->context, $this->at('09:00:00'));

        self::assertSame(0, $expired);
        self::assertSame('pending_payment', $this->statusOf('expordr00006'));
        self::assertNotNull($this->currentLinkRow()['provider_session_issued_at']);
        self::assertSame(4, $this->stock());
    }

    // =====================================================================
    // What must NOT change
    // =====================================================================

    /**
     * The storefront (non-admin-origin) 60-minute behaviour is byte-unchanged:
     * a storefront order can never have a payment link (mint refuses any origin
     * but `admin`), so the guard always allows and the sweep behaves exactly as
     * it did before this task.
     */
    public function testStorefrontOrdersStillExpireAtSixtyMinutesWithTheSameStockRelease(): void
    {
        $this->seedStaleOrder('expordr00007', origin: 'storefront', placedAt: '2026-08-11 07:59:59');

        $expired = $this->service()->expireStale($this->context, $this->at('09:00:00'));

        self::assertSame(1, $expired);
        self::assertSame('canceled', $this->statusOf('expordr00007'));
        self::assertSame(6, $this->stock());
        self::assertSame('release', (string) $this->connection->table('commerce_stock_movements')
            ->where('variant_uuid', '=', 'expvariant01')
            ->orderBy('id', 'DESC')
            ->first()['reason']);
    }

    public function testAStorefrontOrderInsideTheSixtyMinuteWindowIsStillUntouched(): void
    {
        $this->seedStaleOrder('expordr00008', origin: 'storefront', placedAt: '2026-08-11 08:30:00');

        self::assertSame(0, $this->service()->expireStale($this->context, $this->at('09:00:00')));
        self::assertSame('pending_payment', $this->statusOf('expordr00008'));
    }

    public function testTheSweepStillNeverTouchesADraft(): void
    {
        $this->seedStaleOrder('expordr00009', status: 'draft', placedAt: null);

        self::assertSame(0, $this->service()->expireStale($this->context, $this->at('09:00:00')));
        self::assertSame('draft', $this->statusOf('expordr00009'));
    }

    // =====================================================================
    // The swept TTL transition
    // =====================================================================

    public function testTheTickSweepsLapsedLinksIntoExpired(): void
    {
        $this->seedStaleOrder('expordr00010');
        $this->seedLink('explink00010', 'expordr00010', expiresAt: '2026-08-11 08:30:00');
        $this->seedLink('explink00011', 'expordr00010', expiresAt: '2026-08-18 08:00:00');

        $this->service()->expireStale($this->context, $this->at('09:00:00'));

        self::assertSame(PaymentLinkRepository::STATUS_EXPIRED, $this->linkStatus('explink00010'));
        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $this->linkStatus('explink00011'));
    }

    public function testTheSweepNeverResurrectsATerminalLink(): void
    {
        $this->seedStaleOrder('expordr00011');
        $this->seedLink(
            'explink00012',
            'expordr00011',
            expiresAt: '2026-08-11 08:30:00',
            status: PaymentLinkRepository::STATUS_CONSUMED
        );

        $this->service()->expireStale($this->context, $this->at('09:00:00'));

        self::assertSame(PaymentLinkRepository::STATUS_CONSUMED, $this->linkStatus('explink00012'));
    }

    public function testTheSweepOnlyExpiresThisTenantsLapsedLinks(): void
    {
        $this->seedStaleOrder('expordr00012');
        $this->seedLink('explink00013', 'expordr00012', expiresAt: '2026-08-11 08:30:00', tenant: 'othertenant1');

        $this->service()->expireStale($this->context, $this->at('09:00:00'));

        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $this->linkStatus('explink00013'));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * @param list<string> $candidates receives the candidate uuids the sweep selected
     * @param (callable(): void)|null $afterCandidates runs once, after the candidate
     *     query and BEFORE the first per-order transaction -- the sweep window
     */
    private function service(array &$candidates = [], ?callable $afterCandidates = null): ExpiryService
    {
        $collect = function (ApplicationContext $context, string $tenant, array $uuids) use (
            &$candidates,
            $afterCandidates
        ): void {
            $candidates = $uuids;
            if ($afterCandidates !== null) {
                $afterCandidates();
            }
        };

        return new ExpiryService(
            $this->orders,
            new StockRepository(),
            new SentinelTenantResolver(),
            null,
            null,
            new PaymentSessionExposureGuard($this->links, $this->orders),
            $this->links,
            $collect
        );
    }

    private function paymentLinks(): PaymentLinkService
    {
        return new PaymentLinkService(
            $this->orders,
            $this->links,
            new class implements CurrentTenantResolver {
                public function tenantUuid(ApplicationContext $context): string
                {
                    return LeaseAwareExpiryTest::tenant();
                }
            },
            null,
            new class implements PaymentCollector {
                public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
                {
                    return new PaymentInitiation('fakepsp', 'ok', ['checkout_url' => LeaseAwareExpiryTest::url()]);
                }
            },
            new class implements PaymentLinkReturnUrlProvider {
                /** @return array{return: string, cancel: string} */
                public function urlsFor(ApplicationContext $context, string $linkUuid): ?array
                {
                    return [
                        'return' => 'https://shop.example.com/checkout/pay/return/' . $linkUuid . '/sig',
                        'cancel' => 'https://shop.example.com/checkout/pay/cancel/' . $linkUuid . '/sig',
                    ];
                }
            }
        );
    }

    public static function tenant(): string
    {
        return self::TENANT;
    }

    public static function url(): string
    {
        return self::CHECKOUT_URL;
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-11 ' . $time, new \DateTimeZone('UTC'));
    }

    private function statusOf(string $orderUuid): string
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $orderUuid)->first();
        self::assertNotNull($row);

        return (string) $row['status'];
    }

    private function linkStatus(string $linkUuid): string
    {
        $row = $this->connection->table('commerce_payment_links')->where('uuid', '=', $linkUuid)->first();
        self::assertNotNull($row);

        return (string) $row['status'];
    }

    /** @return array<string,mixed> */
    private function currentLinkRow(): array
    {
        $rows = $this->allLinkRows();
        self::assertNotSame([], $rows);

        return $rows[count($rows) - 1];
    }

    /** @return list<array<string,mixed>> */
    private function allLinkRows(): array
    {
        return $this->connection->table('commerce_payment_links')->orderBy('id', 'ASC')->get();
    }

    private function stock(): int
    {
        return (new StockRepository())->quantity($this->context, self::TENANT, 'expvariant01');
    }

    private function seedStaleOrder(
        string $orderUuid,
        string $origin = 'admin',
        string $status = 'pending_payment',
        ?string $placedAt = '2026-08-11 07:00:00'
    ): void {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $orderUuid,
            'tenant_uuid' => self::TENANT,
            'order_number' => 'ORD-EXP-' . substr($orderUuid, -3),
            'status' => $status,
            'origin' => $origin,
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'email' => 'buyer@example.com',
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'placed_at' => $placedAt,
        ]);

        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => 'l' . substr($orderUuid, -11),
            'order_uuid' => $orderUuid,
            'variant_uuid' => 'expvariant01',
            'product_name' => 'Blue Mug',
            'sku' => 'MUG-BLUE',
            'unit_price' => 500,
            'quantity' => 2,
            'line_total' => 1000,
        ]);

        if ($this->connection->table('commerce_stock')->where('variant_uuid', '=', 'expvariant01')->first() === null) {
            $this->connection->table('commerce_stock')->insert([
                'tenant_uuid' => self::TENANT,
                'variant_uuid' => 'expvariant01',
                'quantity' => 4,
                'tracked' => 1,
            ]);
        }
    }

    private function seedLink(
        string $uuid,
        string $orderUuid,
        string $expiresAt,
        string $status = PaymentLinkRepository::STATUS_ACTIVE,
        ?string $issuedAt = null,
        string $tenant = self::TENANT
    ): void {
        $this->connection->table('commerce_payment_links')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'token_hash' => hash('sha256', $uuid),
            'status' => $status,
            'expires_at' => $expiresAt,
            'created_by' => self::ACTOR,
            'initiation_count' => 0,
            'provider_session_issued_at' => $issuedAt,
            'created_at' => '2026-08-11 08:00:00',
        ]);
    }
}
