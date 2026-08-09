<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\Commerce\Console\OrdersExpireCommand;
use Glueful\Extensions\Commerce\Events\OrderCanceled;
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Extensions\Commerce\Events\OrderPaid;
use Glueful\Extensions\Commerce\Events\OrderPlaced;
use Glueful\Extensions\Commerce\Events\RefundCompleted;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Mail\CommerceMailer;
use Glueful\Extensions\Commerce\Orders\DraftCleanupService;
use Glueful\Extensions\Commerce\Orders\Events\DraftOrderEvents;
use Glueful\Extensions\Commerce\Orders\ExpiryService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\RecordingCommerceMailer;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Admin-order-creation cycle 2, Task 8 -- bounded, idempotent draft TTL cleanup.
 *
 * `DraftCleanupService::cancelStale()` takes its clock as an argument, so every
 * assertion below is fully deterministic: no `time()`, no sleeping, no
 * tolerance windows. Cancellation is a draft-SPECIFIC path -- it records an
 * order-event audit row and NOTHING else: no dispatched lifecycle event, no
 * mail, no webhook, no marketplace fan-out.
 */
final class DraftCleanupTest extends CommerceTestCase
{
    private const TENANT = '';

    private const NOW = '2026-06-30 12:00:00';

    public function testTtlBoundaryCancelsOnlyDraftsOlderThanTheConfiguredWindow(): void
    {
        self::assertSame(30, CommerceSettings::draftTtlDays($this->context));

        $this->seedDraft('draftfresh01', $this->daysBeforeNow(29));
        $this->seedDraft('draftstale01', $this->daysBeforeNow(31));

        self::assertSame(1, $this->service()->cancelStale($this->context, $this->now()));

        self::assertSame('draft', $this->statusOf('draftfresh01'));
        self::assertSame('canceled', $this->statusOf('draftstale01'));
    }

    /**
     * The EXACT boundary (review fix): a draft aged precisely `draft_ttl_days`
     * SURVIVES. The comparison is strict (`<`), so "exactly 30 days old" is not
     * yet "older than 30 days" -- the boundary always resolves in the
     * operator's favor, and the draft is swept on a later tick instead.
     */
    public function testADraftAgedExactlyTheTtlSurvives(): void
    {
        $this->seedDraft('draftexact01', $this->daysBeforeNow(30));

        self::assertSame(0, $this->service()->cancelStale($this->context, $this->now()));
        self::assertSame('draft', $this->statusOf('draftexact01'));
        self::assertSame([], $this->eventTypesFor('draftexact01'));

        // One second past the boundary IS stale -- proving the survival above
        // is the strict comparison, not an off-by-a-day cutoff.
        self::assertSame(
            1,
            $this->service()->cancelStale($this->context, $this->now()->modify('+1 second'))
        );
        self::assertSame('canceled', $this->statusOf('draftexact01'));
    }

    public function testTheTtlWindowIsRuntimeConfigurable(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['orders' => ['draft_ttl_days' => 7]]);
        self::assertSame(7, CommerceSettings::draftTtlDays($this->context));

        $this->seedDraft('draftfresh02', $this->daysBeforeNow(6));
        $this->seedDraft('draftstale02', $this->daysBeforeNow(8));

        self::assertSame(1, $this->service()->cancelStale($this->context, $this->now()));

