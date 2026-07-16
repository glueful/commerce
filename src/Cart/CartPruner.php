<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Cart;

use Glueful\Bootstrap\ApplicationContext;

final class CartPruner
{
    public function prune(ApplicationContext $context): int
    {
        return db($context)->table('commerce_carts')->executeModification(
            <<<'SQL'
UPDATE commerce_carts
SET status = 'abandoned', updated_at = ?
WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < ?
SQL,
            [
                db($context)->getDriver()->formatDateTime(),
                gmdate('Y-m-d H:i:s'),
            ]
        );
    }
}
