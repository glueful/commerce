<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Console;

use Glueful\Extensions\Commerce\Console\DownloadsBackfillCommand;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Helpers\Utils;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `commerce:downloads:backfill` (design spec §3, recovery surface 3 of 3): operator
 * bulk repair over every paid/fulfilled/refunded order. `--dry-run` and a real run
 * share {@see DownloadGrantService::previewForOrder()}, so this suite asserts the
 * counts are identical between the two modes for the same fixture.
 */
final class DownloadsBackfillCommandTest extends CommerceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bind(
            DownloadGrantService::class,
            new DownloadGrantService(new OrderRepository(), new DownloadGrantRepository())
        );
    }

    public function testDryRunReportsCountsWithoutWritingAnyGrant(): void
    {
        $this->seedOrder('orderbf00001', 'paid');
        $this->seedOrderLine('orderbf00001', [
            $this->snapshotEntry('dlbf000001', 'blobbf0000001', 'File.pdf'),
            $this->snapshotEntry('dlbf000002', 'blobbf0000002', 'File2.pdf'),
        ]);

        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Dry run complete: 2 created, 0 skipped, 1 order(s) scanned.', $tester->getDisplay());
        self::assertSame(0, $this->connection->table('commerce_download_grants')->count());
    }

    public function testRealRunCreatesMissingGrantsAndReportsIdenticalCountsToDryRun(): void
    {
        $this->seedOrder('orderbf00002', 'paid');
        $this->seedOrderLine('orderbf00002', [
            $this->snapshotEntry('dlbf000003', 'blobbf0000003', 'File3.pdf'),
            $this->snapshotEntry('dlbf000004', 'blobbf0000004', 'File4.pdf'),
        ]);

        $tester = new CommandTester($this->command());
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Backfill complete: 2 created, 0 skipped, 1 order(s) scanned.', $tester->getDisplay());
        self::assertSame(2, $this->connection->table('commerce_download_grants')->count());
    }

    public function testSecondRunReportsEverythingAlreadySkippedAndCreatesNoDuplicates(): void
    {
        $this->seedOrder('orderbf00003', 'paid');
        $this->seedOrderLine('orderbf00003', [
            $this->snapshotEntry('dlbf000005', 'blobbf0000005', 'File5.pdf'),
        ]);

        (new CommandTester($this->command()))->execute([]);
        self::assertSame(1, $this->connection->table('commerce_download_grants')->count());

        $tester = new CommandTester($this->command());
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Backfill complete: 0 created, 1 skipped, 1 order(s) scanned.', $tester->getDisplay());
        self::assertSame(1, $this->connection->table('commerce_download_grants')->count());
    }

    public function testTenantOptionNarrowsProcessingToOneTenant(): void
    {
        $this->seedOrder('orderbfA0001', 'paid', tenant: 'tenantAAAA01');
        $this->seedOrderLine('orderbfA0001', [
            $this->snapshotEntry('dlbfA000001', 'blobbfA000001', 'A.pdf'),
        ]);
        $this->seedOrder('orderbfB0001', 'paid', tenant: 'tenantBBBB02');
        $this->seedOrderLine('orderbfB0001', [
            $this->snapshotEntry('dlbfB000001', 'blobbfB000001', 'B.pdf'),
        ]);

        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['--tenant' => 'tenantAAAA01']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('1 created', $tester->getDisplay());
        self::assertSame(
            1,
            $this->connection->table('commerce_download_grants')
                ->where('tenant_uuid', '=', 'tenantAAAA01')->count()
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_download_grants')
                ->where('tenant_uuid', '=', 'tenantBBBB02')->count()
        );
    }

    public function testUnqualifyingOrdersAreNeverScanned(): void
    {
        $this->seedOrder('orderbf00004', 'pending_payment');
        $this->seedOrderLine('orderbf00004', [
            $this->snapshotEntry('dlbf000006', 'blobbf0000006', 'File6.pdf'),
        ]);

        $tester = new CommandTester($this->command());
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No qualifying orders found.', $tester->getDisplay());
        self::assertSame(0, $this->connection->table('commerce_download_grants')->count());
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function command(): DownloadsBackfillCommand
    {
        return new DownloadsBackfillCommand($this->context->getContainer(), $this->context);
    }

    private function seedOrder(string $uuid, string $status, string $tenant = ''): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
    }

    /** @param list<array<string,mixed>> $downloads */
    private function seedOrderLine(string $orderUuid, array $downloads): void
    {
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => Utils::generateNanoID(),
            'order_uuid' => $orderUuid,
            'variant_uuid' => 'var000000001',
            'product_name' => 'Digital Item',
            'sku' => 'SKU-' . $orderUuid,
            'option_values' => '[]',
            'unit_price' => 500,
            'quantity' => 1,
            'line_total' => 500,
            'downloads' => json_encode($downloads, JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return array<string,mixed> */
    private function snapshotEntry(string $downloadUuid, string $blobUuid, string $name): array
    {
        return [
            'download_uuid' => $downloadUuid,
            'blob_uuid' => $blobUuid,
            'name' => $name,
            'download_limit' => null,
            'expiry_days' => null,
        ];
    }
}
