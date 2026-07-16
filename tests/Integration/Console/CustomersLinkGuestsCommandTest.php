<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Console;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\UserIdentity;
use Glueful\Extensions\Commerce\Console\CustomersLinkGuestsCommand;
use Glueful\Extensions\Commerce\Support\EmailNormalizer;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `commerce:customers:link-guests` (design spec §7, "Guest linking"): stamps
 * `user_uuid` onto a guest order ONLY when the provider resolves an identity
 * whose normalized email EXACTLY matches the order's normalized email. A
 * username-only resolution (or any mismatch) is rejected + reported, never
 * stamped — `findByLogin()` is identifier-agnostic and the provider contract
 * carries no verified-email flag (design spec §7, "Documented risk").
 */
final class CustomersLinkGuestsCommandTest extends CommerceTestCase
{
    public function testDryRunReportsThePlanWithoutStampingUserUuid(): void
    {
        $this->seedGuestOrder('orderlinkg01', 'match@example.com');
        $this->bindProvider(['match@example.com' => new UserIdentity(uuid: 'linkeduser01', email: 'match@example.com')]);

        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Dry run complete: 1 linked, 0 rejected, 0 unresolved, 1 guest order(s) scanned.', $this->normalizedDisplay($tester));
        self::assertNull($this->orderRow('orderlinkg01')['user_uuid']);
    }

    public function testExactNormalizedEmailMatchStampsUserUuid(): void
    {
        $this->seedGuestOrder('orderlinkg02', ' Match@Example.COM ');
        $this->bindProvider(['match@example.com' => new UserIdentity(uuid: 'linkeduser02', email: 'match@example.com')]);

        $tester = new CommandTester($this->command());
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Link complete: 1 linked, 0 rejected, 0 unresolved, 1 guest order(s) scanned.', $this->normalizedDisplay($tester));
        self::assertSame('linkeduser02', $this->orderRow('orderlinkg02')['user_uuid']);
    }

    public function testUsernameOnlyResolutionIsRejectedAndNotStamped(): void
    {
        // findByLogin() resolved something (e.g. by username), but the identity's
        // own email is completely different from the order's email -- must be
        // treated as a non-match, not a "close enough" link.
        $this->seedGuestOrder('orderlinkg03', 'someone@example.com');
        $this->bindProvider([
            'someone@example.com' => new UserIdentity(uuid: 'linkeduser03', email: 'totally-different@other.com'),
        ]);

        $tester = new CommandTester($this->command());
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Link complete: 0 linked, 1 rejected, 0 unresolved, 1 guest order(s) scanned.', $this->normalizedDisplay($tester));
        self::assertNull($this->orderRow('orderlinkg03')['user_uuid']);
    }

    public function testResolvedIdentityWithNullEmailIsRejected(): void
    {
        $this->seedGuestOrder('orderlinkg04', 'noemail@example.com');
        $this->bindProvider(['noemail@example.com' => new UserIdentity(uuid: 'linkeduser04')]);

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertStringContainsString('0 linked, 1 rejected', $this->normalizedDisplay($tester));
        self::assertNull($this->orderRow('orderlinkg04')['user_uuid']);
    }

    public function testUnresolvedEmailIsReportedAndNotStamped(): void
    {
        $this->seedGuestOrder('orderlinkg05', 'ghost@example.com');
        $this->bindProvider([]);

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertStringContainsString('0 linked, 0 rejected, 1 unresolved', $this->normalizedDisplay($tester));
        self::assertNull($this->orderRow('orderlinkg05')['user_uuid']);
    }

