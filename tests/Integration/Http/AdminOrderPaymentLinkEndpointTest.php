<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\PaymentLinkPublicUrlProvider;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderPaymentLinkController;
use Glueful\Extensions\Commerce\Orders\Events\PaymentLinkEvents;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkException;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkService;
use Glueful\Extensions\Commerce\Orders\PaymentSessionExposureDecision;
use Glueful\Extensions\Commerce\Orders\PaymentSessionExposureGuard;
use Glueful\Extensions\Commerce\Orders\UnavailablePaymentLinkPublicUrlProvider;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Payment-links Task 8 (design spec §2.2 "Catalog entries"): the ONE HTTP owner
 * of mint, revoke, and link status.
 *
 * Three properties are load-bearing and each has its own assertions below:
 *  1. `store()` calls {@see PaymentLinkService::mintPublic()}, never `mint()` --
 *     so no bare bearer token can ever reach a response body, and a host with no
 *     public-URL provider MINTS NOTHING;
 *  2. `show()` publishes state, expiry, and exposure -- never the token, never
 *     its hash;
 *  3. every unknown throwable becomes a BODILESS 500: a driver failure or the
 *     nested-transaction LogicException must not put engine internals on the
 *     wire.
 */
final class AdminOrderPaymentLinkEndpointTest extends CommerceTestCase
{
    private const TENANT = '';
    private const ORDER = 'plctlorder01';
    private const BASE = 'https://shop.example.com/checkout/pay/';

    public function testStoreMintsThroughTheOneTimePublicUrlAndNeverExposesABareToken(): void
    {
        $this->seedOrder();

        $response = $this->controller()->store($this->request([]), self::ORDER);
        $body = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['link', 'url'], $this->sortedKeys($body['data']));

        $row = $this->currentLinkRow();
        self::assertStringStartsWith(self::BASE, $body['data']['url']);
        // The URL's final segment is the token; the hash in the table is its
        // sha256, and nothing else in the payload repeats either.
        $token = basename((string) $body['data']['url']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
        self::assertSame(hash('sha256', $token), (string) $row['token_hash']);

        self::assertSame(
            ['expires_at', 'link_uuid', 'provider_session_issued', 'status'],
            $this->sortedKeys($body['data']['link'])
        );
        self::assertArrayNotHasKey('rawToken', $body['data']);
        self::assertArrayNotHasKey('token', $body['data']);
        self::assertStringNotContainsString($token, json_encode($body['data']['link'], JSON_THROW_ON_ERROR));
    }

