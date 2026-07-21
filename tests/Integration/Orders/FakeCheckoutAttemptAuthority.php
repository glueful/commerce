<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Marketplace\CheckoutConflictException;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptAuthority;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptContext;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptReplay;

/**
 * Test-only, self-contained fake of the pack-owned attempt ledger (design
 * spec §7, Slice-2 Task 3) -- Commerce itself never binds a
 * {@see CheckoutAttemptAuthority} implementation. State is an in-process map
 * keyed by idempotency key (fine for these single-tenant fixtures); an
 * `$onComplete` hook lets a test observe or force-fail the "bind attempt to
 * order" write from INSIDE the placement transaction, exactly where a real
 * implementation's write would run.
 */
final class FakeCheckoutAttemptAuthority implements CheckoutAttemptAuthority
{
    /** @var list<CheckoutAttemptContext> */
    public array $claimOrReplayCalls = [];

    /** @var list<array{orderUuid:string,orderRef:string,rawGuestToken:string}> */
    public array $completeCalls = [];

    /** @var array<string, array{fingerprint:string, orderUuid:string, orderRef:string, guestCredential:string}> */
    private array $completed = [];

    /** @param (callable(ApplicationContext,string):void)|null $onComplete */
    public function __construct(private $onComplete = null)
    {
    }

    public function claimOrReplay(
        ApplicationContext $c,
        string $tenant,
        CheckoutAttemptContext $ctx
    ): ?CheckoutAttemptReplay {
        $this->claimOrReplayCalls[] = $ctx;

        $existing = $this->completed[$ctx->idempotencyKey] ?? null;
        if ($existing === null) {
            return null;
        }

        if ($existing['fingerprint'] !== $ctx->requestFingerprint) {
            throw new CheckoutConflictException(
                'Checkout conflict: idempotency key reused with a different request.'
            );
        }

        return new CheckoutAttemptReplay($existing['orderUuid'], $existing['orderRef'], $existing['guestCredential']);
    }

    public function complete(
        ApplicationContext $c,
        string $tenant,
        CheckoutAttemptContext $ctx,
        string $orderUuid,
        string $orderRef,
        string $rawGuestToken
    ): void {
        $this->completeCalls[] = [
            'orderUuid' => $orderUuid,
            'orderRef' => $orderRef,
            'rawGuestToken' => $rawGuestToken,
        ];

        if ($this->onComplete !== null) {
            ($this->onComplete)($c, $orderUuid);
        }

        $this->completed[$ctx->idempotencyKey] = [
            'fingerprint' => $ctx->requestFingerprint,
            'orderUuid' => $orderUuid,
            'orderRef' => $orderRef,
            'guestCredential' => $rawGuestToken,
        ];
    }
}
