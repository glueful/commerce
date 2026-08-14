<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Extensions\Commerce\Http\Admin\AdminOrderArtifactController;
use Glueful\Extensions\Commerce\Orders\DraftCleanupService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Cleanup-train Task 5: `DELETE /orders/{uuid}/artifact` -- the ONE HTTP owner
 * of draft-artifact deletion.
 *
 * The refusal matrix is the whole contract, and it has exactly three outcomes:
 *  - 200 for a tenant-scoped row with `order_number IS NULL` AND
 *    `status = 'canceled'` -- structurally proven never to have touched money;
 *  - typed 409 `order_not_deletable` for ANY other row the tenant owns,
 *    including an ACTIVE draft (cancel it first) and every numbered order;
 *  - a NON-REVEALING 404 for unknown and cross-tenant uuids alike, which must be
 *    byte-indistinguishable from each other.
 */
final class AdminOrderArtifactEndpointTest extends CommerceTestCase
{
    private const TENANT = '';
    private const ARTIFACT = 'artctlordr1';

    public function testDeletingAnArtifactAnswers200AndRemovesTheRowAndItsChildren(): void
    {
        $this->seedArtifact();
        $this->seedLine(self::ARTIFACT, 'artctlline1');
        $this->seedEvent(self::ARTIFACT, 'draft_canceled');

        $response = $this->controller()->destroy($this->request(), self::ARTIFACT);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['order_uuid'], array_keys($body['data']));
        self::assertSame(self::ARTIFACT, $body['data']['order_uuid']);

        self::assertNull($this->rowOf(self::ARTIFACT));
        self::assertSame(0, $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', self::ARTIFACT)->count());
        self::assertSame(0, $this->connection->table('commerce_order_events')
            ->where('order_uuid', '=', self::ARTIFACT)->count());
    }

    /**
     * An ACTIVE draft is the case the draft-INCLUSIVE lookup exists for. With
     * the ordinary draft-BLIND lookup every sibling admin order endpoint uses, a
     * draft uuid would answer the non-revealing 404 -- which would be a lie about
     * deletability and would leave "cancel it, then delete it" undiscoverable.
     * It is a typed 409 instead.
     *
     * @dataProvider undeletableRows
     * @param array<string,mixed> $overrides
     */
    public function testEveryNonArtifactRowIsATyped409(array $overrides, string $expectedStatus): void
    {
        $this->seedArtifact($overrides);
        $this->seedLine(self::ARTIFACT, 'artctlline2');

        $response = $this->controller()->destroy($this->request(), self::ARTIFACT);
        $body = $this->json($response);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            AdminOrderArtifactController::REASON_NOT_DELETABLE,
            $body['error']['details']['reason'] ?? null
        );
        self::assertSame($expectedStatus, $body['error']['details']['status'] ?? null);

        self::assertNotNull($this->rowOf(self::ARTIFACT), 'a refused delete writes nothing');
        self::assertSame(1, $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', self::ARTIFACT)->count());
    }

    /** @return array<string,array{0: array<string,mixed>, 1: string}> */
    public static function undeletableRows(): array
    {
        return [
            'an active draft' => [['status' => 'draft'], 'draft'],
            'a canceled order that has a number' => [['order_number' => 'ORD-000200'], 'canceled'],
            'an unpaid order' => [
                ['status' => 'pending_payment', 'order_number' => 'ORD-000201'],
                'pending_payment',
            ],
            'a paid order' => [['status' => 'paid', 'order_number' => 'ORD-000202'], 'paid'],
            'a refunded order' => [['status' => 'refunded', 'order_number' => 'ORD-000203'], 'refunded'],
        ];
    }

    /**
     * Containment: an unknown uuid and a uuid belonging to somebody else produce
     * the IDENTICAL exception -- same class, same message -- so this endpoint can
     * never be used to enumerate another tenant's order uuids.
     */
    public function testUnknownAndCrossTenantUuidsAreIndistinguishable(): void
    {
        $this->seedArtifact(['tenant_uuid' => 'othertenant1']);

        $unknown = $this->captureNotFound('artctlnope1');
        $crossTenant = $this->captureNotFound(self::ARTIFACT);

        self::assertSame($unknown[0], $crossTenant[0]);
        self::assertSame($unknown[1], $crossTenant[1]);

        self::assertNotNull($this->rowOf(self::ARTIFACT), "another tenant's row is never touched");
    }

