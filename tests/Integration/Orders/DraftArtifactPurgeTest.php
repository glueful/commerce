<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Console\OrdersExpireCommand;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\DraftCleanupService;
use Glueful\Extensions\Commerce\Orders\ExpiryService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Cleanup-train Task 5 -- HARD DELETION of draft ARTIFACTS.
 *
 * A draft artifact is an order row that was canceled while still a draft: it
 * carries `order_number IS NULL` (only finalize/checkout ever allocate a
 * number) AND `status = 'canceled'`. That pair is a STRUCTURAL proof the row
 * never touched money -- no payment, invoice, stock claim, payment link, refund
 * or marketplace child can reference it -- which is the ONLY reason a hard
 * delete is legal at all. Everything below exists to pin that the guard is the
 * authority, not the caller.
 *
 * Both halves of the feature share ONE mechanic
 * ({@see DraftCleanupService::deleteArtifact()}), exactly as the admin cancel
 * endpoint and the TTL sweep share `cancelDraft()`: the HTTP endpoint deletes
 * one artifact on operator demand, and {@see DraftCleanupService::purgeStale()}
 * deletes aged ones on the ordinary expiry cron tick. Two implementations of
 * "delete an artifact" could drift; one cannot.
 */
final class DraftArtifactPurgeTest extends CommerceTestCase
{
    private const TENANT = '';

    private const NOW = '2026-06-30 12:00:00';

    // -----------------------------------------------------------------
    // the shared guarded delete
    // -----------------------------------------------------------------

    public function testDeletingAnArtifactRemovesTheRowAndEveryChildItCanHave(): void
    {
        $this->seedArtifact('artifactdel1', $this->daysBeforeNow(1));
        $this->seedLine('artifactdel1', 'artlinedel01');
        $this->seedEvent('artifactdel1', 'draft_created');
        $this->seedEvent('artifactdel1', 'draft_canceled');
        $this->seedAttempt('artifactdel1', 'key-artifactdel1');

        // A bystander order with children of its own: the delete is addressed by
        // uuid, never by "everything that looks abandoned".
        $this->seedOrder('bystander001', 'paid', $this->daysBeforeNow(1));
        $this->seedLine('bystander001', 'artlinebys01');
        $this->seedEvent('bystander001', 'status:paid');
        $this->seedAttempt('bystander001', 'key-bystander001');

        self::assertTrue(
            $this->service()->deleteArtifact($this->context, self::TENANT, 'artifactdel1', 'operator0001')
        );

        self::assertNull($this->rowOf('artifactdel1'));
        self::assertSame(0, $this->lineCount('artifactdel1'));
        self::assertSame(0, $this->eventCount('artifactdel1'));
        self::assertSame(0, $this->attemptCount('artifactdel1'));

        self::assertNotNull($this->rowOf('bystander001'));
        self::assertSame(1, $this->lineCount('bystander001'));
        self::assertSame(1, $this->eventCount('bystander001'));
        self::assertSame(1, $this->attemptCount('bystander001'));
    }

    /**
     * ONE transaction, proven as the property that actually matters: ATOMICITY.
     *
     * The order row is deleted FIRST (the compare-and-set that authorizes the
     * whole operation), children after. If those two were not inside one
     * transaction, a child-delete failure would leave the order row gone and its
     * lines/events orphaned forever -- unreachable through every tenant-scoped
     * reader in this engine, since they all join back through `commerce_orders`.
     *
     * The fault is injected by dropping `commerce_order_draft_attempts` before
     * the call, which is the last child delete; the assertion is that the order
     * row and the EARLIER child deletes are all back.
     */
    public function testAFailingChildDeleteRollsTheWholeDeletionBack(): void
    {
        $this->seedArtifact('artifactrbk1', $this->daysBeforeNow(1));
        $this->seedLine('artifactrbk1', 'artlinerbk01');
        $this->seedEvent('artifactrbk1', 'draft_canceled');

        $this->connection->getSchemaBuilder()->dropTableIfExists('commerce_order_draft_attempts');

        try {
            $this->service()->deleteArtifact($this->context, self::TENANT, 'artifactrbk1', 'operator0001');
            self::fail('a missing child table must surface, never be swallowed');
        } catch (\Throwable) {
            // expected -- the point is what the database looks like afterwards
        }

        self::assertNotNull($this->rowOf('artifactrbk1'), 'the CAS-deleted order row must come back');
        self::assertSame(1, $this->lineCount('artifactrbk1'), 'the line delete must come back');
        self::assertSame(1, $this->eventCount('artifactrbk1'), 'the event delete must come back');
    }

