<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptAuthority;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptContext;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptReplay;

/**
 * Transaction-visibility proof fixture (design spec §7, Slice-2 Task 3):
 * `complete()` -- called INSIDE the still-open placement transaction, right
 * after the order row is inserted -- reads that SAME order back through a
 * SECOND, wholly independent {@see Connection} (its own PDO handle) rather
 * than the one `CheckoutService` is using. SQLite's default rollback-journal
 * locking lets a reader keep seeing the pre-transaction snapshot while a
 * writer holds its (as yet uncommitted) RESERVED lock, so this read must
 * come back null here and only becomes non-null once the caller's
 * `placeOrder()` transaction actually commits.
 */
final class VisibilityProbeCheckoutAttemptAuthority implements CheckoutAttemptAuthority
{
    public ?array $duringTransactionOrderRow = null;
    public bool $observed = false;

    public function __construct(private readonly Connection $reader)
    {
    }

    public function claimOrReplay(
        ApplicationContext $c,
        string $tenant,
        CheckoutAttemptContext $ctx
    ): ?CheckoutAttemptReplay {
        return null;
    }

    public function complete(
        ApplicationContext $c,
        string $tenant,
        CheckoutAttemptContext $ctx,
        string $orderUuid,
        string $orderRef,
        string $rawGuestToken
    ): void {
        $this->duringTransactionOrderRow = $this->reader->table('commerce_orders')
            ->where('uuid', '=', $orderUuid)
            ->first();
        $this->observed = true;
    }
}
