<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;

final class OrderNumberGenerator
{
    public function next(ApplicationContext $context, string $tenant): string
    {
        $updated = $this->incrementExisting($context, $tenant);
        if ($updated === 0) {
            try {
                db($context)->table('commerce_sequences')->insert([
                    'tenant_uuid' => $tenant,
                    'name' => 'order',
                    'value' => 1,
                ]);
            } catch (\Throwable) {
                $this->incrementExisting($context, $tenant);
            }
        }

        $row = db($context)->table('commerce_sequences')
            ->where('tenant_uuid', '=', $tenant)
            ->where('name', '=', 'order')
            ->first();
        $value = (int) ($row['value'] ?? 1);
        $format = (string) config($context, 'commerce.orders.number_format', 'ORD-{seq}');

        return str_replace('{seq}', str_pad((string) $value, 6, '0', STR_PAD_LEFT), $format);
    }

    private function incrementExisting(ApplicationContext $context, string $tenant): int
    {
        return db($context)->table('commerce_sequences')->executeModification(
            <<<'SQL'
UPDATE commerce_sequences
SET value = value + 1
WHERE tenant_uuid = ? AND name = ?
SQL,
            [$tenant, 'order']
        );
    }
}