    /**
     * @dataProvider undeletableRows
     * @param array<string,mixed> $overrides
     */
    public function testTheGuardRefusesEverythingThatIsNotAnArtifact(array $overrides): void
    {
        $this->seedArtifact('artifactgrd1', $this->daysBeforeNow(1), null, self::TENANT, $overrides);
        $this->seedLine('artifactgrd1', 'artlinegrd01');

        self::assertFalse(
            $this->service()->deleteArtifact($this->context, self::TENANT, 'artifactgrd1', 'operator0001')
        );

        self::assertNotNull($this->rowOf('artifactgrd1'));
        self::assertSame(1, $this->lineCount('artifactgrd1'));
    }

    /** @return array<string,array{0: array<string,mixed>}> */
    public static function undeletableRows(): array
    {
        return [
            // An ACTIVE draft must be canceled first -- deleting it outright would
            // let a mis-click destroy work in progress with no intermediate state.
            'an active draft' => [['status' => 'draft']],
            'a numbered canceled order' => [['order_number' => 'ORD-000123']],
            'an unpaid order' => [['status' => 'pending_payment', 'order_number' => 'ORD-000124']],
            'a paid order' => [['status' => 'paid', 'order_number' => 'ORD-000125']],
            'a refunded order' => [['status' => 'refunded', 'order_number' => 'ORD-000126']],
            // The pathological half-shape: canceled, numbered. Numbered wins.
            'a canceled order that kept its number' => [['order_number' => 'ORD-000127']],
            // ...and the other half: numberless but not canceled. Never reachable
            // today (only a draft is numberless and live), pinned anyway.
            'a numberless live order' => [['status' => 'pending_payment']],
        ];
    }

    public function testTheGuardIsTenantScoped(): void
    {
        $this->seedArtifact('artifactten1', $this->daysBeforeNow(1), null, 'othertenant1');

        self::assertFalse(
            $this->service()->deleteArtifact($this->context, self::TENANT, 'artifactten1', 'operator0001')
        );
        self::assertNotNull($this->rowOf('artifactten1'));
    }

    public function testDeletingAnUnknownUuidIsACleanNoOp(): void
    {
        self::assertFalse(
            $this->service()->deleteArtifact($this->context, self::TENANT, 'artifactnope', 'operator0001')
        );
    }

    /** Two callers racing the same artifact: exactly one reports a deletion. */
    public function testASecondDeleteOfTheSameArtifactReportsNothingDeleted(): void
    {
        $this->seedArtifact('artifacttwo1', $this->daysBeforeNow(1));
        $service = $this->service();

        self::assertTrue($service->deleteArtifact($this->context, self::TENANT, 'artifacttwo1', 'operator0001'));
        self::assertFalse($service->deleteArtifact($this->context, self::TENANT, 'artifacttwo1', 'operator0002'));
    }

    // -----------------------------------------------------------------
    // audit posture
    // -----------------------------------------------------------------

