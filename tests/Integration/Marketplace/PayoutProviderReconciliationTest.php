<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\ReconciliationService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * `ReconciliationService::scanPayouts()`'s MV4 Task 9 extension (design spec
 * §2.3/§2.4/§2.6/§2.8): a `paid`/`reversed` row still expects its original
 * `payout_debit = -amount`; a `reversed` row (or a partially-reversed still-`paid` row)
 * additionally requires cumulative `payout_reversal` postings summing to `reversed_total`,
 * and a full reversal requires `reversed_total == amount`. An in-flight `pending` hold or a
 * `failed` row is never flagged for a "missing" debit it was never supposed to (yet) have.
 * Provider-side truth is NEVER consulted by this class -- it is a pure ledger-vs-row scan,
 * and it never reads or names payvia's own `payvia_transfers` table.
 */
final class PayoutProviderReconciliationTest extends CommerceTestCase
{
    private const TENANT = '';
    private const SELLER = 'sellerPRCN0001';

    private ReconciliationService $reconciliation;
    private int $ledgerSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconciliation = new ReconciliationService();
    }

    /** @return array{missing: list<array<string,mixed>>, duplicate: list<array<string,mixed>>, mismatched: list<array<string,mixed>>} */
    private function scan(): array
    {
        return $this->reconciliation->scan($this->context, self::TENANT);
    }

    private function seedPayout(string $uuid, string $status, int $amount, int $reversedTotal = 0): void
    {
        $this->connection->table('commerce_payouts')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => self::SELLER,
            'currency' => 'USD',
            'amount' => $amount,
            'idempotency_key' => $uuid,
            'status' => $status,
            'method' => 'provider',
            'provider' => 'default',
            'destination_ref' => 'acct-recon',
            'retryable' => $status === 'failed',
            'attempt_count' => 1,
            'reversed_total' => $reversedTotal,
        ]);
    }

    private function postLedgerEntry(string $payoutUuid, string $entryType, int $amount): void
    {
        $this->ledgerSeq++;
        $this->connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => 'ledgerPRCN' . str_pad((string) $this->ledgerSeq, 4, '0', STR_PAD_LEFT),
            'tenant_uuid' => self::TENANT,
            'account_key' => 'seller:' . self::SELLER,
            'account_kind' => 'seller',
            'seller_uuid' => self::SELLER,
            'currency' => 'USD',
            'entry_type' => $entryType,
            'amount' => $amount,
            'payout_uuid' => $payoutUuid,
            'idempotency_key' => $payoutUuid . ':' . $entryType . ':' . $this->ledgerSeq,
        ]);
    }

    // -----------------------------------------------------------------
    // A reversed row missing its cumulative payout_reversal.
    // -----------------------------------------------------------------

    public function testFlagsAReversedRowMissingItsCumulativePayoutReversal(): void
    {
        $this->seedPayout('payoutPRCNRV1', 'reversed', 1000, reversedTotal: 1000);
        $this->postLedgerEntry('payoutPRCNRV1', 'payout_debit', -1000);
        // No payout_reversal posted at all -- reversed_total says 1000 was reversed, but the
        // ledger disagrees.

        $report = $this->scan();

        self::assertSame([], $report['duplicate']);
        self::assertSame([], $report['mismatched']);
        self::assertCount(1, $report['missing']);
        $finding = $report['missing'][0];
        self::assertSame('payout', $finding['source']);
        self::assertSame('payoutPRCNRV1', $finding['payout_uuid']);
        self::assertSame('payout_reversal', $finding['entry_type']);
        self::assertSame(1000, $finding['expected_amount']);
    }

    // -----------------------------------------------------------------
    // A coherent paid row (debit present, no reversal expected) is accepted.
    // -----------------------------------------------------------------

    public function testAcceptsACoherentPaidRowWithItsDebitPresent(): void
    {
        $this->seedPayout('payoutPRCNPD1', 'paid', 750);
        $this->postLedgerEntry('payoutPRCNPD1', 'payout_debit', -750);

        self::assertSame(['missing' => [], 'duplicate' => [], 'mismatched' => []], $this->scan());
    }

    // -----------------------------------------------------------------
    // An in-flight pending hold (no debit yet) is not a coherence violation.
    // -----------------------------------------------------------------

    public function testAcceptsAnInFlightPendingHoldAsNonViolation(): void
    {
        $this->seedPayout('payoutPRCNPN1', 'pending', 400);
        $this->postLedgerEntry('payoutPRCNPN1', 'reserve_hold', -400);
        // Deliberately no payout_debit -- the payout hasn't actually paid out yet.

        self::assertSame(['missing' => [], 'duplicate' => [], 'mismatched' => []], $this->scan());
    }

    // -----------------------------------------------------------------
    // A failed (retryable or terminal) row is likewise never flagged for a missing debit.
    // -----------------------------------------------------------------

    public function testAcceptsAFailedRowWithoutADebitAsNonViolation(): void
    {
        $this->seedPayout('payoutPRCNFL1', 'failed', 250);
        $this->postLedgerEntry('payoutPRCNFL1', 'reserve_hold', -250);
        $this->postLedgerEntry('payoutPRCNFL1', 'reserve_release', 250);

        self::assertSame(['missing' => [], 'duplicate' => [], 'mismatched' => []], $this->scan());
    }

    // -----------------------------------------------------------------
    // A coherent PARTIAL reversal (still status=paid) is accepted when the cumulative sum
    // matches reversed_total.
    // -----------------------------------------------------------------

    public function testAcceptsACoherentPartialReversalStillStatusPaid(): void
    {
        $this->seedPayout('payoutPRCNPR1', 'paid', 1000, reversedTotal: 300);
        $this->postLedgerEntry('payoutPRCNPR1', 'payout_debit', -1000);
        $this->postLedgerEntry('payoutPRCNPR1', 'payout_reversal', 300);

        self::assertSame(['missing' => [], 'duplicate' => [], 'mismatched' => []], $this->scan());
    }

    public function testFlagsAMismatchedCumulativePayoutReversalSum(): void
    {
        $this->seedPayout('payoutPRCNMM1', 'paid', 1000, reversedTotal: 500);
        $this->postLedgerEntry('payoutPRCNMM1', 'payout_debit', -1000);
        // Two reversal postings summing to only 400, but reversed_total claims 500.
        $this->postLedgerEntry('payoutPRCNMM1', 'payout_reversal', 200);
        $this->postLedgerEntry('payoutPRCNMM1', 'payout_reversal', 200);

        $report = $this->scan();

        self::assertSame([], $report['missing']);
        self::assertSame([], $report['duplicate']);
        self::assertCount(1, $report['mismatched']);
        $finding = $report['mismatched'][0];
        self::assertSame('payout_reversal', $finding['entry_type']);
        self::assertSame(500, $finding['expected_amount']);
        self::assertSame(400, $finding['found_amount']);
    }

    // -----------------------------------------------------------------
    // A `reversed` row whose reversed_total != amount is flagged (full-reversal invariant).
    // -----------------------------------------------------------------

    public function testFlagsAReversedRowWhoseReversedTotalDoesNotEqualAmount(): void
    {
        $this->seedPayout('payoutPRCNFR1', 'reversed', 1000, reversedTotal: 600);
        $this->postLedgerEntry('payoutPRCNFR1', 'payout_debit', -1000);
        $this->postLedgerEntry('payoutPRCNFR1', 'payout_reversal', 600);

        $report = $this->scan();

        self::assertSame([], $report['missing']);
        self::assertSame([], $report['duplicate']);
        $reversedTotalFindings = array_values(array_filter(
            $report['mismatched'],
            static fn (array $f): bool => $f['entry_type'] === 'reversed_total'
        ));
        self::assertCount(1, $reversedTotalFindings);
        self::assertSame(1000, $reversedTotalFindings[0]['expected_amount']);
        self::assertSame(600, $reversedTotalFindings[0]['found_amount']);
    }

    // -----------------------------------------------------------------
    // Contract-only coupling: this class never reads/names payvia_transfers.
    // -----------------------------------------------------------------

    public function testReconciliationServiceNeverReferencesPayviaTransfers(): void
    {
        $path = (new \ReflectionClass(ReconciliationService::class))->getFileName();
        self::assertIsString($path);
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringNotContainsString(
            'payvia_transfers',
            $source,
            'ReconciliationService must source provider truth ONLY via PayoutCollector::status(), '
                . 'never by reading payvia-owned tables.'
        );
    }
}
