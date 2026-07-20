<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\AdjustmentException;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentService;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerException;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * `AdjustmentService::post()` (design spec §2.10, MV3 Task 9): a non-zero
 * signed `adjustment` entry with a mandatory reason/actor/idempotency key,
 * claiming the account lock but performing NO available-balance check
 * (adjustments are corrections and may legitimately drive a balance
 * negative). Rows are append-only -- a correction is always a NEW,
 * opposite-signed entry.
 */
final class AdjustmentTest extends CommerceTestCase
{
    private const TENANT = '';
    private const SELLER = 'sellerADJUST01';
    private const ACTOR = 'operatorADJUS1';

    private LedgerRepository $ledger;
    private AdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new LedgerRepository();
        $this->service = new AdjustmentService($this->ledger, new LedgerAccountLock());
    }

    private function accountKey(): string
    {
        return LedgerRepository::accountKeyForSeller(self::SELLER);
    }

    // -----------------------------------------------------------------
    // 1. Positive + negative signed entries, seller and marketplace accounts.
    // -----------------------------------------------------------------

    public function testPostsAPositiveSignedAdjustmentWithReasonAndActor(): void
    {
        $this->service->post(
            $this->context,
            self::TENANT,
            $this->accountKey(),
            'USD',
            500,
            'goodwill credit',
            'idem-adj-1',
            self::ACTOR
        );

        $row = $this->connection->table('commerce_marketplace_ledger')
            ->where('account_key', '=', $this->accountKey())
            ->where('entry_type', '=', 'adjustment')
            ->first();

        self::assertNotNull($row);
        self::assertSame(500, (int) $row['amount']);
        self::assertSame('goodwill credit', $row['reason']);
        self::assertSame(self::ACTOR, $row['created_by']);
        self::assertSame('seller', $row['account_kind']);
        self::assertSame(self::SELLER, $row['seller_uuid']);
        self::assertSame('adjustment:' . $this->accountKey() . ':idem-adj-1', $row['idempotency_key']);

        $balance = new SellerBalanceService($this->ledger);
        self::assertSame(500, $balance->available($this->context, self::TENANT, self::SELLER, 'USD'));
    }

    public function testPostsANegativeSignedAdjustment(): void
    {
        $this->service->post(
            $this->context,
            self::TENANT,
            $this->accountKey(),
            'USD',
            -300,
            'overpayment clawback',
            'idem-adj-2',
            self::ACTOR
        );

        $balance = new SellerBalanceService($this->ledger);
        self::assertSame(-300, $balance->available($this->context, self::TENANT, self::SELLER, 'USD'));
    }

    public function testPostsAnAdjustmentAgainstTheMarketplaceAccount(): void
    {
        $this->service->post(
            $this->context,
            self::TENANT,
            LedgerRepository::MARKETPLACE_ACCOUNT_KEY,
            'USD',
            200,
            'marketplace correction',
            'idem-adj-mp',
            self::ACTOR
        );

        $row = $this->connection->table('commerce_marketplace_ledger')
            ->where('account_key', '=', LedgerRepository::MARKETPLACE_ACCOUNT_KEY)
            ->first();

        self::assertNotNull($row);
        self::assertSame('marketplace', $row['account_kind']);
        self::assertNull($row['seller_uuid']);

        $balance = new SellerBalanceService($this->ledger);
        self::assertSame(
            200,
            $balance->marketplaceBalance($this->context, self::TENANT, 'USD')['available']
        );
    }

    // -----------------------------------------------------------------
    // 2. Validation.
    // -----------------------------------------------------------------

    public function testRejectsAZeroAmount(): void
    {
        $this->expectException(AdjustmentException::class);
        $this->service->post(
            $this->context,
            self::TENANT,
            $this->accountKey(),
            'USD',
            0,
            'reason',
            'idem-zero',
            self::ACTOR
        );
    }

    public function testRejectsAnEmptyReason(): void
    {
        $this->expectException(AdjustmentException::class);
        $this->service->post(
            $this->context,
            self::TENANT,
            $this->accountKey(),
            'USD',
            100,
            '',
            'idem-reason',
            self::ACTOR
        );
    }

    public function testRejectsAnEmptyActor(): void
    {
        $this->expectException(AdjustmentException::class);
        $this->service->post(
            $this->context,
            self::TENANT,
            $this->accountKey(),
            'USD',
            100,
            'reason',
            'idem-actor',
            ''
        );
    }

    public function testRejectsAnUnrecognizedAccountKey(): void
    {
        $this->expectException(AdjustmentException::class);
        $this->service->post(
            $this->context,
            self::TENANT,
            'not-a-real-account-key',
            'USD',
            100,
            'reason',
            'idem-unrec',
            self::ACTOR
        );
    }

    public function testValidationFailuresPostNothing(): void
    {
        foreach ([
            [0, 'reason', self::ACTOR],
            [100, '', self::ACTOR],
            [100, 'reason', ''],
        ] as $i => [$amount, $reason, $actor]) {
            try {
                $this->service->post(
                    $this->context,
                    self::TENANT,
                    $this->accountKey(),
                    'USD',
                    $amount,
                    $reason,
                    'idem-novalid-' . $i,
                    $actor
                );
                self::fail('Expected an AdjustmentException.');
            } catch (AdjustmentException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('entry_type', '=', 'adjustment')->count()
        );
    }

    // -----------------------------------------------------------------
    // 3. May drive the balance negative.
    // -----------------------------------------------------------------

    public function testMayDriveTheBalanceNegative(): void
    {
        $this->service->post(
            $this->context,
            self::TENANT,
            $this->accountKey(),
            'USD',
            100,
            'small credit',
            'idem-neg-1',
            self::ACTOR
        );
        $this->service->post(
            $this->context,
            self::TENANT,
            $this->accountKey(),
            'USD',
            -5000,
            'large clawback',
            'idem-neg-2',
            self::ACTOR
        );

        $balance = new SellerBalanceService($this->ledger);
        self::assertSame(-4900, $balance->available($this->context, self::TENANT, self::SELLER, 'USD'));
    }

    // -----------------------------------------------------------------
    // 4. Idempotent replay: matching no-ops, mismatched integrity-fails.
    // -----------------------------------------------------------------

    public function testMatchingReplayNoOpsAfterVerification(): void
    {
        $this->service->post(
            $this->context,
            self::TENANT,
            $this->accountKey(),
            'USD',
            500,
            'same reason',
            'idem-dup-1',
            self::ACTOR
        );
        $this->service->post(
            $this->context,
            self::TENANT,
            $this->accountKey(),
            'USD',
            500,
            'same reason',
            'idem-dup-1',
            self::ACTOR
        );

        self::assertSame(
            1,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('idempotency_key', '=', 'adjustment:' . $this->accountKey() . ':idem-dup-1')
                ->count()
        );

        $balance = new SellerBalanceService($this->ledger);
        self::assertSame(500, $balance->available($this->context, self::TENANT, self::SELLER, 'USD'));
    }

    public function testMismatchedReplayIsAnIntegrityFailure(): void
    {
        $this->service->post(
            $this->context,
            self::TENANT,
            $this->accountKey(),
            'USD',
            500,
            'first reason',
            'idem-dup-2',
            self::ACTOR
        );

        $this->expectException(LedgerException::class);
        $this->service->post(
            $this->context,
            self::TENANT,
            $this->accountKey(),
            'USD',
            600,
            'first reason',
            'idem-dup-2',
            self::ACTOR
        );
    }

    // -----------------------------------------------------------------
    // 5. Append-only: a correction is a NEW compensating entry, never an edit.
    // -----------------------------------------------------------------

    public function testACorrectionIsANewCompensatingEntryNeverAnEditOfThePriorOne(): void
    {
        $this->service->post(
            $this->context,
            self::TENANT,
            $this->accountKey(),
            'USD',
            1000,
            'original credit',
            'idem-orig',
            self::ACTOR
        );
        $this->service->post(
            $this->context,
            self::TENANT,
            $this->accountKey(),
            'USD',
            -1000,
            'compensating reversal of idem-orig',
            'idem-correction',
            self::ACTOR
        );

        self::assertSame(
            2,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('account_key', '=', $this->accountKey())
                ->where('entry_type', '=', 'adjustment')
                ->count(),
            'both the original and the compensating entry must exist -- the original is never edited/deleted'
        );

        $balance = new SellerBalanceService($this->ledger);
        self::assertSame(0, $balance->available($this->context, self::TENANT, self::SELLER, 'USD'));
    }

    // -----------------------------------------------------------------
    // 6. The account lock is claimed within the adjustment transaction.
    // -----------------------------------------------------------------

    public function testTheAccountLockIsClaimedWithinTheAdjustmentTransaction(): void
    {
        self::assertSame(
            0,
            $this->connection->table('commerce_ledger_account_locks')
                ->where('account_key', '=', $this->accountKey())
                ->count(),
            'sanity: no lock row exists before any adjustment'
        );

        $this->service->post(
            $this->context,
            self::TENANT,
            $this->accountKey(),
            'USD',
            250,
            'lock proof',
            'idem-lock-1',
            self::ACTOR
        );

        $lock = $this->connection->table('commerce_ledger_account_locks')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('account_key', '=', $this->accountKey())
            ->where('currency', '=', 'USD')
            ->first();

        self::assertNotNull($lock, 'the account lock must be claimed inside the adjustment transaction');
        self::assertGreaterThanOrEqual(1, (int) $lock['revision']);
    }
}