    /**
     * Deletion leaves NO row behind BY DEFINITION -- an order-event audit row
     * would reference an order that no longer exists, and `eventsForOrder()`
     * joins through `commerce_orders`, so it would be permanently unreadable.
     * The one destructive operation in this engine that records nothing in the
     * database therefore records itself in the app LOG instead: actor + uuid +
     * reason, never customer PII (the artifact's own name/email/phone die with
     * the row and must not be copied into a log line on the way out).
     */
    public function testDeletionLogsActorAndUuidWithNoCustomerPii(): void
    {
        $logger = $this->recordingLogger();
        $this->seedArtifact('artifactlog1', $this->daysBeforeNow(1), null, self::TENANT, [
            'email' => 'walkin@example.com',
            'customer_name' => 'Ada Lovelace',
            'phone_normalized' => '+15550001111',
        ]);

        self::assertTrue(
            $this->service($logger)->deleteArtifact($this->context, self::TENANT, 'artifactlog1', 'operator0001')
        );

        self::assertCount(1, $logger->lines);
        [$level, $message, $payload] = $logger->lines[0];
        self::assertSame('info', $level);
        self::assertSame('commerce.orders.artifact_deleted', $message);
        self::assertSame('artifactlog1', $payload['order_uuid'] ?? null);
        self::assertSame('operator0001', $payload['actor_uuid'] ?? null);
        self::assertSame(DraftCleanupService::REASON_ADMIN, $payload['reason'] ?? null);

        $serialized = json_encode($logger->lines, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('walkin@example.com', $serialized);
        self::assertStringNotContainsString('Ada Lovelace', $serialized);
        self::assertStringNotContainsString('+15550001111', $serialized);
    }

    public function testARefusedDeletionLogsNothing(): void
    {
        $logger = $this->recordingLogger();
        $this->seedOrder('artifactnum1', 'canceled', $this->daysBeforeNow(1));

        self::assertFalse(
            $this->service($logger)->deleteArtifact($this->context, self::TENANT, 'artifactnum1', 'operator0001')
        );
        self::assertSame([], $logger->lines);
    }

    public function testThePurgeSweepLogsItselfUnderItsOwnReason(): void
    {
        $logger = $this->recordingLogger();
        $this->seedArtifact('artifactplg1', $this->daysBeforeNow(45));

        self::assertSame(1, $this->service($logger)->purgeStale($this->context, $this->now()));

        self::assertCount(1, $logger->lines);
        self::assertSame(DraftCleanupService::REASON_PURGE, $logger->lines[0][2]['reason'] ?? null);
        self::assertArrayHasKey('actor_uuid', $logger->lines[0][2]);
        self::assertNull($logger->lines[0][2]['actor_uuid'], 'the sweep has no operator');
    }

    // -----------------------------------------------------------------
    // the purge sweep
    // -----------------------------------------------------------------

    public function testThePurgeWindowBoundaryKeepsFreshArtifactsAndTakesStaleOnes(): void
    {
        self::assertSame(30, CommerceSettings::draftPurgeDays($this->context));

        $this->seedArtifact('artifactfrs1', $this->daysBeforeNow(29));
        $this->seedArtifact('artifactstl1', $this->daysBeforeNow(31));

        self::assertSame(1, $this->service()->purgeStale($this->context, $this->now()));

        self::assertNotNull($this->rowOf('artifactfrs1'));
        self::assertNull($this->rowOf('artifactstl1'));
    }

    /**
     * The exact boundary resolves in the operator's favour, matching
     * {@see DraftCleanupService::cancelStale()}: an artifact aged PRECISELY
     * `draft_purge_days` survives one more tick.
     */
    public function testAnArtifactAgedExactlyThePurgeWindowSurvives(): void
    {
        $this->seedArtifact('artifactexc1', $this->daysBeforeNow(30));

        self::assertSame(0, $this->service()->purgeStale($this->context, $this->now()));
        self::assertNotNull($this->rowOf('artifactexc1'));

        self::assertSame(1, $this->service()->purgeStale($this->context, $this->now()->modify('+1 second')));
        self::assertNull($this->rowOf('artifactexc1'));
    }

    public function testStalenessIsMeasuredFromTheLastTouchNotCreation(): void
    {
        // Canceled long ago but touched yesterday -- the cancel sweep itself
        // stamps `updated_at`, so a freshly canceled artifact always gets the
        // full purge window before it is destroyed.
        $this->seedArtifact('artifacttch1', $this->daysBeforeNow(90), $this->daysBeforeNow(1));
        $this->seedArtifact('artifacttch2', $this->daysBeforeNow(90), $this->daysBeforeNow(60));

        self::assertSame(1, $this->service()->purgeStale($this->context, $this->now()));

        self::assertNotNull($this->rowOf('artifacttch1'));
        self::assertNull($this->rowOf('artifacttch2'));
    }

    public function testThePurgeWindowIsRuntimeConfigurable(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['orders' => ['draft_purge_days' => 7]]);
        self::assertSame(7, CommerceSettings::draftPurgeDays($this->context));

        $this->seedArtifact('artifactcfg1', $this->daysBeforeNow(6));
        $this->seedArtifact('artifactcfg2', $this->daysBeforeNow(8));

        self::assertSame(1, $this->service()->purgeStale($this->context, $this->now()));

        self::assertNotNull($this->rowOf('artifactcfg1'));
        self::assertNull($this->rowOf('artifactcfg2'));
    }

