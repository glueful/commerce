<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Bootstrap\ApplicationContext;

final class OrderNumberGenerator
{
    public function next(ApplicationContext $context, string $tenant): string
    {
        $updated = $this->incrementExisting($context, $tenant);
        if ($updated === 0) {
            try {
                // Run the allocation attempt inside its own savepoint (via the
                // framework's transaction manager -- Connection::transaction()
                // nests as a savepoint when a transaction is already active,
                // or opens a plain outermost transaction otherwise). Without
                // this, a caught unique-violation on PostgreSQL leaves an
                // already-open enclosing transaction (e.g. an order-creation
                // flow) aborted: every later statement -- including the
                // fallback incrementExisting() call below -- fails with
                // "current transaction is aborted". Rolling back to the
                // savepoint instead discards only this failed attempt and
                // keeps the enclosing transaction usable.
                db($context)->transaction(function () use ($context, $tenant): void {
                    db($context)->table('commerce_sequences')->insert([
                        'tenant_uuid' => $tenant,
                        'name' => 'order',
                        'value' => 1,
                    ]);
                });
            } catch (\Throwable) {
                $this->incrementExisting($context, $tenant);
            }
        }

        $row = db($context)->table('commerce_sequences')
            ->where('tenant_uuid', '=', $tenant)
            ->where('name', '=', 'order')
            ->first();
        $value = (int) ($row['value'] ?? 1);
        $format = CommerceSettings::orderNumberFormat($context);

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
