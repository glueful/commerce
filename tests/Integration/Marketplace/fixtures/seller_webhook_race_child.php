<?php

declare(strict_types=1);

/**
 * Standalone subprocess for `SellerWebhookPgsqlTest`'s real-pgsql race lanes
 * (MV5c-2 Task 8): runs ONE real seller-webhook service call in a genuinely
 * separate OS process (and therefore a genuinely separate database
 * connection), so its lock claims really block on PostgreSQL row-lock
 * contention held by the parent test process's own connection A. A
 * dedicated sibling of `fixtures/marketplace_race_child.php` (rather than an
 * extension of it) because the webhook actions below need their OWN
 * encryption-key + SSRF-resolver + mock-HTTP-client bootstrap that no other
 * action in the shared fixture needs.
 *
 * argv: 1=pgConfig JSON, 2=action, 3=args JSON
 * actions: suspend | webhookDisable | webhookEnable | webhookRotateSecret |
 *     webhookDeliver | webhookReclaimExpired | webhookCapture |
 *     webhookStaleFinalize
 * stdout: JSON, shape depends on action (see each branch below)
 *
 * **Shared encryption key (design spec §2.2):** both this child AND the
 * parent test process's own `pgsqlContext()` override `encryption.key` to
 * the SAME fixed 32-byte value ({@see self::FIXED_ENCRYPTION_KEY}) -- a
 * secret minted by ONE process's `SellerWebhookSecretService::mint()` must
 * be decryptable by the OTHER, since both share the SAME real PostgreSQL
 * rows. Mirrors `CommerceRouterTestCase::webhookEncryptionService()`'s
 * identical fixed-key convention.
 *
 * **SSRF resolver (design spec §2.6):** every action here builds a
 * `SafeOutboundTargetResolver` with a FAKE DNS-lookup closure that resolves
 * every host to the single genuinely public, non-reserved address `1.1.1.1`
 * -- no real DNS/network I/O ever happens; `webhookDeliver`'s own HTTP layer
 * is ADDITIONALLY a `MockHttpClient` (never a real socket), so the whole
 * chain is fully network-free and deterministic.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookException;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookOutboxPublisher;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookPayloadProjector;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookSecretService;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Encryption\EncryptionService;
use Glueful\Http\Client;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Security\SafeOutboundTargetResolver;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// Mirrors `CommerceRouterTestCase::webhookEncryptionService()`'s identical
// fixed-key convention -- this exact value MUST also be what the parent test
// process's own `pgsqlContext()` overrides `encryption.key` to (see
// `SellerWebhookPgsqlTest::FIXED_ENCRYPTION_KEY`), since a secret minted by
// one process must be decryptable by the other.
define('FIXED_ENCRYPTION_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));

[, $pgConfigJson, $action, $argsJson] = $argv;
/** @var array<string,mixed> $pgConfig */
$pgConfig = json_decode($pgConfigJson, true, 512, JSON_THROW_ON_ERROR);
/** @var array<string,mixed> $args */
$args = json_decode($argsJson, true, 512, JSON_THROW_ON_ERROR);

$connection = new Connection($pgConfig);

$container = new class ($connection) implements ContainerInterface {
    public function __construct(private Connection $connection)
    {
    }

    public function get(string $id): mixed
    {
        if ($id === 'database' || $id === Connection::class) {
            return $this->connection;
        }

        throw new \RuntimeException("Unknown service: {$id}");
    }

    public function has(string $id): bool
    {
        return $id === 'database' || $id === Connection::class;
    }
};

$context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
$context->setContainer($container);
$context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../../config/commerce.php');
$context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
$context->overrideConfig('encryption.key', FIXED_ENCRYPTION_KEY);

$tenant = (string) $args['tenant'];

function resolver(): SafeOutboundTargetResolver
{
    return new SafeOutboundTargetResolver(static fn (string $host): array => ['1.1.1.1']);
}

/**
 * Symfony's `HttpClientInterface` hands the mock callback a NORMALIZED
 * `headers` option -- a plain LIST of `"Name: Value"` strings (never an
 * associative `name => value` map, regardless of how the caller originally
 * passed them) -- so extracting one header back out means scanning that
 * list for its `Name:` prefix, not indexing into it.
 *
 * @param list<string> $headers
 */