    /**
     * Clamped, not merely cast (same discipline as the payment-link TTL): a `0`
     * would purge an artifact the instant it is canceled -- destroying the very
     * row an operator is about to look at -- and an unbounded value would make
     * the sweep a no-op that quietly never runs.
     *
     * @dataProvider purgeWindowClamps
     */
    public function testThePurgeWindowIsClampedIntoAClosedRange(int $configured, int $expected): void
    {
        $this->context->mergeConfigDefaults('commerce', ['orders' => ['draft_purge_days' => $configured]]);

        self::assertSame($expected, CommerceSettings::draftPurgeDays($this->context));
        self::assertSame($expected, CommerceSettings::clampDraftPurgeDays($configured));
    }

    /** @return array<string,array{0: int, 1: int}> */
    public static function purgeWindowClamps(): array
    {
        return [
            'zero clamps up to the minimum' => [0, CommerceSettings::DRAFT_PURGE_DAYS_MIN],
            'negative clamps up to the minimum' => [-5, CommerceSettings::DRAFT_PURGE_DAYS_MIN],
            'the minimum is honoured' => [1, 1],
            'the maximum is honoured' => [365, 365],
            'absurd clamps down to the maximum' => [100000, CommerceSettings::DRAFT_PURGE_DAYS_MAX],
        ];
    }

    public function testThePurgeIsBoundedByBatchSizeAndSuccessiveRunsDrain(): void
    {
        $batchSize = 3;
        $total = ($batchSize * 2) + 1;
        for ($i = 0; $i < $total; $i++) {
            $this->seedArtifact(sprintf('artifactbt%02d', $i), $this->daysBeforeNow(45));
        }

        $service = $this->service();
        self::assertSame(3, $service->purgeStale($this->context, $this->now(), $batchSize));
        self::assertSame(3, $service->purgeStale($this->context, $this->now(), $batchSize));
        self::assertSame(1, $service->purgeStale($this->context, $this->now(), $batchSize));
        self::assertSame(0, $service->purgeStale($this->context, $this->now(), $batchSize));

        self::assertSame(0, $this->connection->table('commerce_orders')->count());
    }

    public function testANonPositiveBatchSizePurgesNothing(): void
    {
        $this->seedArtifact('artifactbat1', $this->daysBeforeNow(45));

        self::assertSame(0, $this->service()->purgeStale($this->context, $this->now(), 0));
        self::assertNotNull($this->rowOf('artifactbat1'));
    }

