<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;

final class CommerceServiceProvider extends ServiceProvider
{
    /**
     * Commerce binds nothing under shared contract ids. Factories that need the
     * tenant resolver or payment collector resolve the shared contract if bound,
     * otherwise construct an inline fallback.
     *
     * @return array<string, mixed>
     */
    public static function services(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'Commerce primitives: products, carts, orders, inventory, discounts, checkout, payments.';
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('commerce', require __DIR__ . '/../config/commerce.php');
    }

    public function boot(ApplicationContext $context): void
    {
        try {
            $this->loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::DEPENDENT, 'glueful/commerce');
        } catch (\Throwable $e) {
            error_log('[Commerce] Failed to register migrations: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e;
            }
        }
    }

    private function bootEnv(): string
    {
        return (string) ($_ENV['APP_ENV'] ?? (getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production'));
    }
}