    public function testAlreadyLinkedOrdersAreNeverScanned(): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'orderlinkg06',
            'tenant_uuid' => '',
            'order_number' => 'ORD-orderlinkg06',
            'status' => 'paid',
            'email' => 'already@example.com',
            'user_uuid' => 'existinguser6',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
        $this->bindProvider(['already@example.com' => new UserIdentity(uuid: 'linkeduser06', email: 'already@example.com')]);

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertStringContainsString('No guest orders found.', $this->normalizedDisplay($tester));
        self::assertSame('existinguser6', $this->orderRow('orderlinkg06')['user_uuid']);
    }

    public function testEmailOptionNarrowsScopeToOneGuestOrder(): void
    {
        $this->seedGuestOrder('orderlinkg07', 'first@example.com');
        $this->seedGuestOrder('orderlinkg08', 'second@example.com');
        $this->bindProvider([
            'first@example.com' => new UserIdentity(uuid: 'linkeduser07', email: 'first@example.com'),
            'second@example.com' => new UserIdentity(uuid: 'linkeduser08', email: 'second@example.com'),
        ]);

        $tester = new CommandTester($this->command());
        $tester->execute(['--email' => 'first@example.com']);

        self::assertSame('linkeduser07', $this->orderRow('orderlinkg07')['user_uuid']);
        self::assertNull($this->orderRow('orderlinkg08')['user_uuid']);
    }

    public function testTenantOptionNarrowsScopeToOneTenant(): void
    {
        $this->seedGuestOrder('orderlinkga1', 'a@example.com', tenant: 'tenantAAAA01');
        $this->seedGuestOrder('orderlinkgb1', 'b@example.com', tenant: 'tenantBBBB02');
        $this->bindProvider([
            'a@example.com' => new UserIdentity(uuid: 'linkeduserA1', email: 'a@example.com'),
            'b@example.com' => new UserIdentity(uuid: 'linkeduserB1', email: 'b@example.com'),
        ]);

        $tester = new CommandTester($this->command());
        $tester->execute(['--tenant' => 'tenantAAAA01']);

        self::assertSame('linkeduserA1', $this->orderRow('orderlinkga1', 'tenantAAAA01')['user_uuid']);
        self::assertNull($this->orderRow('orderlinkgb1', 'tenantBBBB02')['user_uuid']);
    }

    public function testNoProviderBoundIsANoOpAndReportsClearly(): void
    {
        $this->seedGuestOrder('orderlinkg09', 'nobody@example.com');

        $tester = new CommandTester($this->command());
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No UserProviderInterface bound; nothing to link.', $this->normalizedDisplay($tester));
        self::assertNull($this->orderRow('orderlinkg09')['user_uuid']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function command(): CustomersLinkGuestsCommand
    {
        return new CustomersLinkGuestsCommand($this->context->getContainer(), $this->context);
    }

    /**
     * SymfonyStyle::info() word-wraps a long line across the tester's output
     * width, which would otherwise break a naive single-line
     * assertStringContainsString() check. Collapsing all whitespace runs to a
     * single space makes the assertion robust to wherever that wrap lands.
     */
    private function normalizedDisplay(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /**
     * The fake normalizes its OWN lookup key (a realistic provider would resolve
     * a login case/whitespace-insensitively too) so this test double isolates
     * the behavior actually under test: the CLI's post-resolution comparison
     * between the resolved identity's email and the order's stored email, both
     * normalized independently by {@see EmailNormalizer}.
     *
     * @param array<string,UserIdentity> $byNormalizedLogin
     */
    private function bindProvider(array $byNormalizedLogin): void
    {
        $this->bind(UserProviderInterface::class, new class ($byNormalizedLogin) implements UserProviderInterface {
            /** @param array<string,UserIdentity> $byNormalizedLogin */
            public function __construct(private array $byNormalizedLogin)
            {
            }

            public function findByUuid(string $uuid): ?UserIdentity
            {
                return null;
            }

            public function findByLogin(string $identifier): ?UserIdentity
            {
                return $this->byNormalizedLogin[EmailNormalizer::normalize($identifier)] ?? null;
            }

            public function verifyCredentials(string $identifier, string $password): ?UserIdentity
            {
                return null;
            }
        });
    }

    private function seedGuestOrder(string $uuid, string $email, string $tenant = ''): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'email' => $email,
            'user_uuid' => null,
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
    }

    /** @return array<string,mixed> */
    private function orderRow(string $uuid, string $tenant = ''): array
    {
        $row = $this->connection->table('commerce_orders')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
        self::assertNotNull($row);

        return $row;
    }
}