    /**
     * The precheck is a COURTESY, never the authority -- pinned structurally so
     * a future refactor cannot quietly promote it. `destroy()` must call the
     * guarded delete and branch on what it reports, and it must RE-READ before
     * answering a lost one; a version that trusted its own precheck and returned
     * 200 unconditionally would fail here.
     *
     * The two lost-CAS branches themselves (a row that vanished -> 404, a row
     * that stopped being an artifact -> 409) are proven END TO END through this
     * controller against a genuinely concurrent second OS process in
     * `Orders\ArtifactDeleteRacePgsqlTest`. They are deliberately NOT simulated
     * here: every seam that could stage the interleaving on SQLite (a double of
     * the final `DraftCleanupService`, a divergence between the PHP and SQL
     * forms of the guard) would prove something other than the production path.
     */
    public function testTheEndpointBranchesOnTheGuardedDeleteAndReReadsWhenItLoses(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Http/Admin/AdminOrderArtifactController.php'
        );

        self::assertStringContainsString('->deleteArtifact(', $source);
        self::assertSame(
            2,
            substr_count($source, '->findByUuid('),
            'destroy() must read once to classify and once more to re-classify a lost CAS'
        );
        self::assertStringContainsString('includeDrafts: true', $source, 'the lookup is draft-INCLUSIVE');
    }

    public function testTheActorReachesTheDeletionLogLine(): void
    {
        $this->seedArtifact();

        $captured = [];
        $controller = $this->controller(null, $captured);
        $controller->destroy($this->requestAs('operator0001'), self::ARTIFACT);

        self::assertCount(1, $captured);
        self::assertSame('operator0001', $captured[0]['actor_uuid'] ?? null);
        self::assertSame(self::ARTIFACT, $captured[0]['order_uuid'] ?? null);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $captured */
    private function controller(
        ?DraftCleanupService $cleanup = null,
        array &$captured = []
    ): AdminOrderArtifactController {
        $logger = new class ($captured) extends \Psr\Log\AbstractLogger {
            /** @param array<int,array<string,mixed>> $captured */
            public function __construct(private array &$captured)
            {
            }

            /**
             * @param mixed $level
             * @param string|\Stringable $message
             * @param array<string,mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->captured[] = $context;
            }
        };

        return new AdminOrderArtifactController(
            $this->context,
            new OrderRepository(),
            $cleanup ?? new DraftCleanupService(new OrderRepository(), new SentinelTenantResolver(), $logger),
            new SentinelTenantResolver()
        );
    }

    /** @return array{0: string, 1: string} */
    private function captureNotFound(string $uuid): array
    {
        try {
            $this->controller()->destroy($this->request(), $uuid);
        } catch (NotFoundException $e) {
            return [$e::class, $e->getMessage()];
        }

        self::fail("expected a non-revealing 404 for {$uuid}");
    }

    private function request(): Request
    {
        return Request::create('/commerce/admin/orders/' . self::ARTIFACT . '/artifact', 'DELETE');
    }

    private function requestAs(string $actorUuid): Request
    {
        $request = $this->request();
        $request->attributes->set('auth.user', new \Glueful\Auth\UserIdentity(uuid: $actorUuid));

        return $request;
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string,mixed> $overrides */
    private function seedArtifact(array $overrides = []): void
    {
        $this->connection->table('commerce_orders')->insert($overrides + [
            'uuid' => self::ARTIFACT,
            'tenant_uuid' => self::TENANT,
            'order_number' => null,
            'status' => 'canceled',
            'email' => null,
            'guest_token_hash' => null,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'origin' => 'admin',
            'fulfillment_mode' => 'in_store',
        ]);
    }

    private function seedLine(string $orderUuid, string $lineUuid): void
    {
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => $lineUuid,
            'order_uuid' => $orderUuid,
            'variant_uuid' => 'variant00001',
            'product_name' => 'Widget',
            'sku' => 'WIDGET-1',
            'option_values' => '[]',
            'unit_price' => 1000,
            'quantity' => 1,
            'line_total' => 1000,
        ]);
    }

    private function seedEvent(string $orderUuid, string $type): void
    {
        $this->connection->table('commerce_order_events')->insert([
            'uuid' => substr(md5($orderUuid . $type), 0, 12),
            'order_uuid' => $orderUuid,
            'type' => $type,
            'payload' => null,
            'actor_uuid' => null,
            'visibility' => 'internal',
        ]);
    }

    /** @return array<string,mixed>|null */
    private function rowOf(string $uuid): ?array
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $uuid)->first();

        return is_array($row) ? $row : null;
    }
}