function findCapturedHeader(array $headers, string $name): ?string
{
    $prefix = strtolower($name) . ':';
    foreach ($headers as $line) {
        if (str_starts_with(strtolower($line), $prefix)) {
            return trim(substr($line, strlen($prefix)));
        }
    }

    return null;
}

function endpointService(ApplicationContext $context): SellerWebhookEndpointService
{
    $endpoints = new SellerWebhookEndpointRepository();
    $secrets = new SellerWebhookSecretService($endpoints, new EncryptionService($context));

    return new SellerWebhookEndpointService(
        new SellerRepository(),
        new SellerMembershipRepository(),
        $endpoints,
        new SellerWebhookDeliveryRepository(),
        new FixedSellerRoleAuthority(),
        $secrets,
        resolver()
    );
}

/**
 * @param array{status_code: int} $captured `status_code` (set by the caller
 *     BEFORE this is invoked) is the HTTP status the MockHttpClient hands
 *     back for every request; `method`/`url`/`headers`/`body` are filled in
 *     by this function's own mock callback as a side effect.
 */
function deliveryService(ApplicationContext $context, array &$captured): SellerWebhookDeliveryService
{
    $endpoints = new SellerWebhookEndpointRepository();
    $secrets = new SellerWebhookSecretService($endpoints, new EncryptionService($context));
    $httpClient = new Client(
        new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured['method'] = $method;
            $captured['url'] = $url;
            $captured['headers'] = $options['headers'] ?? [];
            $captured['body'] = $options['body'] ?? '';

            return new MockResponse('', ['http_code' => $captured['status_code']]);
        }),
        new NullLogger(),
        $context,
        resolver()
    );

    return new SellerWebhookDeliveryService(
        new SellerRepository(),
        $endpoints,
        new SellerWebhookDeliveryRepository(),
        new SellerWebhookEventRepository(),
        $secrets,
        $httpClient
    );
}

$out = [];

