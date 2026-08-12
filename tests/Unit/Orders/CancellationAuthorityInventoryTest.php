<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Orders;

use Glueful\Extensions\Commerce\Orders\PaymentSessionExposureGuard;
use PHPUnit\Framework\TestCase;

/**
 * THE CANCELLATION-AUTHORITY INVENTORY (payment-links Task 8, design spec §2.2:
 * "one shared `PaymentSessionExposureGuard` is the authority for every non-draft
 * cancellation path ... an inventory test pins that set ... future final-order
 * cancellation authorities fail the inventory test until wired").
 *
 * This is a RATCHET, not a proof. It walks `src/` for every site that moves a
 * `commerce_orders` row to `canceled` -- through
 * {@see \Glueful\Extensions\Commerce\Orders\OrderRepository::transition()} or
 * through a raw `UPDATE commerce_orders SET status = 'canceled'` -- and requires
 * that:
 *
 *  1. the discovered set equals the PINNED set exactly (a new authority appears
 *     ⇒ this test fails until someone re-reviews it), and
 *  2. every discovered NON-DRAFT authority names
 *     {@see PaymentSessionExposureGuard} in its own source (a new authority that
 *     was added but not wired ⇒ this test fails), and
 *  3. the ONE exempt authority ({@see \Glueful\Extensions\Commerce\Orders\DraftCleanupService})
 *     is still draft-scoped in its own SQL, so the exemption cannot silently
 *     widen into a final-order cancellation path.
 *
 * A determined author can still evade a source scan (an indirected status
 * string, a new table alias). That is accepted: the point is that the ORDINARY
 * ways of adding a cancellation path all trip this, and the fix is always the
 * same one line -- take the guard.
 */
final class CancellationAuthorityInventoryTest extends TestCase
{
    /**
     * Every source file allowed to cancel a `commerce_orders` row today, and
     * why. Keys are repo-relative paths under `src/`.
     *
     * @var array<string,string>
     */
    private const PINNED_AUTHORITIES = [
        // Sorted, because discovery is: an alphabetical set is a set, not an order.
        'Http/Admin/AdminOrderController.php' => 'operator cancel (design spec §2.2: guarded)',
        'Orders/DraftCleanupService.php' => 'DRAFT-only sweep (drafts carry no payment links)',
        'Orders/ExpiryService.php' => 'automatic sweep (design spec §2.2: guarded)',
    ];

    /** The one authority the guard does NOT apply to, because it can only reach drafts. */
    private const DRAFT_ONLY = 'Orders/DraftCleanupService.php';

    public function testTheSetOfOrderCancellationAuthoritiesIsExactlyThePinnedOne(): void
    {
        self::assertSame(
            array_keys(self::PINNED_AUTHORITIES),
            $this->discoverCancellationAuthorities(),
            'a new commerce_orders cancellation authority appeared: wire it to '
            . 'PaymentSessionExposureGuard (design spec §2.2) and pin it here',
        );
    }

    public function testEveryNonDraftCancellationAuthorityTakesTheExposureGuard(): void
    {
        foreach ($this->discoverCancellationAuthorities() as $relative) {
            if ($relative === self::DRAFT_ONLY) {
                continue;
            }

            self::assertStringContainsString(
                'PaymentSessionExposureGuard',
                $this->source($relative),
                "{$relative} cancels orders without consulting the exposure guard",
            );
        }
    }

    public function testTheDraftOnlyExemptionIsStillDraftScopedInItsOwnSql(): void
    {
        $source = $this->source(self::DRAFT_ONLY);

        self::assertStringContainsString('OrderScope::isDraftSql()', $source);
        self::assertStringContainsString('AND {$isDraft}', $source);
        self::assertStringNotContainsString('PaymentLinkRepository', $source);
    }

    /**
     * Both guarded authorities must default-construct a guard when a caller
     * omits it, so an appended-optional collaborator can never leave a
     * cancellation path silently unguarded.
     */
    public function testTheGuardCannotBeOmittedByAConstructorCallSite(): void
    {
        foreach (['Http/Admin/AdminOrderController.php', 'Orders/ExpiryService.php'] as $relative) {
            self::assertStringContainsString(
                'new PaymentSessionExposureGuard(',
                $this->source($relative),
                "{$relative} must fall back to a real guard rather than to null",
            );
        }
    }

    public function testTheGuardPublishesTheAcknowledgementVocabularyTheSpecPins(): void
    {
        self::assertSame('accept_late_payment_risk', PaymentSessionExposureGuard::ACKNOWLEDGEMENT_FIELD);
        self::assertSame('payment_session_risk_accepted', PaymentSessionExposureGuard::RISK_ACCEPTED_EVENT);
    }

    /** @return list<string> repo-relative paths, sorted */
    private function discoverCancellationAuthorities(): array
    {
        $found = [];
        foreach ($this->sourceFiles() as $absolute) {
            $source = (string) file_get_contents($absolute);
            if (!$this->cancelsAnOrder($source)) {
                continue;
            }

            $found[] = str_replace($this->srcRoot() . '/', '', $absolute);
        }

        sort($found);

        return $found;
    }

    private function cancelsAnOrder(string $source): bool
    {
        // 1. OrderRepository::transition(..., 'canceled') -- the ordinary path.
        //    `commerce_seller_orders` has no `transition()` of its own, so this
        //    cannot match a child-order cancellation.
        if (preg_match('/->transition\(\s*[^;]{0,200}?,\s*\'canceled\'/s', $source) === 1) {
            return true;
        }

        // 2. A raw UPDATE against commerce_orders that sets `canceled`.
        return preg_match(
            '/UPDATE\s+commerce_orders\b[\s\S]{0,200}?SET\s+status\s*=\s*\'canceled\'/i',
            $source
        ) === 1;
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->srcRoot(), \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function source(string $relative): string
    {
        $path = $this->srcRoot() . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function srcRoot(): string
    {
        return dirname(__DIR__, 3) . '/src';
    }
}