    /**
     * OVERLAP SAFETY: two sweeps running over the same backlog double-delete
     * nothing. Each row's delete is its own compare-and-set, so the second
     * sweep's rows are simply gone and it reports what it actually did.
     */
    public function testRerunningThePurgeIsIdempotent(): void
    {
        $this->seedArtifact('artifactidm1', $this->daysBeforeNow(45));

        $service = $this->service();
        self::assertSame(1, $service->purgeStale($this->context, $this->now()));
        self::assertSame(0, $service->purgeStale($this->context, $this->now()));
        self::assertSame(0, $service->purgeStale($this->context, $this->now()));
    }

    public function testThePurgeNeverTouchesAnythingThatIsNotAnArtifact(): void
    {
        $ancient = $this->daysBeforeNow(400);
        $this->seedOrder('purgepend001', 'pending_payment', $ancient);
        $this->seedOrder('purgepaid001', 'paid', $ancient);
        // A REAL canceled order: canceled but NUMBERED, so it has money history
        // and is never purgeable however old it gets.
        $this->seedOrder('purgecanc001', 'canceled', $ancient);
        // An ancient ACTIVE draft: the TTL sweep's business, not the purge's.
        $this->seedArtifact('purgedrft001', $ancient, null, self::TENANT, ['status' => 'draft']);
        $this->seedArtifact('purgeartf001', $ancient);

        self::assertSame(1, $this->service()->purgeStale($this->context, $this->now()));

        self::assertNotNull($this->rowOf('purgepend001'));
        self::assertNotNull($this->rowOf('purgepaid001'));
        self::assertNotNull($this->rowOf('purgecanc001'));
        self::assertNotNull($this->rowOf('purgedrft001'));
        self::assertNull($this->rowOf('purgeartf001'));
    }

    public function testThePurgeIsTenantScoped(): void
    {
        $this->seedArtifact('purgemine001', $this->daysBeforeNow(45));
        $this->seedArtifact('purgeothr001', $this->daysBeforeNow(45), null, 'othertenant1');

        self::assertSame(1, $this->service()->purgeStale($this->context, $this->now()));

        self::assertNull($this->rowOf('purgemine001'));
        self::assertNotNull($this->rowOf('purgeothr001'));
    }

    // -----------------------------------------------------------------
    // cron wiring
    // -----------------------------------------------------------------

    /**
     * Hosts get purging with NO new cron obligation: the purge is a third
     * INDEPENDENT sweep on the existing `commerce:orders:expire` tick, isolated
     * from its two siblings exactly as they are from each other.
     */
    public function testTheExpiryCronCommandAlsoDrivesThePurge(): void
    {
        $ancient = gmdate('Y-m-d H:i:s', time() - (400 * 86400));
        $this->seedArtifact('cronartifct1', $ancient);
        $this->seedDraftForCron('crondraft001', $ancient);

        $this->bindCronCollaborators();

        $tester = new CommandTester(new OrdersExpireCommand($this->context->getContainer(), $this->context));
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Purged 1 draft artifact(s).', $display);
        self::assertNull($this->rowOf('cronartifct1'));

        // The draft the SAME tick just canceled is NOT purged in that tick: its
        // `updated_at` was stamped to now, so it gets the full purge window.
        self::assertStringContainsString('Canceled 1 stale draft order(s).', $display);
        self::assertNotNull($this->rowOf('crondraft001'));
    }

