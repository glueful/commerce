<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Extensions\Commerce\Orders\GuestOrderClaimService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;

final class GuestOrderClaimTest extends CommerceTestCase
{
    private const TOKEN = 'guest-token-abc';

    private function order(
        string $uuid,
        string $number,
        string $email = 'buyer@example.test',
        ?string $userUuid = null,
        string $tenant = '',
    ): void {
        db($this->context)->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => $number,
            'email' => $email,
            'user_uuid' => $userUuid,
            'guest_token_hash' => TokenHasher::hash(self::TOKEN),
            'status' => 'paid',
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'created_at' => '2026-07-01 10:00:00',
        ]);
    }

    private function service(): GuestOrderClaimService
    {
        return new GuestOrderClaimService(new OrderRepository(), new SentinelTenantResolver());
    }

    public function testBothProofsTogetherClaimTheOrder(): void
    {
        $this->order('ordr00000001', 'ORD-1');

        $claimed = $this->service()->claim($this->context, 'user00000001', 'buyer@example.test', 'ORD-1', self::TOKEN);

        self::assertSame('ordr00000001', $claimed['uuid']);
        self::assertSame('user00000001', $claimed['user_uuid']);
        self::assertArrayNotHasKey('guest_token_hash', $claimed);
    }

    public function testEmailIsMatchedAfterNormalization(): void
    {
        $this->order('ordr00000002', 'ORD-2', ' Buyer@Example.test ');

        $claimed = $this->service()->claim($this->context, 'user00000001', 'buyer@example.test', 'ORD-2', self::TOKEN);

        self::assertSame('user00000001', $claimed['user_uuid']);
    }

    public function testAWrongGuestTokenIsRejectedEvenWhenTheEmailMatches(): void
    {
        // Email verification proves mailbox control, not that this person placed the order.
        $this->order('ordr00000003', 'ORD-3');

        $this->expectException(NotFoundException::class);
        $this->service()->claim($this->context, 'user00000001', 'buyer@example.test', 'ORD-3', 'wrong-token');
    }

    public function testAMismatchedEmailIsRejectedEvenWithAValidToken(): void
    {
        // The token may have been forwarded in a receipt.
        $this->order('ordr00000004', 'ORD-4');

        $this->expectException(NotFoundException::class);
        $this->service()->claim($this->context, 'user00000001', 'someone@example.test', 'ORD-4', self::TOKEN);
    }

    public function testAnOrderOwnedByAnotherUserIsNotRevealed(): void
    {
        $this->order('ordr00000005', 'ORD-5', 'buyer@example.test', 'user00000002');

        $this->expectException(NotFoundException::class);
        $this->service()->claim($this->context, 'user00000001', 'buyer@example.test', 'ORD-5', self::TOKEN);
    }

    public function testReClaimingYourOwnOrderIsASuccessfulNoOp(): void
    {
        // A double-submitted form must not 404 a visitor out of their own order.
        $this->order('ordr00000006', 'ORD-6', 'buyer@example.test', 'user00000001');

        $claimed = $this->service()->claim($this->context, 'user00000001', 'buyer@example.test', 'ORD-6', self::TOKEN);

        self::assertSame('user00000001', $claimed['user_uuid']);
    }

    public function testAnOrderInAnotherTenantIsNotClaimable(): void
    {
        $this->order('ordr00000007', 'ORD-7', 'buyer@example.test', null, 'tenantaaaa01');

        $this->expectException(NotFoundException::class);
        $this->service()->claim($this->context, 'user00000001', 'buyer@example.test', 'ORD-7', self::TOKEN);
    }

    public function testEveryFailureCarriesTheIdenticalMessage(): void
    {
        // Distinguishable messages would turn this into an order-existence oracle.
        $this->order('ordr00000008', 'ORD-8', 'buyer@example.test', 'user00000002');
        $messages = [];

        foreach ([
            ['ORD-NOPE', self::TOKEN, 'buyer@example.test'],
            ['ORD-8', 'wrong-token', 'buyer@example.test'],
            ['ORD-8', self::TOKEN, 'other@example.test'],
            ['ORD-8', self::TOKEN, 'buyer@example.test'],
        ] as [$number, $token, $email]) {
            try {
                $this->service()->claim($this->context, 'user00000001', $email, $number, $token);
                self::fail('Expected the claim to fail for ' . $number);
            } catch (NotFoundException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertSame(['Resource not found.'], array_values(array_unique($messages)));
    }

    public function testHistoricalImportClaimsEveryUnownedOrderMatchingTheVerifiedEmail(): void
    {
        $this->order('ordr00000010', 'ORD-10');
        $this->order('ordr00000011', 'ORD-11', 'BUYER@Example.test ');
        $this->order('ordr00000012', 'ORD-12', 'someone@example.test');
        $this->order('ordr00000013', 'ORD-13', 'buyer@example.test', 'user00000002');

        $claimed = $this->service()->claimAllByVerifiedEmail($this->context, 'user00000001', 'buyer@example.test');

        sort($claimed);
        self::assertSame(['ORD-10', 'ORD-11'], $claimed);
    }

    public function testHistoricalImportIsIdempotent(): void
    {
        $this->order('ordr00000014', 'ORD-14');
        $service = $this->service();

        self::assertSame(
            ['ORD-14'],
            $service->claimAllByVerifiedEmail($this->context, 'user00000001', 'buyer@example.test')
        );
        self::assertSame([], $service->claimAllByVerifiedEmail($this->context, 'user00000001', 'buyer@example.test'));
    }

    public function testHistoricalImportRefusesAnEmptyEmail(): void
    {
        // A blank "verified" email must never match guest orders whose email is also blank.
        $this->order('ordr00000015', 'ORD-15', '');

        self::assertSame([], $this->service()->claimAllByVerifiedEmail($this->context, 'user00000001', ''));
    }
}
