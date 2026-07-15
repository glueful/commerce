<?php

declare(strict_types=1);

/**
 * Standalone subprocess for AddressBookConcurrencyTest's real-PostgreSQL
 * concurrent-first-default race. Runs connection B's competing
 * `AddressBookService::create()` call (requesting `is_default_shipping: true`) in a
 * genuinely separate OS process (and therefore a genuinely separate database
 * connection) so its `AddressBookRepository::claimBook()` really blocks on
 * PostgreSQL row-lock contention while connection A (the parent test process) still
 * holds the address book's parent row open and uncommitted. Mirrors
 * Catalog/fixtures/media_cover_race_child.php's structure exactly.
 *
 * argv: 1=pgConfig JSON, 2=tenant, 3=userUuid, 4=countryCode
 * stdout: JSON {"uuid": string|null, "isDefaultShipping": bool|null, "exceptionClass": string|null}
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Customers\AddressBookRepository;
use Glueful\Extensions\Commerce\Customers\AddressBookService;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $tenant, $userUuid, $countryCode] = $argv;
/** @var array<string,mixed> $pgConfig */
$pgConfig = json_decode($pgConfigJson, true, 512, JSON_THROW_ON_ERROR);

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

$tenantResolver = new class ($tenant) implements CurrentTenantResolver {
    public function __construct(private string $tenant)
    {
    }

    public function tenantUuid(ApplicationContext $context): string
    {
        return $this->tenant;
    }
};

$service = new AddressBookService(new AddressBookRepository(), $tenantResolver);

$uuid = null;
$isDefaultShipping = null;
$exceptionClass = null;

try {
    $row = $service->create($context, $userUuid, [
        'label' => 'B',
        'address' => ['country' => $countryCode],
        'is_default_shipping' => true,
        'is_default_billing' => false,
    ]);
    $uuid = $row['uuid'];
    $isDefaultShipping = $row['is_default_shipping'];
} catch (\Throwable $e) {
    $exceptionClass = $e::class;
}

echo json_encode([
    'uuid' => $uuid,
    'isDefaultShipping' => $isDefaultShipping,
    'exceptionClass' => $exceptionClass,
], JSON_THROW_ON_ERROR);