    public function testAThrowingSiblingSweepStillLetsThePurgeRun(): void
    {
        $ancient = gmdate('Y-m-d H:i:s', time() - (400 * 86400));
        $this->seedArtifact('cronartifct2', $ancient);

        $this->bind(ExpiryService::class, new class {
            public function expireStale(ApplicationContext $context): int
            {
                throw new \RuntimeException('stock backend unavailable');
            }
        });
        $this->bind(DraftCleanupService::class, $this->service());

        $tester = new CommandTester(new OrdersExpireCommand($this->context->getContainer(), $this->context));
        $exit = $tester->execute([]);

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertSame(1, $exit, 'a failed sweep must still surface as a non-zero exit');
        self::assertStringContainsString('Expire stale orders failed: stock backend unavailable', $display);
        self::assertStringContainsString('Purged 1 draft artifact(s).', $display);
        self::assertNull($this->rowOf('cronartifct2'));
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function service(?LoggerInterface $logger = null): DraftCleanupService
    {
        return new DraftCleanupService(new OrderRepository(), new SentinelTenantResolver(), $logger);
    }

    private function bindCronCollaborators(): void
    {
        $this->bind(ExpiryService::class, new ExpiryService(
            new OrderRepository(),
            new StockRepository(),
            new SentinelTenantResolver()
        ));
        $this->bind(DraftCleanupService::class, $this->service());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }

    private function daysBeforeNow(int $days): string
    {
        return $this->now()->modify("-{$days} days")->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $overrides */
    private function seedArtifact(
        string $uuid,
        string $createdAt,
        ?string $updatedAt = null,
        string $tenant = self::TENANT,
        array $overrides = []
    ): void {
        $this->connection->table('commerce_orders')->insert($overrides + [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => null,
            'status' => 'canceled',
            'email' => null,
            'guest_token_hash' => null,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'origin' => 'admin',
            'fulfillment_mode' => 'in_store',
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function seedDraftForCron(string $uuid, string $createdAt): void
    {
        $this->seedArtifact($uuid, $createdAt, null, self::TENANT, ['status' => 'draft']);
    }

    private function seedOrder(string $uuid, string $status, string $createdAt): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'placed_at' => $createdAt,
            'created_at' => $createdAt,
        ]);
    }

    private function seedLine(string $orderUuid, string $lineUuid): void
    {
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => $lineUuid,
            'order_uuid' => $orderUuid,
            'variant_uuid' => 'variant00001',
            'product_name' => 'Widget',
            'sku' => 'WIDGET-1',
            'option_values' => '[]',
            'unit_price' => 1000,
            'quantity' => 1,
            'line_total' => 1000,
        ]);
    }

    private function seedEvent(string $orderUuid, string $type): void
    {
        $this->connection->table('commerce_order_events')->insert([
            'uuid' => substr(md5($orderUuid . $type), 0, 12),
            'order_uuid' => $orderUuid,
            'type' => $type,
            'payload' => null,
            'actor_uuid' => null,
            'visibility' => 'internal',
        ]);
    }

    private function seedAttempt(string $orderUuid, string $key): void
    {
        $this->connection->table('commerce_order_draft_attempts')->insert([
            'tenant_uuid' => self::TENANT,
            'idempotency_key' => $key,
            'request_fingerprint' => str_repeat('f', 64),
            'order_uuid' => $orderUuid,
            'status' => 'completed',
        ]);
    }

    /** @return array<string,mixed>|null */
    private function rowOf(string $uuid): ?array
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $uuid)->first();

        return is_array($row) ? $row : null;
    }

    private function lineCount(string $orderUuid): int
    {
        return $this->connection->table('commerce_order_lines')->where('order_uuid', '=', $orderUuid)->count();
    }

    private function eventCount(string $orderUuid): int
    {
        return $this->connection->table('commerce_order_events')->where('order_uuid', '=', $orderUuid)->count();
    }

    private function attemptCount(string $orderUuid): int
    {
        return $this->connection->table('commerce_order_draft_attempts')
            ->where('order_uuid', '=', $orderUuid)
            ->count();
    }

    /** @return AbstractLogger&object{lines: list<array{0: string, 1: string, 2: array<string,mixed>}>} */
    private function recordingLogger(): object
    {
        return new class extends AbstractLogger {
            /** @var list<array{0: string, 1: string, 2: array<string,mixed>}> */
            public array $lines = [];

            /**
             * @param mixed $level
             * @param string|\Stringable $message
             * @param array<string,mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->lines[] = [(string) $level, (string) $message, $context];
            }
        };
    }
}