    /**
     * The hard review gate: `store()` must reach `mintPublic()`, never `mint()`.
     *
     * Proven two ways, because this is the rule that keeps a bare bearer token
     * off the wire. BEHAVIOURALLY: only `mintPublic()` consults the public-URL
     * provider, so a provider that was called exactly once during `store()`
     * cannot be explained by a `mint()` call. STRUCTURALLY: the controller's own
     * source names `mintPublic(` and never `->mint(`, so a future refactor that
     * hand-composes a URL around `mint()`'s raw token fails here rather than in
     * production.
     */
    public function testStoreCallsMintPublicAndNeverTheRawTokenMint(): void
    {
        $this->seedOrder();
        $provider = $this->urlProvider();

        $this->controller($this->service($provider))->store($this->request([]), self::ORDER);

        self::assertSame(1, $provider->calls, 'only mintPublic() asks the public-URL provider');

        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Http/Admin/AdminOrderPaymentLinkController.php'
        );
        self::assertStringContainsString('mintPublic(', $source);
        self::assertStringNotContainsString('->mint(', $source);
    }

    public function testStoreHonoursAnExplicitTtlAndClampsAnAbsurdOne(): void
    {
        $this->seedOrder();

        $threeDays = (string) $this->json(
            $this->controller()->store($this->request(['ttl_days' => 3]), self::ORDER)
        )['data']['link']['expires_at'];

        // A regenerate: the second mint supersedes the first, so no cleanup is
        // needed and the TTL clamp is exercised on a live order.
        $clamped = (string) $this->json(
            $this->controller()->store($this->request(['ttl_days' => 9999]), self::ORDER)
        )['data']['link']['expires_at'];

        self::assertLessThan($clamped, $threeDays, 'the clamp must exceed an explicit 3 days');
        self::assertLessThan(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+31 days')->format('Y-m-d H:i:s'),
            $clamped,
            'TTL is clamped to 30 days',
        );
    }

    /** @dataProvider malformedTtls */
    public function testAMalformedTtlIsAValidationFailureAndMintsNothing(mixed $ttl): void
    {
        $this->seedOrder();

        $response = $this->controller()->store($this->request(['ttl_days' => $ttl]), self::ORDER);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->allLinkRows());
    }

    /** @return array<string, array{mixed}> */
    public static function malformedTtls(): array
    {
        return [
            'string' => ['soon'],
            'float' => [1.5],
            'array' => [[7]],
            'bool' => [true],
        ];
    }

    // =====================================================================
    // The no-provider case: NOTHING is minted
    // =====================================================================

    public function testStoreWithNoPublicUrlProviderMintsNothingAndAnswersUnavailable(): void
    {
        $this->seedOrder();

        $response = $this->controller($this->service(null))->store($this->request([]), self::ORDER);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            PaymentLinkException::PUBLIC_URL_UNAVAILABLE,
            $this->json($response)['error']['details']['reason']
        );
        self::assertSame([], $this->allLinkRows(), 'a misconfigured host must accumulate no orphan links');
    }

    public function testStoreWithTheEnginesOwnUnavailableProviderMintsNothing(): void
    {
        $this->seedOrder();

        $response = $this->controller($this->service(new UnavailablePaymentLinkPublicUrlProvider()))
            ->store($this->request([]), self::ORDER);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame([], $this->allLinkRows());
    }

    public function testStoreWithAProviderThatReturnsAnInvalidUrlMintsNothing(): void
    {
        $this->seedOrder();
        $service = $this->service($this->urlProvider(static fn (string $t): string => 'http://shop.example.com/pay/' . $t));

        $response = $this->controller($service)->store($this->request([]), self::ORDER);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame([], $this->allLinkRows());
    }

    // =====================================================================
    // Eligibility
    // =====================================================================

    public function testStoreRefusesAnUnknownOrderWithAFourOhFour(): void
    {
        $response = $this->controller()->store($this->request([]), 'plctlnosuch1');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(PaymentLinkException::ORDER_NOT_FOUND, $this->json($response)['error']['details']['reason']);
    }

    public function testStoreRefusesACrossTenantOrderWithTheSameFourOhFour(): void
    {
        $this->seedOrder(tenant: 'plctltenB002');

        self::assertSame(404, $this->controller()->store($this->request([]), self::ORDER)->getStatusCode());
    }

    public function testStoreRefusesAStorefrontOrder(): void
    {
        $this->seedOrder(origin: 'storefront');

        $response = $this->controller()->store($this->request([]), self::ORDER);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            PaymentLinkException::ORDER_NOT_ADMIN_ORIGIN,
            $this->json($response)['error']['details']['reason']
        );
        self::assertSame([], $this->allLinkRows());
    }

    /** Draft isolation: a draft has no payment link, and asking for one is an honest 409. */
    public function testStoreRefusesADraftOrder(): void
    {
        $this->seedOrder(status: 'draft');

        $response = $this->controller()->store($this->request([]), self::ORDER);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            PaymentLinkException::ORDER_NOT_PENDING_PAYMENT,
            $this->json($response)['error']['details']['reason']
        );
        self::assertSame([], $this->allLinkRows());
    }

    // =====================================================================
    // destroy()
    // =====================================================================

    public function testDestroyRevokesTheCurrentLinkAndIsIdempotent(): void
    {
        $this->seedOrder();
        $this->controller()->store($this->request([]), self::ORDER);

        $first = $this->controller()->destroy($this->request([]), self::ORDER);
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(PaymentLinkRepository::STATUS_REVOKED, (string) $this->currentLinkRow()['status']);

        $second = $this->controller()->destroy($this->request([]), self::ORDER);
        self::assertSame(200, $second->getStatusCode());

        self::assertSame(
            [PaymentLinkEvents::MINTED, PaymentLinkEvents::REVOKED],
            $this->eventTypes(),
            'a second revoke writes no second audit row',
        );
    }

    public function testDestroyRefusesAnUnknownOrderWithAFourOhFour(): void
    {
        self::assertSame(404, $this->controller()->destroy($this->request([]), 'plctlnosuch1')->getStatusCode());
    }

    // =====================================================================
    // show(): state, expiry, exposure -- never the token
    // =====================================================================

    public function testShowReturnsNoLinkForAnOrderThatNeverHadOne(): void
    {
        $this->seedOrder();

        $body = $this->json($this->controller()->show($this->request([]), self::ORDER));

        self::assertNull($body['data']['link']);
        self::assertSame(PaymentSessionExposureDecision::REASON_NONE, $body['data']['exposure']['reason']);
        self::assertFalse($body['data']['exposure']['blocks_automatic_cancellation']);
    }

    public function testShowPublishesStateExpiryAndExposureOnly(): void
    {
        $this->seedOrder();
        $url = (string) $this->json($this->controller()->store($this->request([]), self::ORDER))['data']['url'];
        $token = basename($url);

        $response = $this->controller()->show($this->request([]), self::ORDER);
        $body = $this->json($response);
        $raw = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['exposure', 'link'], $this->sortedKeys($body['data']));
        self::assertSame(
            ['expires_at', 'link_uuid', 'provider_session_issued', 'status'],
            $this->sortedKeys($body['data']['link'])
        );
        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $body['data']['link']['status']);
        self::assertFalse($body['data']['link']['provider_session_issued']);

        self::assertStringNotContainsString($token, $raw, 'a status read is not a second copy of the token');
        self::assertStringNotContainsString(hash('sha256', $token), $raw);
    }

    public function testShowReportsExposureOnceASessionHasBeenIssued(): void
    {
        $this->seedOrder();
        $this->controller()->store($this->request([]), self::ORDER);
        $this->connection->table('commerce_payment_links')
            ->where('order_uuid', '=', self::ORDER)
            ->update(['provider_session_issued_at' => '2026-08-11 08:20:00']);

        $body = $this->json($this->controller()->show($this->request([]), self::ORDER));

        self::assertTrue($body['data']['link']['provider_session_issued']);
        self::assertSame(
            PaymentSessionExposureDecision::REASON_SESSION_EXPOSED,
            $body['data']['exposure']['reason']
        );
        self::assertTrue($body['data']['exposure']['requires_risk_acknowledgement']);
    }

    public function testShowAppliesTheLazyExpiryTransitionSoTheOperatorSeesTheCustomersTruth(): void
    {
        $this->seedOrder();
        $this->controller()->store($this->request([]), self::ORDER);
        $this->connection->table('commerce_payment_links')
            ->where('order_uuid', '=', self::ORDER)
            ->update(['expires_at' => '2020-01-01 00:00:00']);

        $body = $this->json($this->controller()->show($this->request([]), self::ORDER));

        self::assertSame(PaymentLinkRepository::STATUS_EXPIRED, $body['data']['link']['status']);
        self::assertSame(PaymentLinkRepository::STATUS_EXPIRED, (string) $this->currentLinkRow()['status']);
    }

    public function testShowRefusesAnUnknownOrderWithAFourOhFour(): void
    {
        self::assertSame(404, $this->controller()->show($this->request([]), 'plctlnosuch1')->getStatusCode());
    }

    /**
     * DELIBERATE ASYMMETRY (fix round 1, minor 6): `store()` refuses a draft
     * with 409 while `show()` answers it normally. Both are true -- "you may not
     * mint a link for a draft" and "this draft has no link" -- and drafts are
     * link-less BY CONSTRUCTION, so `{link: null, exposure: none}` is the only
     * state a draft can be in rather than a guess. Pinned so the disagreement
     * stays contract rather than accident.
     */
    public function testShowOnADraftIsAnHonestEmptyStateRatherThanTheStoreConflict(): void
    {
        $this->seedOrder(status: 'draft');

        $response = $this->controller()->show($this->request([]), self::ORDER);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($body['data']['link']);
        self::assertSame(PaymentSessionExposureDecision::REASON_NONE, $body['data']['exposure']['reason']);
        self::assertFalse($body['data']['exposure']['blocks_automatic_cancellation']);

        // ... while minting for the same row is still the honest 409.
        self::assertSame(409, $this->controller()->store($this->request([]), self::ORDER)->getStatusCode());
    }

    /**
     * LOCK-FREE STATUS READS (fix round 1, Important 1).
     *
     * A status poll must never take `FOR UPDATE` on the order or link rows: an
     * operator page may run it on every refresh, and those are exactly the rows
     * initiation Phase A/B and mint/revoke serialize on -- a read that
     * authorizes nothing must not be able to stall a payment.
     *
     * Pinned at the CALL SURFACE by reading the bodies of the two methods `show`
     * reaches, because the locking is invisible from outside on the default
     * SQLite lane (`PaymentLinkRepository::lockClause()` emits `FOR UPDATE` only
     * for pgsql/mysql, so a SQL-text assertion would pass vacuously here), and
     * both repositories are `final`, so a recording double is not available. The
     * method bodies are the honest place to assert it: `*ForUpdate(` absent,
     * the non-locking variants present, no transaction opened.
     */
    public function testTheStatusReadPathTakesNoRowLocksAndOpensNoTransaction(): void
    {
        $currentLink = $this->methodBody(PaymentLinkService::class, 'currentLink');

        self::assertStringNotContainsString('ForUpdate(', $currentLink, 'a status poll must not lock rows');
        self::assertStringNotContainsString('transaction(', $currentLink);
        self::assertStringContainsString('findByUuid(', $currentLink);
        self::assertStringContainsString('findActiveForOrder(', $currentLink);

        // The guard's own read is the third leg of the same path.
        $decide = $this->methodBody(PaymentSessionExposureGuard::class, 'decide');
        self::assertStringNotContainsString('ForUpdate(', $decide);
        self::assertStringNotContainsString('transaction(', $decide);

        // And the controller action itself reads the order without a lock.
        $show = $this->methodBody(AdminOrderPaymentLinkController::class, 'show');
        self::assertStringNotContainsString('ForUpdate(', $show);
        self::assertStringContainsString('findByUuid(', $show);
    }

    // =====================================================================
    // The unknown-throwable contract
    // =====================================================================

    /**
     * An INFRASTRUCTURE failure -- here a context whose container has no
     * database at all, the same harness `PaymentLinkServiceTest` uses to prove
     * token-free frames -- must become a bodiless 500 on every action. No
     * message, no class name, no driver text.
     *
     * @dataProvider everyAction
     */
    public function testAnUnknownThrowableBecomesABodilessFiveHundred(string $action): void
    {
        $broken = $this->contextWithoutDatabase();
        $controller = new AdminOrderPaymentLinkController(
            $broken,
            $this->service($this->urlProvider()),
            new OrderRepository(),
            new SentinelTenantResolver(),
            new PaymentSessionExposureGuard(new PaymentLinkRepository(), new OrderRepository())
        );

        $response = $controller->{$action}($this->request([]), self::ORDER);

        self::assertSame(500, $response->getStatusCode());
        $raw = (string) $response->getContent();
        self::assertSame('{}', $raw);
        self::assertStringNotContainsString('database', $raw, 'exception text must never reach the wire');
        self::assertStringNotContainsString('Exception', $raw);
    }

    /** @return array<string, array{string}> */
    public static function everyAction(): array
    {
        return [
            'store' => ['store'],
            'show' => ['show'],
            'destroy' => ['destroy'],
        ];
    }

    /**
     * The nested-transaction guard
     * ({@see \Glueful\Extensions\Commerce\Orders\NestedInitiationTransactionException})
     * is a `LogicException`, not a typed refusal -- a CALLER BUG that must be
     * indistinguishable from any other unknown throwable on the wire. This
     * controller never calls `initiateByToken()` (initiation is the host's
     * unauthenticated surface), so the property is pinned structurally: the
     * refusal handler's catch-all is `\Throwable`, which subsumes every
     * `LogicException`, and no action here opens a transaction around a service
     * call.
     */
    public function testTheRefusalHandlerCatchesEveryThrowableAndOpensNoTransaction(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Http/Admin/AdminOrderPaymentLinkController.php'
        );

        self::assertStringContainsString('catch (\Throwable)', $source);
        self::assertStringNotContainsString('->transaction(', $source, 'no action may wrap a service call');
        self::assertStringNotContainsString('->initiateByToken(', $source, 'initiation is the host payer surface');
    }

    public function testEveryTypedRefusalCodeHasAnExplicitHttpStatus(): void
    {
        $mapped = AdminOrderPaymentLinkController::STATUS_BY_ERROR_CODE;

        foreach (PaymentLinkException::ERROR_CODES as $code) {
            self::assertArrayHasKey($code, $mapped, "unmapped payment-link error code: {$code}");
            self::assertGreaterThanOrEqual(400, $mapped[$code]);
        }
        self::assertSame([], array_diff(array_keys($mapped), PaymentLinkException::ERROR_CODES));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function controller(?PaymentLinkService $links = null): AdminOrderPaymentLinkController
    {
        return new AdminOrderPaymentLinkController(
            $this->context,
            $links ?? $this->service($this->urlProvider()),
            new OrderRepository(),
            new SentinelTenantResolver(),
            new PaymentSessionExposureGuard(new PaymentLinkRepository(), new OrderRepository())
        );
    }

    private function service(?PaymentLinkPublicUrlProvider $urls): PaymentLinkService
    {
        return new PaymentLinkService(
            new OrderRepository(),
            new PaymentLinkRepository(),
            new SentinelTenantResolver(),
            $urls
        );
    }

    /** @param (callable(string): ?string)|null $compose */
    private function urlProvider(?callable $compose = null): PaymentLinkPublicUrlProvider
    {
        $compose ??= static fn (string $token): string => self::BASE . $token;

        return new class ($compose) implements PaymentLinkPublicUrlProvider {
            public int $calls = 0;

            /** @param callable(string): ?string $compose */
            public function __construct(private $compose)
            {
            }

            public function urlFor(ApplicationContext $context, string $rawToken): ?string
            {
                $this->calls++;

                return ($this->compose)($rawToken);
            }
        };
    }

    /** The source of ONE method, so a ratchet pins that method rather than a whole file. */
    private function methodBody(string $class, string $method): string
    {
        $reflected = new \ReflectionMethod($class, $method);
        $file = (string) $reflected->getFileName();
        $lines = (array) file($file);
        $start = (int) $reflected->getStartLine() - 1;

        return implode('', array_slice($lines, $start, (int) $reflected->getEndLine() - $start));
    }

    /** The no-database harness: every db() call inside becomes an infrastructure throwable. */
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
        $context->mergeConfigDefaults('commerce', require dirname(__DIR__, 3) . '/config/commerce.php');

        return $context;
    }

    /** @param array<string,mixed> $body */
    private function request(array $body): Request
    {
        return Request::create(
            '/commerce/admin/orders/' . self::ORDER . '/payment-link',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private function eventTypes(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['type'],
            (new OrderRepository())->eventsForOrder($this->context, self::TENANT, self::ORDER)
        );
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

    private function seedOrder(
        string $tenant = self::TENANT,
        string $origin = 'admin',
        string $status = 'pending_payment'
    ): void {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => self::ORDER,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-PLCTL-1',
            'status' => $status,
            'origin' => $origin,
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'placed_at' => '2026-08-11 08:00:00',
        ]);
    }
}
