<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

abstract class CommerceTestCase extends TestCase
{
    protected ApplicationContext $context;
    protected Connection $connection;

    /** @var array<string,mixed> extra container bindings a test may inject */
    protected array $bindings = [];

    /** @var list<class-string> migration classes to run */
    protected const MIGRATIONS = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);

        $schema = $this->connection->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        $connection = $this->connection;
        $bindings = &$this->bindings;

        $container = new class ($connection, $bindings) implements ContainerInterface {
            /** @param array<string,mixed> $bindings */
            public function __construct(
                private Connection $connection,
                private array &$bindings,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                if (array_key_exists($id, $this->bindings)) {
                    return $this->bindings[$id];
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database'
                    || $id === Connection::class
                    || array_key_exists($id, $this->bindings);
            }
        };

        $this->context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $this->context->setContainer($container);
        $this->context->mergeConfigDefaults('commerce', require __DIR__ . '/../../config/commerce.php');
    }

    protected function appContext(): ApplicationContext
    {
        return $this->context;
    }

    protected function connection(): Connection
    {
        return $this->connection;
    }

    protected function bind(string $id, mixed $service): void
    {
        $this->bindings[$id] = $service;
    }
}