        self::assertSame('draft', $this->statusOf('draftfresh02'));
        self::assertSame('canceled', $this->statusOf('draftstale02'));
    }

    /**
     * Staleness is measured from the LAST touch (`updated_at`, falling back to
     * `created_at`): an old draft that an operator edited yesterday is not
     * stale, and must survive.
     */
    public function testAnEditedDraftIsMeasuredFromItsLastTouchNotItsCreation(): void
    {
        $this->seedDraft('drafttouch01', $this->daysBeforeNow(90), $this->daysBeforeNow(1));
        $this->seedDraft('drafttouch02', $this->daysBeforeNow(90), $this->daysBeforeNow(60));

        self::assertSame(1, $this->service()->cancelStale($this->context, $this->now()));

        self::assertSame('draft', $this->statusOf('drafttouch01'));
        self::assertSame('canceled', $this->statusOf('drafttouch02'));
    }

    public function testCleanupIsBoundedByBatchSizeAndSuccessiveRunsDrain(): void
    {
        $batchSize = 3;
        $total = ($batchSize * 2) + 1;
        for ($i = 0; $i < $total; $i++) {
            $this->seedDraft(sprintf('draftbatch%02d', $i), $this->daysBeforeNow(45));
        }

        $service = $this->service();
        self::assertSame(3, $service->cancelStale($this->context, $this->now(), $batchSize));
        self::assertSame(3, $service->cancelStale($this->context, $this->now(), $batchSize));
        self::assertSame(1, $service->cancelStale($this->context, $this->now(), $batchSize));
        // Drained: a fourth run is a clean no-op.
        self::assertSame(0, $service->cancelStale($this->context, $this->now(), $batchSize));

        self::assertSame(
            0,
            $this->connection->table('commerce_orders')->where('status', '=', 'draft')->count()
        );
        self::assertSame(
            $total,
            $this->connection->table('commerce_orders')->where('status', '=', 'canceled')->count()
        );
    }

    public function testRerunningTheCleanupIsIdempotent(): void
    {
        $this->seedDraft('draftidem001', $this->daysBeforeNow(45));

        $service = $this->service();
        self::assertSame(1, $service->cancelStale($this->context, $this->now()));
        self::assertSame(0, $service->cancelStale($this->context, $this->now()));
        self::assertSame(0, $service->cancelStale($this->context, $this->now()));

        // Exactly one audit row -- a rerun never re-records.
        self::assertSame([DraftOrderEvents::EXPIRED], $this->eventTypesFor('draftidem001'));
    }

    public function testCleanupRecordsAuditRowsOnlyAndHasZeroLifecycleSideEffects(): void
    {
        $captured = $this->bindEventCapture();
        $mailer = new RecordingCommerceMailer();
        $this->bind(CommerceMailer::class, $mailer);

        $this->seedDraft('draftaudit01', $this->daysBeforeNow(45));

        self::assertSame(1, $this->service()->cancelStale($this->context, $this->now()));

        self::assertSame([DraftOrderEvents::EXPIRED], $this->eventTypesFor('draftaudit01'));
        self::assertSame([], $captured->events);
        self::assertSame([], $mailer->calls);
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_webhook_events')->count()
        );
    }

    public function testCleanupNeverTouchesAnythingThatIsNotADraft(): void
    {
        $this->seedOrder('finalpend001', 'pending_payment', $this->daysBeforeNow(400));
        $this->seedOrder('finalpaid001', 'paid', $this->daysBeforeNow(400));
        $this->seedOrder('finalcanc001', 'canceled', $this->daysBeforeNow(400));
        $this->seedDraft('draftonly001', $this->daysBeforeNow(400));

        self::assertSame(1, $this->service()->cancelStale($this->context, $this->now()));

        self::assertSame('pending_payment', $this->statusOf('finalpend001'));
        self::assertSame('paid', $this->statusOf('finalpaid001'));
        self::assertSame('canceled', $this->statusOf('finalcanc001'));
        self::assertSame('canceled', $this->statusOf('draftonly001'));
        self::assertSame([], $this->eventTypesFor('finalpend001'));
    }

    public function testCleanupIsTenantScoped(): void
    {
        $this->seedDraft('draftmine001', $this->daysBeforeNow(45));
        $this->seedDraft('draftother01', $this->daysBeforeNow(45), null, 'othertenant1');

        self::assertSame(1, $this->service()->cancelStale($this->context, $this->now()));

        self::assertSame('canceled', $this->statusOf('draftmine001'));
        self::assertSame('draft', $this->statusOf('draftother01'));
    }

    /**
     * The shared cancellation mechanic Task 9's explicit-cancel endpoint reuses:
     * the same idempotent CAS, recording `draft_canceled` instead of
     * `draft_expired`, and refusing anything that is no longer a draft.
     */
    public function testTheExplicitCancelMechanicIsTheSameIdempotentCas(): void
    {
        $this->seedDraft('draftexpl001', $this->daysBeforeNow(1));
        $service = $this->service();

        self::assertTrue($service->cancelDraft(
            $this->context,
            self::TENANT,
            'draftexpl001',
            DraftOrderEvents::CANCELED,
            'operator0001'
        ));
        self::assertFalse($service->cancelDraft(
            $this->context,
            self::TENANT,
            'draftexpl001',
            DraftOrderEvents::CANCELED,
            'operator0001'
        ));

        self::assertSame('canceled', $this->statusOf('draftexpl001'));
        self::assertSame([DraftOrderEvents::CANCELED], $this->eventTypesFor('draftexpl001'));

        $event = $this->connection->table('commerce_order_events')
            ->where('order_uuid', '=', 'draftexpl001')
            ->first();
        self::assertIsArray($event);
        self::assertSame('operator0001', $event['actor_uuid']);
        self::assertSame('internal', $event['visibility']);
    }

    public function testTheExplicitCancelMechanicRefusesANonDraft(): void
    {
        $this->seedOrder('finalpend002', 'pending_payment', $this->daysBeforeNow(1));

        self::assertFalse($this->service()->cancelDraft(
            $this->context,
            self::TENANT,
            'finalpend002',
            DraftOrderEvents::CANCELED
        ));
        self::assertSame('pending_payment', $this->statusOf('finalpend002'));
    }

    public function testTheDraftAuditEventNamesAreTheDocumentedOnes(): void
    {
        self::assertSame('draft_created', DraftOrderEvents::CREATED);
        self::assertSame('draft_canceled', DraftOrderEvents::CANCELED);
        self::assertSame('draft_expired', DraftOrderEvents::EXPIRED);
    }

    public function testTheExpiryCronCommandAlsoDrivesDraftCleanup(): void
    {
        $ancient = gmdate('Y-m-d H:i:s', time() - (400 * 86400));
        $this->seedDraft('draftcron001', $ancient);
        $this->seedOrder('finalpend003', 'pending_payment', $ancient);

        $this->bind(ExpiryService::class, new ExpiryService(
            new OrderRepository(),
            new StockRepository(),
            new SentinelTenantResolver()
        ));
        $this->bind(DraftCleanupService::class, $this->service());

        $tester = new CommandTester(new OrdersExpireCommand($this->context->getContainer(), $this->context));
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Expired 1 order(s).', $display);
        self::assertStringContainsString('Canceled 1 stale draft order(s).', $display);
        self::assertSame('canceled', $this->statusOf('draftcron001'));
        self::assertSame([DraftOrderEvents::EXPIRED], $this->eventTypesFor('draftcron001'));
    }

    /**
     * Sweep ISOLATION (review fix): the two sweeps share a schedule and nothing
     * else. A throwing order-expiry sweep must NOT suspend draft cleanup --
     * otherwise an unrelated outage would let drafts accumulate unbounded for
     * as long as it lasted. The failure is still surfaced (error line + FAILURE
     * exit) so cron alerting fires; it just doesn't take the sibling with it.
     */
    public function testAThrowingExpirySweepStillLetsTheDraftSweepRun(): void
    {
        $ancient = gmdate('Y-m-d H:i:s', time() - (400 * 86400));
        $this->seedDraft('draftcron002', $ancient);

        // ExpiryService is final, so this is a duck-typed stand-in rather than a
        // subclass -- the command resolves it through the container and only
        // ever calls `expireStale()`.
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
        // The sibling sweep ran to completion regardless.
        self::assertStringContainsString('Canceled 1 stale draft order(s).', $display);
        self::assertSame('canceled', $this->statusOf('draftcron002'));
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function service(): DraftCleanupService
    {
        return new DraftCleanupService(new OrderRepository(), new SentinelTenantResolver());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }

    private function daysBeforeNow(int $days): string
    {
        return $this->now()->modify("-{$days} days")->format('Y-m-d H:i:s');
    }

    private function seedDraft(
        string $uuid,
        string $createdAt,
        ?string $updatedAt = null,
        string $tenant = self::TENANT
    ): void {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => null,
            'status' => 'draft',
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

    private function statusOf(string $uuid): string
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $uuid)->first();
        self::assertIsArray($row);

        return (string) $row['status'];
    }

    /** @return list<string> */
    private function eventTypesFor(string $uuid): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['type'],
            $this->connection->table('commerce_order_events')
                ->where('order_uuid', '=', $uuid)
                ->orderBy('id', 'ASC')
                ->get()
        );
    }

    /**
     * Captures EVERY commerce order/refund lifecycle event the mail listener,
     * webhook fan-out, and marketplace listeners key off. The draft path must
     * dispatch none of them.
     */
    private function bindEventCapture(): object
    {
        $capture = new class {
            /** @var list<object> */
            public array $events = [];
        };
        $listeners = new ListenerProvider();
        $eventService = new EventService(new EventDispatcher($listeners), $listeners);
        foreach (
            [
                OrderPlaced::class,
                OrderPaid::class,
                OrderCanceled::class,
                OrderFulfilled::class,
                RefundCompleted::class,
            ] as $eventClass
        ) {
            $eventService->addListener($eventClass, function (object $e) use ($capture): void {
                $capture->events[] = $e;
            });
        }
        $this->bind(EventService::class, $eventService);

        return $capture;
    }
}