try {
    switch ($action) {
        // Mirrors marketplace_race_child.php's identical `suspend` action --
        // the REAL SellerService::suspend(), claiming the seller revision
        // FIRST, the SAME primitive every webhook mutation below claims for
        // the identical seller row.
        case 'suspend':
            $sellers = new SellerService(
                new SellerRepository(),
                new SellerMembershipRepository(),
                new SellerLifecycleEventRepository()
            );
            $seller = $sellers->suspend(
                $context,
                $tenant,
                (string) $args['sellerUuid'],
                (string) $args['reason'],
                (string) $args['actor']
            );
            $out = ['ok' => true, 'status' => $seller['status'], 'exceptionClass' => null];
            break;

        case 'webhookDisable':
            $result = endpointService($context)->disable(
                $context,
                $tenant,
                (string) $args['sellerUuid'],
                (string) $args['endpointUuid'],
                (string) $args['actor'],
                $args['reason'] ?? null
            );
            $out = ['ok' => true, 'status' => $result['endpoint']['status'], 'exceptionClass' => null];
            break;

        case 'webhookEnable':
            $result = endpointService($context)->enable(
                $context,
                $tenant,
                (string) $args['sellerUuid'],
                (string) $args['endpointUuid'],
                (string) $args['actor']
            );
            $out = [
                'ok' => true,
                'status' => $result['endpoint']['status'],
                'consecutiveFailures' => (int) $result['endpoint']['consecutive_failures'],
                'exceptionClass' => null,
            ];
            break;

        case 'webhookRotateSecret':
            $result = endpointService($context)->rotateSecret(
                $context,
                $tenant,
                (string) $args['sellerUuid'],
                (string) $args['endpointUuid'],
                (string) $args['actor']
            );
            $out = [
                'ok' => true,
                'secret' => $result['secret'],
                'exceptionClass' => null,
            ];
            break;

        case 'webhookDeliver':
            $captured = ['status_code' => (int) ($args['statusCode'] ?? 200)];
            $service = deliveryService($context, $captured);
            $outcome = $service->deliver($context, $tenant, (string) $args['deliveryUuid']);
            $out = [
                'ok' => true,
                'outcome' => $outcome,
                'signatureHeader' => findCapturedHeader($captured['headers'] ?? [], 'X-Webhook-Signature'),
                'body' => $captured['body'] ?? null,
                'exceptionClass' => null,
            ];
            break;

        case 'webhookReclaimExpired':
            $captured = ['status_code' => 200];
            $service = deliveryService($context, $captured);
            $outcome = $service->reclaimExpired($context, $tenant, (string) $args['deliveryUuid']);
            $out = ['ok' => true, 'outcome' => $outcome, 'exceptionClass' => null];
            break;

        // The REAL SellerWebhookOutboxPublisher::capture() (design spec
        // §2.4), invoked directly (capture() does NOT wrap itself in a
        // transaction -- it expects the caller to already be inside one, per
        // its own docblock, mirroring `SellerWebhookOutboxTest::captureDirectly()`).
        // Claims the seller revision FIRST, the SAME primitive `suspend()`
        // claims for the identical seller row.
        case 'webhookCapture':
            $publisher = new SellerWebhookOutboxPublisher(
                new MarketplaceMode(),
                new SellerRepository(),
                new SellerWebhookEndpointRepository(),
                new SellerWebhookEventRepository(),
                new SellerWebhookDeliveryRepository(),
                new SellerWebhookPayloadProjector()
            );
            $sellerUuid = (string) $args['sellerUuid'];
            $slice = [
                'order_uuid' => (string) ($args['orderUuid'] ?? 'raceOrderWH1'),
                'order_number' => 'ORD-RACE-WH-1',
                'currency' => 'USD',
                'occurred_at' => gmdate('Y-m-d H:i:s'),
                'seller_order_uuid' => 'selRaceWH01',
                'seller_reference' => 'ORD-1',
                'subtotal' => 1000,
                'allocated_discount' => 0,
                'allocated_shipping' => 0,
                'allocated_tax' => 0,
                'attributed_total' => 1000,
                'commission_amount' => 0,
                'lines' => [],
            ];
            db($context)->transaction(function () use ($publisher, $context, $tenant, $sellerUuid, $slice): void {
                $publisher->capture($context, $tenant, 'order.placed', [
                    'data' => [$sellerUuid => $slice],
                ]);
            });

            $delivery = $connection->table('commerce_seller_webhook_deliveries')
                ->where('tenant_uuid', '=', $tenant)
                ->where('seller_uuid', '=', $sellerUuid)
                ->orderBy('id', 'DESC')
                ->first();
            $out = [
                'ok' => true,
                'exceptionClass' => null,
                'deliveryStatus' => $delivery['status'] ?? null,
                'pauseReason' => $delivery['pause_reason'] ?? null,
            ];
            break;

        // A faithful, standalone replica of the PRIVATE
        // `SellerWebhookDeliveryService::finalize()`'s own critical section
        // (design spec §2.7/§2.9): finalize() has no public entry point a
        // subprocess could call "for real" on an ALREADY-claimed delivery
        // (the public `deliver()` always starts its OWN fresh claim from
        // `status=pending`, which this row is not) -- this action calls the
        // EXACT SAME repository primitives
        // ({@see SellerRepository::claimRevision()},
        // {@see SellerWebhookEndpointRepository::claimRevision()} (the
        // PERMISSIVE variant), {@see SellerWebhookDeliveryRepository::finalize()})
        // that method itself calls, so the genuine two-connection row-lock
        // contention this whole file proves is byte-identical either way.
        case 'webhookStaleFinalize':
            $sellers = new SellerRepository();
            $endpoints = new SellerWebhookEndpointRepository();
            $deliveries = new SellerWebhookDeliveryRepository();
            $sellerUuid = (string) $args['sellerUuid'];
            $endpointUuid = (string) $args['endpointUuid'];
            $deliveryUuid = (string) $args['deliveryUuid'];
            $claimToken = (string) $args['claimToken'];

            $accepted = db($context)->transaction(function () use (
                $context,
                $tenant,
                $sellers,
                $endpoints,
                $deliveries,
                $sellerUuid,
                $endpointUuid,
                $deliveryUuid,
                $claimToken
            ): bool {
                $sellers->claimRevision($context, $tenant, $sellerUuid);
                $endpoints->claimRevision($context, $tenant, $endpointUuid);

                return $deliveries->finalize($context, $tenant, $deliveryUuid, $claimToken, [
                    'status' => 'delivered',
                    'last_status_code' => 200,
                    'last_error' => null,
                ], gmdate('Y-m-d H:i:s'));
            });

            $out = ['ok' => true, 'exceptionClass' => null, 'affected' => $accepted];
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (SellerWebhookException $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage(), 'errorCode' => $e->errorCode];
} catch (NotFoundException $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
